<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\EventSubscriber;

use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\ElementLocator;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint\Result\TargetType;
use Drupal\daedalus_blueprint_api\Event\BlueprintMutationResponseEvent;
use Drupal\layout_builder\SectionComponent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Clears stale cached edit forms when a mutation changes field values.
 *
 * The Edit session caches each entity's loaded form in the sidebar DOM and
 * reuses it on the next edit (entity-form.js getForm()). A mutation that
 * changes an entity's VALUES outside that form (an AI tool edit, a deferred
 * image landing, any blueprint op) leaves the cached form stale, and its
 * next blur would write the pre-mutation values back over the change. Every
 * such mutation answers the mutation-response contract, so the clear rides
 * the contract's piggybacked commands: for each value-changing target, an
 * daedalusEditClearForm command removes the owning entity's cached form and the
 * next edit fetches a fresh one.
 *
 * Structural targets (the _added/_removed/... sentinels) deliberately do NOT
 * clear: a move or duplicate changes no values, and evicting the open form
 * on every drag would tear down the user's editing context for nothing.
 */
class BlueprintMutationClearForms implements EventSubscriberInterface {

  public function __construct(
    protected ElementLocator $elementLocator,
    protected DaedalusLayoutAdapter $daedalusLayoutAdapter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [BlueprintMutationResponseEvent::class => 'onMutationResponse'];
  }

  /**
   * Adds form-clear commands for entities whose field values changed.
   *
   * @param \Drupal\daedalus_blueprint_api\Event\BlueprintMutationResponseEvent $event
   *   The mutation response assembly event.
   */
  public function onMutationResponse(BlueprintMutationResponseEvent $event): void {
    $result = $event->result;
    if (!$result->isSuccess() || !$result instanceof BlueprintResult) {
      return;
    }
    $entity = $result->getEntity();
    if (!$entity instanceof FieldableEntityInterface) {
      return;
    }

    $root_uuid = $entity->uuid();
    $cleared = [];
    foreach ($result->getTargets() as $target) {
      $uuid = (string) ($target['uuid'] ?? '');
      $field_name = (string) ($target['field_name'] ?? '');
      if ($uuid === '' || $field_name === '' || TargetType::isSentinel($field_name)) {
        continue;
      }

      $owner = $uuid === $root_uuid ? $entity : $this->resolveBlockContent($entity, $uuid);
      if ($owner === NULL) {
        continue;
      }

      // The id DaedalusEditFormTrait::getDaedalusEditFormId() builds (the trait
      // carries abstract members, so it cannot be used here).
      $form_id = 'daedalus-edit-form_' . $owner->getEntityTypeId() . '-' . daedalus_ui_entity_identifier($owner);
      if (isset($cleared[$form_id])) {
        continue;
      }
      $cleared[$form_id] = TRUE;
      $event->addCommand(new InvokeCommand(NULL, 'daedalusEditClearForm', ["#$form_id"]));
    }
  }

  /**
   * Resolves an element UUID to its inline block's block_content entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The root entity containing the layout.
   * @param string $uuid
   *   The target UUID (a SectionComponent UUID for inline-block targets).
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The block_content entity, or NULL when the target is not an inline
   *   block (sections, paragraphs, non-inline placements).
   */
  protected function resolveBlockContent(FieldableEntityInterface $entity, string $uuid): ?FieldableEntityInterface {
    $context = $this->elementLocator->locate($entity, $uuid);
    if ($context === NULL || !$context->isLbComponent() || !$context->target instanceof SectionComponent) {
      return NULL;
    }
    return $this->daedalusLayoutAdapter->extractBlockContent($context->target);
  }

}
