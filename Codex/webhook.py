"""List, create, update and delete company, ticket and contact webhooks."""
from flask import Blueprint, abort, flash, redirect, render_template, request, url_for
from urllib.parse import urlparse
from common import TYPES, client, webhook_type, positive_id, load_credentials, credentials_present

bp = Blueprint("webhook", __name__)


def webhook_payload(form):
    url = form.get("webhookUrl", "").strip()
    email = form.get("notificationEmailAddress", "").strip()
    name = form.get("webhook_name", "").strip()
    if urlparse(url).scheme not in ("http", "https") or not urlparse(url).hostname or not name or "@" not in email:
        abort(400, "Provide a webhook URL, notification email and name.")
    return {"WebhookUrl": url, "DeactivationUrl": url,
            "NotificationEmailAddress": email, "Name": name}


@bp.route("/webhook", methods=["GET", "POST"])
def index():
    kind = webhook_type()
    if not credentials_present(load_credentials()):
        return redirect(url_for("credentials.index"))
    api = client()
    endpoint = TYPES[kind] + "Webhooks"
    if request.method == "POST":
        action = request.form.get("action")
        if action == "delete":
            api.request("DELETE", f"{endpoint}/{positive_id(request.form.get('id'))}")
            flash("Webhook deleted.")
        elif action in ("create", "update"):
            payload = webhook_payload(request.form)
            if action == "update":
                payload["id"] = positive_id(request.form.get("id"))
                api.request("PATCH", endpoint, payload)
                flash("Webhook updated.")
            else:
                secret = request.form.get("secretKey", "")
                if not secret:
                    abort(400, "A webhook secret key is required.")
                payload.update(IsActive=True, isReady=True, SecretKey=secret,
                               IsSubscribedToCreateEvents=True, isSubscribedToDeleteEvents=True,
                               isSubscribedToUpdateEvents=True, SendThresholdExceededNotification=False)
                result = api.request("POST", endpoint, payload)
                webhook_id = result.get("itemId", result.get("id"))
                flash("Webhook created.")
                if webhook_id:
                    return redirect(url_for("manage_webhooks.index", type=kind, webhook_id=webhook_id))
        else:
            abort(400, "Unknown action.")
        return redirect(url_for("webhook.index", type=kind))
    hooks = api.items(endpoint + "/query", {"filter": []})
    return render_template("webhooks.html", kind=kind, webhooks=hooks,
                           default_url=load_credentials().get("webhookUrl", ""))
