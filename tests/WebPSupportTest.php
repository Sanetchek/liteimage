<?php
/**
 * Tests for LiteImage\Support\WebPSupport class
 *
 * @package LiteImage
 */

use LiteImage\Support\WebPSupport;

/**
 * Test WebPSupport class
 */
class WebPSupportTest extends PHPUnit\Framework\TestCase
{
    public function testIsWebpSupportedExists()
    {
        $this->assertTrue(method_exists(WebPSupport::class, 'is_webp_supported'));
    }

    public function testIsWebpSupportedReturnsBoolean()
    {
        $result = WebPSupport::is_webp_supported();
        $this->assertIsBool($result);
    }

    public function testIsWebpSupportedIsConsistent()
    {
        // Should return same result on consecutive calls (cached)
        $result1 = WebPSupport::is_webp_supported();
        $result2 = WebPSupport::is_webp_supported();

        $this->assertEquals($result1, $result2);
    }
}

