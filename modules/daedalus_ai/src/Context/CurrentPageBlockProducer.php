<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Context;

use Drupal\daedalus_ai\RouteEntityResolver;
use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * States the current page block: the host the conversation is on.
 *
 * The route host's facts (a page's entity, the template a display hosts,
 * or a saved blueprint on its own canvas) as one line the model reads
 * under the renderer's "Current page" header, followed by the host-level
 * refusals in advance (the root verdict RouteEntityResolver::describe()
 * carries, in the spine's own sentences: a page whose layout belongs to
 * its template says so and where the layout is edited, a frozen
 * translation and a required workspace likewise, so the model never
 * learns at a refusal what the read already knew) and the view mode.
 *
 * Access rides on RouteEntityResolver: a path the current user cannot view
 * resolves to NULL and only the view mode, which is not entity-bound, is
 * stated. With neither a host nor a view mode nothing is stated.
 */
final class CurrentPageBlockProducer implements EventSubscriberInterface {

  public function __construct(
    private readonly RouteEntityResolver $routeEntityResolver,
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
   * States the host line for the turn.
   */
  public function onContextBlocks(ContextBlocksEvent $event): void {
    $context = $event->context();
    $parts = [];

    if (!empty($context['current_route'])) {
      $host = $this->routeEntityResolver->resolve($context['current_route']);
      if ($host) {
        $facts = $this->routeEntityResolver->describe($host);
        $parts[] = self::identity($facts);
        array_push($parts, ...VerdictStamper::explain($facts['verdict'] ?? []));
      }
    }
    if (!empty($context['view_mode'])) {
      $parts[] = sprintf('View mode: "%s".', $context['view_mode']);
    }

    if ($parts === []) {
      return;
    }
    $event->addBlock(new Block(BlockKind::CurrentPage, implode(' ', $parts)));
  }

  /**
   * The host named by what it is: a template, a saved blueprint, or a page.
   *
   * @param array $facts
   *   What RouteEntityResolver::describe() answered.
   */
  private static function identity(array $facts): string {
    if ($facts['template'] !== NULL) {
      // A template: say what editing it reaches (every entity of the kind
      // without a layout of its own) and that its field blocks show sample
      // content, since the model otherwise reads the print as one page.
      return sprintf(
        '%s (a template, not a page: %s ID: %s, UUID: %s). Editing it changes every %s %s without a layout of its own; its field blocks show sample content, never a real entity\'s.',
        $facts['template'],
        $facts['entity_type'],
        $facts['entity_id'],
        $facts['uuid'],
        $facts['kind']['bundle'],
        $facts['kind']['entity_type'],
      );
    }
    if ($facts['blueprint'] !== NULL) {
      // A saved blueprint on its own canvas: its document is material for
      // hosts of its subject's kind, so say what editing it reaches (the
      // saved definition, hence every later placement; placements already
      // on pages are independent copies) and that its field blocks show
      // the subject's sample content.
      return sprintf(
        '%s (a saved blueprint, not a page: %s ID: %s, UUID: %s). Editing it changes the blueprint every later placement lands; placements already on pages are independent copies. Its field blocks are the %s %s\'s fields and show sample content, never a real entity\'s.',
        $facts['blueprint'],
        $facts['entity_type'],
        $facts['entity_id'],
        $facts['uuid'],
        $facts['kind']['bundle'],
        $facts['kind']['entity_type'],
      );
    }
    return sprintf(
      '%s (ID: %s, UUID: %s, type: %s, title: "%s").',
      $facts['entity_type'],
      $facts['entity_id'],
      $facts['uuid'],
      $facts['bundle'],
      $facts['label'],
    );
  }

}
