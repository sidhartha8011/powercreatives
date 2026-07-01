# Power Creatives Connector — Security Assessment

| | |
|---|---|
| **Subject** | Power Creatives Connector (WordPress plugin) — ships as `pcm-connector/pcm-connector.php` |
| **Version assessed** | **2.1.3** |
| **Source of record** | Generated from the template in `includes/modules/seohub/service.php` (`PCM_SEOHub_Service::connector_php_simple()`) |
| **Assessment date** | 2026-07-01 |
| **Assessment type** | Manual static source-code review against the WordPress / OWASP / CWE vulnerability taxonomy |
| **Result** | **No exploitable vulnerabilities identified.** 2 low/informational hardening observations. |

---

## 1. Executive summary

The Power Creatives Connector is a small, single-file WordPress plugin installed on a client's site to let the Power Creatives hub read SEO metadata and perform builder-aware link edits over the WordPress REST API. It was reviewed against the vulnerability classes that the overwhelming majority of published WordPress-plugin CVEs are assigned to (broken access control, SQL injection, XSS, CSRF, SSRF, file upload / code execution, object injection, information exposure, privilege escalation, path traversal).

**Overall posture: strong.** Every state-changing endpoint is authenticated and capability-gated, all database access uses prepared statements, all dynamic output is escaped, the one admin-editable HTML field is `wp_kses_post`-filtered, and the plugin contains **no file-write, file-include, deserialization-of-request-data, or command-execution sinks**. The connector is specifically **not** susceptible to the "missing authentication for a critical function" class represented by the reference CVE below.

Two non-exploitable, defence-in-depth observations are documented for completeness: **OBS-1** — `maybe_unserialize()` is used while iterating a post's own metadata (standard WordPress practice, reachable only by an authenticated `edit_post`-capable user, not request-controlled); and **OBS-2** — the `application_password_is_api_request` filter is forced true to make the hub's authentication reliable, which broadens where a *valid* credential is accepted but bypasses no authorization. Neither is a vulnerability; both carry an optional hardening suggestion.

---

## 2. Reference CVE (scope anchor)

The assessment was anchored to the example provided:

> **[CVE-2026-8732](https://nvd.nist.gov/vuln/detail/CVE-2026-8732)** — *WP Maps Pro* ≤ 6.1.0. **CWE-306: Missing Authentication for Critical Function.** CVSS **9.8 (Critical)**. An unauthenticated attacker reaches an inadequately protected AJAX action via a publicly available nonce and creates a WordPress administrator account, taking over the site.

This is the single most common WordPress-plugin vulnerability pattern: a privileged action exposed without a real capability check. §4 and §5 below demonstrate that the connector does not expose any such action — **no connector endpoint performs a privileged operation without an enforced capability check**, and the connector creates no users, roles, or capabilities at all.

---

## 3. Scope, methodology & limitations

**In scope.** The complete connector plugin as shipped in v2.1.3: its REST routes, public (`template_redirect` / `wp_head` / `robots_txt`) output paths, admin page, Application-Password provisioning, and the link scan/replace engine.

**Methodology.** Line-by-line manual review of the source, mapping each attack surface to its corresponding CWE / OWASP category, then verifying the control that mitigates it directly in code. Each finding cites the responsible function or route.

**Limitations / honest disclaimer.**
- This is a **static source-code audit**, not an automated scan against a live vulnerability feed. It does **not** query the NVD, WPScan, or Patchstack databases (no network access during the review), and it cannot detect a vulnerability in a third-party dependency the connector does not ship — the connector has **no bundled third-party dependencies**; it uses only WordPress core APIs.
- It assesses the connector **code**, not the security of the WordPress core, the hosting environment, the web server, TLS configuration, or other plugins installed alongside it.
- "No vulnerability identified" reflects this review's depth, not a guarantee of absolute security. See §7 for the recommended ongoing-monitoring posture.

---

## 4. Architecture & trust model

- **Purpose.** Expose SEO meta + a builder-aware link editor to a single trusted hub, over the WordPress REST API.
- **Authentication.** The hub authenticates as a `manage_options` administrator using a **WordPress Application Password** that the connector provisions once at activation (`pcm_conn_ensure()`) and the site admin pastes into the hub. WordPress core validates the Basic-auth credential on every request before the route's `permission_callback` runs.
- **Authorization.** Every route additionally enforces a WordPress capability (`manage_options`, `edit_posts`, or per-object `edit_post`) inside its `permission_callback`.
- **Trust boundary.** The connector grants the hub the privileges of the administrator who installed it — **by design**. This is the same trust an admin extends to any REST client they authorize; the connector does not escalate privileges beyond the installing account.

---

## 5. Attack-surface inventory & control verification

### 5.1 REST endpoints (`pcm-conn/v1`)

| Route | Method | `permission_callback` | Per-object check | Purpose |
|---|---|---|---|---|
| `/site` | GET / POST | `current_user_can('manage_options')` | — | Read/write SEO-meta settings |
| `/replace-url` | POST | `current_user_can('manage_options')` | **`current_user_can('edit_post', $pid)`** | Builder-aware link replace |
| `/scan-links` | GET | `current_user_can('edit_posts')` | post existence check | Enumerate a post's links |
| `/ai` | GET / POST | `current_user_can('manage_options')` | — | robots.txt / JSON-LD config |
| `/llm-info` | GET / POST | `current_user_can('manage_options')` | — | LLM-info page content |

**Verified:** No route is registered without a `permission_callback`, and none returns `__return_true`. `/replace-url` and `/scan-links` additionally validate the target post exists and (for writes) that the caller can edit *that specific* post — defeating insecure-direct-object-reference (IDOR, CWE-639). This is the structural opposite of the reference CVE.

### 5.2 Public (unauthenticated) output paths — read-only by design

| Surface | Output | Escaping |
|---|---|---|
| `/llm-info` HTML page (`template_redirect`) | Admin-set overview HTML | Stored via `wp_kses_post()` on write; title via `esc_html()` |
| `robots.txt` (`robots_txt` filter) | Admin-set robots rules | Plain-text append |
| `<head>` SEO meta (`wp_head`) | description / keywords | `esc_attr()` |
| JSON-LD (`wp_head`) | Admin-set JSON | `json_decode()`-validated before echo |

**Verified:** All public paths are **read-only** and emit only administrator-configured, sanitized content. None accepts a state-changing request. There is no unauthenticated write surface anywhere in the connector.

### 5.3 Admin page & provisioning

- The connector admin page and the Application-Password regeneration action are gated by `current_user_can('manage_options')` **and** `check_admin_referer('pcm_conn_gen')` (anti-CSRF nonce).
- The connection code is `base64(json({url,user,pass}))` shown only to the logged-in admin on their own settings screen, and rendered through `esc_textarea()`.

---

## 6. Vulnerability-class findings

| # | Class (CWE) | Status | Evidence |
|---|---|---|---|
| 1 | **Broken access control / missing auth (CWE-306, CWE-862, CWE-639)** | ✅ Not vulnerable | Every route has an enforced `permission_callback`; per-object `edit_post` check on `/replace-url`; no user/role creation anywhere. |
| 2 | **SQL injection (CWE-89)** | ✅ Not vulnerable | All reads use `$wpdb->prepare(… WHERE post_id = %d …)`; all writes use `$wpdb->update()` with structured args; table names come from `$wpdb->postmeta` (core), never input. |
| 3 | **Cross-site scripting (CWE-79)** | ✅ Not vulnerable | Dynamic output escaped with `esc_html` / `esc_attr` / `esc_textarea`; the one rich-HTML field is `wp_kses_post`-filtered on write and is admin-only. |
| 4 | **CSRF (CWE-352)** | ✅ Not vulnerable | Admin POST actions use `check_admin_referer()`; REST routes authenticate per-request (Application Password / cookie + nonce), so there is no ambient-credential CSRF surface. |
| 5 | **SSRF (CWE-918)** | ✅ Not vulnerable | The only outbound `wp_remote_post()` targets the **fixed** `PCM_CONN_HUB_URL` constant set at install; no request value influences the destination. |
| 6 | **Unrestricted upload / RCE / code injection (CWE-434, CWE-94, CWE-95)** | ✅ Not vulnerable | No `eval`, `system`, `exec`, `shell_exec`, `create_function`, dynamic `include`, `file_put_contents`, `fopen`, or `unlink`; the connector handles no file uploads. |
| 7 | **PHP object injection / unsafe deserialization (CWE-502)** | ⚠️ Low — see OBS-1 | `maybe_unserialize()` is applied to a post's **own** stored metadata during scan/replace, not to request data; reachable only by an authenticated `edit_post`-capable caller. |
| 8 | **Sensitive data exposure (CWE-200)** | ✅ Acceptable | The Application Password is stored in `wp_options` and shown (base64-encoded) only to `manage_options` admins — required for the paste-to-connect UX; the hub stores it encrypted at rest. base64 is encoding, not secrecy, and is never exposed to a lower-privileged user. |
| 9 | **Privilege escalation (CWE-269)** | ✅ Not vulnerable | The connector creates no roles/caps and never elevates a caller; the hub operates strictly at the installing admin's privilege level. |
| 10 | **Path traversal / LFI (CWE-22, CWE-98)** | ✅ Not vulnerable | `parse_url($_SERVER['REQUEST_URI'])` is used only for read-only path *string matching* (`/llm-info`, `/robots`); no filesystem path is ever derived from input. |
| 11 | **Open redirect (CWE-601)** | ✅ Not vulnerable | The connector issues no `wp_redirect`/`Location` based on input. |

### OBS-1 (Low / informational) — `maybe_unserialize()` on stored post metadata

**Where.** `replace_links()`, `replace_link_in_element()`, and `scan_links()` iterate a post's metadata rows and call `maybe_unserialize()` on each `meta_value`.

**Why it is low risk.** The deserialized data is the **site's own existing post metadata**, not attacker-supplied request input. Reaching it requires authentication **and** the `edit_post` capability for the target post. Exploiting CWE-502 here would additionally require a pre-existing write primitive to plant a crafted serialized object in that post's meta **and** a viable POP gadget chain in the loaded code — neither of which the connector provides. This is the same pattern WordPress core itself uses for post meta.

**Optional hardening.** Restrict deserialization to the known page-builder meta keys the engine actually targets (e.g. `_elementor_data`, `_bricks_*`, Divi/Oxygen keys), or short-circuit when the raw value does not begin with a serialized/JSON marker, so unrelated serialized blobs are never instantiated.

### OBS-2 (Informational) — `application_password_is_api_request` forced true

**Where.** The connector adds `add_filter('application_password_is_api_request', '__return_true')` so WordPress accepts the hub's Application-Password (Basic-auth) credential reliably, including on hosts where core's default API-request detection or the `?rest_route=` form would otherwise reject it.

**Why it is informational, not a vulnerability.** This filter only widens *where a valid credential is accepted for authentication*; it does **not** bypass any authorization. Every privileged action still runs behind its route's capability check (§5.1), and an attacker without the high-entropy app password gains nothing from it. An attacker *with* the credential already holds the installing admin's access regardless of this filter. Standard login throttling and the 24-character app-password entropy mitigate credential-guessing.

**Optional hardening.** Scope the filter to the connector's own REST namespace (return `true` only when the request path targets `pcm-conn/v1`, else fall through to core's default) so app-password acceptance is not broadened site-wide. The filter is also registered twice (once per route block) — collapsing it to a single registration is a harmless tidy-up.

---

## 7. Recommendations

**Code (optional, low priority).**
1. Apply the OBS-1 hardening (scope deserialization to known builder meta keys) at the next connector revision.
2. Apply the OBS-2 hardening (scope `application_password_is_api_request` to the `pcm-conn/v1` namespace; collapse the duplicate filter registration). *No code change is required for the connector to be considered safe.*

**Process / ongoing monitoring (recommended).**
2. Keep the connector minimal — its safety derives largely from having **no third-party dependencies** and **no file/exec sinks**. Preserve that property in future versions.
3. For continuous coverage against *newly published* CVEs (which a point-in-time code review cannot predict), enrol the connected sites in an automated WordPress vulnerability monitor — **WPScan**, **Patchstack**, or **Wordfence** — which track the live NVD / vendor feeds this static review cannot.
4. Re-run this assessment whenever the connector adds a new REST route, a new public output path, a file operation, or a third-party dependency.

---

## 8. Conclusion

As of version **2.1.3**, the Power Creatives Connector presents **no identified exploitable vulnerability** across the standard WordPress / OWASP / CWE attack classes, and is specifically **not** susceptible to the missing-authentication class of the reference CVE-2026-8732. The single observation (OBS-1) is a defence-in-depth refinement, not a vulnerability. The connector's small footprint, dependency-free design, uniformly capability-gated endpoints, prepared SQL, and escaped output give it a strong security posture.

---

### Appendix A — Code references (in `includes/modules/seohub/service.php`, connector template)

| Control | Anchor |
|---|---|
| Route registration + permission callbacks | `register_rest_route('pcm-conn/v1', …)` — `/site`, `/replace-url`, `/scan-links`, `/ai`, `/llm-info` |
| Per-object authorization | `current_user_can('edit_post', $pid)` in the `/replace-url` callback |
| Prepared SQL | `$wpdb->prepare("… WHERE post_id = %d …")` in `scan_links()` / `replace_links()` / `replace_link_in_element()` |
| Output escaping | `esc_html()` (llm-info title), `esc_attr()` (meta tags), `esc_textarea()` (connection code) |
| Input sanitization | `wp_kses_post()` (llm-info content), `absint()` (post ids) |
| Anti-CSRF | `check_admin_referer('pcm_conn_gen')` |
| Auth provisioning | `pcm_conn_ensure()` → `WP_Application_Passwords::create_new_application_password()` |
| Fixed outbound target | `wp_remote_post(PCM_CONN_HUB_URL, …)` |

### Appendix B — Document control

| Field | Value |
|---|---|
| Document | Power Creatives Connector — Security Assessment |
| Connector version | 2.1.3 |
| Date | 2026-07-01 |
| Method | Manual static source-code review (no automated CVE-feed scan) |
| Next review trigger | New route / public output / file op / dependency, or a relevant published CVE |
