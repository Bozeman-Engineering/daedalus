<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * The walk every style store convergence makes over the two entity stores.
 *
 * A convergence differs in ONE thing: how it rewrites a keyed entry map
 * (convergeEntries()). Which variants and skins are visited, across which
 * revisions, which are left to the sync lane, and how a changed revision
 * is written back in place are the same for all of them, so they are
 * written once here.
 *
 * Shipped-unmodified variants and package layers are SKIPPED: the sync
 * lane owns them (their disk sources already speak the current grammar),
 * and an in-place rewrite would flip their hash to "modified" and cut
 * them off from future shipped updates. A skin's site layer is the
 * site's and converges either way.
 */
abstract class StyleStoreConvergenceBase implements StyleStoreConvergenceInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Converges one keyed entry map: a host map, or element-relative styles.
   *
   * @param array $entries
   *   The entries, keyed by style key (or by relative key, for a skin's
   *   defaults under one scope).
   * @param string $context
   *   The owner of the entries, for report lines.
   * @param string[] $lines
   *   Collected report lines; every change is reported through report().
   * @param bool $changed
   *   Set to TRUE when the entries were rewritten; never reset to FALSE.
   *
   * @return array
   *   The converged entries, equal to the input when nothing changed.
   */
  abstract protected function convergeEntries(array $entries, string $context, array &$lines, bool &$changed): array;

  /**
   * Reports one convergence line: collected for the caller AND logged.
   */
  protected function report(array &$lines, string $line): void {
    $lines[] = $line;
    $this->loggerFactory->get('daedalus_stylish')->notice($line);
  }

  /**
   * {@inheritdoc}
   */
  public function convergeVariants(): array {
    $lines = [];
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    assert($storage instanceof RevisionableStorageInterface);
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    foreach ($storage->loadMultiple($ids) as $variant) {
      /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant */
      if ($variant->isShipped() && !$variant->isModified()) {
        continue;
      }
      $revision_ids = array_keys($storage->getQuery()->allRevisions()->accessCheck(FALSE)->condition('id', $variant->id())->execute());
      foreach ($revision_ids as $revision_id) {
        /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $revision */
        $revision = $storage->loadRevision($revision_id);
        $changed = FALSE;
        $styles = $this->convergeEntries($revision->getStyles(), sprintf('Variant "%s" (revision %s)', $variant->getMachineName(), $revision_id), $lines, $changed);
        if ($changed) {
          $revision->setStyles($styles);
          $revision->setNewRevision(FALSE);
          $revision->save();
        }
      }
    }
    return $lines;
  }

  /**
   * {@inheritdoc}
   */
  public function convergeSkins(): array {
    $lines = [];
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    assert($storage instanceof RevisionableStorageInterface);
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    foreach ($storage->loadMultiple($ids) as $skin) {
      /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin */
      // A shipped unmodified package layer is left to the sync lane; the
      // site layer over it is the site's and is converged either way.
      $package_swept = !($skin->isShipped() && !$skin->isModified());
      $revision_ids = array_keys($storage->getQuery()->allRevisions()->accessCheck(FALSE)->condition('id', $skin->id())->execute());
      foreach ($revision_ids as $revision_id) {
        /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $revision */
        $revision = $storage->loadRevision($revision_id);
        $changed = FALSE;
        $layers = ['site' => [$revision->siteDefaults(), $revision->setSiteDefaults(...)]];
        if ($package_swept) {
          $layers['package'] = [$revision->packageDefaults(), $revision->setPackageDefaults(...)];
        }
        foreach ($layers as $layer => [$defaults, $write]) {
          $layer_changed = FALSE;
          foreach ($defaults as $scope => $relatives) {
            if (is_array($relatives)) {
              $defaults[$scope] = $this->convergeEntries($relatives, sprintf('Skin "%s" (revision %s) %s defaults %s', $skin->getMachineName(), $revision_id, $layer, $scope), $lines, $layer_changed);
            }
          }
          if ($layer_changed) {
            $write($defaults);
            $changed = TRUE;
          }
        }
        if ($changed) {
          $revision->setNewRevision(FALSE);
          $revision->save();
        }
      }
    }
    return $lines;
  }

}
