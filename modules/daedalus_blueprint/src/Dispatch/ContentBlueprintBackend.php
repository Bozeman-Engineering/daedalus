<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dispatch;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\BlueprintDeserializerInterface;
use Drupal\daedalus_blueprint\BlueprintOperationsInterface;
use Drupal\daedalus_blueprint\BlueprintSchemaBuilderInterface;
use Drupal\daedalus_blueprint\BlueprintSerializerInterface;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Result\BlueprintResultInterface;

/**
 * Blueprint backend for page hosts: what the host resolver hosts.
 *
 * The generic dispatch surface takes entities; this backend is the door
 * where an entity becomes its host and the host spine takes over: a
 * fieldable entity (a content host) or a Layout Builder enabled display (a
 * display host, a page document although the entity is config). Acts as
 * the fallback backend for any entity the host resolver can host.
 */
class ContentBlueprintBackend implements BlueprintBackendInterface {

  /**
   * Constructs a ContentBlueprintBackend.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\daedalus_blueprint\BlueprintSerializerInterface $serializer
   *   The blueprint serializer.
   * @param \Drupal\daedalus_blueprint\BlueprintDeserializerInterface $deserializer
   *   The blueprint deserializer.
   * @param \Drupal\daedalus_blueprint\BlueprintSchemaBuilderInterface $schemaBuilder
   *   The schema builder.
   * @param \Drupal\daedalus_blueprint\BlueprintOperationsInterface $operations
   *   The operations service.
   * @param \Drupal\daedalus_blueprint\EntityStorageHandler $storageHandler
   *   The entity storage handler.
   * @param \Drupal\daedalus_blueprint\Host\HostResolver $hostResolver
   *   The host resolver.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected BlueprintSerializerInterface $serializer,
    protected BlueprintDeserializerInterface $deserializer,
    protected BlueprintSchemaBuilderInterface $schemaBuilder,
    protected BlueprintOperationsInterface $operations,
    protected EntityStorageHandler $storageHandler,
    protected HostResolver $hostResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function supports(string $entity_type_id): bool {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if ($entity_type === NULL) {
      return FALSE;
    }
    return $entity_type->entityClassImplements(FieldableEntityInterface::class);
  }

  /**
   * {@inheritdoc}
   */
  public function supportsEntity(EntityInterface $entity): bool {
    return $this->hostResolver->hosts($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function serialize(EntityInterface $entity, ?string $langcode = NULL): array {
    return $this->serializer->serialize($this->host($entity), $langcode);
  }

  /**
   * {@inheritdoc}
   */
  public function serializeSummary(EntityInterface $entity, ?string $langcode = NULL): array {
    return $this->serializer->serializeSummary($this->host($entity), $langcode);
  }

  /**
   * {@inheritdoc}
   */
  public function getSchema(string $entity_type_id, ?string $bundle = NULL, ?string $view_mode = NULL): array {
    if ($bundle === NULL) {
      $bundle = $entity_type_id;
    }
    return $this->schemaBuilder->getSchema($entity_type_id, $bundle, $view_mode);
  }

  /**
   * {@inheritdoc}
   */
  public function create(array $blueprint, array $options = []): BlueprintResultInterface {
    return $this->operations->createEntity($blueprint, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function update(EntityInterface $entity, array $data, array $options = []): BlueprintResultInterface {
    return $this->operations->updateFields($this->host($entity), $data, $options);
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
    return $this->operations->batchOperations($this->host($entity), $operations, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function persist(EntityInterface $entity, bool $is_new = FALSE): void {
    $this->storageHandler->persist($this->host($entity), $is_new);
  }

  /**
   * {@inheritdoc}
   */
  public function load(string $entity_type_id, int|string $entity_id): ?EntityInterface {
    return $this->storageHandler->load($entity_type_id, $entity_id)?->entity();
  }

  /**
   * {@inheritdoc}
   */
  public function requiresWorkspace(): bool {
    return $this->storageHandler->isWorkspaceRequired();
  }

  /**
   * The host of an entity this backend was handed.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface
   *   Its host.
   */
  public function host(EntityInterface $entity): HostInterface {
    return $this->hostResolver->fromEntity($entity);
  }

  /**
   * Returns the underlying operations service for content-specific methods.
   *
   * @return \Drupal\daedalus_blueprint\BlueprintOperationsInterface
   *   The operations service.
   */
  public function getOperations(): BlueprintOperationsInterface {
    return $this->operations;
  }

  /**
   * Returns the underlying serializer for content-specific methods.
   *
   * @return \Drupal\daedalus_blueprint\BlueprintSerializerInterface
   *   The serializer service.
   */
  public function getSerializer(): BlueprintSerializerInterface {
    return $this->serializer;
  }

  /**
   * Returns the underlying storage handler for content-specific methods.
   *
   * @return \Drupal\daedalus_blueprint\EntityStorageHandler
   *   The storage handler.
   */
  public function getStorageHandler(): EntityStorageHandler {
    return $this->storageHandler;
  }

}
