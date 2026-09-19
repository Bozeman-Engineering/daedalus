<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Core\Render\Markup;
use Drupal\Core\Template\Attribute;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_ui\Render\LiveAttributionDemand;
use Drupal\Core\Entity\EntityInterface;
use Drupal\twig_events\Event\TwigRenderTemplateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Rewrites inline-block field selectable keys to component-uuid form.
 *
 * Field-level attribution happens while the field renders, where only the
 * host entity is visible — so an inline block's fields are initially stamped
 * `field:{block_content_uuid}:{name}`. But the canonical identity for
 * inline-block content is the Layout Builder component:
 * `block:{component_uuid}:{name}`, because that is how both the server and
 * the blueprint address it (see SelectableKey). The component UUID only becomes
 * visible at the block template boundary, so this subscriber rewrites the
 * keys there.
 *
 * The rewrite is exact-string on markup we stamped ourselves, scoped to this
 * block's output and qualified by this block's content UUID — nested inline
 * blocks are safe because their keys were already rewritten when their own
 * (inner, earlier) block template rendered.
 */
final class InlineBlockFieldKeys implements EventSubscriberInterface {

  public function __construct(
    protected DaedalusUi $daedalusUi,
    protected LiveAttributionDemand $liveAttribution,
  ) {}

  /**
   * Rewrites an inline block template's field keys to the block's own.
   */
  public function onTwigRenderTemplate(TwigRenderTemplateEvent $event): void {
    // Block templates only: block.html.twig and block--* suggestions.
    $template = basename((string) $event->getTemplateFile());
    if (!preg_match('/^block(--|\.)/', $template)) {
      return;
    }

    $variables = $event->getVariables();

    // The component UUID stamped by LayoutBuilderBlockAttributes: the
    // editing attribute in edit mode, parsed from the selection identity on
    // a live render (where only the selectable key is stamped).
    $attributes = $variables['attributes'] ?? [];
    if ($attributes instanceof Attribute) {
      $attributes = $attributes->toArray();
    }
    $component_uuid = $attributes['data-layout-builder-block-uuid'] ?? NULL;
    if (empty($component_uuid)) {
      $selectable = $attributes[SelectableKey::ATTRIBUTE] ?? NULL;
      if (is_string($selectable) && preg_match('/^block:([^:]+)$/', $selectable, $matches)) {
        $component_uuid = $matches[1];
      }
    }
    $component_uuid = $component_uuid === NULL ? NULL : (string) $component_uuid;

    // The inline block's content entity.
    $entity = $variables['elements']['content']['#block_content'] ?? NULL;
    if (empty($component_uuid) || !$entity instanceof EntityInterface) {
      return;
    }

    // In edit mode every field key is rewritten; on a live render only
    // demanded ones exist (a styled inline-block field's CSS scopes to the
    // component-uuid form, so its stamped key must be rewritten to match).
    if ($this->daedalusUi->getMode() !== 'edit'
      && !$this->liveAttribution->demandsKeyPrefix(SelectableKey::blockField($component_uuid, ''), $entity)) {
      return;
    }

    $output = (string) $event->getOutput();
    $search = sprintf('%s="%s', SelectableKey::ATTRIBUTE, SelectableKey::field($entity->uuid(), ''));
    if (!str_contains($output, $search)) {
      return;
    }
    $replace = sprintf('%s="%s', SelectableKey::ATTRIBUTE, SelectableKey::blockField((string) $component_uuid, ''));
    $event->setOutput(Markup::create(str_replace($search, $replace, $output)));
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
