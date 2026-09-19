<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\Core\Site\Settings;

/**
 * The distro lock: one spine fact about who may change shipped materials.
 *
 * A shipped material (a variant, swatch or animation synced from a
 * package's files, a stencil read from a file) is the package's design
 * vocabulary, and the package's files are its one author. On a consuming
 * site the lock HOLDS: the site cannot push into, edit or delete a shipped
 * material; it forks one instead ("Copy as my variant") or reverts toward
 * the disk. Letting a consuming site write into a shipped material would
 * fork the content away from the file and turn every future sync into a
 * conflict, so the lock protects consuming sites from drift.
 *
 * The package author is the other audience. Authoring happens on a
 * development site whose module directories are writable, with the site
 * as the canvas and drush (`daedalus-blueprint:stencil-ship`,
 * `daedalus_stylish:ship`) carrying the result back to disk. There the lock is in
 * the way, and the author lifts it with a settings.php flag:
 *
 * @code
 * $settings['daedalus_blueprint_developer'] = TRUE;
 * @endcode
 *
 * A settings flag, not a permission, because writable module directories
 * are an environment property: a dev site needs no role setup, and a
 * production administrator cannot lift a lock that exists to protect
 * production. The lock is consulted by every shipped-material gate on the
 * materials spine (daedalus_stylish's variant, swatch and animation managers and
 * blueprint-lane field handlers, the materials providers' `updatable`
 * record flag, the stencil module's read-only hint), so one flag opens
 * every gate at once. Roster records are cached: flipping the flag needs a
 * cache rebuild before the client sees `updatable` change.
 */
final class DistroLock {

  /**
   * The settings.php key that lifts the lock.
   */
  public const SETTING = 'daedalus_blueprint_developer';

  /**
   * Whether the lock holds: shipped materials are read-only on this site.
   *
   * @return bool
   *   TRUE on a consuming site (the default), FALSE on a development site
   *   that set `$settings['daedalus_blueprint_developer'] = TRUE`.
   */
  public function holds(): bool {
    return !Settings::get(self::SETTING, FALSE);
  }

}
