<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_blueprint\BlueprintOperationsInterface;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint_api\Ajax\BlueprintApplyResponseCommand;
use Drupal\daedalus_blueprint_api\MutationResponseBuilder;
use Drupal\daedalus_tempstore\History\HistoryManager;
use Drupal\daedalus_tempstore\History\HistoryRecorder;
use Drupal\daedalus_ui\Event\LayoutBuilderReplaceMedia;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The media drop on a placed block: one update_element op on the spine.
 *
 * A file dragged from the desktop onto a media reference's drop target is
 * uploaded by daedalus_ui's MediaUpload controller, which creates the media
 * entity and dispatches LayoutBuilderReplaceMedia, the block context riding
 * the request query (blockUuid) when the reference sits in a placed block.
 * This subscriber is where the drop meets the op spine: it runs
 * `update_element {element: uuid, properties: {media field: {target_id}}}`
 * on the entity whose field it is (the placed block; the host's root when
 * no block is named or the block is a field block presenting the host's
 * own field, the one rule the capability resolver keeps content open by)
 * on the root's working copy through the existing reference handler,
 * persists through the storage handler, annotates the history entry, and
 * answers the standard mutation-response payload as one
 * BlueprintApplyResponseCommand, applied client-side through the app's one
 * applyResponse pipeline (the swap by key, the mirror, the timeline).
 * Nothing here serializes a block or renders a layout: the drop is exactly
 * the op an AI tool or the panel form would issue for the same change, and
 * the op door judges it (a template's block on a plain host is refused as
 * foreign).
 */
class DaedalusUiReplaceMedia implements EventSubscriberInterface {

  use StringTranslationTrait;

  public function __construct(
    protected RequestStack $requestStack,
    protected BlueprintOperationsInterface $operations,
    protected EntityStorageHandler $storageHandler,
    protected MutationResponseBuilder $responseBuilder,
    protected HistoryRecorder $historyRecorder,
    protected HistoryManager $historyManager,
    protected DaedalusLayoutAdapter $layoutAdapter,
    protected HostResolver $hostResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      LayoutBuilderReplaceMedia::class => ['onReplace'],
    ];
  }

  /**
   * Commits the replacement as an op and answers the mutation payload.
   *
   * The root entity the event carries is already the working copy (the
   * controller resolved it through the entity tempstore); the langcode
   * rides the request query as it does for the mutate endpoint, NULL for
   * the default translation. A failed result (an unknown component, a field
   * the block lacks, a refused reference) answers the same payload shape
   * with success false and the spine's errors, which the client judges as
   * any op failure.
   *
   * @param \Drupal\daedalus_ui\Event\LayoutBuilderReplaceMedia $event
   *   The replace media event.
   */
  public function onReplace(LayoutBuilderReplaceMedia $event): void {
    $request = $this->requestStack->getCurrentRequest();
    $langcode = $request->query->get('langcode') ?: NULL;
    $field_name = $event->getMediaReference();
    $entity = $event->getEntity();
    if (!$entity instanceof FieldableEntityInterface) {
      throw new BadRequestHttpException('Daedalus Layout: the media replacement targets a non-fieldable entity.');
    }
    $root = $this->hostResolver->fromEntity($entity);
    $element = $this->elementOf($root, $request->query->get('blockUuid'), $field_name);

    $result = $this->operations->batchOperations($root, [
      [
        'op' => 'update_element',
        'element' => $element,
        'properties' => [$field_name => ['target_id' => (int) $event->getMedia()->id()]],
      ],
    ], ['langcode' => $langcode]);

    $extra = [];
    if ($result->isSuccess()) {
      $this->historyRecorder->annotate((string) $this->t('Replaced @label', ['@label' => $this->fieldLabel($root, $element, $field_name)]), $result->getTargets());
      $extra['persisted'] = $this->storageHandler->persist(
        $result->getHost(),
        element_uuids: $result->getElementUuids(),
      );
      // Flush now (instead of the end-of-request fallback) so the response
      // carries the timeline the drop just extended.
      $this->historyRecorder->flush();
      $extra['history'] = $this->historyManager->metadata($root);
    }

    // The drop lands on the canvas root's page, so the root is the canvas.
    $json = $this->responseBuilder->build($request, $entity, $result, $langcode, $extra, $event->getViewMode(), $root);
    $response = new AjaxResponse();
    $response->addCommand(new BlueprintApplyResponseCommand(Json::decode($json->getContent())));
    $event->setResponse($response);
  }

  /**
   * The entity the dropped-on field belongs to: a placed block, or the root.
   *
   * The block context names the placement the drop target sits in; a field
   * block presenting the host's own field shows the host's field, so the
   * write is the root's. No block context (a field rendered outside the
   * layout, a page without one) is the root's field too.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $root
   *   The host.
   * @param mixed $block_uuid
   *   The blockUuid query value, if any.
   * @param string $field_name
   *   The media reference field.
   *
   * @return string
   *   The uuid the op targets.
   */
  protected function elementOf(HostInterface $root, mixed $block_uuid, string $field_name): string {
    if (!is_string($block_uuid) || $block_uuid === '') {
      return $root->uuid();
    }
    return $this->layoutAdapter->presentedHostField($root, $block_uuid) === $field_name ? $root->uuid() : $block_uuid;
  }

  /**
   * The replaced field's label, for the history entry.
   *
   * Read off the entity the op just wrote (the root, or the placed block
   * located through the spine's own adapter), falling back to the field
   * name when the block cannot be extracted.
   */
  protected function fieldLabel(HostInterface $root, string $element, string $field_name): string {
    if ($element === $root->uuid()) {
      $fields = $root->fields();
      return $fields?->hasField($field_name) ? (string) $fields->getFieldDefinition($field_name)->getLabel() : $field_name;
    }
    $context = $this->layoutAdapter->locateElement($root, $element);
    $block = $context?->isLbComponent() ? $this->layoutAdapter->extractBlockContent($context->target) : NULL;
    if ($block?->hasField($field_name)) {
      return (string) $block->getFieldDefinition($field_name)->getLabel();
    }
    return $field_name;
  }

}
