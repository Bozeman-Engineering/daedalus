<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Core\Render\Markup;
use Drupal\Core\Template\Attribute;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\daedalus_ui\Render\LiveAttributionDemand;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\twig_events\Event\TwigRenderTemplateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Guarantees field attributes reach the page in edit mode.
 *
 * Everything the editing UI knows about a rendered field rides the field
 * template's attributes: the selectable key (data-selectable), the entity
 * metadata, and the attributes editing tools stamp via the
 * EditableFieldAttributes event (e.g. daedalus_edit's
 * data-daedalus-edit-field-value-wrapper). All of them are delivered through {{
 * attributes }} in the theme's field template — and a theme that overrides
 * field.html.twig without printing {{ attributes }} silently severs every field
 * on the page from the editing UI. That silent failure is what killed core's
 * Quickedit.
 *
 * This subscriber closes that hole. After a field template renders, it checks
 * whether the selectable key made it into the output. When it did (the normal
 * case), the markup is untouched and theme fidelity is perfect. When it did
 * not, the rendered output is wrapped in a container we control, carrying the
 * full attribute bag the template failed to print.
 *
 * The same hole exists on live renders for fields with live-attribution
 * demand (a styled field's CSS scopes to its selectable key), so the
 * guarantee extends there — gated to demanded fields only, a live page in a
 * broken theme grows wrappers around exactly the styled fields and nothing
 * else.
 */
final class SelectableFieldDelivery implements EventSubscriberInterface {

  public function __construct(
    protected DaedalusUi $daedalusUi,
    protected LiveAttributionDemand $liveAttribution,
  ) {}

  /**
   * Delivers the selectable attributes on a rendered field template.
   */
  public function onTwigRenderTemplate(TwigRenderTemplateEvent $event): void {
    // Field templates only: field.html.twig and field--* suggestions.
    $template = basename((string) $event->getTemplateFile());
    if (!preg_match('/^field(--|\.)/', $template)) {
      return;
    }

    $variables = $event->getVariables();
    $entity = $variables['element']['#object'] ?? NULL;
    $field_name = $variables['element']['#field_name'] ?? NULL;
    if (!$entity instanceof FieldableEntityInterface || empty($field_name)) {
      return;
    }

    // Mirror the gates wrapAndAttributeFieldsForEditing() stamps under: only
    // wrap when the selectable key should have been present (in edit mode a
    // field of an entity the route host's canvas edits, ownsFields(); on a
    // live render a demanded key).
    $key = SelectableKey::field($entity->uuid(), $field_name);
    $stamped = $this->daedalusUi->getMode() === 'edit'
      ? $this->daedalusUi->ownsFields($entity)
      : $this->liveAttribution->demandsKey($key, $entity);
    if (!$stamped) {
      return;
    }

    $marker = sprintf('%s="%s"', SelectableKey::ATTRIBUTE, $key);
    $output = (string) $event->getOutput();
    if (str_contains($output, $marker)) {
      return;
    }

    $attributes = $variables['attributes'] ?? [];
    if (!$attributes instanceof Attribute) {
      $attributes = new Attribute($attributes);
    }
    $event->setOutput(Markup::create('<div' . $attributes . '>' . $output . '</div>'));
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
