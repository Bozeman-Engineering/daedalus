<?php

declare(strict_types=1);

namespace Drupal\daedalus\Migrate\PlusSuite;

use Drupal\Core\DependencyInjection\Container;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\daedalus\Migrate\PhaseInterface;
use Drupal\daedalus_layout\ComponentTransform\ComponentTransformRegistry;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionListInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The content phase: the old heading components become text blocks.
 *
 * Runs once, after `drush updb` and the migration recipe (the text block
 * type must exist), over the default revision of every host carrying a
 * layout. Each host's sections, nested sections included, go through
 * daedalus_layout's migration spine (ComponentTransformRegistry, the one
 * section walk that descends container components) with HeadingTransform
 * as the only transform, and a host with a rewritten component is saved
 * once, in place: the inline-block presave machinery then saves the new
 * text blocks, writes their revision ids into the components and records
 * their usage, per the spine's block_serialized contract. Older revisions
 * keep their heading components (best effort, by decision); the old heading
 * blocks and their type stay for them.
 *
 * The registry is the phase's own, holding the transform as a plain
 * service, rather than the site's: a transform tagged on the site would
 * make daedalus_layout's presave safety net rewrite any inline_block:heading
 * component on every site forever, and the pathway adds no runtime
 * behaviour. Only the overrides field (layout_builder__layout) is walked:
 * it is the one layout field the inline-block presave machinery persists
 * serialized replacements in; any other layout_section field (a section
 * library template's, migrated to stencils by `drush updb`) is named in the
 * report as left alone, and the closing audit counts what it holds.
 *
 * The walk stops at the first failure with the host named; the hosts saved
 * before it stay migrated, a rewritten component never applies again, and
 * the entry leaves the phase unrecorded so a rerun continues.
 */
final class HeadingToText implements PhaseInterface {

  /**
   * The id the transform has in the phase's own registry.
   */
  private const TRANSFORM_ID = 'daedalus.migrate.heading_transform';

  /**
   * The fields the text block type must carry.
   */
  private const TEXT_FIELDS = ['field_text_tag', 'field_text_text', 'field_text_url'];

  /**
   * Hosts loaded per chunk.
   */
  private const BATCH = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function run(): ContentReport {
    $refusal = $this->refusal();
    if ($refusal !== NULL) {
      return new ContentReport(refusal: $refusal);
    }
    $transform = new HeadingTransform($this->allowedTags());
    $registry = $this->registry($transform);
    $hosts = [];
    $left = [];
    try {
      foreach ($this->entityFieldManager->getFieldMapByFieldType('layout_section') as $entity_type_id => $fields) {
        if (!$this->entityTypeManager->getStorage($entity_type_id) instanceof SqlContentEntityStorage) {
          continue;
        }
        foreach (array_keys($fields) as $field_name) {
          if ($field_name !== OverridesSectionStorage::FIELD_NAME) {
            $left[] = "$entity_type_id.$field_name";
            continue;
          }
          $hosts[$entity_type_id] = $this->walkHosts($entity_type_id, $registry, $transform);
        }
      }
    }
    catch (\Throwable $e) {
      return new ContentReport($hosts, $transform->dropped(), $left, failure: sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));
    }
    return new ContentReport($hosts, $transform->dropped(), $left);
  }

  /**
   * Why the phase cannot run, or NULL when it can.
   *
   * The text block type and its three fields come from the migration
   * recipe; without them there is nothing to rewrite the headings into.
   */
  private function refusal(): ?string {
    if (!$this->entityTypeManager->hasDefinition('block_content')) {
      return 'block_content is not installed; there are no inline blocks to migrate.';
    }
    if (!$this->entityTypeManager->getStorage('block_content_type')->load(HeadingTransform::TEXT_BUNDLE)) {
      return sprintf('The %s block type is not installed. Apply the migration recipe first (drush recipe <daedalus>/migrate), then run this phase again.', HeadingTransform::TEXT_BUNDLE);
    }
    $missing = array_diff(self::TEXT_FIELDS, array_keys($this->entityFieldManager->getFieldDefinitions('block_content', HeadingTransform::TEXT_BUNDLE)));
    if ($missing) {
      return sprintf('The %s block type lacks the fields %s. Apply the migration recipe first, then run this phase again.', HeadingTransform::TEXT_BUNDLE, implode(', ', $missing));
    }
    return NULL;
  }

  /**
   * The values field_text_tag allows on this site.
   *
   * @return list<string>
   *   The allowed values.
   */
  private function allowedTags(): array {
    $definition = $this->entityFieldManager->getFieldStorageDefinitions('block_content')['field_text_tag'];
    return array_map('strval', array_keys(options_allowed_values($definition)));
  }

  /**
   * The spine's registry over the phase's one transform.
   *
   * The registry loads its transforms from a container by id; the phase
   * hands it a container of its own holding the transform.
   */
  private function registry(HeadingTransform $transform): ComponentTransformRegistry {
    $services = new Container();
    $services->set(self::TRANSFORM_ID, $transform);
    return new ComponentTransformRegistry($services, [self::TRANSFORM_ID]);
  }

  /**
   * Rewrites and saves the default revisions of one host type.
   *
   * @return array{hosts: int, saved: int, components: int, items: list<string>}
   *   The hosts with a layout, the hosts saved, the components rewritten
   *   and one line per saved host.
   */
  private function walkHosts(string $entity_type_id, ComponentTransformRegistry $registry, HeadingTransform $transform): array {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->exists(OverridesSectionStorage::FIELD_NAME)
      ->sort($entity_type->getKey('id'))
      ->execute();
    $entry = ['hosts' => count($ids), 'saved' => 0, 'components' => 0, 'items' => []];
    foreach (array_chunk(array_values($ids), self::BATCH) as $chunk) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $host */
      foreach ($storage->loadMultiple($chunk) as $host) {
        $before = $transform->converted();
        if (!$registry->transformLayoutFields($host, [OverridesSectionStorage::FIELD_NAME])) {
          continue;
        }
        if ($host instanceof RevisionableInterface) {
          // The default revision is rewritten in place: the migration is not
          // an editorial change, and older revisions keep their identity.
          $host->setNewRevision(FALSE);
        }
        $host->save();
        $this->assertPersisted($storage->loadUnchanged($host->id()));
        $count = $transform->converted() - $before;
        $entry['saved']++;
        $entry['components'] += $count;
        $entry['items'][] = sprintf('%s %s (%s): %d', $entity_type_id, $host->id(), $host->label() ?? '', $count);
      }
      $storage->resetCache();
    }
    return $entry;
  }

  /**
   * Fails when a saved host still carries an unsaved replacement.
   *
   * The inline-block presave machinery persists a serialized block only
   * through the overrides section storage (an overridable display and a
   * layout with sections); a host it does not reach would keep the block
   * serialized in its component, unsaved and untracked, so the phase stops
   * there rather than leave it.
   */
  private function assertPersisted(ContentEntityInterface $host): void {
    $field = $host->get(OverridesSectionStorage::FIELD_NAME);
    if (!$field instanceof SectionListInterface) {
      throw new \RuntimeException(sprintf('%s %s does not carry a section list in %s.', $host->getEntityTypeId(), $host->id(), OverridesSectionStorage::FIELD_NAME));
    }
    foreach ($field->getSections() as $section) {
      $unsaved = $this->unsavedReplacements($section);
      if ($unsaved) {
        throw new \RuntimeException(sprintf('%s %s was saved but its components %s still hold their text block serialized: the inline-block machinery did not persist them. Is the layout of its display overridable?', $host->getEntityTypeId(), $host->id(), implode(', ', $unsaved)));
      }
    }
  }

  /**
   * The uuids of the text components a section still holds serialized.
   *
   * @return list<string>
   *   The component uuids, nested sections included.
   */
  private function unsavedReplacements(Section $section): array {
    $unsaved = [];
    foreach ($section->getComponents() as $component) {
      $configuration = $component->get('configuration');
      if (($configuration['id'] ?? '') === HeadingTransform::TEXT && !empty($configuration['block_serialized'])) {
        $unsaved[] = $component->getUuid();
      }
      $embedded = SectionBlock::embeddedSection($component);
      if ($embedded) {
        $unsaved = [...$unsaved, ...$this->unsavedReplacements($embedded)];
      }
    }
    return $unsaved;
  }

}
