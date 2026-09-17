"""Smoke test: exercises every route against a stubbed Autotask API."""

import json
import os
import sys
import tempfile
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

os.environ["AUTOTASK_API_USER"] = "user@example.com"
os.environ["AUTOTASK_API_SECRET"] = "secret"
os.environ["AUTOTASK_INTEGRATION_CODE"] = "CODE"
os.environ["AUTOTASK_BASE_URL"] = "https://example.invalid/atservicesrest/v1.0"
os.environ["AUTOTASK_WEBHOOK_URL"] = "https://hooks.example.com/in"
os.environ["FLASK_SECRET_KEY"] = "test"

import config  # noqa: E402

config.CRED_FILE = Path(tempfile.mkdtemp()) / "creds.json"

import app as app_module  # noqa: E402

CALLS = []


def fake_request(method, url, headers=None, json=None, params=None, timeout=None):
    """Stand in for requests.request, returning canned Autotask payloads."""
    CALLS.append((method, url))

    class Response:
        def __init__(self, status, body):
            self.status_code = status
            self._body = body

        def json(self):
            return self._body

    if url.endswith("/Companies/entityInformation"):
        return Response(200, {"fields": []})
    if url.endswith("Webhooks/query"):
        return Response(200, {"items": [{
            "id": 42,
            "name": "Test hook",
            "webhookUrl": "https://hooks.example.com/in",
            "notificationEmailAddress": "ops@example.com",
        }]})
    if url.endswith("CompanyWebhookFields/entityInformation/fields"):
        return Response(200, {"fields": [{"picklistValues": [
            {"label": "companyName", "value": "101"},
            {"label": "phone", "value": "102"},
        ]}]})
    if url.endswith("CompanyWebhookUdfFields/entityInformation/fields"):
        return Response(200, {"fields": [{"name": "udfFieldID", "picklistValues": [
            {"label": "Custom One", "value": "900"},
        ]}]})
    if url.endswith("/Fields"):
        if method == "POST":
            return Response(201, {"itemId": 7})
        return Response(200, {"items": [
            {"id": 5, "fieldID": 101, "isSubscribedField": True, "isDisplayAlwaysField": False},
        ]})
    if url.endswith("/UdfFields"):
        if method == "POST":
            return Response(201, {"itemId": 8})
        return Response(200, {"items": []})
    if "/Fields/" in url or "/UdfFields/" in url:
        return Response(204, None)
    if method == "POST":
        return Response(201, {"itemId": 99})
    if method == "PATCH":
        return Response(200, {"itemId": 42})
    if method == "DELETE":
        return Response(200, {})
    return Response(200, {})


def run():
    app_module.app.config["TESTING"] = True
    client = app_module.app.test_client()

    with patch("autotask.requests.request", side_effect=fake_request):
        checks = []

        r = client.get("/")
        checks.append(("GET /", r.status_code == 200 and b"Autotask API Credentials" in r.data))

        r = client.post("/", data={
            "api_user": "user@example.com", "api_secret": "secret",
            "integration_code": "CODE", "base_url": "https://example.invalid/atservicesrest/v1.0",
            "webhook_url": "https://hooks.example.com/in",
            "notification_email": "ops@example.com", "webhook_name": "Test hook",
            "test_connection": "1",
        }, follow_redirects=True)
        checks.append(("POST / (test connection)", b"Connection successful" in r.data))

        r = client.get("/webhooks?type=company")
        checks.append(("GET /webhooks", r.status_code == 200 and b"Test hook" in r.data))

        r = client.get("/webhooks?type=nonsense")
        checks.append(("bad type rejected", r.status_code == 404))

        r = client.post("/webhooks/create?type=ticket", data={
            "webhook_url": "https://hooks.example.com/t",
            "notification_email": "ops@example.com",
            "webhook_name": "Ticket hook",
        }, follow_redirects=True)
        checks.append(("POST create webhook", b"created successfully" in r.data))

        r = client.post("/webhooks/update?type=company", data={
            "webhook_id": "42", "webhook_url": "https://hooks.example.com/in",
            "notification_email": "ops@example.com", "webhook_name": "Renamed",
        }, follow_redirects=True)
        checks.append(("POST update webhook", b"updated successfully" in r.data))

        r = client.post("/webhooks/42/select?type=company", data={
            "webhook_url": "https://hooks.example.com/in",
            "notification_email": "ops@example.com", "webhook_name": "Test hook",
        }, follow_redirects=True)
        checks.append(("select webhook -> fields", b"companyName" in r.data and b"Custom One" in r.data))
        checks.append(("existing selection pre-ticked", r.data.count(b"checked") >= 1))

        r = client.post(
            "/fields/save?type=company&webhook_id=42",
            json={"fields": {
                "101": {"name": "companyName", "udf": False, "subscribe": True, "display": True},
                "900": {"name": "Custom One", "udf": True, "subscribe": True, "display": False},
            }},
        )
        body = r.get_json()
        checks.append(("POST /fields/save", r.status_code == 200 and body["success"] is True))

        r = client.post("/webhooks/42/delete?type=company", follow_redirects=True)
        checks.append(("POST delete webhook", b"deleted" in r.data))

        r = client.post("/webhooks/delete-all?type=company", follow_redirects=True)
        checks.append(("POST delete all", b"Deleted 1 webhook" in r.data))

        stored = json.loads(config.CRED_FILE.read_text())
        checks.append(("creds persisted", stored["integration_code"] == "CODE"))

    failed = [name for name, ok in checks if not ok]
    for name, ok in checks:
        print(f"{'PASS' if ok else 'FAIL'}  {name}")
    print(f"\n{len(checks) - len(failed)}/{len(checks)} passed")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(run())
