# LiteImage WordPress Plugin

Optimizes images in WordPress with dynamic thumbnail sizes, WebP support, and accessibility.

## Features

* Converts images to **WebP**, **JPEG**, and **PNG**.
* Dynamic thumbnail generation.
* Lazy‑loading and accessibility (alt text / `loading="lazy"`) support.
* [Intervention Image](https://github.com/Intervention/image) integration for advanced processing.

## Requirements

* PHP ≥ 5.6
* WordPress (latest recommended)
* Composer

### Composer dependencies

* `mpdf/mpdf: ^8.1`
* `vlucas/phpdotenv: ^5.6`
* `intervention/image: ^2.7`

## Installation

1. **Clone the repository**

   ```bash
   git clone https://github.com/Sanetchek/liteimage.git
   ```
2. **Navigate to the plugin directory**

   ```bash
   cd liteimage
   ```
3. **Install Composer dependencies**

   ```bash
   composer install
   ```
4. **Activate the plugin** in the WordPress admin panel (`Plugins → Installed Plugins`).

## Usage

Call `liteimage_picture()` in your theme or plugin templates:

```php
<?php
// Example usage

echo liteimage_picture(
    $image_id,
    [1920, 0],               // Default (width, height) for full‑size thumbnail
    ['class' => 'my-image'], // Extra HTML attributes
    ['768' => [1280, 0]],    // ≥ 768 px — use 1280 px wide image
    ['767' => [768, 480]]    // ≤ 767 px — use 768 × 480 fallback
);
```

## Development

### Prerequisites

* Composer installed and available in your `$PATH`.

### Development dependencies

* `dealerdirect/phpcodesniffer-composer-installer: ^0.7.0`
* `wptrt/wpthemereview: ^0.2.1`
* `php-parallel-lint/php-parallel-lint: ^1.2.0`
* `wp-cli/i18n-command: ^2.2.5`

### Scripts

* **Lint code**

  ```bash
  composer lint:wpcs
  composer lint:php
  ```
* **Generate POT file**

  ```bash
  composer make-pot
  ```

## Support

* **Report issues:** [https://github.com/Sanetchek/liteimage/issues](https://github.com/Sanetchek/liteimage/issues)
* **Source code:** [https://github.com/Sanetchek/liteimage](https://github.com/Sanetchek/liteimage)

## License

GPL‑2.0‑or‑later

## Contributors

See the **Contributors** page on GitHub.
