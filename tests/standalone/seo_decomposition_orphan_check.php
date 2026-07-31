<?php
/**
 * Surgical orphan-call detector for the SEO decomposition.
 *
 * Only checks the methods actually MOVED out of PCM_SEO_Service. Flags any call
 * that still targets the OLD owner: `self::x()` / `$this->x()` inside
 * seo/service.php, or `PCM_SEO_Service::x()` anywhere.
 *
 * Catches exactly the bug `$this->build_field_vars()` was — a moved method left
 * behind by a name-scoped rewrite, invisible to a suite that never runs the path.
 * (Inheritance-aware by construction: we never look at unrelated method names.)
 */
$root = dirname(dirname(__DIR__));

$moved = [
    'PCM_SEO_Views'     => ['list_views','create_view','set_default_view','delete_view'],
    'PCM_SEO_Business'  => ['merge_business_ladder','biz_site_option','business_record_for_site',
                            'save_site_business_overrides','parse_maps_url','hex_to_dec'],
    'PCM_SEO_Redirects' => ['connector_supports_redirects','redirect_rows','push_redirects',
                            'list_redirects','save_redirect','delete_redirect','remote_url_usage'],
    'PCM_SEO_AI'        => ['field_use_map','field_prompts','get_default_prompts','resolve_prompt',
                            'seed_seo_templates','seo_template_prompt','seo_entry_prompt',
                            'seo_section_label','substitute_vars','sanitize_ai_output',
                            'build_field_vars','generate_field','generate_site_field','optimize_body'],
    'PCM_SEO_Page_State' => ['page_fingerprint','page_state','page_state_compare','page_state_reply',
                            'write_page_state','superseded_rule_ids'],
    'PCM_SEO_Local'     => ['seo_key_map','detect_seo_plugin','seo_get','seo_update','list_content',
                            'build_row','link_count_meta','scan_links','scan_link_details','get_post_links',
                            'nth_link_pos','purge_post_caches','deep_str_replace','replace_url_in_meta',
                            'update_post_link','remove_post_link','parse_heading_details',
                            'rebuild_heading_html','get_post_headings','parse_content_nodes',
                            'get_post_content_nodes','update_post_heading','run_prompt_section',
                            'optimize_heading','count_links','check_broken_links','save_cell_fields',
                            'duplicate','save_cell'],
    'PCM_SEO_Remote_Headings' => ['remote_get_headings','heading_target_post_id',
                            'remote_apply_heading_override','remote_update_heading',
                            'remote_update_heading_apply','remote_optimize_heading'],
    'PCM_SEO_Page_Inventory' => ['assemble_content_html','attribute_inventory',
                            'connector_rules_schema_version','connector_supports_rules','extract_imgs',
                            'heading_instructions','parse_page_snapshot','push_rules','remote_add_media',
                            'remote_fetch_snapshot','remote_get_inventory','rule_rows_for_display',
                            'rules_to_schema','section_runs','served_inventory','split_unit_sections'],
    'PCM_SEO_Editing'   => ['list_versions','migrate_site_overrides','note_wants_broad_rewrite',
                            'parse_section_reply','post_rule_rows','push_current_rules_or_rollback',
                            'record_version','rekey_section_rules','remote_optimize_section',
                            'restore_rule_rows','save_heading_rule','sentence_retention',
                            'update_owned_heading_unit','update_section_owned_heading'],
];
$owner = [];
foreach ($moved as $cls => $ms) { foreach ($ms as $m) { $owner[$m] = $cls; } }

// Verify each moved method really is defined on its new class.
echo "=== new homes ===\n";
foreach ($moved as $cls => $ms) {
    $file = null;
    foreach (glob("$root/includes/modules/seo/*.php") as $f) {
        if (preg_match('/^class\s+' . $cls . '\b/m', file_get_contents($f))) { $file = $f; break; }
    }
    if ($file === null) { echo "  !! $cls: FILE NOT FOUND\n"; continue; }
    $src = file_get_contents($file);
    $missing = [];
    foreach ($ms as $m) {
        if (!preg_match('/function\s+' . preg_quote($m, '/') . '\s*\(/', $src)) { $missing[] = $m; }
    }
    printf("  %-20s %s  %s\n", $cls, basename($file),
        $missing ? 'MISSING: ' . implode(', ', $missing) : 'all ' . count($ms) . ' present');
}

// Scan every PHP file under includes/ + tests/ for stale targets.
$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/includes"));
foreach ($it as $f) { if ($f->isFile() && $f->getExtension() === 'php') { $files[] = $f->getPathname(); } }
foreach (glob("$root/tests/unit/*.php") as $f) { $files[] = $f; }
foreach (glob("$root/tests/standalone/*.php") as $f) { $files[] = $f; }

$problems = [];
$service = "$root/includes/modules/seo/service.php";
foreach ($files as $f) {
    foreach (file($f) as $i => $l) {
        $line = $i + 1;
        $t = ltrim($l);
        if ($t === '' || $t[0] === '*' || str_starts_with($t, '//') || str_starts_with($t, '/*')) { continue; }
        $rel = str_replace($root . '/', '', $f);

        // self:: / $this-> inside service.php only (that's where the old owner was)
        if ($f === $service) {
            foreach (['/\bself::(\w+)\s*\(/', '/\$this->(\w+)\s*\(/'] as $pat) {
                if (preg_match_all($pat, $l, $mm)) {
                    foreach ($mm[1] as $m) {
                        if (isset($owner[$m])) {
                            $problems[] = "$rel:$line  calls \$this/self::$m() but it now lives on {$owner[$m]}";
                        }
                    }
                }
            }
        }
        // ANY instance-variable call to a moved method: $svc->save_cell(), $this->service->x() …
        // This is the gap that let two `$svc->save_cell()` test calls through the first time.
        if (preg_match_all('/\$\w+(?:->\w+)*->(\w+)\s*\(/', $l, $mm)) {
            foreach ($mm[1] as $m) {
                if (isset($owner[$m])) {
                    $problems[] = "$rel:$line  instance call ->$m() but it is now a static on {$owner[$m]}";
                }
            }
        }
        // STRING class-name references that name the old owner alongside a moved
        // method: ReflectionMethod('PCM_SEO_Service','x'), array('PCM_SEO_Service','x')
        // callables, add_action(..., array('PCM_SEO_Service','x')). These are invisible
        // to any `::` scan — this is the gap that hid page_versioning_test.php's
        // ReflectionMethod('PCM_SEO_Service', 'write_page_state').
        if (str_contains($l, "'PCM_SEO_Service'") || str_contains($l, '"PCM_SEO_Service"')) {
            foreach ($owner as $m => $cls) {
                if (preg_match('/[\'"]' . preg_quote($m, '/') . '[\'"]/', $l)) {
                    $problems[] = "$rel:$line  string ref 'PCM_SEO_Service' + '$m' — that method now lives on $cls";
                }
            }
        }
        // PCM_SEO_Service::moved() anywhere
        if (preg_match_all('/\bPCM_SEO_Service::(\w+)\s*\(/', $l, $mm)) {
            foreach ($mm[1] as $m) {
                if (isset($owner[$m])) {
                    $problems[] = "$rel:$line  calls PCM_SEO_Service::$m() but it now lives on {$owner[$m]}";
                }
            }
        }
    }
}

// ── ACCESSIBILITY ──────────────────────────────────────────────────────────────
// Being DEFINED on the new class is not enough — it must be REACHABLE. A method
// that was `private static` while its caller lived in the same class becomes a
// fatal the moment the caller moves to a different class:
//   "Call to private method PCM_SEO_Page_Inventory::section_runs() from scope
//    PCM_SEO_Editing"
// php -l accepts it, every test above stays green, and it only blows up when a
// user clicks the button. This check is why that class of bug is now catchable.
echo "\n=== inaccessible cross-class calls ===\n";
$access = [];
$seoFiles = glob("$root/includes/modules/seo/*.php");
foreach ($seoFiles as $file) {
    $src = file_get_contents($file);
    if (!preg_match('/^class (PCM_SEO_[A-Za-z_]+)/m', $src, $cm)) { continue; }
    $cls = $cm[1];
    preg_match_all('/^    (private|protected) static function ([a-z_][a-z0-9_]*)\(/m', $src, $mm, PREG_SET_ORDER);
    foreach ($mm as $x) {
        foreach ($seoFiles as $other) {
            if ($other === $file) { continue; }
            if (preg_match('/\b' . $cls . '::' . $x[2] . '\s*\(/', file_get_contents($other))) {
                $access[] = "$cls::{$x[2]}() is {$x[1]} but is called from " . basename($other)
                          . " — promote it to public";
            }
        }
    }
}
$access = array_unique($access);
if (empty($access)) { echo "  NONE\n"; } else { foreach ($access as $a) { echo "  $a\n"; } }

echo "\n=== stale call sites ===\n";
if (empty($problems)) { echo "  NONE\n"; }
else { foreach ($problems as $p) { echo "  $p\n"; } }
exit((empty($problems) && empty($access)) ? 0 : 1);
