<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\DesignSystem;

use Drupal\Core\Extension\ExtensionDiscovery;

/**
 * The one door to core's per-process extension scan cache.
 *
 * ExtensionDiscovery remembers every directory it scanned for the life
 * of the process (a protected static with no reset), so a module
 * directory created mid-request is invisible to the installer: its
 * `extension.list.module->reset()->getList()` re-reads the cached scan
 * and reports the module missing. The package ship creates a module and
 * installs it in one command, so it forgets the scans first; a subclass
 * is the only way to reach the static without reflection.
 */
final class ExtensionScanReset extends ExtensionDiscovery {

  /**
   * Forgets every scanned directory; the next scan reads disk again.
   */
  public static function forget(): void {
    static::$files = [];
  }

}
