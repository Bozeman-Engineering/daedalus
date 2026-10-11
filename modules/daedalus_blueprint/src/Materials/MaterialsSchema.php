<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_blueprint\Dialect\DialectPrinter;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Renders the roster as the blueprint schema's `materials` fragment.
 *
 * The AI's one discovery lane for every material kind, projected from the
 * roster (never walked from the entities): the named values (tokens by
 * scale, swatches with their labels and descriptions), the saved variants
 * with their descriptions and where they apply, the blueprints printed in
 * full as page-language fragments (sample content and worn looks, the
 * definition as a document without ids) with the looks each one owns,
 * and the animations with their keyframe steps, under one guidance
 * paragraph that teaches how each kind is read and referenced. The
 * fragment keeps the FULL text (descriptions unclipped); the cached
 * prompt's design system brief (DialectCatalogPrompt) is the twin that
 * clips every description to its first sentence and projects the same
 * roster in the active system's own structure.
 *
 * A blueprint prints where the catalog is in hand: the printer spells a
 * worn variant by its record (an axis attribute or a class) and a block
 * by its declared contract, both catalog facts, so the schema builds the
 * catalog over the same roster it iterates (the catalog builder memoizes
 * on roster identity). A blueprint's owned looks (the composition
 * variants only its placements may wear) list under the blueprint, never
 * under the element or the flat variants map: they address the
 * blueprint's children by position, and the guidance says so once. A
 * saved blueprint is a page of its own, so its entry carries the
 * entity_id the page tools address it by; a shipped one carries none.
 *
 * Grows-by-data per kind: a kind the site has none of is absent, not an
 * empty map under a reserved key, and the guidance says so once ("a kind
 * absent here has no materials on this site"), which keeps a writer from
 * inventing machine names as well as an empty roster would. Blueprint
 * vocabulary (the blueprints group, the fragment and looks sentences, the
 * placement spelling) rides only while the site has blueprints, and the
 * animation vocabulary only while it has animations.
 *
 * The fragment iterates the roster's offered view (MaterialsRoster::
 * offered()): a material its author opted out of the AI has no entry and
 * no description here, as on the brief. The blueprint prints run over
 * the full catalog, since a print spells what the definition actually
 * wears (legality, never advertisement): a blueprint wearing an unoffered
 * look still prints it, and the look simply lists nowhere.
 *
 * What the model is shown follows scope and bundle. The blueprints group
 * is BLOCK scope (what places into a page, the Add panel's question) and,
 * when the schema is read for a bundle, only what the bundle's host is
 * offered (CanvasOffer: a root, fits the host); a read with no bundle
 * host (a hand-built schema, a type nothing hosts) keeps block scope
 * unfiltered by fit. Page blueprints are never in it: a page blueprint
 * is a START, what a page of the bundle begins as, and starts() projects
 * the records the spine's PageStart::starts() answers as the schema's own
 * `starts` key (label, description, the saved one's entity_id; no
 * fragment, since a whole page's print is heavy and a saved blueprint is
 * readable with page_read), where MaterialsSchemaHooks lands it on a page
 * bundle's schema.
 *
 * A blueprint's entry prints its whole body, so the fragment never lists
 * every offered blueprint: a site designed for a hundred would pay a
 * hundred prints per read. The `blueprints` group holds the SITE
 * FAVORITES (MaterialRecord::$favorite, the default the Add panel lists
 * under Favorites for everyone; an editor's own marks never reach a
 * surface cached for every user) and the `groups` group is the site's
 * group vocabulary (DialectCatalog::groups(), declaration order) with
 * how many offered block blueprints each holds, plus the reserved
 * DialectCatalog::UNGROUPED bucket for the offered ones in no declared
 * group: the model's index. One group is read in full on demand: narrow()
 * replaces the blueprints group with that group's entries AFTER the
 * cached schema is read (the schema cache key never changes), which is
 * what eb_read_schema's `group` parameter does.
 */
class MaterialsSchema {

  /**
   * The applies sentence a root-only variant carries on its entry.
   */
  public const APPLIES_ROOT_ONLY = 'top-level sections only, never nested inside another section';

  /**
   * The placement value a band blueprint carries on its entry.
   *
   * A blueprint whose root wears a root-only variant (MaterialsRoster::
   * isBand()) places at the document's top level only; every other
   * blueprint carries no placement key and places anywhere the grammar
   * allows.
   */
  public const PLACEMENT_TOP_LEVEL = 'top_level';

  /**
   * The one-line rule beside a blueprint's owned looks.
   *
   * An owned look is a composition variant: it addresses the blueprint's
   * children by position, so a placement whose shape drifts from the
   * fragment wears it differently, exactly as a person editing a
   * placement in the UI would find.
   */
  public const LOOKS_BY_POSITION = 'An owned look addresses the blueprint\'s children by position: keep the shape where you keep the look.';

  /**
   * The fragment's keys, in the order they ride.
   *
   * The one statement of the shape: fragment() assembles its groups by
   * it and narrow() keeps a replaced group in its place.
   */
  public const KEYS = ['guidance', 'tokens', 'swatches', 'variants', 'groups', 'blueprints', 'animations', 'fonts'];

  /**
   * The key of a group entry's count: the offered block blueprints it holds.
   */
  public const GROUP_COUNT = 'blueprints';

  public function __construct(
    protected MaterialsRosterBuilder $rosterBuilder,
    protected DialectCatalogBuilder $catalogBuilder,
    protected DialectPrinter $printer,
    protected CanvasOffer $offer,
  ) {}

  /**
   * The fragment: guidance, then one group per kind the site has.
   *
   * The blueprints group holds the site favorites in full; the groups
   * group is the index of every offered block blueprint (narrow() lists
   * one group in full after the cache).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The host of the bundle the schema is read for, whose offer narrows
   *   the blueprints to the block blueprints it is offered; NULL for a
   *   read with no bundle host, which keeps block scope unfiltered by
   *   fit.
   */
  public function fragment(?HostInterface $host = NULL): array {
    $roster = $this->rosterBuilder->build()->offered();
    $records = $this->blueprintRecords($roster, $host);
    $groups = $this->groups($records);
    $favorites = array_filter($records, static fn (MaterialRecord $record): bool => $record->favorite);
    return $this->assemble([
      'guidance' => $this->guidance($roster, $records, $groups),
      'tokens' => $this->tokens($roster),
      'swatches' => $this->swatches($roster),
      'variants' => $this->variants($roster),
      'groups' => $groups,
      'blueprints' => $this->blueprints($roster, $favorites),
      'animations' => $this->animations($roster),
      'fonts' => $this->fonts($roster),
    ]);
  }

  /**
   * A fragment with one group's blueprints in place of the site favorites.
   *
   * Applied AFTER the cached schema is read, never inside the cache: the
   * group's records are re-read from the roster (memoized per request)
   * and printed in full where the favorites sat, every other group of
   * the fragment (the guidance included) carried through as cached. An
   * empty group leaves the blueprints group absent, as an empty kind is.
   *
   * @param array $fragment
   *   The fragment as fragment() built it (the schema's `materials`).
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The bundle's host the schema was read for (fragment()'s), so the
   *   listing is the same offer the index counted.
   * @param string $group
   *   A declared group id, or DialectCatalog::UNGROUPED for the offered
   *   block blueprints in no declared group.
   *
   * @return array
   *   The fragment, its blueprints group replaced.
   *
   * @throws \InvalidArgumentException
   *   When the group is neither declared nor the reserved bucket; a
   *   surface answers the model a corrective from groupIds() before
   *   asking.
   */
  public function narrow(array $fragment, ?HostInterface $host, string $group): array {
    if (!in_array($group, $this->groupIds(), TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown group "%s"; one of %s.', $group, implode(', ', $this->groupIds())));
    }
    $roster = $this->rosterBuilder->build()->offered();
    $records = array_filter(
      $this->blueprintRecords($roster, $host),
      fn (MaterialRecord $record): bool => $group === DialectCatalog::UNGROUPED
        ? $this->declaredGroups($record) === []
        : in_array($group, $this->declaredGroups($record), TRUE),
    );
    $fragment['blueprints'] = $this->blueprints($roster, $records);
    return $this->assemble($fragment);
  }

  /**
   * The group ids a narrowing may name: the declared ones, then ungrouped.
   *
   * @return string[]
   *   The ids, declaration order, DialectCatalog::UNGROUPED last.
   */
  public function groupIds(): array {
    return [...array_keys($this->catalogBuilder->build()->groups()), DialectCatalog::UNGROUPED];
  }

  /**
   * The fragment's groups in their one order, the empty ones left out.
   *
   * @param array $parts
   *   Group key => its content; '' or [] for a group with nothing.
   */
  protected function assemble(array $parts): array {
    $fragment = [];
    foreach (self::KEYS as $key) {
      if (isset($parts[$key]) && $parts[$key] !== [] && $parts[$key] !== '') {
        $fragment[$key] = $parts[$key];
      }
    }
    return $fragment;
  }

  /**
   * The group index: the vocabulary with counts, and the ungrouped bucket.
   *
   * Every declared group rides with its label, its description (when
   * written) and how many of the listed records it holds, a group holding
   * none included (the vocabulary is also the ids a blueprint's groups
   * take); DialectCatalog::UNGROUPED rides last, with its count alone,
   * only while a listed record is in no declared group. A record's
   * undeclared ids (a shipped blueprint from a package whose groups this
   * site lacks) count nowhere: a surface ignores them, so the record is
   * ungrouped here. [] when the site declares no groups and nothing is
   * listed.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $records
   *   The blueprint records the fragment indexes (blueprintRecords()).
   *
   * @return array<string, array{label?: string, description?: string, blueprints: int}>
   *   The entries, keyed by group id.
   */
  protected function groups(array $records): array {
    $groups = [];
    foreach ($this->catalogBuilder->build()->groups() as $id => $group) {
      $entry = ['label' => $group['label']];
      if ($group['description'] !== '') {
        $entry['description'] = $group['description'];
      }
      $entry[self::GROUP_COUNT] = 0;
      $groups[$id] = $entry;
    }
    $ungrouped = 0;
    foreach ($records as $record) {
      $declared = $this->declaredGroups($record);
      if ($declared === []) {
        $ungrouped++;
      }
      foreach ($declared as $id) {
        $groups[$id][self::GROUP_COUNT]++;
      }
    }
    if ($ungrouped > 0) {
      $groups[DialectCatalog::UNGROUPED] = [self::GROUP_COUNT => $ungrouped];
    }
    return $groups;
  }

  /**
   * The declared group ids a record carries, in the record's order.
   *
   * @return string[]
   *   The ids the catalog declares among the record's groups.
   */
  protected function declaredGroups(MaterialRecord $record): array {
    return array_values(array_intersect($record->groups, array_keys($this->catalogBuilder->build()->groups())));
  }

  /**
   * What a cached schema carrying the fragment or the starts depends on.
   *
   * @return string[]
   *   The roster's cache tags, and the offer's (the site lists a fit
   *   reads) since both projections judge a blueprint against a host.
   */
  public function cacheTags(): array {
    return array_values(array_unique([
      ...$this->rosterBuilder->build()->getCacheableMetadata()->getCacheTags(),
      ...$this->offer->cacheMetadata()->getCacheTags(),
    ]));
  }

  /**
   * A bundle's starts: the page blueprints its schema read offers.
   *
   * The records PageStart::starts() answers for the bundle's host (page
   * scope, a root, fits), narrowed to the AI's view (MaterialRecord::
   * $offered, which the verb leaves to the surface since the chooser
   * offers what the palette places), keyed by id in the verb's own order
   * (the site's favorites first, then the rest, each run by label), the
   * one order the chooser's cards show too, so this projection never
   * orders again. Each entry is the blueprint's face and address: label,
   * description and,
   * for a saved blueprint, the entity_id page_read takes; no fragment,
   * since a whole page's print is heavy and the saved one is readable on
   * demand. The reserved starts (blank, template) are not records and
   * ride page_create's own description, not this map.
   *
   * @param array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord> $starts
   *   The records PageStart::starts() answered for the host.
   *
   * @return array<string, array{label: string, description: string, entity_id?: string}>
   *   The entries, keyed by blueprint id.
   */
  public function starts(array $starts): array {
    $entries = [];
    foreach ($starts as $record) {
      if (!$record->offered) {
        continue;
      }
      $entry = [
        'label' => $record->label,
        'description' => $record->description,
      ];
      if ($record->entityId !== NULL) {
        $entry['entity_id'] = $record->entityId;
      }
      $entries[$record->id] = $entry;
    }
    return $entries;
  }

  /**
   * The guidance paragraph: roster-reading facts only.
   *
   * Facts about reading the roster live here (schema = facts); the
   * preference order and the mint gate ride the style guide's own guidance
   * and the design system brief (MaterialsProse, the one source), and the
   * membership sentence is the brief's. Door-neutral: it names the page
   * language's spellings and no JSON op.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialsRoster $roster
   *   The roster's offered view.
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $blueprints
   *   The blueprint records the fragment indexes (blueprintRecords()):
   *   the blueprint vocabulary rides only while there are any.
   * @param array $groups
   *   The group index (groups()): its vocabulary rides only while it
   *   holds an entry.
   */
  protected function guidance(MaterialsRoster $roster, array $blueprints, array $groups): string {
    $has_blueprints = $blueprints !== [];
    $sentences = [
      'The site\'s materials; a request like "keep to the materials" or "ignore the materials" means this set, and a kind absent here has no materials on this site. Whose members they are (the active design system\'s plus the global ones) is the design system brief\'s sentence.',
      '"tokens" are the design tokens the active skin mints, grouped by scale, and "swatches" the published palette swatches with their labels; both are named values, keyed by the CSS custom property a style value references as var(--name) (e.g. var(--space-4)), each entry carrying its "value" and, when its author wrote one, a "description" (why it exists and when to reach for it). A value is a plain string or a condition-keyed map of VALUES for that ONE name ("default" always applies; a key like "dark" is the condition under which the SAME name resolves to that value, never part of its name: var(--color-bg-dark) names nothing). Colors fit color-valued properties and stops inside gradients; gradients fit image-valued properties such as background-image, alone or as a comma-separated layer.',
      '"variants" are the saved reusable looks, keyed by machine name; "spelling" is how the page language wears one (class="name", or an axis attribute on the element\'s tag such as width="full"), an entry without an axis is on the built-in "style" axis, and an "applies" line narrows where a variant may be worn.',
    ];
    if ($has_blueprints) {
      $has_bands = array_filter($blueprints, $roster->isBand(...)) !== [];
      $sentences[] = '"blueprints" are saved compositions (one root container each, with its children), placed as <pb-blueprint name="id"/> elements in a page-language fragment; each entry\'s "fragment" is the blueprint printed in the page language, its sample content and worn looks included, and its "looks" are the looks the blueprint owns, wearable only inside its placements.'
        . ' ' . self::LOOKS_BY_POSITION
        . ($has_bands ? ' A blueprint with "placement": "' . self::PLACEMENT_TOP_LEVEL . '" is a page band (its root wears a top-level-only variant): place it at the document\'s top level, never inside another container.' : '')
        . ' A blueprint with an "entity_id" is saved on this site and is a page of its own: page_read (entity_type blueprint, that entity_id) prints its body with ids and page_edit edits it, changing what every later placement lands; one without is shipped by a module and read-only.'
        . ' When the user asks to save a composition as a blueprint: build the subtree with ordinary ops, then save_material (kind blueprint) its root element in the same page_edit batch.';
    }
    if ($groups !== []) {
      $sentences[] = '"groups" is the site\'s group vocabulary (the ids a blueprint\'s groups take) with how many offered blueprints each holds, "' . DialectCatalog::UNGROUPED . '" the ones in no group; "blueprints" holds the site favorites in full, or one group\'s when the read named a group: eb_read_schema with group (an id, or ' . DialectCatalog::UNGROUPED . ') lists that group here instead.';
    }
    if ($roster->animations() !== []) {
      $sentences[] = '"animations" are the published keyframes animations, keyed by machine name; "spelling" is the ident an animation-name value (or the name in an animation shorthand) references, never a var(); "steps" are the keyframes as stored, offset => declarations.';
    }
    if ($roster->fonts() !== []) {
      $sentences[] = '"fonts" are the published self-hosted font families, keyed by machine name; "spelling" is the quoted family a font-family value (or the font shorthand) references, never a var(); "faces" are the files the family ships, one per weight and style.';
    }
    return implode(' ', $sentences);
  }

  /**
   * The tokens, grouped by scale, keyed by custom property: value, description.
   *
   * The value as stored (a string or a condition-keyed map); the
   * description (why the token exists and when to reach for it, the
   * skin author's reasoning) rides only when the record carries one, the
   * omit-when-nothing rule the swatches follow. The whole text is the
   * schema's to carry: the brief prints the first sentence alone.
   */
  protected function tokens(MaterialsRoster $roster): array {
    $groups = [];
    foreach ($roster->tokens() as $record) {
      $entry = ['value' => $record->value];
      if ($record->description !== '') {
        $entry['description'] = $record->description;
      }
      $groups[(string) $record->group][self::customProperty($record)] = $entry;
    }
    return $groups;
  }

  /**
   * The swatches, keyed by custom property: label, kind, value, description.
   *
   * The description (when to reach for the swatch, in the system's
   * voice) rides only when the record carries one, the omit-when-nothing
   * rule the variants follow.
   */
  protected function swatches(MaterialsRoster $roster): array {
    $swatches = [];
    foreach ($roster->swatches() as $record) {
      $entry = [
        'label' => $record->label,
        'kind' => $record->group,
        'value' => $record->value,
      ];
      if ($record->description !== '') {
        $entry['description'] = $record->description;
      }
      $swatches[self::customProperty($record)] = $entry;
    }
    return $swatches;
  }

  /**
   * The unowned variants, keyed by machine name, applicability serialized on.
   *
   * Per entry: label, the page-language spelling when the variant has one,
   * scope, axis only when not the built-in style axis, an applies line
   * when the variant is root-only, and description when it carries one.
   * Keys with nothing to say are omitted rather than emitted empty. A
   * look a blueprint owns is absent here: it lists under its blueprint.
   */
  protected function variants(MaterialsRoster $roster): array {
    $variants = [];
    foreach ($roster->variants() as $record) {
      if ($record->ownedBy !== NULL) {
        continue;
      }
      $entry = ['label' => $record->label];
      $spelling = $this->spellingProse($record);
      if ($spelling !== '') {
        $entry['spelling'] = $spelling;
      }
      $entry['scope'] = $record->scope?->__toString();
      if ($record->group !== NULL && $record->group !== 'style') {
        $entry['axis'] = $record->group;
      }
      if ($record->rootOnly()) {
        $entry['applies'] = self::APPLIES_ROOT_ONLY;
      }
      if ($record->description !== '') {
        $entry['description'] = $record->description;
      }
      $variants[$record->id] = $entry;
    }
    return $variants;
  }

  /**
   * The blueprint records the fragment lists: block scope, the host's offer.
   *
   * Block scope always (a page blueprint is a start, never a placement,
   * and lists under the schema's `starts`); with a host, only the records
   * the host is offered (CanvasOffer: a root, fits), the same judgement
   * the Add panel's payload applies on a canvas, so the model and the
   * editor are offered the same blueprints for the same bundle. Without
   * a host nothing judges the fit.
   *
   * @return array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord>
   *   The records, keyed by id, roster order.
   */
  protected function blueprintRecords(MaterialsRoster $roster, ?HostInterface $host): array {
    $records = $roster->blueprints(MaterialRecord::SCOPE_BLOCK);
    if ($host === NULL) {
      return $records;
    }
    return array_filter($records, fn (MaterialRecord $record): bool => $this->offer->offered($record, $host, MaterialRecord::SCOPE_BLOCK));
  }

  /**
   * The blueprints by id: label, description, scope, entity_id, fragment, looks, placement.
   *
   * The fragment is the record's tree printed through the dialect printer
   * over the site's catalog (DialectPrinter::printFragment(): no ids, no
   * <style> block, every worn variant spelled as the page language wears
   * it, every block's sample content as its attributes and body). A tree
   * the page language cannot print (a multi-region layout, a part or
   * condition style on a definition, a worn variant the catalog spells
   * nowhere) carries "unprintable" with the printer's reason instead: the
   * blueprint stays placeable by name and the schema still answers, the
   * one recovery a whole-site read can take for one definition. The
   * entity_id rides a saved blueprint only (the page the page tools
   * address); the looks are the variants the blueprint owns, keyed by
   * machine name; the placement key rides only a band blueprint
   * (top_level). Keys with nothing to say are omitted.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialsRoster $roster
   *   The roster's offered view (the owned looks and the band verdicts).
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $records
   *   The blueprint records to print in full: the site favorites among
   *   blueprintRecords(), or one group's (narrow()).
   */
  protected function blueprints(MaterialsRoster $roster, array $records): array {
    if ($records === []) {
      return [];
    }
    $catalog = $this->catalogBuilder->build();
    $owned = [];
    foreach ($roster->variants() as $variant) {
      if ($variant->ownedBy !== NULL) {
        $owned[$variant->ownedBy][$variant->id] = $this->lookEntry($variant);
      }
    }
    $blueprints = [];
    foreach ($records as $record) {
      $entry = [
        'label' => $record->label,
        'description' => $record->description,
        'scope' => $record->blueprintScope,
      ];
      if ($record->entityId !== NULL) {
        $entry['entity_id'] = $record->entityId;
      }
      if ($record->tree !== NULL) {
        $entry += $this->fragmentEntry($record, $catalog);
      }
      if (isset($owned[$record->id])) {
        $entry['looks'] = $owned[$record->id];
      }
      if ($roster->isBand($record)) {
        $entry['placement'] = self::PLACEMENT_TOP_LEVEL;
      }
      $blueprints[$record->id] = $entry;
    }
    return $blueprints;
  }

  /**
   * A blueprint's printed fragment, or the reason the page language has none.
   *
   * The fragment is a WRITE TEMPLATE (the model rewrites it with its own
   * content), so only a wholly authored print is offered: a print with a
   * note (a region layout, a field or a style the grammar does not spell)
   * is not one the model could write back, and the entry answers the
   * first note's detail as the reason instead.
   *
   * @return array
   *   ['fragment' => the print] or ['unprintable' => the reason].
   */
  protected function fragmentEntry(MaterialRecord $record, DialectCatalog $catalog): array {
    $print = $this->printer->printFragment($record->tree, $catalog);
    if ($print->isAuthored()) {
      return ['fragment' => $print->text];
    }
    return ['unprintable' => sprintf('The page language cannot print this blueprint (%s); place it as <pb-blueprint name="%s"/> and style the placed elements once they print with ids.', $print->notes[0]->detail, $record->id)];
  }

  /**
   * An owned look's entry under its blueprint: label, spelling, description.
   *
   * No scope and no owner key: the blueprint it sits under is the owner,
   * and its placements are the only place the look is worn.
   */
  protected function lookEntry(MaterialRecord $record): array {
    $entry = ['label' => $record->label];
    $spelling = $this->spellingProse($record);
    if ($spelling !== '') {
      $entry['spelling'] = $spelling;
    }
    if ($record->description !== '') {
      $entry['description'] = $record->description;
    }
    return $entry;
  }

  /**
   * A record's spellings as the page language writes them, comma-joined.
   */
  protected function spellingProse(MaterialRecord $record): string {
    $spellings = [];
    foreach ($record->spellings as $slot => $spelling) {
      $spellings[] = MaterialsRoster::spellingProse($slot, $spelling);
    }
    return implode(', ', $spellings);
  }

  /**
   * The animations, keyed by machine name: label, spelling, steps, description.
   *
   * The steps ride the record's value (offset => declarations) so a
   * writer sees what an animation does before wearing it; the spelling is
   * the ident as animation-name writes it, never a var() (customProperty()
   * refuses an animation record on purpose). Description only when the
   * record carries one.
   */
  protected function animations(MaterialsRoster $roster): array {
    $animations = [];
    foreach ($roster->animations() as $record) {
      $entry = ['label' => $record->label];
      $spelling = $this->spellingProse($record);
      if ($spelling !== '') {
        $entry['spelling'] = $spelling;
      }
      $entry['steps'] = $record->value;
      if ($record->description !== '') {
        $entry['description'] = $record->description;
      }
      $animations[$record->id] = $entry;
    }
    return $animations;
  }

  /**
   * The fonts, keyed by machine name: label, spelling, family, faces, description.
   *
   * The record's value is {family, faces}: the family a font-family value
   * names and the faces (weight, style, source) the family ships, so a
   * writer sees which weights exist before building a stack. The spelling
   * is the quoted family as font-family writes it, never a var().
   */
  protected function fonts(MaterialsRoster $roster): array {
    $fonts = [];
    foreach ($roster->fonts() as $record) {
      $entry = ['label' => $record->label];
      $spelling = $this->spellingProse($record);
      if ($spelling !== '') {
        $entry['spelling'] = $spelling;
      }
      $entry['family'] = is_array($record->value) ? ($record->value['family'] ?? NULL) : NULL;
      $entry['faces'] = is_array($record->value) ? ($record->value['faces'] ?? []) : [];
      if ($record->description !== '') {
        $entry['description'] = $record->description;
      }
      $fonts[$record->id] = $entry;
    }
    return $fonts;
  }

  /**
   * The custom property a named value's style spelling references.
   *
   * The style spelling is the whole reference as written, var(--x); the
   * schema keys named values by the property inside it (--x), the one
   * spelling the JSON door's bindings and the page language's var() share.
   */
  public static function customProperty(MaterialRecord $record): string {
    $spelling = $record->spelling(MaterialRecord::SLOT_STYLE);
    if ($spelling === NULL || !preg_match('/^var\(\s*(--[A-Za-z0-9_-]+)\s*\)$/', $spelling, $m)) {
      throw new \LogicException(sprintf('%s "%s" has no var() style spelling to key a named value by.', ucfirst($record->kind->noun()), $record->id));
    }
    return $m[1];
  }

}
