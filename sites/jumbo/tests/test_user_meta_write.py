"""Mock-httpx tests for the scoped user-meta write surface.

Run:  uv run --with pytest --with pytest-asyncio --with 'fastmcp>=3.2,<4' \
          --with httpx python -m pytest tests -q

No live WordPress: every request is served by an in-memory MockTransport that
also simulates WP's silent-discard behaviour for unregistered meta keys.
"""
import json as _json

import httpx
import pytest

import server  # conftest sets env + sys.path first

pytestmark = pytest.mark.asyncio


def _raw(obj):
    """Return the underlying coroutine fn whether @mcp.tool returned the
    function itself or a FastMCP Tool wrapper."""
    for attr in ("fn", "func", "_fn"):
        f = getattr(obj, attr, None)
        if callable(f):
            return f
    return obj


update_user_meta = _raw(server.update_user_meta)
bulk_update_user_meta = _raw(server.bulk_update_user_meta)


def _make_world():
    users = {
        9201: {"id": 9201, "email": "alice@x.com", "name": "Alice",
               "meta": {"event_registration_status": ""}},
        9202: {"id": 9202, "email": "bob@x.com", "name": "Bob",
               "meta": {"event_registration_status": "confirmed-2"}},
        9300: {"id": 9300, "email": "discard@x.com", "name": "Dis",
               "meta": {"event_registration_status": ""}},
    }
    discard_ids = {9300}  # simulate WP 200-ing but DISCARDING the meta write
    counts = {"GET": 0, "POST": 0}

    def handler(request: httpx.Request) -> httpx.Response:
        counts[request.method] = counts.get(request.method, 0) + 1
        tail = request.url.path.split("/wp/v2", 1)[-1]

        if request.method == "GET" and tail == "/users":
            email = (request.url.params.get("search") or "").lower()
            hits = [u for u in users.values() if u["email"].lower() == email]
            return httpx.Response(200, json=hits)

        if request.method == "GET" and tail.startswith("/users/"):
            uid = int(tail.rsplit("/", 1)[1])
            if uid not in users:
                return httpx.Response(404, json={"code": "rest_user_invalid_id"})
            return httpx.Response(200, json=users[uid])

        if request.method == "POST" and tail.startswith("/users/"):
            uid = int(tail.rsplit("/", 1)[1])
            if uid not in users:
                return httpx.Response(404, json={"code": "rest_user_invalid_id"})
            body = {}
            try:
                body = _json.loads(request.content.decode() or "{}")
            except Exception:
                body = {}
            if uid not in discard_ids:
                users[uid]["meta"].update(body.get("meta", {}))
            return httpx.Response(200, json=users[uid])

        return httpx.Response(404, json={"code": "no_route", "path": request.url.path})

    return users, counts, handler


@pytest.fixture
def wp(monkeypatch):
    users, counts, handler = _make_world()
    mock_client = httpx.AsyncClient(transport=httpx.MockTransport(handler))
    monkeypatch.setattr(server, "client", mock_client)
    return {"users": users, "counts": counts}


# 1 -------------------------------------------------------------------------
async def test_allowlist_rejection_zero_calls(wp):
    r = await update_user_meta(site="qa", key="user_tier", value="x", email="alice@x.com")
    assert r["applied"] is False
    assert "not writable" in r.get("error", "")
    assert wp["counts"] == {"GET": 0, "POST": 0}


# 2 -------------------------------------------------------------------------
async def test_dry_run_default_no_post(wp):
    r = await update_user_meta(site="qa", key="event_registration_status",
                               value="confirmed-3", email="alice@x.com")
    assert r["status"] == "would_change"
    assert r["applied"] is False and r["dry_run"] is True
    assert r["old_value"] == ""
    assert wp["counts"]["POST"] == 0
    assert wp["counts"]["GET"] >= 1


# 3 -------------------------------------------------------------------------
async def test_unknown_site_no_calls(wp):
    r = await update_user_meta(site="does-not-exist", key="event_registration_status",
                               value="x", email="alice@x.com")
    assert r["applied"] is False
    assert "unknown site" in r["error"]
    assert wp["counts"] == {"GET": 0, "POST": 0}


# 4 -------------------------------------------------------------------------
async def test_user_id_xor_email(wp):
    both = await update_user_meta(site="qa", key="event_registration_status",
                                  value="x", user_id=9201, email="alice@x.com")
    neither = await update_user_meta(site="qa", key="event_registration_status", value="x")
    assert "EXACTLY ONE" in both["error"]
    assert "EXACTLY ONE" in neither["error"]
    assert wp["counts"] == {"GET": 0, "POST": 0}


# 5 -------------------------------------------------------------------------
async def test_unresolved_email_aborts_batch(wp):
    r = await bulk_update_user_meta(site="qa", key="event_registration_status",
                                    value="confirmed-4",
                                    emails=["alice@x.com", "ghost@x.com"],
                                    dry_run=False)
    assert r.get("aborted") is True
    assert r["summary"]["unresolved"] == 1
    assert wp["counts"]["POST"] == 0


# 6 -------------------------------------------------------------------------
async def test_idempotence_already_set(wp):
    r = await update_user_meta(site="qa", key="event_registration_status",
                               value="confirmed-2", email="bob@x.com", dry_run=False)
    assert r["status"] == "already_set"
    assert r["applied"] is False
    assert wp["counts"]["POST"] == 0


# 7 -------------------------------------------------------------------------
async def test_readback_mismatch_write_unconfirmed(wp):
    r = await update_user_meta(site="qa", key="event_registration_status",
                               value="confirmed-2", email="discard@x.com", dry_run=False)
    assert r["status"] == "write_unconfirmed"
    assert r["applied"] is False
    assert wp["counts"]["POST"] == 1


# 8 -------------------------------------------------------------------------
async def test_batch_over_max_refused(wp):
    r = await bulk_update_user_meta(site="qa", key="event_registration_status", value="x",
                                    emails=["a@x.com", "b@x.com", "c@x.com"], max_batch=2)
    assert "exceeds max_batch" in r["error"]
    assert wp["counts"] == {"GET": 0, "POST": 0}


# Bonus: happy paths prove the write + summary actually work -----------------
async def test_apply_happy_path(wp):
    r = await update_user_meta(site="qa", key="event_registration_status",
                               value="confirmed-5", email="alice@x.com", dry_run=False)
    assert r["status"] == "applied" and r["applied"] is True
    assert r["readback"] == "confirmed-5"
    assert wp["users"][9201]["meta"]["event_registration_status"] == "confirmed-5"


async def test_json_string_emails_coerced(wp):
    """MCP clients serialize array args as JSON strings; pydantic strict mode
    rejects them before the tool body runs. _coerce_list must absorb that."""
    assert server._coerce_list('["a@x.com", "b@x.com"]') == ["a@x.com", "b@x.com"]
    assert server._coerce_list("a@x.com, b@x.com") == ["a@x.com", "b@x.com"]
    assert server._coerce_list("[]") == []
    assert server._coerce_list("") == []
    assert server._coerce_list(["already", "a", "list"]) == ["already", "a", "list"]
    assert server._coerce_list(None) is None
    # end-to-end through the tool with a JSON-string payload
    r = await bulk_update_user_meta(site="qa", key="event_registration_status",
                                    value="confirmed-7",
                                    emails='["alice@x.com", "bob@x.com"]')
    assert r["summary"]["resolved"] == 2
    assert r["dry_run"] is True


async def test_bulk_apply_and_summary(wp):
    r = await bulk_update_user_meta(site="qa", key="event_registration_status",
                                    value="confirmed-6",
                                    emails=["alice@x.com", "bob@x.com"], dry_run=False)
    assert r["summary"]["applied"] == 2
    assert r["summary"]["failed"] == 0
    assert r["dry_run"] is False
