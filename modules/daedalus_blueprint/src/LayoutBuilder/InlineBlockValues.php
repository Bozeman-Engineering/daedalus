<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerManager;
use Drupal\layout_builder\SectionComponent;
use Psr\Log\LoggerInterface;

/**
 * Inline blocks as values: a block's fields in the field grammar.
 *
 * A content host persists an inline block as a block_content entity and
 * its component names the entity by revision id. A host whose persistence
 * is valueBlocks (a display: config, shipped through config export, which
 * cannot carry a serial id) persists the block as VALUES instead: the
 * component's `block_values` key holds the block's fields in the spine's
 * own field grammar, the form a blueprint already stores, with references
 * spelled by uuid so the template resolves on any site. At build the
 * plugin hydrates the values into an unsaved block (daedalus_layout's
 * inline block plugin subclass), so the render, the Configure dialog and
 * every op see a block entity as they always did; only the storage shape
 * differs. No block entity, no revision id, no usage row.
 *
 * Value blocks hold what the field grammar can spell: a non-empty field
 * whose handler serializes nothing (a form-only field in the grammar) has
 * no value to store, so a block carrying one is unspellable and the op
 * that would place it on a template answers a corrective instead. A
 * contrib field type joins by having a field handler, the same way a block
 * plugin joins by having config schema.
 *
 * This service is the one owner of the `block_values` shape: the ops and
 * the deserializer refuse through unspellable(), the inline block
 * machinery writes through store() at a display's save, the migration
 * transform rewrites stored displays through it, and the adapter and the
 * plugin hydrate through hydrate().
 */
class InlineBlockValues {

  /**
   * The component configuration key holding the values.
   */
  public const KEY = 'block_values';

  /**
   * The configuration keys that name a block ENTITY, dropped by store().
   */
  public const ENTITY_KEYS = ['block_serialized', 'block_revision_id', 'block_id', 'block_uuid'];

  /**
   * The field types whose items name an entity by serial id.
   *
   * Their `target_id` is re-spelled as `uuid` in a value block (the
   * reference handlers accept both).
   */
  protected const REFERENCE_FIELD_TYPES = ['entity_reference', 'file', 'image'];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FieldHandlerManager $fieldHandlerManager,
    protected BlueprintAccessChecker $accessChecker,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Whether a component configuration carries values.
   *
   * @param array $configuration
   *   The component configuration.
   *
   * @return bool
   *   TRUE when block_values is present and an array.
   */
  public static function hasValues(array $configuration): bool {
    return isset($configuration[self::KEY]) && is_array($configuration[self::KEY]);
  }

  /**
   * The block's fields as values.
   *
   * Every processable field the current user may view, through its
   * handler, with references spelled by uuid. Empty fields carry nothing
   * (hydration leaves them empty), and a field the grammar cannot spell is
   * absent too: ask unspellable() before storing when the loss matters.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $block
   *   The block content entity.
   *
   * @return array
   *   The values, keyed by field name.
   */
  public function valuesOf(FieldableEntityInterface $block): array {
    $values = [];
    foreach ($this->fieldHandlerManager->getProcessableFieldDefinitions($block) as $field_name => $definition) {
      $items = $block->get($field_name);
      if (!$this->accessChecker->fieldVisible($items)) {
        continue;
      }
      $value = $this->fieldHandlerManager->getHandler($definition)->serialize($items, $definition);
      if ($value === NULL) {
        continue;
      }
      $values[$field_name] = $this->spellReferencesByUuid($value, $definition);
    }
    return $values;
  }

  /**
   * The block's fields a value block cannot hold.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $block
   *   The block content entity.
   *
   * @return string[]
   *   The names of the non-empty fields whose handler serializes nothing
   *   (form-only in the grammar); empty when the block is spellable.
   */
  public function unspellable(FieldableEntityInterface $block): array {
    $names = [];
    foreach ($this->fieldHandlerManager->getProcessableFieldDefinitions($block) as $field_name => $definition) {
      $items = $block->get($field_name);
      if ($items->isEmpty()) {
        continue;
      }
      if (!$this->accessChecker->fieldVisible($items)
        || $this->fieldHandlerManager->getHandler($definition)->serialize($items, $definition) === NULL) {
        $names[] = $field_name;
      }
    }
    return $names;
  }

  /**
   * Hydrates values into an unsaved, non-reusable block.
   *
   * A load, not a write: no field access or text format is judged (the
   * values were judged when they were stored; the handlers run under the
   * system posture) and nothing is saved. A value a handler refuses
   * (a reference whose target is gone) is logged and left empty, the same
   * fate a deleted target has in any stored field, so one missing media
   * never takes a template's every page down with it.
   *
   * @param string $block_type
   *   The block_content bundle.
   * @param array $values
   *   The values, as valuesOf() spelled them.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface
   *   The unsaved block.
   */
  public function hydrate(string $block_type, array $values): FieldableEntityInterface {
    /** @var \Drupal\Core\Entity\FieldableEntityInterface $block */
    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => $block_type,
      'reusable' => FALSE,
    ]);
    $processable = $this->fieldHandlerManager->getProcessableFieldDefinitions($block);
    foreach ($values as $field_name => $value) {
      if (!isset($processable[$field_name])) {
        $this->logger->warning('Value block of type @type carries a field @field that is not in the grammar; the value was skipped.', [
          '@type' => $block_type,
          '@field' => $field_name,
        ]);
        continue;
      }
      $definition = $processable[$field_name];
      $result = $this->fieldHandlerManager->getHandler($definition)->deserialize($value, $definition, [
        'entity' => $block,
        'fields' => $values,
        'system' => TRUE,
      ]);
      if ($result->hasErrors()) {
        foreach ($result->getErrors() as $error) {
          $this->logger->warning('Value block of type @type: @field could not be hydrated (@code: @message); the field was left empty.', [
            '@type' => $block_type,
            '@field' => $field_name,
            '@code' => $error->getCode(),
            '@message' => $error->getMessage(),
          ]);
        }
        continue;
      }
      $block->set($field_name, $result->getValue());
    }
    return $block;
  }

  /**
   * Stores a block on its component as values.
   *
   * The block's values land under block_values and every key naming the
   * block as an entity is dropped, so the component (and the config export
   * carrying it) names no content id.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The inline block component.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $block
   *   The block content entity to spell.
   */
  public function store(SectionComponent $component, FieldableEntityInterface $block): void {
    $configuration = $component->get('configuration');
    $configuration[self::KEY] = $this->valuesOf($block);
    foreach (self::ENTITY_KEYS as $key) {
      unset($configuration[$key]);
    }
    $component->setConfiguration($configuration);
  }

  /**
   * Re-spells a reference field's items by uuid.
   *
   * @param mixed $value
   *   The handler's serialized value: one item or a list of them.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The field definition.
   *
   * @return mixed
   *   The value with each item's target_id replaced by the target's uuid;
   *   a non-reference field's value untouched.
   */
  protected function spellReferencesByUuid(mixed $value, FieldDefinitionInterface $definition): mixed {
    $type = $definition->getType();
    if (!in_array($type, self::REFERENCE_FIELD_TYPES, TRUE) || !is_array($value)) {
      return $value;
    }
    $target_type = $type === 'entity_reference' ? (string) $definition->getSetting('target_type') : 'file';
    $storage = $this->entityTypeManager->getStorage($target_type);
    $spell = function (array $item) use ($storage): array {
      if (!isset($item['target_id'])) {
        return $item;
      }
      $target = $storage->load($item['target_id']);
      if ($target === NULL) {
        return $item;
      }
      unset($item['target_id']);
      return ['uuid' => $target->uuid()] + $item;
    };
    if (array_is_list($value)) {
      return array_map(static fn ($item) => is_array($item) ? $spell($item) : $item, $value);
    }
    return $spell($value);
  }

}
