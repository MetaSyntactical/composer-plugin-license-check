<?php

declare(strict_types=1);

namespace Metasyntactical\Composer\LicenseCheck;

use PHPUnit\Framework\TestCase;

/**
 * @group unit
 * @covers \Metasyntactical\Composer\LicenseCheck\ComposerConfig
 */
final class ComposerConfigTest extends TestCase
{
    public function testEmptyConfig(): void
    {
        $config = new ComposerConfig([]);

        self::assertEquals([], $config->whitelist());
        self::assertEquals([], $config->blacklist());
        self::assertEquals([], $config->whitelistedPackages());
    }
}
