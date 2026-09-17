"""Configuration: webhook type map and credential storage.

Credentials come from .env, and any edits made in the UI are persisted to
creds.json (gitignored) so they survive a restart. Nothing is hardcoded.
"""

import json
import os
from pathlib import Path

from dotenv import load_dotenv

BASE_DIR = Path(__file__).resolve().parent
CRED_FILE = BASE_DIR / "creds.json"

load_dotenv(BASE_DIR / ".env")

DEFAULT_BASE_URL = "https://webservices2.autotask.net/ATServicesRest/v1.0"

# One entry per webhook type the app can manage. Mirrors the PHP $webhookTypes.
WEBHOOK_TYPES = {
    "company": {
        "label": "Company",
        "entity": "Companies",
        "webhook_endpoint": "CompanyWebhooks",
        "field_endpoint": "CompanyWebhookFields",
        "udf_field_endpoint": "CompanyWebhookUdfFields",
        "default_name": "Company Created Webhook",
    },
    "ticket": {
        "label": "Ticket",
        "entity": "Tickets",
        "webhook_endpoint": "TicketWebhooks",
        "field_endpoint": "TicketWebhookFields",
        "udf_field_endpoint": "TicketWebhookUdfFields",
        "default_name": "Ticket Created Webhook",
    },
    "contact": {
        "label": "Contact",
        "entity": "Contacts",
        "webhook_endpoint": "ContactWebhooks",
        "field_endpoint": "ContactWebhookFields",
        "udf_field_endpoint": "ContactWebhookUdfFields",
        "default_name": "Contact Created Webhook",
    },
}

# Fields we persist to creds.json. Keys match the form field names.
CRED_KEYS = (
    "api_user",
    "api_secret",
    "integration_code",
    "base_url",
    "webhook_url",
    "notification_email",
    "webhook_name",
    "webhook_id",
)


def env_credentials():
    """Credential defaults taken from the environment."""
    return {
        "api_user": os.getenv("AUTOTASK_API_USER", ""),
        "api_secret": os.getenv("AUTOTASK_API_SECRET", ""),
        "integration_code": os.getenv("AUTOTASK_INTEGRATION_CODE", ""),
        "base_url": os.getenv("AUTOTASK_BASE_URL", DEFAULT_BASE_URL),
        "webhook_url": os.getenv("AUTOTASK_WEBHOOK_URL", ""),
        "notification_email": os.getenv("AUTOTASK_NOTIFICATION_EMAIL", ""),
        "webhook_name": os.getenv("AUTOTASK_WEBHOOK_NAME", ""),
        "webhook_id": "",
    }


def load_credentials():
    """Env defaults, overlaid with anything saved from the UI."""
    creds = env_credentials()
    if CRED_FILE.exists():
        try:
            stored = json.loads(CRED_FILE.read_text()) or {}
        except json.JSONDecodeError:
            stored = {}
        for key in CRED_KEYS:
            value = stored.get(key)
            if value not in (None, ""):
                creds[key] = value
    if not creds["base_url"]:
        creds["base_url"] = DEFAULT_BASE_URL
    return creds


def save_credentials(updates):
    """Merge updates into creds.json, keeping values we weren't given."""
    stored = {}
    if CRED_FILE.exists():
        try:
            stored = json.loads(CRED_FILE.read_text()) or {}
        except json.JSONDecodeError:
            stored = {}
    for key in CRED_KEYS:
        if key in updates and updates[key] is not None:
            stored[key] = updates[key]
    CRED_FILE.write_text(json.dumps(stored, indent=4))
    return load_credentials()


def webhook_secret_key():
    return os.getenv("AUTOTASK_WEBHOOK_SECRET_KEY", "")
