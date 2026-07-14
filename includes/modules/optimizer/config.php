<?php
/**
 * Optimizer Module Configuration
 *
 * THE OPTIMIZER SYSTEM (architecture: docs/GAP-ANALYSIS-OPTIMIZER-
 * ARCHITECTURE-20260713.md): every optimization purpose is a separate
 * TEACHER — one file each under teachers/, discovered by the service —
 * contributing suggestions to ONE catalog. The user's basket of ticked
 * suggestions rides the page editor's existing optimize → red/green
 * review pipeline. Teachers never talk to each other.
 *
 * Auto-discovered by PCM_Module_Loader.
 *
 * @package PowerCreatives
 */

if (!defined('ABSPATH')) {
    exit;
}

return [
    'id'             => 'optimizer',
    'name'           => 'Optimizer',
    'description'    => 'Reality-based content analysis: per-purpose teachers producing tickable optimization suggestions.',
    'version'        => '1.0.0',
    'controller'     => 'PCM_REST_Optimizer',
    'rest_namespace' => 'pcm/v1/optimizer',
];
