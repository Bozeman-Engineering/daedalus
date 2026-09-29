<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layouts;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * The one-box convergence: container part styles hoist onto the root.
 *
 * The container is ONE element at every depth now; the old inner
 * `.daedalus-stylish-layout-container` part no longer exists in the declaration or
 * the render. Stored styles addressed to it (`::.daedalus-stylish-layout-container`
 * and its `::>` children entries) have no element left, so every store
 * re-keys them onto the root: host style maps (the sweep), daedalus_variant
 * styles (all revisions, composition slot paths included), daedalus_skin
 * defaults, and stored stencils. When the root already carries an entry,
 * the two merge per condition with the ROOT's properties winning (the two
 * boxes are one now; a genuine property collision is reported loudly) and
 * variant reference lists concatenating.
 *
 * Shipped-unmodified variants and skins are SKIPPED: the sync lane owns
 * them (their disk sources were rewritten to the root key with the
 * declaration), and an in-place rewrite would flip their hash to
 * "modified" and cut them off from future shipped updates.
 *
 * ContainerMigration's frozen CONTAINER_PART constant is deliberately
 * reused: the historical shapes it converges still SPEAK the part key, and
 * this hoist runs after those sweeps (tag priority), so truly stale data
 * walks the whole history in one pass.
 */
class ContainerPartHoist {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Reports one migration line: collected for the caller AND logged.
   */
  protected function report(array &$lines, string $line, bool $lossy = FALSE): void {
    $lines[] = $line;
    $logger = $this->loggerFactory->get('daedalus_stylish_layouts');
    $lossy ? $logger->warning($line) : $logger->notice($line);
  }

  /**
   * Strips the retired part segment from a style key or slot path.
   *
   * `container:{uuid}::.daedalus-stylish-layout-container` becomes the root key,
   * the part's children entry (`::.daedalus-stylish-layout-container > *`, or
   * the legacy `::.daedalus-stylish-layout-container::>` spelling) becomes the
   * root's `::> *` children entry, and a composition slot path
   * (`container(1)::.daedalus-stylish-layout-container`) loses the segment the
   * same way. The children spelling written is the relative selector
   * grammar's canonical one (RelativeSelectorSweep converges the legacy
   * spelling on every store this hoist does not reach first).
   */
  public function hoistKey(string $key): string {
    return str_replace([
      ContainerMigration::CONTAINER_PART . ' > *',
      ContainerMigration::CONTAINER_PART . '::>',
      ContainerMigration::CONTAINER_PART,
    ], ['::> *', '::> *', ''], $key);
  }

  /**
   * Merges a hoisted part entry into the root's existing entry.
   *
   * Per condition and per property, the ROOT's value wins: the part's
   * styles join the box the root already styles, and where both spoke the
   * same property the root's word was the outer box's fact, kept and
   * reported loudly (any choice is lossy once two boxes are one). Variant
   * reference lists concatenate, deduplicating by variant name; bare-name
   * lists (stencils) concatenate the same way.
   *
   * @param array $root
   *   The root's existing entry.
   * @param array $part
   *   The hoisted part entry.
   * @param string $context
   *   Where the merge happens, for report lines.
   * @param string[] $lines
   *   Collected report lines.
   *
   * @return array
   *   The merged entry.
   */
  protected function mergeHoisted(array $root, array $part, string $context, array &$lines): array {
    foreach ($part as $condition => $values) {
      if ($condition === 'variants') {
        if (!is_array($values)) {
          continue;
        }
        $existing = is_array($root['variants'] ?? NULL) ? $root['variants'] : [];
        $names = array_map(static fn ($ref) => is_array($ref) ? ($ref['variant'] ?? NULL) : $ref, $existing);
        foreach ($values as $ref) {
          $name = is_array($ref) ? ($ref['variant'] ?? NULL) : $ref;
          if (in_array($name, $names, TRUE)) {
            continue;
          }
          $existing[] = $ref;
          $names[] = $name;
        }
        $root['variants'] = $existing;
        continue;
      }
      if (!is_array($values)) {
        if (!array_key_exists($condition, $root)) {
          $root[$condition] = $values;
        }
        continue;
      }
      foreach ($values as $property => $value) {
        if (is_array($root[$condition] ?? NULL) && array_key_exists($property, $root[$condition])) {
          if ($root[$condition][$property] !== $value) {
            $this->report($lines, sprintf('%s: the root already declares "%s" under "%s"; its value won over the hoisted part\'s "%s".', $context, $property, $condition, is_scalar($value) ? (string) $value : gettype($value)), TRUE);
          }
          continue;
        }
        $root[$condition][$property] = $value;
      }
    }
    return $root;
  }

  /**
   * Hoists a whole style map's part entries onto their root keys.
   *
   * Fragment references still addressing the part re-key in the same pass
   * (the referenced variant's own styles hoist in hoistVariants(), so a
   * fragment spelling the part segment would dangle).
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
  public function hoistStyleMap(array $map, string $context, array &$lines): ?array {
    $changed = FALSE;
    $result = [];
    $hoists = [];
    foreach ($map as $key => $entry) {
      if (is_string($key) && str_contains($key, ContainerMigration::CONTAINER_PART)) {
        $hoists[$key] = $entry;
        continue;
      }
      $result[$key] = $entry;
    }
    // Root entries first, hoists after: the root's properties must win the
    // merge regardless of the stored key order.
    foreach ($hoists as $key => $entry) {
      $target = $this->hoistKey($key);
      $changed = TRUE;
      if (is_array($result[$target] ?? NULL) && is_array($entry)) {
        $result[$target] = $this->mergeHoisted($result[$target], $entry, "$context $target", $lines);
        $this->report($lines, sprintf('%s: hoisted the "%s" styles onto "%s" (the container is one box).', $context, $key, $target));
        continue;
      }
      if (array_key_exists($target, $result)) {
        // A malformed collision (either side not an entry array): keep the
        // root's value, drop the part's loudly.
        $this->report($lines, sprintf('%s: dropped the "%s" styles - the "%s" entry could not merge them.', $context, $key, $target), TRUE);
        continue;
      }
      $result[$target] = $entry;
      $this->report($lines, sprintf('%s: hoisted the "%s" styles onto "%s" (the container is one box).', $context, $key, $target));
    }
    foreach ($result as $key => $entry) {
      if (!is_array($entry) || empty($entry['variants']) || !is_array($entry['variants'])) {
        continue;
      }
      foreach ($entry['variants'] as $i => $ref) {
        $fragment = is_array($ref) ? ($ref['fragment'] ?? NULL) : NULL;
        if (is_string($fragment) && str_contains($fragment, ContainerMigration::CONTAINER_PART)) {
          $result[$key]['variants'][$i]['fragment'] = $this->hoistKey($fragment);
          $this->report($lines, sprintf('%s %s: rewrote the "%s" fragment reference to "%s".', $context, $key, $fragment, $this->hoistKey($fragment)));
          $changed = TRUE;
        }
      }
    }
    return $changed ? $result : NULL;
  }

  /**
   * Hoists one element-relative styles array (variant/skin/stencil shape).
   *
   * @param array $styles
   *   Styles keyed by element-relative key ('' the element itself).
   * @param string $context
   *   Where the styles live, for report lines.
   * @param string[] $lines
   *   Collected report lines.
   * @param bool $changed
   *   Set TRUE when anything was rewritten.
   *
   * @return array
   *   The rewritten styles.
   */
  protected function hoistRelativeStyles(array $styles, string $context, array &$lines, bool &$changed): array {
    $result = [];
    $hoists = [];
    foreach ($styles as $relative => $entry) {
      if (is_string($relative) && str_contains($relative, ContainerMigration::CONTAINER_PART)) {
        $hoists[$relative] = $entry;
        continue;
      }
      $result[$relative] = $entry;
    }
    foreach ($hoists as $relative => $entry) {
      $target = $this->hoistKey($relative);
      $changed = TRUE;
      if (is_array($result[$target] ?? NULL) && is_array($entry)) {
        $result[$target] = $this->mergeHoisted($result[$target], $entry, sprintf('%s "%s"', $context, $target), $lines);
        $this->report($lines, sprintf('%s: hoisted the "%s" styles onto %s (the container is one box).', $context, $relative, $target === '' ? 'the root' : '"' . $target . '"'));
        continue;
      }
      if (array_key_exists($target, $result)) {
        $this->report($lines, sprintf('%s: dropped the "%s" styles - the "%s" entry could not merge them.', $context, $relative, $target), TRUE);
        continue;
      }
      $result[$target] = $entry;
      $this->report($lines, sprintf('%s: hoisted the "%s" styles onto %s (the container is one box).', $context, $relative, $target === '' ? 'the root' : '"' . $target . '"'));
    }
    return $result;
  }

  /**
   * Hoists every daedalus_variant's part-keyed styles, across ALL revisions.
   *
   * Shipped-unmodified variants are skipped: the sync lane delivers their
   * rewritten disk sources, and an in-place rewrite would flip their hash
   * to "modified" and cut them off from future shipped updates.
   *
   * @return string[]
   *   Report lines.
   */
  public function hoistVariants(): array {
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
        $context = sprintf('Variant "%s" (revision %s)', $variant->getMachineName(), $revision_id);
        $styles = $this->hoistRelativeStyles($revision->getStyles(), $context, $lines, $changed);
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
   * Hoists every daedalus_skin's part-keyed defaults, across ALL revisions.
   *
   * Every defaults scope re-keys (only container scopes ever carried the
   * part, but the key grammar is one). Shipped-unmodified skins are
   * skipped for the same sync-lane reason as variants.
   *
   * @return string[]
   *   Report lines.
   */
  public function hoistSkins(): array {
    $lines = [];
    $storage = $this->entityTypeManager->getStorage('daedalus_skin');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    foreach ($storage->loadMultiple($ids) as $skin) {
      /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $skin */
      if ($skin->isShipped() && !$skin->isModified()) {
        continue;
      }
      $revision_ids = array_keys($storage->getQuery()->allRevisions()->accessCheck(FALSE)->condition('id', $skin->id())->execute());
      foreach ($revision_ids as $revision_id) {
        /** @var \Drupal\daedalus_stylish\DaedalusStylishSkinInterface $revision */
        $revision = $storage->loadRevision($revision_id);
        $changed = FALSE;
        $context = sprintf('Skin "%s" (revision %s)', $skin->getMachineName(), $revision_id);
        $defaults = $revision->getDefaults();
        foreach ($defaults as $scope => $relatives) {
          if (!is_array($relatives)) {
            continue;
          }
          $defaults[$scope] = $this->hoistRelativeStyles($relatives, "$context defaults $scope", $lines, $changed);
        }
        if ($changed) {
          $revision->setDefaults($defaults);
          $revision->setNewRevision(FALSE);
          $revision->save();
        }
      }
    }
    return $lines;
  }

  /**
   * Hoists every stored stencil's part-keyed styles, at every depth.
   *
   * File-shipped stencils are module code and cannot be rewritten here;
   * one still carrying the part key fails loudly at instantiate time with
   * the one-box correctives.
   *
   * @return string[]
   *   Report lines.
   */
  public function hoistStencils(): array {
    $lines = [];
    if (!$this->entityTypeManager->hasDefinition('stencil')) {
      return $lines;
    }
    $storage = $this->entityTypeManager->getStorage('stencil');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    foreach ($storage->loadMultiple($ids) as $stencil) {
      /** @var \Drupal\daedalus_blueprint_stencil\StencilInterface $stencil */
      $changed = FALSE;
      $element = $this->hoistStencilNode($stencil->getElement(), sprintf('Stencil "%s"', $stencil->getMachineName()), $lines, $changed);
      if ($changed) {
        $stencil->setElement($element);
        $stencil->save();
      }
    }
    return $lines;
  }

  /**
   * Recursively hoists one stencil node's styles.
   */
  protected function hoistStencilNode(array $node, string $context, array &$lines, bool &$changed): array {
    if (!empty($node['regions']) && is_array($node['regions'])) {
      foreach ($node['regions'] as $region => $children) {
        if (!is_array($children)) {
          continue;
        }
        foreach ($children as $i => $child) {
          if (is_array($child)) {
            $node['regions'][$region][$i] = $this->hoistStencilNode($child, $context, $lines, $changed);
          }
        }
      }
    }
    if (!empty($node['styles']) && is_array($node['styles'])) {
      $node['styles'] = $this->hoistRelativeStyles($node['styles'], "$context styles", $lines, $changed);
    }
    return $node;
  }

}
