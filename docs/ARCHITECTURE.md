# Open Claw Engine – Architecture

This document describes the high‑level architecture of the **Open Claw Engine** WordPress plugin.

**Attribution:** Designed by Host Hobbit Ltd. Author: Mike Warburton. https://hosthobbit.com

---

## High-Level Overview

Open Claw Engine is a **content automation layer** that sits inside WordPress and delegates AI work to:

- An **external agent** (Mode A – recommended) calling REST endpoints, or
- A **direct LLM gateway** (Mode B) implemented via WordPress filters.

The plugin focuses on: Job scheduling and logging; WordPress‑native post creation and media handling; SEO and quality guardrails; Safe, auditable automation (no virality promises).

---

## Main Components

### 1. Bootstrap / Core

- `jarvis-content-engine.php`: Defines constants, autoloader, activation/deactivation; creates `jarvis_content_jobs` table and cron events.
- `includes/class-jarvis-plugin.php`: Central orchestrator; instantiates Jobs_Table, Settings, Content_Pipeline, Cron; wires admin UI and REST controllers.

### 2. Data Model

- `includes/db/class-jarvis-jobs-table.php`: Table `wp_jarvis_content_jobs`; columns id, subject, status, scheduled_at, generated_at, published_at, score_json, logs_json, post_id; methods create_table(), insert_job(), update_job(), get_job(), get_recent_jobs(), drop_table().

### 3. Settings & Admin UI

- `includes/admin/class-jarvis-settings.php`: Manages `jarvis_content_engine_settings`; defaults for mode, API, content, SEO, image, quality; sanitization.
- `includes/admin/class-jarvis-admin.php`: Open Claw Engine top‑level menu; settings forms and Job Logs table; manage_options and nonces.

### 4. REST Layer

- `includes/rest/class-jarvis-rest-base-controller.php`: Namespace `jarvis/v1`; authorize_request() (capability + auth + rate limiting).
- `includes/rest/class-jarvis-rest-jobs-controller.php`: POST/GET /jobs, /jobs/{id}/run, /jobs/{id}/approve, /generate, /publish.
- `includes/rest/class-jarvis-rest-health-controller.php`: GET /health.

### 5. Services

- Auth, SEO scoring, image service, content pipeline (run_scheduled_campaigns, run_job, approve_job, generate_once; jarvis_content_engine_generate filter).

### 6. Cron / Uninstall

- Daily event and retry event; uninstall drops table and option when cleanup_on_uninstall is enabled.

---

## Integration Modes

**Mode A – External Agent:** Agent calls POST /generate or /publish; plugin creates posts and logs.

**Mode B – Plugin Calls LLM:** Developer implements jarvis_content_engine_generate filter; filter calls OpenAI‑compatible API; returns payload; plugin persists.

---

## Quality & Extensibility

- Scoring engine, draft‑only mode, link rules, image handling.
- Filters: jarvis_content_engine_generate, jarvis_content_engine_rate_limit.
