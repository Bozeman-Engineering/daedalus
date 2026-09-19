<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\ComponentTransform;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictionsInterface;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\StyleMapItem;

/**
 * Migrates legacy inline_block:layout_block components to nested sections.
 *
 * The legacy nesting model wrapped a section list in a block_content entity
 * (bundle layout_block, one layout_builder__layout section, Count(1,1)):
 * component -> block_content -> sections. Under the unified model a nested
 * section is an ordinary container component (SectionBlock) whose
 * configuration embeds ONE Section::toArray() value. This transform reads
 * the old shape itself (unserialize block_serialized, or load the
 * referenced block revision, and recurse) and rewrites the component in
 * place, deepest-first, so a page nested N layout blocks deep converts in
 * one pass regardless of which store the spine is walking.
 *
 * What one conversion does:
 * - Extracts the block_content's one section as a VALUE (multi-section
 *   lists, which Count(1,1) should have prevented, wrap in a section using
 *   the site's first allowed layout; an unresolvable block leaves an empty
 *   default section so the page keeps a valid, visibly empty container
 *   instead of a broken component).
 * - Recurses: nested legacy layout blocks inside the extracted section
 *   convert first, and already-converted containers are descended so a
 *   mixed-nesting tree (containers inside legacy blocks) normalizes fully.
 * - Remaps field-block context_mapping entries off daedalus_layout.root_entity:
 *   under the unified model the root entity IS the entity at every depth,
 *   and containers forward it as layout_builder.entity.
 * - Converges identity: the embedded section's daedalus_layout uuid becomes the
 *   component uuid (the SectionBlock invariant), because styles, ops, and
 *   the tree all address a nested section by its SECTION uuid. This is the
 *   one sanctioned exception to the keep-the-component-uuid rule on
 *   ComponentTransformInterface: the wrapper's block identity is retired,
 *   and its style-map entries migrate to the section key below.
 * - Migrates host style-map entries keyed block:{component} to
 *   container:{section} (the wrapper rendered the section's own layout
 *   root, so the styling target is the same DOM element under its new key).
 * - Migrates a custom admin label into layout_settings.label, the
 *   core-native home the tree and serializer read.
 *
 * The transform does NOT delete the orphaned layout_block block_content
 * entities: other, not-yet-walked host revisions may still reference other
 * revisions of the same entity, and unlike the heading migration the
 * entity IS the data until every referencing revision is rewritten.
 * daedalus_layout_post_update_0003_layout_block_gc() deletes them (usage rows
 * included) after the store walks complete.
 */
class LayoutBlockToSectionTransform implements ComponentTransformInterface {

  /**
   * The legacy layout block plugin id.
   */
  protected const LEGACY_PLUGIN_ID = 'inline_block:layout_block';

  /**
   * The shipped default admin label, never worth migrating to a section.
   */
  protected const DEFAULT_LABEL = 'Layout Block';

  /**
   * Section uuids already claimed per host object.
   *
   * A duplicated legacy component (copied configuration) duplicates the
   * embedded section uuid; converging component identity onto a duplicate
   * uuid would make two sibling components collide on one array key and
   * silently drop one at the next Section::fromArray(). The second
   * occurrence re-mints, mirroring TreeIndex collision repair. Keyed by
   * host object (a WeakMap) so two revisions of the same entity, distinct
   * objects legitimately carrying the same uuids, never cross-claim.
   *
   * @var \WeakMap<\Drupal\Core\Entity\EntityInterface, array<string, true>>
   */
  protected \WeakMap $claimedUuids;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected UuidInterface $uuid,
    protected PlacementRestrictionsInterface $placementRestrictions,
  ) {
    $this->claimedUuids = new \WeakMap();
  }

  /**
   * Mints an empty wrapper section with a layout the site actually offers.
   *
   * The storage-less allowed-layouts answer (the globally filtered picker
   * list) replaces the old hardcoded layout_onecol, which is neither
   * guaranteed installed nor guaranteed allowed. Migration cannot proceed
   * on a site offering no layouts at all; that is a misconfiguration to
   * surface, not to paper over.
   */
  protected function emptyWrapperSection(): Section {
    $allowed = $this->placementRestrictions->allowedLayoutDefinitions(NULL);
    if ($allowed === []) {
      throw new \RuntimeException('Cannot migrate a legacy layout block: no layout plugins are allowed on this site.');
    }
    return new Section(array_key_first($allowed));
  }

  /**
   * {@inheritdoc}
   */
  public function applies(SectionComponent $component, EntityInterface $host): bool {
    return ($component->get('configuration')['id'] ?? '') === self::LEGACY_PLUGIN_ID;
  }

  /**
   * {@inheritdoc}
   */
  public function transform(SectionComponent $component, EntityInterface $host): void {
    $configuration = $component->get('configuration');
    $section = $this->extractSection($configuration, $host);
    $this->convertSubtree($section, $host);
    $this->migrateLabel($configuration, $section);

    $section_uuid = $this->claimSectionUuid($section, $host);
    $this->migrateStyles($host, $component->getUuid(), $section_uuid);

    // One identity: the component re-keys to the section's uuid (see the
    // class docblock). The caller owns fixing the parent section's array
    // key afterwards: convertSubtree() below and the registry's
    // transformSections() both re-add the component under its new uuid,
    // which matters because the host layout field serializes the Section
    // OBJECT, stale keys included.
    $component->set('uuid', $section_uuid);
    $component->setConfiguration([
      'id' => SectionBlock::PLUGIN_ID,
      'label_display' => '0',
      SectionBlock::SECTION_KEY => $section->toArray(),
      'context_mapping' => SectionBlock::CONTEXT_MAPPING,
    ]);
  }

  /**
   * Extracts a legacy component's nested section list as ONE section value.
   *
   * Block_serialized wins over block_revision_id: it is the fresher copy in
   * every store that carries it (unsaved hosts, display defaults mid-save,
   * stale tempstore states passing through the presave net).
   *
   * @param array $configuration
   *   The legacy component configuration.
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity whose layout holds the component.
   *
   * @return \Drupal\layout_builder\Section
   *   A detached section VALUE (never the block's live field objects, which
   *   may be shared through the storage entity cache).
   */
  protected function extractSection(array $configuration, EntityInterface $host): Section {
    $block = NULL;
    if (!empty($configuration['block_serialized'])) {
      // Layout Builder's own inline-block payload (InlineBlock::blockSubmit()).
      // phpcs:ignore DrupalPractice.FunctionCalls.InsecureUnserialize.InsecureUnserialize
      $candidate = unserialize($configuration['block_serialized']);
      if ($candidate instanceof FieldableEntityInterface) {
        $block = $candidate;
      }
    }
    if (!$block && !empty($configuration['block_revision_id'])) {
      /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
      $storage = $this->entityTypeManager->getStorage('block_content');
      $block = $storage->loadRevision($configuration['block_revision_id']);
    }

    $sections = [];
    if ($block instanceof FieldableEntityInterface && $block->hasField('layout_builder__layout')) {
      foreach ($block->get('layout_builder__layout') as $item) {
        if ($item->section instanceof Section) {
          // Round-trip through toArray() so the transform mutates a private
          // copy, not Section objects owned by a possibly-cached entity.
          $sections[] = Section::fromArray($item->section->toArray());
        }
      }
    }

    if (count($sections) === 1) {
      return $sections[0];
    }
    if ($sections === []) {
      // Unresolvable or empty: an empty default section keeps the
      // component valid new-shape state (a container must embed a section)
      // and renders nothing, which is what the broken legacy block did.
      return $this->emptyWrapperSection();
    }
    // More than one section should have been impossible (Count 1,1), but
    // data predating the constraint may carry it: wrap the sections as
    // nested containers of a default section so nothing is dropped.
    $wrapper = $this->emptyWrapperSection();
    foreach ($sections as $delta => $nested) {
      $this->claimSectionUuid($nested, $host);
      $wrapper->appendComponent(SectionBlock::createComponent($nested, 'content', $delta));
    }
    return $wrapper;
  }

  /**
   * Normalizes an extracted section's subtree, deepest-first.
   *
   * Converts nested legacy layout blocks (recursion happens inside
   * transform() via extractSection() -> convertSubtree()), descends
   * already-converted containers, and remaps legacy root-entity context
   * mappings on every component.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section whose components are normalized in place.
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity whose layout holds the tree.
   */
  protected function convertSubtree(Section $section, EntityInterface $host): void {
    foreach ($section->getComponents() as $original_uuid => $component) {
      if ($this->applies($component, $host)) {
        $this->transform($component, $host);
        // The conversion changed the component's uuid; re-key it in the
        // section so in-memory lookups stay coherent (weight restored
        // because appendComponent() re-assigns it).
        $weight = $component->getWeight();
        $section->removeComponent($original_uuid);
        $section->appendComponent($component);
        $component->setWeight($weight);
        continue;
      }
      // Mixed nesting: a container placed inside a legacy layout block
      // still needs its own subtree normalized.
      if ($embedded = SectionBlock::embeddedSection($component)) {
        $this->convertSubtree($embedded, $host);
        $configuration = $component->get('configuration');
        $configuration[SectionBlock::SECTION_KEY] = $embedded->toArray();
        $component->setConfiguration($configuration);
        continue;
      }
      $this->remapRootEntityContext($component);
    }
  }

  /**
   * Remaps a component's context_mapping off daedalus_layout.root_entity.
   *
   * The legacy model remapped field blocks moved into a layout block onto
   * the daedalus_layout.root_entity context. Containers forward the root entity as
   * layout_builder.entity at every depth, so the core-native id is correct
   * again everywhere.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component to remap in place.
   */
  protected function remapRootEntityContext(SectionComponent $component): void {
    $configuration = $component->get('configuration');
    $mapping = $configuration['context_mapping'] ?? [];
    if (!is_array($mapping) || !in_array(NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID, $mapping, TRUE)) {
      return;
    }
    foreach ($mapping as $slot => $context_id) {
      if ($context_id === NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID) {
        $mapping[$slot] = 'layout_builder.entity';
      }
    }
    $configuration['context_mapping'] = $mapping;
    $component->setConfiguration($configuration);
  }

  /**
   * Migrates a custom admin label into layout_settings.label.
   *
   * @param array $configuration
   *   The legacy component configuration.
   * @param \Drupal\layout_builder\Section $section
   *   The extracted section.
   */
  protected function migrateLabel(array $configuration, Section $section): void {
    $label = trim((string) ($configuration['label'] ?? ''));
    if ($label === '' || strcasecmp($label, self::DEFAULT_LABEL) === 0) {
      return;
    }
    $settings = $section->getLayoutSettings();
    if (!empty($settings['label'])) {
      return;
    }
    $settings['label'] = $label;
    $section->setLayoutSettings($settings);
  }

  /**
   * Claims (stamping or re-minting as needed) a section's daedalus_layout uuid.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section needing a unique identity on this host.
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity whose layout holds the tree.
   *
   * @return string
   *   The claimed uuid, stamped on the section.
   */
  protected function claimSectionUuid(Section $section, EntityInterface $host): string {
    $claimed = $this->claimedUuids[$host] ?? [];
    $section_uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    if (!is_string($section_uuid) || $section_uuid === '' || isset($claimed[$section_uuid])) {
      // Duplicate identity styles the first occurrence only, matching
      // TreeIndex collision repair: the copy gets fresh identity and loses
      // the shared styling rather than colliding.
      $section_uuid = $this->uuid->generate();
      $section->setThirdPartySetting('daedalus_layout', 'uuid', $section_uuid);
    }
    $claimed[$section_uuid] = TRUE;
    $this->claimedUuids[$host] = $claimed;
    return $section_uuid;
  }

  /**
   * Moves host style-map entries from the block key to the section key.
   *
   * The wrapper block key (block:{uuid} and its ::sub-element extensions)
   * addressed the same rendered element the section key now addresses.
   * Hosts without a daedalus_stylish_styles field (entity view displays, sites
   * without Daedalus Stylish) migrate without styles, mirroring the heading
   * migration. Single-colon block-field keys of the wrapper (its layout
   * field render) have no equivalent element and stay behind as inert
   * entries.
   *
   * @param \Drupal\Core\Entity\EntityInterface $host
   *   The entity whose layout holds the component.
   * @param string $block_uuid
   *   The legacy component uuid.
   * @param string $section_uuid
   *   The section uuid replacing it.
   */
  protected function migrateStyles(EntityInterface $host, string $block_uuid, string $section_uuid): void {
    // The field name is DaedalusStylishFieldManager::FIELD_NAME, referenced as a
    // literal so this class never loads Daedalus Stylish code Daedalus Stylish may not be
    // installed to provide.
    if (!$host instanceof FieldableEntityInterface || !$host->hasField('daedalus_stylish_styles')) {
      return;
    }
    $item = $host->get('daedalus_stylish_styles')->first();
    if (!$item instanceof StyleMapItem) {
      return;
    }

    $map = $item->getStyleMap();
    $from = 'block:' . $block_uuid;
    $to = 'container:' . $section_uuid;
    $changed = FALSE;
    foreach (array_keys($map) as $key) {
      if ($key !== $from && !str_starts_with($key, $from . '::')) {
        continue;
      }
      $target = $to . substr($key, strlen($from));
      $entry = $map[$key];
      unset($map[$key]);
      $changed = TRUE;
      if (!array_key_exists($target, $map)) {
        $map[$target] = $entry;
        continue;
      }
      // Both keys carry styling (the section key was reachable through the
      // AI grammar even while the block key was the UI's address). A
      // variant reference is a whole entry, so when either side holds one
      // the existing section entry wins wholesale; otherwise merge
      // per-condition with the section entry's properties winning.
      $existing = $map[$target];
      if (array_key_exists('variant', $existing) || array_key_exists('variant', $entry)) {
        continue;
      }
      foreach ($entry as $condition => $values) {
        $existing[$condition] = ($existing[$condition] ?? []) + $values;
      }
      $map[$target] = $existing;
    }
    if ($changed) {
      $item->setStyleMap($map);
    }
  }

}
