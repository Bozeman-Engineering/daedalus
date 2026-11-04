<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\Materials\VocabularyProseInterface;
use Drupal\daedalus_blueprint\Materials\VocabularyRosterInterface;

/**
 * Collects the styling DECLARATIONS half of the site's dialect vocabulary.
 *
 * The catalog builder derives everything the spine itself owns (block types
 * with their field attribute contracts, the container layout id) and reads the
 * site's MATERIALS from the materials roster (tokens, variants, blueprints,
 * animations, fonts: MaterialsRosterBuilder over the tagged providers), then
 * dispatches this event so the styling layer (daedalus_stylish, or whatever
 * declares conditions and axes on a site) can contribute the declarations no
 * material record carries: each variant scope's axis attribute order with each
 * axis's description and the properties it owns, the condition vocabulary with
 * its CSS wrappers, the selector rosters per owner (the declared part classes
 * and the addressable base tags a relative selector's compounds may land on
 * under a container, a block type or any other block), the instance namespaces,
 * the vocabulary rosters per element kind with their value spaces (the
 * site-wide bindings and the per-address deviations) and the prose that teaches
 * them, the birth defaults each placement scope stamps and the base tags the
 * active skin dresses. The site's group vocabulary (the filter categories
 * blueprints and block types are filed under on the surfaces that offer them)
 * rides the same event: its declaration lives in a family the spine cannot
 * read, so the UI layer that parses it contributes the groups here. The render
 * cards (what each element renders as: dialect writes paired with the HTML they
 * render as, or the kind shells an undeclared type renders through) ride it
 * too, derived from the markup declarations by the layer that reads them.
 * Nothing here names a material by spelling: a material reaches the catalog
 * through the roster or not at all (a birth default names a variant by id,
 * which the roster resolves), so the catalog, the cached prompt, the schema and
 * the validators never disagree about what is published.
 */
final class DialectCatalogBuildEvent extends Event {

  public const EVENT_NAME = 'daedalus_blueprint.dialect_catalog_build';

  /**
   * Axis facts per variant scope: scope => [attr => {description, owns}].
   *
   * Attributes in declared print order; each carries the axis's
   * description (NULL when undescribed) and the property names it owns.
   *
   * @var array<string, array<string, array{description: ?string, owns: string[]}>>
   */
  private array $axes = [];

  /**
   * Birth defaults per placement scope: scope => [attr => variant id].
   *
   * @var array<string, array<string, string>>
   */
  private array $birthDefaults = [];

  /**
   * The base tags the active skin's defaults dress, in authored order.
   *
   * @var string[]
   */
  private array $dressedTags = [];

  /**
   * The active skin's label, NULL until a layer names one.
   */
  private ?string $skin = NULL;

  /**
   * The site's group vocabulary: group id => {label, description}.
   *
   * @var array<string, array{label: string, description: string}>
   */
  private array $groups = [];

  /**
   * The render cards per owner: dialect tag => the validated card.
   *
   * @var array<string, array{renders: array<string, string>, shells: string[]}>
   */
  private array $cards = [];

  /**
   * Condition name => CSS wrapper, in canonical order.
   */
  private array $conditions = [];

  /**
   * Selector rosters per owner: dialect tag => {parts, tags}.
   *
   * @var array<string, array{parts: array<string, string>, tags: string[]}>
   */
  private array $selectors = [];

  /**
   * Instance-namespace declarations: pattern selector => {label, tags}.
   */
  private array $instances = [];

  /**
   * Vocabulary rosters per element kind: kind => the roster.
   *
   * @var array<string, \Drupal\daedalus_blueprint\Materials\VocabularyRosterInterface>
   */
  private array $stylable = [];

  /**
   * The prose that renders the vocabulary rosters, from their layer.
   */
  private ?VocabularyProseInterface $prose = NULL;

  /**
   * The site-wide bindings: property => its value space.
   *
   * @var array<string, array>
   */
  private array $bindings = [];

  /**
   * The deviations: address print => [property => its value space].
   *
   * @var array<string, array<string, array>>
   */
  private array $deviations = [];

  /**
   * Constructs the build event.
   *
   * @param array $blockTypes
   *   The spine-derived block type contracts (read-only for subscribers).
   * @param string $containerLayoutId
   *   The one container layout's id ('' when the site declares none).
   * @param string[] $placeableBundles
   *   The inline block bundles the placement spine allows site-wide, the
   *   builder's one placement read (read-only for subscribers; [] for a
   *   site without block types, and for a hand-built event).
   */
  public function __construct(
    private readonly array $blockTypes,
    private readonly string $containerLayoutId = '',
    private readonly array $placeableBundles = [],
  ) {}

  /**
   * The one container layout's id, '' when the site declares none.
   */
  public function getContainerLayoutId(): string {
    return $this->containerLayoutId;
  }

  /**
   * The spine-derived block type contracts, keyed by machine name.
   */
  public function getBlockTypes(): array {
    return $this->blockTypes;
  }

  /**
   * The placeable inline block bundles, the builder's one placement answer.
   *
   * A superset of the block types' keys: the bundles whose fields the
   * contract cannot spell are placeable too, and print generically. A
   * subscriber that names the placeable set reads it here and never asks
   * the placement spine itself, so a bare site (no block types) reaches
   * no block plugin discovery through the catalog build.
   *
   * @return string[]
   *   The placeable inline block bundle names.
   */
  public function getPlaceableBundles(): array {
    return $this->placeableBundles;
  }

  /**
   * Declares a variant scope's axes, in print order, with their facts.
   *
   * The declaration facts the roster does not carry: which axis prints
   * first on the element the scope places variants on, what each axis
   * means (its description, written for a designer: the sequence rules
   * and the "when" no single value can say) and which properties it OWNS
   * (the properties its values write, so a document wears the axis
   * instead of writing them; the styling layer answers such a write with
   * a warning, never an error). Which variants each axis offers, and
   * whether an axis is top-level only, are read from the variant records'
   * attribute spellings and applicability facts. Scopes are the variant
   * scopes ("kind:container", "kind:block", "block_type:text",
   * "block_plugin:system_menu_block"); the catalog merges the scopes that
   * place variants on one element (the kind, then its refinement) into
   * that element's axis view.
   *
   * @param string $scope
   *   The variant scope the axes belong to.
   * @param array<string, array> $axes
   *   Axis attribute name ('width', 'layout') => its facts, in declared
   *   order: 'description' (a string, or absent/NULL for none) and 'owns'
   *   (a list of property names, or absent for none). An attribute
   *   declared twice keeps its first facts.
   */
  public function addAxes(string $scope, array $axes): void {
    if (Address::parse($scope)?->isRoot() !== TRUE) {
      throw new \InvalidArgumentException(sprintf('Axes are declared per variant scope, a root address ("kind:container", "block_type:text", ...); "%s" is not one.', $scope));
    }
    foreach ($axes as $attribute => $facts) {
      if (!is_string($attribute) || $attribute === '' || !is_array($facts)) {
        throw new \InvalidArgumentException('Axes are declared as attribute name => {description, owns} in print order; the axis values come from the materials roster.');
      }
      $description = $facts['description'] ?? NULL;
      $owns = $facts['owns'] ?? [];
      if (($description !== NULL && !is_string($description)) || !is_array($owns) || !array_is_list($owns) || array_filter($owns, static fn ($name): bool => !is_string($name) || $name === '') !== []) {
        throw new \InvalidArgumentException(sprintf('The facts of axis "%s" on %s are a description (a string or NULL) and owns (a list of property names).', $attribute, $scope));
      }
      $this->axes[$scope][$attribute] ??= ['description' => $description, 'owns' => array_values($owns)];
    }
  }

  /**
   * The declared axes per scope: scope => [attr => {description, owns}].
   *
   * @return array<string, array<string, array{description: ?string, owns: string[]}>>
   *   Attributes in print order, each with its facts.
   */
  public function getAxes(): array {
    return $this->axes;
  }

  /**
   * Declares the variants a placement scope's elements are born wearing.
   *
   * The styling layer's birth defaults (daedalus_stylish's default_variants,
   * resolved for a top-level placement) for the scopes op-created
   * placements are stamped from: the container layout and each block
   * type. Keyed by the axis ATTRIBUTE the element's tag speaks (the same
   * key space as the axis views), valued by the variant id the roster
   * resolves. The dialect is a total statement and stamps nothing under
   * a document; the catalog carries these so the brief and the schema can
   * teach what the palette's placements wear.
   *
   * @param string $scope
   *   The placement scope ("layout:daedalus_stylish_container",
   *   "block_type:text").
   * @param array<string, string> $defaults
   *   Axis attribute => variant id.
   */
  public function addBirthDefaults(string $scope, array $defaults): void {
    if (Address::parse($scope)?->isRoot() !== TRUE) {
      throw new \InvalidArgumentException(sprintf('Birth defaults are declared per placement scope, a root address ("layout:daedalus_stylish_container", "block_type:text", ...); "%s" is not one.', $scope));
    }
    foreach ($defaults as $attribute => $variant) {
      if (!is_string($attribute) || $attribute === '' || !is_string($variant) || $variant === '') {
        throw new \InvalidArgumentException(sprintf('Birth defaults on %s map axis attribute names to variant ids.', $scope));
      }
      $this->birthDefaults[$scope][$attribute] ??= $variant;
    }
  }

  /**
   * The declared birth defaults: scope => [attr => variant id].
   *
   * @return array<string, array<string, string>>
   *   The defaults per placement scope.
   */
  public function getBirthDefaults(): array {
    return $this->birthDefaults;
  }

  /**
   * Declares the base tags the active skin's defaults dress.
   *
   * The skin's tag defaults (its base:{tag} scopes) are what lets a
   * document pick a heading tag and leave its size to the skin; the
   * catalog carries the tag list so the brief and the schema can say so
   * from data instead of hand prose.
   *
   * @param string[] $tags
   *   Tag names, in the skin's authored order.
   */
  public function addDressedTags(array $tags): void {
    foreach ($tags as $tag) {
      if (!is_string($tag) || $tag === '') {
        throw new \InvalidArgumentException('Dressed tags are a list of tag names.');
      }
    }
    $this->dressedTags = array_values(array_unique(array_merge($this->dressedTags, $tags)));
  }

  /**
   * The base tags the active skin dresses.
   *
   * @return string[]
   *   The base tags the active skin dresses, [] when no skin does.
   */
  public function getDressedTags(): array {
    return $this->dressedTags;
  }

  /**
   * Names the active skin.
   *
   * The skin is the design system's, and the brief names it beside the
   * system; the styling layer that loads it contributes its label so the
   * spine never reads a styling entity. Two layers naming a skin is a
   * wiring fault, refused loudly.
   *
   * @param string $label
   *   The active skin's label.
   */
  public function setSkin(string $label): void {
    if ($label === '') {
      throw new \InvalidArgumentException('The skin label is a non-empty string.');
    }
    if ($this->skin !== NULL) {
      throw new \LogicException(sprintf('Two catalog contributors named the active skin ("%s", "%s").', $this->skin, $label));
    }
    $this->skin = $label;
  }

  /**
   * The active skin's label, or NULL when no layer named one.
   */
  public function getSkin(): ?string {
    return $this->skin;
  }

  /**
   * Contributes the site's group vocabulary, in declaration order.
   *
   * A group is the site's word for what a block is for: a filter category
   * the surfaces that offer blueprints and block types (the Add panel, the
   * page chooser, the model's index) file them under, and nothing else; no
   * operation, fit or placement rule reads it. The layer that parses the
   * declaration family contributes the groups validated; the catalog
   * carries them so every surface reads one answer. Each id is a machine
   * name with a non-empty label and an optional description ('' when none).
   * DialectCatalog::UNGROUPED is the reserved bucket for the things in no
   * group and may never be declared. An id contributed twice keeps its
   * first declaration.
   *
   * @param array<string, array{label: string, description?: ?string}> $groups
   *   Group id => its label and optional description, in declaration order.
   */
  public function addGroups(array $groups): void {
    foreach ($groups as $id => $declaration) {
      $id = (string) $id;
      if (!preg_match(DialectCatalog::GROUP_ID_PATTERN, $id)) {
        throw new \InvalidArgumentException(sprintf('A group id is a machine name (lowercase letters, digits and underscores); "%s" is not one.', $id));
      }
      if ($id === DialectCatalog::UNGROUPED) {
        throw new \InvalidArgumentException(sprintf('"%s" is the reserved bucket for the things in no group; a declared group never takes it.', DialectCatalog::UNGROUPED));
      }
      $label = is_array($declaration) ? ($declaration['label'] ?? NULL) : NULL;
      $description = is_array($declaration) ? ($declaration['description'] ?? '') : NULL;
      if (!is_string($label) || trim($label) === '' || !is_string($description)) {
        throw new \InvalidArgumentException(sprintf('The group "%s" is declared as a non-empty label and an optional description (a string).', $id));
      }
      $this->groups[$id] ??= ['label' => trim($label), 'description' => trim($description)];
    }
  }

  /**
   * The contributed group vocabulary: group id => {label, description}.
   *
   * @return array<string, array{label: string, description: string}>
   *   The groups in declaration order; the description '' when none.
   */
  public function getGroups(): array {
    return $this->groups;
  }

  /**
   * Contributes one owner's render card: what its element renders as.
   *
   * A card is the AI's one read of the markup model, in the form a reader
   * learns a function from: input/output pairs. Each pair maps a dialect
   * write (`<d-text url="…">…</d-text>`) to the HTML the resolver renders
   * it as (`<p><a class="text-block-link" href="…">…</a></p>`), derived from
   * the layered markup declarations by the layer that reads them
   * (daedalus_ui) and printed by every AI surface from the catalog; the
   * dialect print itself stays a data print. The shape:
   * - 'renders': the pairs, input => html, in teaching order (the plainest
   *   write first); both non-empty strings. [] only for a type nothing
   *   resolves (the fallback card).
   * - 'shells': the kind shells a declaration-less type renders through
   *   ('block', 'field'); [] for a declared element.
   * A card carries at least one of the two. Anything else is refused
   * loudly, as is a second card for one owner.
   *
   * @param string $owner
   *   The owner's dialect tag (d-container, d-{block type}) or a
   *   generically printed bundle's owner spelling
   *   (DialectCatalog::ownerForGenericBlockType()).
   * @param array{renders: array<string, string>, shells: string[]} $card
   *   The card, in the shape above.
   */
  public function addCard(string $owner, array $card): void {
    if (!preg_match(DialectCatalog::CARD_OWNER_PATTERN, $owner)) {
      throw new \InvalidArgumentException(sprintf('Cards are declared per owner, a dialect tag (%s, %s{block type}) or a generically printed bundle spelled %s[type=ID]; "%s" is not one.', RelativeSelector::TYPE_CONTAINER, RelativeSelector::TYPE_PREFIX, RelativeSelector::TYPE_BLOCK, $owner));
    }
    if (isset($this->cards[$owner])) {
      throw new \LogicException(sprintf('Two catalog contributors declared the card of %s.', $owner));
    }
    $this->cards[$owner] = self::validCard($owner, $card);
  }

  /**
   * The contributed render cards: owner => the card.
   *
   * @return array<string, array{renders: array<string, string>, shells: string[]}>
   *   The cards in contribution order, each in the shape addCard() takes.
   */
  public function getCards(): array {
    return $this->cards;
  }

  /**
   * Validates a card loudly.
   */
  private static function validCard(string $owner, array $card): array {
    $keys = array_keys($card);
    sort($keys);
    if ($keys !== DialectCatalog::CARD_KEYS) {
      throw new \InvalidArgumentException(sprintf('The card of %s carries exactly %s; got %s.', $owner, implode(', ', DialectCatalog::CARD_KEYS), implode(', ', array_keys($card)) ?: 'nothing'));
    }
    if (!is_array($card['renders'])) {
      throw new \InvalidArgumentException(sprintf('The renders of %s map each dialect write to the HTML it renders as.', $owner));
    }
    foreach ($card['renders'] as $input => $html) {
      if (!is_string($input) || trim($input) === '' || !is_string($html) || trim($html) === '') {
        throw new \InvalidArgumentException(sprintf('A render of %s is a dialect write mapped to the HTML it renders as, both non-empty strings; got %s => %s.', $owner, json_encode($input), json_encode($html)));
      }
    }
    if (!is_array($card['shells']) || !array_is_list($card['shells']) || array_filter($card['shells'], static fn ($shell): bool => !is_string($shell) || $shell === '') !== []) {
      throw new \InvalidArgumentException(sprintf('The shells of %s are a list of kind names.', $owner));
    }
    if ($card['renders'] === [] && $card['shells'] === []) {
      throw new \InvalidArgumentException(sprintf('The card of %s says nothing: no renders and no shells.', $owner));
    }
    return ['renders' => $card['renders'], 'shells' => array_values($card['shells'])];
  }

  /**
   * Contributes condition declarations (name => ['wrapper', 'axis']).
   *
   * Later layers never override a name an earlier layer declared.
   */
  public function addConditions(array $conditions): void {
    $this->conditions += $conditions;
  }

  /**
   * The declared conditions, name => ['wrapper', 'axis'], in canonical order.
   */
  public function getConditions(): array {
    return $this->conditions;
  }

  /**
   * Contributes one owner's selector roster: its parts and its tags.
   *
   * The owner is a dialect tag (`d-container`, `d-text`, `d-block` for
   * any plugin block or undeclared type): what the compounds of a
   * relative selector under "#id" may land on when that element, or a
   * type compound naming it, is the owner. 'parts' are the declared part
   * classes (with the leading dot, as the style map spells them) with
   * their labels; 'tags' the base tags a tag compound may name there
   * (the styling layer's one declaration of a prose tag's styleability,
   * the same list its validator resolves the tag tier through). Which
   * TYPES may follow an owner is the spine's fact (a container's children
   * are the page's elements; a block has none), derived by the catalog,
   * never contributed. Layers merge per owner: a part keeps its first
   * label, tags accumulate in order.
   *
   * @param string $owner
   *   The owner's dialect tag.
   * @param array{parts?: array<string, string>, tags?: string[]} $roster
   *   The owner's parts (selector => label) and tags.
   */
  public function addSelectors(string $owner, array $roster): void {
    if (!preg_match(RelativeSelector::TYPE_PATTERN, $owner)) {
      throw new \InvalidArgumentException(sprintf('Selector rosters are declared per owner, a dialect tag (%s, %s{block type}, %s); "%s" is not one.', RelativeSelector::TYPE_CONTAINER, RelativeSelector::TYPE_PREFIX, RelativeSelector::TYPE_BLOCK, $owner));
    }
    if (array_diff_key($roster, ['parts' => TRUE, 'tags' => TRUE]) !== []) {
      throw new \InvalidArgumentException(sprintf('The selector roster of %s carries parts and tags only (types are the spine\'s).', $owner));
    }
    $parts = $roster['parts'] ?? [];
    $tags = $roster['tags'] ?? [];
    if (!is_array($parts) || !is_array($tags) || !array_is_list($tags)) {
      throw new \InvalidArgumentException(sprintf('The selector roster of %s is parts (selector => label) and tags (a list of tag names).', $owner));
    }
    $entry = $this->selectors[$owner] ?? ['parts' => [], 'tags' => []];
    foreach ($parts as $selector => $label) {
      if (!is_string($selector) || !preg_match(RelativeSelector::CLASS_PATTERN, $selector) || !is_string($label)) {
        throw new \InvalidArgumentException(sprintf('A part of %s is a class selector (".class-name") mapped to its label.', $owner));
      }
      $entry['parts'][$selector] ??= $label;
    }
    foreach ($tags as $tag) {
      if (!is_string($tag) || !preg_match(RelativeSelector::TAG_PATTERN, $tag)) {
        throw new \InvalidArgumentException(sprintf('A tag of %s is a base tag name.', $owner));
      }
    }
    $entry['tags'] = array_values(array_unique(array_merge($entry['tags'], $tags)));
    $this->selectors[$owner] = $entry;
  }

  /**
   * The selector rosters per owner: dialect tag => {parts, tags}.
   *
   * @return array<string, array{parts: array<string, string>, tags: string[]}>
   *   The contributed rosters, in contribution order.
   */
  public function getSelectors(): array {
    return $this->selectors;
  }

  /**
   * Contributes instance-namespace declarations from the spine.
   *
   * Each entry keys by pattern selector ('.s-*') and carries the
   * namespace's 'label' plus its declared 'tags' map (tag => label): the
   * content-borne identity classes rich-text bodies wear and the tags
   * the identity minter mints, with their labels. Identity only: which
   * tags a "#id tag" selector may style is each owner's selector roster
   * (addSelectors()), declared where a prose tag's styleability lives.
   */
  public function addInstances(array $instances): void {
    $this->instances += $instances;
  }

  /**
   * The instance-namespace declarations, pattern selector => {label, tags}.
   */
  public function getInstances(): array {
    return $this->instances;
  }

  /**
   * Contributes the vocabulary rosters, their value spaces and their prose.
   *
   * The styling layer hands over, per element kind ('container', 'block',
   * 'field'), the vocabulary roster that says which CSS properties the
   * site names there (granted(), in its declaration order) and corrects a
   * miss (corrective()); the rosters' value spaces, the same law: the
   * BINDINGS (per property, the site-wide value space: "font-size takes
   * the text scale") and the DEVIATIONS (per address, the value spaces a
   * place narrows below the bindings: a part capping font-size, a block
   * type curating a scale to a few stops), both as data, as the layer's
   * law carries them ({scale, stops, allowed, min, max, units}); plus the
   * one prose generator that renders rosters, bindings and deviations
   * for the model (VocabularyProseInterface): the roster is taught in
   * every posture, and only the sentence saying how a property outside it
   * is judged varies, so that sentence comes from the layer that holds
   * the law, and the brief and the JSON door's style_guide print the
   * value spaces from one derivation in one text. One roster per kind at
   * every placement depth: a declaration's grant never forks on where
   * the element sits.
   *
   * @param array<string, \Drupal\daedalus_blueprint\Materials\VocabularyRosterInterface> $kind_to_rosters
   *   Map of kind => the kind's vocabulary roster.
   * @param \Drupal\daedalus_blueprint\Materials\VocabularyProseInterface $prose
   *   The prose generator for the contributed rosters and value spaces.
   * @param array<string, array> $bindings
   *   Property => its site-wide value space, in the layer's order; [] on
   *   a site that binds nothing site-wide.
   * @param array<string, array<string, array>> $deviations
   *   Address print ("kind:container", "block_type:text::.text-block-link")
   *   => [property => its value space], only the places that narrow below
   *   the bindings; [] when none does.
   */
  public function addStylable(array $kind_to_rosters, VocabularyProseInterface $prose, array $bindings, array $deviations): void {
    foreach ($kind_to_rosters as $kind => $roster) {
      if (!$roster instanceof VocabularyRosterInterface) {
        throw new \InvalidArgumentException(sprintf('The stylable roster for kind "%s" must be a vocabulary roster (VocabularyRosterInterface).', $kind));
      }
    }
    foreach ($bindings as $property => $space) {
      if (!is_string($property) || $property === '' || !is_array($space)) {
        throw new \InvalidArgumentException('Bindings map a CSS property name to its value space (an array).');
      }
    }
    foreach ($deviations as $address => $spaces) {
      if (!is_string($address) || Address::parse($address) === NULL) {
        throw new \InvalidArgumentException(sprintf('Deviations are declared per address ("kind:container", "block_type:text::.text-block-link"); "%s" is not one.', $address));
      }
      if (!is_array($spaces) || $spaces === []) {
        throw new \InvalidArgumentException(sprintf('The deviations of %s map CSS property names to value spaces; an address that deviates nowhere is absent.', $address));
      }
      foreach ($spaces as $property => $space) {
        if (!is_string($property) || $property === '' || !is_array($space)) {
          throw new \InvalidArgumentException(sprintf('The deviations of %s map CSS property names to value spaces (arrays).', $address));
        }
      }
    }
    $this->stylable += $kind_to_rosters;
    $this->bindings += $bindings;
    $this->deviations += $deviations;
    $this->prose = $prose;
  }

  /**
   * The contributed bindings: property => its value space.
   *
   * @return array<string, array>
   *   In contribution order; [] when no layer binds anything.
   */
  public function getBindings(): array {
    return $this->bindings;
  }

  /**
   * The contributed deviations: address print => [property => value space].
   *
   * @return array<string, array<string, array>>
   *   In contribution order; [] when no place narrows below the bindings.
   */
  public function getDeviations(): array {
    return $this->deviations;
  }

  /**
   * The contributed vocabulary rosters, keyed by element kind.
   *
   * @return array<string, \Drupal\daedalus_blueprint\Materials\VocabularyRosterInterface>
   *   kind => roster.
   */
  public function getStylable(): array {
    return $this->stylable;
  }

  /**
   * The prose generator contributed with the rosters, NULL when none was.
   */
  public function getVocabularyProse(): ?VocabularyProseInterface {
    return $this->prose;
  }

}
