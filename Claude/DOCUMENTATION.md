# Autotask Webhook Manager — User Guide

## What this tool does

Autotask can call a URL of yours whenever a Company, Ticket or Contact changes.
Setting that up through the API takes several calls: create the webhook, then
tell it which fields should *trigger* it and which fields should be *included*
in the payload it sends. This tool does all of that from three pages.

## Before you start

You need an Autotask API user with these three values:

- **API User** — the API-only resource's username
- **API Secret** — its password
- **Integration Code** — your tracking identifier

Plus the **Base URL** for your Autotask zone, e.g.
`https://webservices2.autotask.net/ATServicesRest/v1.0`. If you're not sure
which zone number you're on, check `zoneInformation` in the Autotask API docs.

Put these in `.env` (copy `.env.example`). They are never committed — `.env`
and `creds.json` are both gitignored.

## Page 1 — Credentials

Open http://localhost:5001.

Fill in the API details and press:

- **Test Connection** — calls `Companies/entityInformation`. A green message
  means the credentials work. A red one shows the HTTP status Autotask returned
  (401 usually means a wrong secret or integration code).
- **Save & Continue** — writes the values to `creds.json` so you don't retype
  them next time.

The three webhook fields on this page (URL, notification email, name) are just
defaults that pre-fill the create form later. You can leave them blank.

## Page 2 — Manage Webhooks

Choose **Company**, **Ticket** or **Contact** at the top. Each type has its own
set of webhooks in Autotask; switching tabs re-queries that type.

For each existing webhook you can:

- **Save Changes** — edit its URL, notification email or name
- **Edit Fields** — select this webhook and go to the field picker
- **Delete** — remove it (asks for confirmation)

Below the list:

- **Delete All … Webhooks** — deletes every webhook of the current type. Asks
  for confirmation, then reports how many were removed and lists any failures.
- **Create New … Webhook** — enter a URL, notification email and name. The new
  webhook is created subscribed to create, update and delete events, marked
  active, and you're taken straight to its field picker.

The secret key Autotask sends back with each webhook call comes from
`AUTOTASK_WEBHOOK_SECRET_KEY` in `.env`. Set it to something real before you
use this in production — use it to verify incoming calls are genuinely from
Autotask.

## Page 3 — Select Fields

Shows the webhook's URL, ID and name at the top so you know what you're
editing, then two tables:

- **Standard Fields** — the entity's built-in fields
- **User-Defined Fields (UDF)** — your custom fields

Each row has two tick boxes:

- **Trigger** — a change to this field fires the webhook
- **Include** — this field's value is always sent in the payload, whether or
  not it changed

The **Select All** boxes in each column header tick every row in that column.

Press **Save Field Selections**. Each ticked field is written to Autotask one
at a time (existing row deleted, new one created), so with a lot of fields this
can take a while — the spinner stays up until it's done. If anything fails you
get the specific field name and the API error, not a generic message.

Fields you untick are left as they are in Autotask rather than being removed —
this matches the original PHP behaviour. To clear a field's subscription
entirely, remove it in Autotask directly.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| "Enter your Autotask API credentials first" | One of user / secret / integration code / base URL is blank |
| Test Connection returns HTTP 401 | Wrong secret or integration code |
| Test Connection returns HTTP 404 | Wrong zone in the Base URL |
| "No webhook selected" on the fields page | Go to Manage Webhooks and press Edit Fields on one |
| No standard fields listed | The API user lacks permission on that entity |
| Port 5001 already in use | `PORT=5002 python app.py` |

## Security notes

- Credentials live in `.env` and `creds.json`, both gitignored. Neither is ever
  sent anywhere except to Autotask.
- TLS certificates are verified on every call. The original PHP disabled this.
- The original `delete_all_webhooks.php` had a live API user, secret and
  integration code hardcoded in it. Those are in git history — rotate that
  API user's secret in Autotask.
