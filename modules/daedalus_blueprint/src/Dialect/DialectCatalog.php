<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\ValueGrammar;
use Drupal\daedalus_blueprint\Materials\ValueReference;
use Drupal\daedalus_blueprint\Materials\VocabularyProseInterface;
use Drupal\daedalus_blueprint\Materials\VocabularyRosterInterface;

/**
 * The site vocabulary the dialect printer and parser speak against.
 *
 * A pure data object: which block types exist and how their fields ride
 * attributes and bodies, which condition names map to which CSS wrappers,
 * which part classes and instance namespaces are declared, and the site's
 * MATERIALS roster (tokens, swatches, variants, stencils, animations). The
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
 * - $conditions: condition name => CSS wrapper, in canonical (axis
 *   declaration) order. A wrapper starting with ':' is a selector
 *   pseudo-class (a state); one starting with '@' is an at-rule prelude.
 *   Compound condition names order their parts by this list's order.
 * - $parts: declared part class => list of block-type tags that own it.
 * - $instances: instance-namespace declarations from the spine's
 *   `instances:` lane, keyed by pattern selector ('.s-*'): each carries
 *   'label' and the declared 'tags' map (tag => label). These are the
 *   content-borne identity classes rich-text bodies wear ('s-a3f' on a
 *   paragraph) and the tags the identity minter mints; the dialect
 *   resolves the classes by the same rule every other resolver speaks
 *   (exact part, then owner pattern part, then the site instance
 *   namespaces).
 * - $textTags: the addressable text-tag vocabulary, the tags a "#id tag"
 *   selector may style (the element-scoped tag tier), in declaration
 *   order. Contributed by the styling layer from its one declaration of
 *   a prose tag's styleability (daedalus_stylish's site base section); never
 *   derived from the instance namespaces, whose tags are identity.
 * - $stylable: element kind ('container', 'block', 'field') => the kind's
 *   VOCABULARY roster (VocabularyRosterInterface: the CSS property names
 *   the site names on that kind, in declaration order, and the
 *   corrective for a miss). Contributed in every posture; empty means
 *   the styling layer declares no roster. One roster per kind at every
 *   placement depth: a declaration's grant is a function of the
 *   declaration alone (placement curates offers and birth, never
 *   legality). How a property outside a roster is judged is the
 *   validator's, not the catalog's: the parser judges grammar only.
 * - $prose: the prose generator that renders the rosters for the model
 *   (VocabularyProseInterface), from the layer that built them, so the
 *   cached prompt teaches the rosters in the words their law uses.
 * - $roster: the materials roster (MaterialsRosterBuilder::build() on a
 *   live site; a hand-built MaterialsRoster in tests). NULL is the empty
 *   roster: no material spells anywhere, so every var(), class, axis
 *   value and stencil name is refused.
 * - $birthDefaults: placement scope print ("layout:daedalus_stylish_container",
 *   "block_type:text") => [axis attribute => variant id], the variants
 *   the styling layer stamps on an op-created placement of that scope
 *   at the top level (its default_variants). The dialect itself is a
 *   total statement and stamps nothing; the view (birthDefaults()) is
 *   what the brief and the schema teach as the palette's birth.
 * - $dressedTags: the base tags the active skin's defaults dress, in the
 *   skin's order (dressedTags()): the data behind "pick the tag, the
 *   skin sizes it".
 */
final class DialectCatalog {

  /**
   * The dialect grammar version the catalog records.
   *
   * The model-facing serialization is versioned HERE, on the catalog:
   * durable artifacts never store dialect text (stencils store the lean
   * node tree, prompts regenerate from live config), so only this one
   * contract surface names the grammar's version. Tracks the dialect
   * spec; bump it when tags, attributes, value grammar, or the op
   * envelope change meaning. 0.6: placement provenance (stencil="") and
   * variant axes as attributes on every element's tag.
   */
  public const DIALECT_VERSION = '0.6';

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
   * The global attributes (identity, wear, look, provenance), the two
   * generic-form identity attributes (plugin, type), the stencil
   * reference's name, and the set op's body slot; data-* is reserved as a
   * prefix (print-only annotations).
   */
  public const RESERVED_ATTRS = ['id', 'class', 'style', 'stencil', 'plugin', 'type', 'name', self::BODY_ATTR];

  /**
   * Normalized wrapper prelude => condition name.
   */
  private array $wrapperIndex = [];

  /**
   * Condition name => position in canonical order.
   */
  private array $conditionOrder = [];

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
    private readonly array $parts = [],
    private readonly array $instances = [],
    private readonly array $stylable = [],
    ?MaterialsRoster $roster = NULL,
    private readonly array $textTags = [],
    private readonly ?VocabularyProseInterface $prose = NULL,
    private readonly array $birthDefaults = [],
    private readonly array $dressedTags = [],
    private readonly ?string $skin = NULL,
  ) {
    foreach ($stylable as $kind => $vocabulary) {
      if (!$vocabulary instanceof VocabularyRosterInterface) {
        throw new \InvalidArgumentException(sprintf('The stylable roster for kind "%s" must be a vocabulary roster (VocabularyRosterInterface).', $kind));
      }
    }
    $this->roster = $roster ?? new MaterialsRoster([], new CacheableMetadata());
    $this->grammar = new ValueGrammar();
    $position = 0;
    foreach ($conditions as $name => $wrapper) {
      $this->conditionOrder[$name] = $position++;
      if (str_starts_with($wrapper, '@')) {
        $this->wrapperIndex[self::normalizeWrapper($wrapper)] = $name;
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
          // A stencil's owned look is wearable only inside that stencil's
          // placements, so it is the stencil's to teach (the schema and
          // the brief list it under the stencil), never the element's.
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
   * axis values, the class rosters, the stencils, the named values) then
   * answers for the materials their authors share with the AI, while the
   * catalog itself, built over the full roster, stays what the parser and
   * the validators judge against. The same declarations (axes, block
   * types, conditions, parts, instances, stylable rosters, birth defaults,
   * dressed tags, skin) over the narrower roster; this very catalog when
   * nothing is opted out.
   */
  public function offered(): DialectCatalog {
    if ($this->offered === NULL) {
      $roster = $this->roster->offered();
      $this->offered = $roster === $this->roster ? $this : new self(
        containerLayoutId: $this->containerLayoutId,
        axes: $this->axes,
        blockTypes: $this->blockTypes,
        conditions: $this->conditions,
        parts: $this->parts,
        instances: $this->instances,
        stylable: $this->stylable,
        roster: $roster,
        textTags: $this->textTags,
        prose: $this->prose,
        birthDefaults: $this->birthDefaults,
        dressedTags: $this->dressedTags,
        skin: $this->skin,
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
   *   TRUE for <pb-container>.
   * @param string|null $block_type
   *   The block type machine name (declared or not) for inline blocks.
   * @param string|null $plugin_id
   *   The plugin id for <pb-block plugin>; the scope is by base plugin id.
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
   * The container kind and the container layout for <pb-container>; the
   * block kind and the block type for a declared or generic block, the
   * block kind and the base plugin id for <pb-block plugin>. The one
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
   * The dialect tag for a block type: pb- + machine name, '_' as '-'.
   */
  public static function tagForBlockType(string $type): string {
    return 'pb-' . str_replace('_', '-', $type);
  }

  /**
   * The block type machine name a pb- tag names, or NULL for non-pb tags.
   */
  public static function blockTypeForTag(string $tag): ?string {
    if (!str_starts_with($tag, 'pb-')) {
      return NULL;
    }
    return str_replace('-', '_', substr($tag, 3));
  }

  /**
   * The declared class roster for one element, by what it is.
   *
   * The style-axis variants whose scope PLACES them on the element
   * (scopesFor(): the kind, then the container layout, the block type or
   * the base plugin id), minus the looks a stencil owns (those belong to
   * the stencil's placements and list under the stencil). A class outside
   * the roster still resolves (styleVariants(), the spine judging scope);
   * the roster is what the element's teaching line and correctives name.
   *
   * @param bool $is_container
   *   TRUE for <pb-container>.
   * @param string|null $block_type
   *   The block type machine name (declared or not) for inline blocks.
   * @param string|null $plugin_id
   *   The plugin id for <pb-block plugin>; the roster is by base plugin id.
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
   * The ordered condition vocabulary: name => wrapper.
   */
  public function conditions(): array {
    return $this->conditions;
  }

  /**
   * Whether a condition is a state (its wrapper is a pseudo-class).
   */
  public function isStateCondition(string $name): bool {
    return isset($this->conditions[$name]) && str_starts_with($this->conditions[$name], ':');
  }

  /**
   * The published state condition names (pseudo-class wrappers).
   */
  public function stateConditions(): array {
    return array_keys(array_filter(
      $this->conditions,
      static fn (string $wrapper): bool => str_starts_with($wrapper, ':'),
    ));
  }

  /**
   * The published at-rule wrappers: prelude => condition name.
   */
  public function atRuleWrappers(): array {
    return array_filter(
      $this->conditions,
      static fn (string $wrapper): bool => str_starts_with($wrapper, '@'),
    );
  }

  /**
   * The published width bands: condition name => @container wrapper.
   *
   * A width band is a container-query wrapper (the page-level query root
   * measures it), in the catalog's order: narrow, then medium on the
   * shipped vocabulary. The base look (no wrapper, "default") is the wide
   * one; each band adapts it downward. This is THE responsive grammar a
   * page can write: math functions (clamp(), calc()) are not values here.
   */
  public function widthBands(): array {
    return array_filter(
      $this->conditions,
      static fn (string $wrapper): bool => str_starts_with($wrapper, '@container'),
    );
  }

  /**
   * The published preference wrappers: condition name => @media wrapper.
   *
   * Visitor preferences (color scheme, reduced motion): every at-rule
   * wrapper that is not a width band.
   */
  public function preferenceWrappers(): array {
    return array_diff_key($this->atRuleWrappers(), $this->widthBands());
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
   * The condition a wrapper prelude names, matched modulo whitespace/case.
   */
  public function conditionForWrapper(string $prelude): ?string {
    return $this->wrapperIndex[self::normalizeWrapper($prelude)] ?? NULL;
  }

  /**
   * The canonical compound spelling for a set of condition parts.
   *
   * Compound parts identify a condition regardless of order; the canonical
   * spelling orders parts by the condition table (daedalus_stylish's rule), so the
   * parser always reconstructs the one spelling the validator accepts.
   *
   * @param string[] $parts
   *   Condition names; empty means the default condition.
   */
  public function canonicalCompound(array $parts): string {
    if ($parts === []) {
      return 'default';
    }
    usort($parts, fn (string $a, string $b): int => ($this->conditionOrder[$a] ?? PHP_INT_MAX) <=> ($this->conditionOrder[$b] ?? PHP_INT_MAX));
    return implode('+', $parts);
  }

  /**
   * Splits a stored condition key into its compound parts.
   */
  public static function compoundParts(string $condition): array {
    return $condition === '' ? [] : explode('+', $condition);
  }

  /**
   * The declared part classes: class => owning block-type tags.
   */
  public function parts(): array {
    return $this->parts;
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
   * @return string[]
   *   Tag names in declaration order; [] when the styling layer declares
   *   no prose-tag styleability (no tag tier exists on the site).
   */
  public function textTags(): array {
    return $this->textTags;
  }

  /**
   * The text tag correctives and prompts use as their worked example.
   *
   * The paragraph when the site declares it (the canonical prose tag),
   * else the first declared tag; NULL when no tag tier exists.
   */
  public function exampleTextTag(): ?string {
    if ($this->textTags === []) {
      return NULL;
    }
    return in_array('p', $this->textTags, TRUE) ? 'p' : $this->textTags[0];
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
   * Normalizes an at-rule prelude for byte-for-byte-modulo-whitespace match.
   */
  private static function normalizeWrapper(string $prelude): string {
    return strtolower(trim(preg_replace('/\s+/', ' ', $prelude)));
  }

}
