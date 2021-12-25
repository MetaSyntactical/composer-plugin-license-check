<?php

declare(strict_types=1);

namespace Metasyntactical\Composer\LicenseCheck;

final class ComposerConfig
{
    private array $whitelist;
    private array $blacklist;
    private array $whitelistedPackages;

    /**
     * @psalm-param array{whitelist?: list<mixed>, blacklist?: list<mixed>, whitelisted-packages?: list<mixed>} $options
     */
    public function __construct(array $options)
    {
        $this->whitelist = array_filter(
            $options['whitelist'] ?? [],
            'is_string',
        );
        $this->blacklist = array_filter(
            $options['blacklist'] ?? [],
            'is_string',
        );
        $this->whitelistedPackages = array_filter(
            $options['whitelisted-packages'] ?? [],
            'is_string',
        );
    }

    public function whitelist(): array
    {
        return $this->whitelist;
    }

    public function blacklist(): array
    {
        return $this->blacklist;
    }

    public function whitelistedPackages(): array
    {
        return $this->whitelistedPackages;
    }
}
