<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_layouts;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Drupal\layout_builder\Section;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_stylish\StyleMapSweepBase;

/**
 * Converts a host's proportions grammar to mode + share (the columns variant).
 *
 * Runs once per host AFTER the section walk and after the grammar sweep
 * (tag priority): by then the map speaks the container grammar and every
 * retiring reference has landed on its split_* spelling, so this sweep is
 * the one place worn split references and stepper-written explicit track
 * overrides become the `columns` arrangement reference plus per-child
 * flex-grow overrides. The share half needs each container's children in
 * ORDER, which only the section tree knows - that is why this is its own
 * transform rather than more ContainerGrammarSweep: it reads the host's
 * layout store (a content host's layout fields, a display host's template)
 * and indexes every container section's ordered child element keys first.
 *
 * Registered permanently (the LayoutBlockToSectionTransform posture): the
 * split ratios are frozen constants, so stale tempstore sessions carrying
 * split references or stepper tracks keep converging on save after the
 * variants and the stepper are gone.
 */
class RowArrangementSweep extends StyleMapSweepBase {

  public function __construct(
    protected ContainerMigration $migration,
    protected EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  protected function sweep(array $map, HostInterface $host, string $context, array &$lines): ?array {
    return $this->migration->migrateArrangement($map, $this->childrenIndex($host), $context, $lines);
  }

  /**
   * Indexes every container section's ordered child element keys.
   *
   * The store is read raw, never through the host's document door: a
   * content host's sectionStorage() seeds an empty override from the
   * template, and a migration walk must never instantiate documents.
   *
   * @return array<string, string[]>
   *   container:{uuid} / block:{uuid} child keys in flow order, keyed by
   *   container section uuid. A container whose children cannot all be
   *   identified is left out (positions must never shift).
   */
  protected function childrenIndex(HostInterface $host): array {
    $index = [];
    foreach ($this->rawSections($host) as $section) {
      $this->indexSection($section, $index);
    }
    return $index;
  }

  /**
   * The sections a host's store holds, read raw.
   *
   * @return \Drupal\layout_builder\Section[]
   *   The root sections of every layout store the host carries.
   */
  protected function rawSections(HostInterface $host): array {
    $entity = $host->entity();
    if ($entity instanceof LayoutEntityDisplayInterface) {
      return $entity->isLayoutBuilderEnabled() ? $entity->getSections() : [];
    }
    if (!$entity instanceof FieldableEntityInterface) {
      return [];
    }
    $sections = [];
    $field_map = $this->entityFieldManager->getFieldMapByFieldType('layout_section');
    foreach (array_keys($field_map[$entity->getEntityTypeId()] ?? []) as $field_name) {
      if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
        continue;
      }
      /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $field */
      $field = $entity->get($field_name);
      array_push($sections, ...array_values($field->getSections()));
    }
    return $sections;
  }

  /**
   * Indexes one section (and its embedded subtree) into the children map.
   */
  protected function indexSection(Section $section, array &$index): void {
    $components = $section->getComponents();
    uasort($components, static fn ($a, $b) => $a->getWeight() <=> $b->getWeight());
    $children = [];
    foreach ($components as $component) {
      $embedded = SectionBlock::embeddedSection($component);
      if ($embedded) {
        $child_uuid = $embedded->getThirdPartySetting('daedalus_layout', 'uuid');
        $children[] = is_string($child_uuid) && $child_uuid !== '' ? "container:$child_uuid" : NULL;
        $this->indexSection($embedded, $index);
      }
      else {
        $children[] = 'block:' . $component->getUuid();
      }
    }
    $uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    if ($section->getLayoutId() === ContainerMigration::TARGET_LAYOUT && is_string($uuid) && $uuid !== '' && !in_array(NULL, $children, TRUE)) {
      $index[$uuid] = $children;
    }
  }

}
