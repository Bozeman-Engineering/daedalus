<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\EventSubscriber;

use Drupal\daedalus_blueprint\Event\FieldAppliedEvent;
use Drupal\daedalus_edit\EntityIdentityMinting;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Mints identity on every field the blueprint spine applies (stage time).
 *
 * Blueprint mutations (AI tools, the endpoint verbs, the entity form's
 * commit) stage field values into tempstore without an entity save, so the
 * presave backstop never runs and staged content would render identity-less
 * until its first real save: no rows in the markup view, nothing for
 * Daedalus Stylish to target. This subscriber is the stage-time seam: it
 * rides the spine's FieldAppliedEvent, which fires after every successful
 * set in validateAndApplyFields() for both value kinds (a grammar value a
 * handler deserialized and an ExtractedValue a form submit handed over) and
 * before constraint validation, so the minted rewrite is validated and
 * staged exactly as the operation's own value is. Fields without a text
 * format are left alone by the walker; vocabulary tags the format allows
 * without class are skipped exactly as on the presave path (IdentityMinter's
 * rules) and the status report names them.
 */
class FieldAppliedMintIdentity implements EventSubscriberInterface {

  public function __construct(
    protected EntityIdentityMinting $entityIdentityMinting,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [FieldAppliedEvent::class => 'onFieldApplied'];
  }

  /**
   * Mints the applied field's items in place.
   *
   * @param \Drupal\daedalus_blueprint\Event\FieldAppliedEvent $event
   *   The field-applied event.
   */
  public function onFieldApplied(FieldAppliedEvent $event): void {
    $this->entityIdentityMinting->mintItems($event->getItems());
  }

}
