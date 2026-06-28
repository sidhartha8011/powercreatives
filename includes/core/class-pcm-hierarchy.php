<?php
/**
 * PCM_Hierarchy — single source of truth for the Brand → Delivery → Project chain.
 *
 * Canonical hierarchy:
 *   Brand → (has many) Delivery → (has many) Project → (has many) things (e.g. approval sets).
 *
 * Storage: a Project belongs to a Delivery (`projects.deliveryId`); a Delivery belongs to a
 * Brand (`deliveries.brandId`). Anything attached to a Project derives its delivery + brand
 * LIVE from this chain via the helpers below — it must NOT store/hardcode brandId/deliveryId.
 * So when a project is reassigned to another delivery (or that delivery to another brand),
 * everything that belongs to the project follows automatically.
 *
 * This is platform-wide logic on purpose: every module resolves up the chain through here,
 * rather than each owning its own brand/delivery copy.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Hierarchy
{
    /**
     * Resolve a project's full chain — delivery + brand derived live from the project's
     * CURRENT delivery (and that delivery's brand). Missing links resolve to null.
     *
     * @return array{projectId:?int,deliveryId:?int,brandId:?int}
     */
    public static function for_project(?int $project_id): array
    {
        $out = array('projectId' => $project_id ?: null, 'deliveryId' => null, 'brandId' => null);
        if (!$project_id) {
            return $out;
        }

        global $wpdb;
        $projects   = PCM_Schema::table('projects');
        $deliveries = PCM_Schema::table('deliveries');

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $delivery_id = $wpdb->get_var($wpdb->prepare("SELECT deliveryId FROM {$projects} WHERE id = %d", $project_id));
        if (!$delivery_id) {
            return $out;
        }
        $out['deliveryId'] = (int) $delivery_id;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $brand_id = $wpdb->get_var($wpdb->prepare("SELECT brandId FROM {$deliveries} WHERE id = %d", (int) $delivery_id));
        if ($brand_id) {
            $out['brandId'] = (int) $brand_id;
        }
        return $out;
    }

    /** The delivery a project currently belongs to, or null. */
    public static function delivery_for_project(?int $project_id): ?int
    {
        return self::for_project($project_id)['deliveryId'];
    }

    /** The brand a project currently belongs to (via its delivery), or null. */
    public static function brand_for_project(?int $project_id): ?int
    {
        return self::for_project($project_id)['brandId'];
    }
}
