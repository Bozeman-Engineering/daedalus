<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\daedalus_ui\Event\BundleRosterEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Mirrors "Let editors style elements" on the bundle roster.
 *
 * The roster (daedalus_ui's Controller\BundleRoster, the bundle form's
 * read-only second door) asks every module that owns a control on the
 * Page builder tab for one column per setting; this is Daedalus Stylish's,
 * the per-bundle box in the tab's Styling group (Form\BundleEditFormAlter).
 * The cell states what the box reflects on an existing bundle: whether the
 * style map field exists for it (DaedalusStylishFieldManager::isEnabled()),
 * as On or Off, on every row the roster lists (the box sits on every
 * bundle the tab serves, block types included). Nothing here writes: the
 * tab stays the one write path, and the opt-out record it keeps is the
 * tab's own business.
 *
 * The answer is a field config's existence, so the row carries core's
 * `entity_field_info` tag beside the bundle's own.
 */
final class StylingColumn implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The column id, the same on every row.
   */
  public const COLUMN = 'styling';

  public function __construct(
    private readonly DaedalusStylishFieldManager $fieldManager,
  ) {}

  /**
   * Adds the Styling column to the row.
   */
  public function onBundleRoster(BundleRosterEvent $event): void {
    $event->addCacheTags(['entity_field_info']);
    $enabled = $this->fieldManager->isEnabled($event->getBundleOf(), (string) $event->getBundle()->id());
    $event->setColumn(self::COLUMN, $this->t('Styling'), $enabled ? $this->t('On') : $this->t('Off'));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      BundleRosterEvent::class => ['onBundleRoster'],
    ];
  }

}
