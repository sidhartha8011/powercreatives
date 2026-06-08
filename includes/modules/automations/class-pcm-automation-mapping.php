<?php
/**
 * Automation Input Mapping
 *
 * Resolves a rule's declarative inputMapping against a trigger's context to build
 * the inputs an action handler consumes. This is the glue that makes automations
 * cross-module: a trigger exposes a context (e.g. { name, link, brandId, setId }),
 * and an action declares the inputs it needs; the mapping wires one to the other.
 *
 * Mapping shape (JSON): { actionInputKey: template, ... } where each template is
 * either a literal string or contains {{context.key}} / {{key}} placeholders.
 * Example:
 *   { "prompt": "Ad for {{name}}", "brandId": "{{brandId}}" }
 *
 * Resolution is pure string substitution — placeholders only READ context values,
 * never evaluate code.
 *
 * @package PowerCreatives
 * @since   1.16.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PCM_Automation_Mapping
{
    /**
     * Resolve a mapping against a context.
     *
     * @param array $mapping Map of inputKey => template string.
     * @param array $context Trigger context (flat key => scalar/array).
     * @return array Map of inputKey => resolved value.
     */
    public static function resolve(array $mapping, array $context): array
    {
        $resolved = array();
        foreach ($mapping as $key => $template) {
            if (is_array($template)) {
                // Nested mapping (rare) — resolve recursively.
                $resolved[$key] = self::resolve($template, $context);
                continue;
            }
            $resolved[$key] = self::interpolate((string) $template, $context);
        }
        return $resolved;
    }

    /**
     * Substitute {{context.key}} / {{key}} placeholders in a string with values
     * from the context. Unknown placeholders resolve to an empty string. If the
     * entire template is a single placeholder, the raw (typed) context value is
     * returned so non-string values (ints, etc.) survive.
     *
     * @param string $template Template string.
     * @param array  $context  Context map.
     * @return mixed Resolved value (string, or raw context value for a lone token).
     */
    public static function interpolate(string $template, array $context)
    {
        $template = trim($template);

        // Lone placeholder → return the raw context value (preserves type).
        if (preg_match('/^\{\{\s*(?:context\.)?([a-zA-Z0-9_]+)\s*\}\}$/', $template, $m)) {
            return self::lookup($m[1], $context);
        }

        // Inline placeholders within text → string interpolation.
        return preg_replace_callback(
            '/\{\{\s*(?:context\.)?([a-zA-Z0-9_]+)\s*\}\}/',
            static function ($m) use ($context) {
                $value = self::lookup($m[1], $context);
                if (is_array($value)) {
                    return wp_json_encode($value);
                }
                return $value === null ? '' : (string) $value;
            },
            $template
        );
    }

    /**
     * Look up a key in the context (returns null if absent).
     *
     * @param string $key     Context key.
     * @param array  $context Context map.
     * @return mixed|null
     */
    private static function lookup(string $key, array $context)
    {
        return array_key_exists($key, $context) ? $context[$key] : null;
    }
}
