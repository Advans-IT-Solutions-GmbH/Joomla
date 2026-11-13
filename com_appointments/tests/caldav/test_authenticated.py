"""Unit checks for the authenticated-client harness; not server evidence."""
import importlib.util
import pathlib
import sys
import types
import unittest
from unittest.mock import Mock, patch


class AuthenticatedHarnessTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        stub = types.ModuleType("clients")
        stub.WORK = pathlib.Path("/private-test-work")
        stub.BASE = "https://localhost:43572"
        stub.CA = "/private-test-work/ca.pem"
        stub.user = lambda _: {"totp": "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ"}
        stub.STATE = {"mycalendars": "/index.php?option=com_appointments&view=mycalendars&Itemid=101"}
        spec = importlib.util.spec_from_file_location("authenticated_test_target", pathlib.Path(__file__).with_name("authenticated.py"))
        cls.target = importlib.util.module_from_spec(spec)
        with patch.dict(sys.modules, {"clients": stub}):
            spec.loader.exec_module(cls.target)

    def test_rfc6238_sha1_code(self):
        with patch.object(self.target.time, "time", return_value=59):
            self.assertEqual(self.target.totp(), "287082")

    def test_real_form_inputs_and_one_time_secret(self):
        html = """<form action="/index.php?task=devices.create&amp;option=com_appointments">
        <input type="hidden" name="token" value="1"><input name="form_nonce" value="nonce">
        <input type="hidden" name="rule_version" value="v1"><input type="password" name="password">
        </form><code id="appointments-app-password">ABCD-EFGH</code>"""
        parser = self.target.Forms(html)
        form = parser.find("devices.create")
        self.assertIn("&option=com_appointments", form["action"])
        self.assertEqual(form["fields"], {"token": "1", "form_nonce": "nonce", "rule_version": "v1"})
        self.assertEqual(parser.secret, "ABCD-EFGH")
        self.assertIsNone(parser.find("user.login"))

    def test_no_credentials_sent_to_cross_origin_or_http(self):
        browser = Mock()
        for path in ("https://example.invalid/login", "http://localhost:43572/login"):
            with self.subTest(path=path), self.assertRaises(AssertionError):
                self.target.request(browser, path, {"password": "synthetic"})
        browser.request.assert_not_called()

    def test_redirect_origin_checked_before_follow(self):
        browser = Mock()
        browser.request.return_value = types.SimpleNamespace(
            status_code=307, headers={"Location": "https://example.invalid/login"})
        with self.assertRaises(AssertionError):
            self.target.request(browser, "/login", {"password": "synthetic"})
        self.assertEqual(browser.request.call_count, 1)
        self.assertEqual(browser.request.call_args.kwargs["verify"], "/private-test-work/ca.pem")
        self.assertFalse(browser.request.call_args.kwargs["allow_redirects"])


if __name__ == "__main__":
    unittest.main()
