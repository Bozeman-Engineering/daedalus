<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Render;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\ElementLocator;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;

/**
 * Maps mutation-result targets to the selectable keys to re-render.
 *
 * Targets name what changed in model terms ({uuid, field_name-or-sentinel});
 * selectable keys name what to swap in render terms. The mapping picks the
 * smallest subtree whose markup fully reflects the change:
 * - A field change re-renders that field's wrapper.
 * - A field that went from empty to valued or back (the PRESENCE sentinel)
 *   re-renders its containing subtree: the block root for a block field,
 *   the whole entity for a root or paragraph field, because an empty field
 *   renders no wrapper and the page has nothing to swap into.
 * - An added or reordered item re-renders its parent container (the item's
 *   position only exists in the parent's markup).
 * - A removal or restructure re-renders the whole entity: the removed node has
 *   no surviving subtree, and restructures span containers we cannot enumerate
 *   from the target alone.
 *
 * Any target that cannot be mapped more precisely falls back to the root
 * entity key, which subsumes every other subtree.
 */
class TargetKeyMapper {

  /**
   * Constructs a TargetKeyMapper.
   *
   * @param \Drupal\daedalus_blueprint\ElementLocator $elementLocator
   *   The element locator.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter $daedalusLayoutAdapter
   *   The daedalus_layout adapter, source of section UUIDs.
   */
  public function __construct(
    protected ElementLocator $elementLocator,
    protected DaedalusLayoutAdapter $daedalusLayoutAdapter,
  ) {}

  /**
   * Maps change targets to the selectable keys of the subtrees to re-render.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the mutation was applied to.
   * @param array<int, array{uuid: string, field_name: string}|string> $targets
   *   The change targets from the blueprint result. A string target is
   *   already a selectable key (a style write names the styled element
   *   directly) and passes through verbatim; the render's missing-subtree
   *   fallback covers a key with no stamped element.
   *
   * @return string[]
   *   Deduplicated selectable keys. May contain the root entity key, which
   *   subsumes all others.
   */
  public function map(HostInterface $host, array $targets): array {
    $keys = [];
    foreach ($targets as $target) {
      $keys[] = is_string($target)
        ? $target
        : $this->mapTarget($host, (string) $target['uuid'], (string) $target['field_name']);
    }
    return array_values(array_unique($keys));
  }

  /**
   * Maps a single target to a selectable key.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   * @param string $uuid
   *   The target UUID.
   * @param string $field_name
   *   The target field name or change-kind sentinel.
   *
   * @return string
   *   The selectable key of the subtree to re-render.
   */
  protected function mapTarget(HostInterface $host, string $uuid, string $field_name): string {
    $fallback = SelectableKey::entity($host->uuid());

    if ($uuid === $host->uuid()) {
      return TargetType::isSentinel($field_name) ? $fallback : SelectableKey::field($uuid, $field_name);
    }

    $context = $this->elementLocator->locate($host, $uuid);
    if ($context === NULL) {
      return $fallback;
    }

    if ($context->isLbComponent()) {
      if (!TargetType::isSentinel($field_name)) {
        return SelectableKey::blockField($uuid, $field_name);
      }
      if ($field_name === TargetType::PRESENCE) {
        // A block field that appeared or disappeared has no wrapper of its
        // own in one of the two states; the block root is the smallest
        // subtree stamped in both.
        return SelectableKey::block($uuid);
      }
      if (in_array($field_name, [TargetType::ADDED, TargetType::REORDERED], TRUE)
        && $context->target instanceof SectionComponent
        && $context->section instanceof Section
      ) {
        // The owning container's subtree is the smallest markup that fully
        // reflects an added or reordered child: regions have no selectable
        // keys (a container's children are one ordered flow), so the
        // container root is the swap target for every layout, multi-region
        // layouts included (their subtree subsumes the region).
        $section_uuid = $this->daedalusLayoutAdapter->getSectionUuid($context->section);
        if ($section_uuid !== NULL) {
          return SelectableKey::container($section_uuid);
        }
      }
      return $fallback;
    }

    if ($context->isParagraph()) {
      if (!TargetType::isSentinel($field_name)) {
        return SelectableKey::field($uuid, $field_name);
      }
      if (in_array($field_name, [TargetType::ADDED, TargetType::REORDERED], TRUE)
        && $context->fieldName !== NULL
        && $context->parentEntity !== NULL
        && $context->parentEntity === $host->fields()
      ) {
        // Only root-level host fields map cleanly: paragraphs nested inside
        // inline blocks address their host field by component UUID, which
        // this context does not carry.
        return SelectableKey::field($context->parentEntity->uuid(), $context->fieldName);
      }
      return $fallback;
    }

    // A section's own properties (label, layout_settings) and a restructure
    // of it both render inside its container: the smallest subtree that
    // fully reflects the change.
    if ($context->isSection() && (!TargetType::isSentinel($field_name) || $field_name === TargetType::RESTRUCTURED)) {
      return SelectableKey::container($uuid);
    }

    return $fallback;
  }

}
