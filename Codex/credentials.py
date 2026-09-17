"""Replacement for credentials.php, using one shared server-side credential store."""
from flask import Blueprint, flash, redirect, render_template, request, url_for
from common import load_credentials, save_credentials, validate_base_url, current_app

bp = Blueprint("credentials", __name__)


@bp.route("/credentials", methods=["GET", "POST"])
def index():
    data = load_credentials()
    if request.method == "POST":
        for key in ("apiUser", "integrationCode", "baseUrl", "webhookUrl"):
            data[key] = request.form.get(key, "").strip()
        if request.form.get("apiSecret"):
            data["apiSecret"] = request.form["apiSecret"]
        data["baseUrl"] = validate_base_url(data["baseUrl"])
        if request.form.get("action") == "test":
            current_app.config["CLIENT_FACTORY"](data).request("GET", "Companies/entityInformation")
            flash("Connection successful. Save to keep any changes.")
        else:
            save_credentials(data)
            flash("Credentials saved.")
            return redirect(url_for("webhook.index"))
    return render_template("credentials.html", credentials=data)
