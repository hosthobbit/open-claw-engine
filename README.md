# Open Claw Engine

**Open Claw Engine** is a WordPress plugin that lets an external AI agent generate and publish **daily, SEO‑optimized long‑form posts** with images, internal/external links, schema markup, and quality guardrails.

**Attribution:** Designed by **Host Hobbit Ltd**. Author: **Mike Warburton**. [https://hosthobbit.com](https://hosthobbit.com)

It supports two modes:

- **Mode A (recommended)**: External agent calls the plugin's REST API (Application Passwords / JWT / HMAC).
- **Mode B**: Plugin delegates generation to an OpenAI‑compatible endpoint via hooks/filters.

The plugin focuses on **discoverability, on‑page SEO, safety, and editorial control** (no virality promises).

---

## Requirements

- **PHP** 8.1+
- **WordPress** 6.x
- HTTPS for external REST access (strongly recommended)

Optional:

- Rank Math or Yoast SEO for deeper SEO integration.
- JWT auth plugin if you want to use JWT for Mode A.

---

## Installation

1. Copy the `jarvis-content-engine` folder into your WordPress `wp-content/plugins` directory.
2. In `wp-admin` → **Plugins**, activate **Open Claw Engine**.
3. Go to **Open Claw Engine** in the admin menu to configure:
   - **Integration mode** (external agent vs direct LLM fallback).
   - **Auth mode** (Application Password / JWT / HMAC).
   - Content defaults, SEO defaults, link/image rules, and quality thresholds.
4. (Optional) If you want uninstall to fully clean up, enable **"Cleanup on uninstall"**.

---

## Configuration Overview

### Connection & Auth

- **Integration Mode**  
  - `external` – external agent calls REST endpoints (recommended).
  - `direct` – plugin expects a filter implementation (Mode B) to call an LLM API.

- **Auth Mode**  
  - `Application Password` – best default; use a dedicated service user.
  - `JWT` – uses your existing JWT plugin for authentication.
  - `HMAC` – Open Claw Engine expects signed requests using an HMAC shared secret.

Secrets (API keys, HMAC secret) are **never shown in clear text** in the admin UI.

### Content Defaults

You can set:

- Target **categories** and **tags**.
- **Tone** (professional, conversational, technical) and **voice** (1st/2nd/3rd person).
- Long‑form **word count range** (e.g., 1200–2500 words).
- Daily scheduling time and cadence (daily/weekday/custom via cron).

### SEO & Quality

- **Meta title template** with placeholders (`{title}`, `{site_name}`, `{primary_keyword}`).
- **Slug strategy** (kebab/simple).
- **Keyword strategy** (primary + secondary keywords).
- **Link rules**:
  - Min/max internal links.
  - Min/max external links with allowlist/blocklist.
- **Image rules**:
  - Featured image required.
  - Inline image count.
  - Alt‑text generation required.
- **Quality thresholds**:
  - Readability minimum.
  - SEO score minimum.
  - Draft‑only mode vs auto‑publish when thresholds are met.

See `docs/ARCHITECTURE.md` and `docs/API.md` for deeper details.

---

## How It Works (High Level)

1. **Campaign configuration** – you configure a daily subject (e.g. "Managed WordPress security tips") and defaults in the admin UI.
2. **Scheduling** – WP‑Cron triggers a daily event at your configured time.
3. **Generation** – for each scheduled run or API call:
   - The plugin asks a **generation agent** (external service or LLM API) via the `jarvis_content_engine_generate` filter to provide:
     - Title options / chosen title.
     - Outline and long‑form article body with H2/H3.
     - FAQ section and CTA block.
     - Suggested internal/external links, meta, OG fields, and schema JSON‑LD.
   - The plugin stores the content as a **draft or published post** based on your workflow and thresholds.
4. **Scoring & Guardrails** – Open Claw Engine computes a simple SEO/quality score and enforces thresholds (readability, link distribution, content length).
5. **Images** – image URLs from the generator are downloaded into the **Media Library**, with alt text set from context, and assigned as featured / inline images as configured.
6. **SEO integration** – meta title/description and focus keyword are saved in Rank Math / Yoast fields when available, plus plugin‑specific fallbacks.
7. **Logs & Health** – job records and scores are stored in a custom table and surfaced in the admin **Job Logs** view. A `/health` endpoint is available for external monitoring.

---

## REST API Overview

Base namespace: `/wp-json/jarvis/v1`

Core endpoints:

- `POST /jobs` – create a scheduled content job.
- `GET /jobs/{id}` – get job status and details.
- `POST /jobs/{id}/run` – force run/generate a job.
- `POST /jobs/{id}/approve` – publish associated draft post (for editorial workflows).
- `POST /generate` – one‑shot generate + draft.
- `POST /publish` – one‑shot generate + publish (requires `publish_posts` capability).
- `GET /health` – plugin health and config summary.

Authentication is via standard **WordPress REST** auth (cookies for logged‑in admins) plus:

- **Application Passwords** (recommended for external agents).
- **JWT** (if you have a JWT plugin configured).
- **HMAC** signed requests using a shared secret and timestamp/nonce.

See `docs/API.md` for detailed request/response schemas and curl examples.

---

## External Agent Integration (Mode A)

In Mode A, your external agent (e.g. an orchestrator service) drives Jarvis by:

1. Authenticating with Application Password / JWT / HMAC.
2. Calling `POST /generate` or `POST /publish` with:
   - `subject`
   - `primary_keyword` and `secondary_keywords[]`
   - `audience` and `intent`
3. Optionally polling `GET /jobs/{id}` for status, or integrating logs into your observability stack.

Open Claw Engine handles:

- WordPress post creation and taxonomy assignment.
- Media downloads into the Media Library.
- SEO/quality scoring and thresholds.
- Schema/meta persistence and SEO plugin compatibility.

---

## Direct LLM Integration (Mode B)

In Mode B, the plugin expects a site‑specific implementation of the filter:

```php
add_filter( 'jarvis_content_engine_generate', function( $generation, $context ) {
    // Call your OpenAI‑compatible API here using $context.
    // Return an array with at least: title, content, excerpt, internal_links, external_links.
    return array(
        'title'            => '...',
        'content'          => '<p>...</p>',
        'excerpt'          => '...',
        'internal_links'   => array(/* ... */),
        'external_links'   => array(/* ... must respect allowlist/blocklist ... */),
        'featured_image_url' => 'https://...',
        'featured_image_alt' => '...',
        'meta_title'       => '...',
        'meta_description' => '...',
        'og_title'         => '...',
        'og_description'   => '...',
        'schema_jsonld'    => array(/* Article + FAQ schema */),
        'faq'              => array(
            array( 'q' => 'Question?', 'a' => 'Answer...' ),
        ),
        'cta'              => '<p>Call‑to‑action...</p>',
    );
}, 10, 2 );
```

This lets you keep all LLM calling code (and secrets) in a **custom mu‑plugin or theme**, while Open Claw Engine handles only WordPress‑side responsibilities.

---

## Development & Testing

- Basic PHPUnit tests live in `tests/` (SEO scoring, pipeline behavior).
- You can extend the scoring engine or pipeline using filters and actions.

Run tests (from WordPress root with a configured `phpunit.xml`):

```bash
phpunit --testsuite jarvis-content-engine
```

---

## Security Notes

- All admin actions require **`manage_options`**.
- REST routes enforce capabilities (`edit_posts`, `publish_posts`) plus optional HMAC/JWT.
- Inputs are sanitized and escaped following WordPress coding standards.
- No secrets are hard‑coded in the plugin; you provide API keys/secrets via settings or environment‑specific code.

See `docs/SECURITY.md` for a deeper security review and threat model.

---

## Attribution

- **Designed by:** Host Hobbit Ltd  
- **Author:** Mike Warburton  
- **URL:** https://hosthobbit.com  

---

## License

GPL‑2.0‑or‑later.

