<?php

namespace BalBuf\DrupalLibrariesInstaller;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Script\ScriptEvents;
use Composer\EventDispatcher\Event;
use Composer\Package\Package;

class Plugin implements PluginInterface, EventSubscriberInterface {

  // property which contains drupal libraries in composer.json extra
  const EXTRA_PROP = 'drupal-libraries';
  // composer package type for libraries
  const TYPE = 'drupal-library';

  /**
   * Called when the composer plugin is activated.
   */
  public function activate(Composer $composer, IOInterface $io) {
    // no activation steps required
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
   * @param  Event  $event install/update event
   */
  public function install(Event $event) {
    // get composer object
    $composer = $event->getComposer();
    // get root package extra prop
    $extra = $composer->getPackage()->getExtra();

    // do we have any libraries listed?
    if (empty($extra[static::EXTRA_PROP]) || !is_array($extra[static::EXTRA_PROP])) {
      return;
    }

    // get some services
    $downloadManager = $composer->getDownloadManager();
    $installationManager = $composer->getInstallationManager();

    // install each library
    foreach ($extra[static::EXTRA_PROP] as $library => $url) {
      // create a virtual package for this library
      // we don't ask for a version number, so just use "1.0.0" so the package is considered stable
      $package = new Package(static::TYPE . '/' . $library, '1.0.0', $url);
      // all URLs are assumed to be zips (for now)
      $package->setDistType('zip');
      $package->setDistUrl($url);
      $package->setType(static::TYPE);
      // let composer download and unpack the library for us!
      $downloadManager->download($package, $installationManager->getInstallPath($package));
    }
  }

}
