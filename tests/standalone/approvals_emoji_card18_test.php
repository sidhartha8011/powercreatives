<?php
/**
 * Card 18 — Bugfixes / Approvals Module / Reviews. The live item (twice re-reported):
 * "Clicking on one ad opens the edit … if the ad has emojis, they disappear and get
 * removed once the ad is saved" → "it still seems to disappear … click one ad → click
 * outside → ad saved without emojis" → 2026-08-10 "It is still killing the emojis".
 * (The launch-lane edit + clipboard-title items are struck through — done.)
 *
 * ROOT CAUSE (closes the 07-31 investigation): WordPress's wp-emoji script rewrites
 * emoji characters into <img class="emoji"> across the whole document via a
 * MutationObserver. The ad BODY is a ProseMirror (Tiptap) contentEditable whose schema
 * has no image node — ProseMirror re-parses the mutated DOM, DROPS the unknown <img>,
 * and onUpdate fires with emoji-less text; clicking outside saves that. The headline /
 * description are <textarea>s whose VALUES twemoji never touches — which is exactly
 * the recorded asymmetry (🎬 died in the body while 📊 survived in the headline). The
 * server chain was already verified emoji-safe (raw-body fallback, entity decode,
 * sanitize_* keeps emoji, wp_json_encode storage).
 *
 * THE FIX, three layers, pinned here (client halves EXECUTED in node):
 *   1. PHP: the wp-emoji detection script is removed on both app surfaces (wp-admin
 *      page + any page carrying the shortcode, inline mode included).
 *   2. main.tsx neutralizeTwemoji(): even if the script is already on the page (cached
 *      HTML, another plugin), twemoji.parse becomes a no-op — late loads included.
 *   3. Both Tiptap editors restore <img class="emoji"> back to the alt character on
 *      paste (copying from a twemoji'd page would lose emoji the same way).
 *
 * Run: php tests/standalone/approvals_emoji_card18_test.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
$ROOT = dirname(__DIR__, 2);
$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void { global $PASS, $FAIL; if ($ok) { $PASS++; echo "  ok  $name\n"; } else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; } }

$admin = file_get_contents($ROOT . '/includes/class-pcm-admin.php');
$short = file_get_contents($ROOT . '/includes/class-pcm-shortcode.php');
$main  = file_get_contents($ROOT . '/app/src/main.tsx');
$ed1   = file_get_contents($ROOT . '/app/src/components/shared/TiptapBodyEditor.tsx');
$ed2   = file_get_contents($ROOT . '/app/src/modules/Copy/components/TiptapBodyEditor.tsx');

echo "\n1. PHP: wp-emoji is dropped on BOTH app surfaces\n";
check('admin page: detection script + styles removed inside enqueue_assets', strpos($admin, "remove_action('admin_print_scripts', 'print_emoji_detection_script');") !== false && strpos($admin, "remove_action('admin_print_styles', 'print_emoji_styles');") !== false);
$pos_hook = strpos($admin, "'toplevel_page_power-creatives' !== \$hook_suffix");
$pos_rm   = strpos($admin, "remove_action('admin_print_scripts', 'print_emoji_detection_script');");
check('…AFTER the our-page-only gate (other admin screens keep their emoji script)', $pos_hook !== false && $pos_rm !== false && $pos_rm > $pos_hook, array($pos_hook, $pos_rm));
check('shortcode page: wp_head detection script removed at its priority (7) + styles', strpos($short, "remove_action('wp_head', 'print_emoji_detection_script', 7);") !== false && strpos($short, "remove_action('wp_print_styles', 'print_emoji_styles');") !== false);
$pos_short_rm = strpos($short, "remove_action('wp_head', 'print_emoji_detection_script', 7);");
$pos_inline   = strpos($short, '// Look for an explicit mode="inline"');
$pos_gate     = strpos($short, "if (!has_shortcode(\$post->post_content, 'power_creatives')) {");
check('…BEFORE the inline-mode early return (inline pages have the same editors) and AFTER the shortcode gate', $pos_short_rm !== false && $pos_inline !== false && $pos_short_rm < $pos_inline && $pos_gate !== false && $pos_short_rm > $pos_gate, array($pos_gate, $pos_short_rm, $pos_inline));

echo "\n2. main.tsx neutralizeTwemoji() — EXECUTED\n";
$start = strpos($main, 'function neutralizeTwemoji()');
$end   = strpos($main, 'if (rootElement) {', (int) $start);
check('the neutralizer exists and runs before mount, only when the app actually mounts', $start !== false && $end !== false && preg_match('/if \(rootElement\) \{\s*\r?\n\s*neutralizeTwemoji\(\);/', $main) === 1);
$fn = substr($main, (int) $start, (int) $end - (int) $start);
$fn = preg_replace('/\(x: unknown\)/', '(x)', $fn);
$fn = preg_replace('/\(t: any\)/', '(t)', $fn);
$fn = preg_replace('/const w = window as any;/', 'const w = window;', $fn);
$fn = preg_replace('/let stored: any;/', 'let stored;', $fn);
$js = $fn . "\n"
    . "const out = {};\n"
    . "// Case A: twemoji already loaded (cached page) — parse must become inert\n"
    . "global.window = { twemoji: { parse: (n) => { throw new Error('EMOJI KILLED'); } } };\n"
    . "neutralizeTwemoji();\n"
    . "try { window.twemoji.parse('🎬 node'); out.preloaded = 'inert'; } catch (e) { out.preloaded = 'still kills'; }\n"
    . "// Case B: twemoji loads AFTER us (async loader) — the setter must disarm it\n"
    . "global.window = {};\n"
    . "neutralizeTwemoji();\n"
    . "window.twemoji = { parse: (n) => { throw new Error('EMOJI KILLED'); } };\n"
    . "try { window.twemoji.parse('🎬 node'); out.late = 'inert'; } catch (e) { out.late = 'still kills'; }\n"
    . "// Case C: parse still RETURNS its input (twemoji.parse(string) callers keep working)\n"
    . "out.passthrough = window.twemoji.parse('🎬 stays') === '🎬 stays';\n"
    . "console.log(JSON.stringify(out));\n";
$tmp = tempnam(sys_get_temp_dir(), 'twe') . '.cjs';
file_put_contents($tmp, $js);
$out = json_decode((string) shell_exec('node ' . escapeshellarg($tmp) . ' 2>&1'), true);
@unlink($tmp);
check('a twemoji that was ALREADY on the page is disarmed', ($out['preloaded'] ?? '') === 'inert', $out);
check('a twemoji that loads LATER is disarmed the moment it is assigned', ($out['late'] ?? '') === 'inert', $out);
check('the no-op passes its input through (string callers unaffected)', ($out['passthrough'] ?? false) === true, $out);

echo "\n3. Both Tiptap editors restore twemoji images on paste — EXECUTED\n";
foreach (array('shared' => $ed1, 'copy' => $ed2) as $name => $src) {
    check("$name editor: transformPastedHTML wired to restoreEmojiImages", strpos($src, 'transformPastedHTML: restoreEmojiImages,') !== false);
}
$start = strpos($ed1, 'function restoreEmojiImages');
$end   = strpos($ed1, 'function plainTextToHtml', (int) $start);
$fn    = substr($ed1, (int) $start, (int) $end - (int) $start);
$fn    = preg_replace('/\(html: string\): string/', '(html)', $fn);
$js = $fn . "\n"
    . "const out = {};\n"
    . "out.restored = restoreEmojiImages('<p>Great film <img class=\"emoji\" draggable=\"false\" alt=\"🎬\" src=\"https://s.w.org/images/core/emoji/1f3ac.svg\"> today</p>');\n"
    . "out.attrOrder = restoreEmojiImages('<p><img alt=\"📊\" class=\"wp-smiley emoji\" src=\"x.svg\"></p>');\n"
    . "out.realImg = restoreEmojiImages('<p><img class=\"pcm-photo\" alt=\"team photo\" src=\"team.jpg\"></p>');\n"
    . "out.noImg = restoreEmojiImages('<p>plain 🎬 text</p>');\n"
    . "console.log(JSON.stringify(out));\n";
$tmp = tempnam(sys_get_temp_dir(), 'emj') . '.cjs';
file_put_contents($tmp, $js);
$out = json_decode((string) shell_exec('node ' . escapeshellarg($tmp) . ' 2>&1'), true);
@unlink($tmp);
check('a twemoji <img class="emoji" alt="🎬"> becomes the character again', ($out['restored'] ?? '') === '<p>Great film 🎬 today</p>', $out);
check('attribute order does not matter (alt before class, wp-smiley variant)', ($out['attrOrder'] ?? '') === '<p>📊</p>', $out);
check('a REAL image (no emoji class) is left alone', ($out['realImg'] ?? '') === '<p><img class="pcm-photo" alt="team photo" src="team.jpg"></p>', $out);
check('emoji already text pass through untouched', ($out['noImg'] ?? '') === '<p>plain 🎬 text</p>', $out);
check('both editors carry their own copy of the restore (no cross-module import)', strpos($ed2, 'function restoreEmojiImages') !== false);

echo "\n4. The already-verified server chain is still intact (no regression while fixing the client)\n";
$actl = file_get_contents($ROOT . '/includes/modules/approvals/controller.php');
$asvc = file_get_contents($ROOT . '/includes/modules/approvals/service.php');
check('controller keeps the raw-body fallback for WAF-stripped 4-byte UTF-8', strpos($actl, 'decode_numeric_entities') !== false);
check('service still sanitizes body via sanitize_textarea_field (verified emoji-safe)', strpos($asvc, 'sanitize_textarea_field') !== false);

echo "\n5. Safari round — THE EMOJI LOSS GUARD (mechanism-independent, both ends EXECUTED)\n";
// The PHP guard, sliced out of the service and run for real.
$gs = strpos($asvc, "    private const EMOJI_CHARS");
$ge = strpos($asvc, "    public static function update_snapshot_asset", (int) $gs);
$guard = substr($asvc, (int) $gs, (int) $ge - (int) $gs);
$guard = str_replace('private const', 'const', $guard);
$guard = str_replace('self::EMOJI_CHARS', 'GuardHost::EMOJI_CHARS', $guard);
eval('class GuardHost { ' . $guard . ' }');
$stored = "Njut av fördelarna 🥛✨ och handla nu";
check('pure emoji loss → the stored value is kept (the Safari repro)', GuardHost::keep_emoji_on_pure_loss('Njut av fördelarna  och handla nu', $stored) === $stored);
check('a ZWJ family + skin tone wiped → kept too', GuardHost::keep_emoji_on_pure_loss('Great team ', 'Great team 👨‍👩‍👧🏽') === 'Great team 👨‍👩‍👧🏽');
check('a REAL edit (words changed) passes through, emoji count irrelevant', GuardHost::keep_emoji_on_pure_loss('Njut av alla fördelar och handla nu', $stored) === 'Njut av alla fördelar och handla nu');
check('emoji deleted TOGETHER with other text passes through', GuardHost::keep_emoji_on_pure_loss('Njut av fördelarna och handla', $stored) === 'Njut av fördelarna och handla');
check('same emoji, reordered/replaced (counts equal) passes through', GuardHost::keep_emoji_on_pure_loss('Njut av fördelarna ✨🥛 och handla nu', $stored) === 'Njut av fördelarna ✨🥛 och handla nu');
check('ADDING an emoji passes through', GuardHost::keep_emoji_on_pure_loss($stored . ' 🎉', $stored) === $stored . ' 🎉');
check('empty stored / identical input are untouched fast paths', GuardHost::keep_emoji_on_pure_loss('x', '') === 'x' && GuardHost::keep_emoji_on_pure_loss($stored, $stored) === $stored);
check('the guard is applied to all three copy fields on write (after sanitize, against the stored value)', substr_count($asvc, 'self::keep_emoji_on_pure_loss(sanitize_textarea_field($updates[') === 2 && strpos($asvc, "self::keep_emoji_on_pure_loss(sanitize_text_field(\$updates['headline']), (string) (\$item['headline'] ?? ''))") !== false);

// The client guard, executed via node.
$tsg = file_get_contents($ROOT . '/app/src/lib/emojiGuard.ts');
$card = file_get_contents($ROOT . '/app/src/modules/Approvals/components/CreativeAssetCard.tsx');
$fn = str_replace('export function', 'function', $tsg);
$fn = preg_replace('/\(s: string\): string/', '(s)', $fn);
$fn = preg_replace('/\(s: string\): number/', '(s)', $fn);
$fn = preg_replace('/\(next: string, prev: string\): string/', '(next, prev)', $fn);
$js = $fn . "\n"
    . "const out = {};\n"
    . "const stored = 'Njut av f\\u00f6rdelarna \\u{1F95B}\\u2728 och handla nu';\n"
    . "out.pureLoss = keepEmojiOnPureLoss('Njut av f\\u00f6rdelarna  och handla nu', stored) === stored;\n"
    . "out.realEdit = keepEmojiOnPureLoss('Njut av alla f\\u00f6rdelar', stored) === 'Njut av alla f\\u00f6rdelar';\n"
    . "out.added = keepEmojiOnPureLoss(stored + ' \\u{1F389}', stored) === stored + ' \\u{1F389}';\n"
    . "out.addedTight = keepEmojiOnPureLoss(stored + '\\u{1F389}', stored) === stored + '\\u{1F389}';\n"
    . "console.log(JSON.stringify(out));\n";
$tmp = tempnam(sys_get_temp_dir(), 'egd') . '.cjs';
file_put_contents($tmp, $js);
$out = json_decode((string) shell_exec('node ' . escapeshellarg($tmp) . ' 2>&1'), true);
@unlink($tmp);
check('client guard: pure loss restored, real edits + additions pass (node)', ($out['pureLoss'] ?? false) === true && ($out['realEdit'] ?? false) === true && ($out['added'] ?? false) === true && ($out['addedTight'] ?? false) === true, $out);
check('the card guards all three fields BEFORE the dirty check and SENDS the guarded values', strpos($card, "const finalBody = keepEmojiOnPureLoss(editedBody, asset.body || '');") !== false && strpos($card, 'body: escapeAstral(finalBody),') !== false && strpos($card, 'headline: escapeAstral(finalHeadline),') !== false && strpos($card, 'description: escapeAstral(finalDescription)') !== false && strpos($card, "finalBody === (asset.body || '')") !== false);
check('…and puts the restored emoji back on screen', strpos($card, 'setEditedBody(finalBody);') !== false);

echo "\n" . str_repeat('-', 60) . "\n";
echo "  passed: {$PASS}   failed: {$FAIL}\n";
exit($FAIL > 0 ? 1 : 0);
