"""Run with python app.py, then open http://127.0.0.1:5000."""
import os
from pathlib import Path
import secrets

from flask import Flask, abort, jsonify, redirect, render_template, request, session, url_for
from common import APIError, AutotaskClient
import credentials
import delete_all_webhooks
import manage_webhooks
import savefields
import test_credentials
import webhook


def create_app(config=None):
    app = Flask(__name__)
    app.config.update(
        SECRET_KEY=os.environ.get("FLASK_SECRET_KEY") or secrets.token_hex(32),
        CREDENTIALS_FILE=Path(__file__).parent / "creds.json",
        CLIENT_FACTORY=AutotaskClient,
        MAX_CONTENT_LENGTH=2 * 1024 * 1024,
        SESSION_COOKIE_HTTPONLY=True,
        SESSION_COOKIE_SAMESITE="Strict",
    )
    if config:
        app.config.update(config)
    for module in (credentials, delete_all_webhooks, manage_webhooks, savefields, test_credentials, webhook):
        app.register_blueprint(module.bp)

    @app.before_request
    def protect_forms():
        if "csrf_token" not in session:
            session["csrf_token"] = secrets.token_urlsafe(32)
        if request.method == "POST":
            token = request.form.get("csrf_token", "")
            if not secrets.compare_digest(token, session["csrf_token"]):
                abort(400, "Invalid form token. Reload the page and try again.")

    @app.after_request
    def response_headers(response):
        response.headers["Cache-Control"] = "no-store"
        response.headers["X-Content-Type-Options"] = "nosniff"
        response.headers["X-Frame-Options"] = "DENY"
        response.headers["Referrer-Policy"] = "no-referrer"
        response.headers["Permissions-Policy"] = "geolocation=(), microphone=(), camera=()"
        response.headers["X-Permitted-Cross-Domain-Policies"] = "none"
        response.headers["Strict-Transport-Security"] = "max-age=31536000; includeSubDomains"
        # Bootstrap is loaded from jsDelivr; nothing here needs inline script.
        response.headers["Content-Security-Policy"] = (
            "default-src 'self'; "
            "style-src 'self' https://cdn.jsdelivr.net 'unsafe-inline'; "
            "script-src 'self' https://cdn.jsdelivr.net; "
            "img-src 'self' data:; "
            "frame-ancestors 'none'"
        )
        return response

    @app.get("/")
    def index():
        return redirect(url_for("webhook.index"))

    @app.errorhandler(APIError)
    def api_error(error):
        return render_template("message.html", message=str(error), error=True), 502

    @app.errorhandler(400)
    def bad_request(error):
        if request.path.startswith("/savefields"):
            return jsonify(success=False, errors=[error.description]), 400
        return render_template("message.html", message=error.description, error=True), 400

    return app


app = create_app()

if __name__ == "__main__":
    app.run(host="127.0.0.1", port=int(os.environ.get("PORT", "5000")), debug=False)
