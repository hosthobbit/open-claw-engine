# Open Claw Engine – API Reference

**Attribution:** Designed by Host Hobbit Ltd. Author: Mike Warburton. https://hosthobbit.com

Base URL:

- `https://your-site.example/wp-json/jarvis/v1`

Authentication:

- Standard WordPress REST auth (cookies for logged‑in users).
- Application Passwords (recommended for external agents).
- Optional JWT (via installed JWT plugin).
- Optional HMAC signed requests when `auth_mode = hmac`.

All responses are JSON.

---

## Authentication Details

### Application Password

1. Create an **Application Password** for a user with `edit_posts` / `publish_posts` (or an editorial service user).
2. Use HTTP Basic Auth:

```bash
curl -X POST \
  -u "editor@example.com:YOUR_APP_PASSWORD" \
  "https://your-site.example/wp-json/jarvis/v1/generate" \
  -H "Content-Type: application/json" \
  -d '{
    "subject": "Managed WordPress security tips",
    "primary_keyword": "managed wordpress security"
  }'
```

### JWT (optional)

If you have a JWT plugin installed:

1. Obtain a JWT from your auth endpoint.
2. Send it as `Authorization: Bearer <token>` header.

### HMAC

When `auth_mode = hmac`, you must sign each request:

```text
message = nonce + "|" + timestamp + "|" + method + "|" + route + "|" + raw_body
signature = HMAC_SHA256( message, shared_secret )
```

Headers:

- `X-Jarvis-Nonce: <random-string>`
- `X-Jarvis-Timestamp: <unix-seconds>`
- `X-Jarvis-Signature: <hex-of-hmac>`

The plugin checks a ±5‑minute clock skew and rejects invalid signatures.

---

## POST /jobs

Create a **content job** that will be run later (e.g. by cron or manual `/run`).

**Request**

```http
POST /wp-json/jarvis/v1/jobs
Content-Type: application/json
Authorization: Basic ...
```

Body:

```json
{
  "subject": "Managed WordPress security tips",
  "primary_keyword": "managed wordpress security",
  "secondary_keywords": ["wordpress hardening", "wp firewall"],
  "audience": "technical marketing managers at agencies",
  "intent": "informational",
  "scheduled_at": "2026-03-01 09:00:00"
}
```

**Response 201**

```json
{
  "id": 42,
  "status": "scheduled",
  "subject": "Managed WordPress security tips"
}
```

---

## GET /jobs/{id}

Retrieve job status and metadata.

```http
GET /wp-json/jarvis/v1/jobs/42
Authorization: Basic ...
```

**Response 200**

```json
{
  "id": 42,
  "subject": "Managed WordPress security tips",
  "status": "generated",
  "scheduled_at": "2026-03-01 09:00:00",
  "generated_at": "2026-03-01 09:02:15",
  "published_at": null,
  "post_id": 123,
  "score": {
    "total": 82,
    "seo": 70,
    "readability": 90,
    "word_count": 1850,
    "avg_sentence_length": 18.2,
    "uniqueness_warning": false,
    "notes": [
      "Add more internal links to relevant content."
    ]
  },
  "logs": {
    "source": "generate_once"
  }
}
```

---

## POST /jobs/{id}/run

Force generation for an existing scheduled job.

```http
POST /wp-json/jarvis/v1/jobs/42/run
Authorization: Basic ...
```

**Response 200/201**

```json
{
  "job_id": 42,
  "post_id": 123,
  "post_status": "draft",
  "score": { "...": "..." }
}
```

---

## POST /jobs/{id}/approve

Publish the associated post for a job when you're using draft‑only workflows.

```http
POST /wp-json/jarvis/v1/jobs/42/approve
Authorization: Basic ...
```

Requires `publish_posts` capability (and passes through HMAC/JWT if configured).

**Response 200**

```json
{
  "message": "Post published.",
  "post_id": 123
}
```

---

## POST /generate

One‑shot **generate + draft** flow.

```http
POST /wp-json/jarvis/v1/generate
Authorization: Basic ...
Content-Type: application/json
```

Body is the same as `POST /jobs`:

```json
{
  "subject": "Managed WordPress security tips",
  "primary_keyword": "managed wordpress security",
  "secondary_keywords": ["wordpress hardening"],
  "audience": "technical marketing managers",
  "intent": "informational"
}
```

**Response 201**

```json
{
  "job_id": 43,
  "post_id": 124,
  "post_status": "draft",
  "score": {
    "total": 84,
    "seo": 72,
    "readability": 92
  }
}
```

---

## POST /publish

One‑shot **generate + publish** (skips the draft stage when thresholds are met).

Requires `publish_posts` capability.

```http
POST /wp-json/jarvis/v1/publish
Authorization: Basic ...
Content-Type: application/json
```

Body is the same as `/generate`.

**Response 201**

```json
{
  "job_id": 44,
  "post_id": 125,
  "post_status": "publish",
  "score": {
    "total": 90,
    "seo": 80,
    "readability": 95
  }
}
```

If SEO/quality scores are below your thresholds, Jarvis may still leave the post in **draft**, and the `post_status` field will indicate this.

---

### Error responses and provider diagnostics

When generation fails (e.g., invalid API key, model errors, or malformed output), `/generate` and `/publish` return a 4xx/5xx JSON body including a `provider_error` block:

```json
{
  "status_code": 502,
  "error": "generation_failed",
  "message": "Content generation failed.",
  "provider_error": {
    "code": "jarvis_provider_http_status_401",
    "message": "LLM provider returned an error.",
    "meta": {
      "provider_enabled": true,
      "api_base": "https://api.openai.com/v1",
      "endpoint": "https://api.openai.com/v1/chat/completions",
      "model": "gpt-4.1",
      "timeout": 30,
      "max_tokens": 3000,
      "temperature": 0.4,
      "attempt": 3,
      "http_status": 401,
      "response_snippet": "You must be authenticated...",
      "key_present": true
    }
  }
}
```

Notes:

- `provider_error.meta` is **redacted** and never includes full API keys or request bodies.
- For malformed model output (non‑JSON or missing `content`), `code` is `jarvis_provider_invalid_json` or `invalid_generation_payload`.
- If the provider is disabled or not configured, `code` is `jarvis_provider_disabled` or `jarvis_provider_not_configured`.

---

## GET /health

Simple health/status endpoint for monitoring.

```http
GET /wp-json/jarvis/v1/health
```

**Response 200**

```json
{
  "plugin": "jarvis-content-engine",
  "version": "1.0.0",
  "mode": "external",
  "auth_mode": "application_password",
  "cron_scheduled": true,
  "time": "2026-02-26 10:25:00"
}
```

---

## GET /debug/provider

Admin‑only endpoint for inspecting provider configuration and the last error (no secrets).

```http
GET /wp-json/jarvis/v1/debug/provider
Authorization: Cookie (logged‑in admin)
```

**Response 200**

```json
{
  "provider_enabled": true,
  "api_base": "https://api.openai.com/v1",
  "endpoint": "https://api.openai.com/v1/chat/completions",
  "model": "gpt-4.1",
  "key_present": true,
  "last_error": {
    "time": "2026-02-26 11:05:12",
    "code": "jarvis_provider_http_status_401",
    "message": "LLM provider returned an error.",
    "meta": {
      "provider_enabled": true,
      "api_base": "https://api.openai.com/v1",
      "endpoint": "https://api.openai.com/v1/chat/completions",
      "model": "gpt-4.1",
      "timeout": 30,
      "max_tokens": 3000,
      "temperature": 0.4,
      "attempt": 3,
      "http_status": 401,
      "response_snippet": "You must be authenticated...",
      "key_present": true
    }
  }
}
```

---

## Example Postman / curl Snippets

### Create a daily job (curl)

```bash
curl -X POST \
  -u "editor@example.com:APP_PASSWORD" \
  "https://your-site.example/wp-json/jarvis/v1/jobs" \
  -H "Content-Type: application/json" \
  -d '{
    "subject": "Managed WordPress security tips - day 1",
    "primary_keyword": "managed wordpress security",
    "secondary_keywords": ["wordpress firewall", "wp audit logs"],
    "audience": "SMB site owners using WordPress",
    "intent": "informational"
  }'
```

### One‑shot generate+publish (curl)

```bash
curl -X POST \
  -u "editor@example.com:APP_PASSWORD" \
  "https://your-site.example/wp-json/jarvis/v1/publish" \
  -H "Content-Type: application/json" \
  -d '{
    "subject": "Best practices for managed WordPress security",
    "primary_keyword": "managed wordpress security best practices",
    "secondary_keywords": ["wordpress security checklist"],
    "audience": "technical marketers",
    "intent": "informational"
  }'
```

You can import these examples directly into Postman as raw requests or by constructing a small Postman collection around these endpoints.

