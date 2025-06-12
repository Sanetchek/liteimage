# LiteImage WordPress Plugin
Optimizes images in WordPress with dynamic thumbnail sizes, WebP support, and accessibility.

## Features
* Converts images to **WebP**, **JPEG**, and **PNG**.
* Dynamic thumbnail generation.
* Lazy‑loading and accessibility (alt text / `loading="lazy"`) support.
* [Intervention Image](https://github.com/Intervention/image) integration for advanced processing.

## Requirements
* PHP ≥ 5.6
* WordPress (latest recommended)
* Composer

## File Structure
- `liteimage.php`: Main plugin file, initializes the plugin.
- `src/`: Contains modularized classes (`Settings.php`, `Logger.php`, `WebPSupport.php`, `ThumbnailGenerator.php`, `Admin.php`, `ThumbnailCleaner.php`, `Functions.php`).
- `assets/images/wp/`: Stores static assets like the Bitcoin QR code.
- `languages/`: Translation files.
- `vendor/`: Composer dependencies.

### Composer dependencies
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
4. **Activate the plugin** in the WordPress admin panel (`Plugins → Installed Plugins`).

## Usage
Call `liteimage()` in your theme or plugin templates:

```php
<?php
// Example usage
echo liteimage(
    $image_id,
    [
        'thumb' => [1920, 0],               // Default (width, height)
        'args' => ['class' => 'my-image'], // HTML attributes
        'min' => ['768' => [1280, 0]],     // ≥ 768px
        'max' => ['767' => [768, 480]],    // ≤ 767px
    ],
    $mobile_image_id
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
