# WordPress Development

Use modern PHP structure even in WordPress. WordPress is globally oriented, but plugin code does not need to be.

## Recommended Approach

- Put plugin classes under a vendor namespace such as `Acme\SeoToolkit\`
- Use Composer PSR-4 autoloading instead of manual `require_once` chains
- Keep the plugin bootstrap file thin: load the autoloader, build services, register hooks
- Minimize global functions; if unavoidable, prefix them aggressively
- Isolate WordPress-specific code from domain logic so business rules stay testable
- Declare the minimum versions in the plugin header (`Requires PHP: 8.1`, `Requires at least: 6.x`) and keep them consistent with `composer.json`

## Plugin Structure

```text
my-plugin/
├── my-plugin.php
├── composer.json
├── uninstall.php
├── src/
│   ├── Bootstrap.php
│   ├── Admin/
│   │   └── SettingsPage.php
│   ├── Domain/
│   │   └── SlugGenerator.php
│   └── Infrastructure/
│       └── WordPressOptionRepository.php
└── vendor/
```

```json
{
  "autoload": {
    "psr-4": {
      "Acme\\SeoToolkit\\": "src/"
    }
  }
}
```

Run `composer dump-autoload` after namespace changes.

## Thin Bootstrap File

```php
<?php
declare(strict_types=1);

/**
 * Plugin Name: SEO Toolkit
 * Requires PHP: 8.1
 * Text Domain: acme-seo
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/vendor/autoload.php';

register_activation_hook(__FILE__, [Acme\SeoToolkit\Bootstrap::class, 'activate']);

(new Acme\SeoToolkit\Bootstrap())->register();
```

## Hook Registration Through Classes

```php
<?php
declare(strict_types=1);

namespace Acme\SeoToolkit;

final class Bootstrap
{
    public function __construct(
        private readonly Admin\SettingsPage $settingsPage = new Admin\SettingsPage(),
    ) {
    }

    public static function activate(): void
    {
        add_option('acme_seo_version', '1.0.0'); // runs once; keep it fast and idempotent
    }

    public function register(): void
    {
        add_action('admin_menu', [$this->settingsPage, 'register']);
        add_action('admin_post_acme_save_settings', [$this->settingsPage, 'handleSave']);
    }
}
```

## Keep WordPress at the Boundary

Domain code is plain PHP and unit-testable without loading WordPress. Note the pattern accepts non-ASCII titles; an ASCII-only `[^a-z0-9]` pattern would turn a Japanese title into an empty slug. Inside WordPress itself, `sanitize_title()` is the standard function; this example shows the isolation.

<!-- verify:run -->
```php
<?php
declare(strict_types=1);

namespace Acme\SeoToolkit\Domain;

final class SlugGenerator
{
    public function generate(string $title): string
    {
        $slug = preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower(trim($title))) ?? '';

        return trim($slug, '-');
    }
}

$generator = new SlugGenerator();
assert($generator->generate('  Hello, World!  ') === 'hello-world');
assert($generator->generate('日本語 タイトル') === '日本語-タイトル');
assert($generator->generate('!!!') === '');
```

```php
<?php
declare(strict_types=1);

namespace Acme\SeoToolkit\Infrastructure;

final class WordPressOptionRepository
{
    public function save(string $key, string $value): void
    {
        update_option($key, $value);
    }
}
```

## Handling Requests: Capability, Nonce, Sanitize, Escape

```php
<?php
declare(strict_types=1);

namespace Acme\SeoToolkit\Admin;

final class SettingsPage
{
    public function register(): void
    {
        add_options_page(
            esc_html__('SEO Toolkit', 'acme-seo'),
            esc_html__('SEO Toolkit', 'acme-seo'),
            'manage_options',
            'acme-seo',
            [$this, 'render'],
        );
    }

    public function render(): void
    {
        $title = (string) get_option('acme_seo_title', '');
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="acme_save_settings">
            <?php wp_nonce_field('acme_save_settings'); ?>
            <input name="title" value="<?php echo esc_attr($title); ?>">
            <?php submit_button(); ?>
        </form>
        <?php
    }

    public function handleSave(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'acme-seo'), '', ['response' => 403]);
        }
        check_admin_referer('acme_save_settings');

        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        update_option('acme_seo_title', $title);

        wp_safe_redirect(add_query_arg('updated', '1', wp_get_referer() ?: admin_url()));
        exit;
    }
}
```

Order matters: capability check, then nonce, then unslash and sanitize, then act. Escape late, at the point of output, with the function that matches the context (`esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`).

## Database Access

```php
<?php
declare(strict_types=1);

global $wpdb;

$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_author = %d AND post_status = %s LIMIT %d",
    $authorId,
    'publish',
    $limit,
));

// LIKE: escape the wildcard characters in the user's text first
$like = '%' . $wpdb->esc_like($term) . '%';
$matches = $wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s", $like));
```

Prefer `WP_Query`, `get_posts()`, and the options/meta APIs over raw SQL, and cache expensive results with transients or the object cache.

## REST API

Every route needs a `permission_callback`. Use `__return_true` explicitly for public endpoints so the choice is visible.

```php
<?php
declare(strict_types=1);

add_action('rest_api_init', static function (): void {
    register_rest_route('acme/v1', '/items/(?P<id>\d+)', [
        'methods' => WP_REST_Server::READABLE,
        'callback' => static fn (WP_REST_Request $request): WP_REST_Response => new WP_REST_Response(['id' => (int) $request['id']]),
        'permission_callback' => static fn (): bool => current_user_can('edit_posts'),
        'args' => [
            'id' => ['validate_callback' => static fn (mixed $value): bool => is_numeric($value)],
        ],
    ]);
});
```

## Dependencies Inside a Plugin

All plugins share one PHP process. If two plugins bundle different versions of the same Composer package under the same namespace, whichever loads first wins and the other breaks. Prefix bundled dependencies with Strauss or PHP-Scoper at build time, or avoid third-party packages for small needs.

## WordPress-Specific Rules

- Do not put non-trivial business logic directly inside action/filter callbacks
- Treat WordPress functions as infrastructure adapters, not your core domain API
- Escape on output (`esc_html`, `esc_attr`, `wp_kses_post`) and validate on input
- Verify capabilities and nonces for admin actions and form submissions
- Prefer constructor injection for collaborators, but adapt to WordPress lifecycle pragmatically
- If you must expose global functions for hooks, make them thin wrappers around namespaced classes
- Use `get_stylesheet_directory*()` / `get_stylesheet_uri()` when child themes must override assets or data
- For AJAX/HTMX endpoints, return the real failure status code instead of silently rendering fallback HTML with `200`
- Wrap user-visible strings in `__()` / `esc_html__()` with a literal text domain (translation tools cannot read variables)
- Clean up in `uninstall.php` (options, custom tables), not in the deactivation hook
- Avoid `outline: none`; preserve visible focus states and sufficient placeholder contrast

## Testing and Standards

- Unit-test the `Domain/` classes with plain PHPUnit. Test hook wiring with Brain Monkey or WP_Mock, and integration behaviour with `wp-env` and `WP_UnitTestCase`.
- WordPress Coding Standards (WPCS) expect `class-foo.php` file names, which conflicts with PSR-4. If you adopt WPCS, exclude `WordPress.Files.FileName` for `src/`, or follow PER Coding Style and skip WPCS for namespaced code. Decide once and document it.
