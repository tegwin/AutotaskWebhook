# Autotask Webhook Manager — Python conversion

Python conversion of the six PHP files in the parent directory. The original files are unchanged. This is a Flask web application, not a collection of standalone command-line scripts.

## Run locally

Requires Python 3.10 or newer.

```sh
cd /Users/christimm/Programming/AutotaskWebhook/Codex
python3 -m venv .venv
source .venv/bin/activate
python -m pip install -r requirements.txt
python app.py
```

Open http://127.0.0.1:5000 and enter your Autotask API username, secret, integration code and regional API base URL. Use **Test credentials**, then **Save credentials**. Choose Company, Ticket or Contact to manage webhooks and edit standard/UDF field selections. If port 5000 is busy, run `PORT=5001 python app.py`.

## File mapping

| Original | Python replacement |
| --- | --- |
| credentials.php | credentials.py — credential form and storage |
| test_credentials.php | test_credentials.py — read-only connection test |
| webhook.php | webhook.py — list/create/update/delete |
| manage_webhooks.php | manage_webhooks.py — standard/UDF field selection |
| savefields.php | savefields.py — JSON field-saving endpoint |
| delete_all_webhooks.php | delete_all_webhooks.py — deletion page redirect and bulk deletion |

`app.py` starts the app; `common.py` shares the REST client and credential handling; `templates/` contains the browser pages. All pages are served by Python at extensionless URLs such as `/credentials` and `/webhook`. No PHP routes or PHP runtime are used. Old GET mutation links and PHP form payloads are not supported; use the new forms. The old delete_all_webhooks.php actually listed company webhooks with individual deletion; this remains available in the main manager. Bulk deletion from manage_webhooks.php is available for the selected type.

## Credentials and configuration

Credentials entered in the UI are stored in `Codex/creds.json` with owner-only file permissions. API secrets are not stored in browser cookies or rendered back into HTML. No hardcoded PHP credentials were copied and no live API calls were made during conversion. The parent directory's credentials file is not imported automatically.

Alternatively set `AUTOTASK_API_USER`, `AUTOTASK_API_SECRET`, `AUTOTASK_INTEGRATION_CODE`, and `AUTOTASK_BASE_URL` in your shell. These override saved values on load. `.env` files are not automatically loaded. Use `FLASK_SECRET_KEY` for a stable session signing key; otherwise restarting the app invalidates existing form tokens.

The app binds to localhost and is intended as a single-user administrative tool. It has no user login or per-user credential separation. Add authentication and an appropriate production server before exposing it to other users. HTTPS certificate checking is enabled. API base URLs are restricted to HTTPS Autotask hosts, and redirects cannot forward credentials elsewhere.

## Conversion fixes and behavior

- Company, ticket and contact operations use their original REST endpoints and payloads.
- Credential pages share one store, eliminating the PHP scripts' incompatible session formats.
- Creation accepts the receiver's secret key instead of hardcoding one.
- Field IDs are read by metadata name, not by assuming the first metadata entry is correct.
- Standard fields and UDFs have separate selection namespaces, even when IDs overlap.
- Unchecking both flags removes the existing assignment; unchanged selections generate no writes.
- Field updates retain the PHP delete/recreate API approach. If recreation fails, the app attempts to restore the previous flags and reports failures. Multi-field saves and bulk deletions are not transactional: earlier successes can remain after a later failure. Reload to inspect current state.
- Full field inventories are checked before saving, so a stale/incomplete form cannot silently clear missing fields.
- API errors are reported accurately, including unsuccessful saves; no false success messages.
- Collection requests follow pagination; transport uses timeouts and TLS verification.
- Mutations require POST with a form token. Bulk deletion requires explicit confirmation.

## Verification

```sh
python -m unittest discover -s tests -v
```

Tests use simulated API responses and temporary credentials. Live Autotask behavior still needs checking with your account; local tests do not verify tenant permissions or the live API contract.
