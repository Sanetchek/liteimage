<?php

namespace LiteImage\Tests\Image;

use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;
use LiteImage\Image\SmartCompressionService;
use LiteImage\Image\SmartCompressionTelemetry;
use LiteImage\Image\ThumbnailGenerator;
use LiteImage\Support\WebPSupport;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

class ThumbnailGeneratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setWebpSupport(true);
        $this->setSmartCompressionService(null);
        SmartCompressionTelemetry::clear();
    }

    protected function tearDown(): void
    {
        SmartCompressionTelemetry::clear();
        $this->setSmartCompressionService(null);
        $this->setWebpSupport(null);
        parent::tearDown();
    }

    public function testGenerateThumbnailUsesSmartCompressionWhenEnabled(): void
    {
        $image = $this->createMock(ImageInterface::class);
        $image->expects($this->once())
            ->method('scale')
            ->with(120, 80)
            ->willReturnSelf();

        $smartService = $this->createMock(SmartCompressionService::class);
        $smartService->expects($this->once())
            ->method('encode')
            ->with(
                $image,
                'webp',
                $this->stringContains('smart-thumb.webp'),
                $this->callback(static function (array $options): bool {
                    return isset($options['initial_quality']) && $options['initial_quality'] === 100
                        && isset($options['context']['size_name'])
                        && $options['context']['density'] === '1x';
                })
            )
            ->willReturn([
                'quality' => 100,
                'path' => sys_get_temp_dir() . '/smart-thumb.webp',
                'bytes' => 1024,
                'psnr' => 99.0,
                'iterations' => 1,
                'strategy' => 'psnr',
            ]);

        $this->setSmartCompressionService($smartService);

        $this->invokeGenerateThumbnail(
            $image,
            '/tmp/source.webp',
            'smart',
            120,
            80,
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smart-thumb.webp',
            'webp',
            false,
            80,
            [
                'enabled' => true,
                'initial_quality' => 80,
                'min_quality' => 80,
                'target_psnr' => 44.5,
                'max_iterations' => 6,
                'min_savings_percent' => 4.0,
                'context' => [
                    'size_name' => 'smart',
                    'density' => '1x',
                    'width' => 120,
                    'height' => 80,
                ],
                'upscaled' => false,
            ]
        );
    }

    public function testGenerateThumbnailSkipsWhenWebpUnsupported(): void
    {
        $this->setWebpSupport(false);

        /** @var ImageInterface&MockObject $image */
        $image = $this->createMock(ImageInterface::class);
        $image->expects($this->never())->method('scale');

        $this->invokeGenerateThumbnail(
            $image,
            '/tmp/source.jpg',
            'test-size',
            200,
            150,
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unused.webp',
            'jpg',
            false,
            80,
            [
                'enabled' => true,
                'context' => [
                    'size_name' => 'test-size',
                    'density' => '1x',
                    'width' => 200,
                    'height' => 150,
                ],
                'upscaled' => false,
            ]
        );
    }

    public function testGenerateOriginalThumbnailFallsBackToDirectEncodeWhenDisabled(): void
    {
        $image = $this->createMock(ImageInterface::class);
        $encoded = $this->createMock(EncodedImageInterface::class);

        $destPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fallback-direct.jpg';

        $image->expects($this->once())
            ->method('scale')
            ->with(640, 480)
            ->willReturnSelf();

        $image->expects($this->once())
            ->method('toJpeg')
            ->with(80)
            ->willReturn($encoded);

        $encoded->expects($this->once())
            ->method('save')
            ->with($destPath)
            ->willReturnCallback(static function () use ($destPath): void {
                file_put_contents($destPath, 'binarydata');
            });

        $this->invokeGenerateOriginalThumbnail(
            $image,
            '/tmp/source.jpeg',
            'landscape',
            640,
            480,
            $destPath,
            'jpeg',
            false,
            80,
            [
                'enabled' => false,
                'initial_quality' => 80,
                'context' => [
                    'size_name' => 'landscape',
                    'density' => '1x',
                    'width' => 640,
                    'height' => 480,
                ],
                'upscaled' => false,
            ]
        );

        $this->assertFileExists($destPath);
        @unlink($destPath);
    }

    public function testGenerateOriginalThumbnailUsesSmartCompressionWhenEnabled(): void
    {
        $image = $this->createMock(ImageInterface::class);
        $image->expects($this->once())
            ->method('scale')
            ->with(320, 240)
            ->willReturnSelf();

        $smartService = $this->createMock(SmartCompressionService::class);
        $smartService->expects($this->once())
            ->method('encode')
            ->with(
                $image,
                'jpeg',
                $this->stringContains('smart-original.jpg'),
                $this->callback(static function (array $options): bool {
                    return isset($options['initial_quality']) && $options['initial_quality'] === 88
                        && isset($options['context']['density']) && $options['context']['density'] === '1x';
                })
            )
            ->willReturn([
                'quality' => 88,
                'path' => sys_get_temp_dir() . '/smart-original.jpg',
                'bytes' => 2048,
                'psnr' => 45.5,
                'iterations' => 2,
                'strategy' => 'psnr',
            ]);

        $this->setSmartCompressionService($smartService);

        $this->invokeGenerateOriginalThumbnail(
            $image,
            '/tmp/source.jpg',
            'smart-original',
            320,
            240,
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'smart-original.jpg',
            'jpeg',
            false,
            88,
            [
                'enabled' => true,
                'initial_quality' => 88,
                'min_quality' => 80,
                'target_psnr' => 44.5,
                'max_iterations' => 6,
                'min_savings_percent' => 4.0,
                'context' => [
                    'size_name' => 'smart-original',
                    'density' => '1x',
                    'width' => 320,
                    'height' => 240,
                ],
                'upscaled' => false,
            ]
        );
    }

    public function testResolveWebpQualityPassThroughForNonWebp(): void
    {
        $method = (new ReflectionClass(ThumbnailGenerator::class))->getMethod('resolve_webp_quality');
        $method->setAccessible(true);

        $this->assertSame(
            77,
            $method->invoke(null, 'jpeg', 77)
        );
    }

    public function testResolveWebpQualityForcesLosslessForWebp(): void
    {
        $method = (new ReflectionClass(ThumbnailGenerator::class))->getMethod('resolve_webp_quality');
        $method->setAccessible(true);

        $this->assertSame(
            100,
            $method->invoke(null, 'webp', 80)
        );
    }

    private function invokeGenerateThumbnail(
        ImageInterface $image,
        string $filePath,
        string $sizeName,
        int $destWidth,
        int $destHeight,
        string $webpPath,
        string $originalExtension,
        bool $crop,
        int $quality,
        array $smartOptions = []
    ): void {
        $reflection = new ReflectionClass(ThumbnailGenerator::class);
        $method = $reflection->getMethod('generate_thumbnail');
        $method->setAccessible(true);
        $method->invoke(
            null,
            $image,
            $filePath,
            $sizeName,
            $destWidth,
            $destHeight,
            $webpPath,
            $originalExtension,
            $crop,
            $quality,
            $smartOptions
        );
    }

    private function invokeGenerateOriginalThumbnail(
        ImageInterface $image,
        string $filePath,
        string $sizeName,
        int $destWidth,
        int $destHeight,
        string $destPath,
        string $originalExtension,
        bool $crop,
        int $quality,
        array $smartOptions = []
    ): void {
        $reflection = new ReflectionClass(ThumbnailGenerator::class);
        $method = $reflection->getMethod('generate_original_thumbnail');
        $method->setAccessible(true);
        $method->invoke(
            null,
            $image,
            $filePath,
            $sizeName,
            $destWidth,
            $destHeight,
            $destPath,
            $originalExtension,
            $crop,
            $quality,
            $smartOptions
        );
    }

    private function setWebpSupport(?bool $value): void
    {
        $reflection = new ReflectionClass(WebPSupport::class);
        $property = $reflection->getProperty('webp_supported');
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }

    private function setSmartCompressionService(?SmartCompressionService $service): void
    {
        $reflection = new ReflectionClass(ThumbnailGenerator::class);
        $property = $reflection->getProperty('smartCompressionService');
        $property->setAccessible(true);
        $property->setValue(null, $service);
    }
}


