<?php declare(strict_types=1);

namespace Shopware\Tests\Unit\Core\Content\Seo;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Seo\SeoResolver;
use Shopware\Core\Framework\Log\Package;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\Doctrine\FakeResultFactory;

/**
 * @internal
 */
#[Package('inventory')]
#[CoversClass(SeoResolver::class)]
class SeoResolverTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function resolveDataProvider(): array
    {
        return [
            'null case' => [
                '',
                '/',
            ],
            'same content, leading, but trailing slash' => [
                '/seo-url',
                '/seo-url',
            ],
            'same content, leading and trailing slash' => [
                '/seo-url/',
                '/seo-url/',
            ],
            'no trailing slash' => [
                'seo-url',
                '/seo-url',
            ],
            'trailing slash' => [
                'seo-url/',
                '/seo-url/',
            ],
            '2 levels, no trailing slash' => [
                'seo-url/nice-addition',
                '/seo-url/nice-addition',
            ],
            '2 levels, trailing slash' => [
                'seo-url/nice-addition/',
                '/seo-url/nice-addition/',
            ],
            'lots of levels, no trailing slash' => [
                'seo-url/nice-addition/with/something/really/really/reaaaaally/long',
                '/seo-url/nice-addition/with/something/really/really/reaaaaally/long',
            ],
            'lots of levels, trailing slash' => [
                'seo-url/nice-addition/with/something/really/really/reaaaaally/long/',
                '/seo-url/nice-addition/with/something/really/really/reaaaaally/long/',
            ],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function resolveCanonicalDataProvider(): array
    {
        return [
            'null case' => [
                '',
                '/',
            ],
            'same content, leading, but trailing slash' => [
                '/Industrial-Kids',
                '/Industrial-Kids',
            ],
            'same content, leading and trailing slash' => [
                '/Industrial-Kids/',
                '/Industrial-Kids/',
            ],
            'no trailing slash' => [
                'Industrial-Kids',
                '/Industrial-Kids',
            ],
            'trailing slash' => [
                'Industrial-Kids/',
                '/Industrial-Kids/',
            ],
            'lots of levels, no trailing slash' => [
                'Industrial-Kids/Automotive/Outdoors-Books-Beauty/Shoes-Beauty-Books',
                '/Industrial-Kids/Automotive/Outdoors-Books-Beauty/Shoes-Beauty-Books',
            ],
            'lots of levels, trailing slash' => [
                'Industrial-Kids/Automotive/Outdoors-Books-Beauty/Shoes-Beauty-Books/',
                '/Industrial-Kids/Automotive/Outdoors-Books-Beauty/Shoes-Beauty-Books/',
            ],
        ];
    }

    #[DataProvider('resolveDataProvider')]
    public function testResolveWithIsCanonical(string $pathInfo, string $expected): void
    {
        $salesChannelId = Uuid::randomHex();
        $seoResolver = new SeoResolver($this->getMockConnection($salesChannelId, true, $pathInfo));

        $resolvedSeoUrl = $seoResolver->resolve(Uuid::randomHex(), $salesChannelId, $pathInfo);

        static::assertSame($expected, $resolvedSeoUrl['pathInfo']);
    }

    #[DataProvider('resolveCanonicalDataProvider')]
    public function testResolveWithNotCanonical(string $pathInfo, string $expected): void
    {
        $salesChannelId = Uuid::randomHex();
        $seoResolver = new SeoResolver($this->getMockConnection($salesChannelId, false, $pathInfo));

        /** @var array{canonicalPathInfo: string, pathInfo: string, isCanonical: bool} $resolvedSeoUrl */
        $resolvedSeoUrl = $seoResolver->resolve(Uuid::randomHex(), $salesChannelId, $pathInfo);

        static::assertSame($expected, $resolvedSeoUrl['canonicalPathInfo']);
    }

    public function testResolveWithQueryStringReturnsCanonical(): void
    {
        $salesChannelId = Uuid::randomHex();
        $expectedPathInfo = '/detail/12345';

        $seoResolver = new SeoResolver($this->getMockConnection($salesChannelId, true, $expectedPathInfo));

        $resolvedSeoUrl = $seoResolver->resolveWithQueryString(Uuid::randomHex(), $salesChannelId, 'Main-product/SWDEMO10001', 'test=123');

        static::assertSame($expectedPathInfo, $resolvedSeoUrl['pathInfo']);
        static::assertTrue((bool) $resolvedSeoUrl['isCanonical']);
    }

    public function testResolveWithoutQueryStringPrefersPlainCanonical(): void
    {
        $salesChannelId = Uuid::randomHex();

        $connection = $this->createMock(Connection::class);
        $firstResult = FakeResultFactory::createResult([
            [
                'id' => Uuid::randomHex(),
                'salesChannelId' => $salesChannelId,
                'isCanonical' => true,
                'pathInfo' => '/detail/plain',
                'seoPathInfo' => 'Main-product/SWDEMO10001',
            ],
            [
                'id' => Uuid::randomHex(),
                'salesChannelId' => $salesChannelId,
                'isCanonical' => true,
                'pathInfo' => '/detail/query',
                'seoPathInfo' => 'Main-product/SWDEMO10001?test=123',
            ],
        ], $connection);
        $secondResult = FakeResultFactory::createResult([], $connection);

        $connection->method('executeQuery')->willReturn($firstResult, $secondResult);
        $connection->method('getDatabasePlatform')->willReturn($this->createMock(AbstractPlatform::class));

        $seoResolver = new SeoResolver($connection);

        $resolved = $seoResolver->resolve(Uuid::randomHex(), $salesChannelId, 'Main-product/SWDEMO10001');

        static::assertNotEmpty($resolved);

        static::assertSame('/detail/plain', $resolved['pathInfo']);
        static::assertArrayHasKey('seoPathInfo', $resolved);
        static::assertSame('Main-product/SWDEMO10001', $resolved['seoPathInfo']);
        static::assertTrue((bool) $resolved['isCanonical']);
    }

    public function testResolveWithPlainCanonicalAndQueryStringDoesNotSetCanonicalPathInfo(): void
    {
        $salesChannelId = Uuid::randomHex();

        $connection = $this->createMock(Connection::class);
        $firstResult = FakeResultFactory::createResult([
            [
                'id' => Uuid::randomHex(),
                'salesChannelId' => $salesChannelId,
                'isCanonical' => true,
                'pathInfo' => '/detail/plain',
                'seoPathInfo' => 'Main-product/SWDEMO10001',
            ],
        ], $connection);
        $secondResult = FakeResultFactory::createResult([], $connection);

        $connection->method('executeQuery')->willReturn($firstResult, $secondResult);
        $connection->method('getDatabasePlatform')->willReturn($this->createMock(AbstractPlatform::class));

        $seoResolver = new SeoResolver($connection);

        $resolved = $seoResolver->resolveWithQueryString(Uuid::randomHex(), $salesChannelId, 'Main-product/SWDEMO10001', 'utm=123');

        static::assertSame('/detail/plain', $resolved['pathInfo']);
        static::assertTrue((bool) $resolved['isCanonical']);
        static::assertArrayNotHasKey('canonicalPathInfo', $resolved);
    }

    public function testResolveWithFlagQueryStringDoesNotSetCanonicalPathInfo(): void
    {
        $salesChannelId = Uuid::randomHex();

        $connection = $this->createMock(Connection::class);
        $firstResult = FakeResultFactory::createResult([
            [
                'id' => Uuid::randomHex(),
                'salesChannelId' => $salesChannelId,
                'isCanonical' => true,
                'pathInfo' => '/detail/flag',
                'seoPathInfo' => 'Latest-Product/SW10005?test12345',
            ],
        ], $connection);
        $secondResult = FakeResultFactory::createResult([], $connection);

        $connection->method('executeQuery')->willReturn($firstResult, $secondResult);
        $connection->method('getDatabasePlatform')->willReturn($this->createMock(AbstractPlatform::class));

        $seoResolver = new SeoResolver($connection);

        $resolved = $seoResolver->resolveWithQueryString(Uuid::randomHex(), $salesChannelId, 'Latest-Product/SW10005', 'test12345=');

        static::assertSame('/detail/flag', $resolved['pathInfo']);
        static::assertTrue((bool) $resolved['isCanonical']);
        static::assertArrayNotHasKey('canonicalPathInfo', $resolved);
    }

    private function getMockConnection(string $salesChannelId, bool $isCanonical, string $pathInfo): Connection&MockObject
    {
        $mock = $this->createMock(Connection::class);
        $firstResult = FakeResultFactory::createResult([[
            'id' => Uuid::randomHex(),
            'salesChannelId' => $salesChannelId,
            'isCanonical' => $isCanonical,
            'pathInfo' => $pathInfo,
        ]], $mock);
        $canonicalResult = FakeResultFactory::createResult([[
            'id' => Uuid::randomHex(),
            'isCanonical' => $isCanonical,
            'seoPathInfo' => $pathInfo,
        ]], $mock);

        $mock
            ->method('executeQuery')
            ->willReturn($firstResult, $canonicalResult);
        $mock
            ->method('getDatabasePlatform')
            ->willReturn($this->createMock(AbstractPlatform::class));

        return $mock;
    }
}
