<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_blueprint\Dialect\DialectPrinter;
use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Renders the roster as the blueprint schema's `materials` fragment.
 *
 * The JSON door's one discovery lane for every material kind, projected
 * from the roster (never walked from the entities): the named values
 * (tokens by scale, with their descriptions), the
 * saved variants with their descriptions and where they apply, the
 * blueprints printed in full as page-language fragments (sample content
 * and worn looks, the definition as a document without ids) with the
 * looks each one owns, the animations with their keyframe steps and the
 * fonts with their faces, under one guidance paragraph that teaches how
 * each kind is read and referenced, every description whole. The JSON
 * door (eb_edit, the API, the CLI) edits variant and skin
 * entities whose style maps reference named values and has no cached
 * brief to lean on, so fragment() keeps every kind.
 *
 * The dialect door reads the same roster twice: the cached prompt's
 * design system brief (DialectCatalogPrompt) is its always-on layer and
 * carries every token, variant, animation and font with its
 * whole description and value, so a small edit is made from the brief
 * alone, and the schema is its composition manual. dialect() narrows
 * this fragment to what the brief does not carry: the group index, the
 * blueprints in full (the fragment and the looks, the one place a
 * blueprint's rule rides), the animations' steps and the fonts' faces,
 * with no description on an animation or a font (the brief's) and its
 * own guidance paragraph, which teaches only those kinds
 * (DIALECT_KEYS). DialectSchemaProjector applies it; each material fact
 * has one home on that door.
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
 * The `blueprints` group holds EVERY OFFERED block blueprint in full
 * (MaterialRecord::$offered, the "share with the AI" flag): a blueprint's
 * entry prints its whole body, so the fragment grows with every blueprint
 * shared, and the AI context budget (MaterialsBudget) is the ceiling that
 * refuses sharing past it. A site favorite (MaterialRecord::$favorite) is
 * the Add panel's listing and means nothing to this surface. The `groups`
 * group is the site's group vocabulary (DialectCatalog::groups(),
 * declaration order) with how many shared block blueprints each holds,
 * plus the reserved DialectCatalog::UNGROUPED bucket for the shared ones
 * in no declared group: the vocabulary a saved blueprint is filed under,
 * and a filter. One group is read alone on demand: narrow() replaces the
 * blueprints group with that group's entries AFTER the cached schema is
 * read (the schema cache key never changes), which is what
 * eb_read_schema's `group` parameter does.
 *
 * The fragment is also a weight source of the AI context budget
 * (MaterialsBudget): weights() attributes the no-host fragment (the
 * heaviest view, block scope unfiltered by fit) entry by entry (JSON,
 * the shape the JSON door ships and the brief's measure is calibrated
 * against): each record's entry with its key is the record's, a kind's
 * braces, the commas between its entries and (for tokens) the scale
 * keys and braces are the kind's shared, the guidance and the group
 * index are the system's (the index rides every declared group at a
 * zero count, so no material toggle shrinks it). A shared blueprint's
 * whole fragment, its owned looks included, is what that blueprint
 * weighs, which is why sharing is the lever the budget watches.
 */
class MaterialsSchema implements MaterialsWeightSourceInterface {

  /**
   * The applies sentence a root-only variant carries on its entry.
   */
  public const APPLIES_ROOT_ONLY = 'top-level sections only, never nested inside another section';

  /**
   * The placement value a section blueprint carries on its entry.
   *
   * A blueprint whose root wears a root-only variant (MaterialsRoster::
   * isSection()) places at the document's top level only; every other
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
  public const KEYS = ['guidance', 'tokens', 'variants', 'groups', 'blueprints', 'animations', 'fonts'];

  /**
   * The dialect door's keys, in the order they ride: a subset of KEYS.
   *
   * What the design system brief does not already carry whole: the
   * group index, the blueprints in full, the animations' steps and the
   * fonts' faces. The tokens and variants are the brief's
   * alone on that door. dialect() assembles by it, dialectKinds() derives
   * the kinds a surface names from it, and a surface describing the
   * dialect schema (the structure line, eb_read_schema's wire
   * description) says what this list says.
   */
  public const DIALECT_KEYS = ['guidance', 'groups', 'blueprints', 'animations', 'fonts'];

  /**
   * The keys whose entries carry no description on the dialect door.
   *
   * An animation's and a font's description is the brief's; their
   * entries here carry what the brief does not (the steps, the faces).
   */
  public const DIALECT_UNDESCRIBED = ['animations', 'fonts'];

  /**
   * The key of a group entry's count: the offered block blueprints it holds.
   */
  public const GROUP_COUNT = 'blueprints';

  /**
   * The dialect paragraph's opening: what this shape is and where the rest is.
   *
   * The brief is the one always-on layer on the dialect door, so a
   * request about "the materials" means the brief's set; this key is
   * the composition manual, and an absence here speaks only for the
   * kinds it carries (a token is never here, and the brief says whether
   * the site has any).
   */
  public const DIALECT_OPENING = 'The materials the design system brief in your instructions does not already carry: the brief lists every token, variant, animation and font with its whole description and value, and the blueprints as a menu (a request like "keep to the materials" or "ignore the materials" means the brief\'s set); this key carries what composing reads on demand, the blueprints in full, the animations\' steps and the fonts\' faces, and one of those kinds absent here has none on this site.';

  /**
   * The animation sentence both doors' paragraphs state.
   */
  public const ANIMATIONS_SENTENCE = '"animations" are the published keyframes animations, keyed by machine name; "spelling" is the ident an animation-name value (or the name in an animation shorthand) references, never a var(); "steps" are the keyframes as stored, offset => declarations.';

  /**
   * The font sentence both doors' paragraphs state.
   */
  public const FONTS_SENTENCE = '"fonts" are the published self-hosted font families, keyed by machine name; "spelling" is the quoted family a font-family value (or the font shorthand) references, never a var(); "faces" are the files the family ships, one per weight and style.';

  public function __construct(
    protected MaterialsRosterBuilder $rosterBuilder,
    protected DialectCatalogBuilder $catalogBuilder,
    protected DialectPrinter $printer,
    protected CanvasOffer $offer,
  ) {}

  /**
   * The fragment: guidance, then one group per kind the site has.
   *
   * The blueprints group holds every offered block blueprint in full; the
   * groups group is the index over them (narrow() lists one group alone
   * after the cache).
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
    return $this->assemble([
      'guidance' => $this->guidance($roster, $records, $groups),
      'tokens' => $this->tokens($roster),
      'variants' => $this->variants($roster),
      'groups' => $groups,
      'blueprints' => $this->blueprints($roster, $records),
      'animations' => $this->animations($roster),
      'fonts' => $this->fonts($roster),
    ]);
  }

  /**
   * A fragment with one group's blueprints alone: the group as a filter.
   *
   * Applied AFTER the cached schema is read, never inside the cache: the
   * group's records are re-read from the roster (memoized per request)
   * and printed in full in place of the whole shared set, every other
   * group of the fragment (the guidance included) carried through as
   * cached. An empty group leaves the blueprints group absent, as an
   * empty kind is.
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
    // KEYS orders both doors' shapes: DIALECT_KEYS is its subset in the
    // same order, so a dialect-narrowed fragment keeps its shape here.
    return $this->assemble($fragment);
  }

  /**
   * The fragment narrowed to the dialect door: what the brief does not carry.
   *
   * Applied by DialectSchemaProjector AFTER the cached schema is read
   * (fragment() is one cached projection for both doors): the keys
   * DIALECT_KEYS names carry through as cached, every other kind leaves
   * (the brief states each token and variant whole), an
   * animation's and a font's entry drops its description (the brief's)
   * and keeps its spelling, steps or faces, and the guidance is the
   * dialect paragraph, which teaches only the kinds this shape carries.
   * A pure function of the fragment: narrow() composes with it in either
   * order, since a group listing is a blueprints group like any other.
   *
   * @param array $fragment
   *   The fragment as fragment() built it, or as narrow() replaced its
   *   blueprints group.
   *
   * @return array
   *   The narrowed fragment, in DIALECT_KEYS order, the empty kinds absent.
   */
  public function dialect(array $fragment): array {
    $narrowed = array_intersect_key($fragment, array_flip(self::DIALECT_KEYS));
    foreach (self::DIALECT_UNDESCRIBED as $key) {
      foreach (array_keys($narrowed[$key] ?? []) as $id) {
        unset($narrowed[$key][$id]['description']);
      }
    }
    $narrowed['guidance'] = $this->dialectGuidance($narrowed);
    return $this->assemble($narrowed, self::DIALECT_KEYS);
  }

  /**
   * The material kinds a dialect fragment carries, as the plural nouns.
   *
   * Derived from DIALECT_KEYS, so a surface naming "the materials the
   * schema carries" (the projector's structure line, eb_read_schema's
   * wire description) and the shape itself cannot drift apart.
   *
   * @param string[]|null $keys
   *   A narrowed fragment's keys, to name the kinds that fragment
   *   actually carries (grows-by-data); NULL for every kind the dialect
   *   door may carry.
   *
   * @return string[]
   *   The plurals, in MaterialKind order.
   */
  public static function dialectKinds(?array $keys = NULL): array {
    $kinds = [];
    foreach (MaterialKind::cases() as $kind) {
      $plural = $kind->plural();
      if (in_array($plural, self::DIALECT_KEYS, TRUE) && ($keys === NULL || in_array($plural, $keys, TRUE))) {
        $kinds[] = $plural;
      }
    }
    return $kinds;
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
   * @param string[] $keys
   *   The door's key order: KEYS (the JSON door's, which also orders a
   *   dialect-narrowed fragment) or DIALECT_KEYS.
   */
  protected function assemble(array $parts, array $keys = self::KEYS): array {
    $fragment = [];
    foreach ($keys as $key) {
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
   * {@inheritdoc}
   *
   * The no-host fragment weighed as the characters of its JSON, one
   * render, the same fragment() the schema caches. A key named for a kind
   * (MaterialKind::plural()) is that kind's, entry by entry: a record
   * weighs its entry's JSON plus its key, quotes and colon (the tokens'
   * keys are custom properties, mapped back to the record ids through
   * the same offered roster); what the kind's JSON has
   * beyond its entries (its braces, the commas between entries, a scale's
   * key, colon and braces) is the kind's shared. Every other key (a
   * string key, the guidance, at its own length) is the system's.
   */
  public function weights(): MaterialsWeights {
    $records = [];
    $shared = [];
    $system = 0;
    $kinds = [];
    foreach (MaterialKind::cases() as $kind) {
      $kinds[$kind->plural()] = $kind;
    }
    $ids = $this->namedValueIds();
    foreach ($this->fragment() as $key => $value) {
      if (!isset($kinds[$key])) {
        $system += strlen(is_string($value) ? $value : self::json($value));
        continue;
      }
      $kind = $kinds[$key];
      $entries = $kind === MaterialKind::Token ? array_merge(...array_values($value)) : $value;
      $weighed = [];
      foreach ($entries as $entry_key => $entry) {
        $id = isset($ids[$kind->value]) ? $ids[$kind->value][$entry_key] : (string) $entry_key;
        $weighed[$id] = strlen(self::json((string) $entry_key)) + 1 + strlen(self::json($entry));
      }
      $records[$kind->value] = $weighed;
      $shared[$kind->value] = strlen(self::json($value)) - array_sum($weighed);
    }
    return new MaterialsWeights($records, $shared, $system);
  }

  /**
   * The record behind each named value's key: kind value => property => id.
   *
   * The fragment keys tokens by the custom property
   * (customProperty()), the budget's records by the record id; the map
   * is read off the same offered roster fragment() projects.
   *
   * @return array<string, array<string, string>>
   *   For MaterialKind::Token, custom property => record id.
   */
  protected function namedValueIds(): array {
    $roster = $this->rosterBuilder->build()->offered();
    $ids = [MaterialKind::Token->value => []];
    foreach ($roster->tokens() as $record) {
      $ids[MaterialKind::Token->value][self::customProperty($record)] = $record->id;
    }
    return $ids;
  }

  /**
   * A fragment value as the JSON the door ships it as (what weights() counts).
   */
  protected static function json(mixed $value): string {
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  }

  /**
   * {@inheritdoc}
   *
   * The roster's and the offer's, the same facts cacheTags() names for
   * a cached schema, with the roster's contexts.
   */
  public function cacheMetadata(): CacheableMetadata {
    return $this->rosterBuilder->cacheMetadata()->merge($this->offer->cacheMetadata());
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
   * The JSON door's guidance paragraph: roster-reading facts only.
   *
   * Facts about reading the roster live here (schema = facts); the
   * preference order and the mint gate ride the style guide's own guidance
   * and the design system brief (MaterialsProse, the one source), and the
   * membership sentence is the brief's. Door-neutral: it names the page
   * language's spellings and no JSON op. The kind sentences it shares
   * with the dialect paragraph (dialectGuidance()) are one text each.
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
    $sentences = [
      'The site\'s materials; a request like "keep to the materials" or "ignore the materials" means this set, and a kind absent here has no materials on this site. Whose members they are (the active design system\'s plus the global ones) is the design system brief\'s sentence.',
      '"tokens" are the design tokens the active skin mints, grouped by scale (the palette\'s colors and gradients among them): named values, keyed by the CSS custom property a style value references as var(--name) (e.g. var(--space-4), var(--color-accent)), each entry carrying its "value" and, when its author wrote one, a "description" (why it exists and when to reach for it). A value is a plain string or a condition-keyed map of VALUES for that ONE name ("default" always applies; a key like "dark" is the condition under which the SAME name resolves to that value, never part of its name: var(--color-bg-dark) names nothing). Colors fit color-valued properties and stops inside gradients; gradients fit image-valued properties such as background-image, alone or as a comma-separated layer.',
      '"variants" are the saved reusable looks, keyed by machine name; "spelling" is how the page language wears one (class="name", or an axis attribute on the element\'s tag such as width="full"), an entry without an axis is on the built-in "style" axis, and an "applies" line narrows where a variant may be worn.',
    ];
    if ($blueprints !== []) {
      $sentences[] = self::blueprintSentence(array_filter($blueprints, $roster->isSection(...)) !== []);
    }
    if ($groups !== []) {
      $sentences[] = self::groupsSentence();
    }
    if ($roster->animations() !== []) {
      $sentences[] = self::ANIMATIONS_SENTENCE;
    }
    if ($roster->fonts() !== []) {
      $sentences[] = self::FONTS_SENTENCE;
    }
    return implode(' ', $sentences);
  }

  /**
   * The dialect door's guidance paragraph: the kinds the narrowed shape carries.
   *
   * Opens by saying where the rest is (the brief, the always-on layer
   * the model edits from), then teaches each kind present in the
   * narrowed fragment with the same sentence the JSON door's paragraph
   * uses, grows-by-data over the fragment's own keys: the blueprint
   * vocabulary rides while the shape lists blueprints or indexes groups,
   * the section sentence while a listed blueprint is a section, the animation
   * and font sentences while their groups ride. Never a token or
   * variant sentence: those kinds are not here to read.
   *
   * @param array $narrowed
   *   The fragment narrowed to DIALECT_KEYS (dialect()).
   */
  protected function dialectGuidance(array $narrowed): string {
    $sentences = [self::DIALECT_OPENING];
    if (isset($narrowed['blueprints']) || isset($narrowed['groups'])) {
      $has_sections = array_filter($narrowed['blueprints'] ?? [], static fn (array $entry): bool => ($entry['placement'] ?? NULL) === self::PLACEMENT_TOP_LEVEL) !== [];
      $sentences[] = self::blueprintSentence($has_sections);
    }
    if (isset($narrowed['groups'])) {
      $sentences[] = self::groupsSentence();
    }
    if (isset($narrowed['animations'])) {
      $sentences[] = self::ANIMATIONS_SENTENCE;
    }
    if (isset($narrowed['fonts'])) {
      $sentences[] = self::FONTS_SENTENCE;
    }
    return implode(' ', $sentences);
  }

  /**
   * The blueprint sentences both paragraphs state.
   *
   * @param bool $has_sections
   *   Whether a section is among the blueprints the paragraph covers: the
   *   placement sentence rides only then (grows-by-data).
   */
  protected static function blueprintSentence(bool $has_sections): string {
    return '"blueprints" are saved compositions (one root container each, with its children), placed as <d-blueprint name="id"/> elements in a page-language fragment; each entry\'s "fragment" is the blueprint printed in the page language, its sample content and worn looks included, and its "looks" are the looks the blueprint owns, wearable only inside its placements.'
      . ' ' . self::LOOKS_BY_POSITION
      . ($has_sections ? ' A blueprint with "placement": "' . self::PLACEMENT_TOP_LEVEL . '" is a section (its root wears a top-level-only variant): place it at the document\'s top level, never inside another container.' : '')
      . ' A blueprint with an "entity_id" is saved on this site and is a page of its own: page_read (entity_type blueprint, that entity_id) prints its body with ids and page_edit edits it, changing what every later placement lands; one without is shipped by a module and read-only.'
      . ' When the user asks to save a composition as a blueprint: build the subtree with ordinary ops, then save_material (kind blueprint) its root element in the same page_edit batch.';
  }

  /**
   * The group-index sentence both paragraphs state.
   */
  protected static function groupsSentence(): string {
    return '"groups" is the site\'s group vocabulary (the ids a blueprint\'s groups take) with how many offered blueprints each holds, "' . DialectCatalog::UNGROUPED . '" the ones in no group; "blueprints" holds every blueprint shared with you in full, or one group\'s alone when the read named a group: eb_read_schema with group (an id, or ' . DialectCatalog::UNGROUPED . ') lists that group here instead.';
  }

  /**
   * The tokens, grouped by scale, keyed by custom property: value, description.
   *
   * The value as stored (a string or a condition-keyed map); the
   * description (why the token exists and when to reach for it, the
   * skin author's reasoning) rides only when the record carries one, the
   * omit-when-nothing rule the variants follow. Whole here and whole on
   * the brief; on the dialect door the tokens are the brief's alone.
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
   * machine name; the placement key rides only a section blueprint
   * (top_level). Keys with nothing to say are omitted.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialsRoster $roster
   *   The roster's offered view (the owned looks and the section verdicts).
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $records
   *   The blueprint records to print in full: every one of
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
      if ($roster->isSection($record)) {
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
    return ['unprintable' => sprintf('The page language cannot print this blueprint (%s); place it as <d-blueprint name="%s"/> and style the placed elements once they print with ids.', $print->notes[0]->detail, $record->id)];
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
