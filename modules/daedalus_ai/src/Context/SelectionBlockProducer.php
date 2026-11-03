<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Context;

use Drupal\daedalus_ai\ElementSelectionResolver;
use Drupal\daedalus_ai\RouteEntityResolver;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * States the selection block: the elements the user selected on the page.
 *
 * The resolved elements as a numbered list under the renderer's "ACTIVE
 * SELECTION" header. Each element names the styling subject the user is
 * looking at when it is a rule under the element (one sentence of
 * vocabulary: the rule and its style-map key) and the write address when
 * styling acts at a rule other than the subject's own (the rule, its
 * owner, the element or an ancestor, and its style-map key, so the AI
 * writes at the rule the user picked rather than on the element), what it
 * wears (its style stack's worn variants by label, at the subject when one
 * rides, each wearing with its origin: applied here with its spelling, via
 * a composition, or a fragment of one; labels only, the values are
 * explain_element_styles' answer) and the blueprint it was placed from (by
 * id and label) so imprecise references resolve against the materials and
 * "update the blueprint this came from" needs no print read. What an
 * element KIND is for is not repeated here: the cached design system brief
 * and the schema teach elements once, and a selection block that restated
 * it paid that teaching again per selected element per turn.
 *
 * A selection is the scope of its own turn only (the Selection Scope
 * protocol makes it law); the renderer's header says so and that a later
 * message's selection supersedes it, since the transcript keeps every
 * turn's block.
 */
final class SelectionBlockProducer implements EventSubscriberInterface {

  public function __construct(
    private readonly RouteEntityResolver $routeEntityResolver,
    private readonly ElementSelectionResolver $elementSelectionResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ContextBlocksEvent::EVENT_NAME => 'onContextBlocks',
    ];
  }

  /**
   * Resolves the selected elements and states them for the turn.
   */
  public function onContextBlocks(ContextBlocksEvent $event): void {
    $context = $event->context();
    if (empty($context['selected_elements']) || !is_array($context['selected_elements'])) {
      return;
    }

    $current_route = $context['current_route'] ?? NULL;
    $host = $current_route ? $this->routeEntityResolver->resolve($current_route) : NULL;
    $resolved = $this->elementSelectionResolver->resolve($context['selected_elements'], $host);
    if (empty($resolved)) {
      return;
    }

    $lines = [];
    foreach ($resolved as $number => $element) {
      $lines[] = '- Element ' . $number . ': ' . $element['description'];
      if (!empty($element['target'])) {
        $lines[] = '  Styling subject: ' . $element['target'] . '.';
      }
      if (!empty($element['address'])) {
        $lines[] = '  Styling address: ' . $element['address'] . '.';
      }
      if (!empty($element['materials'])) {
        $lines[] = '  Wears: ' . $element['materials'];
      }
      if (!empty($element['placed_from'])) {
        $lines[] = '  Placed from: ' . $element['placed_from'];
      }
    }

    $event->addBlock(new Block(BlockKind::Selection, implode("\n", $lines)));
  }

}
