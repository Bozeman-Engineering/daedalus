<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\Form;

use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\daedalus_blueprint\Host\PageHosts;
use Drupal\daedalus_blueprint\Host\PageStart;
use Drupal\daedalus_ui\Form\BundleEditFormAlter as DaedalusUiBundleEditFormAlter;

/**
 * Adds "Offer starting designs" to a page bundle's Page builder section.
 *
 * The chooser's one setting: with it on, the bundle's add form opens on
 * the start chooser (StartChooser) whenever at least one page blueprint
 * is offered for the bundle. The box sits in the "When a page is created"
 * group of the shared section Daedalus UI owns
 * (BundleEditFormAlter::ensureGroup()), beside the initial mode, and its
 * help states the count of page blueprints that fit the bundle today,
 * with a link to the blueprint listing where the library provides one.
 * The help names the starts the chooser will offer: "a page blueprint,
 * the template, or blank" when the bundle's template holds a document to
 * follow, "a page blueprint or blank" when it does not
 * (PageStart::templateOffered(), the one judgement the chooser's Template
 * card follows, so the words and the cards never disagree), and "a page
 * blueprint of slot fills, or the template" on a locked bundle, whose
 * pages fill the template's slots and have no blank page of their own
 * (PageStart::blankOffered(), the Blank card's judgement). Where the
 * bundle's pages carry no document at all (Layout Builder off) a page
 * has nothing to start, so the box is disabled with the reason.
 *
 * Stored as `third_party_settings.daedalus_layout.offer_starts`, a boolean,
 * on the bundle config entity; the schema names the key per bundle config
 * type and ::hasSettingsSchema() is the probe, so the control never writes
 * a key with no schema. Value routing is FLAT (explicit #parents and an
 * own submit handler), never a third_party_settings #parents route
 * (copyFormValuesToEntity() would replace the entity's third-party
 * settings wholesale).
 */
final class BundleEditFormAlter {

  use StringTranslationTrait;

  /**
   * The flat #parents name of the control.
   */
  public const CONTROL = 'daedalus_layout_offer_starts';

  /**
   * The third-party setting the control writes.
   */
  public const SETTING = 'offer_starts';

  /**
   * The route of the blueprint listing the help links to, when it exists.
   */
  private const LISTING_ROUTE = 'entity.blueprint.collection';

  public function __construct(
    protected DaedalusUiBundleEditFormAlter $sectionOwner,
    protected PageHosts $pageHosts,
    protected PageStart $pageStart,
    protected RouteProviderInterface $routeProvider,
  ) {}

  /**
   * Implements hook_form_alter() for bundle entity edit forms.
   */
  public function formAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof BundleEntityFormBase) {
      return;
    }
    /** @var \Drupal\Core\Config\Entity\ConfigEntityBundleBase $bundle_entity */
    $bundle_entity = $form_object->getEntity();
    $bundle_of = $bundle_entity->getEntityType()->getBundleOf();
    // A block type's section has no creation group (the section owner's
    // rule: its groups are the Add panel, the page and styling), and a
    // bundle config type the schema does not name gets no key written.
    if ($bundle_of === 'block_content'
      || !$this->sectionOwner->hasSettingsSchema($bundle_entity->getEntityType(), 'daedalus_layout')
      || !$this->sectionOwner->ensureGroup($form, $form_state, DaedalusUiBundleEditFormAlter::GROUP_CREATION)) {
      return;
    }
    $host = $this->pageHosts->bundleHost($bundle_of, (string) $bundle_entity->id());
    $own_layout = $host !== NULL && $this->pageStart->takesStart($host);
    $control = [
      '#type' => 'checkbox',
      '#parents' => [self::CONTROL],
      '#title' => $this->t('Offer starting designs'),
      '#attributes' => [DaedalusUiBundleEditFormAlter::SUMMARY_ATTRIBUTE => $this->t('Starting designs offered')],
    ];
    if ($own_layout) {
      $count = count($this->pageStart->starts($host));
      $fit = $count === 0
        ? $this->t('No page blueprint fits @bundle content yet.', ['@bundle' => $bundle_entity->label()])
        : $this->formatPlural($count, '1 page blueprint fits @bundle content.', '@count page blueprints fit @bundle content.', ['@bundle' => $bundle_entity->label()]);
      $listing = $this->routeProvider->getRoutesByNames([self::LISTING_ROUTE]) !== []
        ? Link::fromTextAndUrl($this->t('Manage blueprints'), Url::fromRoute(self::LISTING_ROUTE))->toString()
        : '';
      $template = $this->pageStart->templateOffered($host);
      $picks = match (TRUE) {
        !$this->pageStart->blankOffered($host) => $template
          ? $this->t('a page blueprint of slot fills, or the template')
          : $this->t('a page blueprint of slot fills'),
        $template => $this->t('a page blueprint, the template, or blank'),
        default => $this->t('a page blueprint or blank'),
      };
      $control += [
        '#description' => $this->t('Before the form, the author picks how the page starts: @picks. @fit @listing', [
          '@picks' => $picks,
          '@fit' => $fit,
          '@listing' => $listing,
        ]),
        '#default_value' => (bool) $bundle_entity->getThirdPartySetting('daedalus_layout', self::SETTING, FALSE),
      ];
    }
    else {
      $control += [
        '#description' => $this->t('Needs pages that carry a layout of their own.'),
        '#default_value' => FALSE,
        '#disabled' => TRUE,
      ];
    }
    $form['page_builder'][DaedalusUiBundleEditFormAlter::GROUP_CREATION][self::SETTING] = $control;
    array_unshift($form['actions']['submit']['#submit'], [$this, 'submit']);
  }

  /**
   * Submit handler.
   */
  public function submit(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\Core\Config\Entity\ConfigEntityBundleBase $bundle_entity */
    $bundle_entity = $form_state->getFormObject()->getEntity();
    $bundle_entity->setThirdPartySetting('daedalus_layout', self::SETTING, (bool) $form_state->getValue(self::CONTROL));
  }

}
