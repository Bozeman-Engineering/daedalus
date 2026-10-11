<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_config\Result;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\daedalus_blueprint\Result\BlueprintResultBase;

/**
 * Result of a blueprint operation on a config entity.
 *
 * Carries the config entity the operation produced; everything else is the
 * shared result machinery.
 */
class ConfigBlueprintResult extends BlueprintResultBase {

  /**
   * Constructs a new result.
   *
   * @param string $phase
   *   The phase reached ('structural', 'semantic', 'hydrated', 'validated').
   * @param \Drupal\daedalus_blueprint\Result\BlueprintError[] $errors
   *   The errors collected during the operation.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   The warnings collected during the operation.
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface|null $entity
   *   The config entity if the operation succeeded.
   */
  public function __construct(
    string $phase,
    array $errors = [],
    array $warnings = [],
    protected readonly ?ConfigEntityInterface $entity = NULL,
  ) {
    parent::__construct($phase, $errors, $warnings);
  }

  /**
   * Creates a successful result with a hydrated config entity.
   *
   * @param \Drupal\Core\Config\Entity\ConfigEntityInterface $entity
   *   The hydrated entity.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Any warnings collected.
   *
   * @return static
   *   A new successful result.
   */
  public static function success(ConfigEntityInterface $entity, array $warnings = []): static {
    return new static('hydrated', [], $warnings, $entity);
  }

  /**
   * Returns the config entity if the operation succeeded.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface|null
   *   The config entity, or NULL if the operation failed or was validate-only.
   */
  public function getEntity(): ?ConfigEntityInterface {
    return $this->entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function subjectSummary(): array {
    if ($this->entity === NULL) {
      return [];
    }
    $summary = ['entity_type' => $this->entity->getEntityTypeId()];
    if ($this->entity->id()) {
      $summary['entity_id'] = $this->entity->id();
    }
    if ($this->entity->label()) {
      $summary['label'] = $this->entity->label();
    }
    return $summary;
  }

}
