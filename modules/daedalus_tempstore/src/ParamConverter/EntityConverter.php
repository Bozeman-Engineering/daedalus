<?php

declare(strict_types=1);

namespace Drupal\daedalus_tempstore\ParamConverter;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\Core\ParamConverter\EntityConverter as EntityConverterBase;
use Drupal\daedalus_tempstore\EntityTempstoreRepository;

/**
 * Entity Converter for daedalus_tempstore.
 *
 * Decorates core's entity param converter to swap entities with their
 * tempstore versions when the activation voters indicate it should and the
 * current user may update the stored entity.
 */
class EntityConverter extends EntityConverterBase {

  /**
   * The stored entity each swapped-in unsaved copy stands for.
   *
   * Keyed by the unsaved copy, so an entry lives exactly as long as the
   * copy does.
   *
   * @var \WeakMap<\Drupal\Core\Entity\EntityInterface, \Drupal\Core\Entity\EntityInterface>
   */
  private \WeakMap $stored;

  /**
   * The entities whose swap is being decided right now.
   *
   * Keyed by entity type id and entity id.
   *
   * @var array<string, true>
   */
  private array $deciding = [];

  /**
   * Constructs a new EntityConverter.
   *
   * @param \Drupal\Core\ParamConverter\ParamConverterInterface $inner
   *   The decorated inner param converter.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\daedalus_tempstore\EntityTempstoreRepository|null $tempstoreRepository
   *   The tempstore repository.
   * @param \Drupal\daedalus_tempstore\ParamConverter\ActivationVoters $activationVoters
   *   The activation voters.
   */
  public function __construct(
    protected ParamConverterInterface $inner,
    EntityTypeManagerInterface $entity_type_manager,
    EntityRepositoryInterface $entity_repository,
    protected ?EntityTempstoreRepository $tempstoreRepository,
    protected ActivationVoters $activationVoters,
  ) {
    parent::__construct($entity_type_manager, $entity_repository);
    $this->stored = new \WeakMap();
  }

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    $entity = parent::convert($value, $definition, $name, $defaults);
    if (!$entity) {
      return $entity;
    }

    return $this->loadEntityFromTempstore($entity);
  }

  /**
   * Load entity from tempstore.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to load from tempstore.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The tempstore version of the entity if it exists, differs, and the
   *   current user may update the stored entity; the original entity
   *   otherwise.
   */
  public function loadEntityFromTempstore(EntityInterface $entity): EntityInterface {
    if (!$this->activationVoters->isActive()) {
      return $entity;
    }

    if (!$this->tempstoreRepository) {
      return $entity;
    }

    // Deciding the swap reads both entities whole, and a computed field may
    // match the current route to answer (a token that names the current
    // page does), which converts this same parameter again. Left alone,
    // that inner ask decides the swap again, reads the entities again, and
    // never returns. It gets the entity it brought instead: the decision it
    // would wait for is the one already under way.
    $key = $entity->getEntityTypeId() . ':' . $entity->id();
    if (isset($this->deciding[$key])) {
      return $entity;
    }
    $this->deciding[$key] = TRUE;
    try {
      return $this->swap($entity);
    }
    finally {
      unset($this->deciding[$key]);
    }
  }

  /**
   * Answers the unsaved copy of an entity when there is one to answer.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity a route parameter was converted to.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The unsaved copy if it exists, differs, and the current user may
   *   update the stored entity; the entity given otherwise.
   */
  private function swap(EntityInterface $entity): EntityInterface {
    // The unsaved copy is for someone who may edit the entity as it is
    // saved. The voters say an editing surface is active; they do not say
    // this user may edit this entity.
    if (!($this->storedOf($entity) ?? $entity)->access('update')) {
      return $entity;
    }

    $tempstore_entity = $this->tempstoreRepository->get($entity);
    if ($tempstore_entity) {
      $tempstore_entity_array = $tempstore_entity->toArray();
      $entity_array = $entity->toArray();
      $difference = $this->recursiveArrayDiffAssoc($tempstore_entity_array, $entity_array);
      if (!empty($difference)) {
        // Who may reach the entity is a question for what is saved: the
        // copy's own values (its published state, its author) are unsaved
        // edits like any other.
        $this->stored[$tempstore_entity] = $entity;
        $entity = $tempstore_entity;
        // Notify all voters that an entity was swapped.
        $this->activationVoters->onEntitySwapped($entity);
      }
    }

    return $entity;
  }

  /**
   * The stored entity an unsaved copy was swapped in for.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   An entity a route parameter was converted to.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The stored entity when this converter answered its unsaved copy,
   *   NULL when the entity is not a copy it swapped in.
   */
  public function storedOf(EntityInterface $entity): ?EntityInterface {
    return $this->stored[$entity] ?? NULL;
  }

  /**
   * Recursively compare two arrays for differences.
   *
   * @param array $array1
   *   The first array.
   * @param array $array2
   *   The second array.
   *
   * @return array
   *   The differences found between the arrays.
   */
  private function recursiveArrayDiffAssoc(array $array1, array $array2): array {
    $difference = [];

    foreach ($array1 as $key => $value) {
      if (is_array($value) && isset($array2[$key]) && is_array($array2[$key])) {
        $diff = $this->recursiveArrayDiffAssoc($value, $array2[$key]);
        if (!empty($diff)) {
          $difference[$key] = $diff;
        }
      }
      elseif (!array_key_exists($key, $array2) || $value !== $array2[$key]) {
        $difference[$key] = $value;
      }
    }

    return $difference;
  }

}
