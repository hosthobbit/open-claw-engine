# Rebrand and Features Patch – Summary

## 1. Changed file list

| File | Changes |
|------|--------|
| **jarvis-content-engine.php** | Plugin header: Name → Open Claw Engine; Author → Mike Warburton; Author URI / Description include Host Hobbit Ltd. |
| **includes/rest/class-jarvis-rest-health-controller.php** | `plugin` in health response → `open-claw-engine`. |
| **includes/admin/class-jarvis-admin.php** | Menu and page title → Open Claw Engine; model row → dropdown (from API) + text input + "Refresh models" link; footer attribution (Host Hobbit Ltd, Mike Warburton, URL); `handle_refresh_models()` and admin_post action; `verify_remote_image_exists` toggle in Images section; success notice after refresh. |
| **includes/admin/class-jarvis-settings.php** | New setting `verify_remote_image_exists` (bool, default true); sanitization. |
| **includes/services/class-jarvis-image-service.php** | New `is_fetchable_image_url( $url )`: HEAD then GET fallback, requires HTTP 200 and Content-Type image/*. |
| **includes/services/class-jarvis-llm-provider.php** | `sanitize_generation_images( $payload, $settings )`: ensures `featured_image_url`, `featured_image_alt`, `og_image_url`, `inline_images`; when `verify_remote_image_exists` is on, strips non-fetchable URLs and adds warnings. |
| **includes/services/class-jarvis-model-discovery.php** | **New.** GET `{api_base}/models` with Bearer key; transient cache 15 min; fallback list; `get_models()`, `refresh_models()`. |
| **README.md** | Rebrand to Open Claw Engine; attribution (Host Hobbit Ltd, Mike Warburton, hosthobbit.com). |
| **docs/API.md** | Title and attribution. |
| **docs/ARCHITECTURE.md** | Rebrand and attribution. |
| **docs/SECURITY.md** | Rebrand and attribution. |

**Unchanged for compatibility:** Option key `jarvis_content_engine_settings`, REST namespace `jarvis/v1`, text domain `jarvis-content-engine`, all REST endpoint paths and request/response shapes.

---

## 2. Migration notes

- **Option keys:** None changed.
- **REST:** Namespace remains `jarvis/v1`. Only the health response field `plugin` is now `open-claw-engine`.
- **New setting:** `verify_remote_image_exists` is added with default `true`. No migration; existing installs get it via `wp_parse_args` with defaults.

---

## 3. Test checklist

### Health

```bash
curl -s "https://YOUR_SITE/wp-json/jarvis/v1/health"
```

- Expect `"plugin": "open-claw-engine"`, plus `version`, `mode`, `auth_mode`, `cron_scheduled`, `time`.

### Model list discovery (admin only)

1. In admin, set **API Base URL** and **API Key**, save.
2. Reload the settings page: Model dropdown should be populated.
3. Click **Refresh models**: success notice and dropdown should reflect current API list.

---

## 4. Manual doc fix (optional)

In `docs/SECURITY.md`, one line may still start with "Jarvis' " (curly apostrophe) before "Open Claw Engine's diagnostics…". You can delete the leading "Jarvis' " by hand if you want the sentence to read only "Open Claw Engine's diagnostics…".
