"""Save field selections and return truthful JSON, replacing savefields.php."""
from flask import Blueprint, abort, jsonify, request
from common import APIError, TYPES, client, positive_id, webhook_type
from manage_webhooks import field_groups

bp = Blueprint("savefields", __name__)


def save_selections(api, kind, webhook_id, submitted, groups):
    errors = []
    for group in groups:
        base = f"{TYPES[kind]}Webhooks/{webhook_id}/{group['child']}"
        for option in group["options"]:
            fid = str(option["value"])
            flags = submitted.getlist(f"{group['key']}[{fid}]")
            subscribe, display = "subscribe" in flags, "display" in flags
            old = group["existing"].get(fid)
            if old and bool(old.get("isSubscribedField")) == subscribe and bool(old.get("isDisplayAlwaysField")) == display:
                continue
            if not old and not (subscribe or display):
                continue
            try:
                if old:
                    api.request("DELETE", f"{base}/{old['id']}")
                if subscribe or display:
                    payload = {"WebhookID": webhook_id,
                               "IsSubscribedField": int(subscribe),
                               "IsDisplayAlwaysField": int(display)}
                    if group["key"] == "standard":
                        payload.update(fieldID=int(fid), FieldName=option["label"])
                    else:
                        payload["UdfFieldId"] = int(fid)
                    try:
                        api.request("POST", base, payload)
                    except APIError as exc:
                        # The PHP API strategy deletes then recreates. Restore the old
                        # flags if recreation fails, without pretending this is atomic.
                        if old:
                            restore = dict(payload, IsSubscribedField=int(bool(old.get("isSubscribedField"))),
                                           IsDisplayAlwaysField=int(bool(old.get("isDisplayAlwaysField"))))
                            try:
                                api.request("POST", base, restore)
                            except APIError:
                                raise APIError(f"{exc} Previous assignment could not be restored; reload and check this field.") from exc
                        raise
            except APIError as exc:
                errors.append(f"{group['key']} field {option['label']}: {exc}")
    return errors


@bp.post("/savefields")
def index():
    kind = webhook_type()
    webhook_id = positive_id(request.form.get("webhook_id"))
    if request.form.get("action") != "save_fields":
        abort(400, "Unknown action.")
    try:
        api = client()
        groups = field_groups(api, kind, webhook_id)
        # Require the full page's field inventory, preventing partial/malformed
        # requests from accidentally clearing every omitted checkbox.
        expected = {f"{g['key']}:{o['value']}" for g in groups for o in g['options']}
        if set(request.form.getlist("field_inventory")) != expected:
            return jsonify(success=False, errors=["Fields changed or the form is incomplete. Reload and try again."]), 409
        errors = save_selections(api, kind, webhook_id, request.form, groups)
        return jsonify(success=not errors, errors=errors), 200 if not errors else 502
    except APIError as exc:
        return jsonify(success=False, errors=[str(exc)]), 502
