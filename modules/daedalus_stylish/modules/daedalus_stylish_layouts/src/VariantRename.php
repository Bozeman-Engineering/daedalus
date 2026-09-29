<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layouts;

use Drupal\Core\DependencyInjection\Container;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\daedalus_layout\ComponentTransform\ComponentTransformRegistry;
use Drupal\daedalus_layout\ComponentTransform\HostEntityWalker;

/**
 * Renames a variant machine name across every store, once.
 *
 * A shipped variant's machine name is identity in three stores: host style
 * maps reference it ({variant, fragment, via} items, the map lane), stored
 * stencils name it (the bare-name list the exporter writes) and variant
 * styles nest it (a composition's slot entries, the variant-styles lane).
 * This service owns the rewrite of all three plus the retirement of the
 * old shipped entity, so a rename post-update speaks through one
 * implementation and names only the two spellings.
 *
 * The host walk rides daedalus_layout's HostEntityWalker (every revision
 * and translation, batched through the caller's sandbox) with a registry
 * of its own holding one VariantRenameSweep, so the sweep is never a
 * registered host transform: a rename is a one-shot, and a permanent
 * old-to-new sweep would eat legitimate wearings once the old name is
 * minted again for something else (the row / columns case).
 */
class VariantRename {

  /**
   * The registry id of the sweep inside the walker's private container.
   */
  protected const SWEEP_ID = 'daedalus_stylish_layouts.variant_rename_sweep';

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * Reports one rename line: collected for the caller AND logged.
   *
   * @param string[] $lines
   *   The caller's collected report.
   * @param string $line
   *   The line.
   * @param bool $lossy
   *   TRUE logs a warning (a store was left as it was), FALSE a notice.
   */
  protected function report(array &$lines, string $line, bool $lossy = FALSE): void {
    $lines[] = $line;
    $logger = $this->loggerFactory->get('daedalus_stylish_layouts');
    $lossy ? $logger->warning($line) : $logger->notice($line);
  }

  /**
   * Walks one batch of host revisions, repointing $from to $to in their maps.
   *
   * @param array $sandbox
   *   The post-update sandbox; the walker owns its state entry and sets
   *   $sandbox['#finished'].
   * @param string $from
   *   The machine name to retire.
   * @param string $to
   *   The machine name it becomes.
   *
   * @return int
   *   The number of host revisions rewritten in this call.
   */
  public function walkHosts(array &$sandbox, string $from, string $to): int {
    $services = new Container();
    $services->set(self::SWEEP_ID, new VariantRenameSweep($this, $from, $to));
    $walker = new HostEntityWalker(
      $this->entityTypeManager,
      $this->entityFieldManager,
      new ComponentTransformRegistry($services, [], [], [self::SWEEP_ID]),
    );
    return $walker->walk($sandbox);
  }

  /**
   * Repoints every $from reference in a style map to $to.
   *
   * Only the reference's variant is rewritten; its fragment and its origin
   * (the "via" composition that distributed it) ride along untouched, so a
   * wearing a composition stamped keeps its provenance under the new name.
   *
   * @param array $map
   *   The style map.
   * @param string $from
   *   The machine name to retire.
   * @param string $to
   *   The machine name it becomes.
   * @param string $context
   *   Where the map lives, for report lines.
   * @param string[] $lines
   *   Collected report lines.
   *
   * @return array|null
   *   The rewritten map, or NULL when nothing referenced $from.
   */
  public function renameInStyleMap(array $map, string $from, string $to, string $context, array &$lines): ?array {
    $changed = FALSE;
    foreach ($map as $key => $entry) {
      if (is_array($entry)) {
        $map[$key] = $this->renameInEntry($entry, $from, $to, "$context $key", $lines, $changed);
      }
    }
    return $changed ? $map : NULL;
  }

  /**
   * Repoints the reference-shaped variants list of one style entry.
   */
  protected function renameInEntry(array $entry, string $from, string $to, string $context, array &$lines, bool &$changed): array {
    if (empty($entry['variants']) || !is_array($entry['variants'])) {
      return $entry;
    }
    foreach ($entry['variants'] as $i => $reference) {
      if (is_array($reference) && ($reference['variant'] ?? NULL) === $from) {
        $entry['variants'][$i]['variant'] = $to;
        $this->report($lines, sprintf('%s: repointed the "%s" reference to "%s"%s.', $context, $from, $to, isset($reference['via']) && is_string($reference['via']) && $reference['via'] !== '' ? sprintf(' (worn via "%s")', $reference['via']) : ''));
        $changed = TRUE;
      }
    }
    return $entry;
  }

  /**
   * Repoints $from to $to in every daedalus_variant's styles, all revisions.
   *
   * Composition variants nest references in their slot entries (the same
   * reference shape as maps, never an origin). Shipped-unmodified variants
   * are skipped and reported: the sync lane delivers their rewritten disk
   * sources, and an in-place rewrite would flip their hash to "modified"
   * and cut them off from future shipped updates (the ContainerPartHoist
   * precedent).
   *
   * @return string[]
   *   Report lines.
   */
  public function renameInVariants(string $from, string $to): array {
    $lines = [];
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    foreach ($storage->loadMultiple($ids) as $variant) {
      /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant */
      $revision_ids = array_keys($storage->getQuery()->allRevisions()->accessCheck(FALSE)->condition('id', $variant->id())->execute());
      $shipped = $variant->isShipped() && !$variant->isModified();
      foreach ($revision_ids as $revision_id) {
        /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $revision */
        $revision = $storage->loadRevision($revision_id);
        $changed = FALSE;
        $context = sprintf('Variant "%s" (revision %s)', $variant->getMachineName(), $revision_id);
        // A shipped-unmodified revision's per-reference lines are probed
        // and discarded: only the one skip line is reported for it.
        $revision_lines = [];
        $styles = $revision->getStyles();
        foreach ($styles as $relative => $entry) {
          if (is_array($entry)) {
            $styles[$relative] = $this->renameInEntry($entry, $from, $to, "$context $relative", $revision_lines, $changed);
          }
        }
        if (!$changed) {
          continue;
        }
        if ($shipped) {
          $this->report($lines, sprintf('%s: left its nested "%s" references to the sync lane (shipped and unmodified: its provider\'s disk source delivers the rename).', $context, $from), TRUE);
          continue;
        }
        array_push($lines, ...$revision_lines);
        $revision->setStyles($styles);
        $revision->setNewRevision(FALSE);
        $revision->save();
      }
    }
    return $lines;
  }

  /**
   * Renames $from to $to in every stored stencil's bare-name variant lists.
   *
   * File-shipped stencils are module code and cannot be rewritten here.
   * Every node depth is walked (the current `children` grammar and the
   * retired `regions` one, so no stored stencil escapes).
   *
   * @return string[]
   *   Report lines.
   */
  public function renameInStencils(string $from, string $to): array {
    $lines = [];
    if (!$this->entityTypeManager->hasDefinition('stencil')) {
      return $lines;
    }
    $storage = $this->entityTypeManager->getStorage('stencil');
    $ids = $storage->getQuery()->accessCheck(FALSE)->execute();
    foreach ($storage->loadMultiple($ids) as $stencil) {
      /** @var \Drupal\daedalus_blueprint_stencil\StencilInterface $stencil */
      $changed = FALSE;
      $element = $this->renameInStencilNode($stencil->getElement(), $from, $to, sprintf('Stencil "%s"', $stencil->getMachineName()), $lines, $changed);
      if ($changed) {
        $stencil->setElement($element);
        $stencil->save();
      }
    }
    return $lines;
  }

  /**
   * Recursively renames one stencil node's bare-name variant lists.
   */
  protected function renameInStencilNode(array $node, string $from, string $to, string $context, array &$lines, bool &$changed): array {
    if (!empty($node['children']) && is_array($node['children'])) {
      foreach ($node['children'] as $i => $child) {
        if (is_array($child)) {
          $node['children'][$i] = $this->renameInStencilNode($child, $from, $to, $context, $lines, $changed);
        }
      }
    }
    if (!empty($node['regions']) && is_array($node['regions'])) {
      foreach ($node['regions'] as $region => $children) {
        if (!is_array($children)) {
          continue;
        }
        foreach ($children as $i => $child) {
          if (is_array($child)) {
            $node['regions'][$region][$i] = $this->renameInStencilNode($child, $from, $to, $context, $lines, $changed);
          }
        }
      }
    }
    if (empty($node['styles']) || !is_array($node['styles'])) {
      return $node;
    }
    foreach ($node['styles'] as $relative => $entry) {
      if (!is_array($entry) || empty($entry['variants']) || !is_array($entry['variants']) || !in_array($from, $entry['variants'], TRUE)) {
        continue;
      }
      $names = [];
      foreach ($entry['variants'] as $name) {
        if ($name === $from) {
          $name = $to;
        }
        if (in_array($name, $names, TRUE)) {
          $this->report($lines, sprintf('%s styles %s: dropped a duplicate "%s" name after the rename.', $context, $relative, is_string($name) ? $name : '?'));
          continue;
        }
        $names[] = $name;
      }
      $node['styles'][$relative]['variants'] = $names;
      $this->report($lines, sprintf('%s styles %s: renamed the "%s" variant name to "%s".', $context, $relative, $from, $to));
      $changed = TRUE;
    }
    return $node;
  }

  /**
   * Deletes a provider's unmodified shipped variant under a retired name.
   *
   * Sync never deletes, so the entity under the old name would live on as
   * ordinary site content (and, once the name is minted again for another
   * variant, would be updated to that variant's styles while keeping its
   * old label and description). An unmodified copy is dead weight and
   * deletes; a modified copy is user work and stays, reported (the
   * retireShippedSplitVariants() precedent). The caller syncs the
   * provider afterwards so the current disk definitions land clean.
   *
   * @return string[]
   *   Report lines.
   */
  public function retireShipped(string $name, string $provider): array {
    $lines = [];
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    foreach ($storage->loadByProperties(['machine_name' => $name]) as $variant) {
      /** @var \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant */
      if (!$variant->isShipped() || $variant->getProvider() !== $provider) {
        continue;
      }
      if ($variant->isModified()) {
        $this->report($lines, sprintf('Kept the modified "%s" variant: it carries user changes and lives on as an ordinary variant.', $name), TRUE);
        continue;
      }
      $variant->delete();
      $this->report($lines, sprintf('Deleted the retired shipped "%s" variant (its references repointed).', $name));
    }
    return $lines;
  }

}
