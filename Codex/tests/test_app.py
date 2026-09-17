import json
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from urllib.error import HTTPError

from werkzeug.datastructures import MultiDict

from app import create_app
from common import APIError, AutotaskClient, load_credentials
from savefields import save_selections


class FakeAPI:
    def __init__(self):
        self.calls = []
        self.failure = None

    def request(self, method, path, payload=None):
        self.calls.append((method, path, payload))
        if self.failure:
            raise APIError(self.failure)
        if path.endswith("/entityInformation/fields"):
            key = "udfFieldID" if "Udf" in path else "fieldID"
            return {"fields": [{"name": "irrelevant"}, {"name": key,
                    "picklistValues": [{"value": 7, "label": "Example"}]}]}
        return {"itemId": 99}

    def items(self, path, query=None):
        self.calls.append(("LIST", path, query))
        if self.failure:
            raise APIError(self.failure)
        if path.endswith("/query"):
            return [{"id": 12, "name": "Existing", "webhookUrl": "https://example.com/hook",
                     "notificationEmailAddress": "test@example.com", "isActive": True}]
        key = "udfFieldID" if path.endswith("UdfFields") else "fieldID"
        return [{"id": 88, key: 7, "isSubscribedField": True, "isDisplayAlwaysField": False}]


class ApplicationTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.path = Path(self.temp.name) / "creds.json"
        self.path.write_text(json.dumps({"apiUser": "user", "apiSecret": "test-secret-only",
                                        "integrationCode": "code"}))
        self.api = FakeAPI()
        self.app = create_app({"TESTING": True, "SECRET_KEY": "test-key",
                               "CREDENTIALS_FILE": self.path, "CLIENT_FACTORY": lambda creds: self.api})
        self.browser = self.app.test_client()
        self.browser.get("/credentials")
        with self.browser.session_transaction() as session:
            self.token = session["csrf_token"]

    def tearDown(self):
        self.temp.cleanup()

    def post(self, path, data):
        form = MultiDict(data)
        form.add("csrf_token", self.token)
        return self.browser.post(path, data=form)

    def test_python_pages(self):
        for kind, entity in [("company", "Company"), ("ticket", "Ticket"), ("contact", "Contact")]:
            for page in ("webhook", "manage_webhooks"):
                with self.subTest(kind=kind, page=page):
                    response = self.browser.get(f"/{page}?type={kind}&webhook_id=12")
                    self.assertEqual(response.status_code, 200)
            self.assertIn(("LIST", entity + "Webhooks/query", {"filter": []}), self.api.calls)
        self.assertEqual(self.browser.get("/test_credentials").status_code, 200)

    def test_no_php_routes_or_links(self):
        self.assertFalse(any('.php' in rule.rule for rule in self.app.url_map.iter_rules()))
        for page in ('credentials', 'webhook', 'manage_webhooks', 'test_credentials', 'savefields', 'delete_all_webhooks'):
            self.assertEqual(self.browser.get('/' + page + '.php').status_code, 404)
        for page in ('credentials', 'webhook', 'manage_webhooks?webhook_id=12'):
            self.assertNotIn(b'.php', self.browser.get('/' + page).data)

    def test_credentials_do_not_leak_and_save_permissions(self):
        response = self.browser.get("/credentials")
        self.assertNotIn(b"test-secret-only", response.data)
        with self.browser.session_transaction() as session:
            self.assertNotIn("apiSecret", session)
        response = self.post("/credentials", {"action": "save", "apiUser": "changed",
                            "integrationCode": "code", "baseUrl": "https://webservices2.autotask.net/ATServicesRest/v1.0"})
        self.assertEqual(response.status_code, 302)
        self.assertEqual(self.path.stat().st_mode & 0o777, 0o600)
        self.assertEqual(json.loads(self.path.read_text())["apiSecret"], "test-secret-only")

    def test_mutations_require_csrf_and_get_does_not_delete(self):
        self.assertEqual(self.browser.post("/webhook", data={"action": "delete", "id": 12}).status_code, 400)
        self.browser.get("/webhook?delete_id=12")
        self.browser.get("/delete_all_webhooks?delete=12")
        self.assertFalse(any(call[0] == "DELETE" for call in self.api.calls))

    def test_create_update_delete_and_bulk_delete(self):
        values = {"action": "create", "webhookUrl": "https://example.com/hook",
                  "notificationEmailAddress": "test@example.com", "webhook_name": "New", "secretKey": "receiver-secret"}
        response = self.post("/webhook?type=ticket", values)
        self.assertEqual(response.status_code, 302)
        self.assertIn("webhook_id=99", response.location)
        self.assertEqual(self.api.calls[-1][1], "TicketWebhooks")
        self.assertEqual(self.api.calls[-1][2]["SecretKey"], "receiver-secret")
        values.update(action="update", id="12")
        self.assertEqual(self.post("/webhook?type=contact", values).status_code, 302)
        self.assertEqual(self.api.calls[-1][0:2], ("PATCH", "ContactWebhooks"))
        self.assertEqual(self.post("/webhook", {"action": "delete", "id": "12"}).status_code, 302)
        self.assertEqual(self.api.calls[-1][0:2], ("DELETE", "CompanyWebhooks/12"))
        self.assertEqual(self.post("/delete_all_webhooks", {"confirmation": "no"}).status_code, 400)
        self.assertEqual(self.post("/delete_all_webhooks?type=ticket", {"confirmation": "DELETE"}).status_code, 302)
        self.assertEqual(self.api.calls[-1][0:2], ("DELETE", "TicketWebhooks/12"))

    def test_field_ids_do_not_collide_and_unchecking_removes(self):
        data = MultiDict([("action", "save_fields"), ("webhook_id", "12"),
                          ("field_inventory", "standard:7"), ("field_inventory", "udf:7"),
                          ("udf[7]", "subscribe")])
        response = self.post("/savefields?type=company", data)
        self.assertEqual(response.json, {"success": True, "errors": []})
        writes = [c for c in self.api.calls if c[0] in ("DELETE", "POST")]
        self.assertEqual(writes, [("DELETE", "CompanyWebhooks/12/Fields/88", None)])

    def test_missing_inventory_makes_no_writes(self):
        response = self.post("/savefields", {"action": "save_fields", "webhook_id": 12})
        self.assertEqual(response.status_code, 409)
        self.assertFalse(any(c[0] in ("POST", "DELETE") for c in self.api.calls))

    def test_errors_are_not_success(self):
        self.api.failure = "API unavailable"
        response = self.post("/savefields", {"action": "save_fields", "webhook_id": 12})
        self.assertEqual(response.status_code, 502)
        self.assertFalse(response.json["success"])
        self.assertEqual(self.browser.get("/webhook").status_code, 502)

    def test_validation_and_missing_credentials(self):
        self.assertEqual(self.browser.get("/webhook?type=invalid").status_code, 400)
        self.assertEqual(self.browser.get("/manage_webhooks?webhook_id=wrong").status_code, 400)
        self.path.unlink()
        self.assertIn("credentials", self.browser.get("/webhook").location)

    def test_field_recreation_failure_restores_previous_flags(self):
        groups = [{"key": "standard", "child": "Fields", "options": [{"value": 7, "label": "Name"}],
                   "existing": {"7": {"id": 88, "isSubscribedField": True, "isDisplayAlwaysField": False}}}]
        with patch.object(self.api, "request", side_effect=[{}, APIError("Create failed"), {}]) as call:
            errors = save_selections(self.api, "company", 12, MultiDict({"standard[7]": "display"}), groups)
            self.assertEqual(len(errors), 1)
            self.assertEqual(call.call_args_list[2].args[2]["IsSubscribedField"], 1)
            self.assertEqual(call.call_args_list[2].args[2]["IsDisplayAlwaysField"], 0)


class TransportTests(unittest.TestCase):
    def setUp(self):
        self.client = AutotaskClient({"apiUser": "user", "apiSecret": "secret", "integrationCode": "code"})

    def test_pagination(self):
        next_url = self.client.base_url + "/CompanyWebhooks/query?next=2"
        with patch.object(self.client, "request", side_effect=[
            {"items": [{"id": 1}], "pageDetails": {"nextPageUrl": next_url}},
            {"items": [{"id": 2}]}]) as request:
            self.assertEqual(self.client.items("CompanyWebhooks/query", {"filter": []}), [{"id": 1}, {"id": 2}])
            self.assertEqual(request.call_args.args, ("GET", next_url))

    def test_other_hosts_rejected_before_network(self):
        with self.assertRaises(APIError):
            self.client.request("GET", "https://example.com/steal")

    def test_empty_204_response(self):
        with patch("common.build_opener") as opener:
            opener.return_value.open.return_value.__enter__.return_value.read.return_value = b""
            self.assertEqual(self.client.request("DELETE", "CompanyWebhooks/1"), {})

    def test_http_errors_and_bad_json(self):
        with patch("common.build_opener") as opener:
            opener.return_value.open.side_effect = HTTPError("url", 403, "Forbidden", {}, None)
            with self.assertRaisesRegex(APIError, "HTTP 403"):
                self.client.request("GET", "Companies/entityInformation")
        with patch("common.build_opener") as opener:
            opener.return_value.open.return_value.__enter__.return_value.read.return_value = b"not-json"
            with self.assertRaisesRegex(APIError, "invalid JSON"):
                self.client.request("GET", "Companies/entityInformation")


if __name__ == "__main__":
    unittest.main()
