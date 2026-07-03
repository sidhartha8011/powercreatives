<?php
/**
 * Users Service — WP-user mirroring + delivery assignments.
 *
 * @package PowerCreatives
 * @since   1.18.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Users_Service
{
    /**
     * Every WordPress user, mirrored into wp_pcm_users (rows created on
     * demand so users are assignable before their first login), with the
     * live plugin access level and current delivery assignments.
     *
     * @return array[]
     */
    public function list_users(): array
    {
        $wp_users = get_users(array('orderby' => 'display_name', 'order' => 'ASC'));

        // Mirror: make sure a pcm row exists for every WP user, and collect ids.
        $items = array();
        foreach ($wp_users as $wp_user) {
            $open_id  = 'wp_' . $wp_user->ID;
            $pcm_user = PCM_DB::get_user_by_open_id($open_id);
            if (!$pcm_user) {
                PCM_DB::upsert_user(array(
                    'openId'    => $open_id,
                    'name'      => $wp_user->display_name,
                    'email'     => $wp_user->user_email,
                    'role'      => user_can($wp_user, 'manage_options') ? 'admin' : 'user',
                    'avatarUrl' => get_avatar_url($wp_user->ID),
                ));
                $pcm_user = PCM_DB::get_user_by_open_id($open_id);
                // Seed defaults exactly like get_current_pcm_user() does for
                // self-created rows — without this, mirrored users have NO
                // automation rules (their approval sets never produce
                // notifications) and no default prompts.
                if ($pcm_user) {
                    $this->seed_defaults((int) $pcm_user->id);
                }
            }
            if (!$pcm_user) {
                continue; // degenerate: insert failed — skip rather than fatal.
            }

            $items[] = array(
                'pcmId'          => (int) $pcm_user->id,
                'wpUserId'       => (int) $wp_user->ID,
                'name'           => $wp_user->display_name,
                'email'          => $wp_user->user_email,
                'avatarUrl'      => get_avatar_url($wp_user->ID),
                // LIVE level — always mirrors current WP capabilities.
                'role'           => user_can($wp_user, 'manage_options') ? 'admin' : 'user',
                'isPlatformUser' => false,
                'username'       => null,
            );
        }

        // Platform users — created in this module (username + password login,
        // no WordPress account). These are the accounts a visitor uses at the
        // [power_creatives] shortcode gate.
        foreach ($this->platform_users() as $pu) {
            $items[] = array(
                'pcmId'          => (int) $pu->id,
                'wpUserId'       => 0,
                'name'           => (string) $pu->name,
                'email'          => (string) $pu->email,
                'avatarUrl'      => '',
                'role'           => in_array($pu->role, array('admin', 'user'), true) ? (string) $pu->role : 'user',
                'isPlatformUser' => true,
                'username'       => (string) $pu->username,
            );
        }

        // Attach assignments in one query.
        $by_user = $this->assignments_for_users(array_column($items, 'pcmId'));
        foreach ($items as &$item) {
            $item['assignedDeliveryIds'] = $by_user[$item['pcmId']] ?? array();
        }
        unset($item);

        return $items;
    }

    /**
     * Create a platform-native login user (username + password, no WP account).
     * Seeds default prompts + automation rules just like a mirrored WP user, so
     * the new user has a working workspace from first login.
     *
     * @param array $data { username, password, name?, email?, role? }
     * @return array|WP_Error The created user's list row, or a validation error.
     */
    public function create_user(array $data): array|WP_Error
    {
        $username = strtolower(trim((string) ($data['username'] ?? '')));
        $password = (string) ($data['password'] ?? '');
        $name     = sanitize_text_field((string) ($data['name'] ?? ''));
        $email    = trim((string) ($data['email'] ?? ''));
        // Access level, set deliberately by the admin creating the account.
        // 'admin' = a co-admin with workspace-wide oversight (review + manage other
        // users + assign deliveries); 'user' = a self-scoped workspace user. Only
        // the admin-gated Users routes reach this, so a normal user can never set it.
        $role     = in_array(($data['role'] ?? 'user'), array('admin', 'user'), true) ? (string) $data['role'] : 'user';

        // Username: 3–191 chars, lowercase letters/digits/._- only (a stable,
        // URL-safe handle — also keeps the derived openId 'pcm_local_<username>' clean).
        if (!preg_match('/^[a-z0-9._-]{3,191}$/', $username)) {
            return new WP_Error('pcm_invalid_username', __('Username must be 3–191 characters: lowercase letters, digits, dot, underscore or hyphen.', 'power-creatives'), array('status' => 400));
        }
        if (strlen($password) < 8 || strlen($password) > 256) {
            return new WP_Error('pcm_invalid_password', __('Password must be 8–256 characters.', 'power-creatives'), array('status' => 400));
        }
        if ($email !== '' && !is_email($email)) {
            return new WP_Error('pcm_invalid_email', __('Enter a valid email address, or leave it blank.', 'power-creatives'), array('status' => 400));
        }
        if (PCM_DB::get_user_by_username($username)) {
            return new WP_Error('pcm_username_taken', __('That username is already taken.', 'power-creatives'), array('status' => 409));
        }

        $id = PCM_DB::create_platform_user(array(
            'username' => $username,
            'password' => $password,
            'name'     => $name !== '' ? $name : $username,
            'email'    => $email,
            'role'     => $role,
        ));
        if (!$id) {
            return new WP_Error('pcm_create_failed', __('Could not create the user. The username may already be taken.', 'power-creatives'), array('status' => 500));
        }

        $this->seed_defaults($id);

        return array(
            'pcmId'          => $id,
            'wpUserId'       => 0,
            'name'           => $name !== '' ? $name : $username,
            'email'          => $email,
            'avatarUrl'      => '',
            'role'           => $role,
            'isPlatformUser' => true,
            'username'       => $username,
            'assignedDeliveryIds' => array(),
        );
    }

    /**
     * Reset a platform user's password. WP-mirrored users are rejected — they
     * authenticate through WordPress, not through this module.
     *
     * @param int    $pcm_id   Target PCM user id.
     * @param string $password New plain password (8–256 chars).
     * @return true|WP_Error
     */
    public function set_password(int $pcm_id, string $password): bool|WP_Error
    {
        if (strlen($password) < 8 || strlen($password) > 256) {
            return new WP_Error('pcm_invalid_password', __('Password must be 8–256 characters.', 'power-creatives'), array('status' => 400));
        }
        $user = PCM_DB::get_user_by_id($pcm_id);
        if (!$user || empty($user->username)) {
            return new WP_Error('pcm_not_platform_user', __('Only platform users (username + password) can have their password reset here.', 'power-creatives'), array('status' => 404));
        }
        if (!PCM_DB::set_user_password($pcm_id, $password)) {
            return new WP_Error('pcm_password_failed', __('Could not update the password.', 'power-creatives'), array('status' => 500));
        }
        return true;
    }

    /**
     * Change a platform user's access level ('admin' | 'user'). WP-mirrored users
     * are rejected — their role mirrors their live WordPress capability and is
     * managed in WordPress, not here.
     *
     * @param int    $pcm_id Target PCM user id.
     * @param string $role   'admin' or 'user'.
     * @return string|WP_Error The applied role, or an error.
     */
    public function set_role(int $pcm_id, string $role): string|WP_Error
    {
        $role = in_array($role, array('admin', 'user'), true) ? $role : '';
        if ($role === '') {
            return new WP_Error('pcm_invalid_role', __('Role must be “admin” or “user”.', 'power-creatives'), array('status' => 400));
        }
        $user = PCM_DB::get_user_by_id($pcm_id);
        if (!$user || empty($user->username)) {
            return new WP_Error('pcm_not_platform_user', __('Only platform users’ roles can be changed here.', 'power-creatives'), array('status' => 404));
        }
        global $wpdb;
        $table = PCM_Schema::table('users');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $ok = $wpdb->update(
            $table,
            array('role' => $role, 'updatedAt' => current_time('mysql')),
            array('id' => $pcm_id),
            array('%s', '%s'),
            array('%d')
        );
        if ($ok === false) {
            return new WP_Error('pcm_role_failed', __('Could not update the role.', 'power-creatives'), array('status' => 500));
        }
        if (class_exists('PCM_Access')) {
            PCM_Access::reset_memo(); // the is_admin memo may be stale for this user
        }
        return $role;
    }

    /**
     * Delete a platform user. WP-mirrored users are rejected (they belong to
     * WordPress, not this module). Their delivery assignments are removed too.
     *
     * @param int $pcm_id Target PCM user id.
     * @return true|WP_Error
     */
    public function delete_user(int $pcm_id): bool|WP_Error
    {
        global $wpdb;
        $user = PCM_DB::get_user_by_id($pcm_id);
        if (!$user || empty($user->username)) {
            return new WP_Error('pcm_not_platform_user', __('Only platform users can be deleted here.', 'power-creatives'), array('status' => 404));
        }

        // Remove any delivery assignments pointing at this user, then the row.
        $assignments_table = PCM_Schema::table('delivery_assignments');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete($assignments_table, array('userId' => $pcm_id), array('%d'));

        $users_table = PCM_Schema::table('users');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $deleted = $wpdb->delete($users_table, array('id' => $pcm_id), array('%d'));
        if (!$deleted) {
            return new WP_Error('pcm_delete_failed', __('Could not delete the user.', 'power-creatives'), array('status' => 500));
        }
        return true;
    }

    /** Seed default prompts + automation rules for a freshly created PCM user. */
    private function seed_defaults(int $pcm_id): void
    {
        if (class_exists('PCM_Prompt_Seeds')) {
            PCM_Prompt_Seeds::seed_for_user($pcm_id);
        }
        if (class_exists('PCM_Automation_Seeds')) {
            PCM_Automation_Seeds::seed_for_user($pcm_id);
        }
    }

    /**
     * All platform users (username + password logins created in this module).
     *
     * @return object[]
     */
    private function platform_users(): array
    {
        global $wpdb;
        $table = PCM_Schema::table('users');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $rows = $wpdb->get_results(
            "SELECT id, username, name, email, role FROM {$table} WHERE username IS NOT NULL ORDER BY username ASC"
        );
        return $rows ?: array();
    }

    /**
     * Replace a user's delivery assignment set. Every delivery must belong to
     * the calling admin (ownership check) — you can only hand out access to
     * your own deliveries.
     *
     * @param int   $assignee_id  PCM user id receiving access.
     * @param int[] $delivery_ids Deliveries to assign (validated, deduped).
     * @param int   $admin_id     Calling admin's PCM user id.
     * @return int[]|WP_Error Final assigned ids, or error.
     */
    public function set_assignments(int $assignee_id, array $delivery_ids, int $admin_id): array|WP_Error
    {
        global $wpdb;

        // Assignee must exist.
        $users_table = PCM_Schema::table('users');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $assignee = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$users_table} WHERE id = %d",
            $assignee_id
        ));
        if (!$assignee) {
            return new WP_Error('pcm_user_not_found', __('User not found.', 'power-creatives'), array('status' => 404));
        }

        // A workspace admin (WP admin or platform admin) may assign ANY existing
        // delivery and manage the assignee's FULL assignment set. A non-admin owner
        // is limited to their own deliveries (legacy per-owner scoping).
        $is_admin         = class_exists('PCM_Access') && PCM_Access::is_admin($admin_id);
        $deliveries_table = PCM_Schema::table('deliveries');
        if (!empty($delivery_ids)) {
            $placeholders = implode(',', array_fill(0, count($delivery_ids), '%d'));
            if ($is_admin) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
                $valid = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$deliveries_table} WHERE id IN ({$placeholders})",
                    ...$delivery_ids
                ));
            } else {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
                $valid = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$deliveries_table} WHERE userId = %d AND id IN ({$placeholders})",
                    $admin_id,
                    ...$delivery_ids
                ));
            }
            if (count($valid) !== count($delivery_ids)) {
                return new WP_Error(
                    'pcm_delivery_not_owned',
                    __('One or more deliveries do not exist or are not yours to assign.', 'power-creatives'),
                    array('status' => 403)
                );
            }
        }

        // Reconcile against the assignee's current set: for an admin that's EVERY
        // assignment (full management); for a non-admin, only their own deliveries'
        // assignments (leaving other admins' assignments untouched).
        $assignments_table = PCM_Schema::table('delivery_assignments');
        if ($is_admin) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $current = $wpdb->get_col($wpdb->prepare(
                "SELECT deliveryId FROM {$assignments_table} WHERE userId = %d",
                $assignee_id
            ));
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $current = $wpdb->get_col($wpdb->prepare(
                "SELECT a.deliveryId
                 FROM {$assignments_table} a
                 INNER JOIN {$deliveries_table} d ON d.id = a.deliveryId
                 WHERE a.userId = %d AND d.userId = %d",
                $assignee_id,
                $admin_id
            ));
        }
        $current = array_map('intval', $current ?: array());

        foreach (array_diff($current, $delivery_ids) as $remove_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->delete(
                $assignments_table,
                array('deliveryId' => $remove_id, 'userId' => $assignee_id),
                array('%d', '%d')
            );
        }
        foreach (array_diff($delivery_ids, $current) as $add_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $assignments_table,
                array(
                    'deliveryId' => $add_id,
                    'userId'     => $assignee_id,
                    'assignedBy' => $admin_id,
                    'createdAt'  => current_time('mysql'),
                ),
                array('%d', '%d', '%d', '%s')
            );
        }

        // The assignee's visible set changed — bust their cached lists
        // (transient-backed, so they'd otherwise serve stale data until TTL)
        // and the per-request grant memo (defensive: a later read in this same
        // request would otherwise see the pre-write grant set).
        PCM_DB::invalidate('deliveries', $assignee_id);
        PCM_DB::invalidate('brands', $assignee_id);
        if (class_exists('PCM_Access')) {
            PCM_Access::reset_memo();
        }

        return $delivery_ids;
    }

    /**
     * Assigned delivery ids per user, one query.
     *
     * @param int[] $pcm_ids PCM user ids.
     * @return array<int, int[]>
     */
    private function assignments_for_users(array $pcm_ids): array
    {
        if (empty($pcm_ids)) {
            return array();
        }
        global $wpdb;
        $table = PCM_Schema::table('delivery_assignments');
        $placeholders = implode(',', array_fill(0, count($pcm_ids), '%d'));
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT userId, deliveryId FROM {$table} WHERE userId IN ({$placeholders})",
            ...array_map('intval', $pcm_ids)
        ));

        $by_user = array();
        foreach (($rows ?: array()) as $row) {
            $by_user[(int) $row->userId][] = (int) $row->deliveryId;
        }
        return $by_user;
    }
}
