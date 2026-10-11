<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Host;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Materials\BlueprintRequirements;
use Drupal\daedalus_blueprint\Materials\CanvasOffer;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintResult;

/**
 * The start: one spine verb for a page's first document.
 *
 * A page's first document has one source, picked once, when the page is
 * created: its bundle's template (the default when nothing is chosen), a
 * page blueprint, or nothing (blank). Every door that creates a page (the
 * chooser in front of an add form, page_create's `start`, a preview's dry
 * run) applies the choice here, to the IN-MEMORY entity before its first
 * save, so one save lands entity and document together and the seed has
 * no "choice pending" state to keep. An existing page, empty or not,
 * never takes a start (START_ON_EXISTING_ENTITY): a whole layout is
 * written onto a page only at its creation, and afterwards its document
 * changes through operations.
 *
 * The three starts, by what they leave on the unsaved host:
 * - template: nothing. The override field stays truly empty, and the page
 *   follows its template until the first read of its document seeds it
 *   (ContentHost::seed()), as every page did before starts existed.
 * - blank: core's own blank marker (one layout_builder_blank section,
 *   SectionListTrait::hasBlankSection(), written through
 *   removeAllSections(TRUE)). Core's contract for the marker is "started,
 *   and purposefully emptied", the persisted fact the seed reads as "not
 *   emptiness, leave it alone", so a blank page stays blank on a bundle
 *   whose template is not, with no new field.
 * - a page blueprint's id: the roster record's whole document (its tree
 *   and its page-level, node-less style entries) through the spine's one
 *   whole-document copy (DocumentInstantiator::instantiateDocument()),
 *   with fresh identity, NO provenance stamp (a started page is not a
 *   placement of the blueprint: "Update blueprint" never appears on it),
 *   absent inline-block fields sample-populated as a placement's are, as
 *   a system and admitted batch like the template seed (the seed is the
 *   "nothing was chosen" start, and a chosen start is held to no stricter
 *   rule than the default one; the gate is the entity's create access,
 *   which every creating door already asked). The seed is declined ahead
 *   of the copy, so the blueprint's document is the page's one source.
 *
 * The verb accepts `template` everywhere (it is the default, the start a
 * page gets when nothing is chosen), but a surface OFFERS it only when
 * the bundle's template holds a document to follow (templateOffered(),
 * the same section list the seed would instantiate): on a blank template
 * the template start and the blank start open the same empty page, and
 * a card promising "follows the template" with nothing to follow is a
 * promise with nothing behind it. Blank is the always-offered empty
 * start of a page that owns its whole document (blankOffered()).
 *
 * A page of a locked bundle (its display Layout Builder enabled, not
 * overridable: HostResolver::foreignOwner() names the template's host)
 * owns only the slots its template places, so its starts are fills: a
 * page blueprint whose every root is a slot section (`slot` on the
 * tree's top-level containers, <pb-slot name="id"> in the dialect) of a
 * slot the template declares (CanvasOffer::fillsSlots(), the offer's
 * one extra condition at page scope) lands those fills in the page's
 * own document, under the lock, exactly as an editor fills the slots on
 * the canvas; `template` and `blank` both mean "no slot filled" there
 * (a locked page's field is its fills or nothing, never core's marker:
 * ContentHost::seed()), so a chooser offers Template alone of the two.
 *
 * The spine knows a blueprint through its roster record alone (the
 * library imports the spine, never the other way), and what a bundle may
 * start as is the one "offered here" judgement asked for page scope
 * (CanvasOffer: page scope, a root, fits the host). The reserved words
 * `blank` and `template` are spelled here, and the library's reserved-id
 * rule points at them, so no blueprint can take a start's name.
 */
final class PageStart {

  /**
   * The start that leaves the page to its template, the default.
   */
  public const TEMPLATE = 'template';

  /**
   * The start that writes core's blank marker: started, and empty.
   */
  public const BLANK = 'blank';

  /**
   * The ids no blueprint can have: the two starts that are not blueprints.
   */
  public const RESERVED = [self::BLANK, self::TEMPLATE];

  /**
   * The parameter path every start refusal carries.
   */
  public const PATH = 'start';

  public function __construct(
    protected MaterialsRosterBuilder $rosterBuilder,
    protected CanvasOffer $offer,
    protected BlueprintRequirements $requirements,
    protected DocumentInstantiator $instantiator,
    protected HostResolver $hostResolver,
  ) {}

  /**
   * Whether the host's template holds a document to follow.
   *
   * The one judgement behind every surface that offers the template start
   * (the chooser's Template card, the bundle form's words): the host's
   * display, read as its own host (HostResolver::fromEntity()), owns
   * sections (DisplayHost::ownSections(), which reads core's blank marker
   * as none). That list is the seed's input (ContentHost::seed() into
   * DocumentInstantiator::seed()), so "offered" and "would seed" never
   * disagree: a template this answers FALSE for seeds nothing, and a page
   * started from it opens as the blank start's would. The display is the
   * read, never the host's own document: a read of a bundle's unsaved
   * host would seed it. A display nothing hosts (Layout Builder off) is
   * a template with no document, FALSE.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host a start would land on: a bundle's unsaved host, or a page's.
   *
   * @return bool
   *   TRUE when the template holds sections to follow.
   */
  public function templateOffered(HostInterface $host): bool {
    $display = $host->display();
    return $display !== NULL
      && $this->hostResolver->hosts($display)
      && $this->hostResolver->fromEntity($display)->ownSections() !== [];
  }

  /**
   * Whether a host's pages take a start at all: they own a first document.
   *
   * The one judgement behind every surface that asks whether a bundle has
   * starts (the chooser in front of an add form, the preview route, the
   * bundle's schema read, page_create's parameters) and the rule apply()
   * refuses with NO_LAYOUT: a start lands a page's first document, so it
   * needs a fieldable host carrying the override field (a document door).
   * What that document may hold is the host's shape: the whole page when
   * the host owns the whole document it renders, the fills of its
   * template's slots when it is rendered inside a foreign document (a
   * page of a locked bundle), which the offer (starts()) judges per
   * blueprint (CanvasOffer::fillsSlots()). A host with no document door
   * at all (a bundle Layout Builder renders without the field, a kernel
   * fixture without daedalus_layout) takes none.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host a start would land on: a bundle's unsaved host, or a page's.
   *
   * @return bool
   *   TRUE when a start other than template can land on the host.
   */
  public function takesStart(HostInterface $host): bool {
    return $host->fields() instanceof FieldableEntityInterface
      && $host->hasDocument();
  }

  /**
   * Whether the blank start is one to offer: an empty page of the host's own.
   *
   * The judgement behind the chooser's Blank card and the bundle form's
   * words: blank writes core's marker ("started, and purposefully
   * emptied") onto a page that owns its whole document, the one empty
   * start on an overridable bundle. A page of a locked bundle
   * (HostResolver::foreignOwner() names the template's host) has no empty
   * page of its own to open: its render is the template's whatever it
   * fills, so blank and template open the same page there and the
   * template card is the honest one; `blank` stays valid in the URL and
   * the start line either way, and apply() fills nothing for it.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host a start would land on: a bundle's unsaved host, or a page's.
   *
   * @return bool
   *   TRUE when blank opens a page the template does not.
   */
  public function blankOffered(HostInterface $host): bool {
    return $this->hostResolver->foreignOwner($host) === NULL;
  }

  /**
   * The page blueprints a host may start as: the offer, asked for page scope.
   *
   * The page-scope blueprint records with a root that fit the host, keyed
   * by blueprint id, in the one order every offering surface shows them:
   * the site's favorites first (MaterialRecord::$favorite, the face's own
   * default; an editor's marks never reach a start), then the rest, each
   * run by label, natural and case-insensitive, as the Add panel lists its
   * rows. The roster's own order (provider, then entity id) means nothing
   * to an editor, and the chooser's cards and the schema's `starts` both
   * take this answer as it is, so neither orders again. The askers with
   * no entity yet (the chooser, the preview, a bundle's schema read) ask
   * with the bundle's unsaved host (PageHosts::bundleHost()); the AI
   * opt-out (MaterialRecord::$offered) is not applied here, since the
   * chooser offers what the palette places, and the AI surfaces read it
   * off the records they print.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host a start would land on: a bundle's unsaved host, or a page's.
   *
   * @return array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord>
   *   The records, keyed by blueprint id, favorites first, then by label.
   */
  public function starts(HostInterface $host): array {
    $starts = [];
    foreach ($this->rosterBuilder->build()->blueprints() as $id => $record) {
      if ($this->offer->offered($record, $host, MaterialRecord::SCOPE_PAGE)) {
        $starts[$id] = $record;
      }
    }
    // PHP's sort is stable, so two blueprints with one label keep the
    // roster's order between them.
    uasort($starts, static fn (MaterialRecord $a, MaterialRecord $b): int => ($b->favorite <=> $a->favorite) ?: strnatcasecmp($a->label, $b->label));
    return $starts;
  }

  /**
   * Applies a start to a new entity's host, in memory.
   *
   * Nothing is persisted: the caller saves the entity once, and that save
   * lands entity and document together (the storage handler's commit, an
   * entity form's save). Refusals come back in the spine's error shape,
   * every one at the `start` path: START_ON_EXISTING_ENTITY for a host
   * whose entity is saved, NO_LAYOUT for a host with no document door
   * (blank and a blueprint need one; template leaves such a host as it
   * is, since its render is its template's either way), and for a
   * blueprint id the codes place_blueprint answers where they exist
   * (BLUEPRINT_NOT_FOUND, BLUEPRINT_EMPTY, BLUEPRINT_DOES_NOT_FIT, the
   * last also for a page blueprint that is not a set of fills of the
   * slots a locked bundle's template places) plus BLUEPRINT_WRONG_SCOPE
   * for a block blueprint, whose corrective names place_blueprint, the
   * door a block blueprint goes through. A copy that fails inside the
   * batch comes back with the instantiator's errors under start[i] and
   * styles, each naming the blueprint, and the batch's rollback leaves
   * the host as it was.
   *
   * On a host rendered inside a foreign document (a locked bundle's page)
   * blank writes nothing: the page's field is its fills or nothing, and
   * core's marker would read as a whole document started and emptied,
   * which such a page cannot be. A fitting blueprint's roots land as the
   * page's slot sections through the same whole-document copy, the slot
   * each root carries stamped at birth (add_section's `slot`).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host of the unsaved entity.
   * @param string $start
   *   The start: self::TEMPLATE, self::BLANK or a page blueprint's id.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintResult
   *   The result: success carrying the host, or the refusal.
   */
  public function apply(HostInterface $host, string $start): BlueprintResult {
    if (!$host->entity()->isNew()) {
      return BlueprintResult::semanticFailure([
        new BlueprintError(
          path: self::PATH,
          code: 'START_ON_EXISTING_ENTITY',
          message: sprintf('A start is applied only when the entity is created; %s %s already exists, and its document is changed through operations.', $host->entityTypeId(), $host->id()),
          context: ['entity_type' => $host->entityTypeId(), 'entity_id' => $host->id()],
          hint: 'Omit start on an existing page. Change its document through the ops road: place a block blueprint with place_blueprint (the Add panel), or add_section, add_element, move_element, remove_element and the style ops in one batch (eb_edit), or page_edit on a page-language site.',
        ),
      ]);
    }
    if ($start === self::TEMPLATE) {
      return BlueprintResult::success($host);
    }
    if (!$this->takesStart($host)) {
      return BlueprintResult::semanticFailure([
        new BlueprintError(
          path: self::PATH,
          code: 'NO_LAYOUT',
          message: sprintf('%s %s has no page document of its own to start: its render is its template\'s.', $host->entityTypeId(), $host->bundle()),
          context: ['entity_type' => $host->entityTypeId(), 'bundle' => $host->bundle()],
          hint: sprintf('Pass start: %s (or omit it) for a bundle whose pages share the template\'s layout; blank and a page blueprint need a bundle whose pages carry a document of their own.', self::TEMPLATE),
        ),
      ]);
    }
    $owner = $this->hostResolver->foreignOwner($host);
    if ($start === self::BLANK) {
      // A locked page's empty start is its template: nothing to write.
      if ($owner !== NULL) {
        return BlueprintResult::success($host);
      }
      $host->declineSeed();
      /** @var \Drupal\layout_builder\Field\LayoutSectionItemList $list */
      $list = $host->fields()->get(ContentHost::LAYOUT_FIELD);
      $list->removeAllSections(TRUE);
      return BlueprintResult::success($host);
    }

    $record = $this->rosterBuilder->build()->get(MaterialKind::Blueprint, $start);
    if ($record === NULL) {
      return BlueprintResult::structuralFailure([
        new BlueprintError(
          path: self::PATH,
          code: 'BLUEPRINT_NOT_FOUND',
          message: sprintf('"%s" is not a start: not %s, %s or a page blueprint offered for this %s.', $start, self::TEMPLATE, self::BLANK, $this->spellSubject($host)),
          context: ['available' => [...self::RESERVED, ...array_keys($this->starts($host))]],
          hint: sprintf('Pass %s (the bundle\'s template), %s (an empty page) or the id of a page blueprint offered for this bundle (its schema read lists them under starts).', self::TEMPLATE, self::BLANK),
        ),
      ]);
    }
    if ($record->blueprintScope !== MaterialRecord::SCOPE_PAGE) {
      return BlueprintResult::semanticFailure([
        new BlueprintError(
          path: self::PATH,
          code: 'BLUEPRINT_WRONG_SCOPE',
          message: sprintf('Blueprint "%s" is a block blueprint: one container placed into a page, never a page\'s start.', $start),
          context: ['blueprint' => $start, 'scope' => $record->blueprintScope],
          hint: 'Start the page from a page blueprint, blank or template, then place the block blueprint into it with place_blueprint (the page language\'s <pb-blueprint>, the Add panel).',
        ),
      ]);
    }
    if (($record->tree ?? []) === []) {
      return BlueprintResult::semanticFailure([
        new BlueprintError(
          path: self::PATH,
          code: 'BLUEPRINT_EMPTY',
          message: sprintf('Blueprint "%s" is empty: its document holds no root container, so there is nothing to start from.', $start),
          context: ['blueprint' => $start],
          hint: $record->entityId === NULL
            ? 'Start from a page blueprint that holds a root, or from blank or template.'
            : sprintf('Compose the blueprint first, on its own canvas or through the page tools with entity_type: blueprint and entity_id: %s, then start from it; or start from a page blueprint that holds a root, or from blank or template.', $record->entityId),
        ),
      ]);
    }
    $missing = $this->requirements->missing($record->requires, $host);
    if ($missing !== []) {
      $subject = $this->spellSubject($host);
      return BlueprintResult::semanticFailure([
        new BlueprintError(
          path: self::PATH,
          code: 'BLUEPRINT_DOES_NOT_FIT',
          message: sprintf('Blueprint "%s" does not fit this %s: it needs %s.', $start, $subject, BlueprintRequirements::describe($missing, $host)),
          context: ['blueprint' => $start, 'subject' => $subject, 'missing' => $missing],
          hint: isset($missing[BlueprintRequirements::BUNDLES])
            ? 'Start a page of a bundle its allow-list names, or widen the allow-list in the blueprint\'s details.'
            : 'Start from a page blueprint offered for this bundle (its schema read lists them under starts), or from blank or template.',
        ),
      ]);
    }
    if ($owner !== NULL && !$this->offer->fillsSlots($record, $host)) {
      $subject = $this->spellSubject($host);
      $slots = $this->offer->declaredSlots($host);
      return BlueprintResult::semanticFailure([
        new BlueprintError(
          path: self::PATH,
          code: 'BLUEPRINT_DOES_NOT_FIT',
          message: sprintf('Blueprint "%s" does not fit this %s: a page of this bundle owns only the slots its template places (%s), and the blueprint is not a set of fills of them.', $start, $subject, $slots === [] ? 'none' : implode(', ', $slots)),
          context: ['blueprint' => $start, 'subject' => $subject, 'slots' => $slots],
          hint: sprintf('Start from %s, or from a page blueprint whose every top-level section is a <pb-slot name="..."> filling one of those slots (its schema read lists them under starts); a whole page document starts a page of a bundle whose display allows each page its own layout.', self::TEMPLATE),
        ),
      ]);
    }

    // The blueprint's document is the page's one source: the seed is
    // declined before any read of the storage could run it. Fresh identity
    // (the default minting), no provenance stamp, absent fields filled.
    $host->declineSeed();
    $result = $this->instantiator->instantiateDocument($host, $record->tree, $record->styles, [
      'sample_fields' => TRUE,
      'path' => self::PATH,
      'indexed' => TRUE,
      'batch' => ['system' => TRUE, 'admitted' => TRUE],
    ]);
    if ($result->isSuccess()) {
      return $result;
    }
    // A copy's errors surface far from the blueprint that caused them, so
    // every one names it.
    $errors = array_map(
      static fn (BlueprintError $error): BlueprintError => new BlueprintError(
        $error->getPath(),
        $error->getCode(),
        $error->getMessage(),
        $error->getContext() + ['blueprint' => $start],
        $error->getHint(),
        $error->getOperationIndex(),
        $error->getElement(),
      ),
      $result->getErrors(),
    );
    return $result->getPhase() === 'semantic'
      ? BlueprintResult::semanticFailure($errors, $result->getWarnings())
      : BlueprintResult::structuralFailure($errors);
  }

  /**
   * A host's subject as the fit refusal spells it: entity_type:bundle.
   */
  protected function spellSubject(HostInterface $host): string {
    return BlueprintRequirements::spellSubject($host);
  }

}
