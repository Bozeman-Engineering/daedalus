<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\EventSubscriber;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_blueprint\Host\PageHosts;
use Drupal\daedalus_blueprint\Host\PageStart;
use Drupal\daedalus_layout\Form\BundleEditFormAlter;
use Drupal\daedalus_ui\Event\BundleRosterEvent;
use Drupal\daedalus_ui\Form\BundleEditFormAlter as DaedalusUiBundleEditFormAlter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Mirrors "Offer starting designs" on the bundle roster.
 *
 * The roster (daedalus_ui's Controller\BundleRoster, the bundle form's
 * read-only second door) asks every module that owns a control on the
 * Page builder tab for one column per setting; this is daedalus_layout's,
 * the chooser's one setting (Form\BundleEditFormAlter, the
 * `offer_starts` third-party setting). The cell states what the tab's box
 * would show, under the tab's own rules: no column at all on a block type
 * or a bundle config type the schema does not name (the tab has no
 * creation group there), "n/a" where the bundle's pages carry no layout
 * of their own (PageStart::takesStart() on the bundle's unsaved host, the
 * judgement that disables the box), otherwise the stored value as On or
 * Off. Nothing here writes: the tab stays the one write path.
 *
 * The answer depends on the bundle's field definitions (the Layout
 * Builder override field is what gives a page a layout of its own), so
 * the row carries core's `entity_field_info` tag beside the bundle's own.
 */
final class StartingDesignsColumn implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * The column id, the same on every row.
   */
  public const COLUMN = 'starting_designs';

  public function __construct(
    private readonly DaedalusUiBundleEditFormAlter $sectionOwner,
    private readonly PageHosts $pageHosts,
    private readonly PageStart $pageStart,
  ) {}

  /**
   * Adds the Starting designs column to a page bundle's row.
   */
  public function onBundleRoster(BundleRosterEvent $event): void {
    $bundle = $event->getBundle();
    $bundle_of = $event->getBundleOf();
    if ($bundle_of === 'block_content'
      || !$this->sectionOwner->hasSettingsSchema($bundle->getEntityType(), 'daedalus_layout')) {
      return;
    }
    $event->addCacheTags(['entity_field_info']);
    $host = $this->pageHosts->bundleHost($bundle_of, (string) $bundle->id());
    if ($host === NULL || !$this->pageStart->takesStart($host)) {
      $cell = $this->t('n/a');
    }
    else {
      $cell = $bundle->getThirdPartySetting('daedalus_layout', BundleEditFormAlter::SETTING, FALSE)
        ? $this->t('On')
        : $this->t('Off');
    }
    $event->setColumn(self::COLUMN, $this->t('Starting designs'), $cell);
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
