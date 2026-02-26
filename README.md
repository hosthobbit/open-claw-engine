# Open Claw Engine

> **WordPress plugin** for AI-assisted, daily SEO-optimized long-form posts — with images, links, schema, and quality guardrails.

**Designed by [Host Hobbit Ltd](https://hosthobbit.com)** · Author: **Mike Warburton**

---

> **TODO:** Image generation is not yet creating images for posts. Everything else is working as expected.

---

## Table of contents

- [Requirements](#requirements)
- [Installation](#installation)
- [How to use](#how-to-use)
- [Configuration overview](#configuration-overview)
- [How it works](#how-it-works-high-level)
- [REST API](#rest-api-overview)
- [Mode A: External agent](#external-agent-integration-mode-a)
- [Mode B: Direct LLM](#direct-llm-integration-mode-b)
- [Development & testing](#development--testing)
- [Security](#security-notes)
- [Attribution & license](#attribution--license)

---

## Requirements

| Requirement | Details |
|------------|---------|
| **PHP** | 8.1+ |
| **WordPress** | 6.x |
| **HTTPS** | Strongly recommended for external REST access |

**Optional**

- Rank Math or Yoast SEO for deeper SEO integration
- JWT auth plugin for Mode A (JWT authentication)

---

## Installation

### Install via ZIP (recommended)

1. **Download**  
   Go to **[github.com/hosthobbit/open-claw-engine](https://github.com/hosthobbit/open-claw-engine)** → **Code** → **Download ZIP** (or clone and zip the folder).

2. **Prepare the ZIP**  
   - Unzip on your computer.  
   - The inner folder **must** be named `jarvis-content-engine` (WordPress expects this slug).  
   - If you have `open-claw-engine-main`, rename it to `jarvis-content-engine`.  
   - Zip that single folder so the archive contains one top-level folder: `jarvis-content-engine`, with all plugin files inside.

3. **Install in WordPress**  
   - **Plugins** → **Add New** → **Upload Plugin** → choose `jarvis-content-engine.zip` → **Install Now** → **Activate Plugin**.

4. **Post-install**  
   Open **Open Claw Engine** in the admin menu to configure integration mode, auth, and content defaults.  
   Optionally enable **Cleanup on uninstall** in settings if you want data removed when the plugin is uninstalled.

### Alternative: manual copy

Copy the `jarvis-content-engine` folder into `wp-content/plugins`, then **Plugins** → activate **Open Claw Engine**, and configure as above.

---

## How to use

### First-time setup

1. **Open settings**  
   In the admin sidebar, click **Open Claw Engine**. You’ll see **Settings** and **Job Logs**.

2. **Choose how content is generated**
   - **Mode A (External agent)** — Your service calls the plugin’s REST API. Configure **Auth mode** (Application Password, JWT, or HMAC).
   - **Mode B (Direct LLM)** — The plugin calls an OpenAI-compatible API. Enable **Use built-in OpenAI-compatible provider**, set **API Base URL**, **API Key**, and **Model** (dropdown or custom). Use **Refresh models** after saving to load models from the API.

3. **Connection & auth (Mode A)**
   - **Application Password:** Create a WordPress user for the agent → **Users** → edit user → **Application Passwords** → create a password; use username + password in your client.
   - **HMAC:** Set **HMAC Shared Secret** in the plugin; your client signs requests with it (see `docs/API.md`).

4. **Content & SEO defaults**  
   Set default subject, categories/tags, tone, voice, word count range, publish cadence, daily time, meta title template, slug strategy, keywords, link rules (min/max internal/external, allowlist/blocklist), image rules (featured, inline, alt, OG fallback, verify remote), and quality thresholds (**Draft only** recommended).

5. **Save** the settings.

### Creating and running jobs

**REST API (Mode A)** — Your agent calls:

- `POST /wp-json/jarvis/v1/jobs` — create a job  
- `POST /wp-json/jarvis/v1/jobs/{id}/run` — run a job  
- `POST /wp-json/jarvis/v1/generate` — one-shot generate + draft  
- `POST /wp-json/jarvis/v1/publish` — one-shot generate + publish  

Use the **Job Logs** tab for status and errors.

**Scheduled (WordPress)** — With **Publish cadence** (e.g. daily at 03:00), WP-Cron runs the job automatically. Check **Job Logs** after the run.

### Editorial workflow

- **Draft-only (recommended):** Posts are saved as drafts. Review in **Posts**, edit, then **Publish**.
- **Approve via API:** `POST /wp-json/jarvis/v1/jobs/{id}/approve` to publish the draft (or publish manually in wp-admin).
- **Job Logs:** Use for status, SEO/readability scores, image/link results, and errors.

### Health and monitoring

- **`GET /wp-json/jarvis/v1/health`** — Plugin status and config summary (no secrets). Suited for monitoring or uptime checks.

Full request/response formats: **`docs/API.md`**.

---

## Configuration overview

### Connection & auth

| Setting | Options |
|--------|---------|
| **Integration mode** | `external` (REST) or `direct` (plugin calls LLM) |
| **Auth mode** | Application Password (recommended), JWT, or HMAC |

Secrets are **never shown in clear text** in the admin UI.

### Content defaults

- Target **categories** and **tags**
- **Tone** (professional, conversational, technical) and **voice** (1st/2nd/3rd person)
- **Word count range** (e.g. 1200–2500)
- **Publish cadence** and daily time

### SEO & quality

- **Meta title template** — placeholders: `{title}`, `{site_name}`, `{primary_keyword}`
- **Slug strategy** — kebab / simple
- **Link rules** — min/max internal and external links; allowlist/blocklist
- **Image rules** — featured required, inline count, alt text
- **Quality thresholds** — readability, SEO score; draft-only vs auto-publish

See **`docs/ARCHITECTURE.md`** and **`docs/API.md`** for details.

---

## How it works (high level)

1. **Configure** — Daily subject and defaults in the admin UI.
2. **Schedule** — WP-Cron runs at your configured time (or trigger via API).
3. **Generate** — Plugin asks a generation agent (external or LLM) via `jarvis_content_engine_generate` for title, body, FAQ, CTA, links, meta, OG, schema.
4. **Store** — Content saved as draft or published based on your thresholds.
5. **Score & guardrails** — SEO/quality score; readability, links, length enforced.
6. **Images** — URLs from the generator are downloaded into the Media Library and set as featured/inline with alt text.
7. **SEO** — Meta and focus keyword saved in Rank Math / Yoast when available.
8. **Logs & health** — Job records in a custom table and **Job Logs** view; `/health` endpoint for monitoring.

---

## REST API overview

**Base:** `https://yoursite.com/wp-json/jarvis/v1`

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/jobs` | Create a scheduled content job |
| `GET`  | `/jobs/{id}` | Job status and details |
| `POST` | `/jobs/{id}/run` | Force run/generate a job |
| `POST` | `/jobs/{id}/approve` | Publish the associated draft |
| `POST` | `/generate` | One-shot generate + draft |
| `POST` | `/publish` | One-shot generate + publish (`publish_posts` required) |
| `GET`  | `/health` | Plugin health and config summary |

**Authentication:** WordPress REST auth (cookies for admins), plus Application Passwords, JWT, or HMAC. See **`docs/API.md`** for schemas and examples.

---

## External agent integration (Mode A)

1. Authenticate with Application Password, JWT, or HMAC.
2. Call `POST /generate` or `POST /publish` with `subject`, `primary_keyword`, `secondary_keywords[]`, `audience`, `intent`.
3. Optionally poll `GET /jobs/{id}` or use Job Logs for status.

Open Claw Engine handles: post creation, taxonomy, Media Library downloads, SEO scoring, thresholds, schema/meta, and SEO plugin compatibility.

---

## Direct LLM integration (Mode B)

Implement the filter in a mu-plugin or theme; return at least `title`, `content`, `excerpt`, `internal_links`, `external_links` (and optionally featured image, meta, OG, schema, FAQ, CTA):

```php
add_filter( 'jarvis_content_engine_generate', function( $generation, $context ) {
    // Call your OpenAI-compatible API using $context.
    return array(
        'title'              => '...',
        'content'            => '<p>...</p>',
        'excerpt'            => '...',
        'internal_links'      => array( /* ... */ ),
        'external_links'      => array( /* allowlist/blocklist */ ),
        'featured_image_url'  => 'https://...',
        'featured_image_alt' => '...',
        'meta_title'         => '...',
        'meta_description'   => '...',
        'og_title'           => '...',
        'og_description'     => '...',
        'schema_jsonld'      => array( /* Article + FAQ */ ),
        'faq'                => array( array( 'q' => '...', 'a' => '...' ) ),
        'cta'                => '<p>...</p>',
    );
}, 10, 2 );
```

LLM code and secrets stay in your codebase; the plugin handles WordPress-side behaviour.

---

## Development & testing

- PHPUnit tests in **`tests/`** (SEO scoring, pipeline).
- Extend via filters and actions.

From the WordPress root (with `phpunit.xml` configured):

```bash
phpunit --testsuite jarvis-content-engine
```

---

## Security notes

- Admin: **`manage_options`** required.
- REST: capabilities (`edit_posts`, `publish_posts`) plus optional HMAC/JWT.
- Inputs sanitized and escaped per WordPress standards.
- No hard-coded secrets; keys provided via settings or environment.

See **`docs/SECURITY.md`** for threat model and details.

---

## Attribution & license

| | |
|---|---|
| **Designed by** | Host Hobbit Ltd |
| **Author** | Mike Warburton |
| **URL** | [hosthobbit.com](https://hosthobbit.com) |

**License:** GPL-2.0-or-later.
