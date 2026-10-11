<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Vocabulary;

// cspell:ignore zzzx
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\Materials\VocabularyRosterInterface;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;

/**
 * The vocabulary roster: what one address may style, resolved.
 *
 * The twin of the materials roster (MaterialsRoster): the materials roster
 * is one partition of records per request, this is one PROJECTION OF LAW
 * per address and theme, built by VocabularyRosterBuilder from the
 * layered declarations (the style guide) and the site's enforcement mode.
 * Every consumer that asks "what may this thing style" (the validator's
 * two lanes, the dialect catalog the AI parses and reads against, the
 * client payload, the schema prose) reads one of these instead
 * of deriving the answer for itself, so the answers can never disagree.
 *
 * What it holds, for the address (a root: "kind:container",
 * "block_type:text", "layout:layout_onecol", "base:a"; or a relative
 * selector under one: "kind:block::.part", "kind:block::p",
 * "layout:x::> pb-text .link"):
 * - grants: the declared property groups with their allowlists resolved,
 *   group id => the property names it grants, in declaration order;
 *   granted() flattens them. A grant is a function of the declaration
 *   alone: placement curates offers and birth, never legality, so the
 *   roster carries no depth and one answer serves a page band and a
 *   container nested inside it. Grants are stated over longhands and a
 *   shorthand family (PropertyGroupInterface::getShorthands(): padding
 *   over its four sides, gap over row-gap and column-gap, border-radius
 *   over its corners) is granted wherever every longhand it sets is
 *   granted, resolved by the builder like an allowlist; the served names
 *   already carry the answer, so no consumer derives a shorthand from
 *   the sides, and a miss on one names the missing longhands
 *   (corrective()).
 * - value spaces: per granted property, the effective constraint block
 *   ({scale, stops, allowed, min, max, units} as authored), resolved node
 *   first, then the site's authored constraint_defaults; a
 *   scale-constrained property's closed value list (the scale's minted
 *   tokens as var() references, plus the allowed entries) is
 *   scaleValues(), read from the materials roster, and the stops a basic
 *   row leads with (the element's curated stops, else the scale's
 *   offered tokens) is scaleStops(), the client's stopsFor twin.
 * - axes: the element's variant axis vocabulary (the built-in "style"
 *   axis first, then the declared axes in declaration order), each with
 *   its authored label, its root_only depth stamp (the same fact the
 *   materials records carry as applies.depth; a stamp the placement
 *   consumers read, never a legality fact), its description (the axis's
 *   meaning written for a designer, NULL when undescribed) and the
 *   properties it owns (the names its values write; a document wears the
 *   axis instead of writing them, and the validator's element door
 *   answers such a write with a warning, never an error). A part's or
 *   children address carries its element's axes: variants are worn by
 *   elements.
 * - conditions: the site's condition vocabulary (name => the condition
 *   definition: label, wrapper, type, axis, and the structured band or
 *   preference facts), in canonical compound order.
 * - parts: the declared parts under the address (selector => label, at
 *   every depth of the elements tree) and whether the children
 *   pseudo-part is declared.
 * - materials: the records of the materials roster applicable at the
 *   address, read lazily from the roster the builder hands in (the
 *   materials roster is memoized per request and workspace and reset by
 *   the material hooks on its own; reading it at call time keeps this
 *   roster's memo free of a workspace key and never stale after a
 *   mid-request mint). Variants pair by scope (Address::pairsWith())
 *   against the address's LANDING (landingScope(): the element the
 *   selector's last compound names, the root itself for a root address;
 *   none for a part, the children or a subjectless compound, where no
 *   variant pairs: variants are worn by elements, and a rule wears one
 *   at its matches), every other kind applies everywhere; depth and
 *   ownership are stamps on the records, not filters here.
 * - enforcement: the site's mode and the two predicates every write asks
 *   (propertiesClosed(), constraintsAreLaw()).
 * - cache metadata: what a cached product of the roster (the client
 *   payload, the cached prompt, a stylesheet) must depend on: the
 *   declarations tag, the design-system pointer, enforcement's source,
 *   and the materials roster's own metadata.
 * - correctives: the nearest granted names and the one hint for a
 *   property outside the grant, on the object (candidates(),
 *   didYouMean(), corrective()), the one formatter every door's miss is
 *   answered with (the validator's STYLE_PROPERTY_NOT_DECLARED, whether
 *   the write came as JSON or as the page language).
 *
 * declared() says whether the address names a declared place at all: a
 * root address always does (an undeclared refinement degrades to its
 * kind, a legal undeclared base tag to an empty declaration), a selector
 * address only when every compound of the roster walk landed on a
 * declaration and its terminal pseudo-element, if any, is declared by
 * its host's `pseudo` section; undeclared() names the first compound
 * that did not (a pseudo-element as a compound of kind KIND_PSEUDO). The
 * posture toward an undeclared place (rejected strict, grammar-only
 * open) is the validator's, not the roster's; an undeclared
 * pseudo-element alone still carries a grant, the derivation from its
 * host (the builder's walk), since existence is universal.
 *
 * Immutable after construction and stringable through its address.
 */
final class VocabularyRoster implements VocabularyRosterInterface {

  /**
   * The roster-home sentence every property corrective ends with.
   */
  public const ROSTER_HOME = 'The full roster this element may style is in the catalog (the STYLES lines of your instructions).';

  /**
   * The undeclared() kind of a pseudo-element no host declares.
   *
   * Beside RelativeSelector's compound kinds: the walk reports a derived
   * (undeclared) generated box as a compound of this kind named by the
   * pseudo ("before", "after"), positioned after the chain, so the
   * validator's strict corrective can name it (STYLE_PSEUDO_NOT_DECLARED)
   * while the roster still carries the derived grant for open sites.
   */
  public const KIND_PSEUDO = 'pseudo';

  /**
   * Constructs a roster; VocabularyRosterBuilder is the live source.
   *
   * @param \Drupal\daedalus_blueprint\Materials\Address $address
   *   The address the roster answers for.
   * @param string $theme
   *   The theme whose layered declarations the answers come from.
   * @param array<string, string[]> $grants
   *   Group id => granted property names, allowlists resolved, in
   *   declaration order.
   * @param array<string, array> $valueSpaces
   *   Property => effective constraint block, for the granted properties
   *   that carry one.
   * @param array<string, array{label: ?string, root_only: bool, description: ?string, owns: string[]}> $axes
   *   Axis machine name => its label, depth stamp, description and owned
   *   properties, "style" first.
   * @param array<string, array> $conditions
   *   Condition name => definition, in canonical order.
   * @param array<string, string> $parts
   *   Declared part selector => label, under the address.
   * @param bool $childrenDeclared
   *   Whether the address declares the '> *' children section.
   * @param bool $declared
   *   Whether the address names a declared place.
   * @param array{combinator: ?string, kind: string, name: string, qualifiers: array, position: int}|null $undeclared
   *   The first selector compound the roster walk found no declaration
   *   for, anywhere in the selector (its RelativeSelector compound plus
   *   its position in its own chain), NULL when the address is declared.
   * @param array|null $landing
   *   The declaration a class part resolves against at the end of the
   *   walk: the owner's root, or the last type compound's declaration.
   *   What a corrective enumerates the declared parts of.
   * @param string $enforcement
   *   The site's enforcement mode (StyleGuide::ENFORCEMENT_*).
   * @param \Closure|null $materials
   *   fn (): MaterialsRoster, the materials roster to read applicable
   *   records from; NULL is the empty roster.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheMetadata
   *   What the vocabulary half depends on; the materials roster's own
   *   metadata is merged in when read.
   * @param array<string, string[]> $shorthands
   *   The shorthand families of the site's property groups (shorthand =>
   *   the longhands it sets, PropertyGroupPluginManager::getShorthands());
   *   the corrective's vocabulary for naming a shorthand's missing
   *   longhands. The grants already carry the family rule's answer.
   */
  public function __construct(
    private readonly Address $address,
    private readonly string $theme,
    private readonly array $grants = [],
    private readonly array $valueSpaces = [],
    private readonly array $axes = [],
    private readonly array $conditions = [],
    private readonly array $parts = [],
    private readonly bool $childrenDeclared = FALSE,
    private readonly bool $declared = TRUE,
    private readonly string $enforcement = StyleGuide::ENFORCEMENT_GUARDED,
    private readonly ?\Closure $materials = NULL,
    private readonly ?CacheableMetadata $cacheMetadata = NULL,
    private readonly array $shorthands = [],
    private readonly ?array $undeclared = NULL,
    private readonly ?array $landing = NULL,
  ) {
    if (!in_array($enforcement, [
      StyleGuide::ENFORCEMENT_OPEN,
      StyleGuide::ENFORCEMENT_GUARDED,
      StyleGuide::ENFORCEMENT_STRICT,
    ], TRUE)) {
      throw new \InvalidArgumentException(sprintf('"%s" is not an enforcement mode.', $enforcement));
    }
    foreach ($grants as $group => $properties) {
      if (!is_string($group) || $group === '' || !is_array($properties) || !array_is_list($properties)) {
        throw new \InvalidArgumentException(sprintf('The grants of the roster for "%s" map group ids to property-name lists.', $address));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function address(): Address {
    return $this->address;
  }

  /**
   * The theme the roster was resolved for.
   */
  public function theme(): string {
    return $this->theme;
  }

  /**
   * Whether the address names a declared place.
   */
  public function declared(): bool {
    return $this->declared;
  }

  /**
   * The declared property groups: group id => granted names.
   *
   * @return array<string, string[]>
   *   Allowlists resolved, in declaration order.
   */
  public function grants(): array {
    return $this->grants;
  }

  /**
   * The declared group ids, in declaration order.
   *
   * @return string[]
   *   The ids of the groups that grant here.
   */
  public function groups(): array {
    return array_keys($this->grants);
  }

  /**
   * {@inheritdoc}
   */
  public function granted(): array {
    $names = [];
    foreach ($this->grants as $properties) {
      array_push($names, ...array_map(strval(...), $properties));
    }
    return array_values(array_unique($names));
  }

  /**
   * Whether the address grants a property.
   */
  public function grantsProperty(string $property): bool {
    return in_array($property, $this->granted(), TRUE);
  }

  /**
   * The group that grants a property here, or NULL when none does.
   */
  public function groupOf(string $property): ?string {
    foreach ($this->grants as $group => $properties) {
      if (in_array($property, $properties, TRUE)) {
        return (string) $group;
      }
    }
    return NULL;
  }

  /**
   * Every granted property's effective constraint block.
   *
   * @return array<string, array>
   *   Property => block, for the granted properties that carry one.
   */
  public function valueSpaces(): array {
    return $this->valueSpaces;
  }

  /**
   * The effective constraint block of a property, [] when unconstrained.
   *
   * Node first, then the site's authored constraint_defaults; the block
   * as authored ({scale, stops, allowed, min, max, units}).
   */
  public function valueSpace(string $property): array {
    return $this->valueSpaces[$property] ?? [];
  }

  /**
   * The closed value list of a scale-constrained property.
   *
   * The scale's minted tokens as var() references (the materials roster's
   * token records on that scale, in roster order) plus the constraint's
   * allowed entries: exactly what the validator's scale rule accepts. []
   * when the property is not scale-constrained.
   *
   * @return string[]
   *   The values a scale-constrained property may take.
   */
  public function scaleValues(string $property): array {
    $constraints = $this->valueSpace($property);
    if (!isset($constraints['scale'])) {
      return [];
    }
    $values = [];
    foreach ($this->materialsRoster()->tokens() as $record) {
      if ($record->group === (string) $constraints['scale'] && ($spelling = $record->spelling(MaterialRecord::SLOT_STYLE)) !== NULL) {
        $values[] = $spelling;
      }
    }
    foreach (is_array($constraints['allowed'] ?? NULL) ? $constraints['allowed'] : [] as $extra) {
      $values[] = (string) $extra;
    }
    return $values;
  }

  /**
   * The stops a basic row bound to a scale offers, in row order.
   *
   * The offer half of scaleValues() (legality is the whole scale; this
   * is which of it a chip row leads with), the server twin of the
   * client's stopsFor (style-guide.js scaleRow) so the payload and the
   * roster can never disagree about a row:
   * - The element's curated stops where its block declares them, in list
   *   order, the names the worn skin does not mint dropped (a curated
   *   name is a promise on the skin, SkinConformance's fact; a row never
   *   offers a token that does not exist). A curated row is exact: a
   *   package token the list leaves out is never offered there.
   * - Else the scale's OFFERED tokens in stored order (the skin's
   *   `offered` flag per token: the site-wide everyday steps are a fact
   *   about the tokens, never a guide list), which is also what a
   *   curated row falls back to when none of its names are minted.
   * - On a curated row, SITE-MINTED tokens (a record whose origin is not
   *   shipped: minted on the site, in its skin's site layer) follow the
   *   curated ones while offered, on open and guarded systems, so a
   *   token minted in the UI reaches the rows without a guide edit; on a
   *   strict system they are withheld from basic rows, the curated list
   *   being the law's own offer there (advanced mode and the code view
   *   still offer the whole scale, legality unchanged).
   * - The constraint's allowed entries append after the tokens, as in
   *   scaleValues().
   *
   * @return string[]
   *   The var() spellings the row offers, then the allowed literals; []
   *   when the property is not scale-constrained.
   */
  public function scaleStops(string $property): array {
    $constraints = $this->valueSpace($property);
    if (!isset($constraints['scale'])) {
      return [];
    }
    $scale = (string) $constraints['scale'];
    $minted = [];
    foreach ($this->materialsRoster()->tokens() as $record) {
      if ($record->group === $scale && ($spelling = $record->spelling(MaterialRecord::SLOT_STYLE)) !== NULL) {
        $minted[$record->label] = $record;
      }
    }
    $curated = is_array($constraints['stops'] ?? NULL) ? array_map('strval', $constraints['stops']) : [];
    $row = [];
    foreach ($curated as $name) {
      if (isset($minted[$name])) {
        $row[$name] = $minted[$name];
      }
    }
    if ($row === []) {
      $row = array_filter($minted, static fn (MaterialRecord $record): bool => $record->offered);
    }
    elseif (!$this->propertiesClosed()) {
      foreach ($minted as $name => $record) {
        if (!isset($row[$name]) && $record->offered && $record->origin !== MaterialRecord::ORIGIN_SHIPPED) {
          $row[$name] = $record;
        }
      }
    }
    $values = array_values(array_map(static fn (MaterialRecord $record): string => $record->spelling(MaterialRecord::SLOT_STYLE), $row));
    foreach (is_array($constraints['allowed'] ?? NULL) ? $constraints['allowed'] : [] as $extra) {
      $values[] = (string) $extra;
    }
    return $values;
  }

  /**
   * The variant axis vocabulary with its labels, stamps and facts.
   *
   * @return array<string, array{label: ?string, root_only: bool, description: ?string, owns: string[]}>
   *   Axis => facts, the built-in "style" axis first.
   */
  public function axes(): array {
    return $this->axes;
  }

  /**
   * The described axes: axis => its description.
   *
   * @return array<string, string>
   *   Only the axes carrying a description, in vocabulary order.
   */
  public function axisDescriptions(): array {
    $descriptions = [];
    foreach ($this->axes as $axis => $facts) {
      if (is_string($facts['description'] ?? NULL) && $facts['description'] !== '') {
        $descriptions[$axis] = $facts['description'];
      }
    }
    return $descriptions;
  }

  /**
   * The owning axes: axis => the property names it owns.
   *
   * @return array<string, string[]>
   *   Only the axes owning a property, in vocabulary order.
   */
  public function axisOwns(): array {
    $owns = [];
    foreach ($this->axes as $axis => $facts) {
      if (is_array($facts['owns'] ?? NULL) && $facts['owns'] !== []) {
        $owns[$axis] = array_values($facts['owns']);
      }
    }
    return $owns;
  }

  /**
   * The axis that owns a property here, or NULL when none does.
   *
   * The read behind the validator's STYLE_PROPERTY_AXIS_OWNED warning: a
   * write of the property on the element should be a wear of this axis.
   */
  public function axisOwning(string $property): ?string {
    foreach ($this->axisOwns() as $axis => $properties) {
      if (in_array($property, $properties, TRUE)) {
        return (string) $axis;
      }
    }
    return NULL;
  }

  /**
   * The axis machine names, "style" first.
   *
   * @return string[]
   *   The names.
   */
  public function axisNames(): array {
    return array_keys($this->axes);
  }

  /**
   * The axes stamped root-only ({axis: {depth: root}}).
   *
   * @return string[]
   *   The names; [] when every axis applies at every depth.
   */
  public function rootOnlyAxes(): array {
    return array_keys(array_filter($this->axes, static fn (array $axis): bool => $axis['root_only']));
  }

  /**
   * The condition vocabulary: name => definition, in canonical order.
   */
  public function conditions(): array {
    return $this->conditions;
  }

  /**
   * The declared parts under the address: selector => label.
   *
   * @return array<string, string>
   *   Every part of the elements tree at any depth, in declaration order.
   */
  public function parts(): array {
    return $this->parts;
  }

  /**
   * Whether the address declares the '> *' children section.
   */
  public function childrenDeclared(): bool {
    return $this->childrenDeclared;
  }

  /**
   * The first selector compound the walk found undeclared, or NULL.
   *
   * @return array{combinator: ?string, kind: string, name: string, qualifiers: array, position: int}|null
   *   The compound (RelativeSelector's shape plus its position in its
   *   own chain), NULL when the address names a declared place.
   */
  public function undeclared(): ?array {
    return $this->undeclared;
  }

  /**
   * The declared parts of the walk's landing owner: selector => label.
   *
   * The owner a class part resolves against where the walk ended (the
   * address's root, or the last type compound's declaration), flattened
   * at every depth of its elements tree: what a corrective for an
   * undeclared part enumerates.
   *
   * @return array<string, string>
   */
  public function landingParts(): array {
    $parts = [];
    foreach (PageBuilderDeclarations::flattenElements($this->landing ?? []) as $selector => $part) {
      $parts[(string) $selector] = (string) ($part['label'] ?? $selector);
    }
    return $parts;
  }

  /**
   * The element scope a variant worn at the address pairs against.
   *
   * The landing: for a root address the root itself (an element wears a
   * variant on its own box); for a selector address the element its LAST
   * compound names, since a rule's wear is delivered at the rule's
   * targets: a type compound's root address (`> pb-text` lands on
   * block_type:text, `> pb-container` on kind:container:
   * RelativeSelector::typeAddress()), a tag compound's base tag (`a` on
   * base:a, `> p + p` on base:p). NULL where the targets are no element
   * scope: a part (`.cls`, `> pb-text .link`: a part is its element's
   * anatomy, and variants are worn by elements), the children (`> *`,
   * `> *:not(pb-image)`), a `:not()`-only compound and a generated box
   * (`&::before`, `> pb-text::after`: a pseudo-element is a box of its
   * host, no element, and the anchor names no compound at all). There
   * no variant pairs, and the validator refuses a reference with a
   * corrective naming it. Derived from the address alone: the walk's
   * declaration landing decides grants, this decides pairing, and both
   * read the same last compound.
   *
   * @return \Drupal\daedalus_blueprint\Materials\Address|null
   *   The landing's root address, or NULL when no variant pairs here.
   */
  public function landingScope(): ?Address {
    if ($this->address->isRoot()) {
      return $this->address;
    }
    if ($this->address->selector->pseudo !== NULL) {
      return NULL;
    }
    $last = $this->address->selector->last();
    return match ($last['kind']) {
      RelativeSelector::TYPE => RelativeSelector::typeAddress($last['name']),
      RelativeSelector::TAG => Address::base($last['name']),
      default => NULL,
    };
  }

  /**
   * The materials applicable at the address, in roster order.
   *
   * Variants whose scope pairs with the address's landing (the family
   * rule at landingScope(); none pairs where the landing is no element
   * scope), and every token, swatch, animation and blueprint; each record
   * carries its own depth and ownership stamps for the placement-aware
   * consumers.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   The records.
   */
  public function materials(): array {
    $landing = $this->landingScope();
    return array_values(array_filter(
      $this->materialsRoster()->all(),
      static fn (MaterialRecord $record): bool => $record->kind !== MaterialKind::Variant || ($landing !== NULL && ($record->scope?->pairsWith($landing) ?? FALSE)),
    ));
  }

  /**
   * The variants wearable at the address, keyed by id.
   *
   * @return array<string, \Drupal\daedalus_blueprint\Materials\MaterialRecord>
   *   The records, in roster order.
   */
  public function variants(): array {
    $variants = [];
    foreach ($this->materials() as $record) {
      if ($record->kind === MaterialKind::Variant) {
        $variants[$record->id] = $record;
      }
    }
    return $variants;
  }

  /**
   * The materials roster the answers read from.
   *
   * Read through the closure on every call, never memoized here: the
   * materials builder memoizes per request and workspace and drops its
   * memo when a material is written mid-request, and holding the
   * instance from the first read would hide that reset from this roster.
   */
  public function materialsRoster(): MaterialsRoster {
    return $this->materials === NULL
      ? new MaterialsRoster([], new CacheableMetadata())
      : ($this->materials)();
  }

  /**
   * The site's enforcement mode (StyleGuide::ENFORCEMENT_*).
   */
  public function enforcement(): string {
    return $this->enforcement;
  }

  /**
   * Whether the property world is closed (strict): the grant is law.
   */
  public function propertiesClosed(): bool {
    return $this->enforcement === StyleGuide::ENFORCEMENT_STRICT;
  }

  /**
   * Whether authored constraints bind (guarded or strict).
   */
  public function constraintsAreLaw(): bool {
    return $this->enforcement !== StyleGuide::ENFORCEMENT_OPEN;
  }

  /**
   * What a cached product of this roster must depend on.
   */
  public function getCacheableMetadata(): CacheableMetadata {
    $metadata = new CacheableMetadata();
    if ($this->cacheMetadata !== NULL) {
      $metadata->addCacheableDependency($this->cacheMetadata);
    }
    return $metadata->addCacheableDependency($this->materialsRoster()->getCacheableMetadata());
  }

  /**
   * {@inheritdoc}
   *
   * Two tiers: the names sharing the property's first segment, in roster
   * order ("padding" -> padding-top, padding-bottom, padding-inline: the
   * family the writer was reaching for), then the edit-distance and
   * containment neighbors ("font-color" -> color), closest first.
   */
  public function candidates(string $property, int $limit = 5): array {
    $needle = strtolower($property);
    $segment = explode('-', $needle, 2)[0];
    $family = [];
    $scored = [];
    foreach ($this->granted() as $candidate) {
      if ($candidate === $needle) {
        continue;
      }
      if (explode('-', $candidate, 2)[0] === $segment) {
        $family[] = $candidate;
        continue;
      }
      $distance = levenshtein($needle, $candidate);
      // Needle-contains-candidate only counts for candidates long enough
      // to mean something ("zzzx" does not mean the writer wanted "x").
      if ($distance <= 3 || str_contains($candidate, $needle) || (strlen($candidate) >= 4 && str_contains($needle, $candidate))) {
        $scored[$candidate] = $distance;
      }
    }
    asort($scored);
    return array_slice([...$family, ...array_keys($scored)], 0, $limit);
  }

  /**
   * {@inheritdoc}
   */
  public function didYouMean(string $property, int $limit = 5): string {
    return self::didYouMeanSentence($this->candidates($property, $limit));
  }

  /**
   * {@inheritdoc}
   *
   * The one refusal (StyleMapValidator's STYLE_PROPERTY_NOT_DECLARED, on
   * every door a write reaches it by): the nearest granted names, and
   * where the whole roster lives. A shorthand missed by the family rule
   * (some of its longhands granted, not all) names the longhands it sets
   * and the missing ones first: the writer reached for a family the
   * address grants in part, and the fix is the granted sides, not a
   * neighbor. The roster itself never rides the hint; the catalog
   * teaches it once (the STYLES lines), and a corrective that repeated
   * ~100 names per miss costs ~1k tokens for a one-word fix.
   */
  public function corrective(string $property): string {
    $sentence = $this->didYouMean($property);
    $family = $this->missingLonghands($property);
    if ($family !== NULL) {
      $sentence = sprintf(
        '"%s" sets %s and is granted only where all of them are; %s %s not granted here.%s',
        $property,
        self::listed($this->shorthands[$property]),
        self::listed($family),
        count($family) === 1 ? 'is' : 'are',
        $sentence === '' ? '' : ' ' . $sentence,
      );
    }
    if ($sentence === '') {
      return sprintf('Nothing granted here is close to "%s". %s', $property, self::ROSTER_HOME);
    }
    return $sentence . ' ' . self::ROSTER_HOME;
  }

  /**
   * The ungranted longhands of a shorthand the address grants in part.
   *
   * @return string[]|null
   *   The longhands the family rule found missing, in family order; NULL
   *   when the property is not a shorthand family, is granted, or none
   *   of its longhands is granted (a stranger to the address, corrected
   *   like any other name).
   */
  private function missingLonghands(string $property): ?array {
    $longhands = $this->shorthands[$property] ?? NULL;
    if ($longhands === NULL || $this->grantsProperty($property)) {
      return NULL;
    }
    $missing = array_values(array_diff($longhands, $this->granted()));
    return $missing === $longhands ? NULL : $missing;
  }

  /**
   * Renders names as prose: "a", "a and b", "a, b and c".
   *
   * @param string[] $names
   *   The names, in order.
   */
  private static function listed(array $names): string {
    $last = array_pop($names);
    return $names === [] ? $last : implode(', ', $names) . ' and ' . $last;
  }

  /**
   * Renders candidates as the did-you-mean sentence, '' for none.
   *
   * @param string[] $candidates
   *   The candidates, best first.
   */
  private static function didYouMeanSentence(array $candidates): string {
    if ($candidates === []) {
      return '';
    }
    $last = array_pop($candidates);
    return sprintf('Did you mean %s?', $candidates === [] ? $last : implode(', ', $candidates) . ' or ' . $last);
  }

  /**
   * The roster's address, printed.
   */
  public function __toString(): string {
    return (string) $this->address;
  }

}
