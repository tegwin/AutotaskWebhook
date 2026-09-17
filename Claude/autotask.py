"""Thin wrapper around the Autotask REST API.

Replaces the repeated curl blocks in the PHP. SSL verification is left on
(the PHP disabled it), and every call goes through one place so headers and
timeouts stay consistent.
"""

import requests

TIMEOUT = 30


class AutotaskError(Exception):
    """Raised when Autotask returns an error we want to show the user."""


class AutotaskClient:
    def __init__(self, api_user, api_secret, integration_code, base_url):
        self.api_user = api_user
        self.api_secret = api_secret
        self.integration_code = integration_code
        self.base_url = (base_url or "").rstrip("/")

    @property
    def is_configured(self):
        return all([self.api_user, self.api_secret, self.integration_code, self.base_url])

    def _headers(self):
        return {
            "ApiIntegrationCode": self.integration_code,
            "UserName": self.api_user,
            "Secret": self.api_secret,
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

    def request(self, method, path, json_body=None, params=None):
        """Return (status_code, parsed_body). Body is None if not JSON."""
        url = f"{self.base_url}/{path.lstrip('/')}"
        response = requests.request(
            method,
            url,
            headers=self._headers(),
            json=json_body,
            params=params,
            timeout=TIMEOUT,
        )
        try:
            body = response.json()
        except ValueError:
            body = None
        return response.status_code, body

    # --- Connection test -------------------------------------------------

    def test_connection(self):
        """Return (ok, message). Used by the credentials page."""
        try:
            status, body = self.request("GET", "Companies/entityInformation")
        except requests.RequestException as exc:
            return False, f"Connection error: {exc}"
        if status == 200:
            return True, "Connection successful."
        return False, f"Test failed (HTTP {status}): {_error_text(body)}"

    # --- Webhooks --------------------------------------------------------

    def list_webhooks(self, endpoint):
        status, body = self.request("POST", f"{endpoint}/query", json_body={"filter": []})
        if status < 200 or status >= 300:
            raise AutotaskError(f"Failed to fetch webhooks (HTTP {status}): {_error_text(body)}")
        return (body or {}).get("items", [])

    def find_webhook_by_url(self, endpoint, webhook_url):
        """The PHP looked a webhook up by its URL to recover its ID."""
        if not webhook_url:
            return None
        payload = {"filter": [{"field": "WebhookUrl", "op": "eq", "value": webhook_url}]}
        status, body = self.request("POST", f"{endpoint}/query", json_body=payload)
        if status not in (200, 201):
            raise AutotaskError(f"Webhook lookup failed (HTTP {status}): {_error_text(body)}")
        items = (body or {}).get("items", [])
        return items[0] if items else None

    def create_webhook(self, endpoint, webhook_url, notification_email, name, secret_key):
        payload = {
            "IsActive": True,
            "isReady": True,
            "WebhookUrl": webhook_url,
            "DeactivationUrl": webhook_url,
            "SecretKey": secret_key,
            "NotificationEmailAddress": notification_email,
            "IsSubscribedToCreateEvents": True,
            "isSubscribedToDeleteEvents": True,
            "isSubscribedToUpdateEvents": True,
            "Name": name,
            "SendThresholdExceededNotification": False,
        }
        status, body = self.request("POST", endpoint, json_body=payload)
        if status >= 400 or body is None:
            raise AutotaskError(f"API error ({status}): {_error_text(body)}")
        return body.get("itemId") or body.get("id")

    def update_webhook(self, endpoint, webhook_id, webhook_url, notification_email, name):
        payload = {
            "id": int(webhook_id),
            "WebhookUrl": webhook_url,
            "NotificationEmailAddress": notification_email,
            "Name": name,
            "DeactivationUrl": webhook_url,
        }
        status, body = self.request("PATCH", endpoint, json_body=payload)
        if status >= 400 or body is None:
            raise AutotaskError(f"API error ({status}): {_error_text(body)}")
        return body

    def delete_webhook(self, endpoint, webhook_id):
        status, body = self.request("DELETE", f"{endpoint}/{webhook_id}")
        if status not in (200, 204):
            raise AutotaskError(f"Failed to delete webhook {webhook_id} (HTTP {status}): {_error_text(body)}")

    # --- Field metadata --------------------------------------------------

    def entity_fields(self, entity):
        status, body = self.request("GET", f"{entity}/entityInformation/fields")
        if status != 200:
            raise AutotaskError(f"Failed to fetch fields for {entity} (HTTP {status}).")
        return (body or {}).get("fields", [])

    def webhook_field_picklist(self, field_endpoint):
        """The selectable standard fields live in the first field's picklist."""
        fields = self.entity_fields_raw(field_endpoint)
        if not fields:
            return []
        return fields[0].get("picklistValues", []) or []

    def udf_field_picklist(self, udf_field_endpoint):
        """UDF choices live on the udfFieldID field's picklist."""
        try:
            fields = self.entity_fields_raw(udf_field_endpoint)
        except AutotaskError:
            return []
        for field in fields:
            if field.get("name") == "udfFieldID" and field.get("picklistValues"):
                return field["picklistValues"]
        return []

    def entity_fields_raw(self, endpoint):
        status, body = self.request("GET", f"{endpoint}/entityInformation/fields")
        if status != 200:
            raise AutotaskError(f"Failed to fetch webhook fields (HTTP {status}).")
        return (body or {}).get("fields", [])

    # --- Webhook field subscriptions -------------------------------------

    def list_webhook_fields(self, endpoint, webhook_id, udf=False):
        sub = "UdfFields" if udf else "Fields"
        status, body = self.request("GET", f"{endpoint}/{webhook_id}/{sub}")
        if status < 200 or status >= 300:
            return []
        if isinstance(body, list):
            return body
        return (body or {}).get("items", []) or []

    def delete_webhook_field(self, endpoint, webhook_id, row_id, udf=False):
        sub = "UdfFields" if udf else "Fields"
        status, body = self.request("DELETE", f"{endpoint}/{webhook_id}/{sub}/{row_id}")
        if status < 200 or status >= 300:
            raise AutotaskError(f"HTTP {status} - {_error_text(body)}")

    def create_webhook_field(self, endpoint, webhook_id, payload, udf=False):
        sub = "UdfFields" if udf else "Fields"
        status, body = self.request("POST", f"{endpoint}/{webhook_id}/{sub}", json_body=payload)
        if status < 200 or status >= 300:
            raise AutotaskError(f"HTTP {status} - {_error_text(body)}")


def _error_text(body):
    """Autotask returns errors in an 'errors' list; fall back to the raw body."""
    if isinstance(body, dict) and body.get("errors"):
        return " | ".join(str(e) for e in body["errors"])
    if body is None:
        return "no response body"
    return str(body)
