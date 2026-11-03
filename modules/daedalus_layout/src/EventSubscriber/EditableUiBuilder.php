<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_ui\Event\EditableUiBuilder as EditableUiBuilderEvent;
use Drupal\layout_builder\Entity\LayoutEntityDisplayInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Builds a host's canvas: the layout_builder_plus element over its storage.
 *
 * On an entity view build (the route entity's own render, which already
 * wears the main-entity wrapper) every field of the host is emptied and
 * the element takes their place, the entity view staying the canvas
 * root. On an empty build (a host with no entity view: a template
 * host, rendered for LoadEditablePage and the mutation re-render) the
 * element is the canvas root itself. A plain host (no document) renders
 * its display's document through the foreign owner's storage, read-only.
 * The site frame (a host with no display and no entity view) is the same
 * canvas-root case over its own frame storage: what FrameCanvas builds on
 * the frame's route, this builds for the mutation re-render.
 */
final class EditableUiBuilder implements EventSubscriberInterface {

  public function __construct(
    protected HostResolver $hostResolver,
  ) {}

  /**
   * Replaces the layout fields with the editor in edit mode.
   *
   * @param \Drupal\daedalus_ui\Event\EditableUiBuilder $event
   *   The editable UI build event.
   */
  public function onUiBuild(EditableUiBuilderEvent $event): void {
    if ($event->getMode() !== 'edit') {
      return;
    }
    $host = $event->getHost();
    $display = $host->display();
    if ($display !== NULL) {
      if (!$display instanceof LayoutEntityDisplayInterface || !$display->isLayoutBuilderEnabled()) {
        return;
      }
    }
    elseif (!$host instanceof FrameHost) {
      // A host with no display is the site frame (its document is its
      // own, no display renders it) or nothing this element edits.
      return;
    }
    // The canvas shows the document the host is rendered in: its own, or
    // the owner's when the host is rendered inside a foreign one (a page
    // of a locked bundle shows its template; what it owns inside it is
    // reached through the template's slots, not through its own storage).
    $section_storage = ($this->hostResolver->foreignOwner($host) ?? $host)->sectionStorage();
    if ($section_storage === NULL) {
      return;
    }

    $build = $event->getBuild();
    // The entity view supplied the canvas root (DaedalusUiHooks::entityView
    // stamps the route entity's build before the alter this event rides).
    $entity_view = !empty($build['#attributes']['data-main-entity']);
    $fields = $host->fields();
    if ($entity_view && $fields !== NULL && $display !== NULL) {
      // Override the entity build with the layout builder UI: each field
      // key present stays as an empty render array rather than leaving
      // the build, because core's preprocess hooks read base-field
      // elements by key (TaxonomyThemeHooks::preprocessTaxonomyTerm()
      // reads $variables['elements']['name'] unguarded,
      // TaxonomyThemeHooks.php:83, core issue 3015623) and an empty child
      // renders nothing.
      foreach (array_keys($fields->getFieldDefinitions()) as $field_name) {
        if (array_key_exists($field_name, $build)) {
          $build[$field_name] = [];
        }
      }
      unset($build['_layout_builder']);
    }
    $build['layout'] = [
      '#type' => 'layout_builder_plus',
      '#section_storage' => $section_storage,
      '#host' => $host,
      '#canvas_root' => !$entity_view,
    ];
    $event->setBuild($build);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      EditableUiBuilderEvent::class => ['onUiBuild'],
    ];
  }

}
