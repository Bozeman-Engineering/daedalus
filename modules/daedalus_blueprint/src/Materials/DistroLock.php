<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\Core\Site\Settings;

/**
 * The distro lock: one spine fact about who may change shipped materials.
 *
 * A shipped material (a variant, swatch, animation or font synced from a
 * package's files, a skin's package layer, a blueprint read from a file) is
 * the package's design vocabulary, and the package's files are its one
 * author. On a consuming site the lock HOLDS: the site cannot push into,
 * edit or delete a shipped material; it forks one instead ("Copy as my
 * variant", "Copy as my skin") or reverts toward the disk. Letting a
 * consuming site write into a shipped material would fork the content
 * away from the file and turn every future sync into a conflict, so the
 * lock protects consuming sites from drift. The skin is the one shipped
 * material a consuming site keeps authoring, because it has a second
 * layer: while the lock holds, every token gesture and every
 * update_default lands in the skin's site layer over the package's
 * (daedalus_stylish's SkinManager::writesSiteLayer()), which the sync
 * never writes and the ship never reads; a shipped token itself can be
 * neither deleted nor renamed there, only overridden.
 *
 * The package author is the other audience. Authoring happens on a
 * development site whose module directories are writable, with the site
 * as the canvas and drush (`daedalus-blueprint:design-system:ship`,
 * `daedalus-blueprint:blueprint-ship`) carrying the result back to disk.
 * There the lock is in the way, and the author lifts it with a
 * settings.php flag:
 *
 * @code
 * $settings['daedalus_blueprint_developer'] = TRUE;
 * @endcode
 *
 * A settings flag, not a permission, because writable module directories
 * are an environment property: a dev site needs no role setup, and a
 * production administrator cannot lift a lock that exists to protect
 * production. The lock is consulted by every shipped-material gate on the
 * materials spine (daedalus_stylish's variant, swatch, animation and font
 * managers, the skin's layer rule, the blueprint-lane field handlers, the
 * materials providers' `updatable` record flag, the blueprint library's
 * read-only hint), so one flag opens every gate at once: with it lifted a
 * token write, an update_default and a revision restore land in a shipped
 * skin's package layer, the layer `daedalus-blueprint:design-system:ship`
 * reads. Roster records are cached: flipping the flag needs a cache
 * rebuild before the client sees `updatable` change.
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
