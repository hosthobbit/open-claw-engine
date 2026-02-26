🦾 Open Claw Engine

Open Claw Engine is a WordPress plugin that lets an external AI agent generate and publish daily, SEO-optimized long-form posts with images, internal/external links, schema markup, and quality guardrails.

Designed by: Host Hobbit Ltd
Author: Mike Warburton
🌐 https://hosthobbit.com

🚀 Features

Daily long-form content generation

SEO optimization with schema markup

Internal & external link control

Image handling (featured + inline)

Editorial guardrails & quality thresholds

REST API driven architecture

Draft-only or auto-publish workflows

⚠️ No virality promises — this focuses on sustainable SEO and structured publishing.

⚙️ Generation Modes
🟢 Mode A (Recommended) – External Agent

External agent calls the plugin REST API using:

Application Passwords

JWT

HMAC

🔵 Mode B – Direct LLM

Plugin delegates generation to an OpenAI-compatible endpoint via hooks/filters.

⚠️ TODO

Image generation is currently not working correctly.
Posts generate fine, but images are not being created properly.

📋 Requirements

PHP 8.1+

WordPress 6.x

HTTPS (strongly recommended for REST access)

Optional

Rank Math or Yoast SEO (deeper integration)

JWT plugin (if using JWT authentication)

📦 Installation
Install via ZIP (Recommended)
1️⃣ Download

Go to:

https://github.com/hosthobbit/open-claw-engine

Click Code → Download ZIP

2️⃣ Prepare the ZIP

Unzip the downloaded file

Rename folder to:

jarvis-content-engine

If GitHub named it open-claw-engine-main, rename it.

Re-zip so structure looks like:

jarvis-content-engine/
  jarvis-content-engine.php
  README.md
  includes/
3️⃣ Install in WordPress

Log in to wp-admin

Go to Plugins → Add New

Click Upload Plugin

Select jarvis-content-engine.zip

Click Install Now

Click Activate

🗂 Manual Installation

Copy the folder to:

wp-content/plugins/

Then activate Open Claw Engine from Plugins.

🔧 Post-Install Setup

Go to:

Open Claw Engine → Settings

Configure:

Integration mode

Authentication mode

Content defaults

SEO defaults

Link rules

Image rules

Quality thresholds

Optional:
Enable Cleanup on uninstall if you want data removed when uninstalling.

🧠 How to Use
First-Time Setup

In WordPress sidebar:

Open Claw Engine

You will see:

Settings

Job Logs

🟢 Mode A – External Agent

An external service calls the REST API.

Auth Options

Application Password (recommended)

JWT

HMAC

Application Password Setup

Create a WordPress user

Go to Users → Edit User

Scroll to Application Passwords

Generate password

Use username + password in your agent

HMAC Setup

Set HMAC Shared Secret in plugin

Sign requests using secret
See: docs/API.md

🔵 Mode B – Direct LLM

Enable:

Use built-in OpenAI-compatible provider

Provide:

API Base URL

API Key

Model

Click Refresh models if needed.

📝 Content & SEO Defaults
Content

Default subject

Categories / tags

Tone (professional / conversational / technical)

Voice (1st / 2nd / 3rd person)

Word count range

Publish cadence

Daily time

SEO

Meta title template

Slug strategy

Primary + secondary keywords

Links

Min/max internal links

Min/max external links

Allowlist / blocklist

Images

Featured image required

Inline image count

Alt text required

Verify remote image exists

Use featured as OG fallback

Quality

Readability minimum

SEO score minimum

Draft-only (recommended)

Auto-publish if thresholds met

🏃 Creating & Running Jobs
Via REST API (Mode A)
POST /wp-json/jarvis/v1/jobs
POST /wp-json/jarvis/v1/jobs/{id}/run
POST /wp-json/jarvis/v1/generate
POST /wp-json/jarvis/v1/publish

Monitor progress in Job Logs.

Via WP-Cron

If publish cadence is set (e.g. daily at 03:00), WordPress will run automatically.

Check Job Logs afterward.

🛠 Editorial Workflow
Draft-Only (Recommended)

Generated posts:

Saved as drafts

Reviewed manually

Published when ready

Approve via API
POST /wp-json/jarvis/v1/jobs/{id}/approve
❤️ Health & Monitoring
GET /wp-json/jarvis/v1/health

Returns plugin status + config summary (no secrets).

See docs/API.md for full schemas.

🧩 Configuration Overview
Integration Mode

external – REST driven (recommended)

direct – LLM via filter

Auth Mode

Application Password

JWT

HMAC

Secrets are never displayed in plain text.

🔬 How It Works (High Level)

Configure campaign defaults

WP-Cron triggers run

Generator returns:

Title

Outline

Long-form article

FAQ

CTA

Links

Schema

Plugin:

Creates post

Downloads images

Applies SEO

Scores content

Saves draft or publishes

Logs stored in custom table

/health endpoint available

🌐 REST API Overview

Base namespace:

/wp-json/jarvis/v1
Core Endpoints
POST /jobs
GET  /jobs/{id}
POST /jobs/{id}/run
POST /jobs/{id}/approve
POST /generate
POST /publish
GET  /health

Authentication:

Application Password

JWT

HMAC

See docs/API.md for examples.

🧠 Mode B Filter Example
add_filter( 'jarvis_content_engine_generate', function( $generation, $context ) {

    return array(
        'title'              => '...',
        'content'            => '<p>...</p>',
        'excerpt'            => '...',
        'internal_links'     => array(),
        'external_links'     => array(),
        'featured_image_url' => 'https://...',
        'featured_image_alt' => '...',
        'meta_title'         => '...',
        'meta_description'   => '...',
        'og_title'           => '...',
        'og_description'     => '...',
        'schema_jsonld'      => array(),
        'faq'                => array(
            array( 'q' => 'Question?', 'a' => 'Answer...' ),
        ),
        'cta'                => '<p>Call-to-action...</p>',
    );

}, 10, 2 );
🧪 Development & Testing

Tests located in:

tests/

Run:

phpunit --testsuite jarvis-content-engine
🔐 Security Notes

Admin actions require manage_options

REST routes enforce edit_posts / publish_posts

Inputs sanitized per WordPress standards

No hard-coded secrets

See docs/SECURITY.md

📜 License

GPL-2.0-or-later

🤖 GitHub Actions

Located in:

.github/workflows/ci.yml

Runs on push + pull request

PHP syntax check

Optional WordPress PHPCS

🔗 Connecting to GitHub
git init
git add .
git commit -m "Initial commit: Open Claw Engine"
git remote add origin https://github.com/YOUR_USERNAME/open-claw-engine.git
git branch -M main
git push -u origin main

Then open the Actions tab to see CI run.
