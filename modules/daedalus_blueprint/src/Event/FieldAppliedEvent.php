<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\daedalus_blueprint\FieldValueKind;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Fired after a field write lands on an entity mid-operation.
 *
 * Dispatched by BlueprintOperations::validateAndApplyFields() once per field
 * it sets, for every write path that method serves (root fields, inline
 * block fields, paragraph fields, on update and on add) and for both value
 * kinds: a grammar value a handler deserialized and an ExtractedValue the
 * caller built in-process. It fires after the set and before constraint
 * validation, so a subscriber that rewrites the applied value (the
 * stage-time identity seam minting s-* classes into text) has its rewrite
 * validated and staged exactly as the operation's own value is.
 *
 * The host is the root the operation ran against. The entity carried is
 * the one the field was written on: the host's fields entity for a root
 * field, the nested block or paragraph otherwise; the element uuid is the
 * blueprint address of that entity (the host's uuid, the Layout Builder
 * component uuid, the paragraph uuid), the same uuid the operation reports
 * as the write's target. The applied field is already a target of the
 * operation, so a subscriber that rewrites it declares nothing; a
 * subscriber that writes some OTHER field has changed something the
 * operation cannot re-render, and this event carries no target bag on
 * purpose: rewrite the applied field, or use an operation.
 */
final class FieldAppliedEvent extends Event {

  /**
   * Constructs a FieldAppliedEvent.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the operation ran against.
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity the field was set on.
   * @param string $fieldName
   *   The field that was set.
   * @param string $elementUuid
   *   The blueprint uuid of the element the entity is (host, component or
   *   paragraph uuid).
   * @param \Drupal\daedalus_blueprint\FieldValueKind $valueKind
   *   Which kind of value landed.
   */
  public function __construct(
    private readonly HostInterface $host,
    private readonly FieldableEntityInterface $entity,
    private readonly string $fieldName,
    private readonly string $elementUuid,
    private readonly FieldValueKind $valueKind,
  ) {}

  /**
   * The host the operation ran against.
   */
  public function getHost(): HostInterface {
    return $this->host;
  }

  /**
   * The entity the field was set on.
   */
  public function getEntity(): FieldableEntityInterface {
    return $this->entity;
  }

  /**
   * The name of the field that was set.
   */
  public function getFieldName(): string {
    return $this->fieldName;
  }

  /**
   * The blueprint uuid of the element whose field was set.
   */
  public function getElementUuid(): string {
    return $this->elementUuid;
  }

  /**
   * Which kind of value landed on the field.
   */
  public function getValueKind(): FieldValueKind {
    return $this->valueKind;
  }

  /**
   * The applied field's live item list, for reading or rewriting in place.
   */
  public function getItems(): FieldItemListInterface {
    return $this->entity->get($this->fieldName);
  }

}
