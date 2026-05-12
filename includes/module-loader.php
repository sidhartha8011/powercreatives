<?php
/**
 * Module Loader — Auto-Discovery System
 *
 * Scans the `includes/modules/` directory for module config files.
 * Each module must provide a `config.php` that returns an array with:
 *   - id:             string  — unique module identifier
 *   - name:           string  — human-readable module name
 *   - version:        string  — module version
 *   - controller:     string  — fully qualified class name of the REST controller
 *   - rest_namespace: string  — REST API namespace (e.g. 'pcm/v1/brands')
 *
 * Usage in power-creatives.php:
 *   require_once PCM_PLUGIN_DIR . 'includes/module-loader.php';
 *   PCM_Module_Loader::discover();
 *   // Then in rest_api_init:
 *   PCM_Module_Loader::register_routes();
 *
 * Adding a new module:
 *   1. Create includes/modules/{feature}/config.php
 *   2. Create includes/modules/{feature}/controller.php
 *   3. Done! The loader auto-discovers it.
 *
 * @package PowerCreatives
 * @since   1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Module_Loader
{

    /**
     * Registry of discovered modules.
     * Keyed by module ID.
     *
     * @var array<string, array>
     */
    private static array $modules = [];

    /**
     * Whether discovery has already run.
     *
     * @var bool
     */
    private static bool $discovered = false;

    /**
     * Discover all modules by scanning includes/modules/{name}/config.php.
     *
     * Each config.php must return an associative array with the keys
     * documented above. Invalid configs are silently skipped with
     * an error_log() notice.
     *
     * @return void
     */
    public static function discover(): void
    {
        if (self::$discovered) {
            return;
        }

        self::$discovered = true;

        $modules_dir = PCM_PLUGIN_DIR . 'includes/modules/';

        // Guard: nothing to discover if directory doesn't exist yet
        if (!is_dir($modules_dir)) {
            return;
        }

        // Scan for subdirectories containing config.php
        $entries = scandir($modules_dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            // Skip dots and files
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $config_path = $modules_dir . $entry . '/config.php';

            if (!file_exists($config_path)) {
                continue;
            }

            $config = require $config_path;

            // Validate required keys
            if (!is_array($config) || empty($config['id']) || empty($config['controller'])) {
                error_log(sprintf(
                    'PCM Module Loader: Invalid config in modules/%s/config.php — missing "id" or "controller".',
                    $entry
                ));
                continue;
            }

            // Auto-require the controller file if it exists alongside config
            $controller_path = $modules_dir . $entry . '/controller.php';
            if (file_exists($controller_path)) {
                require_once $controller_path;
            }

            // Optional: require service.php if present
            $service_path = $modules_dir . $entry . '/service.php';
            if (file_exists($service_path)) {
                require_once $service_path;
            }

            self::$modules[$config['id']] = $config;
        }
    }

    /**
     * Register REST routes for all discovered modules.
     *
     * Called from the `rest_api_init` hook. Instantiates each module's
     * controller and calls register() (inherited from PCM_REST_Base).
     *
     * @return void
     */
    public static function register_routes(): void
    {
        foreach (self::$modules as $id => $config) {
            $class = $config['controller'];

            if (!class_exists($class)) {
                error_log(sprintf(
                    'PCM Module Loader: Controller class "%s" not found for module "%s".',
                    $class,
                    $id
                ));
                continue;
            }

            $controller = new $class();

            if (method_exists($controller, 'register')) {
                $controller->register();
            }
        }
    }

    /**
     * Get all discovered modules.
     *
     * @return array<string, array> Module configs keyed by ID.
     */
    public static function get_modules(): array
    {
        return self::$modules;
    }

    /**
     * Get a single module config by ID.
     *
     * @param string $module_id Module identifier.
     * @return array|null Module config or null if not found.
     */
    public static function get(string $module_id): ?array
    {
        return self::$modules[$module_id] ?? null;
    }

    /**
     * Check if a module is loaded.
     *
     * @param string $module_id Module identifier.
     * @return bool
     */
    public static function has(string $module_id): bool
    {
        return isset(self::$modules[$module_id]);
    }
}
