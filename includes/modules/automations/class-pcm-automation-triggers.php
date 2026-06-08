<?php
/**
 * Automation Triggers Registry
 *
 * A trigger is the "IF" side of an automation rule: something that happens in a
 * module (e.g. an approval set changes lane). Modules register triggers here so
 * the Automations module can offer them in its UI and the engine can fire them.
 *
 * This is the extensibility seam for cross-module automations — today only the
 * Approvals "set status changed" trigger is wired, but any module can call
 * PCM_Automation_Triggers::register() to add its own.
 *
 * Each trigger is an array:
 *   id              string  unique id, namespaced by module ('approvals.set_status_changed')
 *   module          string  owning module id (for grouping in the UI)
 *   label           string  human label
 *   description     string  short help text
 *   contextKeys     array   keys the trigger provides in its dispatch context
 *   conditionFields array   UI fields the user can filter on; each:
 *                           { key, label, type, options?, default? }
 *
 * @package PowerCreatives
 * @since   1.15.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Automation_Triggers
{
    /** @var array<string, array> */
    private static array $triggers = array();

    /** @var bool */
    private static bool $booted = false;

    /**
     * Register (or override) a trigger.
     *
     * @param array $trigger Trigger definition (see class docblock).
     * @return void
     */
    public static function register(array $trigger): void
    {
        if (empty($trigger['id'])) {
            return;
        }
        self::$triggers[$trigger['id']] = $trigger;
    }

    /**
     * No built-ins are hardcoded here anymore. Triggers are registered by each
     * module in its own includes/modules/{id}/automations.php (auto-loaded by
     * PCM_Module_Loader::discover()), so adding a module's triggers is a
     * drop-in file with zero central edits.
     *
     * @return void
     */
    private static function boot(): void
    {
        self::$booted = true;
    }

    /**
     * All registered triggers (UI catalog).
     *
     * @return array<int, array>
     */
    public static function all(): array
    {
        self::boot();
        return array_values(self::$triggers);
    }

    /**
     * Get a trigger by id.
     *
     * @param string $id Trigger id.
     * @return array|null
     */
    public static function get(string $id): ?array
    {
        self::boot();
        return self::$triggers[$id] ?? null;
    }

    /**
     * Whether a trigger id is registered.
     *
     * @param string $id Trigger id.
     * @return bool
     */
    public static function is_valid(string $id): bool
    {
        self::boot();
        return isset(self::$triggers[$id]);
    }
}
