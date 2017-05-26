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
use Composer\Plugin\PluginInterface;
use Metasyntactical\Composer\LicenseCheck\Command\CommandProvider;

final class LicenseCheckPlugin
    implements PluginInterface, CapableInterface, EventSubscriberInterface
{
    private const PLUGIN_PACKAGE_NAME = 'metasyntactical/composer-plugin-license-check';
    #
    # PluginInterface
    #

    public function activate(Composer $composer, IOInterface $io): void
    {
    }

    #
    # CapableInterface
    #

    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class
        ];
    }

    #
    # EventSubscriberInterface
    #
    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::POST_PACKAGE_INSTALL => [['handleEventAndCheckLicense', 0]],
            PackageEvents::POST_PACKAGE_UPDATE => [['handleEventAndCheckLicense', 0]],
        ];
    }

    public function handleEventAndCheckLicense(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        if (!in_array($operation->getJobType(), ['install', 'update'], true)) {
            return;
        }

        $package = null;
        if ($event->getOperation()->getJobType() === 'install') {
            /** @var InstallOperation $operation */
            $package = $operation->getPackage();
        }
        if ($event->getOperation()->getJobType() === 'update') {
            /** @var UpdateOperation $operation */
            $package = $operation->getTargetPackage();
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
        var_dump($package->getName());
        var_dump($package);
    }
}