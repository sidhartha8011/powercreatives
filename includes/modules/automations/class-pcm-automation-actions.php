<?php
/**
 * Automation Actions Registry
 *
 * An action is the "THEN" side of an automation rule: something to do when a
 * trigger fires and its conditions match. Modules register actions here so the
 * Automations module can offer them in its UI and the engine can run them.
 *
 * This is the extensibility seam for cross-module actions — today only the
 * generic 'webhook' action is implemented, but future actions (e.g.
 * 'image.generate', 'approvals.create_set') register the same way and the
 * engine dispatches them in PCM_Automation_Engine::run_action().
 *
 * Each action is an array:
 *   id           string  unique id ('webhook')
 *   module       string  owning module id ('core' for built-ins)
 *   label        string  human label
 *   description  string  short help text
 *   configFields array   UI fields for the action config; each:
 *                        { key, label, type, required?, placeholder? }
 *   implemented  bool     whether the engine can actually run it yet
 *
 * @package PowerCreatives
 * @since   1.15.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Automation_Actions
{
    /** @var array<string, array> */
    private static array $actions = array();

    /** @var bool */
    private static bool $booted = false;

    /**
     * Register (or override) an action.
     *
     * @param array $action Action definition (see class docblock).
     * @return void
     */
    public static function register(array $action): void
    {
        if (empty($action['id'])) {
            return;
        }
        self::$actions[$action['id']] = $action;
    }

    /**
     * No built-ins are hardcoded here anymore. Actions are registered by each
     * module in its own includes/modules/{id}/automations.php (auto-loaded by
     * PCM_Module_Loader::discover()). The core 'webhook' action is registered by
     * includes/modules/automations/automations.php.
     *
     * @return void
     */
    private static function boot(): void
    {
        self::$booted = true;
    }

    /**
     * All registered actions (UI catalog).
     *
     * @return array<int, array>
     */
    public static function all(): array
    {
        self::boot();
        return array_values(self::$actions);
    }

    /**
     * Get an action by id.
     *
     * @param string $id Action id.
     * @return array|null
     */
    public static function get(string $id): ?array
    {
        self::boot();
        return self::$actions[$id] ?? null;
    }

    /**
     * Whether an action id is registered.
     *
     * @param string $id Action id.
     * @return bool
     */
    public static function is_valid(string $id): bool
    {
        self::boot();
        return isset(self::$actions[$id]);
    }
}
