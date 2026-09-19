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
 * tempstore versions when the activation voters indicate it should.
 */
class EntityConverter extends EntityConverterBase {

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
   *   The tempstore version of the entity if it exists and differs,
   *   or the original entity.
   */
  public function loadEntityFromTempstore(EntityInterface $entity): EntityInterface {
    if (!$this->activationVoters->isActive()) {
      return $entity;
    }

    if (!$this->tempstoreRepository) {
      return $entity;
    }

    $tempstore_entity = $this->tempstoreRepository->get($entity);
    if ($tempstore_entity) {
      $tempstore_entity_array = $tempstore_entity->toArray();
      $entity_array = $entity->toArray();
      $difference = $this->recursiveArrayDiffAssoc($tempstore_entity_array, $entity_array);
      if (!empty($difference)) {
        $entity = $tempstore_entity;
        // Notify all voters that an entity was swapped.
        $this->activationVoters->onEntitySwapped($entity);
      }
    }

    return $entity;
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
