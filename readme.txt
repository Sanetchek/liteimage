=== LiteImage ===
Contributors: sanetchek
Tags: image optimization, responsive images, webp, thumbnails, accessibility
Requires at least: 5.0
Tested up to: 6.6
Stable tag: 3.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Optimize images with dynamic thumbnails, WebP support, and accessibility for faster, responsive WordPress sites.

== Description ==

LiteImage is a lightweight, developer-friendly WordPress plugin that optimizes images using dynamic thumbnail generation, WebP support, and accessibility enhancements. With a fully refactored object-oriented architecture, LiteImage gives full control over responsive image rendering and disk space management.

### Key Features

- **Dynamic Thumbnails**: Generate only the sizes you need on demand using the `liteimage()` function. Avoid bloated Media Libraries.
- **WebP Support**: Convert images to WebP automatically using `cwebp`, GD, or Imagick (if available). Falls back to original format if needed.
- **Responsive Images**: Serve the right image for the right device with media queries (`min`, `max`) and a mobile fallback image.
- **Mobile-Specific Images**: Serve a dedicated mobile image for viewports under 768px.
- **Accessibility & SEO**: Add `alt`, `aria-label`, and other HTML attributes to improve accessibility and search rankings.
- **Clean Thumbnails**: Delete LiteImage or WordPress-generated thumbnails from Tools > LiteImage Settings.
- **Debug Logging**: Track plugin activity in `liteimage-debug.log` when logging is enabled.
- **OOP Refactor (v3.1)**: Fully class-based core with backward compatibility for existing `liteimage()` calls.
- **Intervention Image Support**: Automatically uses Intervention Image if `vendor/autoload.php` is available.

== Installation ==

1. Upload the `liteimage` folder to `/wp-content/plugins/`.
2. Activate the plugin in your WordPress admin (Plugins > Installed Plugins).
3. Go to Tools > LiteImage Settings to configure thumbnail behavior, WebP support, and debug logs.
4. Use the `liteimage()` function in your theme or custom templates.

== Usage ==

Call the `liteimage()` function inside your theme files or custom templates to generate and display optimized images.

**Function Signature**:
```php
liteimage(int $image_id, array $data = [], int|null $mobile_image_id = null)
```

**Parameters**:
- `$image_id`: Media Library attachment ID.
- `$data` (optional):
  - `thumb`: Default image size (e.g. `'full'` or `[width, height]`).
  - `args`: HTML attributes (e.g. `['alt' => 'Example', 'class' => 'img']`).
  - `min`: Images for min-width media queries.
  - `max`: Images for max-width media queries.
- `$mobile_image_id`: Optional image ID for mobile devices (<768px).

**Examples**:
```php
// Basic usage
echo liteimage(123);

// With custom size and attributes
echo liteimage(123, [
    'thumb' => [1280, 720],
    'args' => ['alt' => 'Alt text', 'class' => 'img-responsive']
]);

// Responsive with mobile fallback
echo liteimage(123, [
    'thumb' => [1920, 0],
    'min' => ['768' => [1920, 0]],
    'max' => ['767' => [768, 480]],
    'args' => ['alt' => 'Responsive', 'fetchpriority' => 'high']
], 456);
```

== Settings ==

Access the settings page under **Tools > LiteImage Settings**:

- **Disable Thumbnails**: Prevent default WordPress sizes; generate only what you need.
- **Enable Logs**: Log plugin actions to `liteimage-debug.log`.
- **Clear Thumbnails**: Remove LiteImage- or WordPress-generated thumbnails with one click.
- **WebP Support Status**: Shows active WebP converter (cwebp, GD, or Imagick) or fallback.

== Frequently Asked Questions ==

**Does LiteImage support WebP?**
Yes, if `cwebp` is installed or GD/Imagick support WebP. Otherwise, it will fall back to JPEG or PNG.

**Can I use different images for mobile?**
Yes. Use the third `$mobile_image_id` parameter to set a mobile-only image (for screens < 768px).

**How do I clear thumbnails?**
Go to Tools > LiteImage Settings and use the available cleanup buttons.

**Where are logs stored?**
Logs are saved to `liteimage-debug.log` in the plugin folder when logging is enabled.

== Screenshots ==

1. LiteImage Settings with General & Usage tabs.
2. Thumbnail cleanup buttons.
3. Media Library showing dynamic sizes.

== Changelog ==

= 3.1 =
- Refactored to OOP (class-based architecture).
- Improved maintainability and performance.
- Added Intervention Image support.
- Preserved compatibility with `liteimage()`.

= 2.1 =
- Added buttons to clear WordPress and LiteImage thumbnails.
- Improved cleanup logic and UI guidance.

= 2.0 =
- Added WebP support status display.
- Codebase refactor for modularity.

= 1.0 =
- Initial release.

== Upgrade Notice ==

= 3.1 =
This release includes a full rewrite to a class-based structure. All existing uses of `liteimage()` continue to work. It is strongly recommended to upgrade for better performance and long-term maintainability.

== Credits ==

Developed by **Oleksandr Gryshko**
Powered by [Intervention Image](http://image.intervention.io/)

== License ==

This plugin is licensed under the GPLv2 or later.
See: https://www.gnu.org/licenses/gpl-2.0.html
