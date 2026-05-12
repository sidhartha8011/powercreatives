<?php
require __DIR__ . '/../../../wp-load.php';
global $wpdb;
$prefix = $wpdb->prefix . 'pcm_';

// Templates
$templates = $wpdb->get_results("SELECT id, name, module, description, isDefault FROM {$prefix}templates ORDER BY module, name");
echo "=== TEMPLATES (" . count($templates) . ") ===" . PHP_EOL;
foreach ($templates as $t) {
    echo $t->id . ' | ' . $t->module . ' | ' . $t->name . ' | default=' . $t->isDefault . PHP_EOL;
}

// Prompt overrides
$prompts = $wpdb->get_results("SELECT id, module, section, variantName, isActive FROM {$prefix}prompt_overrides ORDER BY module, section");
echo PHP_EOL . "=== PROMPT OVERRIDES (" . count($prompts) . ") ===" . PHP_EOL;
foreach ($prompts as $p) {
    echo $p->id . ' | ' . $p->module . ' | ' . $p->section . ' | ' . $p->variantName . ' | active=' . $p->isActive . PHP_EOL;
}
