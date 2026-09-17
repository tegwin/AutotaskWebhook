"""Read-only credential test endpoint (replacement for test_credentials.php)."""
from flask import Blueprint, render_template
from common import client

bp = Blueprint("test_credentials", __name__)


@bp.get("/test_credentials")
def index():
    client().request("GET", "Companies/entityInformation")
    return render_template("message.html", message="Credentials are working.")
