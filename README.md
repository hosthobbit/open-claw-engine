# Open Claw Engine

**Open Claw Engine** is a WordPress plugin that lets an external AI agent generate and publish **daily, SEO‐optimized long‐form posts** with images, internal/external links, schema markup, and quality guardrails.

**Attribution:** Designed by **Host Hobbit Ltd**. Author: **Mike Warburton**. [https://hosthobbit.com](https://hosthobbit.com)

It supports two modes:

- **Mode A (recommended)**: External agent calls the plugin's REST API (Application Passwords / JWT / HMAC).
- **Mode B**: Plugin delegates generation to an OpenAI‐compatible endpoint via hooks/filters.

The plugin focuses on **discoverability, on‐page SEO, safety, and editorial control** (no virality promises).

**TODO:** We need to fix the image generation — it is not currently making images for the posts. Apart from that, it's all good.

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

### Install via ZIP (recommended)

1. **Download the plugin**
   - Go to [https://github.com/hosthobbit/open-claw-engine](https://github.com/hosthobbit/open-claw-engine).
   - Click **Code** → **Download ZIP** (or clone and then zip the folder).

2. **Prepare the ZIP file**
   - Unzip the downloaded file on your computer.
   - The folder inside must be named **`jarvis-content-engine`** (this is the plugin slug WordPress expects).
   - If GitHub gave you a folder named `open-claw-engine-main`, rename it to **`jarvis-content-engine`**.
   - Zip that single folder again so the archive contains **one** folder: `jarvis-content-engine`, with all plugin files (e.g. `jarvis-content-engine.php`, `README.md`, `includes/`, etc.) inside it.

3. **Install in WordPress**
   - Log in to **wp-admin**.
   - Go to **Plugins** → **Add New**.
   - Click **Upload Plugin**, choose your **jarvis-content-engine.zip** file, then **Install Now**.
   - After installation, click **Activate Plugin**.

4. **Optional: manual copy**
   - Alternatively, copy the `jarvis-content-engine` folder into your site's `wp-content/plugins` directory, then activate **Open Claw Engine** under **Plugins**.

5. **Post-install**
   - Go to **Open Claw Engine** in the admin menu to configure integration mode, auth, and content defaults.
   - If you want the plugin to remove its data when uninstalled, enable **Cleanup on uninstall** in settings.

### Alternative: manual copy

1. Copy the `jarvis-content-engine` folder into your WordPress `wp-content/plugins` directory.
2. In `wp-admin` → **Plugins**, activate **Open Claw Engine**.
3. Go to **Open Claw Engine** in the admin menu to configure:
   - **Integration mode** (external agent vs direct LLM fallback).
   - **Auth mode** (Application Password / JWT / HMAC).
   - Content defaults, SEO defaults, link/image rules, and quality thresholds. Optionally enable **Cleanup on uninstall** in the main Settings tab if you want data removed when the plugin is uninstalled.

---

## How to use

### First-time setup

1. **Open the settings page**
   In the WordPress admin sidebar, click **Open Claw Engine**. You'll see two tabs: **Settings** and **Job Logs**.

2. **Choose how content is generated**
   - **Mode A (External agent)** – An external service (e.g. your own API or automation) will call the plugin's REST API to trigger generation. You'll configure **Auth mode** (Application Password, JWT, or HMAC) so that service can authenticate.
   - **Mode B (Direct LLM)** – The plugin will call an OpenAI‐compatible API itself. Enable **Use built-in OpenAI-compatible provider** and fill in **API Base URL**, **API Key**, and **Model** (use the dropdown or type a model ID). Optionally click **Refresh models** after saving to load models from your API.

3. **Set connection and auth (Mode A)**
   - **Auth Mode**: Choose **Application Password** (recommended), **JWT**, or **HMAC**.
   - For Application Passwords: create a WordPress user for the agent, then **Users** → edit that user → **Application Passwords** → create a new password and use it (with the username) in your external client.
   - For HMAC: set a **HMAC Shared Secret** in the plugin; your client must sign requests with that secret (see `docs/API.md`).

4. **Set content and SEO defaults**
   - **Content defaults**: Default subject, target categories/tags, tone, voice, word count range, publish cadence (daily/weekdays), and daily time.
   - **SEO**: Meta title template, slug strategy, primary/secondary keywords.
   - **Links**: Min/max internal and external links; optional allowlist/blocklist for external URLs.
   - **Images**: Featured image required, inline image count, alt text, Use featured as OG fallback, Verify remote image exists (recommended).
   - **Quality**: Readability and SEO score minimums; **Draft only** (recommended) so posts are created as drafts for review, or **Auto publish** when thresholds are met.

5. **Save** the settings.

### Creating and running jobs

- **Via REST API (Mode A)**
  Your external agent calls:
  - `POST /wp-json/jarvis/v1/jobs` – create a scheduled job (subject, keywords, etc.).
  - `POST /wp-json/jarvis/v1/jobs/{id}/run` – run that job immediately.
  - `POST /wp-json/jarvis/v1/generate` – one-shot generate and save as draft.
  - `POST /wp-json/jarvis/v1/publish` – one-shot generate and publish (if allowed).

  Use the **Job Logs** tab to see status, scores, and any errors.

- **Via WordPress (scheduled)**
  If you use **Publish cadence** (e.g. daily at 03:00), WP-Cron will run the configured job automatically. Check **Job Logs** after the run.

### Editorial workflow

1. **Draft-only (recommended)**
   With **Draft only** enabled, every generated post is saved as a **draft**. You review it in **Posts**, edit if needed, then click **Publish** when ready.

2. **Approve via API**
   For jobs created via the API, you can publish the associated draft by calling `POST /wp-json/jarvis/v1/jobs/{id}/approve` (or publish the post manually in wp-admin).

3. **Job Logs**
   Use the **Job Logs** tab to see each run: status, SEO/readability scores, image and link results, and error messages. Fix any misconfiguration (e.g. API key, model, or image URLs) and re-run if needed.

### Health and monitoring

- **GET /wp-json/jarvis/v1/health** – Returns plugin status and a short config summary (no secrets). Use this from monitoring or uptime checks.

For full request/response formats and examples, see **`docs/API.md`**.

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
- Long‐form **word count range** (e.g., 1200–2500 words).
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
  - Alt‐text generation required.
- **Quality thresholds**:
  - Readability minimum.
  - SEO score minimum.
  - Draft‐only mode vs auto‐publish when thresholds are met.

See `docs/ARCHITECTURE.md` and `docs/API.md` for deeper details.

---

## How It Works (High Level)

1. **Campaign configuration** – you configure a daily subject (e.g. “Managed WordPress security tips”) and defaults in the admin UI.
2. **Scheduling** – WP‐Cron triggers a daily event at your configured time.
3. **Generation** – for each scheduled run or API call:
   - The plugin asks a **generation agent** (external service or LLM API) via the `jarvis_content_engine_generate` filter to provide:
     - Title options / chosen title.
     - Outline and long‐form article body with H2/H3.
     - FAQ section and CTA block.
     - Suggested internal/external links, meta, OG fields, and schema JSON‐LD.
   - The plugin stores the content as a **draft or published post** based on your workflow and thresholds.
4. **Scoring & Guardrails** – Open Claw Engine computes a simple SEO/quality score and enforces thresholds (readability, link distribution, content length).
5. **Images** – image URLs from the generator are downloaded into the **Media Library**, with alt text set from context, and assigned as featured / inline images as configured.
6. **SEO integration** – meta title/description and focus keyword are saved in Rank Math / Yoast fields when available, plus plugin‐specific fallbacks.
7. **Logs & Health** – job records and scores are stored in a custom table and surfaced in the admin **Job Logs** view. A `/health` endpoint is available for external monitoring.

---

## REST API Overview

Base namespace: `/wp-json/jarvis/v1`

Core endpoints:

- `POST /jobs` – create a scheduled content job.
- `GET /jobs/{id}` – get job status and details.
- `POST /jobs/{id}/run` – force run/generate a job.
- `POST /jobs/{id}/approve` – publish associated draft post (for editorial workflows).
- `POST /generate` – one‐shot generate + draft.
- `POST /publish` – one‐shot generate + publish (requires `publish_posts` capability).
- `GET /health` – plugin health and config summary.

Authentication is via standard **WordPress REST** auth (cookies for logged‐in admins) plus:

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

In Mode B, the plugin expects a site‐specific implementation of the filter:

```php
add_filter( 'jarvis_content_engine_generate', function( $generation, $context ) {
    // Call your OpenAI‐compatible API here using $context.
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
        'cta'              => '<p>Call‐to‐action...</p>',
    );
}, 10, 2 );
```

This lets you keep all LLM calling code (and secrets) in a **custom mu‐plugin or theme**, while Open Claw Engine handles only WordPress‐side responsibilities.

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
- No secrets are hard‐coded in the plugin; you provide API keys/secrets via settings or environment‐specific code.

See `docs/SECURITY.md` for a deeper security review and threat model.

---

## Attribution

- **Designed by:** Host Hobbit Ltd
- **Author:** Mike Warburton
- **URL:** https://hosthobbit.com

---

## License

GPL‐2.0‐or‐later.
