# Contributing to Power Creatives

## Adding a New Module

1. Create `includes/modules/{name}/config.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }
return [
    'id'             => 'my-feature',
    'name'           => 'My Feature',
    'description'    => 'What this module does.',
    'version'        => '1.0.0',
    'controller'     => 'PCM_REST_MyFeature',
    'rest_namespace' => 'pcm/v1/my-feature',
];
```

2. Create `includes/modules/{name}/controller.php`:
```php
<?php
if (!defined('ABSPATH')) { exit; }

class PCM_REST_MyFeature extends PCM_REST_Base
{
    protected function routes(): array
    {
        return [
            ['GET', '/my-feature', 'list_items'],
        ];
    }

    public function list_items(WP_REST_Request $request): WP_REST_Response
    {
        return $this->success(['items' => []]);
    }
}
```

3. Done — the module is auto-discovered on next page load.

## Adding a New AI Provider

1. Create `includes/core/providers/class-pcm-provider-{name}.php` implementing `PCM_Provider_Interface`
2. Add the class mapping to `PCM_Provider_Registry::PROVIDERS`
3. Add provider metadata to `PCM_Providers::get_all()`

## Adding a New AI Model

Models are managed dynamically via the Models module (database-driven).
To add a model:
1. Go to Settings → Integrations → Add your API key
2. Click "Sync Models" — models are auto-discovered from the provider API
3. Models can be enabled/disabled per module (image, video, text)

## Code Standards

- **PHP 8.1+** required (typed properties, enums, match expressions)
- **WordPress coding standards** for naming (`snake_case` for functions/variables)
- **PHPDoc** on all public methods
- All SQL via `$wpdb->prepare()` — never raw interpolation
- REST responses via `$this->success()` / `$this->error()` — never raw `WP_REST_Response`
- Shared logic belongs in `includes/core/`, not duplicated across modules

## File Naming

| Location | Convention | Example |
|----------|-----------|---------|
| Core classes | `class-pcm-{name}.php` | `class-pcm-llm.php` |
| Module controllers | `controller.php` | `modules/image/controller.php` |
| Module configs | `config.php` | `modules/image/config.php` |
| Module services | `service.php` | `modules/image/service.php` |
| Provider implementations | `class-pcm-provider-{name}.php` | `class-pcm-provider-openai.php` |
