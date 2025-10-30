# LiteImage Test Suite

## 📋 Available Tests

### ✅ Created Tests

1. **ConfigTest.php** - Tests for `LiteImage\Config`
   - Constant existence
   - Mobile breakpoint
   - WebP quality
   - Thumbnail prefix
   - Allowed MIME types

2. **WebPSupportTest.php** - Tests for `LiteImage\Support\WebPSupport`
   - Method existence
   - Boolean return value
   - Cache consistency

3. **SettingsTest.php** - Tests for `LiteImage\Admin\Settings`
   - Singleton pattern
   - Method existence
   - get/set/save methods

4. **PluginTest.php** - Tests for `LiteImage\Plugin`
   - Singleton pattern
   - Version constant
   - Activation/deactivation methods

5. **RendererTest.php** - Tests for `LiteImage\Image\Renderer`
   - Instantiation
   - Data array handling
   - Mobile image support

6. **ThumbnailGeneratorTest.php** - Tests for `LiteImage\Image\ThumbnailGenerator`
   - get_thumb_size method
   - Size name format
   - Zero dimensions handling

7. **AdminPageTest.php** - Tests for `LiteImage\Admin\AdminPage`
   - Static methods
   - Admin functionality

---

## 🚀 Running Tests

```bash
composer test
```

---

## 📊 Coverage

**Total: 7 test classes, 38+ test methods**

