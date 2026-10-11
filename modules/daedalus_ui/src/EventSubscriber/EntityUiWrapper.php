<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Core\Render\Markup;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_ui\ModePluginManager;
use Drupal\twig_events\Event\TwigRenderTemplateEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Guarantees entity UI attributes reach the page.
 *
 * The entity UI attributes (the wrapper id AJAX refreshes target, the view
 * mode, the entity root's selectable key, and the main-entity markers) are
 * stamped on the entity build at build time and delivered through
 * {{ attributes }} printed on the entity template's outermost element
 * (DaedalusUiHooks::entityView(); LayoutBuilderBlockAttributes for
 * content blocks, whose markup root is the block template). A template that
 * does not print {{ attributes }} silently severs the entity from the
 * editing UI: the failure mode that killed core's Quickedit.
 *
 * This subscriber closes that hole at the entity level, mirroring
 * SelectableFieldDelivery for fields: after an entity template renders, when
 * the wrapper id attribute did not reach the output, the rendered output is
 * wrapped in a fallback div carrying the full attribute set, and a warning
 * names the offending template so the theme can be fixed. In the normal case
 * the markup is untouched and theme fidelity is perfect.
 */
final class EntityUiWrapper implements EventSubscriberInterface {

  public function __construct(
    protected DaedalusUi $daedalusUi,
    protected ModePluginManager $modeManager,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Wraps a rendered entity template in the Edit Mode entity wrapper.
   */
  public function onTwigRenderTemplate(TwigRenderTemplateEvent $event): void {
    if (!$this->modeManager->createInstance('edit')->applies()) {
      return;
    }
    $variables = $event->getVariables();
    $entity_info = $this->getEntityInfo($variables);
    if (empty($entity_info)) {
      return;
    }

    // The normal case: the build-time #attributes stamp reached the output
    // through {{ attributes }}.
    $wrapper_id = sprintf('%s::%s::%s', $entity_info['entity_type'], $entity_info['entity_id'], $entity_info['bundle']);
    $output = $event->getOutput()->__toString();
    $marker = sprintf('data-daedalus-ui-entity-wrapper="%s"', $wrapper_id);
    if (str_contains($output, $marker)) {
      return;
    }

    $this->logger->warning('The %template template did not deliver the entity UI attributes for %wrapper_id; a fallback wrapper div was added. Print {{ attributes }} on the template\'s outermost element.', [
      '%template' => (string) $event->getTemplateFile(),
      '%wrapper_id' => $wrapper_id,
    ]);

    // Is this the main entity for the page?
    $main_entity = '';
    $entity = $this->daedalusUi->deriveHostFromRoute()?->entity();
    if (
      !empty($entity) &&
      $entity_info['entity_type'] === $entity->getEntityTypeId() &&
      (
        $entity_info['entity_id'] === $entity->id() ||
        $entity_info['entity_id'] === $entity->uuid()
      )
    ) {
      $main_entity = sprintf(' data-main-entity="true" data-entity-uuid="%s"', $entity->uuid());
    }

    // Stamp the entity root's selection identity (selectable key) and the
    // metadata entities are loaded by. Block content is skipped: its
    // canonical identity is the Layout Builder placement
    // (block:{component_uuid}), never its block_content entity (mirroring
    // DaedalusUiHooks::entityView()).
    $selectable = '';
    if (!empty($entity_info['uuid']) && $entity_info['entity_type'] !== 'block_content') {
      $selectable = sprintf(' %s="%s" data-entity-type="%s" data-entity-id="%s"', SelectableKey::ATTRIBUTE, SelectableKey::entity($entity_info['uuid']), $entity_info['entity_type'], $entity_info['entity_id']);
    }

    $wrapped_output = sprintf('<div class="daedalus-ui-entity-wrapper" data-daedalus-ui-entity-wrapper="%s" data-daedalus-ui-view-mode="%s"%s%s>%s</div>', $wrapper_id, $entity_info['view_mode'], $main_entity, $selectable, $output);
    $event->setOutput(Markup::create($wrapped_output));
  }

  /**
   * Reads the entity info a regular entity or an inline block template carries.
   */
  private function getEntityInfo(array $variables) {
    if (!empty($variables['elements']['#daedalus_ui_entity'])) {
      // Regular entities.
      return $variables['elements']['#daedalus_ui_entity'];
    }
    elseif (!empty($variables['elements']['content']['#daedalus_ui_entity'])) {
      // Inline blocks.
      return $variables['elements']['content']['#daedalus_ui_entity'];
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      TwigRenderTemplateEvent::class => ['onTwigRenderTemplate'],
    ];
  }

}
