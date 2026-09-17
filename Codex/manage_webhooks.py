"""Field-selection page, replacing manage_webhooks.php."""
from flask import Blueprint, redirect, render_template, request, url_for
from common import APIError, TYPES, client, positive_id, webhook_type

bp = Blueprint("manage_webhooks", __name__)
GROUPS = (("standard", "Fields", "WebhookFields", "fieldID"),
          ("udf", "UdfFields", "WebhookUdfFields", "udfFieldID"))


def field_groups(api, kind, webhook_id):
    groups = []
    for key, child, metadata, id_key in GROUPS:
        response = api.request("GET", TYPES[kind] + metadata + "/entityInformation/fields")
        match = next((f for f in response.get("fields", [])
                      if f.get("name", "").lower() == id_key.lower()), None)
        if match is None:
            raise APIError(f"Autotask metadata is missing {id_key}.")
        options = match.get("picklistValues", [])
        assignments = api.items(f"{TYPES[kind]}Webhooks/{webhook_id}/{child}")
        existing = {str(row[id_key]): row for row in assignments}
        groups.append(dict(key=key, child=child, id_key=id_key, options=options, existing=existing))
    return groups


@bp.get("/manage_webhooks")
def index():
    kind = webhook_type()
    if request.args.get("back") or not request.args.get("webhook_id"):
        return redirect(url_for("credentials.index") if request.args.get("back")
                        else url_for("webhook.index", type=kind))
    webhook_id = positive_id(request.args.get("webhook_id"))
    groups = field_groups(client(), kind, webhook_id)
    return render_template("fields.html", kind=kind, webhook_id=webhook_id, groups=groups)
