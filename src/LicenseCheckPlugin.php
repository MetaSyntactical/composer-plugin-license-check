<?php declare(strict_types=1);

namespace Metasyntactical\Composer\LicenseCheck;

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\CompletePackageInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable as CapableInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Metasyntactical\Composer\LicenseCheck\Command\CommandProvider;

final class LicenseCheckPlugin
    implements PluginInterface, CapableInterface, EventSubscriberInterface
{
    private const PLUGIN_PACKAGE_NAME = 'metasyntactical/composer-plugin-license-check';

    private Composer $composer;

    private IOInterface $io;

    private array $licenseWhitelist = [];

    private array $licenseBlacklist = [];

    private array $whitelistedPackages = [];

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->composer = $composer;
        $this->io = $io;

        $extraConfigKey = self::PLUGIN_PACKAGE_NAME;
        $rootPackage = $composer->getPackage();

        if (array_key_exists($extraConfigKey, $rootPackage->getExtra())
            && is_array($rootPackage->getExtra()[$extraConfigKey])
        ) {
            if (array_key_exists('whitelist', $rootPackage->getExtra()[$extraConfigKey])
                && in_array(gettype($rootPackage->getExtra()[$extraConfigKey]['whitelist']), ['string', 'array'], true)
            ) {
                $this->licenseWhitelist = (array) $rootPackage->getExtra()[$extraConfigKey]['whitelist'];
            }
            if (array_key_exists('blacklist', $rootPackage->getExtra()[$extraConfigKey])
                && in_array(gettype($rootPackage->getExtra()[$extraConfigKey]['blacklist']), ['string', 'array'], true)
            ) {
                $this->licenseBlacklist = (array) $rootPackage->getExtra()[$extraConfigKey]['blacklist'];
            }
            if (array_key_exists('whitelisted-packages', $rootPackage->getExtra()[$extraConfigKey])
                && in_array(gettype($rootPackage->getExtra()[$extraConfigKey]['whitelisted-packages']), ['array'], true)
            ) {
                $this->whitelistedPackages = (array) $rootPackage->getExtra()[$extraConfigKey]['whitelisted-packages'];
            }
        }
    }

    public function deactivate(Composer $composer, IOInterface $io)
    {}

    public function uninstall(Composer $composer, IOInterface $io)
    {}

    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class
        ];
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::COMMAND => [['handleEventAndOutputDebugMessage', 101]],
            PackageEvents::POST_PACKAGE_INSTALL => [['handleEventAndCheckLicense', 100]],
            PackageEvents::POST_PACKAGE_UPDATE => [['handleEventAndCheckLicense', 100]],
        ];
    }

    public function handleEventAndOutputDebugMessage(CommandEvent $event): void
    {
        if (!in_array($event->getCommandName(), ['install', 'update'], true)) {
            return;
        }
        if (!$this->io->isVerbose()) {
            return;
        }

        $this->io->writeError('<info>The Metasyntactical LicenseCheck Plugin has been enabled.</info>');
    }

    public function handleEventAndCheckLicense(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        $operationType = (method_exists($operation, 'getJobType')) ? $operation->getJobType() : $operation->getOperationType();
        if (!in_array($operationType, ['install', 'update'], true)) {
            return;
        }

        $package = null;
        if ($operationType === 'install') {
            /** @var InstallOperation $operation */
            $package = $operation->getPackage();
        }
        if ($operationType === 'update') {
            /** @var UpdateOperation $operation */
            $package = $operation->getTargetPackage();
        }

        if ($package->getName() === self::PLUGIN_PACKAGE_NAME && $operationType === 'install') {
            $this->composer->getEventDispatcher()->addSubscriber($this);
            if ($event->getIO()->isVerbose()) {
                $event->getIO()->writeError('<info>The Metasyntactical LicenseCheck Plugin has been enabled.</info>');
            }
        }

        if ($package->getName() === self::PLUGIN_PACKAGE_NAME) {
            // Skip license check. It is assumed that the licence checker itself is
            // added to the dependencies on purpose and therefore the license the
            // license checker is provided with (MIT) is accepted.
            return;
        }

        $packageLicenses = [];
        if (is_a($package, CompletePackageInterface::class)) {
            /** @var CompletePackageInterface $package */
            $packageLicenses = $package->getLicense();
        }

        $allowedToUse = true;
        if ($allowedToUse && $this->licenseBlacklist) {
            $allowedToUse = !array_intersect($packageLicenses, $this->licenseBlacklist);
        }
        if ($allowedToUse && $this->licenseWhitelist) {
            $allowedToUse = !!array_intersect($packageLicenses, $this->licenseWhitelist);
        }

        if ($package->getName() === 'metasyntactical/composer-plugin-license-check') {
            $allowedToUse = true;
        }

        if (!$allowedToUse) {
            if (!array_key_exists($package->getPrettyName(), $this->whitelistedPackages)) {
                throw new LicenseNotAllowedException(
                    sprintf(
                        'ERROR: Licenses "%s" of package "%s" are not allowed to be used in the project. Installation failed.',
                        implode(', ', $packageLicenses),
                        $package->getPrettyName()
                    )
                );
            }
            $this->io->writeError(
                sprintf(
                    'WARNING: Licenses "%s" of package "%s" are not allowed to be used in the project but the package has been whitelisted.',
                    implode(', ', $packageLicenses),
                    $package->getPrettyName()
                )
            );
        }
    }
}
