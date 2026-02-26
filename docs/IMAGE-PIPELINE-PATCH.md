# Image Pipeline Patch – Deliverables

## 1. Changed file list

| File | Changes |
|------|--------|
| `includes/services/class-jarvis-image-service.php` | Extended `import_image($url, $alt, $post_id)`; added URL/mime validation, filename fallback, attach to post; added `set_og_image_from_url()`, `import_inline_images()`, `redact_url()`; allowed mime types (jpeg/png/webp/gif, no SVG by default). |
| `includes/services/class-jarvis-content-pipeline.php` | Normalized payload (featured_image_url, featured_image_alt, og_image_url, inline_images; legacy `featured_image`); featured then SEO/OG then inline flow; `inject_inline_images_into_content()` + placement helpers; `apply_seo_meta()` extended for OG image + fallback; image log entries merged into job logs; guardrail for `featured_required`; response includes `images` diagnostics. |
| `includes/admin/class-jarvis-settings.php` | New defaults: `use_featured_as_og_fallback` (true), `enable_inline_image_injection` (true); sanitization for both. |
| `includes/admin/class-jarvis-admin.php` | New "Images" settings section: featured_required, inline_image_count, enable_inline_image_injection, use_featured_as_og_fallback. |

**Not changed:** REST controller already returns the pipeline result; the new `images` key is included automatically in `/generate` and `/publish` success responses.

---

## 2. Notes on Yoast / Rank Math keys used

- **Plugin fallback (always stored)**  
  - `_jarvis_og_image_id` (attachment ID)  
  - `_jarvis_og_image_url` (full URL)  

- **Yoast SEO** (when `WPSEO_VERSION` is defined)  
  - `_yoast_wpseo_opengraph-image` – OG image URL  
  - `_yoast_wpseo_opengraph-image-id` – attachment ID (string)  

- **Rank Math** (when `RANK_MATH_VERSION` is defined)  
  - `rank_math_facebook_image` – OG image URL  
  - `rank_math_facebook_image_id` – attachment ID (string)  

OG image is set from `og_image_url` when provided; otherwise, when `use_featured_as_og_fallback` is on, the featured image is used for both plugin fallback and Yoast/Rank Math.

---

## 3. Curl test payloads

Base URL and auth depend on your install. Replace `SITE` and use Application Password or your auth method.

### A. Featured only (generator returns only featured)

The API request does not send image URLs; the **generator** (filter or external agent) returns them. Example: generator returns only `featured_image_url` (and optionally `featured_image_alt`).

**Request (same for all three scenarios):**

```bash
curl -s -X POST "https://SITE/wp-json/jarvis/v1/generate" \
  -H "Content-Type: application/json" \
  -u "USER:APP_PASSWORD" \
  -d '{"subject": "Test post with featured image only"}'
```

**Example generator payload (what your filter/agent should return for "featured only"):**

```json
{
  "title": "Test post with featured image only",
  "content": "<p>Intro paragraph.</p><h2>Section</h2><p>More text.</p>",
  "excerpt": "Short excerpt.",
  "featured_image_url": "https://example.com/valid-image.jpg",
  "featured_image_alt": "Featured image alt text"
}
```

**Expected success response snippet:**

```json
{
  "status_code": 201,
  "job_id": 1,
  "post_id": 123,
  "post_status": "draft",
  "score": { ... },
  "images": {
    "featured_set": true,
    "inline_imported": 0,
    "og_set": false,
    "errors": []
  }
}
```

(`og_set` can be true if `use_featured_as_og_fallback` is on.)

---

### B. Featured + inline + OG (full image payload)

**Same request URL/body as above.** Example generator return that would drive featured + inline + OG:

```json
{
  "title": "Full image test",
  "content": "<p>First paragraph.</p><h2>First section</h2><p>Text.</p><h2>Second section</h2><p>More.</p>",
  "excerpt": "Excerpt.",
  "featured_image_url": "https://example.com/featured.jpg",
  "featured_image_alt": "Featured alt",
  "og_image_url": "https://example.com/og-image.jpg",
  "inline_images": [
    {
      "url": "https://example.com/inline1.jpg",
      "alt": "Inline one",
      "caption": "Caption one",
      "placement_hint": "after_intro"
    },
    {
      "url": "https://example.com/inline2.jpg",
      "alt": "Inline two",
      "caption": "Caption two",
      "placement_hint": "after_h2_1"
    }
  ]
}
```

**Expected success response snippet:**

```json
{
  "status_code": 201,
  "job_id": 2,
  "post_id": 124,
  "post_status": "draft",
  "score": { ... },
  "images": {
    "featured_set": true,
    "inline_imported": 2,
    "og_set": true,
    "errors": []
  }
}
```

---

### C. Broken image URLs (graceful degradation)

Use a generator that returns invalid or unreachable image URLs. Post should still be created; image errors appear in `images.errors` and in job logs.

**Example generator payload (broken URLs):**

```json
{
  "title": "Post with broken images",
  "content": "<p>Content here.</p>",
  "excerpt": "Excerpt.",
  "featured_image_url": "https://invalid.example.com/404.jpg",
  "inline_images": [
    { "url": "not-a-valid-url", "alt": "Bad" },
    { "url": "https://example.com/missing.png", "alt": "Missing" }
  ]
}
```

**Expected success response (post still created; draft):**

```json
{
  "status_code": 201,
  "job_id": 3,
  "post_id": 125,
  "post_status": "draft",
  "score": { ... },
  "images": {
    "featured_set": false,
    "inline_imported": 0,
    "og_set": false,
    "errors": [
      {
        "stage": "featured",
        "message": "Image download failed after multiple attempts.",
        "error_class": "timeout",
        "source_meta": { "scheme": "https", "host_redacted": "***.example.com", "is_https": true, "ext": "jpg" }
      },
      {
        "stage": "inline",
        "message": "Image URL must be http or https.",
        "error_class": "http",
        "source_meta": { "scheme": "other", "host_redacted": "", "is_https": false, "ext": "" }
      }
    ]
  }
}
```

If **featured_required** is true and featured import fails, `post_status` remains `draft` and `guardrail_reasons` (if exposed) would include `featured_image_required_but_failed`; the same `images.errors` and job logs (source `image_service`, stage `featured`) are written.

---

## 4. Image source diagnostics (hostname-redacted)

Image import failures include a **redacted source fingerprint** so you can identify bad or misconfigured sources (e.g. SSL issues) without logging full URLs or secrets.

- **No full URL, path, or query is ever stored** in the REST response, `logs_json`, or debug output.
- Each error includes:
  - **`source_meta`**: `scheme` (http|https|other), `host_redacted` (e.g. `***.example.com`), `is_https`, `ext` (file extension guess, if any).
  - **`error_class`**: one of `ssl`, `timeout`, `dns`, `http`, `mime`, `sideload`, `unknown` for triage.

**Explicit:** No full URL, query string, or token is stored in responses or logs. Only the redacted host (e.g. `***.example.com`), scheme, and optional file extension are included.

---

## 5. Image host reliability rules (must follow)

When returning `featured_image_url`, `og_image_url`, and `inline_images[].url`, generators **must** use only URLs that pass these rules (enforced by the plugin when the allowlist is enabled):

1. **HTTPS only** (`https://`).
2. **Host must be from the allowlist:** `images.unsplash.com`, `cdn.pixabay.com`, `upload.wikimedia.org` (or add via filter).
3. **URL must be a direct image file** ending in: `.jpg`, `.jpeg`, `.png`, `.webp` (no SVG).
4. **Publicly reachable** without auth, cookies, or signed tokens.
5. **If no compliant image is available**, return empty image fields rather than risky URLs.

---

## 6. Security / quality constraints (implemented)

- Only `http` and `https` URLs allowed for import.
- Allowed mime types: `image/jpeg`, `image/png`, `image/webp`, `image/gif`. SVG disallowed unless filter allows.
- Alt and caption sanitized; img `src`/`alt` and figcaption escaped in injected HTML.
- Image failure does not kill the post unless `featured_required` is true and featured image import fails.
