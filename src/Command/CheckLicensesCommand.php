<?php

declare(strict_types=1);

namespace Metasyntactical\Composer\LicenseCheck\Command;

use Composer\Command\BaseCommand;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackageInterface;
use Composer\Package\PackageInterface;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Repository\RepositoryInterface;
use Metasyntactical\Composer\LicenseCheck\ComposerConfig;
use Metasyntactical\Composer\LicenseCheck\LicenseCheckPlugin;
use RuntimeException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class CheckLicensesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setName('check-licenses')
            ->setDescription('Validate licenses of installed packages against specified white- and blacklists.')
            ->setDefinition([
                new InputOption('format', 'f', InputOption::VALUE_REQUIRED, 'Format of the output: text or json', 'text'),
                new InputOption('no-dev', null, InputOption::VALUE_NONE, 'Disables search in require-dev packages.'),
            ])
            ->setHelp(<<<EOT
The check-licenses command displays detailed information about the licenses of
the installed packages and whether they are allowed or forbidden to be used in
the root project.
EOT
            )
        ;
    }

    /**
     * @psalm-return 0|1
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $composer = $this->getComposer();
        if ($composer === null) {
            throw new \LogicException('Composer not found. Maybe the application is not correctly instantiated?');
        }

        $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'check-licenses', $input, $output);
        $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

        $root = $composer->getPackage();
        $repo = $composer->getRepositoryManager()->getLocalRepository();

        if ($input->getOption('no-dev')) {
            $packages = $this->filterRequiredPackages($repo, $root);
        } else {
            /** @psalm-var list<CompletePackageInterface> $additionalPackages */
            $additionalPackages = $repo->getPackages();
            $packages = $this->appendPackages($additionalPackages, []);
        }

        ksort($packages);
        $io = $this->getIO();

        $packagesInfo = $this->calculatePackagesInfo($root, array_values($packages));
        $violationFound = false;

        $format = $input->getOption('format');
        assert(is_string($format));

        switch ($format) {
            case 'text':
                $io->write('Name: <comment>' . $packagesInfo['name'] . '</comment>');
                $io->write('Version: <comment>' . $packagesInfo['version'] . '</comment>');
                $io->write('Licenses: <comment>' . (implode(', ', $packagesInfo['license']) ?: 'none') . '</comment>');
                $io->write('Dependencies:');
                $io->write('');

                $table = new Table($output);
                $table->setStyle('compact');
                $style = $table->getStyle();
                if (method_exists($style, 'setVerticalBorderChars')) {
                    $style->setVerticalBorderChars('', '');
                }
                if (method_exists($style, 'setVerticalBorderChar')) {
                    $style->setVerticalBorderChar('');
                }
                $table->getStyle()->setCellRowContentFormat('%s  ');
                $table->setHeaders(['Name', 'Version', 'License', 'Allowed to Use?']);
                foreach ($packagesInfo['dependencies'] as $dependencyName => $dependency) {
                    $table->addRow([
                        $dependencyName,
                        $dependency['version'],
                        implode(', ', $dependency['license']) ?: 'none',
                        $dependency['allowed_to_use'] ? 'yes' : 'no' . ($dependency['whitelisted'] ? ' (whitelisted)' : ''),
                    ]);
                    $violationFound = $violationFound || (!$dependency['allowed_to_use'] && !$dependency['whitelisted']);
                }
                $table->render();
                break;

            case 'json':
                foreach ($packagesInfo['dependencies'] as $dependency) {
                    $violationFound = $violationFound || !$dependency['allowed_to_use'];
                }
                $io->write(JsonFile::encode($packagesInfo));
                break;

            default:
                throw new RuntimeException(
                    sprintf('Unsupported format "%s". See help for supported formats.', $format)
                );
        }

        return (int) $violationFound;
    }

    /**
     * @psalm-param list<CompletePackageInterface> $packages
     * @psalm-return array{
     *                  name: string,
     *                  version: string,
     *                  license: list<string>,
     *                  dependencies: array<string, array{
     *                      version: string,
     *                      license: list<string>,
     *                      allowed_to_use: bool,
     *                      whitelisted: bool
     *                  }>
     *               }
     */
    private function calculatePackagesInfo(RootPackageInterface $rootPackage, array $packages): array
    {
        $dependencies = [];
        foreach ($packages as $package) {
            $dependencies[$package->getPrettyName()] = $this->calculatePackageInfo($rootPackage, $package);
        }

        /** @psalm-var list<string> $rootLicense */
        $rootLicense = $rootPackage->getLicense();

        return [
            'name' => $rootPackage->getPrettyName(),
            'version' => $rootPackage->getFullPrettyVersion(),
            'license' => $rootLicense,
            'dependencies' => $dependencies,
        ];
    }

    private function getConfig(RootPackageInterface $package): ComposerConfig
    {
        $config = $package->getExtra()[LicenseCheckPlugin::PLUGIN_PACKAGE_NAME] ?? [];
        assert(is_array($config));
        /** @psalm-var array{whitelist?: list<mixed>, blacklist?: list<mixed>, whitelisted-packages?: list<mixed>} $config */

        return new ComposerConfig($config);
    }

    /**
     * @psalm-return array{version: string, license: list<string>, allowed_to_use: bool, whitelisted: bool}
     */
    private function calculatePackageInfo(RootPackageInterface $rootPackage, CompletePackageInterface $package): array
    {
        $allowedToUse = true;
        $whitelisted = false;

        $config = $this->getConfig($rootPackage);

        $whitelist = $config->whitelist();
        $blacklist = $config->blacklist();
        $whitelistedPackages = $config->whitelistedPackages();

        if ($blacklist) {
            $allowedToUse = !array_intersect($package->getLicense(), $blacklist);
        }
        if ($allowedToUse && $whitelist) {
            $allowedToUse = !!array_intersect($package->getLicense(), $whitelist);
        }
        if (!$allowedToUse && array_key_exists($package->getPrettyName(), $whitelistedPackages)) {
            $whitelisted = true;
        }

        if ($package->getName() === 'metasyntactical/composer-plugin-license-check') {
            $allowedToUse = true;
        }

        /** @psalm-var list<string> $packageLicense */
        $packageLicense = $package->getLicense();

        return [
            'version' => $package->getFullPrettyVersion(),
            'license' => $packageLicense,
            'allowed_to_use' => $allowedToUse,
            'whitelisted' => $whitelisted,
        ];
    }

    /**
     * @psalm-param array<string, CompletePackageInterface> $bucket
     * @psalm-return array<string, CompletePackageInterface>
     */
    private function filterRequiredPackages(
        RepositoryInterface $repo,
        PackageInterface $package,
        array $bucket = []
    ): array {
        $requires = array_keys($package->getRequires());

        $packageListNames = array_keys($bucket);
        /** @psalm-var list<CompletePackageInterface> $filteredPackages */
        $filteredPackages = array_filter(
            $repo->getPackages(),
            static function (PackageInterface $package) use ($requires, $packageListNames) {
                return in_array($package->getName(), $requires, true)
                    && !in_array($package->getName(), $packageListNames, true);
            }
        );

        $bucket = $this->appendPackages($filteredPackages, $bucket);

        foreach ($filteredPackages as $filteredPackage) {
            $bucket = $this->filterRequiredPackages($repo, $filteredPackage, $bucket);
        }

        return $bucket;
    }

    /**
     * @psalm-param list<CompletePackageInterface> $packages
     * @psalm-param array<string, CompletePackageInterface> $bucket
     * @psalm-return array<string, CompletePackageInterface>
     */
    public function appendPackages(array $packages, array $bucket): array
    {
        foreach ($packages as $package) {
            $bucket[$package->getName()] = $package;
        }

        return $bucket;
    }
}
