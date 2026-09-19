<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

/**
 * Holds the entity the current AI request is operating on.
 *
 * Request-scoped, mutable state. AI tools and skills execute with no access to
 * the agent or its prompt context (see \Drupal\ai\Base\FunctionCallBase: a
 * function call receives only its LLM-supplied arguments and injected
 * services), so "the entity the user is looking at" — which only the UI layer
 * knows — cannot be read from the agent at execution time. A provider that
 * knows the current entity (e.g. daedalus_ai, from the chat's originating route)
 * sets it during request setup; consumers (e.g. daedalus_ai token propagation to
 * sub-agents) read it to scope behavior to the page in front of the user.
 *
 * Empty when nothing populated it (Drush, off-page calls). Consumers fall back
 * to bundle-agnostic behavior in that case.
 */
class CurrentEntityContext {

  /**
   * The entity type ID, or NULL when no current entity is set.
   *
   * @var string|null
   */
  protected ?string $entityTypeId = NULL;

  /**
   * The bundle machine name, or NULL when no current entity is set.
   *
   * @var string|null
   */
  protected ?string $bundle = NULL;

  /**
   * Sets the current entity context.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle machine name.
   */
  public function set(string $entity_type_id, string $bundle): void {
    $this->entityTypeId = $entity_type_id;
    $this->bundle = $bundle;
  }

  /**
   * Gets the current entity type ID.
   *
   * @return string|null
   *   The entity type ID, or NULL if none is set.
   */
  public function getEntityTypeId(): ?string {
    return $this->entityTypeId;
  }

  /**
   * Gets the current bundle.
   *
   * @return string|null
   *   The bundle machine name, or NULL if none is set.
   */
  public function getBundle(): ?string {
    return $this->bundle;
  }

  /**
   * Whether a current entity context has been set.
   *
   * @return bool
   *   TRUE if both the entity type and bundle are set.
   */
  public function hasEntity(): bool {
    return $this->entityTypeId !== NULL && $this->bundle !== NULL;
  }

}
