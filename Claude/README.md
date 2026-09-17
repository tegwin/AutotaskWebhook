# Autotask Webhook Manager (Python)

A Flask port of the original PHP tool in the parent folder. Same job: create and
manage Autotask webhooks for Companies, Tickets and Contacts, and choose which
fields each webhook triggers on and includes in its payload.

## Quick start

```bash
cd Claude
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env      # fill in your Autotask API details
python app.py
```

Then open **http://localhost:5001** (port 5000 is taken by Docker on this Mac).

Either start method uses 5001:

```bash
python app.py     # reads PORT from .env, defaults to 5001
flask run         # reads FLASK_RUN_PORT from .flaskenv
```

To use a different port: `PORT=5002 python app.py`. If 5001 is busy, the app
now says so and prints the `lsof` command to find what is holding it.

## What changed from the PHP

| PHP | Python |
|---|---|
| Hardcoded API credentials in `delete_all_webhooks.php` | `.env` only, gitignored |
| `$_SESSION` + `creds.json` | `creds.json` seeded from `.env` |
| Repeated `curl_init` blocks | one `AutotaskClient` in `autotask.py` |
| `CURLOPT_SSL_VERIFYPEER => false` | SSL verification left on |
| Save-fields JS always reported success | reports real API errors |
| Delete via `GET ?delete_id=` link | `POST` with confirmation |
| `credentials.php` and `manage_webhooks.php` both had credential forms | one credentials page |

`test_credentials.php` is folded into the **Test Connection** button, and
`delete_all_webhooks.php` into the **Delete All** button on the webhooks page.

## Files

- `app.py` — routes
- `autotask.py` — Autotask REST API client
- `config.py` — webhook type map, credential load/save
- `templates/` — Jinja templates
- `tests/smoke_test.py` — exercises every route against a stubbed API

## Tests

```bash
.venv/bin/python tests/smoke_test.py
```

See [DOCUMENTATION.md](DOCUMENTATION.md) for the user guide.
