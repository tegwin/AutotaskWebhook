"""Individual and bulk deletion, replacing the legacy deletion page."""
from flask import Blueprint, abort, flash, redirect, request, url_for
from common import TYPES, client, webhook_type

bp = Blueprint("delete_all_webhooks", __name__)


@bp.route("/delete_all_webhooks", methods=["GET", "POST"])
def index():
    kind = webhook_type()
    if request.method == "POST":
        if request.form.get("confirmation") != "DELETE":
            abort(400, "Type DELETE to confirm deleting every webhook of this type.")
        api = client()
        endpoint = TYPES[kind] + "Webhooks"
        hooks = api.items(endpoint + "/query", {"filter": []})
        for hook in hooks:
            api.request("DELETE", f"{endpoint}/{hook['id']}")
        flash(f"Deleted {len(hooks)} {kind} webhooks.")
    return redirect(url_for("webhook.index", type=kind))
