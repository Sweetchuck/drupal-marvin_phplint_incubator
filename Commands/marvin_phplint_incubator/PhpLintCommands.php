<?php

declare(strict_types = 1);

namespace Drush\Commands\marvin_phplint_incubator;

use Drupal\marvin\Utils as MarvinUtils;
use Drupal\marvin_incubator\CommandsBaseTrait;
use Drush\Commands\marvin\CommandsBase;
use Robo\Collection\CollectionBuilder;
use Robo\Contract\TaskInterface;
use Sweetchuck\Robo\Git\GitTaskLoader;
use Sweetchuck\Robo\PhpLint\PhpLintTaskLoader;
use Sweetchuck\Utils\ArrayFilterInterface;
use Sweetchuck\Utils\Filter\ArrayFilterEnabled;
use Symfony\Component\Console\Input\InputInterface;

class PhpLintCommands extends CommandsBase {

  use CommandsBaseTrait;
  use GitTaskLoader;
  use PhpLintTaskLoader;

  /**
   * @hook on-event marvin:git-hook:pre-commit
   */
  public function onEventMarvinGitHookPreCommit(InputInterface $input): array {
    $package = $this->normalizeManagedDrupalExtensionName($input->getArgument('packagePath'));

    return [
      'marvin.lint.phpcs' => [
        'weight' => -210,
        'task' => $this->lintPhp([$package['name']]),
      ],
    ];
  }

  /**
   * @hook on-event marvin:lint
   */
  public function onEventMarvinLint(InputInterface $input): array {
    return [
      'marvin.lint.phpcs' => [
        'weight' => -210,
        'task' => $this->lintPhp($input->getArgument('packages')),
      ],
    ];
  }

  /**
   * Runs PHP lint.
   *
   * @command marvin:lint:php
   * @bootstrap none
   *
   * @marvinArgPackages packages
   */
  public function lintPhp(array $packages): CollectionBuilder {
    $packages = array_intersect_key(
      $this->getManagedDrupalExtensions(),
      array_flip($packages),
    );

    $cb = $this->collectionBuilder();

    $phpVariants = $this->getPhpVariants();

    foreach ($packages as $package) {
      $cb->addTask($this->getTaskLintPhpExtension($package, $phpVariants));
    }

    return $cb;
  }

  /**
   * @phpstan-param array<string, marvin-incubator-managed-drupal-extension> $package
   */
  protected function getTaskLintPhpExtension(array $package, array $phpVariants): TaskInterface {
    $fileListerCommand = $this->getFileListerCommand($package['name'], $package['path']);

    $cb = $this->collectionBuilder();
    foreach ($phpVariants as $phpVariant) {
      $phpExecutable = $phpVariant['binDir'] ?: '/usr/bin';
      $phpExecutable .= '/' . ($phpVariant['phpExecutable'] ?: 'php');

      $cb->addTask(
        $this
          ->taskPhpLintFiles()
          ->setWorkingDirectory($package['path'])
          ->setFileListerCommand($fileListerCommand)
          ->setPhpExecutable($phpExecutable)
      );
    }

    return $cb;
  }

  protected function getFileListerCommand(string $packageName, string $packagePath): string {
    // @todo PHP lint - Configurable file name patterns on per package basis.
    $fileListerCommand = sprintf('cd %s && git ls-files -z', escapeshellarg($packagePath));

    $fileListerCommand .= ' --';
    foreach ($this->getPhpFileNamePatterns() as $fileNamePattern) {
      $fileListerCommand .= ' ' . escapeshellarg($fileNamePattern);
    }

    foreach ($this->getExcludePatterns($packageName) as $pattern) {
      $fileListerCommand .= ' ' . escapeshellarg(":!:$pattern");
    }

    return $fileListerCommand;
  }

  protected function getPhpVariants(): array {
    $phpVariants = (array) $this->getConfig()->get('marvin.php.variant');

    return array_filter($phpVariants, $this->getPhpVariantFilter());
  }

  protected function getPhpVariantFilter(): ArrayFilterInterface {
    return new ArrayFilterEnabled();
  }

  /**
   * @return string[]
   */
  protected function getPhpExtensions(): array {
    $extensions = (array) $this->getConfig()->get('marvin.php.extension');

    return array_keys($extensions, TRUE, FALSE);
  }

  /**
   * @return string[]
   */
  protected function getPhpFileNamePatterns(): array {
    return MarvinUtils::prefixSuffixItems($this->getPhpExtensions(), '*.');
  }

  protected function getExcludePatterns(string $packageName): array {
    $patterns = (array) $this
      ->getConfig()
      ->get("marvin.managedDrupalExtension.package.$packageName.phpLint.exclude", []);

    return array_keys($patterns, TRUE, FALSE);
  }

}
