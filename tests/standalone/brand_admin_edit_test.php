<?php
/**
 * Brand write permission — standalone harness.
 *
 * Guards the fix for "You can view this brand but not edit it." shown to an ADMIN
 * using the SPA on the public shortcode page while wp-admin worked fine.
 *
 * WHY THE TWO CONTEXTS DIVERGED — they are different PCM users:
 *   - wp-admin       → get_current_pcm_user() resolves the WP user (`wp_<ID>`), which
 *                      OWNS the brands created there, so the owner check passed.
 *   - shortcode page → the visitor is gate-authed, so the SAME person resolves to
 *                      their PLATFORM user row instead. base-controller.php lets a
 *                      platform admin through (`PCM_Access::is_admin` → return true)
 *                      and PCM_DB::get_brand_by_id() lets an admin READ any brand,
 *                      but update_item() then rejected every non-owner.
 *
 * The second half of the bug: PCM_DB::update_brand()'s WHERE is `id AND userId`, and
 * it reports success on $wpdb->update() returning 0. So even once an admin is allowed
 * through, writing under the ADMIN's id matches no row and the API answers 200/201
 * having saved nothing. Hence writable_owner_id() returns the OWNER's id, never the
 * caller's — that is the assertion that actually matters below.
 *
 * Reflection is used to call the real private method, so this tests the shipped
 * decision rather than a copy of it.
 *
 * Run: php tests/standalone/brand_admin_edit_test.php
 */

error_reporting(E_ALL & ~E_DEPRECATED);
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }

// ── Stubs: only what constructing the controller touches ────────────────────
class WP_Error {
    public $code, $message, $data;
    public function __construct($c = '', $m = '', $d = array()) { $this->code = $c; $this->message = $m; $this->data = $d; }
}
class WP_REST_Request {}
class WP_REST_Response {}

abstract class PCM_REST_Base {
    protected string $default_capability = 'manage_options';
    protected function error(string $m, int $s = 400, string $c = 'pcm_error') { return new WP_Error($c, $m, array('status' => $s)); }
    protected function not_found(string $r = 'Resource') { return $this->error("$r not found.", 404); }
    protected function success($d, int $s = 200) { return $d; }
    protected function get_current_pcm_user(): object { return (object) array('id' => 0); }
}
class PCM_Brands_Service { }
class PCM_DB { }
class PCM_Schema { }

/** Admin membership, keyed by PCM user id. */
class PCM_Access {
    public static array $admins = array();
    public static function is_admin(int $id): bool { return in_array($id, self::$admins, true); }
}

require_once dirname(__DIR__, 2) . '/includes/modules/brands/controller.php';

$PASS = 0; $FAIL = 0;
function check(string $name, $ok, $got = null): void {
    global $PASS, $FAIL;
    if ($ok) { $PASS++; echo "  ok  $name\n"; }
    else { $FAIL++; echo "FAIL  $name\n      got: " . var_export($got, true) . "\n"; }
}

$ctrl = new PCM_REST_Brands();
$m = new ReflectionMethod('PCM_REST_Brands', 'writable_owner_id');
$m->setAccessible(true);
$decide = fn($brand, $user) => $m->invoke($ctrl, $brand, $user);

$OWNER = 7;     // the WP user who created the brands from wp-admin
$ADMIN = 42;    // the same person's PLATFORM user, role='admin', on the front end
$GUEST = 99;    // a delivery grantee — view + use, must NOT gain edit

PCM_Access::$admins = array($ADMIN);
$brand = (object) array('id' => 3, 'userId' => $OWNER);

echo "\n1. The owner can still write their own brand\n";
$r = $decide($brand, (object) array('id' => $OWNER));
check('owner allowed', $r === $OWNER, $r);

echo "\n2. An admin can now write someone else's brand (the reported bug)\n";
$r = $decide($brand, (object) array('id' => $ADMIN));
check('admin allowed', $r !== null, $r);
// THE load-bearing assertion. Returning the admin's own id would make
// PCM_DB::update_brand()'s `WHERE id AND userId` match zero rows, and that method
// returns true whenever $wpdb->update() !== false — a silent 200 that saves nothing.
check('writes as the OWNER, not as the admin', $r === $OWNER, $r);

echo "\n3. A delivery grantee still cannot write (no privilege escalation)\n";
// get_brand_by_id() hands this user the brand for view + use. That must not become
// edit rights just because the admin path was opened up.
$r = $decide($brand, (object) array('id' => $GUEST));
check('grantee refused', $r === null, $r);

echo "\n4. Ownership is compared by VALUE, not identity\n";
// PCM user ids arrive as strings from some callers; a === on mixed types would
// lock the real owner out of their own brand.
$r = $decide((object) array('id' => 3, 'userId' => '7'), (object) array('id' => 7));
check('string owner id matches int caller', $r === $OWNER, $r);
$r = $decide((object) array('id' => 3, 'userId' => 7), (object) array('id' => '7'));
check('int owner id matches string caller', $r === $OWNER, $r);

echo "\n5. Degenerate rows do not hand out access\n";
$r = $decide((object) array('id' => 3), (object) array('id' => $GUEST));
check('missing userId refused for non-admin', $r === null, $r);
$r = $decide((object) array('id' => 3, 'userId' => 0), (object) array('id' => $GUEST));
check('userId 0 refused for non-admin', $r === null, $r);
// A logged-out/unresolved caller is id 0. It must not match a userId-0 row.
$r = $decide((object) array('id' => 3, 'userId' => 0), (object) array('id' => 0));
check('caller id 0 cannot claim a userId-0 brand', $r === null, $r);

echo "\n6. Admin status is read live, not assumed\n";
PCM_Access::$admins = array();
$r = $decide($brand, (object) array('id' => $ADMIN));
check('demoted admin loses write access', $r === null, $r);
PCM_Access::$admins = array($ADMIN);

echo "\n7. The refusal is a 403 with the message the user saw\n";
$rm = new ReflectionMethod('PCM_REST_Brands', 'brand_read_only');
$rm->setAccessible(true);
$err = $rm->invoke($ctrl);
check('message unchanged', $err->message === 'You can view this brand but not edit it.', $err->message);
check('status 403', ($err->data['status'] ?? null) === 403, $err->data ?? null);
check('code pcm_forbidden', $err->code === 'pcm_forbidden', $err->code);

echo "\n8. Every brand-mutating handler is gated (no silent-success path left)\n";
// A handler that writes but never calls writable_owner_id() would still no-op behind
// a 200 for an admin. Compare gate count against write-scope usage in the source.
$src = file_get_contents(dirname(__DIR__, 2) . '/includes/modules/brands/controller.php');
$gates  = substr_count($src, '$owner_id = $this->writable_owner_id(');
$refus  = substr_count($src, 'return $this->brand_read_only();');
$writes = preg_match_all('/\$this->service->(upload_asset|add_asset_from_url|fetch_and_store_website_assets|remove_asset|reorder_assets|set_asset_as_logo)\(|PCM_DB::update_brand\(/', $src);
check('a gate for every write path', $gates === 8, "gates=$gates writes=$writes");
check('a refusal for every gate', $refus === $gates, "refusals=$refus gates=$gates");
check('no write still scoped to the caller',
    !preg_match('/(upload_asset|add_asset_from_url|fetch_and_store_website_assets|remove_asset|reorder_assets|set_asset_as_logo)\([^)]*\$user->id|PCM_DB::update_brand\([^,]+,\s*\$user->id/', $src));

echo "\n" . str_repeat('─', 52) . "\n";
echo "  passed: $PASS   failed: $FAIL\n";
exit($FAIL > 0 ? 1 : 0);
