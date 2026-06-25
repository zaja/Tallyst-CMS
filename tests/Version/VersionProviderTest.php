<?php

namespace App\Tests\Version;

use App\Version\VersionProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VersionProviderTest extends TestCase
{
    /**
     * Display normalisation for the Packagist/create-project path. InstalledVersions is static, so
     * we stub the raw resolution via a subclass and assert the display rules.
     *
     */
    #[DataProvider('rawVersions')]
    public function testGetCoreVersionNormalisesForDisplay(?string $raw, string $expected): void
    {
        $provider = new class($raw) extends VersionProvider {
            public function __construct(private readonly ?string $raw)
            {
            }

            protected function resolveCoreVersion(): ?string
            {
                return $this->raw;
            }
        };

        self::assertSame($expected, $provider->getCoreVersion());
    }

    /** @return iterable<string, array{?string, string}> */
    public static function rawVersions(): iterable
    {
        yield 'tagged with v' => ['v1.1.0', 'v1.1.0'];
        yield 'tagged without v gets one' => ['1.1.0', 'v1.1.0'];
        yield 'null (no metadata) → dev' => [null, '(dev)'];
        yield 'empty → dev' => ['', '(dev)'];
        yield 'branch dev-main → dev' => ['dev-main', '(dev)'];
    }

    /** In this repo (a dev git clone, no Packagist metadata for the package) it must NOT throw. */
    public function testRealResolutionFallsBackGracefully(): void
    {
        $version = (new VersionProvider())->getCoreVersion();

        // Either a real tag (if ever run from a Packagist install) or the dev fallback — never empty,
        // never an exception.
        self::assertNotSame('', $version);
        self::assertMatchesRegularExpression('/^(v\d|\(dev\))/', $version);
    }
}
