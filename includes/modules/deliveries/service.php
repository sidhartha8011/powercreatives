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
            'createdAt'  => $row->createdAt,
            'updatedAt'  => $row->updatedAt,
        );
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
