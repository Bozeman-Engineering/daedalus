<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;

/**
 * Converges every stored style key onto the relative selector grammar.
 *
 * The suffix after a key's "::" is a relative selector in ONE canonical
 * spelling (RelativeSelector). The pseudo-part era spelled the children
 * as a trailing "::>" ("{key}::>", "{key}::.cls::>", "{key}::p::>"); no
 * parser reads that spelling any more, so every store carrying it is
 * rewritten here to the selector it means ("::> *", "::.cls > *",
 * "::p > *"): host style maps (the RelativeSelectorSweep, riding
 * daedalus_layout's transform spine), daedalus_variant styles (all
 * revisions, composition slot paths and fragment references included),
 * daedalus_skin defaults. The rewrite is
 * idempotent: a canonical key rewrites to itself and reports nothing.
 *
 * Shipped-unmodified variants and skins are SKIPPED: the sync lane owns
 * them (their disk sources were rewritten with the grammar), and an
 * in-place rewrite would flip their hash to "modified" and cut them off
 * from future shipped updates. ContainerPartHoist is the model, and this
 * convergence runs after it (tag priority), so a hoisted legacy children
 * entry converges in the same pass.
 */
class RelativeSelectorConvergence {

  /**
   * The legacy spellings this convergence retires, as the suffix reads.
   *
   * Each row is [the pattern over the "::" suffix, the replacement]: a
   * trailing "::>" after nothing, after a part, or after a tag. Kept as
   * a table on purpose: this is the ONE place the retired spelling is
   * still written down.
   */
  public const LEGACY_SPELLINGS = [
    ['/^::>$/', '::> *'],
    ['/^::(\.[A-Za-z0-9_-]+|[a-z][a-z0-9-]*)::>$/', '::$1 > *'],
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Reports one convergence line: collected for the caller AND logged.
   */
  protected function report(array &$lines, string $line): void {
    $lines[] = $line;
    $this->loggerFactory->get('daedalus_stylish')->notice($line);
  }

  /**
   * Converges one style key, map key or slot key to the canonical spelling.
   *
   * The root (everything before the first "::") never changes; the suffix
   * converges through the legacy table, then re-prints through the
   * grammar when it parses (a non-canonical spelling of a legal selector,
   * say doubled spaces, converges too). A suffix the grammar cannot read
   * is left untouched: the validator refuses it loudly, and a sweep must
   * never invent a meaning.
   *
   * @return string
   *   The converged key, the same string when nothing changed.
   */
  public static function convergeKey(string $key): string {
    $pos = strpos($key, RelativeSelector::SUFFIX);
    if ($pos === FALSE) {
      return $key;
    }
    $root = substr($key, 0, $pos);
    $suffix = substr($key, $pos);
    foreach (self::LEGACY_SPELLINGS as [$pattern, $replacement]) {
      $suffix = preg_replace($pattern, $replacement, $suffix);
    }
    $selector = RelativeSelector::parse(substr($suffix, strlen(RelativeSelector::SUFFIX)));
    return $root . ($selector === NULL ? $suffix : RelativeSelector::SUFFIX . $selector);
  }

  /**
   * Converges a whole host style map's keys and fragment references.
   *
   * @param array $map
   *   The style map.
   * @param string $context
   *   The map's host, for report lines.
   * @param string[] $lines
   *   Collected report lines.
   *
   * @return array|null
   *   The rewritten map, or NULL when nothing changed.
   */
  public function convergeStyleMap(array $map, string $context, array &$lines): ?array {
    $changed = FALSE;
    $result = $this->convergeEntries($map, $context, $lines, $changed);
    return $changed ? $result : NULL;
  }

  /**
   * Converges one keyed entry map (a host map, or element-relative styles).
   *
   * A converged key that collides with an entry already spelled
   * canonically keeps the canonical entry and merges the legacy one
   * beneath it per condition (the canonical spelling is the newer write).
   * Fragment references inside "variants" lists converge the same way.
   */
  protected function convergeEntries(array $entries, string $context, array &$lines, bool &$changed): array {
    $result = [];
    foreach ($entries as $key => $entry) {
      $target = is_string($key) ? self::convergeKey($key) : $key;
      if (is_array($entry) && is_array($entry['variants'] ?? NULL)) {
        foreach ($entry['variants'] as $i => $ref) {
          $fragment = is_array($ref) ? ($ref['fragment'] ?? NULL) : NULL;
          if (is_string($fragment) && ($converged = self::convergeKey($fragment)) !== $fragment) {
            $entry['variants'][$i]['fragment'] = $converged;
            $this->report($lines, sprintf('%s %s: rewrote the "%s" fragment reference to "%s".', $context, $target, $fragment, $converged));
            $changed = TRUE;
          }
        }
      }
      if ($target !== $key) {
        $changed = TRUE;
        $this->report($lines, sprintf('%s: rewrote the "%s" key to "%s" (the relative selector grammar).', $context, $key, $target));
        if (is_array($result[$target] ?? NULL) && is_array($entry)) {
          // The canonical entry already landed here: it wins.
          $result[$target] = StyleEntry::merge($result[$target], $entry);
          continue;
        }
      }
      elseif (is_array($result[$target] ?? NULL) && is_array($entry)) {
        // A legacy spelling already landed here; the canonical entry wins.
        $result[$target] = StyleEntry::merge($entry, $result[$target]);
        continue;
      }
      $result[$target] = $entry;
    }
    return $result;
  }

  /**
   * Converges every daedalus_variant's styles, across ALL revisions.
   *
   * @return string[]
   *   Report lines.
   */
  public function convergeVariants(): array {
    $lines = [];
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
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
   * Converges every daedalus_skin's defaults, across ALL revisions.
   *
   * @return string[]
   *   Report lines.
   */
  public function convergeSkins(): array {
    $lines = [];
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
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
