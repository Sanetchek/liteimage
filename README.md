# LiteImage - WordPress Image Optimization Plugin

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-4.6%2B-blue)](https://wordpress.org/)

LiteImage is a lightweight, developer-friendly WordPress plugin that optimizes images using dynamic thumbnail generation, WebP support, and accessibility enhancements.

## ✨ Features

- 🖼️ **Dynamic Thumbnails** - Generate only the sizes you need on-demand
- 🚀 **WebP Support** - Automatic WebP conversion with fallback
- 📱 **Responsive Images** - Serve the right image for the right device
- ♿ **Accessibility** - Built-in alt text and ARIA support
- 🧹 **Cleanup Tools** - Remove unused thumbnails to save disk space
- 🔒 **Secure** - Rate limiting, input validation, MIME type checks
- ⚡ **Performance** - Caching, batch processing, optimized queries
- 🏗️ **Modern Architecture** - Full OOP with PHP namespaces and PSR-4 autoloading

## 📋 Requirements

- PHP 8.1 or higher (PHP 8.2, 8.3 recommended)
- WordPress 4.6 or higher
- Composer (for development)
- GD or Imagick extension (for WebP support)
- Intervention Image 3.x

## 🚀 Installation

### From WordPress.org (Coming Soon)

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for "LiteImage"
3. Click **Install Now** and then **Activate**

### Manual Installation

1. Download the latest release
2. Upload the `liteimage` folder to `/wp-content/plugins/`
3. Run `composer install --no-dev` in the plugin directory
4. Activate the plugin through the WordPress admin

### For Development

```bash
# Clone the repository
git clone https://github.com/Sanetchek/liteimage.git

# Install dependencies
cd liteimage
composer install

# Run tests
composer test

# Check code standards
composer cs-check
```

## 📖 Usage

### Basic Example

```php
<?php
// Display an image with ID 123
echo liteimage(123);
?>
```

### Custom Size

```php
<?php
echo liteimage(123, [
    'thumb' => [1280, 720],
    'args' => [
        'alt' => 'My awesome image',
        'class' => 'img-responsive'
    ]
]);
?>
```

### Responsive with Mobile Image

```php
<?php
echo liteimage(123, [
    'thumb' => [1920, 0],
    'min' => [
        '768' => [1920, 0],  // Desktop
        '1200' => [2560, 0]  // Large desktop
    ],
    'max' => [
        '767' => [768, 480]  // Mobile/Tablet
    ],
    'args' => [
        'alt' => 'Responsive image',
        'fetchpriority' => 'high'
    ]
], 456); // Use image ID 456 for mobile
?>
```

### Function Parameters

```php
liteimage(int $image_id, array $data = [], int|null $mobile_image_id = null)
```

**Parameters:**

- `$image_id` - WordPress attachment ID
- `$data` - Configuration array:
  - `thumb` - Default size: `'full'` or `[width, height]`
  - `args` - HTML attributes: `['alt' => '...', 'class' => '...']`
  - `min` - Min-width breakpoints: `['768' => [1920, 0]]`
  - `max` - Max-width breakpoints: `['767' => [768, 480]]`
- `$mobile_image_id` - Optional mobile image ID (< 768px)

### Gutenberg Block

- Add a **LiteImage Image**block in the Gutenberg editor.
- Specify a desktop and, if necessary, a mobile image.
- Set default sizes and custom breakpoints (*min-width*/*max-width*) to match your image sizes.
- Add any HTML attributes (`class`, `data-*`, `aria-*`, `loading`, `decoding`, etc.).
- The plugin automatically generates retina versions (@2x) for all sources and assembles the correct `srcset`.
- The saved block uses the same rendering logic as the `liteimage()` function, so it maintains consistent behavior on the frontend.

### Checking retina images (manual smoke test)

1. Enable the plugin and upload an image with any extension (JPEG/PNG/WebP).
2. Enable the **Generate WebP thumbnails**option (if available) and save the settings.
3. Insert an image via `liteimage($attachment_id, ['thumb' => [1024, 0]])`.
4. Open a page with `WP_DEBUG` enabled and in the browser console run:
   ```js
   [...document.querySelectorAll('picture img')].forEach((img) => console.log(img.currentSrc, img.srcset));
   ```
5. Make sure that for each `<source>` and `<img>` there is a `srcset` of the form `... 1x, ... 2x`, and that files with the `@2x` suffix are created even for images smaller than the original.
6. Clean up your thumbnails using the LiteImage Cleanup tool and check that the `@2x` files are removed from your downloads folder.

## 🎛️ Settings

Access settings at **Tools > LiteImage Settings**

### General Tab

- **Disable Thumbnails** - Prevent default WordPress thumbnail generation
- **Show Donation Section** - Toggle Bitcoin donation display
- **WebP Support Status** - View available WebP converters
- **Thumbnail Management** - Clear LiteImage or WordPress thumbnails

### Usage Tab

- Function documentation
- Code examples
- Best practices

## 🏗️ Project Structure

```
liteimage/
├── src/                      # Main source code (PSR-4)
│   ├── Admin/                # Admin interface
│   │   ├── AdminPage.php    # Settings page
│   │   └── Settings.php     # Settings management
│   ├── Image/                # Image processing
│   │   ├── Renderer.php     # Image rendering
│   │   ├── ThumbnailGenerator.php
│   │   └── ThumbnailCleaner.php
│   ├── Support/              # Support classes
│   │   ├── Logger.php       # Logging
│   │   └── WebPSupport.php  # WebP detection
│   ├── Config.php            # Configuration constants
│   └── Plugin.php            # Main plugin class
├── assets/                   # Frontend assets
│   └── js/
│       └── admin.js         # Admin JavaScript
├── includes/                 # Legacy includes (backward compatibility)
├── languages/                # Translation files
├── tests/                    # PHPUnit tests
├── vendor/                   # Composer dependencies
├── composer.json             # Composer configuration
├── liteimage.php             # Main plugin file
├── uninstall.php             # Uninstall script
├── phpcs.xml.dist            # Code sniffer rules
├── phpunit.xml.dist          # PHPUnit configuration
├── CHANGELOG.md              # Version history
├── CONTRIBUTING.md           # Contribution guidelines
└── README.md                 # This file
```

## 🛠️ Development

### Code Standards

This project follows:
- **PSR-12** coding standard
- **WordPress** coding standards for WP-specific code
- **PHPDoc** documentation for all classes and methods

### Running Tests

```bash
# Install dev dependencies
composer install

# Run PHP CodeSniffer
composer cs-check

# Fix coding standards automatically
composer cs-fix

# Run PHPUnit tests
composer test
```

### Creating a Pull Request

1. Fork the repository
2. Create a feature branch: `git checkout -b feat/your-feature`
3. Make your changes following [CONTRIBUTING.md](CONTRIBUTING.md)
4. Run tests and code standards checks
5. Commit using [Conventional Commits](https://www.conventionalcommits.org/)
6. Push and create a pull request

See [CONTRIBUTING.md](CONTRIBUTING.md) for detailed guidelines.

## 🔧 Configuration

### Constants

```php
// Enable debug logging
define('LITEIMAGE_LOG_ACTIVE', true);
```

### Filters

```php
// Modify image attributes
add_filter('liteimage_disabled_fallback', function($html, $image_id, $data, $mobile_id) {
    // Your custom logic
    return $html;
}, 10, 4);
```

### Actions

```php
// Before plugin initialization
add_action('plugins_loaded', function() {
    // Your custom code
}, 5); // Priority < 10 to run before LiteImage
```

## ⚡ Performance Tips

1. **Enable WebP** - Install GD or Imagick with WebP support
2. **Use Specific Sizes** - Avoid generating unnecessary thumbnails
3. **Clear Old Thumbnails** - Regularly clean unused sizes
4. **Enable Caching** - LiteImage uses WordPress transients
5. **Optimize Breakpoints** - Use only necessary responsive sizes

## 🔒 Security

- ✅ Input validation and sanitization
- ✅ Nonce verification for all actions
- ✅ Capability checks for admin operations
- ✅ Rate limiting on resource-intensive operations
- ✅ MIME type validation
- ✅ Logs stored outside public directory
- ✅ .htaccess protection for log files

## 📝 Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

## 🤝 Contributing

Contributions are welcome! Please read [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## 📜 License

This project is licensed under the GPL-2.0-or-later License - see the [LICENSE](LICENSE) file for details.

## 👨‍💻 Author

**Oleksandr Gryshko**

- GitHub: [@Sanetchek](https://github.com/Sanetchek)
- Website: [GitHub Profile](https://github.com/Sanetchek)

## 🙏 Acknowledgments

- Powered by [Intervention Image](http://image.intervention.io/)
- WordPress community for feedback and support
- All contributors who help improve this plugin

## 💬 Support

- 🐛 [Report a Bug](https://github.com/Sanetchek/liteimage/issues)
- 💡 [Request a Feature](https://github.com/Sanetchek/liteimage/issues)
- 📖 [Documentation](https://github.com/Sanetchek/liteimage/wiki)
- ⭐ [Star on GitHub](https://github.com/Sanetchek/liteimage)

## ☕ Support Development

If you find this plugin useful, consider supporting its development:

**Bitcoin (BTC):** `1NDUzCkYvKE5qHZnfR9f71NrXL2DJCAVpn`

---

Made with ❤️ for the WordPress community

