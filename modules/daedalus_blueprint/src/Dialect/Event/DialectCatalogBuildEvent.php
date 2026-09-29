<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\Materials\VocabularyProseInterface;
use Drupal\daedalus_blueprint\Materials\VocabularyRosterInterface;

/**
 * Collects the styling DECLARATIONS half of the site's dialect vocabulary.
 *
 * The catalog builder derives everything the spine itself owns (block
 * types with their field attribute contracts, the container layout id)
 * and reads the site's MATERIALS from the materials roster (tokens,
 * swatches, variants, stencils, animations: MaterialsRosterBuilder over
 * the tagged
 * providers), then dispatches this event so the styling layer (daedalus_stylish,
 * or whatever declares conditions and axes on a site) can contribute the
 * declarations no material record carries: each variant scope's axis
 * attribute order with each axis's description and the properties it
 * owns, the condition vocabulary with its CSS wrappers (the structural
 * facts among them named), the selector rosters per owner (the declared
 * part classes and the addressable base tags a relative selector's
 * compounds may land on under a container, a block type or any other
 * block), the instance namespaces, the vocabulary rosters per element
 * kind with the prose that teaches them, the birth defaults each
 * placement scope stamps and the base tags the active skin dresses.
 * Nothing here names a material by spelling: a
 * material reaches the catalog through the roster or not at all (a birth
 * default names a variant by id, which the roster resolves), so the
 * catalog, the cached prompt, the schema and the validators never
 * disagree about what is published.
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
   * Condition name => CSS wrapper, in canonical order.
   */
  private array $conditions = [];

  /**
   * Condition names declared as structural facts.
   *
   * @var string[]
   */
  private array $facts = [];

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
   * Constructs the build event.
   *
   * @param array $blockTypes
   *   The spine-derived block type contracts (read-only for subscribers).
   * @param string $containerLayoutId
   *   The one container layout's id ('' when the site declares none).
   */
  public function __construct(
    private readonly array $blockTypes,
    private readonly string $containerLayoutId = '',
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
   *   The placement scope ("layout:daedalus_stylish_container", "block_type:text").
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
   * Contributes condition declarations (name => CSS wrapper).
   *
   * Later layers never override a name an earlier layer declared.
   */
  public function addConditions(array $conditions): void {
    $this->conditions += $conditions;
  }

  /**
   * The declared conditions, name => CSS wrapper, in canonical order.
   */
  public function getConditions(): array {
    return $this->conditions;
  }

  /**
   * Contributes the condition names that are structural FACTS.
   *
   * A fact is a pseudo-class condition that holds by the element's place
   * in the markup (":first-child") rather than by interaction (":hover"):
   * the dialect spells both as pseudo-classes on the addressed element
   * but teaches and corrects them apart. Names accumulate across layers;
   * a name no layer declared as a condition is ignored by the catalog.
   *
   * @param string[] $names
   *   Condition names.
   */
  public function addFacts(array $names): void {
    $this->facts = array_values(array_unique(array_merge($this->facts, array_values($names))));
  }

  /**
   * The condition names declared as structural facts.
   *
   * @return string[]
   *   Condition names.
   */
  public function getFacts(): array {
    return $this->facts;
  }

  /**
   * Contributes one owner's selector roster: its parts and its tags.
   *
   * The owner is a dialect tag ("pb-container", "pb-text", "pb-block" for
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
      throw new \InvalidArgumentException(sprintf('Selector rosters are declared per owner, a dialect tag ("pb-container", "pb-text", "pb-block"); "%s" is not one.', $owner));
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
   * Contributes vocabulary rosters, keyed by element kind, and their prose.
   *
   * The styling layer hands over, per element kind ('container', 'block',
   * 'field'), the vocabulary roster that says which CSS properties the
   * site names there (granted(), in its declaration order) and corrects a
   * miss (corrective()), plus the one prose generator that renders those
   * rosters for the model (VocabularyProseInterface): the roster is
   * taught in every posture, and only the sentence saying how a property
   * outside it is judged varies, so that sentence comes from the layer
   * that holds the law. One roster per kind at every placement depth: a
   * declaration's grant never forks on where the element sits.
   *
   * @param array<string, \Drupal\daedalus_blueprint\Materials\VocabularyRosterInterface> $kind_to_rosters
   *   Map of kind => the kind's vocabulary roster.
   * @param \Drupal\daedalus_blueprint\Materials\VocabularyProseInterface $prose
   *   The prose generator for the contributed rosters.
   */
  public function addStylable(array $kind_to_rosters, VocabularyProseInterface $prose): void {
    foreach ($kind_to_rosters as $kind => $roster) {
      if (!$roster instanceof VocabularyRosterInterface) {
        throw new \InvalidArgumentException(sprintf('The stylable roster for kind "%s" must be a vocabulary roster (VocabularyRosterInterface).', $kind));
      }
    }
    $this->stylable += $kind_to_rosters;
    $this->prose = $prose;
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
