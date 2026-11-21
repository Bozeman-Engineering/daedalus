<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\ConditionClause;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\Materials\ValueGrammar;
use Drupal\daedalus_blueprint\Materials\ValueReference;
use Drupal\daedalus_blueprint\Materials\VocabularyProseInterface;
use Drupal\daedalus_blueprint\Materials\VocabularyRosterInterface;

/**
 * The site vocabulary the dialect printer and parser speak against.
 *
 * A pure data object: which block types exist and how their fields ride
 * attributes and bodies, which condition names map to which CSS wrappers,
 * which parts and tags a relative selector's compounds may land on under
 * each owner, which instance namespaces are declared, and the site's
 * MATERIALS roster (tokens, variants, blueprints, animations, fonts). The
 * printer and
 * parser are grammar; this is the site's vocabulary, and it is passed in
 * rather than read from config here so the spine stays styling-agnostic
 * (the same way NodeTreeBuilder finds the style map by field type
 * string). Deriving a catalog from live config (block types, fields,
 * daedalus_stylish's declarations, the materials providers) is the catalog
 * builder's job (DialectCatalogBuilder); tests and scripts construct one
 * directly.
 *
 * Materials are never a second table here: every "which variant does this
 * class name", "what does width="full" mean", "is var(--x) published" is
 * answered by the value grammar over the roster's spellings, and the views
 * the printer and prompt read (each element's axis attributes in print
 * order, the class roster an element declares, every wearable style
 * class) are projections of the same records, computed once at
 * construction.
 *
 * Shapes:
 * - $axes: address print (Address, a variant scope) => the declared axes
 *   in print order, axis attribute name => its facts ('kind:container'
 *   => ['width' => ['description' => ..., 'owns' => ['padding-left',
 *   ...]], 'layout' => []]): the description is the axis's meaning
 *   written for a designer (NULL when undescribed), owns the property
 *   names the axis's values write (so a document wears the axis instead
 *   of writing them). Which values each axis takes, and whether it is
 *   top-level only, come from the variant records of that scope that
 *   spell as the attribute; an axis the roster spells but the declaration
 *   does not name prints after the declared ones, undescribed and owning
 *   nothing. An element's axis view (axesFor()) merges the addresses that
 *   place variants on it: its kind, then its refinement (the container
 *   layout, the block type or the base block plugin). An axis attribute
 *   colliding with a reserved attribute (RESERVED_ATTRS, data-*) or with
 *   a field attribute of a block type it rides fails the construction
 *   loudly: one attribute name means one thing on a tag.
 * - $blockTypes: block type machine name => [
 *     'attributes' => [attr name => spec, ...] in declared print order,
 *     'body' => ['field' => name, 'kind' => 'plain'|'rich'] (optional),
 *     'void' => TRUE for childless kinds parsed as void tags (optional),
 *   ], where an attribute spec is [
 *     'field' => the mapped field name,
 *     'key' => a key inside the field's array value (optional),
 *     'kind' => 'link' (bare uri string) or 'ref' (target_id or _label)
 *       (optional; default scalar),
 *     'values' => allowed values list (optional; default any),
 *   ].
 * - $conditions: condition name => ['wrapper' => CSS wrapper, 'axis' =>
 *   the condition's axis], in canonical (axis declaration) order. A
 *   wrapper starting with ':' is a selector pseudo-class (a posable
 *   state; where an element sits among its siblings is a position
 *   qualifier of the relative selector grammar, never a condition); one
 *   starting with '@' is an at-rule prelude. Compound condition names
 *   order their parts by this list's order, one part per axis; the table
 *   is the digester's roster (SelectorDigest::digest()) as it stands.
 * - $selectors: selector rosters per OWNER, the dialect tag of the
 *   element a compound resolves against (`d-container`, `d-text`,
 *   `d-block` for any plugin block or undeclared type): owner =>
 *   ['parts' => [selector => label], 'tags' => [tag, ...]]. The parts are
 *   the owner's declared part classes (with the dot, as the style map
 *   spells them); the tags are the base tags a tag compound may name
 *   there, the styling layer's one declaration of a prose tag's
 *   styleability (never the instance namespaces' identity tags). Which
 *   TYPES may follow an owner is the spine's own fact (typesOf(): every
 *   block type's tag plus d-container and d-block under a container,
 *   nothing under a block), so it is derived here, never contributed.
 * - $instances: instance-namespace declarations from the spine's
 *   `instances:` lane, keyed by pattern selector ('.s-*'): each carries
 *   'label' and the declared 'tags' map (tag => label). These are the
 *   content-borne identity classes rich-text bodies wear ('s-a3f' on a
 *   paragraph) and the tags the identity minter mints; the dialect
 *   resolves the classes by the same rule every other resolver speaks
 *   (exact part, then owner pattern part, then the site instance
 *   namespaces).
 * - $stylable: element kind ('container', 'block', 'field') => the kind's
 *   VOCABULARY roster (VocabularyRosterInterface: the CSS property names
 *   the site names on that kind, in declaration order, and the
 *   corrective for a miss). Contributed in every posture; empty means
 *   the styling layer declares no roster. One roster per kind at every
 *   placement depth: a declaration's grant is a function of the
 *   declaration alone (placement curates offers and birth, never
 *   legality). How a property outside a roster is judged is the
 *   validator's, not the catalog's: the parser judges grammar only.
 * - $prose: the prose generator that renders the rosters, bindings and
 *   deviations for the model (VocabularyProseInterface), from the layer
 *   that built them, so the cached prompt teaches them in the words
 *   their law uses.
 * - $bindings: property => its site-wide value space ({scale, stops,
 *   allowed, min, max, units} as the styling layer's law carries it), in
 *   the layer's order (bindings()): the rosters' value spaces, the same
 *   law the validator judges a value by. Empty on a site that binds
 *   nothing site-wide.
 * - $deviations: address print ("kind:container",
 *   "block_type:text::.text-block-link") => [property => value space],
 *   only the places whose own space narrows below the bindings
 *   (deviations()); empty when none does. The catalog carries the
 *   addresses; a surface labels them in its own words.
 * - $roster: the materials roster (MaterialsRosterBuilder::build() on a
 *   live site; a hand-built MaterialsRoster in tests). NULL is the empty
 *   roster: no material spells anywhere, so every var(), class, axis
 *   value and blueprint name is refused.
 * - $birthDefaults: placement scope print ("layout:daedalus_stylish_container",
 *   "block_type:text") => [axis attribute => variant id], the variants
 *   the styling layer stamps on an op-created placement of that scope
 *   at the top level (its default_variants). The dialect itself is a
 *   total statement and stamps nothing; the view (birthDefaults()) is
 *   what the brief and the schema teach as the palette's birth.
 * - $dressedTags: the base tags the active skin's defaults dress, in the
 *   skin's order (dressedTags()): the data behind "pick the tag, the
 *   skin sizes it".
 * - $groups: the site's group vocabulary, group id => ['label' => ...,
 *   'description' => ...] in declaration order (groups()): the filter
 *   categories blueprints and block types are filed under on the surfaces
 *   that offer them (the Add panel, the page chooser, the model's index),
 *   a filter and nothing else. Declared in a family the spine cannot read
 *   (daedalus_ui's `PROVIDER.page_builder*.yml`, under `site.groups`)
 *   and contributed on the build event by the layer that parses it
 *   (daedalus_layout's SiteGroups); UNGROUPED is the reserved bucket for
 *   the things in no group and is never a declared id.
 * - $cards: owner => the element's RENDER CARD (cards(), cardFor()): what
 *   the element renders as, derived from the layered markup declarations
 *   by the layer that reads them and contributed on the build event
 *   (DialectCatalogBuildEvent::addCard() holds the shape and validates
 *   it): 'renders', dialect writes paired with the HTML each renders as
 *   (input => html, plainest first), and 'shells', the kind shells an
 *   undeclared type renders through (rendersThrough() spells them). The
 *   owner is the element's dialect tag (d-container, d-{block type}) or a
 *   generically printed bundle's spelling (ownerForGenericBlockType()).
 *   The AI's one read of the markup model: the cached prompt and the
 *   schema print the pairs, the print stays a data print.
 */
final class DialectCatalog {

  /**
   * The dialect grammar version the catalog records.
   *
   * The model-facing serialization is versioned HERE, on the catalog:
   * durable artifacts never store dialect text (blueprints store the lean
   * node tree, prompts regenerate from live config), so only this one
   * contract surface names the grammar's version. Tracks the dialect
   * spec; bump it when tags, attributes, value grammar, or the op
   * envelope change meaning. 0.6: placement provenance (blueprint="") and
   * variant axes as attributes on every element's tag. 0.7: the slot
   * section (<d-slot name="id">, a top-level container filling a slot of
   * the document around it). 0.8: the linked placement (<d-linked
   * blueprint="id"/>, a blueprint shown by reference as one block).
   */
  public const DIALECT_VERSION = '0.8';

  /**
   * The attrs key a `set` op patches an element's body under.
   *
   * The body is the one authored surface that is not an attribute; in the
   * op grammar it is addressed by the contract's own slot name, beside
   * class and style, never by the raw field name (one naming system for
   * the whole attrs map: catalog short names).
   */
  public const BODY_ATTR = 'body';

  /**
   * Attribute names no variant axis may take on any tag.
   *
   * The global attributes (identity, wear, look, provenance),
   * generic-form identity attributes (plugin, type), the blueprint
   * reference's name, and the set op's body slot; data-* is reserved as a
   * prefix (print-only annotations).
   */
  public const RESERVED_ATTRS = ['id', 'class', 'style', 'blueprint', 'plugin', 'type', 'name', self::BODY_ATTR];

  /**
   * The reserved group bucket: the blueprints and block types in no group.
   *
   * The surfaces that file by group (the model's index, the Add panel)
   * print it beside the declared groups; a declaration that names it as
   * a group id is refused, by the contributor and by the build event.
   */
  public const UNGROUPED = 'ungrouped';

  /**
   * The shape of a group id: a machine name (a blueprint's `groups` spelling).
   */
  public const GROUP_ID_PATTERN = '/^[a-z0-9_]+$/';

  /**
   * The shape of a card owner: a dialect tag or d-block[type=ID].
   *
   * A bundle the contract cannot spell prints generically as <d-block
   * type="ID">, so its card (the fallback) is owned by the attribute
   * reading a reader of the print already knows how to spell;
   * ownerForGenericBlockType() is its one minting place.
   */
  public const CARD_OWNER_PATTERN = '/^' . RelativeSelector::TYPE_PREFIX . '[a-z][a-z0-9-]*(\[type=[A-Za-z0-9_:.-]+\])?$/';

  /**
   * The keys a card carries, sorted (the build event validates the shape).
   */
  public const CARD_KEYS = ['renders', 'shells'];

  /**
   * The materials roster (the empty roster when none was given).
   */
  private readonly MaterialsRoster $roster;

  /**
   * The value grammar the catalog resolves through (stateless).
   */
  private readonly ValueGrammar $grammar;

  /**
   * The axis views by variant scope, in print order.
   *
   * Shape: scope => [attr => ['scope' => scope, 'values' => [value =>
   * variant id], 'top_level_only' => bool, 'description' => ?string,
   * 'owns' => string[]]]. The scope rides each entry so a merged element
   * view (axesFor()) still says which scope's slot an attribute resolves
   * in.
   */
  private array $axesByScope = [];

  /**
   * Class rosters by variant scope: scope => [class => variant id].
   */
  private array $classByScope = [];

  /**
   * The catalog over the roster's offered view, built once (offered()).
   */
  private ?DialectCatalog $offered = NULL;

  public function __construct(
    private readonly string $containerLayoutId,
    private readonly array $axes,
    private readonly array $blockTypes,
    private readonly array $conditions,
    private readonly array $selectors = [],
    private readonly array $instances = [],
    private readonly array $stylable = [],
    ?MaterialsRoster $roster = NULL,
    private readonly ?VocabularyProseInterface $prose = NULL,
    private readonly array $birthDefaults = [],
    private readonly array $dressedTags = [],
    private readonly ?string $skin = NULL,
    private readonly array $groups = [],
    private readonly array $cards = [],
    private readonly array $bindings = [],
    private readonly array $deviations = [],
  ) {
    foreach ($stylable as $kind => $vocabulary) {
      if (!$vocabulary instanceof VocabularyRosterInterface) {
        throw new \InvalidArgumentException(sprintf('The stylable roster for kind "%s" must be a vocabulary roster (VocabularyRosterInterface).', $kind));
      }
    }
    $this->roster = $roster ?? new MaterialsRoster([], new CacheableMetadata());
    $this->grammar = new ValueGrammar();
    foreach ($conditions as $name => $definition) {
      if (!is_array($definition) || !is_string($definition['wrapper'] ?? NULL) || !is_string($definition['axis'] ?? NULL)) {
        throw new \InvalidArgumentException(sprintf('The condition "%s" must be declared as [wrapper, axis].', $name));
      }
    }
    $this->indexVariants($axes);
    $this->assertAxisAttributesFree();
  }

  /**
   * Projects the variant records into the per-scope axis and class views.
   *
   * @param array<string, array<string, array>> $declared_axes
   *   The declared axes per variant scope: attribute => {description,
   *   owns}, in print order.
   */
  private function indexVariants(array $declared_axes): void {
    $by_scope = [];
    foreach ($this->roster->variants() as $record) {
      $scope = (string) $record->scope;
      foreach ($record->spellings as $slot => $spelling) {
        if ($slot === MaterialRecord::SLOT_CLASS) {
          // A blueprint's owned look is wearable only inside that blueprint's
          // placements, so it is the blueprint's to teach (the schema and
          // the brief list it under the blueprint), never the element's.
          if ($record->ownedBy === NULL) {
            $this->classByScope[$scope][$spelling] = $record->id;
          }
        }
        elseif (($attribute = MaterialRecord::slotAttribute($slot)) !== NULL) {
          $by_scope[$scope][$attribute]['values'][$spelling] = $record->id;
          $by_scope[$scope][$attribute]['root_only'][] = $record->rootOnly();
        }
      }
    }
    foreach ($by_scope as $scope => $by_axis) {
      $declared = is_array($declared_axes[$scope] ?? NULL) ? $declared_axes[$scope] : [];
      foreach ([...array_keys($declared), ...array_keys($by_axis)] as $attribute) {
        $attribute = (string) $attribute;
        if (!isset($by_axis[$attribute]) || isset($this->axesByScope[$scope][$attribute])) {
          continue;
        }
        $facts = is_array($declared[$attribute] ?? NULL) ? $declared[$attribute] : [];
        $description = $facts['description'] ?? NULL;
        $this->axesByScope[$scope][$attribute] = [
          'scope' => $scope,
          'values' => $by_axis[$attribute]['values'],
          'top_level_only' => !in_array(FALSE, $by_axis[$attribute]['root_only'], TRUE),
          'description' => is_string($description) && $description !== '' ? $description : NULL,
          'owns' => array_values(array_map(strval(...), is_array($facts['owns'] ?? NULL) ? $facts['owns'] : [])),
        ];
      }
    }
  }

  /**
   * Refuses an axis attribute that would mean two things on one tag.
   *
   * A reserved name or a data-* spelling collides on every tag; a name a
   * declared block type maps to one of its fields collides on that
   * type's tag, which a block_type:{type} axis rides and every kind:block
   * axis rides too. Loud at construction: the declaration that spelled
   * the axis is wrong, and no print, parse or teaching may quietly pick
   * one meaning.
   */
  private function assertAxisAttributesFree(): void {
    foreach ($this->axesByScope as $scope => $axes) {
      foreach ($axes as $attribute => $axis) {
        $variants = implode(', ', $axis['values']);
        if (in_array($attribute, self::RESERVED_ATTRS, TRUE) || str_starts_with($attribute, 'data-')) {
          throw new \LogicException(sprintf('Variant axis attribute "%s" (scope %s, variants %s) is a reserved attribute name (%s, data-*); rename the axis.', $attribute, $scope, $variants, implode(', ', self::RESERVED_ATTRS)));
        }
        $address = Address::fromString($scope);
        $types = match (TRUE) {
          $address->equals(Address::kind(Address::KIND_BLOCK)) => array_keys($this->blockTypes),
          $address->family === Address::FAMILY_BLOCK_TYPE => [$address->id],
          default => [],
        };
        foreach ($types as $type) {
          if (isset($this->blockTypes[$type]['attributes'][$attribute])) {
            throw new \LogicException(sprintf('Variant axis attribute "%s" (scope %s, variants %s) collides with the %s attribute of <%s> (field %s); rename the axis.', $attribute, $scope, $variants, $attribute, self::tagForBlockType($type), $this->blockTypes[$type]['attributes'][$attribute]['field']));
          }
        }
      }
    }
  }

  /**
   * The dialect grammar version this catalog speaks (see DIALECT_VERSION).
   */
  public function version(): string {
    return self::DIALECT_VERSION;
  }

  /**
   * The one container layout's id, '' when the site declares none.
   */
  public function containerLayoutId(): string {
    return $this->containerLayoutId;
  }

  /**
   * The site's materials roster.
   */
  public function roster(): MaterialsRoster {
    return $this->roster;
  }

  /**
   * This catalog over the roster's offered view (MaterialsRoster::offered()).
   *
   * The AI is taught from here: every view a teaching surface reads (the
   * axis values, the class rosters, the blueprints, the named values) then
   * answers for the materials their authors share with the AI, while the
   * catalog itself, built over the full roster, stays what the parser and
   * the validators judge against. The same declarations (axes, block
   * types, conditions, parts, instances, stylable rosters with their
   * bindings and deviations, birth defaults, dressed tags, skin) over the
   * narrower roster; this very catalog when nothing is opted out.
   */
  public function offered(): DialectCatalog {
    if ($this->offered === NULL) {
      $roster = $this->roster->offered();
      $this->offered = $roster === $this->roster ? $this : new self(
        containerLayoutId: $this->containerLayoutId,
        axes: $this->axes,
        blockTypes: $this->blockTypes,
        conditions: $this->conditions,
        selectors: $this->selectors,
        instances: $this->instances,
        stylable: $this->stylable,
        roster: $roster,
        prose: $this->prose,
        birthDefaults: $this->birthDefaults,
        dressedTags: $this->dressedTags,
        skin: $this->skin,
        groups: $this->groups,
        cards: $this->cards,
        bindings: $this->bindings,
        deviations: $this->deviations,
      );
    }
    return $this->offered;
  }

  /**
   * The value grammar over this catalog's roster.
   */
  public function grammar(): ValueGrammar {
    return $this->grammar;
  }

  /**
   * What a value in a slot IS (ValueGrammar::resolve() over the roster).
   */
  public function resolve(string $value, string $slot): ValueReference {
    return $this->grammar->resolve($value, $slot, $this->roster);
  }

  /**
   * Every reference a value carries (ValueGrammar::references()).
   *
   * @return \Drupal\daedalus_blueprint\Materials\ValueReference[]
   *   The references, never literal.
   */
  public function references(string $value, string $slot): array {
    return $this->grammar->references($value, $slot, $this->roster);
  }

  /**
   * A value with its references respelled canonically.
   */
  public function canonical(string $value, string $slot): string {
    return $this->grammar->canonical($value, $slot, $this->roster);
  }

  /**
   * The did-you-mean hint for a reference that failed in a slot, or ''.
   */
  public function didYouMean(string $slot, string $needle): string {
    return $this->roster->didYouMeanInSlot($slot, $needle);
  }

  /**
   * The variant axes one element carries as attributes, in print order.
   *
   * The scopes that place variants on the element (scopesFor()), each
   * scope's axes in its declared order, the kind's axes first; an
   * attribute name the kind and a refinement both declare is the kind's.
   * Each entry names its scope: the attribute's value resolves in that
   * scope's slot (MaterialRecord::attrSlot(scope, attr)), never site-wide.
   *
   * @param bool $is_container
   *   TRUE for <d-container>.
   * @param string|null $block_type
   *   The block type machine name (declared or not) for inline blocks.
   * @param string|null $plugin_id
   *   The plugin id for <d-block plugin>; the scope is by base plugin id.
   *
   * @return array
   *   attr => ['scope' => variant scope, 'values' => [attr value =>
   *   variant id], 'top_level_only' => bool, 'description' => ?string,
   *   'owns' => string[]].
   */
  public function axesFor(bool $is_container, ?string $block_type, ?string $plugin_id): array {
    $axes = [];
    foreach ($this->scopesFor($is_container, $block_type, $plugin_id) as $scope) {
      $axes += $this->axesByScope[$scope] ?? [];
    }
    return $axes;
  }

  /**
   * The variant scopes that place variants on one element, kind first.
   *
   * The container kind and the container layout for <d-container>; the
   * block kind and the block type for a declared or generic block, the
   * block kind and the base plugin id for <d-block plugin>. The one
   * answer behind the axis view and the class roster.
   *
   * @return string[]
   *   The scopes, printed (the views index by the address's print).
   */
  private function scopesFor(bool $is_container, ?string $block_type, ?string $plugin_id): array {
    if ($is_container) {
      $addresses = [Address::kind(Address::KIND_CONTAINER)];
      // A site without the container layout (no placement spine layout
      // is a container) has no layout address to speak of.
      if ($this->containerLayoutId !== '') {
        $addresses[] = Address::layout($this->containerLayoutId);
      }
    }
    elseif ($plugin_id !== NULL) {
      $addresses = [Address::kind(Address::KIND_BLOCK), Address::blockPlugin(explode(':', $plugin_id, 2)[0])];
    }
    else {
      $addresses = [Address::kind(Address::KIND_BLOCK)];
      if ($block_type !== NULL) {
        $addresses[] = Address::blockType($block_type);
      }
    }
    return array_map(strval(...), $addresses);
  }

  /**
   * The variants a placement scope's elements are born wearing.
   *
   * The styling layer's birth defaults for one placement scope (the
   * container layout, a block type), resolved for a top-level placement:
   * axis attribute => variant id, in declaration order. [] for a scope
   * that stamps nothing, and for every scope on a site whose styling
   * layer contributes none. The dialect never stamps these itself (a
   * document is a total statement); they are what the palette's
   * placements wear, taught so a document can state the same look.
   *
   * @param \Drupal\daedalus_blueprint\Materials\Address $address
   *   The placement scope (a root address).
   *
   * @return array<string, string>
   *   Axis attribute => variant id.
   */
  public function birthDefaults(Address $address): array {
    return $this->birthDefaults[(string) $address->root()] ?? [];
  }

  /**
   * The base tags the active skin's defaults dress, in the skin's order.
   *
   * @return string[]
   *   Tag names; [] when no active skin dresses a tag.
   */
  public function dressedTags(): array {
    return $this->dressedTags;
  }

  /**
   * The active skin's label, or NULL when no styling layer named one.
   *
   * The skin is the active design system's (its default_skin); the
   * brief names it beside the system. The label is the styling layer's
   * contribution, so the spine reads no styling entity.
   */
  public function skin(): ?string {
    return $this->skin;
  }

  /**
   * The site's group vocabulary, in declaration order.
   *
   * The filter categories blueprints and block types are filed under on
   * the surfaces that offer them, contributed on the build event by the
   * layer that parses the declaration (DialectCatalogBuildEvent::addGroups()).
   * A surface ignores an id a thing carries that is not here (a shipped
   * blueprint from a package whose groups this site lacks); UNGROUPED is
   * the bucket for the things in no group and never a key of this map.
   *
   * @return array<string, array{label: string, description: string}>
   *   Group id => its label and description ('' when none); [] when no
   *   layer contributed any.
   */
  public function groups(): array {
    return $this->groups;
  }

  /**
   * The render cards: owner => the card, in contribution order.
   *
   * @return array<string, array{renders: array<string, string>, shells: string[]}>
   *   The cards (see the class docblock); [] when no layer contributed any.
   */
  public function cards(): array {
    return $this->cards;
  }

  /**
   * One owner's render card, or NULL when no layer declared one.
   *
   * @param string $owner
   *   The element's dialect tag (d-container, d-text) or a generically
   *   printed bundle's owner spelling (ownerForGenericBlockType()).
   *
   * @return array{renders: array<string, string>, shells: string[]}|null
   *   The card, or NULL.
   */
  public function cardFor(string $owner): ?array {
    return $this->cards[$owner] ?? NULL;
  }

  /**
   * The card owner spelling of a bundle the dialect prints generically.
   *
   * A bundle whose fields the contract cannot spell prints as <d-block
   * type="ID"> with <d-field> children, so its card (the fallback: the
   * theme's block template, each field in its shell) is owned by that
   * attribute reading, never by a d-ID tag the dialect does not speak.
   */
  public static function ownerForGenericBlockType(string $bundle): string {
    return sprintf('%s[type=%s]', RelativeSelector::TYPE_BLOCK, $bundle);
  }

  /**
   * The kind shells of a card as words.
   *
   * What an undeclared type renders through.
   *
   * The one spelling for both registers (the cached prompt's line and the
   * schema's entry live in different modules, so the words live here, on
   * the structure they describe).
   *
   * @param string[] $shells
   *   The card's shells.
   *
   * @return string
   *   "the theme's block template, each field in its field shell".
   */
  public static function rendersThrough(array $shells): string {
    return implode(', ', array_map(static fn (string $shell): string => match ($shell) {
      'block' => 'the theme\'s block template',
      'field' => 'each field in its field shell',
      default => sprintf('the %s shell', $shell),
    }, $shells));
  }

  /**
   * The [axis attr, attr value] a variant prints as, or NULL when none.
   */
  public function axisForVariant(string $variant): ?array {
    $record = $this->roster->get(MaterialKind::Variant, $variant);
    foreach ($record?->spellings ?? [] as $slot => $spelling) {
      if (($attribute = MaterialRecord::slotAttribute($slot)) !== NULL) {
        return [$attribute, $spelling];
      }
    }
    return NULL;
  }

  /**
   * The declared block types, keyed by machine name.
   */
  public function blockTypes(): array {
    return $this->blockTypes;
  }

  /**
   * The declared block type contract for a machine name, NULL when unknown.
   */
  public function blockType(string $type): ?array {
    return $this->blockTypes[$type] ?? NULL;
  }

  /**
   * The tags of the block types declared with a rich body.
   *
   * A rich body is opaque: the printer prints it verbatim under the block
   * type's own tag and the parser reads it back as text up to that tag's
   * end. Both ask here, so a block type declared rich is opaque to both.
   *
   * @return string[]
   *   Dialect tags, in declaration order.
   */
  public function richTags(): array {
    $tags = [];
    foreach ($this->blockTypes as $type => $spec) {
      if (($spec['body']['kind'] ?? NULL) === 'rich') {
        $tags[] = self::tagForBlockType((string) $type);
      }
    }
    return $tags;
  }

  /**
   * The dialect tag for a block type: the prefix + machine name, '_' as '-'.
   */
  public static function tagForBlockType(string $type): string {
    return RelativeSelector::TYPE_PREFIX . str_replace('_', '-', $type);
  }

  /**
   * The block type machine name a d- tag names, or NULL for non-pb tags.
   */
  public static function blockTypeForTag(string $tag): ?string {
    if (!str_starts_with($tag, RelativeSelector::TYPE_PREFIX)) {
      return NULL;
    }
    return str_replace('-', '_', substr($tag, strlen(RelativeSelector::TYPE_PREFIX)));
  }

  /**
   * The declared class roster for one element, by what it is.
   *
   * The style-axis variants whose scope PLACES them on the element
   * (scopesFor(): the kind, then the container layout, the block type or
   * the base plugin id), minus the looks a blueprint owns (those belong to
   * the blueprint's placements and list under the blueprint). A class outside
   * the roster still resolves (styleVariants(), the spine judging scope);
   * the roster is what the element's teaching line and correctives name.
   *
   * @param bool $is_container
   *   TRUE for <d-container>.
   * @param string|null $block_type
   *   The block type machine name (declared or not) for inline blocks.
   * @param string|null $plugin_id
   *   The plugin id for <d-block plugin>; the roster is by base plugin id.
   *
   * @return array
   *   class => variant id, in roster order.
   */
  public function classRosterFor(bool $is_container, ?string $block_type, ?string $plugin_id): array {
    $roster = [];
    foreach ($this->scopesFor($is_container, $block_type, $plugin_id) as $scope) {
      $roster += $this->classByScope[$scope] ?? [];
    }
    return $roster;
  }

  /**
   * Every published style-axis variant: class => variant id.
   */
  public function styleVariants(): array {
    return array_map(static fn (MaterialRecord $record): string => $record->id, $this->roster->spellings(MaterialRecord::SLOT_CLASS));
  }

  /**
   * The class a variant prints as, or NULL when it is not a style variant.
   */
  public function classForVariant(string $variant): ?string {
    return $this->roster->get(MaterialKind::Variant, $variant)?->spelling(MaterialRecord::SLOT_CLASS);
  }

  /**
   * The ordered condition vocabulary: name => ['wrapper', 'axis'].
   *
   * The condition table the two grammars read: the digester
   * (SelectorDigest::digest() peels a posable state and reads an at-rule
   * prelude against it) and the condition clause grammar
   * (ConditionClause, which spells a compound canonically over it).
   */
  public function conditions(): array {
    return $this->conditions;
  }

  /**
   * The condition wrappers: name => wrapper, in canonical order.
   */
  public function wrappers(): array {
    return array_map(static fn (array $definition): string => $definition['wrapper'], $this->conditions);
  }

  /**
   * The published state condition names: the pseudo-class wrappers.
   */
  public function stateConditions(): array {
    return array_keys($this->wrappersOfForm(ConditionClause::PSEUDO));
  }

  /**
   * The published width bands: condition name => @container wrapper.
   *
   * A width band is a container-query wrapper (the page-level query root
   * measures it), in the catalog's order: narrow, then medium on the
   * shipped vocabulary. The base look (no wrapper, "default") is the wide
   * one; each band adapts it downward. These are the responsive
   * REFERENCES a page can write by name; a literal query is the other
   * spelling, and math functions (clamp(), calc()) are not values here.
   */
  public function widthBands(): array {
    return $this->wrappersOfForm(ConditionClause::CONTAINER);
  }

  /**
   * The published preference wrappers: condition name => @media wrapper.
   *
   * Visitor preferences (color scheme, reduced motion): the media-query
   * wrappers.
   */
  public function preferenceWrappers(): array {
    return $this->wrappersOfForm(ConditionClause::MEDIA);
  }

  /**
   * The published wrappers of one form (ConditionClause::form()).
   *
   * @return array<string, string>
   *   Condition name => wrapper, in canonical order.
   */
  private function wrappersOfForm(string $form): array {
    return array_filter(
      $this->wrappers(),
      static fn (string $wrapper): bool => ConditionClause::form($wrapper) === $form,
    );
  }

  /**
   * The one corrective for a math function in a style value.
   *
   * Clamp()/calc()/min()/max() are refused by the value grammar, and a
   * model reaches for clamp() as its only idea of a headline that fits
   * a phone; the corrective names the lane that exists: one value per
   * width band, the wide value in style="" and the narrow value in a
   * rule under the narrowest published band, in the page document's
   * <style> block or the style op. A catalog without width bands names
   * the generic per-condition form.
   *
   * @param string $property
   *   The refused property.
   *
   * @return string
   *   The hint.
   */
  public function unsupportedValueHint(string $property): string {
    $bands = $this->widthBands();
    if ($bands === []) {
      return 'Write one value per condition, not math: the base value in style="" and an adapted value in a rule under a published wrapper (the page document\'s <style> block, or the style op).';
    }
    $name = array_key_first($bands);
    return sprintf('Responsive values are one value per width band, not math: write the wide value in style="" (the default) and the %s value in a %s rule, %s { #id { %s: ... } }, in the page document\'s <style> block or the style op.', $name, $name, $bands[$name], $property);
  }

  /**
   * The canonical compound spelling for a set of condition clauses.
   *
   * Clauses identify a condition regardless of order; the canonical
   * spelling is the condition grammar's (ConditionClause::canonical():
   * published axes by first appearance in the table, then literal axes,
   * references and literals alike), so the parser always reconstructs
   * the one spelling the validator accepts.
   *
   * @param string[] $parts
   *   Clause texts (a reference's name, a literal's canonical CSS); empty
   *   means the default condition.
   */
  public function canonicalCompound(array $parts): string {
    if ($parts === []) {
      return 'default';
    }
    return ConditionClause::canonical(implode(ConditionClause::DELIMITER, $parts), $this->conditions);
  }

  /**
   * The selector rosters per owner: dialect tag => {parts, tags}.
   *
   * @return array<string, array{parts: array<string, string>, tags: string[]}>
   *   The contributed rosters, in contribution order.
   */
  public function selectors(): array {
    return $this->selectors;
  }

  /**
   * One owner's selector roster: what a compound may land on under it.
   *
   * The parts and tags the styling layer contributed for the owner (an
   * owner no layer named has none), and the types the spine derives: a
   * container's children are the page's elements, so every declared
   * block type's tag plus d-container and d-block may follow it; a
   * block has no child elements of the page, so nothing may.
   *
   * @param string $owner
   *   The owner's dialect tag (`d-container`, `d-text`, `d-block`).
   *
   * @return array{parts: array<string, string>, tags: string[], types: string[]}
   *   The owner's parts (selector => label), tags and types.
   */
  public function selectorsFor(string $owner): array {
    $roster = $this->selectors[$owner] ?? [];
    return [
      'parts' => $roster['parts'] ?? [],
      'tags' => $roster['tags'] ?? [],
      'types' => $owner === RelativeSelector::TYPE_CONTAINER ? $this->types() : [],
    ];
  }

  /**
   * The declared parts of one owner: selector => label.
   *
   * @return array<string, string>
   *   The parts, in declaration order; [] for an owner declaring none.
   */
  public function partsOf(string $owner): array {
    return $this->selectors[$owner]['parts'] ?? [];
  }

  /**
   * The owners declaring a part class, in contribution order.
   *
   * @param string $selector
   *   The class selector, with its dot (".text-block-link").
   *
   * @return string[]
   *   The owners' dialect tags; [] when no owner declares the part.
   */
  public function partOwners(string $selector): array {
    $owners = [];
    foreach ($this->selectors as $owner => $roster) {
      if (isset($roster['parts'][$selector])) {
        $owners[] = (string) $owner;
      }
    }
    return $owners;
  }

  /**
   * Every declared part across the owners: selector => owning dialect tags.
   *
   * The site-wide projection a corrective enumerates when the owner is
   * not at hand.
   *
   * @return array<string, string[]>
   *   Part selector => the owners declaring it, in contribution order.
   */
  public function parts(): array {
    $parts = [];
    foreach ($this->selectors as $owner => $roster) {
      foreach (array_keys($roster['parts'] ?? []) as $selector) {
        $parts[(string) $selector][] = (string) $owner;
      }
    }
    return $parts;
  }

  /**
   * The tags a tag compound may name under one owner.
   *
   * @return string[]
   *   Tag names in declaration order; [] for an owner with no tag tier.
   */
  public function tagsOf(string $owner): array {
    return $this->selectors[$owner]['tags'] ?? [];
  }

  /**
   * The types a type compound may name under one owner.
   *
   * @return string[]
   *   Dialect type tags; [] under a block (it has no child elements).
   */
  public function typesOf(string $owner): array {
    return $this->selectorsFor($owner)['types'];
  }

  /**
   * Every type a type compound may spell: the block types and the kinds.
   *
   * Spelled as the page language spells the element (d-{block type},
   * d-container for any container, d-block for any other block), the
   * spine's own list: the declared block types in their order, then the
   * two kinds.
   *
   * @return string[]
   *   The type tags.
   */
  public function types(): array {
    return [
      ...array_map([self::class, 'tagForBlockType'], array_keys($this->blockTypes)),
      RelativeSelector::TYPE_CONTAINER,
      RelativeSelector::TYPE_BLOCK,
    ];
  }

  /**
   * The instance-namespace declarations, keyed by pattern selector.
   */
  public function instances(): array {
    return $this->instances;
  }

  /**
   * The addressable text-tag vocabulary: the tags "#id tag" may style.
   *
   * The union of the owners' tag rosters in contribution order (one
   * base section declares them, so every owner names the same list).
   *
   * @return string[]
   *   Tag names in declaration order; [] when the styling layer declares
   *   no prose-tag styleability (no tag tier exists on the site).
   */
  public function textTags(): array {
    $tags = [];
    foreach ($this->selectors as $roster) {
      foreach ($roster['tags'] ?? [] as $tag) {
        $tags[(string) $tag] = TRUE;
      }
    }
    return array_keys($tags);
  }

  /**
   * The text tag correctives and prompts use as their worked example.
   *
   * The paragraph when the site declares it (the canonical prose tag),
   * else the first declared tag; NULL when no tag tier exists.
   */
  public function exampleTextTag(): ?string {
    $tags = $this->textTags();
    if ($tags === []) {
      return NULL;
    }
    return in_array('p', $tags, TRUE) ? 'p' : $tags[0];
  }

  /**
   * The identity vocabulary: tag => declared label, across the namespaces.
   *
   * The merged tags maps across the instance namespaces, in declaration
   * order (first declaration wins a duplicate tag): the tags the identity
   * minter mints, the same read the collision guard and the UI labels
   * use. Not the tag tier's vocabulary (textTags() is).
   */
  public function instanceTags(): array {
    $tags = [];
    foreach ($this->instances as $declaration) {
      if (is_array($declaration) && is_array($declaration['tags'] ?? NULL)) {
        foreach ($declaration['tags'] as $tag => $label) {
          $tags[(string) $tag] ??= (string) $label;
        }
      }
    }
    return $tags;
  }

  /**
   * The instance namespace covering a concrete identity class, if any.
   *
   * The last step of the one resolution rule (exact part -> owner pattern
   * part -> site instance namespaces), the same prefix-extension match
   * the spine's resolvers use: a namespace pattern '.s-*' covers every
   * single class extending its prefix ('s-a3f'), never the bare prefix
   * itself.
   *
   * @param string $class
   *   A class name without the leading dot ('s-a3f').
   *
   * @return array|null
   *   The namespace's declaration, or NULL when no namespace covers it.
   */
  public function instanceNamespaceFor(string $class): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]+$/', $class)) {
      return NULL;
    }
    foreach ($this->instances as $pattern => $declaration) {
      $pattern = (string) $pattern;
      if (!is_array($declaration) || !preg_match('/^\.[A-Za-z0-9_-]*\*$/', $pattern)) {
        continue;
      }
      $prefix = substr($pattern, 1, -1);
      if (str_starts_with($class, $prefix) && strlen($class) > strlen($prefix)) {
        return $declaration;
      }
    }
    return NULL;
  }

  /**
   * The vocabulary rosters, keyed by element kind.
   *
   * The rosters the prompt teaches (through vocabularyProse()), the same
   * objects the semantic validator judges a write against; empty on a
   * site whose styling layer declares none. One roster per kind, the
   * same at every placement depth.
   *
   * @return array<string, \Drupal\daedalus_blueprint\Materials\VocabularyRosterInterface>
   *   kind => roster.
   */
  public function vocabularies(): array {
    return $this->stylable;
  }

  /**
   * The prose generator for the rosters, NULL when none was contributed.
   */
  public function vocabularyProse(): ?VocabularyProseInterface {
    return $this->prose;
  }

  /**
   * The site-wide bindings: property => its value space.
   *
   * The rosters' value spaces at the site level, the law a bindings line
   * states (rendered through vocabularyProse()->bindingLines()); empty on
   * a site whose styling layer binds nothing site-wide.
   *
   * @return array<string, array>
   *   Property => value space, in the styling layer's order.
   */
  public function bindings(): array {
    return $this->bindings;
  }

  /**
   * The deviations: address print => [property => its value space].
   *
   * Only the places whose own value space narrows below the bindings
   * (rendered through vocabularyProse()->deviationLines()); empty when
   * none does. Keyed by address print; the surface that prints them
   * speaks its own words for the place.
   *
   * @return array<string, array<string, array>>
   *   Address print => property => value space, in the layer's order.
   */
  public function deviations(): array {
    return $this->deviations;
  }

}
