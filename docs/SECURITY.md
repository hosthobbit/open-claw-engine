# Open Claw Engine – Security Notes

This document summarizes the security posture and design decisions for the **Open Claw Engine** plugin.

**Attribution:** Designed by Host Hobbit Ltd. Author: Mike Warburton. https://hosthobbit.com

---

## Threat Model (High Level)

Open Claw Engine assumes:

- The WordPress instance is running over **HTTPS**.
- Only trusted users have access to `wp-admin`.
- External agents must authenticate using **Application Passwords**, **JWT**, or **HMAC**.

Primary risks addressed:

- Unauthorized content creation or publishing via REST.
- Abuse of AI generation endpoints (DoS / over‑use).
- Injection of unsafe HTML or scripts into posts.
- Leaking of secrets or sensitive configuration.

---

## Authentication & Authorization

### Admin UI

- All admin pages require `manage_options`.
- WordPress' built‑in **nonces** and Settings API are used for forms.

### REST API

- Jobs and generation endpoints require:
  - `edit_posts` capability to create/generate content.
  - `publish_posts` capability to publish (`/publish`, `/jobs/{id}/approve`).
- `Jarvis_REST_Base_Controller::authorize_request()`:
  - Uses `current_user_can()` for logged‑in sessions.
  - Delegates to `Jarvis_Auth_Service` when additional auth is configured (HMAC/JWT).

### HMAC

- When `auth_mode = hmac`:
  - Requests must include:
    - `X-Jarvis-Nonce`
    - `X-Jarvis-Timestamp` (Unix timestamp)
    - `X-Jarvis-Signature` (HMAC-SHA256)
  - Signature is computed from:

    ```text
    message = nonce + "|" + timestamp + "|" + method + "|" + route + "|" + raw_body
    signature = HMAC_SHA256( message, shared_secret )
    ```

  - Clock skew is limited to ±5 minutes.
  - If the secret is missing or invalid, the request is rejected.

### Application Passwords & JWT

- Application Passwords are managed entirely by WordPress core.
- JWT tokens are validated by your installed JWT provider plugin.
- Open Claw Engine simply **requires an authenticated user** with appropriate capabilities.

---

## Rate Limiting

- A simple IP-based rate limit is implemented using `set_transient()` per route.
- Default: **30 requests per minute** per IP+route combination.
- Adjustable via the `jarvis_content_engine_rate_limit` filter.

This is intentionally lightweight and should be combined with:

- API gateways, WAFs, or CDN-level rate limiting for higher traffic scenarios.

---

## Input Validation & Output Escaping

- All settings are sanitized in `Jarvis_Settings::sanitize_settings()` using:
  - `sanitize_text_field()`, `esc_url_raw()`, type casting, and whitelists for enums.
- REST request parameters are sanitized using:
  - `sanitize_text_field()` and casts to arrays/ints as needed.
- HTML content from generators is persisted as post content; you should restrict:
  - HTML tags and attributes via the generator, or
  - Use `wp_kses_post()` on content if your use case requires stricter filtering.
- Admin UI uses:
  - `esc_html()`, `esc_attr()`, `esc_url()` for output.

---

## Secrets Handling

- API keys, HMAC secrets, and other sensitive values:
  - Are **never shown** in clear text in the admin UI.
  - Are stored in WordPress options; you may layer additional encryption at the infrastructure level if desired.
- You can alternatively keep secrets **outside the plugin** (e.g. in environment variables or a custom mu-plugin) and have your `jarvis_content_engine_generate` filter implementation read them from there.

Jarvis' Open Claw Engine's diagnostics and debug logging are designed to avoid leaking secrets:

- `jarvis_content_engine_debug_log` action payloads only include:
  - Redacted provider metadata (API base, endpoint, model, timeouts, response snippets).
  - Never the raw API key or full request/response bodies.
- The `/debug/provider` REST endpoint exposes:
  - High‑level provider configuration (enabled, base URL, model).
  - Whether a key is present (`key_present` boolean).
  - A **redacted** last‑error summary from the LLM provider.
- Jobs' `logs_json` contain structured error codes/messages and redacted metadata only.

---

## Uninstall & Clean-Up

- `uninstall.php`:
  - Checks the `cleanup_on_uninstall` flag before dropping any data.
  - When enabled:
    - Drops the `jarvis_content_jobs` table.
    - Deletes `jarvis_content_engine_settings`.
- No posts or media created by Open Claw Engine are deleted automatically (to avoid content loss).

---

## Recommendations for Production

1. **Use HTTPS** for all REST calls.
2. Prefer **Application Passwords** tied to a dedicated service account with only necessary capabilities.
3. If using HMAC:
   - Rotate the shared secret periodically (use the built-in rotation setting).
   - Store the secret in a secure secret manager rather than hard‑coding it.
4. Monitor:
   - `/wp-json/jarvis/v1/health`
   - Job logs in the admin UI
5. Combine Open Claw Engine with:
   - WAF / CDN security (Cloudflare, etc.).
   - Logging / observability for your external agent or LLM orchestration services.

