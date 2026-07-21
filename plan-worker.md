# Plan v2: Social media source WITH Apify account-watching (Filip confirmed Apify)

Supersedes plan v1's "reject IG/X/TikTok/FB accounts" — those now watch via Apify. Everything else
from v1 stands (post links → one article each w/ the post link embedded; free-feed platforms via
RSS conversion; social rider; badges).

## Verified facts
- Apify sync endpoint: `POST https://api.apify.com/v2/acts/{actorId}/run-sync-get-dataset-items`
  (Bearer token or ?token=; holds connection ≤5 min; returns dataset items directly — no polling).
  Official IG actor `apify~instagram-scraper` (input: directUrls/resultsType 'posts'/resultsLimit).
  TikTok `clockworks~tiktok-scraper`, X `apidojo~tweet-scraper`, FB `apify~facebook-posts-scraper`
  (community actors — schemas can drift → ONE platform→{actor,input-builder,item-mapper} map,
  overridable via a `pcm_apify_actor_map` filter, so drift is a config fix not a code fix).
- Integrations module is provider-generic (brevo/proranktracker/gsc already ride pcm_integrations
  keyed by provider+userId) → provider 'apify' is additive; key lookup for cron context mirrors
  PCM_LLM::get_api_key's prepared query (owner-scoped PCM user id, NEVER get_current_user_id).
- Cost control: Apify bills per result → social accounts are scanned every ≥4h (config-stamped
  lastSocialScan per strategy; resultsLimit 10/account), NOT hourly like RSS.
- No live Apify smoke possible until the user adds a real token — e2e verified with a
  pre_http_request-faked Apify response; the client class + mapping are unit-tested.

## Config contract additions (v1 keys stand)
- config.socialLinks: string[] 1–10 (all pasted links, any platform, post or account)
- config.socialAccounts: [{url, platform}] — Apify-watched accounts (derived at create)
- config.lastSocialScan: 'Y-m-d H:i:s' (watcher-internal)
- Item config for social items: {sourceLink, sourceTitle, sourceText?≤1000ch, social:true}
- Create-time guard: Apify-platform ACCOUNT links + no active apify key for the owner → honest
  controller error ("Add your Apify API token in Integrations first"). Post links never require it.

## Constraints (echoed to every worker)
- PCM_VERSION 1.7.0 + DB 1.43.0 untouched; NO schema change. Baseline: suite 399/3 known
  (TextMatcher, SeoIntegration, PlatformRoleInvariant); tsc 59; build green.
- HARD LANES: never touch includes/modules/optimizer/**, includes/modules/keywords/** (backend),
  includes/modules/seo/**, SectionModal.tsx, KeywordsDrawer.tsx.
- $wpdb->prepare; REST via success()/error()/not_found(); network isolated try/catch (Apify sync
  call timeout 120s inside cron context only — NEVER in a user-facing request path); no new deps.
- Minimal diff; nothing committed. House test conventions.

## Steps
| # | Step | Files | Route |
|---|---|---|---|
| 1 | **Classifier + resolvers + Apify mapping.** New class-pcm-social-source.php: pure `classify($url)` → {platform: youtube/bluesky/reddit/instagram/tiktok/x/facebook/unknown, kind: post/account}; `account_feed_url()` for free platforms (YT channelId page-resolve, bsky /rss, reddit .rss) — network isolated 5s; `post_context($url)` via WP_oEmbed get_data → og:meta → URL-label (never throws); `apify_request($platform,$url,$limit)` → {actor, input} per the actor map (+ `pcm_apify_actor_map` filter); `apify_map_items($platform,$raw)` → watcher shape {permalink,id,title,date,text} per platform's known fields (defensive ??-chains). ~25-case unit tests (classify matrix, request shapes, mapping w/ real-shaped fixtures, feed conversion). | includes/modules/strategy/class-pcm-social-source.php (new), tests/unit/StrategySocialClassifyTest.php (new) | fable |
| 2 | **Apify client + integrations provider.** New class-pcm-apify.php (strategy module): `fetch_account_items(string $platform, string $url, int $limit, int $user_id): array` — token via prepared pcm_integrations query (provider 'apify', owner id, active status — mirror PCM_LLM::get_api_key's query verbatim, find it first); POST run-sync-get-dataset-items, timeout 120, sslverify true; non-200/WP_Error/бad JSON → error_log + array(); `has_key(int $user_id): bool`. Integrations backend: add 'apify' to the providers list (find where brevo/prt entries live; name "Apify (social scraping)", key-only, no models). Integrations UI shows it automatically if list-driven — verify; add minimal entry if hardcoded. Unit tests: token lookup fake, request shape capture, error degradation. | includes/modules/strategy/class-pcm-apify.php (new), includes/modules/integrations/controller.php, (app/src/modules/Integrations/index.tsx ONLY if list not backend-driven), tests/unit/StrategyApifyClientTest.php (new) | fable |
| 3 | **Create-path + watcher wiring + rider.** sanitize: sourceMode 'social', socialLinks 1–10; create (service funnel, post-persist): classify links → posts: items NOW w/ post_context (+queue kick, reuse first-scan idiom); free accounts → converted feeds merged into rssFeeds + rss first-scan armed; Apify accounts → config.socialAccounts (controller pre-validates apify key exists, else honest error BEFORE create); zero-keyword create allowed for social. Watcher: scan_rss_strategy gains a social branch — when socialAccounts non-empty AND lastSocialScan stale ≥4h: PCM_Apify::fetch per account (limit 10) → apify_map_items → SAME ingest/queue/backpressure path → stamp lastSocialScan. Rider: social variant (sourceMode check) — "Write an article about this social media post: '{title}' ({link}). {Post text: "{sourceText}"} … INCLUDE a visible link to the original post in the article HTML." Tests: sanitize, social create split (posts/free/apify), key-guard rejection, stale-gate math, rider wording incl. caption. | includes/modules/strategy/service.php, controller.php, tests/unit/StrategySocialSourceTest.php (new) | fable |
| 4 | **Dialog.** Source: Keywords / RSS feeds / Social media (Share2): 1–10 link rows + angle + helper "One article per post link. Accounts are auto-watched — Instagram/TikTok/X/Facebook need your Apify key (Integrations); YouTube/Bluesky/Reddit are free."; trigger locks 'New source item'; cadence/publishing/duration/research unchanged; submit sourceMode 'social' + socialLinks (≥1 valid http(s) guard). | app/src/modules/Keywords/CreateStrategyDialog.tsx | fable |
| 5 | **Row badge.** Third source case 'Social' (Share2, distinct tint, tooltip lists links) + link line for social strategies (socialLinks via feedHost). | app/src/modules/Strategies/index.tsx | sonnet |
| 6 | **Checkpoint (driver).** Gates; smokes: classifier on real URLs; social create split live (post link → item w/ oEmbed title; YT channel → rssFeeds; IG account w/o key → honest error; then seed fake apify key → accepted into socialAccounts); watcher social branch e2e with pre_http_request-faked Apify dataset (items ingested → queue → article item created); browser: dialog + Integrations Apify entry + row badge; spec-verifier; SESSION_LOG; zip. Report to user: Apify token setup steps. | — | driver |

Routing: 4 fable / 1 sonnet / 1 driver. Sequencing: 1 ∥ 2 ∥ 4 ∥ 5 (disjoint files, contract fixed
above) → 3 (consumes 1+2; service/controller) → 6.

## EXECUTION LOG
| Step | Status | Notes |
|---|---|---|
| 1 classifier+mapping | DONE (fable, 1st try) | PCM_Social_Source: classify 8 platforms (reserved-segment guards), account_feed_url (YT channelId resolve isolated), post_context (oEmbed→og→URL-label, never throws), apify_request map (+filter), apify_map_items (drift-safe chains + text); 24 tests/115 asserts; suite 3-known-only. Note for step 3: ingest must carry `text` into queue entries |
| 2 apify client+provider | DONE (fable, 1st try) | PCM_Apify (token query mirrors PCM_LLM verbatim; Bearer sync-run; all degradations→[]); providers REGISTRY entry landed in core/class-pcm-providers.php (justified allowlist deviation — controller just delegates; validation via api.apify.com/v2/users/me rides the generic Bearer-GET branch); UI list-driven, untouched; classmap regenerated; 7 tests; suite 3-known-only. Driver verified files+classmap directly (worker's "no git repo" claim was its own cwd error) |
| 3 create+watcher wiring | DONE (fable, 1st try) | sanitize+guards; create split (posts→items now w/ context, free accounts→feeds, apify→socialAccounts); watcher social branch (4h gate, limit 10, same ingest); text carried queue→item; rider social variant; 12 tests; suite 442/3-known. Driver fixes: first-scan armed for apify-only; get_social_strategies noted (service-local, allowlist) |
| 4 dialog | DONE (fable, 1st try) | 3-option Source segmented; social branch mirrors RSS (rows 1–10, shared angle, spec helper, guard); trigger/cadence rss→rss|social; submit per contract; tsc 59, build ✓. DRIVER follow-up applied: Keywords/index.tsx strip-gate + toast extended to social (tsc 59, build ✓ re-run) |
| 5 row badge | DONE (sonnet, 1st try) | 3-way badge (Keywords grey / RSS accent / Social primary+Share2); social link line = dedup(socialLinks ∪ rssFeeds) via feedHost; tsc 59, build ✓ |
| 6 checkpoint | DONE (driver) | live smokes: classify 10/10 real URLs; create split live (REAL oEmbed title 'Me at the zoo', channel→videos.xml, IG→socialAccounts, first-scan+queue armed); apify branch e2e w/ faked API (1 call, Bearer, actor URL, 3 items→2 created@cap+1 queued, sourceText+canonical permalinks, 4h gate holds); rider full (wording+text+angle+INCLUDE-link); browser: Apify in Integrations dropdown + Social source UI verified; spec-verifier APPROVED (0 P0/P1; P2 create-latency → driver added 20s context budget + post_context network flag; P3s logged); gates 442/3, tsc 59, build ✓ |
