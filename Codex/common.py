"""Shared configuration, credential storage and Autotask REST transport."""
import json
import os
import tempfile
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin, urlparse
from urllib.request import Request, build_opener, HTTPRedirectHandler

from flask import abort, current_app, request

DEFAULT_URL = "https://webservices2.autotask.net/ATServicesRest/v1.0"
TYPES = {
    "company": "Company",
    "ticket": "Ticket",
    "contact": "Contact",
}
ENV_KEYS = {
    "apiUser": "AUTOTASK_API_USER",
    "apiSecret": "AUTOTASK_API_SECRET",
    "integrationCode": "AUTOTASK_INTEGRATION_CODE",
    "baseUrl": "AUTOTASK_BASE_URL",
}


class APIError(Exception):
    pass


def webhook_type():
    kind = request.args.get("type", "company")
    if kind not in TYPES:
        abort(400, "Invalid webhook type.")
    return kind


def positive_id(value):
    try:
        result = int(value)
        if result <= 0:
            raise ValueError
        return result
    except (TypeError, ValueError):
        abort(400, "A positive webhook ID is required.")


def load_credentials():
    path = Path(current_app.config["CREDENTIALS_FILE"])
    try:
        data = json.loads(path.read_text()) if path.exists() else {}
        if not isinstance(data, dict):
            raise ValueError("Expected a JSON object")
    except (OSError, ValueError) as exc:
        raise APIError("Cannot read the credentials file.") from exc
    data.setdefault("baseUrl", DEFAULT_URL)
    for key, env in ENV_KEYS.items():
        if os.environ.get(env):
            data[key] = os.environ[env]
    return data


def save_credentials(data):
    path = Path(current_app.config["CREDENTIALS_FILE"])
    path.parent.mkdir(parents=True, exist_ok=True)
    fd, temporary = tempfile.mkstemp(dir=path.parent)
    try:
        with os.fdopen(fd, "w") as stream:
            json.dump(data, stream, indent=2)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def credentials_present(data):
    return all(data.get(key) for key in ("apiUser", "apiSecret", "integrationCode"))


def validate_base_url(url):
    parsed = urlparse(url)
    if (parsed.scheme != "https" or not parsed.hostname
            or not parsed.hostname.lower().endswith(".autotask.net")
            or parsed.username or parsed.password or parsed.query or parsed.fragment
            or parsed.port not in (None, 443)):
        raise APIError("Use your HTTPS Autotask API URL on an autotask.net host.")
    return url.rstrip("/")


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class AutotaskClient:
    def __init__(self, credentials):
        if not credentials_present(credentials):
            raise APIError("Save your API credentials first.")
        self.base_url = validate_base_url(credentials.get("baseUrl", DEFAULT_URL))
        self.headers = {
            "ApiIntegrationCode": credentials["integrationCode"],
            "UserName": credentials["apiUser"],
            "Secret": credentials["apiSecret"],
            "Content-Type": "application/json",
            "Accept": "application/json",
        }

    def request(self, method, path, payload=None):
        url = path if path.startswith("https://") else self.base_url + "/" + path.lstrip("/")
        # Pagination must never forward credentials to a different host or API root.
        if not url.startswith(self.base_url + "/"):
            raise APIError("API returned an unexpected pagination URL.")
        req = Request(url, data=None if payload is None else json.dumps(payload).encode(),
                      headers=self.headers, method=method)
        try:
            with build_opener(NoRedirect()).open(req, timeout=30) as response:
                raw = response.read()
        except HTTPError as exc:
            # Do not echo remote bodies, which may contain credentials or customer data.
            raise APIError(f"Autotask returned HTTP {exc.code} for {method} {urlparse(url).path}.") from exc
        except (URLError, TimeoutError, OSError) as exc:
            raise APIError("Could not reach Autotask. Check your API URL and connection.") from exc
        if not raw.strip():
            return {}
        try:
            return json.loads(raw)
        except ValueError as exc:
            raise APIError("Autotask returned invalid JSON.") from exc

    def items(self, path, query=None):
        data = self.request("GET" if query is None else "POST", path, query)
        results, seen = [], set()
        while True:
            if isinstance(data, list):
                results.extend(data)
                break
            if not isinstance(data, dict) or not isinstance(data.get("items"), list):
                raise APIError("Autotask returned an unexpected list response.")
            results.extend(data["items"])
            next_url = (data.get("pageDetails") or {}).get("nextPageUrl")
            if not next_url:
                break
            next_url = urljoin(self.base_url + "/", next_url)
            if next_url in seen:
                raise APIError("Autotask repeated a pagination URL.")
            seen.add(next_url)
            data = self.request("GET", next_url)
        return results


def client():
    return current_app.config["CLIENT_FACTORY"](load_credentials())
