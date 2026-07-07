<?php
/**
 * Deliveries Service — Business Logic Layer
 *
 * Thin domain layer for the Deliveries module. CRUD lives in PCM_DB
 * (see the `DELIVERIES` section there); this class owns formatting,
 * validation, and any future business rules that don't fit the
 * generic CRUD shape.
 *
 * Mirrors the structure of PCM_Brands_Service intentionally — adding
 * new modules to the platform should always feel familiar.
 *
 * @package PowerCreatives
 * @since   1.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Deliveries_Service
{

    /**
     * Allowed delivery statuses. Source of truth for both server-side
     * validation and the Kanban column declaration. Keep in sync with
     * `app/src/modules/Deliveries/types.ts → DELIVERY_STATUSES`.
     *
     * @var string[]
     */
    /**
     * Module ids (frontend nav ids) a delivery can grant to its assignees.
     * 'ads' implies the copy+image backends (Ads is a frontend over both) —
     * see the controllers' $module_grant_keys.
     */
    public const GRANTABLE_MODULES = ['copy', 'image', 'video', 'writer', 'keywords', 'strategies', 'sites', 'ads'];

    /**
     * Central delivery-type → module-preset mapping. Picking a type when
     * creating/editing a delivery pre-fills its `modules` grants. THIS is the
     * single place to extend as new types/modules are added; module ids must
     * stay within GRANTABLE_MODULES. Filterable for site-specific overrides.
     */
    public const TYPE_PRESETS = [
        'seo'        => ['label' => 'SEO',        'modules' => ['writer', 'keywords', 'strategies', 'sites']],
        'google_ads' => ['label' => 'Google Ads', 'modules' => ['ads', 'copy', 'image', 'keywords']],
        'meta_ads'   => ['label' => 'Meta Ads',   'modules' => ['ads', 'copy', 'image']],
    ];

    /** PCM_Settings key holding the admin-customized presets (Settings UI). */
    public const TYPE_PRESETS_SETTING = 'delivery_type_presets';

    /**
     * Resolved type presets: the admin-customized setting when present and
     * valid, else the built-in TYPE_PRESETS. Stored values are normalized on
     * read (keys sanitized, labels text-sanitized, modules whitelisted) so a
     * bad/legacy option blob can never reach SQL or the UI raw. Filterable
     * via 'pcm_delivery_type_presets'.
     *
     * @return array<string, array{label: string, modules: string[]}>
     */
    public static function type_presets(): array
    {
        $stored  = class_exists('PCM_Settings') ? PCM_Settings::get(self::TYPE_PRESETS_SETTING) : null;
        $presets = self::normalize_presets($stored);
        if (empty($presets)) {
            $presets = self::TYPE_PRESETS;
        }
        $filtered = apply_filters('pcm_delivery_type_presets', $presets);
        return is_array($filtered) ? $filtered : $presets;
    }

    /**
     * Normalize a raw presets blob into the canonical shape. Entries with an
     * empty key or label are dropped; module lists are whitelist-filtered.
     *
     * @param mixed $raw Stored setting value.
     * @return array<string, array{label: string, modules: string[]}>
     */
    public static function normalize_presets(mixed $raw): array
    {
        if (!is_array($raw)) {
            return array();
        }
        $clean = array();
        foreach ($raw as $key => $entry) {
            $key = sanitize_key((string) $key);
            if ($key === '' || !is_array($entry)) {
                continue;
            }
            $label = sanitize_text_field((string) ($entry['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $clean[$key] = array(
                'label'   => $label,
                'modules' => self::sanitize_modules($entry['modules'] ?? array()),
            );
        }
        return $clean;
    }

    /**
     * Validate a delivery type against the preset keys.
     *
     * @param mixed $type Candidate type value.
     * @return string|null Validated type key or null.
     */
    public static function sanitize_type(mixed $type): ?string
    {
        if (!is_string($type) || $type === '') {
            return null;
        }
        $type = sanitize_key($type);
        return array_key_exists($type, self::type_presets()) ? $type : null;
    }

    public const STATUSES = ['active', 'paused', 'completed'];

    /**
     * Default status for newly created deliveries.
     *
     * @var string
     */
    public const DEFAULT_STATUS = 'active';

    /**
     * Format a delivery DB row for JSON API output.
     *
     * Coerces numeric columns to PHP ints so the JSON payload contains
     * numbers, not the strings WordPress's wpdb returns for BIGINT
     * columns. The frontend's `useDeliveries` hook still defensively
     * re-normalizes via Number(), but this keeps the wire format honest.
     *
     * @param object $row Raw DB row.
     * @return array Formatted delivery data.
     */
    public function format_delivery(object $row): array
    {
        return array(
            'id'         => (int) $row->id,
            'userId'     => (int) $row->userId,
            'name'       => $row->name,
            'clientName' => $row->clientName ?? null,
            'status'     => $row->status,
            'type'       => isset($row->type) && $row->type !== null && $row->type !== '' ? (string) $row->type : null,
            'brandId'    => isset($row->brandId) && $row->brandId !== null ? (int) $row->brandId : null,
            'projectId'  => isset($row->projectId) && $row->projectId !== null ? (int) $row->projectId : null,
            'modules'    => self::decode_modules($row->modules ?? null),
            'externalId' => $row->externalId ?? '',
            'createdAt'  => $row->createdAt,
            'updatedAt'  => $row->updatedAt,
        );
    }

    /**
     * Whitelist-filter a modules payload against GRANTABLE_MODULES.
     *
     * @param mixed $modules Raw request value.
     * @return string[] Clean module ids (deduped, order preserved).
     */
    public static function sanitize_modules(mixed $modules): array
    {
        if (!is_array($modules)) {
            return array();
        }
        $clean = array();
        foreach ($modules as $module) {
            $module = sanitize_text_field((string) $module);
            if (in_array($module, self::GRANTABLE_MODULES, true) && !in_array($module, $clean, true)) {
                $clean[] = $module;
            }
        }
        return $clean;
    }

    /**
     * Decode the stored modules JSON into a clean array.
     *
     * @param string|null $blob Stored JSON.
     * @return string[]
     */
    private static function decode_modules(?string $blob): array
    {
        if (empty($blob)) {
            return array();
        }
        $list = json_decode((string) $blob, true);
        return is_array($list) ? array_values(array_map('strval', $list)) : array();
    }

    /**
     * Validate a status string against the allowed set.
     *
     * Returns the status unchanged if valid, or null if not. Controllers
     * use this to reject bad input with a 400 before touching the DB.
     *
     * @param mixed $status Candidate status value.
     * @return string|null Validated status or null.
     */
    public function validate_status(mixed $status): ?string
    {
        if (!is_string($status)) {
            return null;
        }
        return in_array($status, self::STATUSES, true) ? $status : null;
    }
}
