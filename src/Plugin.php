<?php

namespace BalBuf\DrupalLibrariesInstaller;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackage;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Package\Package;
use Composer\Util\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\Glob;

class Plugin implements PluginInterface, EventSubscriberInterface {

  /**
   * The installed-libraries.json lock file schema version.
   *
   * @var string
   */
  const SCHEMA_VERSION = '1.0';

  /**
   * The composer package name.
   */
  const PACKAGE_NAME = 'balbuf/drupal-libraries-installer';

  /**
   * @var Composer $composer
   */
  protected $composer;

  /**
   * @var IOInterface $io
   */
  protected $io;

  /**
   * @var \Composer\Downloader\DownloadManager
   */
  protected $downloadManager;

  /**
   * @var \Composer\Installer\InstallationManager
   */
  protected $installationManager;

  /**
   * @var \Composer\Util\Filesystem
   */
  protected $fileSystem;

  /**
   * Called when the composer plugin is activated.
   */
  public function activate(Composer $composer, IOInterface $io) {
    $this->composer = $composer;

    $this->io = $io;
    $this->fileSystem = new Filesystem();
    $this->downloadManager = $composer->getDownloadManager();
    $this->installationManager = $composer->getInstallationManager();
  }

  /**
   * Instruct the plugin manager to subscribe us to these events.
   */
  public static function getSubscribedEvents() {
    return [
      ScriptEvents::POST_INSTALL_CMD => 'install',
      ScriptEvents::POST_UPDATE_CMD => 'install',
    ];
  }

  /**
   * Upon running composer install or update, install the drupal libraries.
   *
   * @param Event $event  install/update event
   *
   * @throws \Exception
   */
  public function install(Event $event) {
    $composer = $event->getComposer();

    $installed_json_file = new JsonFile($this->getInstalledJsonPath(), NULL, $this->io);

    $installed = NULL;
    if ($installed_json_file->exists()) {
      $installed = $installed_json_file->read();
    }

    // Reset if the schema doesn't match the current version.
    if (!isset($installed['schema-version']) || $installed['schema-version'] !== static::SCHEMA_VERSION) {
      $installed = [
        'schema-version' => static::SCHEMA_VERSION,
        'installed' => [],
      ];
    }

    $applied_drupal_libraries = $installed['installed'];

    // Process the root package first.
    $root_package = $composer->getPackage();
    $processed_drupal_libraries = $this->processPackage([], $applied_drupal_libraries, $root_package);

    // Process libraries declared in dependencies.
    if (!empty($root_package->getExtra()['drupal-libraries-dependencies'])) {
      $allowed_dependencies = $root_package->getExtra()['drupal-libraries-dependencies'];
      $local_repo = $composer->getRepositoryManager()->getLocalRepository();
      foreach ($local_repo->getCanonicalPackages() as $package) {
        if (
          $allowed_dependencies === TRUE ||
          (is_array($allowed_dependencies) && in_array($package->getName(), $allowed_dependencies, TRUE))
        ) {
          if (!empty($package->getExtra()['drupal-libraries'])) {
            $processed_drupal_libraries += $this->processPackage(
              $processed_drupal_libraries,
              $applied_drupal_libraries,
              $package
            );
          }
        }
      }
    }

    // Remove unused libraries from disk before attempting to download new ones.
    // Avoids the edge-case where the removed folder happens to be the same as the one where the new one is being
    // installed to.
    $removed_libraries = array_diff_key($applied_drupal_libraries, $processed_drupal_libraries);
    if ($removed_libraries) {
      $this->removeUnusedLibraries($removed_libraries);
    }

    // Attempt to download the libraries.
    $this->downloadLibraries($processed_drupal_libraries, $applied_drupal_libraries);

    // Write the lock file to disk.
    if ($this->io->isDebug()) {
      $this->io->write(static::PACKAGE_NAME . ':');
      $this->io->write(
        sprintf('  - Writing to %s', $this->fileSystem->normalizePath($installed_json_file->getPath()))
      );
    }

    $installed['installed'] = $processed_drupal_libraries;
    $installed_json_file->write($installed);
  }

  /**
   * Drupal library processor.
   *
   * Inspired by https://github.com/civicrm/composer-downloads-plugin
   *
   * @param array $processed_drupal_libraries
   * @param array $drupal_libraries
   *   Applied drupal libraries.
   * @param \Composer\Package\PackageInterface $package
   *
   * @return array
   *   The processed packages.
   */
  protected function processPackage($processed_drupal_libraries, $drupal_libraries, $package) {
    $extra = $package->getExtra();

    if (empty($extra['drupal-libraries']) || !is_array($extra['drupal-libraries'])) {
      return $processed_drupal_libraries;
    }

    // Install each library
    foreach ($extra['drupal-libraries'] as $library => $library_definition) {
      $ignore_patterns = [];
      $sha1checksum = NULL;
      if (is_string($library_definition)) {
        // Simple format.
        $url = $library_definition;
        list($version, $distribution_type) = $this->guessDefaultsFromUrl($url);
      }
      else {
        if (empty($library_definition['url'])) {
          throw new \LogicException("The drupal-library '$library' does not contain a valid URL.");
        }
        $url = $library_definition['url'];
        list($version, $distribution_type) = $this->guessDefaultsFromUrl($url);
        $version = $library_definition['version'] ?? $version;
        $distribution_type = $library_definition['type'] ?? $distribution_type;
        $ignore_patterns = $library_definition['ignore'] ?? $ignore_patterns;
        $sha1checksum = $library_definition['shasum'] ?? $sha1checksum;
      }

      if (isset($processed_drupal_libraries[$library])) {
        // Only the first declaration of the library is ever used. This ensures that the root package always
        // acts as the source of truth over what version of a library is installed.
        $old_definition = $processed_drupal_libraries[$library];
        if ($this->io->isDebug()) {
          $this->io->write(
            sprintf(
              '<warning>Library %s already declared by %s, (%s also attempts to declare one). Skipping...</warning>',
              $library . ' [' . $old_definition['url'] . ']',
              $old_definition['package'],
              "$library [$url]"
            )
          );
        }
      }
      else {
        // Track installed libraries in the package info in installed-libraries.json
        $applied_library = [
          'version' => $version,
          'url' => $url,
          'type' => $distribution_type,
          'ignore' => $ignore_patterns,
          'package' => $package->getName(),
        ];
        if (isset($sha1checksum)) {
          $applied_library['shasum'] = $sha1checksum;
        }

        $processed_drupal_libraries[$library] = $applied_library;
      }
    }

    return $processed_drupal_libraries;
  }

  /**
   * Remove old unused libraries from disk.
   *
   * @param array $old_libraries
   */
  protected function removeUnusedLibraries($old_libraries) {
    foreach ($old_libraries as $library_name => $library_definition) {
      $library_package = $this->getLibraryPackage($library_name, $library_definition);

      $this->downloadManager->remove(
        $library_package,
        $this->installationManager->getInstallPath($library_package)
      );
    }
  }

  /**
   * Download library assets if required.
   *
   * @param array $processed_libraries
   *   The processed libraries.
   */
  protected function downloadLibraries($processed_libraries, $applied_drupal_libraries) {
    foreach ($processed_libraries as $library_name => $processed_library) {
      $library_package = $this->getLibraryPackage($library_name, $processed_library);

      $ignore_patterns = $processed_library['ignore'];
      $install_path = $this->installationManager->getInstallPath($library_package);
      if (
        (
          !isset($applied_drupal_libraries[$library_name]) ||
          $applied_drupal_libraries[$library_name] !== $processed_library
        ) ||
        !file_exists($install_path)
      ) {
        // Download if the package:
        // - wasn't in the lock file.
        // - doesn't match what is in the lock file.
        // - doesn't exist on disk.
        $this->downloadPackage($library_package, $install_path, $ignore_patterns);
      }
    }
  }

  /**
   * Downloads a library package to disk.
   *
   * @param \Composer\Package\Package $library_package
   *   The library package.
   * @param string $install_path
   *   The package install path.
   * @param array $ignore_patterns
   *   File patterns to ignore.
   */
  protected function downloadPackage(Package $library_package, $install_path, $ignore_patterns) {
    // Let composer download and unpack the library for us!
    $this->downloadManager->download($library_package, $install_path);

    // Delete files/folders according to the ignore pattern(s).
    if ($ignore_patterns) {
      $finder = new Finder();

      $patterns = [];
      foreach ($ignore_patterns as $ignore_pattern) {
        $patterns[$ignore_pattern] = Glob::toRegex($ignore_pattern);
      }

      $finder
        ->in($install_path)
        ->ignoreDotFiles(FALSE)
        ->ignoreVCS(FALSE)
        ->ignoreUnreadableDirs()
        // Custom filter pattern for matching files and folders.
        ->filter(
          function ($file) use ($patterns) {
            /** @var \SplFileInfo $file */
            $file_pathname = $file->getRelativePathname();
            if ('\\' === \DIRECTORY_SEPARATOR) {
              // Normalize the path name.
              $file_pathname = str_replace('\\', '/', $file_pathname);
            }
            foreach ($patterns as $pattern) {
              if (preg_match($pattern, $file_pathname)) {
                return TRUE;
              }
            }

            return FALSE;
          }
        );

      foreach ($finder as $file) {
        $file_pathname = $this->fileSystem->normalizePath($file->getPathname());
        $this->io->writeError("    - Removing <info>$file_pathname</info>");
        $this->fileSystem->remove($file_pathname);
      }
    }
  }

  /**
   * Get a drupal-library package object from its definition.
   *
   * @param $library_name
   * @param $library_definition
   *
   * @return \Composer\Package\Package
   */
  protected function getLibraryPackage($library_name, $library_definition) {
    $library_package_name = 'drupal-library/' . $library_name;
    $library_package = new Package(
      $library_package_name, $library_definition['version'], $library_definition['version']
    );
    $library_package->setDistType($library_definition['type']);
    $library_package->setDistUrl($library_definition['url']);
    $library_package->setInstallationSource('dist');
    if (isset($library_definition['shasum'])) {
      $library_package->setDistSha1Checksum($library_definition['shasum']);
    }
    $library_package->setType('drupal-library');

    return $library_package;
  }

  /**
   * Guess the default version and distribution type from the URL.
   *
   * @param string $url
   *   The URL to process.
   *
   * @return array
   *   The version and distribution type.
   */
  protected function guessDefaultsFromUrl($url) {
    // Default to version 1.0.0 so it's considered stable.
    $version = '1.0.0';
    // Default to zips.
    $distribution_type = 'zip';
    // Attempt to guess the version number and type from the URL.
    $match = [];
    if (preg_match('/(v?[\d.]{2,})\.(zip|rar|tgz|tar(?:\.(gz|bz2))?)$/', $url, $match)) {
      $version = $match[1];
      $distribution_type = explode('.', $match[2])[0];
      if ($distribution_type === 'tgz') {
        $distribution_type = 'tar';
      }
    }

    return [$version, $distribution_type];
  }

  /**
   * Get the current installed-libraries json file path.
   *
   * @return string
   */
  protected function getInstalledJsonPath() {
    // Alternative approach.
    // $installed_json_file = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'installed-libraries.json';

    /** @var \Composer\Package\CompletePackage $installer_library_package */
    $installer_library_package = $this->composer->getRepositoryManager()->getLocalRepository()->findPackage(
      static::PACKAGE_NAME,
      '*'
    );

    if (!$installer_library_package || !$installer_library_package instanceof CompletePackage) {
      // This should never happen.
      throw new \LogicException('Could not resolve the %s package!', static::PACKAGE_NAME);
    }

    return $this->installationManager->getInstallPath($installer_library_package) . '/installed-libraries.json';
  }

}
