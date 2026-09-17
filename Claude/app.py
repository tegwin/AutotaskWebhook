"""Autotask Webhook Manager (Flask port of the original PHP tool).

Pages:
  /            credentials - enter/save API details and test the connection
  /webhooks    list, create, edit and delete webhooks for a given type
  /fields      choose which standard and UDF fields a webhook subscribes to
"""

import os
import secrets

from flask import (
    Flask,
    abort,
    flash,
    jsonify,
    redirect,
    render_template,
    request,
    url_for,
)
from flask_wtf.csrf import CSRFProtect

from autotask import AutotaskClient, AutotaskError
from config import (
    WEBHOOK_TYPES,
    load_credentials,
    save_credentials,
    webhook_secret_key,
)

app = Flask(__name__)
# A predictable key lets anyone forge a session cookie, so there is no usable
# default: unset means a fresh random key, and sessions simply do not survive
# a restart until FLASK_SECRET_KEY is set.
app.secret_key = os.getenv("FLASK_SECRET_KEY") or secrets.token_hex(32)

# Every POST here is a browser form, so CSRF applies to all of them.
csrf = CSRFProtect(app)


def get_type():
    """Validated webhook type from the query string."""
    wtype = request.args.get("type", "company")
    if wtype not in WEBHOOK_TYPES:
        abort(404, "Invalid webhook type.")
    return wtype


def get_client(creds=None):
    creds = creds or load_credentials()
    return AutotaskClient(
        creds["api_user"],
        creds["api_secret"],
        creds["integration_code"],
        creds["base_url"],
    )


@app.context_processor
def inject_types():
    return {"webhook_types": WEBHOOK_TYPES}


# --- Credentials ---------------------------------------------------------


@app.route("/", methods=["GET", "POST"])
def credentials():
    creds = load_credentials()

    if request.method == "POST":
        submitted = {
            "api_user": request.form.get("api_user", "").strip(),
            "api_secret": request.form.get("api_secret", "").strip(),
            "integration_code": request.form.get("integration_code", "").strip(),
            "base_url": request.form.get("base_url", "").strip(),
            "webhook_url": request.form.get("webhook_url", "").strip(),
            "notification_email": request.form.get("notification_email", "").strip(),
            "webhook_name": request.form.get("webhook_name", "").strip(),
        }
        creds = save_credentials(submitted)

        if "test_connection" in request.form:
            ok, message = get_client(creds).test_connection()
            flash(message, "success" if ok else "danger")
        else:
            flash("Credentials saved.", "success")
        return redirect(url_for("credentials"))

    return render_template("credentials.html", creds=creds)


# --- Webhooks ------------------------------------------------------------


@app.route("/webhooks")
def webhooks():
    wtype = get_type()
    config = WEBHOOK_TYPES[wtype]
    creds = load_credentials()
    client = get_client(creds)

    if not client.is_configured:
        flash("Enter your Autotask API credentials first.", "warning")
        return redirect(url_for("credentials"))

    items = []
    try:
        items = client.list_webhooks(config["webhook_endpoint"])
    except (AutotaskError, Exception) as exc:  # network errors included
        flash(str(exc), "danger")

    return render_template(
        "webhooks.html",
        wtype=wtype,
        config=config,
        creds=creds,
        webhooks=items,
    )


@app.route("/webhooks/create", methods=["POST"])
def create_webhook():
    wtype = get_type()
    config = WEBHOOK_TYPES[wtype]
    client = get_client()

    webhook_url = request.form.get("webhook_url", "").strip()
    notification_email = request.form.get("notification_email", "").strip()
    name = request.form.get("webhook_name", "").strip() or config["default_name"]

    try:
        webhook_id = client.create_webhook(
            config["webhook_endpoint"],
            webhook_url,
            notification_email,
            name,
            webhook_secret_key(),
        )
    except (AutotaskError, Exception) as exc:
        flash(str(exc), "danger")
        return redirect(url_for("webhooks", type=wtype))

    # Remember the new webhook so the fields page knows what to work on.
    save_credentials(
        {
            "webhook_url": webhook_url,
            "notification_email": notification_email,
            "webhook_name": name,
            "webhook_id": str(webhook_id) if webhook_id else "",
        }
    )
    flash("Webhook created successfully.", "success")
    return redirect(url_for("fields", type=wtype))


@app.route("/webhooks/update", methods=["POST"])
def update_webhook():
    wtype = get_type()
    config = WEBHOOK_TYPES[wtype]
    client = get_client()

    try:
        client.update_webhook(
            config["webhook_endpoint"],
            request.form["webhook_id"],
            request.form.get("webhook_url", "").strip(),
            request.form.get("notification_email", "").strip(),
            request.form.get("webhook_name", "").strip(),
        )
        flash("Webhook updated successfully.", "success")
    except (AutotaskError, Exception) as exc:
        flash(str(exc), "danger")

    return redirect(url_for("webhooks", type=wtype))


@app.route("/webhooks/<int:webhook_id>/delete", methods=["POST"])
def delete_webhook(webhook_id):
    wtype = get_type()
    config = WEBHOOK_TYPES[wtype]
    try:
        get_client().delete_webhook(config["webhook_endpoint"], webhook_id)
        flash(f"Webhook {webhook_id} deleted.", "success")
    except (AutotaskError, Exception) as exc:
        flash(str(exc), "danger")
    return redirect(url_for("webhooks", type=wtype))


@app.route("/webhooks/delete-all", methods=["POST"])
def delete_all_webhooks():
    wtype = get_type()
    config = WEBHOOK_TYPES[wtype]
    client = get_client()

    deleted, failed = 0, []
    try:
        items = client.list_webhooks(config["webhook_endpoint"])
    except (AutotaskError, Exception) as exc:
        flash(str(exc), "danger")
        return redirect(url_for("webhooks", type=wtype))

    for item in items:
        try:
            client.delete_webhook(config["webhook_endpoint"], item["id"])
            deleted += 1
        except (AutotaskError, Exception) as exc:
            failed.append(f"{item['id']}: {exc}")

    flash(f"Deleted {deleted} webhook(s).", "success" if not failed else "warning")
    for message in failed:
        flash(message, "danger")
    return redirect(url_for("webhooks", type=wtype))


@app.route("/webhooks/<int:webhook_id>/select", methods=["POST"])
def select_webhook(webhook_id):
    """The PHP 'Edit Fields' link: remember this webhook, then edit its fields."""
    wtype = get_type()
    save_credentials(
        {
            "webhook_id": str(webhook_id),
            "webhook_url": request.form.get("webhook_url", "").strip(),
            "notification_email": request.form.get("notification_email", "").strip(),
            "webhook_name": request.form.get("webhook_name", "").strip(),
        }
    )
    return redirect(url_for("fields", type=wtype))


# --- Field selection -----------------------------------------------------


@app.route("/fields")
def fields():
    wtype = get_type()
    config = WEBHOOK_TYPES[wtype]
    creds = load_credentials()
    client = get_client(creds)

    if not client.is_configured:
        flash("Enter your Autotask API credentials first.", "warning")
        return redirect(url_for("credentials"))

    webhook_id = creds.get("webhook_id") or ""

    try:
        # If we don't have an ID yet, recover it from the saved webhook URL.
        if not webhook_id:
            existing = client.find_webhook_by_url(
                config["webhook_endpoint"], creds.get("webhook_url")
            )
            if existing:
                webhook_id = str(existing["id"])
                creds = save_credentials({"webhook_id": webhook_id})

        standard_fields = client.webhook_field_picklist(config["field_endpoint"])
        udf_fields = client.udf_field_picklist(config["udf_field_endpoint"])
    except (AutotaskError, Exception) as exc:
        flash(str(exc), "danger")
        return redirect(url_for("webhooks", type=wtype))

    selected = _current_selections(client, config, webhook_id)

    return render_template(
        "fields.html",
        wtype=wtype,
        config=config,
        creds=creds,
        webhook_id=webhook_id,
        standard_fields=standard_fields,
        udf_fields=udf_fields,
        selected=selected,
    )


def _current_selections(client, config, webhook_id):
    """Map field ID -> ['subscribe', 'display'] for the tick boxes."""
    selected = {}
    if not webhook_id:
        return selected

    endpoint = config["webhook_endpoint"]
    for udf in (False, True):
        rows = client.list_webhook_fields(endpoint, webhook_id, udf=udf)
        id_key = "udfFieldID" if udf else "fieldID"
        for row in rows:
            field_id = row.get(id_key)
            if not field_id:
                continue
            marks = selected.setdefault(str(field_id), [])
            if row.get("isSubscribedField"):
                marks.append("subscribe")
            if row.get("isDisplayAlwaysField"):
                marks.append("display")
    return selected


@app.route("/fields/save", methods=["POST"])
def save_fields():
    """AJAX endpoint. Rewrites each field row: delete the old one, create anew."""
    wtype = get_type()
    config = WEBHOOK_TYPES[wtype]
    creds = load_credentials()
    client = get_client(creds)
    endpoint = config["webhook_endpoint"]

    webhook_id = request.args.get("webhook_id") or creds.get("webhook_id")
    if not webhook_id:
        return jsonify(success=False, errors=["No webhook selected."]), 400

    payload = request.get_json(silent=True) or {}
    # Selections arrive as {"<field_id>": {"name": "...", "subscribe": bool, "display": bool}}
    selections = payload.get("fields", {})
    errors = []

    for field_id, choice in selections.items():
        is_udf = bool(choice.get("udf"))
        try:
            _replace_field_row(
                client,
                endpoint,
                webhook_id,
                field_id,
                choice,
                is_udf,
            )
        except AutotaskError as exc:
            label = choice.get("name") or field_id
            errors.append(f"{'UDF' if is_udf else 'Field'} '{label}': {exc}")
        except Exception as exc:  # network failure
            errors.append(f"{choice.get('name') or field_id}: {exc}")

    return jsonify(success=not errors, errors=errors)


def _replace_field_row(client, endpoint, webhook_id, field_id, choice, is_udf):
    """Delete any existing row for this field, then create the new one."""
    id_key = "udfFieldID" if is_udf else "fieldID"
    existing = None
    for row in client.list_webhook_fields(endpoint, webhook_id, udf=is_udf):
        if str(row.get(id_key)) == str(field_id):
            existing = row.get("id")
            break

    if existing is not None:
        client.delete_webhook_field(endpoint, webhook_id, existing, udf=is_udf)

    # Nothing ticked means the field should simply stay removed.
    if not choice.get("subscribe") and not choice.get("display"):
        return

    body = {
        "WebhookID": int(webhook_id),
        "IsSubscribedField": 1 if choice.get("subscribe") else 0,
        "IsDisplayAlwaysField": 1 if choice.get("display") else 0,
    }
    if is_udf:
        body["UdfFieldId"] = int(field_id)
    else:
        body["fieldID"] = int(field_id)
        body["FieldName"] = choice.get("name", "")

    client.create_webhook_field(endpoint, webhook_id, body, udf=is_udf)


# Port 5000 is taken by Docker on this Mac, so this app lives on 5001.
DEFAULT_PORT = 5001


if __name__ == "__main__":
    host = os.getenv("HOST", "127.0.0.1")
    port = int(os.getenv("PORT") or DEFAULT_PORT)

    try:
        # Debug puts an interactive console on every error page, so it is opt-in.
        debug = os.getenv("FLASK_DEBUG", "").lower() in ("1", "true", "yes")
        app.run(debug=debug, host=host, port=port)
    except OSError as exc:
        # Nearly always "Address already in use" - say which port and who has it.
        raise SystemExit(
            f"Could not start on {host}:{port} - {exc}\n"
            f"Find what is using it with:  lsof -nP -iTCP:{port} -sTCP:LISTEN\n"
            f"Or pick another port with:   PORT=5002 python app.py"
        )
