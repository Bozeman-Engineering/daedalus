<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendInterface;
use Drupal\daedalus_blueprint\Result\BlueprintResultInterface;

/**
 * Blueprint backend for config entities.
 *
 * Delegates to config-specific services. Registered at priority 0 so it is
 * checked before the content backend (priority -10).
 */
class ConfigBlueprintBackend implements BlueprintBackendInterface {

  /**
   * Constructs a ConfigBlueprintBackend.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\daedalus_blueprint_config\ConfigSerializer $serializer
   *   The config serializer.
   * @param \Drupal\daedalus_blueprint_config\ConfigDeserializer $deserializer
   *   The config deserializer.
   * @param \Drupal\daedalus_blueprint_config\ConfigSchemaBuilder $schemaBuilder
   *   The config schema builder.
   * @param \Drupal\daedalus_blueprint_config\ConfigOperations $operations
   *   The config operations service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ConfigSerializer $serializer,
    protected ConfigDeserializer $deserializer,
    protected ConfigSchemaBuilder $schemaBuilder,
    protected ConfigOperations $operations,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(string $entity_type_id): bool {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if ($entity_type === NULL) {
      return FALSE;
    }
    return $entity_type->entityClassImplements(ConfigEntityInterface::class);
  }

  /**
   * {@inheritdoc}
   */
  public function serialize(EntityInterface $entity, ?string $langcode = NULL): array {
    if (!$entity instanceof ConfigEntityInterface) {
      throw new \InvalidArgumentException('ConfigBlueprintBackend requires a ConfigEntityInterface entity.');
    }
    return $this->serializer->serialize($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function serializeSummary(EntityInterface $entity, ?string $langcode = NULL): array {
    if (!$entity instanceof ConfigEntityInterface) {
      throw new \InvalidArgumentException('ConfigBlueprintBackend requires a ConfigEntityInterface entity.');
    }
    return $this->serializer->serializeSummary($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getSchema(string $entity_type_id, ?string $bundle = NULL, ?string $view_mode = NULL): array {
    return $this->schemaBuilder->getSchema($entity_type_id);
  }

  /**
   * {@inheritdoc}
   */
  public function create(array $blueprint, array $options = []): BlueprintResultInterface {
    return $this->deserializer->deserialize($blueprint);
  }

  /**
   * {@inheritdoc}
   */
  public function update(EntityInterface $entity, array $data, array $options = []): BlueprintResultInterface {
    if (!$entity instanceof ConfigEntityInterface) {
      throw new \InvalidArgumentException('ConfigBlueprintBackend requires a ConfigEntityInterface entity.');
    }
    return $this->operations->updateProperties($entity, $data);
  }

  /**
   * {@inheritdoc}
   */
  public function validate(array $blueprint): BlueprintResultInterface {
    return $this->deserializer->deserialize($blueprint, ['validate_only' => TRUE]);
  }

  /**
   * {@inheritdoc}
   */
  public function batchOperations(EntityInterface $entity, array $operations, array $options = []): BlueprintResultInterface {
    if (!$entity instanceof ConfigEntityInterface) {
      throw new \InvalidArgumentException('ConfigBlueprintBackend requires a ConfigEntityInterface entity.');
    }
    return $this->operations->batchOperations($entity, $operations);
  }

  /**
   * {@inheritdoc}
   */
  public function persist(EntityInterface $entity, bool $is_new = FALSE): void {
    if (!$entity instanceof ConfigEntityInterface) {
      throw new \InvalidArgumentException('ConfigBlueprintBackend requires a ConfigEntityInterface entity.');
    }
    $entity->save();
  }

  /**
   * {@inheritdoc}
   */
  public function load(string $entity_type_id, int|string $entity_id): ?EntityInterface {
    $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($entity_id);

    if (!$entity instanceof ConfigEntityInterface) {
      return NULL;
    }

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function requiresWorkspace(): bool {
    return FALSE;
  }

  /**
   * Returns the underlying operations service for config-specific methods.
   *
   * @return \Drupal\daedalus_blueprint_config\ConfigOperations
   *   The operations service.
   */
  public function getOperations(): ConfigOperations {
    return $this->operations;
  }

}
