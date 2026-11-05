#!/usr/bin/env python3
"""CalDAV client checks of the internet calendars, one phase per call.

Two real clients talk to the Joomla 6 test container over HTTPS, verifying its
certificate against the test CA: the Python `caldav` library (discovery, the
calendar list, object changes, sync tokens) and `vdirsyncer` (a two-way sync
into a local directory, which is what the clean-up must empty). Raw WebDAV
requests check what the clients cannot show: status codes, preconditions and
headers.

    clients.py <phase>

Phases run in the order of PHASES; tests/caldav/run-clients.sh calls the
server fixture (tests/caldav/fixture.php) between them. Input: /work/state.json
(output of `fixture.php setup`), /work/ca.pem, /work/booking.json. State between
phases: /work/clients.json; vdirsyncer keeps its files under /work/vdirsyncer.
Every check prints one line, ✓ or ✗; the exit code is the number of failures.
"""

from __future__ import annotations

import json
import pathlib
import sqlite3
import subprocess
import sys
import uuid
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta, timezone
from urllib.parse import unquote, urlparse
from xml.sax.saxutils import escape

import caldav
import niquests
from icalendar import Calendar as ICalendar

WORK = pathlib.Path("/work")
CA = str(WORK / "ca.pem")
STATE = json.loads((WORK / "state.json").read_text())
# TeamServices::davBaseUrl() of the site, for example https://joomla/dav/.
_DAV_URL = urlparse(STATE["dav_url"])
BASE = f"{_DAV_URL.scheme}://{_DAV_URL.netloc}"
DAV = _DAV_URL.path if _DAV_URL.path.endswith("/") else _DAV_URL.path + "/"
CLIENT_STATE = WORK / "clients.json"
VDIR = WORK / "vdirsyncer"
NS = {"d": "DAV:", "c": "urn:ietf:params:xml:ns:caldav", "cs": "http://calendarserver.org/ns/"}

failures = 0


def check(name: str, condition: bool, detail: str = "") -> bool:
    global failures
    if condition:
        print(f"✓ {name}")
    else:
        failures += 1
        print(f"✗ {name}" + (f" - {detail}" if detail else ""))
    return condition


def guarded(name: str, fn) -> None:
    """Run a group of checks; an exception counts as one failure, not a crash."""
    try:
        fn()
    except Exception as error:  # noqa: BLE001 - every error is a failed check here
        check(name, False, f"{type(error).__name__}: {error}")


def saved() -> dict:
    return json.loads(CLIENT_STATE.read_text()) if CLIENT_STATE.exists() else {}


def save(data: dict) -> None:
    merged = saved()
    merged.update(data)
    CLIENT_STATE.write_text(json.dumps(merged, indent=2))


# --- addresses -------------------------------------------------------------

def user(name: str) -> dict:
    return STATE["users"][name]


def home(name: str) -> str:
    return f"{DAV}calendars/u{user(name)['id']}/"


def calendar(name: str, key: str) -> str:
    return f"{home(name)}{STATE['calendars'][key]['uri']}/"


def path_of(href: str) -> str:
    return unquote(urlparse(href).path)


# --- raw WebDAV ------------------------------------------------------------

def http(method: str, path: str, who: str | None = None, *, password: str | None = None,
         headers: dict | None = None, body: str | None = None, base: str = BASE,
         verify: str | bool = CA) -> niquests.Response:
    auth = None
    if who is not None:
        auth = (user(who)["username"], password if password is not None else user(who)["app_password"])
    url = path if path.startswith("http") else base + path
    return niquests.request(method, url, auth=auth, headers=headers or {}, data=body,
                            verify=verify, allow_redirects=False, timeout=30)


def multistatus(response: niquests.Response) -> ET.Element:
    if response.status_code != 207:
        raise AssertionError(f"expected 207, got {response.status_code}")
    return ET.fromstring(response.content)


def propfind(path: str, who: str, depth: str, props: str) -> niquests.Response:
    body = f'<?xml version="1.0" encoding="utf-8"?><d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav" xmlns:cs="http://calendarserver.org/ns/"><d:prop>{props}</d:prop></d:propfind>'
    return http("PROPFIND", path, who, headers={"Depth": depth, "Content-Type": "application/xml; charset=utf-8"}, body=body)


def collection_props(path: str, who: str) -> dict:
    root = multistatus(propfind(path, who, "0", "<cs:getctag/><d:sync-token/><d:displayname/><d:current-user-privilege-set/>"))
    found = {}
    for propstat in root.iterfind(".//d:propstat", NS):
        # Only properties the server returned (200); a 404 propstat lists unsupported ones.
        if " 200 " in f" {propstat.findtext('d:status', '', NS)} ":
            for prop in propstat.iterfind("d:prop/*", NS):
                found[prop.tag] = prop
    return {
        "ctag": (found.get("{http://calendarserver.org/ns/}getctag").text or "") if "{http://calendarserver.org/ns/}getctag" in found else None,
        "token": (found.get("{DAV:}sync-token").text or "") if "{DAV:}sync-token" in found else None,
        "privileges": {p.tag for p in found["{DAV:}current-user-privilege-set"].iter() if p.tag != "{DAV:}privilege"} if "{DAV:}current-user-privilege-set" in found else set(),
    }


def calendars_in_home(who: str) -> set[str]:
    root = multistatus(propfind(home(who), who, "1", "<d:resourcetype/>"))
    paths = set()
    for response in root.iterfind("d:response", NS):
        href = path_of(response.findtext("d:href", "", NS))
        if response.find(".//c:calendar", NS) is not None:
            paths.add(href if href.endswith("/") else href + "/")
    return paths


def objects(path: str, who: str) -> dict[str, str]:
    """href -> etag of every object of a collection (depth-1 PROPFIND)."""
    root = multistatus(propfind(path, who, "1", "<d:getetag/><d:resourcetype/>"))
    found = {}
    for response in root.iterfind("d:response", NS):
        href = path_of(response.findtext("d:href", "", NS))
        etag = response.findtext(".//d:getetag", None, NS)
        if href.rstrip("/") != path.rstrip("/") and etag:
            found[href] = etag
    return found


def sync_collection(path: str, who: str, token: str) -> tuple[int, str | None, dict[str, str], set[str], bytes]:
    body = ('<?xml version="1.0" encoding="utf-8"?><d:sync-collection xmlns:d="DAV:">'
            f"<d:sync-token>{escape(token)}</d:sync-token><d:sync-level>1</d:sync-level>"
            "<d:prop><d:getetag/></d:prop></d:sync-collection>")
    response = http("REPORT", path, who, headers={"Content-Type": "application/xml; charset=utf-8"}, body=body)
    if response.status_code != 207:
        return response.status_code, None, {}, set(), response.content
    root = ET.fromstring(response.content)
    changed, deleted = {}, set()
    for item in root.iterfind("d:response", NS):
        href = path_of(item.findtext("d:href", "", NS))
        status = item.findtext("d:status", "", NS)
        if "404" in status:
            deleted.add(href)
        else:
            etag = item.findtext(".//d:getetag", None, NS)
            if etag:
                changed[href] = etag
    return 207, root.findtext("d:sync-token", None, NS), changed, deleted, response.content


def get(path: str, who: str) -> niquests.Response:
    return http("GET", path, who)


def event_text(path: str, who: str) -> str:
    response = get(path, who)
    if response.status_code != 200:
        raise AssertionError(f"GET {path}: expected 200, got {response.status_code}")
    return response.text


def put(path: str, who: str, ics: str, extra: dict | None = None) -> niquests.Response:
    headers = {"Content-Type": "text/calendar; charset=utf-8"}
    headers.update(extra or {})
    return http("PUT", path, who, headers=headers, body=ics)


# --- iCalendar fixtures ----------------------------------------------------

def stamp(moment: datetime) -> str:
    return moment.strftime("%Y%m%dT%H%M%SZ")


def escape_text(value: str) -> str:
    """RFC 5545 TEXT escaping, as IcsWriter::escapeText() does."""
    return value.replace("\\", "\\\\").replace(";", "\\;").replace(",", "\\,").replace("\n", "\\n")


def ics(uid: str, summary: str, start: datetime, minutes: int = 60, extra: str = "") -> str:
    lines = [
        "BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//com_appointments//caldav-clients//EN",
        "BEGIN:VEVENT", f"UID:{uid}", f"DTSTAMP:{stamp(datetime.now(timezone.utc))}",
        f"DTSTART:{stamp(start)}", f"DTEND:{stamp(start + timedelta(minutes=minutes))}",
        f"SUMMARY:{escape_text(summary)}", "LOCATION:CI meeting room", "DESCRIPTION:Confidential CI description",
    ]
    if extra:
        lines.extend(extra.split("\n"))
    lines += ["END:VEVENT", "END:VCALENDAR", ""]
    return "\r\n".join(lines)


def day(offset: int, hour: int) -> datetime:
    base = datetime.now(timezone.utc).replace(hour=hour, minute=0, second=0, microsecond=0)
    return base + timedelta(days=offset)


def uids_of(path: str, who: str) -> dict[str, str]:
    """UID -> href of every object, read with GET."""
    found = {}
    for href in objects(path, who):
        text = event_text(href, who)
        for line in text.splitlines():
            if line.startswith("UID:"):
                found[line[4:].strip()] = href
    return found


def caldav_client(who: str, url: str) -> caldav.DAVClient:
    return caldav.DAVClient(url=url, username=user(who)["username"], password=user(who)["app_password"],
                            ssl_verify_cert=CA)


# --- phases ----------------------------------------------------------------

def phase_seed() -> None:
    """OWNER writes the fixtures EV-1 .. EV-3 into CAL-T, EV-5 into CAL-R, EV-7 into CAL-F."""
    seeds = {
        "EV-1": ("T", ics("ci-ev1-" + uuid.uuid4().hex, "CI single event", day(2, 9))),
        "EV-2": ("T", "\r\n".join([
            "BEGIN:VCALENDAR", "VERSION:2.0", "PRODID:-//com_appointments//caldav-clients//EN", "BEGIN:VEVENT",
            "UID:ci-ev2-" + uuid.uuid4().hex, f"DTSTAMP:{stamp(datetime.now(timezone.utc))}",
            "DTSTART;VALUE=DATE:" + day(4, 0).strftime("%Y%m%d"), "DTEND;VALUE=DATE:" + day(5, 0).strftime("%Y%m%d"),
            "SUMMARY:CI all-day event", "END:VEVENT", "END:VCALENDAR", ""])),
        "EV-3": ("T", ics("ci-ev3-" + uuid.uuid4().hex, "CI weekly series", day(1, 14), 30,
                          f"RRULE:FREQ=WEEKLY;COUNT=6\nEXDATE:{stamp(day(8, 14))}")),
        "EV-5": ("R", ics("ci-ev5-" + uuid.uuid4().hex, "CI revocation event", day(3, 11))),
        "EV-7": ("F", ics("ci-ev7-" + uuid.uuid4().hex, "CI owner only event", day(3, 13))),
    }
    uids = {}
    for name, (key, text) in seeds.items():
        uid = next(line[4:] for line in text.split("\r\n") if line.startswith("UID:"))
        href = calendar("owner", key) + uid + ".ics"
        response = put(href, "owner", text, {"If-None-Match": "*"})
        check(f"OWNER creates {name} in CAL-{key} ({response.status_code})", response.status_code in (201, 204))
        uids[name] = {"uid": uid, "key": key, "href_owner": href}
    save({"seeds": uids})


def phase_discovery() -> None:
    response = http("PROPFIND", "/.well-known/caldav", headers={"Depth": "0"})
    check("/.well-known/caldav answers 301 to the DAV root", response.status_code == 301
          and path_of(response.headers.get("Location", "")).rstrip("/") == DAV.rstrip("/"),
          f"{response.status_code} {response.headers.get('Location')}")

    plain = http("PROPFIND", DAV, "writer", base=BASE.replace("https://", "http://"), headers={"Depth": "0"}, verify=False)
    check("Plain HTTP is refused with 403, no body and no redirect",
          plain.status_code == 403 and not plain.content and "Location" not in plain.headers, str(plain.status_code))

    joomla = http("PROPFIND", DAV, "writer", password=user("writer")["password"], headers={"Depth": "0"})
    wrong = http("PROPFIND", DAV, "writer", password="A" * 32, headers={"Depth": "0"})
    anonymous = http("PROPFIND", DAV, headers={"Depth": "0"})
    mismatch = http("PROPFIND", DAV, "writer", password=user("reader")["app_password"], headers={"Depth": "0"})
    check("Anonymous CalDAV requests require authentication", anonymous.status_code == 401, str(anonymous.status_code))
    check("The Joomla password never signs in to CalDAV (401)", joomla.status_code == 401, str(joomla.status_code))
    check("A wrong app password gets the same 401 as the Joomla password",
          wrong.status_code == 401 and wrong.headers.get("WWW-Authenticate") == joomla.headers.get("WWW-Authenticate")
          and wrong.content == joomla.content)
    check("Another user's app password cannot authenticate WRITER",
          mismatch.status_code == 401 and mismatch.content == wrong.content, str(mismatch.status_code))
    for secret in (user("writer")["password"], user("writer")["app_password"], user("reader")["app_password"]):
        check("Authentication errors never echo a submitted secret",
              all(secret not in r.text and secret not in str(dict(r.headers)) for r in (joomla, wrong, mismatch)))
    # Use the same app password with an upper-case Joomla user name.
    uppercase = niquests.request("PROPFIND", BASE + DAV,
                                auth=(user("writer")["username"].upper(), user("writer")["app_password"]),
                                headers={"Depth": "0"}, verify=CA, allow_redirects=False, timeout=30)
    check("CalDAV user names are case-insensitive", uppercase.status_code == 207, str(uppercase.status_code))

    def library_discovery() -> None:
        client = caldav_client("writer", BASE + "/.well-known/caldav")
        principal = client.principal()
        check("caldav finds the principal of WRITER through the well-known address",
              path_of(str(principal.url)).rstrip("/") == f"{DAV}principals/u{user('writer')['id']}", str(principal.url))
        listed = {path_of(str(c.url)) if str(c.url).endswith("/") else path_of(str(c.url)) + "/" for c in principal.calendars()}
        expected = {calendar("writer", "T"), calendar("writer", "R")}
        check("caldav lists exactly the granted calendars CAL-T and CAL-R", listed == expected, f"{sorted(listed)}")
        names = {c.get_display_name() for c in principal.calendars()}
        check("Calendar names come from the calendar records",
              names == {STATE["calendars"]["T"]["title"], STATE["calendars"]["R"]["title"]}, f"{names}")
    guarded("caldav discovery", library_discovery)

    other = propfind(f"{DAV}principals/u{user('owner')['id']}/", "writer", "0", "<d:displayname/>")
    check("Another user's principal is not visible", other.status_code in (403, 404), str(other.status_code))
    foreign_home = propfind(home("owner"), "writer", "1", "<d:resourcetype/>")
    check("Another user's calendar home is not visible", foreign_home.status_code in (403, 404), str(foreign_home.status_code))
    denied = propfind(calendar("writer", "F"), "writer", "0", "<d:displayname/>")
    check("Default deny: CAL-F without a grant is 404 in WRITER's home", denied.status_code == 404, str(denied.status_code))
    seeds = saved()["seeds"]
    hidden = get(calendar("writer", "F") + seeds["EV-7"]["uid"] + ".ics", "writer")
    check("Default deny: an object of CAL-F is 404 for WRITER", hidden.status_code == 404, str(hidden.status_code))
    mk = http("MKCALENDAR", home("writer") + "ci-new/", "writer")
    check("MKCALENDAR is refused with 403", mk.status_code == 403, str(mk.status_code))
    for method, body in (
        ("ACL", '<d:acl xmlns:d="DAV:"/>'),
        ("POST", '<cs:share xmlns:cs="http://calendarserver.org/ns/"/>'),
    ):
        denied = http(method, calendar("owner", "T"), "owner",
                      headers={"Content-Type": "application/xml"}, body=body)
        check(f"{method} cannot share a calendar even as OWNER (403)",
              denied.status_code == 403, str(denied.status_code))

    props = collection_props(calendar("writer", "T"), "writer")
    check("CTag equals the sync token", props["ctag"] is not None and props["ctag"] == props["token"], f"{props['ctag']} / {props['token']}")
    any_response = propfind(calendar("writer", "T"), "writer", "0", "<d:displayname/>")
    cache = any_response.headers.get("Cache-Control", "")
    check("CalDAV answers are private and no-store", "no-store" in cache and "private" in cache, cache)
    check("CalDAV sets no cookie", "Set-Cookie" not in any_response.headers)
    check("CalDAV answers are noindex", "noindex" in any_response.headers.get("X-Robots-Tag", ""))


def phase_sync() -> None:
    seeds = saved()["seeds"]
    path = calendar("writer", "T")

    status, token, changed, deleted, _ = sync_collection(path, "writer", "")
    names = {p.rsplit("/", 1)[-1] for p in changed}
    expected = {seeds[n]["uid"] + ".ics" for n in ("EV-1", "EV-2", "EV-3")}
    check("Initial sync-collection returns every object of CAL-T", status == 207 and names == expected, f"{status} {sorted(names)}")
    check("Initial sync reports no deletion", not deleted)
    check("Initial sync returns a non-empty token", bool(token))

    client = caldav_client("writer", BASE + DAV)
    cal = client.calendar(url=BASE + path)
    initial = cal.get_objects_by_sync_token(load_objects=True, disable_fallback=True)
    check("caldav performs a real initial sync without a full-query fallback",
          bool(initial.sync_token) and not str(initial.sync_token).startswith("fake-")
          and {path_of(str(item.url)).rsplit("/", 1)[-1] for item in initial} == expected)
    parsed = {}
    for item in initial:
        event = ICalendar.from_ical(item.data).walk("VEVENT")[0]
        parsed[str(event["UID"])] = event
    all_day = parsed[seeds["EV-2"]["uid"]]
    check("caldav preserves all-day date values and the exclusive end date",
          all_day["DTSTART"].params.get("VALUE") == all_day["DTEND"].params.get("VALUE") == "DATE"
          and all_day.decoded("DTEND") - all_day.decoded("DTSTART") == timedelta(days=1))
    series = parsed[seeds["EV-3"]["uid"]]
    check("caldav preserves the weekly recurrence and excluded instance",
          series["RRULE"]["FREQ"] == ["WEEKLY"] and series["RRULE"]["COUNT"] == [6]
          and len(series["EXDATE"].dts) == 1)
    client_token = initial.sync_token

    status, again, changed, deleted, _ = sync_collection(path, "writer", token or "")
    check("A repeated sync without changes transfers nothing and keeps the token",
          status == 207 and not changed and not deleted and again == token, f"{status} {len(changed)} {again}")

    ctag_before = collection_props(path, "writer")["ctag"]
    ev1 = seeds["EV-1"]["href_owner"]
    ev2 = seeds["EV-2"]["href_owner"]
    current = http("GET", ev1, "owner")
    check("OWNER reads the object before updating it", current.status_code == 200 and bool(current.headers.get("ETag")))
    update = put(ev1, "owner", ics(seeds["EV-1"]["uid"], "CI single event, changed", day(2, 10)), {"If-Match": current.headers.get("ETag", "")})
    check("OWNER updates EV-1", update.status_code in (200, 204), str(update.status_code))
    remove = http("DELETE", ev2, "owner")
    check("OWNER deletes EV-2", remove.status_code == 204, str(remove.status_code))
    ev4 = "ci-ev4-" + uuid.uuid4().hex
    create = put(calendar("owner", "T") + ev4 + ".ics", "owner", ics(ev4, "CI added later", day(6, 15)), {"If-None-Match": "*"})
    check("OWNER creates EV-4", create.status_code == 201, str(create.status_code))

    ctag_after = collection_props(path, "writer")["ctag"]
    check("The CTag changes with every change", ctag_after not in (None, ctag_before), f"{ctag_before} -> {ctag_after}")
    status, newer, changed, deleted, _ = sync_collection(path, "writer", token or "")
    names = {p.rsplit("/", 1)[-1] for p in changed}
    check("Incremental sync returns exactly the changed and the added object",
          status == 207 and names == {seeds["EV-1"]["uid"] + ".ics", ev4 + ".ics"}, f"{sorted(names)}")
    check("Incremental sync reports the deleted object",
          {p.rsplit("/", 1)[-1] for p in deleted} == {seeds["EV-2"]["uid"] + ".ics"}, f"{sorted(deleted)}")
    check("Incremental sync gives a new token", newer not in (None, token))
    incremental = cal.get_objects_by_sync_token(client_token, load_objects=True, disable_fallback=True)
    removed = {path_of(str(url)).rsplit("/", 1)[-1] for url in incremental.deleted_urls}
    downloaded = {path_of(str(item.url)).rsplit("/", 1)[-1] for item in incremental
                  if item.url.canonical() not in incremental.deleted_urls}
    check("caldav incrementally downloads exactly the changed and added objects",
          downloaded == {seeds["EV-1"]["uid"] + ".ics", ev4 + ".ics"}
          and incremental.sync_token not in (None, client_token), str(downloaded))
    check("caldav incrementally receives the deleted object",
          removed == {seeds["EV-2"]["uid"] + ".ics"}, str(removed))

    status, _, _, _, body = sync_collection(path, "writer", "http://invalid.example/ci-bogus-token")
    check("An unknown sync token is refused with valid-sync-token", status in (403, 409) and b"valid-sync-token" in body, str(status))

    # Writes of WRITER through the caldav library, read back by OWNER.
    def library_writes() -> None:
        client = caldav_client("writer", BASE + DAV)
        cal = client.calendar(url=BASE + path)
        uid = "ci-writer-" + uuid.uuid4().hex
        add = getattr(cal, "add_event", None) or cal.save_event
        event = add(ics(uid, "CI written by WRITER", day(5, 9)))
        owner_view = uids_of(calendar("owner", "T"), "owner")
        check("An event created with caldav reaches OWNER", uid in owner_view)
        event.load()
        event.data = ics(uid, "CI written by WRITER, changed", day(5, 11))
        event.save()
        text = event_text(owner_view[uid], "owner")
        check("An event changed with caldav reaches OWNER", "SUMMARY:" + escape_text("CI written by WRITER, changed") in text)
        event.delete()
        check("An event deleted with caldav is gone for OWNER", uid not in uids_of(calendar("owner", "T"), "owner"))
        events = cal.events()
        check("caldav reads the events of CAL-T", len(events) >= 3, str(len(events)))
    guarded("caldav writes", library_writes)

    href = calendar("writer", "T") + seeds["EV-1"]["uid"] + ".ics"
    current = get(href, "writer")
    stale = put(href, "writer", ics(seeds["EV-1"]["uid"], "CI stale write", day(2, 12)), {"If-Match": '"ci-stale-etag"'})
    check("PUT with a stale If-Match is refused with 412", stale.status_code == 412, str(stale.status_code))
    exists = put(href, "writer", ics(seeds["EV-1"]["uid"], "CI overwrite", day(2, 12)), {"If-None-Match": "*"})
    check("PUT with If-None-Match * on an existing object is refused with 412", exists.status_code == 412, str(exists.status_code))
    stale_delete = http("DELETE", href, "writer", headers={"If-Match": '"ci-stale-etag"'})
    check("DELETE with a stale If-Match is refused with 412", stale_delete.status_code == 412, str(stale_delete.status_code))
    preserved = get(href, "writer")
    check("Rejected preconditions leave the object and its ETag unchanged",
          preserved.status_code == current.status_code == 200 and preserved.content == current.content
          and preserved.headers.get("ETag") == current.headers.get("ETag") is not None)
    fresh = put(href, "writer", ics(seeds["EV-1"]["uid"], "CI single event, by WRITER", day(2, 10)), {"If-Match": current.headers.get("ETag", "")})
    check("PUT with the current If-Match succeeds", fresh.status_code in (200, 204), str(fresh.status_code))
    duplicate = put(calendar("writer", "T") + "ci-duplicate-" + uuid.uuid4().hex + ".ics", "writer",
                    ics(seeds["EV-1"]["uid"], "CI duplicate UID", day(2, 10)), {"If-None-Match": "*"})
    check("A second object with the same UID is refused with no-uid-conflict",
          duplicate.status_code in (403, 409) and b"no-uid-conflict" in duplicate.content, str(duplicate.status_code))
    save({"ev4": ev4})


def phase_roles() -> None:
    seeds = saved()["seeds"]
    href = calendar("reader", "T") + seeds["EV-1"]["uid"] + ".ics"
    check("READER reads an object", get(href, "reader").status_code == 200)
    write = put(href, "reader", ics(seeds["EV-1"]["uid"], "CI by READER", day(2, 10)))
    check("READER cannot change an object (403)", write.status_code == 403, str(write.status_code))
    create = put(calendar("reader", "T") + "ci-reader.ics", "reader", ics("ci-reader-" + uuid.uuid4().hex, "CI", day(2, 10)))
    check("READER cannot create an object (403)", create.status_code == 403, str(create.status_code))
    check("READER cannot delete an object (403)", http("DELETE", href, "reader").status_code == 403)
    privileges = collection_props(calendar("reader", "T"), "reader")["privileges"]
    check("READER's privilege set has read and no write", "{DAV:}read" in privileges and "{DAV:}write" not in privileges
          and "{DAV:}write-content" not in privileges, f"{sorted(privileges)}")

    path = calendar("busy", "T")
    texts = [event_text(h, "busy") for h in objects(path, "busy")]
    check("BUSY sees one busy block per object", len(texts) == 3, str(len(texts)))
    joined = "\n".join(texts)
    check("Busy blocks carry the summary «Busy» only", all("SUMMARY:Busy" in t for t in texts) and "CI single event" not in joined
          and "CI weekly series" not in joined)
    check("Busy blocks are confidential", all("CLASS:CONFIDENTIAL" in t for t in texts))
    check("Busy blocks carry no location, description or attendee",
          not any(word in joined for word in ("LOCATION", "DESCRIPTION", "ATTENDEE", "ORGANIZER", "Confidential CI description")))
    check("Busy blocks do not reveal the original UIDs", not any(seeds[n]["uid"] in joined for n in ("EV-1", "EV-3")))
    check("The busy series keeps its recurrence", "RRULE:FREQ=WEEKLY" in joined)
    blocked = put(path + "ci-busy.ics", "busy", ics("ci-busy-" + uuid.uuid4().hex, "CI", day(2, 10)))
    check("BUSY cannot write (403)", blocked.status_code == 403, str(blocked.status_code))
    busy_href = next(iter(objects(path, "busy")))
    check("BUSY cannot delete an object (403)", http("DELETE", busy_href, "busy").status_code == 403)
    check("BUSY cannot change an object (403)",
          put(busy_href, "busy", ics("ci-busy-change", "CI", day(2, 10))).status_code == 403)


def vdirsyncer_directory(who: str = "vdir") -> pathlib.Path:
    return VDIR if who == "vdir" else VDIR.with_name(VDIR.name + "-" + who)


def vdirsyncer_config(who: str = "vdir") -> pathlib.Path:
    directory = vdirsyncer_directory(who)
    directory.mkdir(parents=True, exist_ok=True)
    (directory / "calendars").mkdir(exist_ok=True)
    config = directory / "config"
    vdir = user(who)
    config.write_text(f"""[general]
status_path = "{directory}/status/"

[pair internet]
a = "internet_local"
b = "internet_remote"
collections = ["from b"]
conflict_resolution = "b wins"

[storage internet_local]
type = "filesystem"
path = "{directory}/calendars/"
fileext = ".ics"

[storage internet_remote]
type = "caldav"
url = "{BASE}/"
username = "{vdir['username']}"
password = "{vdir['app_password']}"
verify = "{CA}"
""")
    return config


def vdirsyncer(*args: str, who: str = "vdir") -> subprocess.CompletedProcess:
    result = subprocess.run(["vdirsyncer", "-c", str(vdirsyncer_config(who)), *args], input="y\n" * 20,
                            capture_output=True, text=True, timeout=300)
    if result.returncode != 0:
        print("  vdirsyncer:", (result.stderr or result.stdout).strip().replace("\n", "\n  "))
    return result


def local_items(who: str = "vdir") -> list[pathlib.Path]:
    folder = vdirsyncer_directory(who) / "calendars" / STATE["calendars"]["T"]["uri"]
    return sorted(folder.glob("*.ics")) if folder.exists() else []


def status_items() -> int:
    """Items vdirsyncer remembers for CAL-T (its sqlite status of the collection)."""
    total = 0
    for file in (VDIR / "status").rglob("*"):
        if file.is_file() and STATE["calendars"]["T"]["uri"] in file.name and file.suffix == ".items":
            with sqlite3.connect(file) as db:
                total += db.execute("SELECT COUNT(*) FROM status").fetchone()[0]
    return total


def phase_vdirsyncer() -> None:
    discover = vdirsyncer("discover")
    check("vdirsyncer discovers the calendars from the site address", discover.returncode == 0)
    first = vdirsyncer("sync")
    server = objects(calendar("vdir", "T"), "vdir")
    check("vdirsyncer's first sync succeeds", first.returncode == 0)
    check("vdirsyncer holds every object of CAL-T locally", len(local_items()) == len(server) and len(server) >= 3,
          f"{len(local_items())} local, {len(server)} on the server")
    check("vdirsyncer's status records every object", status_items() == len(server), str(status_items()))

    uid = "ci-vdir-" + uuid.uuid4().hex
    folder = VDIR / "calendars" / STATE["calendars"]["T"]["uri"]
    (folder / f"{uid}.ics").write_text(ics(uid, "CI created by vdirsyncer", day(7, 9)))
    upload = vdirsyncer("sync")
    check("vdirsyncer uploads a local event", upload.returncode == 0 and uid in uids_of(calendar("owner", "T"), "owner"))
    local = next(p for p in local_items() if uid in p.read_text())
    local.write_text(ics(uid, "CI changed locally by vdirsyncer", day(7, 10)))
    change = vdirsyncer("sync")
    owner_items = uids_of(calendar("owner", "T"), "owner")
    check("vdirsyncer uploads a local change",
          change.returncode == 0 and uid in owner_items
          and "SUMMARY:CI changed locally by vdirsyncer" in event_text(owner_items[uid], "owner"))
    local.unlink()
    remove = vdirsyncer("sync")
    check("vdirsyncer removes a locally deleted event from the server",
          remove.returncode == 0 and uid not in uids_of(calendar("owner", "T"), "owner"))

    ev5 = "ci-ev8-" + uuid.uuid4().hex
    href = calendar("owner", "T") + ev5 + ".ics"
    create = put(href, "owner", ics(ev5, "CI added by OWNER for vdirsyncer", day(7, 11)), {"If-None-Match": "*"})
    check("OWNER creates the vdirsyncer download fixture", create.status_code == 201, str(create.status_code))
    download = vdirsyncer("sync")
    check("vdirsyncer downloads a server event",
          download.returncode == 0 and any(ev5 in p.read_text() for p in local_items()))
    update = put(href, "owner", ics(ev5, "CI changed on server for vdirsyncer", day(7, 12)),
                 {"If-Match": get(href, "owner").headers.get("ETag", "")})
    check("OWNER changes the vdirsyncer download fixture", update.status_code in (200, 204), str(update.status_code))
    download = vdirsyncer("sync")
    check("vdirsyncer downloads a server change",
          download.returncode == 0 and any(ev5 in p.read_text() and "SUMMARY:CI changed on server for vdirsyncer" in p.read_text()
                                         for p in local_items()))
    remove = http("DELETE", href, "owner", headers={"If-Match": get(href, "owner").headers.get("ETag", "")})
    check("OWNER deletes the vdirsyncer download fixture", remove.status_code == 204, str(remove.status_code))
    download = vdirsyncer("sync")
    check("vdirsyncer deletes a server-removed event locally",
          download.returncode == 0 and not any(ev5 in p.read_text() for p in local_items()))
    save({"vdir_uid": uid})


def phase_booking() -> None:
    booking = json.loads((WORK / "booking.json").read_text())
    found = uids_of(calendar("writer", "T"), "writer")
    check("A booking appears in CAL-T for WRITER with the UID of its mail ICS", booking["uid"] in found)
    if booking["uid"] in found:
        text = event_text(found[booking["uid"]], "writer")
        start = booking["start_utc"].replace("-", "").replace(":", "").replace(" ", "T")
        check("The booking object starts at the booked time", start in text, start)
    sync = vdirsyncer("sync")
    check("vdirsyncer fetches the booking", sync.returncode == 0 and any(booking["uid"] in p.read_text() for p in local_items()))
    busy = "\n".join(event_text(h, "busy") for h in objects(calendar("busy", "T"), "busy"))
    start = booking["start_utc"].replace("-", "").replace(":", "").replace(" ", "T")
    check("BUSY sees a busy block at the booked time", f"DTSTART:{start}" in busy, start)
    check("The booking is a busy block for BUSY, without the invitee",
          "Guest" not in busy and "ci-guest@" not in busy and "ATTENDEE" not in busy)
    writer_text = event_text(found[booking["uid"]], "writer") if booking["uid"] in found else ""
    check("The invitee reached the booking object itself (so the BUSY check is meaningful)",
          "CI Guest" in writer_text and "ci-guest@" in writer_text, "invitee not in the object")
    save({"booking_uid": booking["uid"]})


def phase_booking_cancelled() -> None:
    uid = saved()["booking_uid"]
    check("A cancelled booking disappears from CAL-T", uid not in uids_of(calendar("writer", "T"), "writer"))
    sync = vdirsyncer("sync")
    check("vdirsyncer removes the cancelled booking locally",
          sync.returncode == 0 and not any(uid in p.read_text() for p in local_items()))


def phase_mfa_denied() -> None:
    response = propfind(calendar("writer", "T"), "writer", "1", "<d:getetag/>")
    check("An active device without MFA is refused with 403", response.status_code == 403, str(response.status_code))
    check("The MFA refusal reveals no calendar contents", "CI single event" not in response.text)


def phase_mfa_restored() -> None:
    check("Restoring MFA restores the effective grant",
          propfind(calendar("writer", "T"), "writer", "0", "<d:displayname/>").status_code == 207)


def phase_revoked() -> None:
    listed = calendars_in_home("writer")
    check("A revoked grant disappears from the calendar home",
          listed == {calendar("writer", "T")}, f"{sorted(listed)}")
    gone = propfind(calendar("writer", "R"), "writer", "0", "<d:displayname/>")
    check("The revoked calendar answers 404", gone.status_code == 404, str(gone.status_code))
    seeds = saved()["seeds"]
    check("Objects of the revoked calendar answer 404",
          get(calendar("writer", "R") + seeds["EV-5"]["uid"] + ".ics", "writer").status_code == 404)
    check("The other calendar of WRITER is unaffected",
          propfind(calendar("writer", "T"), "writer", "0", "<d:displayname/>").status_code == 207)
    # Baseline for the clean-up phases, taken before run-clients.sh starts the clean-up.
    for who in ("cleanup-sync", "cleanup-list", "cleanup-timeout"):
        known = objects(calendar(who, "T"), who)
        check(f"Clean-up baseline: {who} synchronised CAL-T", len(known) >= 3, str(len(known)))
    discover = vdirsyncer("discover", who="cleanup-guard")
    initial = vdirsyncer("sync", who="cleanup-guard")
    check("The independent vdirsyncer deletion-guard profile has a non-empty local copy",
          discover.returncode == 0 and initial.returncode == 0 and len(local_items("cleanup-guard")) >= 3)
    save({"owner_baseline": objects(calendar("owner", "T"), "owner"),
          "cleanup_known": list(objects(calendar("cleanup-sync", "T"), "cleanup-sync")),
          "cleanup_token": collection_props(calendar("cleanup-sync", "T"), "cleanup-sync")["token"]})


def unchanged_for_owner(phase: str) -> None:
    before = saved()["owner_baseline"]
    after = objects(calendar("owner", "T"), "owner")
    changed = {h for h in before.keys() | after.keys() if before.get(h) != after.get(h)}
    check(f"Clean-up, {phase}: nothing deleted, changed or uploaded on the server since before the clean-up",
          after == before and len(before) >= 3, f"{sorted(changed)}")


def phase_cleanup_empty() -> None:
    # A collection listing acknowledges delivery and immediately hides that device's
    # calendar. Each destructive probe therefore needs its own device.
    who = "cleanup-sync"
    path = calendar(who, "T")
    before = saved()
    props = collection_props(path, who)
    check("Clean-up, empty: the CTag changed", props["ctag"] is not None and props["ctag"].endswith("-c"), str(props["ctag"]))
    check("Clean-up, empty: CTag equals the published sync token", props["ctag"] == props["token"])
    write = put(path + "ci-cleanup.ics", who, ics("ci-cleanup-" + uuid.uuid4().hex, "CI", day(2, 10)))
    check("Clean-up, empty: writes are refused with 403", write.status_code == 403, str(write.status_code))
    status, token, changed, deleted, _ = sync_collection(path, who, before["cleanup_token"])
    check("Clean-up, empty: sync-collection reports every known object deleted",
          status == 207 and not changed and bool(before["cleanup_known"])
          and set(before["cleanup_known"]) <= deleted and token is not None and token == props["token"],
          f"{status}, {len(changed)} changed, {len(deleted)} deleted")
    check("Clean-up, empty: the calendar is served without objects",
          objects(calendar("cleanup-list", "T"), "cleanup-list") == {})

    count_before = len(local_items())
    check("The clean-up starts with a non-empty local copy", count_before >= 3, str(count_before))
    guard_before = len(local_items("cleanup-guard"))
    guard = vdirsyncer("sync", who="cleanup-guard")
    check("vdirsyncer refuses to apply a completely emptied calendar without --force-delete",
          guard.returncode != 0 and guard_before >= 3 and len(local_items("cleanup-guard")) == guard_before,
          f"{guard.returncode} {len(local_items('cleanup-guard'))}")
    check("vdirsyncer reports its deletion guard rather than an unrelated failure",
          "was completely emptied" in guard.stderr and "--force-delete" in guard.stderr, guard.stderr)
    forced = vdirsyncer("sync", "--force-delete")
    check("vdirsyncer applies the empty calendar", forced.returncode == 0)
    check("The clean-up deleted vdirsyncer's local copy", local_items() == [], str(len(local_items())))
    check("vdirsyncer's status holds no object of CAL-T any more", status_items() == 0, str(status_items()))
    unchanged_for_owner("empty")


def phase_cleanup_hidden() -> None:
    for who in ("cleanup-sync", "cleanup-list", "vdir"):
        gone = propfind(calendar(who, "T"), who, "0", "<d:displayname/>")
        check(f"Clean-up, hidden: {who}'s delivered collection answers 404",
              gone.status_code == 404, str(gone.status_code))
        listed = calendars_in_home(who)
        check(f"Clean-up, hidden: {who}'s calendar home lists no calendar", listed == set(), f"{sorted(listed)}")
    unchanged_for_owner("hidden")


def phase_cleanup_revoked() -> None:
    for who in ("cleanup-sync", "cleanup-list", "vdir"):
        response = propfind(home(who), who, "0", "<d:displayname/>")
        check(f"Clean-up done: {who}'s device is refused with 401", response.status_code == 401, str(response.status_code))
    sync = vdirsyncer("sync")
    check("vdirsyncer fails to sign in after the clean-up", sync.returncode != 0)
    unchanged_for_owner("revoked")


def phase_lockout() -> None:
    # Last: the address bucket may lock every client on this throw-away network.
    statuses = []
    for _ in range(11):
        response = http("PROPFIND", DAV, "owner", password="B" * 32, headers={"Depth": "0"})
        statuses.append(response.status_code)
        if response.status_code == 429:
            break
    check("Repeated authentication failures are refused and then rate-limited",
          len(statuses) >= 2 and statuses[-1] == 429
          and all(status == 401 for status in statuses[:-1]), str(statuses))
    correct = propfind(calendar("owner", "T"), "owner", "0", "<d:displayname/>")
    retry = correct.headers.get("Retry-After", "")
    check("A locked account refuses even its correct app password with Retry-After",
          correct.status_code == 429 and retry.isdigit() and int(retry) > 0,
          f"{correct.status_code}, Retry-After={retry}")


PHASES = {
    "seed": phase_seed,
    "discovery": phase_discovery,
    "sync": phase_sync,
    "roles": phase_roles,
    "vdirsyncer": phase_vdirsyncer,
    "booking": phase_booking,
    "booking-cancelled": phase_booking_cancelled,
    "mfa-denied": phase_mfa_denied,
    "mfa-restored": phase_mfa_restored,
    "revoked": phase_revoked,
    "cleanup-empty": phase_cleanup_empty,
    "cleanup-hidden": phase_cleanup_hidden,
    "cleanup-revoked": phase_cleanup_revoked,
    "lockout": phase_lockout,
}

if __name__ == "__main__":
    if len(sys.argv) != 2 or sys.argv[1] not in PHASES:
        print("Usage: clients.py " + "|".join(PHASES), file=sys.stderr)
        sys.exit(2)
    print(f"--- {sys.argv[1]} ---")
    guarded(f"phase {sys.argv[1]}", PHASES[sys.argv[1]])
    sys.exit(min(failures, 100))
