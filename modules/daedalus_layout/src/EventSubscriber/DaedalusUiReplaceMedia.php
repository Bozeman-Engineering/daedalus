<?php

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\daedalus_ui\Ajax\UpdateElement;
use Drupal\daedalus_ui\Event\LayoutBuilderReplaceMedia;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\RemoveCommand;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\daedalus_layout\SectionStorage\NestedAwareSectionStorage;
use Drupal\layout_builder\LayoutTempstoreRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Daedalus UI replace media.
 */
class DaedalusUiReplaceMedia implements EventSubscriberInterface {

  public function __construct(
    protected RequestStack $requestStack,
    protected LayoutTempstoreRepositoryInterface $layoutBuilderTempstore,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events = [];
    if (class_exists(LayoutBuilderReplaceMedia::class)) {
      $events[LayoutBuilderReplaceMedia::class] = ['onReplace'];
    }
    return $events;
  }

  /**
   * On replace.
   *
   * A user has dragged a Media Type compatible file from their desktop to a
   * dropzone on an existing Media Block on the page. We then replace the media.
   *
   * @param \Drupal\daedalus_ui\Event\LayoutBuilderReplaceMedia $event
   *   The replace media event.
   *
   * @throws \Drupal\Component\Plugin\Exception\ContextException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\TypedData\Exception\ReadOnlyException
   */
  public function onReplace(LayoutBuilderReplaceMedia $event) {
    $media = $event->getMedia();
    $entity = $event->getEntity();
    $media_reference_field = $event->getMediaReference();
    $parameters = $this->requestStack->getCurrentRequest()->query->all();
    $block_uuid = $parameters['blockUuid'];

    // Get the storage from tempstore (where pending changes live).
    $canonical_storage = NestedAwareSectionStorage::getSectionStorageForEntity($entity);
    $section_storage = $this->layoutBuilderTempstore->get($canonical_storage);

    $component = $section_storage->getComponent($block_uuid);
    if (!$component) {
      throw new \InvalidArgumentException(sprintf('Block with UUID "%s" not found', $block_uuid));
    }

    $media_block_plugin = $component->getPlugin();
    $media_block_configuration = $media_block_plugin->getConfiguration();
    $media_block = $section_storage->getBlockContentFromPlugin($media_block_plugin);
    $event->setMediaBlock($media_block);

    if (!$media_block->hasField($media_reference_field)) {
      throw new \InvalidArgumentException(sprintf('Invalid media reference field "%s"', $media_reference_field));
    }

    $media_block->get($media_reference_field)->setValue($media->id());
    $media_block_configuration['block_serialized'] = serialize($media_block);
    $media_block_plugin->setConfiguration($media_block_configuration);
    $component->setConfiguration($media_block_plugin->getConfiguration());

    $this->layoutBuilderTempstore->set($section_storage);

    $response = new AjaxResponse();
    $response->addCommand(new RemoveCommand('[data-drupal-messages]'));
    $layout = [
      '#type' => 'layout_builder_plus',
      '#section_storage' => $section_storage,
    ];
    $selector = sprintf('[data-layout-builder-block-uuid="%s"]', $component->getUuid());
    $response->addCommand(new UpdateElement($layout, [$selector]));

    $event->setResponse($response);
  }

}
