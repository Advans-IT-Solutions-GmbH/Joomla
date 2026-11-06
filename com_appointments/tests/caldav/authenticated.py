#!/usr/bin/env python3
"""Synthetic password/TOTP -> key session -> HTTP device -> real clients.

Run after fixture setup/seed. All credentials, cookies and TOTP material remain
in the private client work directory, never in the evidence output.
"""
import base64
import hashlib
import hmac
import json
import re
import secrets
import struct
import sys
import time
import uuid
from html.parser import HTMLParser
from urllib.parse import urljoin, urlparse

import clients as c
import niquests
from caldav.lib.error import AuthorizationError

PRIVATE = c.WORK / "authenticated.json"
MY = "/index.php?option=com_appointments&view=mycalendars"
ACCOUNT = c.user("authenticated")


def require(condition: bool, label: str) -> None:
    if not condition:
        print("FAIL authenticated:", label)
        raise AssertionError(label)
    print("PASS authenticated:", label)


class Forms(HTMLParser):
    def __init__(self, html):
        super().__init__(convert_charrefs=True)
        self.forms = []
        self.current = None
        self.secret = ""
        self.in_secret = False
        self.feed(html)

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag == "form":
            self.current = {"action": a.get("action", ""), "fields": {}}
        if tag == "input" and self.current is not None and a.get("type", "") in ("hidden", ""):
            if a.get("name"):
                self.current["fields"][a["name"]] = a.get("value", "")
        if tag == "code" and a.get("id") == "appointments-app-password":
            self.in_secret = True

    def handle_endtag(self, tag):
        if tag == "form" and self.current is not None:
            self.forms.append(self.current)
            self.current = None
        if tag == "code":
            self.in_secret = False

    def handle_data(self, data):
        if self.in_secret:
            self.secret += data

    def find(self, task):
        return next((f for f in self.forms if task in f["action"] or f["fields"].get("task") == task), None)


def write_private(data):
    PRIVATE.write_text(json.dumps(data), encoding="utf-8")
    PRIVATE.chmod(0o600)


def private():
    return json.loads(PRIVATE.read_text(encoding="utf-8"))


def request(browser, path, fields=None):
    url = urljoin(c.BASE + "/", path)
    # Never send synthetic credentials/cookies to an external redirect target.
    for _ in range(10):
        if urlparse(url).scheme != "https" or urlparse(url).netloc != urlparse(c.BASE).netloc:
            raise AssertionError("Browser navigation must stay on the isolated HTTPS origin")
        response = browser.request("GET" if fields is None else "POST", url, data=fields,
                                   verify=c.CA, allow_redirects=False, timeout=60)
        if response.status_code not in (301, 302, 303, 307, 308):
            return response
        if response.status_code in (301, 302, 303):
            fields = None
        url = urljoin(url, response.headers["Location"])
    raise AssertionError("Browser redirect limit reached")


def submit(browser, form, fields):
    if form is None:
        raise AssertionError("Expected real Joomla form")
    return request(browser, form["action"], form["fields"] | fields)


def totp():
    counter = struct.pack(">Q", int(time.time()) // 30)
    digest = hmac.new(base64.b32decode(ACCOUNT["totp"]), counter, hashlib.sha1).digest()
    offset = digest[-1] & 15
    return str((struct.unpack(">I", digest[offset:offset + 4])[0] & 0x7FFFFFFF) % 1000000).zfill(6)


def login():
    browser = niquests.Session()
    page = request(browser, "/index.php?option=com_users&view=login")
    login_form = Forms(page.text).find("user.login")
    password = submit(browser, login_form, {"username": ACCOUNT["username"], "password": ACCOUNT["password"]})
    captive = Forms(password.text).find("captive.validate")
    require(captive is not None, "password sign-in requires Joomla's real MFA captive form")
    gated = request(browser, MY)
    require(Forms(gated.text).find("captive.validate") is not None
            and Forms(gated.text).find("devices.create") is None,
            "password-only session cannot create a device before MFA")
    completed = submit(browser, Forms(gated.text).find("captive.validate"), {"code": totp()})
    require(completed.status_code == 200 and Forms(completed.text).find("captive.validate") is None,
            "locally generated TOTP completes the real second factor")
    page = request(browser, MY)
    require(page.status_code == 200 and "no-store" in page.headers.get("Cache-Control", ""),
            "signed-in My calendars renders privately")
    cookies = []
    for cookie in browser.cookies:
        cookies.append({k: getattr(cookie, k) for k in ("name", "value", "domain", "path", "secure", "expires")})
    write_private({"cookies": cookies})


def browser_session():
    browser = niquests.Session()
    for cookie in private()["cookies"]:
        browser.cookies.set(cookie["name"], cookie["value"], domain=cookie["domain"],
                            path=cookie["path"], secure=cookie["secure"], expires=cookie["expires"])
    return browser


def device(browser, name, client):
    page = request(browser, MY)
    form = Forms(page.text).find("devices.create")
    created = submit(browser, form, {"name": name, "client": client, "rule": "1"})
    secret = Forms(created.text).secret.strip()
    require(created.status_code == 200 and bool(re.fullmatch(r"[A-Za-z0-9 -]{20,100}", secret))
            and "no-store" in created.headers.get("Cache-Control", ""),
            f"{client} app password is returned once after real password/MFA key-session release")
    require(Forms(request(browser, MY).text).secret == "", "app password is absent on subsequent GET")
    return secret


def configure():
    data = private()
    browser = browser_session()
    data["caldav"] = device(browser, "Synthetic native caldav", "other")
    data["vdir"] = device(browser, "Synthetic native vdirsyncer", "other")
    write_private(data)
    c.STATE["users"]["authenticated"]["app_password"] = data["caldav"]
    return data


def activate():
    data = private()
    c.STATE["users"]["authenticated"]["app_password"] = data["caldav"]
    return data


def denied_by_caldav(password, label):
    with c.caldav.DAVClient(url=c.BASE + c.DAV, username=ACCOUNT["username"],
                           password=password, ssl_verify_cert=c.CA) as client:
        try:
            client.principal()
        except AuthorizationError:
            require(True, label)
            return
    require(False, label)


def vdir(*args):
    result = c.vdirsyncer(*args, who="authenticated")
    # c.vdirsyncer reports diagnostics on error; errors contain no credentials.
    require(result.returncode == 0, "real vdirsyncer " + " ".join(args) + " succeeds")


def items():
    directory = c.vdirsyncer_directory("authenticated") / "calendars" / c.STATE["calendars"]["T"]["uri"]
    return list(directory.glob("*.ics"))


def clients():
    data = configure()
    path = c.calendar("authenticated", "T")
    bad = c.http("PROPFIND", path, "authenticated", secrets.token_hex(16),
                 headers={"Depth": "0"})
    require(bad.status_code == 401, "wrong app password is refused with 401")
    denied_by_caldav(secrets.token_hex(16), "real caldav rejects the wrong app password")
    user_password = c.http("PROPFIND", path, "authenticated", ACCOUNT["password"], headers={"Depth": "0"})
    require(user_password.status_code == 401, "Joomla password is not a CalDAV app password")
    client = c.caldav_client("authenticated", c.BASE + c.DAV)
    calendar = client.calendar(url=c.BASE + path)
    initial = calendar.get_objects_by_sync_token(load_objects=True, disable_fallback=True)
    require(bool(initial.sync_token) and len(initial) >= 3, "real caldav initial sync reads existing events")
    uid = "ci-auth-" + uuid.uuid4().hex
    event = calendar.save_event(c.ics(uid, "Synthetic authenticated create", c.day(7, 9)))
    event.load()
    require(uid in event.data, "real caldav creates and reads an event")
    event.data = c.ics(uid, "Synthetic authenticated change", c.day(7, 10))
    event.save()
    event.load()
    require("Synthetic authenticated change" in event.data, "real caldav changes and reloads an event")
    event.delete()
    require(uid not in c.uids_of(path, "authenticated"), "real caldav deletes the event")
    c.STATE["users"]["authenticated"]["app_password"] = data["vdir"]
    vdir("discover")
    vdir("sync")
    require(len(items()) >= 3, "real vdirsyncer reads the calendar through the HTTP-created device")
    uid = "ci-auth-vdir-" + uuid.uuid4().hex
    local = items()[0].parent / (uid + ".ics")
    local.write_text(c.ics(uid, "Synthetic vdir create", c.day(8, 9)), encoding="utf-8")
    vdir("sync")
    require(uid in c.uids_of(path, "authenticated"), "real vdirsyncer creates on the server")
    local.write_text(c.ics(uid, "Synthetic vdir change", c.day(8, 10)), encoding="utf-8")
    vdir("sync")
    server = c.uids_of(path, "authenticated")
    require("Synthetic vdir change" in c.event_text(server[uid], "authenticated"), "real vdirsyncer changes on the server")
    local.unlink()
    vdir("sync")
    require(uid not in c.uids_of(path, "authenticated"), "real vdirsyncer deletes on the server")


def blocked():
    data = activate()
    path = c.calendar("authenticated", "T")
    result = c.put(path + "blocked.ics", "authenticated", c.ics("blocked-" + uuid.uuid4().hex, "Blocked", c.day(8, 11)))
    require(result.status_code in (401, 403, 404), "blocked account cannot upload calendar data")
    # Blocking deliberately preserves cleanup-only access, never original contents.
    response = c.propfind(path, "authenticated", "1", "<d:getetag/>")
    require(response.status_code in (207, 401, 404), "blocked account has only cleanup access or denial")
    if response.status_code == 207:
        root = c.multistatus(response)
        require(not list(root.iterfind(".//d:getetag", c.NS)), "blocked account receives no event contents")
    c.STATE["users"]["authenticated"]["app_password"] = data["vdir"]
    sync = c.vdirsyncer("sync", "--force-delete", who="authenticated")
    require(sync.returncode == 0 and not items(), "blocked account's real vdirsyncer receives only deletion tombstones")


def revoked():
    data = activate()
    path = c.calendar("authenticated", "T")
    for key in ("caldav", "vdir"):
        response = c.http("PROPFIND", path, "authenticated", data[key], headers={"Depth": "0"})
        require(response.status_code == 401, f"revoked HTTP-created {key} device is refused with 401")
    denied_by_caldav(data["caldav"], "real caldav cannot authenticate its revoked device")
    c.STATE["users"]["authenticated"]["app_password"] = data["vdir"]
    result = c.vdirsyncer("sync", who="authenticated")
    require(result.returncode != 0, "real vdirsyncer cannot authenticate its revoked device")


if __name__ == "__main__":
    phases = {"login": login, "clients": clients, "blocked": blocked, "revoked": revoked}
    try:
        if len(sys.argv) != 2 or sys.argv[1] not in phases:
            raise AssertionError("Expected login|clients|blocked|revoked")
        phases[sys.argv[1]]()
    except Exception as error:
        # Neither HTML nor exception messages are safe: they can contain credentials.
        print("FAIL authenticated:", type(error).__name__, "(sensitive diagnostics suppressed)")
        sys.exit(1)
