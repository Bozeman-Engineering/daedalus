<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Vocabulary;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\ConditionTypePluginManager;
use Drupal\daedalus_stylish\PropertyGroupPluginManager;
use Drupal\daedalus_stylish\StyleGuide;

/**
 * Builds the vocabulary roster for an address and theme.
 *
 * The one derivation behind every "what may this address style" answer,
 * the twin of MaterialsRosterBuilder: the layered declarations (the style
 * guide, with the active design system's package already layered in by
 * daedalus_ui) resolved for one address into a VocabularyRoster.
 * Memoized per request, keyed on the theme, the address and the
 * enforcement mode; never on the workspace, because declarations and
 * config are not workspace-staged (the roster reads its materials from
 * the materials roster at call time, and that builder keeps the
 * workspace key). The declarations themselves are persistently cached by
 * daedalus_ui, so a per-request memo is all the roster needs;
 * persistence belongs to the products (the client payload, the cached
 * prompt) on the roster's cache metadata.
 *
 * Address resolution is the vocabulary rule of this module over the
 * grammar daedalus_blueprint owns (Address): "block_type:{bundle}" is the
 * per-block-type declaration with the universal block kind layered
 * underneath (an undeclared bundle degrades to the kind), "block_plugin:
 * {id}" the per-plugin declaration the same way, "layout:{id}" the
 * per-layout declaration over the universal container kind, "kind:{k}"
 * the universal kind declaration, "base:{tag}" the site declaration's
 * base section for a tag of the closed BASE_TAGS vocabulary (a legal
 * undeclared tag resolves to an empty declaration; a tag outside the
 * vocabulary, like a kind outside KINDS, is not a place and is refused
 * loudly). A relative selector resolves by the roster WALK: its compounds
 * resolve left to right from the owner's declaration, each landing on
 * the declaration it names, and the roster is the LAST landing's. A
 * class part resolves by the one part rule (an exact part in the
 * elements tree at any depth, then an owner pattern part, then the site
 * instance namespaces) against the declaration the previous compound
 * landed on (the owner when first, a type's declaration after a type); a
 * tag by the tag tier (the base section's declaration of that tag); a
 * type by that type's own root declaration (d-{bundle} the bundle's,
 * d-container the container kind's, d-block the block kind's); `*`
 * (only after `>`) by the current landing's `children` section. A
 * compound the declarations do not carry ends the walk: the roster is
 * built with declared() FALSE, no grant, and undeclared() naming the
 * compound, so the validator can shape its corrective. A terminal
 * pseudo-element (`&::before`, `.quote::after`, `> d-text::before`)
 * lands last, on the generated box of the chain's landing (the owner's
 * root for the anchor): every element has both boxes, so existence is
 * never a declaration question, and what may be styled there is
 * DECLARED-OR-DERIVED. Declared, the host node's `pseudo: {before: {…},
 * after: {…}}` section answers, its property_groups the grant like any
 * node's; undeclared, the box inherits its host's property_groups and the
 * roster is built with declared() FALSE and undeclared() naming the
 * pseudo (kind VocabularyRoster::KIND_PSEUDO), so a strict site refuses
 * it and an open one grants the derivation. Either way the pseudo-only
 * groups (PropertyGroupPluginManager::getPseudoGroups(): `content`, the
 * existence switch) are granted at the box and nowhere else.
 */
class VocabularyRosterBuilder {

  /**
   * Built rosters keyed by theme, then enforcement mode, then address.
   *
   * @var array<string, array<string, array<string, \Drupal\daedalus_stylish\Vocabulary\VocabularyRoster>>>
   */
  protected array $memo = [];

  public function __construct(
    protected StyleGuide $styleGuide,
    protected PropertyGroupPluginManager $propertyGroups,
    protected ConditionTypePluginManager $conditionTypes,
    protected PageBuilderDeclarations $declarations,
    protected ThemeManagerInterface $themeManager,
    protected MaterialsRosterBuilder $materials,
    protected WideSelectors $wideSelectors,
    protected PseudoVocabularies $pseudoVocabularies,
  ) {}

  /**
   * Builds (and memoizes) the roster for an address.
   *
   * @param \Drupal\daedalus_blueprint\Materials\Address $address
   *   The address.
   * @param string|null $theme
   *   The theme whose declarations answer; defaults to the site default
   *   theme (the theme styled pages render in), else the active theme.
   *
   * @throws \InvalidArgumentException
   *   When the address's root is outside the site's vocabulary (a kind no
   *   declaration may key by, a base tag outside BASE_TAGS).
   */
  public function build(Address $address, ?string $theme = NULL): VocabularyRoster {
    $theme = $this->resolveTheme($theme);
    $enforcement = $this->styleGuide->enforcement();
    return $this->memo[$theme][$enforcement][(string) $address] ??= $this->resolve($address, $theme, $enforcement);
  }

  /**
   * The theme a roster answers for.
   *
   * The given one, else the site default theme (the theme styled pages render
   * in), else the active theme.
   */
  protected function resolveTheme(?string $theme): string {
    return $theme ?? $this->declarations->siteTheme() ?? $this->themeManager->getActiveTheme()->getName();
  }

  /**
   * Drops every memoized roster (the declarations or the law changed).
   */
  public function reset(): void {
    $this->memo = [];
  }

  /**
   * Resolves one address against the theme's declarations.
   */
  protected function resolve(Address $address, string $theme, string $enforcement): VocabularyRoster {
    $root = $this->resolveRoot($address->root(), $theme);
    ['node' => $node, 'undeclared' => $undeclared, 'owner' => $owner] = $this->walk($root, $address->selector, $theme, $enforcement === StyleGuide::ENFORCEMENT_STRICT);
    $declared = $undeclared === NULL;
    $node ??= [];
    ['grants' => $grants, 'valueSpaces' => $spaces] = $this->vocabulary($node, $theme);

    $axes = [];
    $labels = StyleGuide::axisLabels($root);
    $root_only = StyleGuide::rootOnlyAxes($root);
    $descriptions = StyleGuide::axisDescriptions($root);
    $owns = StyleGuide::axisOwns($root);
    foreach (StyleGuide::axes($root) as $axis) {
      $axes[$axis] = [
        'label' => $labels[$axis] ?? NULL,
        'root_only' => in_array($axis, $root_only, TRUE),
        'description' => $descriptions[$axis] ?? NULL,
        'owns' => $owns[$axis] ?? [],
      ];
    }

    $parts = [];
    foreach (PageBuilderDeclarations::flattenElements($node) as $selector => $part) {
      $parts[(string) $selector] = (string) ($part['label'] ?? $selector);
    }

    $metadata = (new CacheableMetadata())->addCacheTags([
      PageBuilderDeclarations::CACHE_TAG,
      'config:' . ActiveDesignSystem::CONFIG_NAME,
      ...$this->styleGuide->enforcementCacheTags(),
    ]);

    return new VocabularyRoster(
      address: $address,
      theme: $theme,
      grants: $grants,
      valueSpaces: $spaces,
      axes: $axes,
      conditions: $this->conditionTypes->getConditions(),
      parts: $parts,
      childrenDeclared: is_array($node['children'] ?? NULL),
      declared: $declared,
      undeclared: $undeclared,
      landing: $owner,
      enforcement: $enforcement,
      materials: fn () => $this->materials->build(),
      cacheMetadata: $metadata,
      shorthands: $this->propertyGroups->getShorthands(),
    );
  }

  /**
   * The vocabulary half of a roster for one declaration node.
   *
   * The one grant-and-value-space derivation (resolve() reads it for
   * every addressed node): the node's property_groups with their
   * allowlists resolved to property names (group id => names, in
   * declaration order; a group that grants nothing is dropped; a
   * shorthand family granted wherever every longhand it sets is, the
   * manager's expandDeclaredProperties()), and per granted property the
   * effective constraint block, the node's own first, then the site's
   * authored constraint_defaults, both expanded over the shorthand
   * families by the guide (StyleGuide::expandConstraintFamilies(): a
   * node's padding block is its sides' block too, and a node's
   * padding-left block still wins over the site's padding). Public for the
   * one place an address cannot reach: a PATTERN node (an owner's
   * pattern part or a site instance namespace, "{prefix}*") is not an
   * address but the declaration every concrete class in its namespace
   * resolves to, so the client payload serves it through this same
   * derivation and its answers equal the roster of any address it covers.
   *
   * @param array $node
   *   A declaration node (an effective root, a part, a children section,
   *   a pattern part, an instance namespace).
   * @param string|null $theme
   *   The theme whose constraint defaults answer; defaults as build()'s.
   *
   * @return array{grants: array<string, string[]>, valueSpaces: array<string, array>}
   *   The grants and the value spaces, as VocabularyRoster carries them.
   */
  public function vocabulary(array $node, ?string $theme = NULL): array {
    $grants = [];
    $spaces = [];
    $defaults = $this->styleGuide->getConstraintDefaults($this->resolveTheme($theme));
    $groups = $node['property_groups'] ?? [];
    foreach (is_array($groups) ? $groups : [] as $group_id => $declaration) {
      $group_id = (string) $group_id;
      $granted = $this->propertyGroups->expandDeclaredProperties([$group_id => $declaration]);
      if ($granted === []) {
        continue;
      }
      $grants[$group_id] = $granted;
      $constraints = is_array($declaration) && is_array($declaration['constraints'] ?? NULL) ? $declaration['constraints'] : [];
      $constraints = $this->styleGuide->expandConstraintFamilies($constraints);
      foreach ($granted as $property) {
        $block = $constraints[$property] ?? $defaults[$property] ?? NULL;
        if (is_array($block) && $block !== []) {
          $spaces[$property] = $block;
        }
      }
    }
    return ['grants' => $grants, 'valueSpaces' => $spaces];
  }

  /**
   * The effective declaration a root address names.
   *
   * @throws \InvalidArgumentException
   *   When the root is outside the site's vocabulary.
   */
  protected function resolveRoot(Address $root, string $theme): array {
    return match ($root->family) {
      Address::FAMILY_KIND => $this->styleGuide->getKind($root->id, $theme),
      Address::FAMILY_BLOCK_TYPE => $this->styleGuide->getBlockType($root->id, $theme),
      Address::FAMILY_BLOCK_PLUGIN => $this->styleGuide->getBlockPlugin($root->id, $theme),
      Address::FAMILY_LAYOUT => $this->styleGuide->getLayout($root->id, $theme),
      Address::FAMILY_BASE => in_array($root->id, StyleGuide::BASE_TAGS, TRUE)
        ? $this->styleGuide->getBase($root->id, $theme)
        : throw new \InvalidArgumentException(sprintf('"%s" is not a styling address: "%s" is outside the base tag vocabulary (%s).', $root, $root->id, implode(', ', StyleGuide::BASE_TAGS))),
    };
  }

  /**
   * The roster walk: resolves a selector's compounds left to right.
   *
   * Each compound's subject lands on a declaration node:
   * - a class part (".name") by the one part rule against the declaration
   *   the previous compound landed on (the owner when first, a type's
   *   declaration after a type): the exact part anywhere in that
   *   declaration's elements tree, then its pattern parts, then the site
   *   instance namespaces;
   * - a tag ("p") by the tag tier: the site's base declaration of that
   *   tag, the one declaration of a prose tag's styleability, whatever
   *   the owner; a tag the base section does not declare is undeclared
   *   (admission by mode is the validator's: strict refuses, open and
   *   guarded admit it grant-less), and one the wide selector tier does
   *   not know ("navv") is reported with `known` FALSE and the tier's
   *   nearest names, a typo in every mode;
   * - a type ("d-text") by that type's own root declaration (the bundle
   *   declaration over the block kind; d-container the container kind;
   *   d-block the block kind), which also becomes the owner a following
   *   class part resolves against. A bundle no declaration covers
   *   (StyleGuide::getBlockTypes()) is an undeclared type, whatever the
   *   kind fallback would answer: a type compound names ONE spelling of
   *   the page language, and an unknown one selects nothing live;
   * - `*` (only after `>`, `+` or `~`) by the current landing's
   *   `children` section (declared like a part, beside the element's own
   *   property_groups).
   * A compound reached by a sibling combinator (`+`, `~`) resolves where
   * its predecessor did (its siblings are under the same parent): `> p +
   * *` lands on the owner's children, `> d-text .link ~ .link` on the
   * same part. The qualified anchor (`&:first-child`, `&:not(:last-child)
   * > *`) is the owner standing first: it lands on the root itself, and
   * the chain behind it walks on from there. A compound's qualifiers are
   * vocabulary to judge and never move the landing: a `:has()` argument
   * is walked from the qualified compound's landing (a type's declaration
   * owning the argument's parts; a sibling lead in the argument resolves
   * in the compound's own context), a `:not()` compound is judged in the
   * qualified compound's context (an implied subject, `:not(:has(a))`,
   * judges only its qualifiers from the qualified compound's landing),
   * and a position pseudo-class is structure with no vocabulary to judge.
   * The other qualifier kinds are admitted by enforcement mode, the one
   * rule for every vocabulary (judgeQualifiers()): a class qualifier
   * (`li.active`, `&.open`) is a declared part where one of the
   * compound's owner matches, else a markup class; an attribute
   * qualifier is a predicate on the markup; a pseudo-class qualifier is
   * a published condition where one is spelled by it (`li:hover > ul`,
   * in every mode), else a state the browser knows. On a strict site
   * (the walk's `$closed`) the undeclared ones end the walk reported by
   * their kind (VocabularyRoster::KIND_CLASS, KIND_ATTRIBUTE,
   * KIND_PSEUDO_CLASS), so the validator can refuse them with the
   * corrective; on an open or guarded site they are admitted and the
   * walk goes on, the landing's grant being the qualified compound's
   * own. A pseudo-class the wide selector tier does not know (`a:hovre`)
   * ends the walk in every mode, reported with `known` FALSE and the
   * tier's nearest names, as an unknown tag is. The first compound the
   * declarations do not carry, anywhere, ends the walk; the landing is
   * the last top-level compound's.
   *
   * A terminal pseudo-element lands after the chain, on the box of the
   * chain's landing (the root itself for the anchor, `&::before`): the
   * host's `pseudo` section names it (declared), else the box takes its
   * vocabulary from the spec table (PseudoVocabularies: `::marker`,
   * `::placeholder`, `::selection`, `::first-letter`, `::first-line`) or
   * derives its host's property_groups, and is reported undeclared as a
   * pseudo "compound" (kind VocabularyRoster::KIND_PSEUDO, its name the
   * pseudo's, positioned after the chain) while still carrying the node,
   * so an open site grants it and a strict one refuses it. The
   * pseudo-only groups (content, the existence switch) are granted on the
   * two generated boxes alone (pseudoNode()). A pseudo-element the wide
   * selector tier does not know (`::markr`) lands nowhere in every mode,
   * reported with `known` FALSE and the tier's nearest names, as an
   * unknown tag is. An undeclared compound in the chain ends the walk
   * before the box is reached: the host is corrected first.
   *
   * @param array $root
   *   The owner's effective root declaration.
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector|null $selector
   *   The selector, or NULL for the root itself.
   * @param string $theme
   *   The theme.
   * @param bool $closed
   *   Whether the site is strict (the closed world: an undeclared
   *   qualifier ends the walk; open and guarded admit it).
   *
   * @return array{node: ?array, undeclared: ?array, owner: ?array}
   *   The landing node (NULL when undeclared, except a derived
   *   pseudo-element's, which carries the derivation), the undeclared
   *   compound (its kind, name and position in its chain, NULL when every
   *   compound landed) and the declaration a class part would resolve
   *   against where the walk ended (the owner or the last type's
   *   declaration: what a corrective enumerates declared parts of).
   */
  protected function walk(array $root, ?RelativeSelector $selector, string $theme, bool $closed): array {
    $compounds = $selector?->compounds ?? [];
    $walked = $this->walkChain($compounds, $root, $root, $root, $theme, $closed);
    $pseudo = $selector?->pseudo;
    if ($pseudo === NULL || $walked['node'] === NULL) {
      return $walked;
    }
    $undeclared = [
      'combinator' => NULL,
      'kind' => VocabularyRoster::KIND_PSEUDO,
      'name' => $pseudo,
      'qualifiers' => [],
      'position' => count($compounds),
    ];
    if (!$this->wideSelectors->knowsPseudoElement($pseudo)) {
      return [
        'node' => NULL,
        'undeclared' => $undeclared + [
          'known' => FALSE,
          'similar' => NearMisses::rank($pseudo, $this->wideSelectors->pseudoElements()),
        ],
        'owner' => $walked['owner'],
      ];
    }
    return [
      'node' => $this->pseudoNode($walked['node'], $pseudo),
      'undeclared' => $this->declaredPseudo($walked['node'], $pseudo) !== NULL ? NULL : $undeclared,
      'owner' => $walked['owner'],
    ];
  }

  /**
   * The declaration node a pseudo-element lands on.
   *
   * The host's `pseudo: {before: {property_groups: …}}` section when it
   * declares the box (declaredPseudo()); else the spec table's node for a
   * pseudo-element whose vocabulary is its own (specNode(): `::marker`,
   * `::placeholder`, `::selection`, `::first-letter`, `::first-line`);
   * else the derivation: a node granting exactly the host's
   * property_groups (allowlists and constraints included), the vocabulary
   * of `::before`, `::after` and every pseudo-element the table does not
   * carry (`::backdrop`). The pseudo-only groups (the manager's
   * getPseudoGroups(): content, the existence switch) are granted last on
   * the two generated boxes alone, unless the declaration already lists
   * one, whose position and constraints then stand: a generated box must
   * be switchable on, so no declaration has to say so, and no other
   * pseudo-element has an existence switch. Public for the one place an
   * address cannot reach, like vocabulary(): a PATTERN node's declared
   * box (an owner pattern part or an instance namespace carrying a pseudo
   * section) is served by the client payload through this derivation, so
   * its answers equal the roster of any box it covers. The client twin is
   * style-guide.js pseudoNode().
   *
   * @param array $host
   *   The declaration the chain landed on.
   * @param string $pseudo
   *   The pseudo-element's name (`before`, `marker`, …).
   *
   * @return array
   *   The box's declaration node.
   */
  public function pseudoNode(array $host, string $pseudo): array {
    $node = $this->declaredPseudo($host, $pseudo) ?? $this->specNode($pseudo) ?? ['property_groups' => $host['property_groups'] ?? []];
    $groups = is_array($node['property_groups'] ?? NULL) ? $node['property_groups'] : [];
    if (in_array($pseudo, RelativeSelector::GENERATED_BOXES, TRUE)) {
      foreach ($this->propertyGroups->getPseudoGroups() as $group_id) {
        $groups[$group_id] ??= [];
      }
    }
    $node['property_groups'] = $groups;
    return $node;
  }

  /**
   * The spec table's declaration node for a pseudo-element, or NULL.
   *
   * The table's allowlist (PseudoVocabularies::properties()) grouped by
   * the CURATED group that owns each property, in the allowlist's order
   * of first appearance, as `{group: {properties: […]}}`: the same shape
   * a declared pseudo section writes, so vocabulary() resolves it like
   * any node's. Curated groups only, because the roster's grants are the
   * panel's offers and the client serves the curated groups alone (the
   * wide tier is server-side vocabulary); a wide-tier property the spec
   * allows (`font`, `white-space`, `vertical-align`) is still admitted at
   * the write by mode, as everywhere, and the validator's every-mode
   * allowlist refusal reads the whole table, not this node.
   *
   * @return array{property_groups: array<string, array{properties: string[]}>}|null
   *   The node, or NULL for a pseudo-element the table does not carry.
   */
  protected function specNode(string $pseudo): ?array {
    $allowed = $this->pseudoVocabularies->properties($pseudo);
    if ($allowed === NULL) {
      return NULL;
    }
    $curated = $this->propertyGroups->getCuratedGroups();
    $groups = [];
    foreach ($allowed as $property) {
      $group_id = $this->propertyGroups->getGroupIdForProperty($property);
      if ($group_id !== NULL && isset($curated[$group_id])) {
        $groups[$group_id]['properties'][] = $property;
      }
    }
    return ['property_groups' => $groups];
  }

  /**
   * The host's declared section for a pseudo-element, or NULL (derived).
   */
  protected function declaredPseudo(array $host, string $pseudo): ?array {
    $section = $host['pseudo'] ?? NULL;
    return is_array($section) && is_array($section[$pseudo] ?? NULL) ? $section[$pseudo] : NULL;
  }

  /**
   * Walks one chain of compounds (the selector's, or a `:has()` argument).
   *
   * @param array[] $compounds
   *   The chain.
   * @param array $node
   *   The declaration the first compound resolves in.
   * @param array $owner
   *   The declaration a class part resolves against.
   * @param array $previous
   *   The declaration a leading sibling compound resolves in (the context
   *   of the compound the chain qualifies; the root at the top level,
   *   where the grammar admits no sibling lead).
   * @param string $theme
   *   The theme.
   * @param bool $closed
   *   Whether the site is strict (walk()).
   *
   * @return array{node: ?array, undeclared: ?array, owner: ?array}
   *   As walk() answers.
   */
  protected function walkChain(array $compounds, array $node, array $owner, array $previous, string $theme, bool $closed): array {
    foreach ($compounds as $position => $compound) {
      if (in_array($compound['combinator'], [RelativeSelector::NEXT, RelativeSelector::SUBSEQUENT], TRUE)) {
        $node = $previous;
      }
      $landing = $this->land($compound, $node, $owner, $theme);
      if ($landing === NULL) {
        return ['node' => NULL, 'undeclared' => $this->undeclaredCompound($compound, $position), 'owner' => $owner];
      }
      $undeclared = $this->judgeQualifiers($compound['qualifiers'], $landing, $compound['kind'] === RelativeSelector::TYPE ? $landing : $owner, $node, $owner, $theme, $closed, $position);
      if ($undeclared !== NULL) {
        return ['node' => NULL, 'undeclared' => $undeclared['undeclared'], 'owner' => $undeclared['owner']];
      }
      $previous = $node;
      $node = $landing;
      if ($compound['kind'] === RelativeSelector::TYPE) {
        $owner = $landing;
      }
    }
    return ['node' => $node, 'undeclared' => NULL, 'owner' => $owner];
  }

  /**
   * The declaration a compound's subject lands on, or NULL when undeclared.
   */
  protected function land(array $compound, array $node, array $owner, string $theme): ?array {
    return match ($compound['kind']) {
      RelativeSelector::CLASS_PART => PageBuilderDeclarations::findElement($owner, $compound['name'])
        ?? PageBuilderDeclarations::findPatternElement($owner, $compound['name'])
        ?? $this->styleGuide->instanceElement($compound['name'], $theme),
      RelativeSelector::TAG => $this->styleGuide->baseTagElement($compound['name'], $theme),
      RelativeSelector::TYPE => $this->resolveType($compound['name'], $theme),
      RelativeSelector::UNIVERSAL => is_array($node['children'] ?? NULL) ? $node['children'] : NULL,
      // The qualified anchor is the owner itself: it lands where the
      // chain started (the owner's root; the anchor stands only first).
      RelativeSelector::ANCHOR_KIND => $node,
    };
  }

  /**
   * Judges a compound's qualifiers against its landing and its context.
   *
   * A `:has()` argument is walked from the landing, its class parts
   * resolving against the argument owner (a type's landing, else the
   * context's owner) and a sibling lead in the compound's context. A
   * `:not()` compound is judged where the qualified compound stands: its
   * subject lands in the same context, and its own qualifiers are judged
   * from that landing; an implied subject judges its qualifiers from the
   * qualified compound's landing and argument owner. An `:is()`
   * qualifier's every member is judged as the `:not()` compound is
   * (judgeMember()): the words of every alternative must be in the
   * vocabulary, whichever one matches. A position
   * pseudo-class (`:first-child`, `:nth-child(2n+1)`) is structure the
   * markup decides, no vocabulary to judge: it is skipped. The other
   * kinds are vocabulary admitted by mode: a class qualifier is a
   * declared part of the argument owner where one matches, else a markup
   * class (judgeClass()); an attribute qualifier a markup predicate; a
   * pseudo-class a published condition where one is spelled by it, else
   * a state the browser knows (judgePseudoClass()). Strict ends the walk
   * on the undeclared ones, open and guarded admit them; a pseudo-class
   * outside the wide selector tier ends the walk in every mode.
   *
   * @param array[] $qualifiers
   *   The qualifiers.
   * @param array $landing
   *   The declaration the qualified subject landed on.
   * @param array $argument_owner
   *   The declaration a class part in a `:has()` argument, or a class
   *   qualifier, resolves against.
   * @param array $node
   *   The declaration the qualified compound resolved in (its context).
   * @param array $owner
   *   The declaration a class part resolved against in that context.
   * @param string $theme
   *   The theme.
   * @param bool $closed
   *   Whether the site is strict (walk()).
   * @param int $position
   *   The qualified compound's position in its chain (what a refused
   *   qualifier is reported at).
   *
   * @return array{undeclared: array, owner: array}|null
   *   The first undeclared compound in the arguments (its position in its
   *   own chain) or refused qualifier (at the compound's position), and
   *   the owner it resolved against; NULL when every argument compound
   *   landed and every qualifier is admitted.
   */
  protected function judgeQualifiers(array $qualifiers, array $landing, array $argument_owner, array $node, array $owner, string $theme, bool $closed, int $position): ?array {
    foreach ($qualifiers as $qualifier) {
      $name = $qualifier['name'];
      if ($name === RelativeSelector::HAS) {
        $walked = $this->walkChain($qualifier['argument'], $landing, $argument_owner, $node, $theme, $closed);
        $undeclared = $walked['undeclared'] === NULL ? NULL : [
          'undeclared' => $walked['undeclared'],
          'owner' => $walked['owner'],
        ];
      }
      elseif ($name === RelativeSelector::NOT) {
        $undeclared = $this->judgeMember($qualifier['argument'], $landing, $argument_owner, $node, $owner, $theme, $closed, $position);
      }
      elseif ($name === RelativeSelector::IS) {
        $undeclared = NULL;
        foreach ($qualifier['argument'] as $member) {
          $undeclared = $this->judgeMember($member, $landing, $argument_owner, $node, $owner, $theme, $closed, $position);
          if ($undeclared !== NULL) {
            break;
          }
        }
      }
      elseif ($name === RelativeSelector::CLASS_QUALIFIER) {
        $undeclared = $this->judgeClass($qualifier['argument'], $argument_owner, $theme, $closed, $position);
      }
      elseif ($name === RelativeSelector::ATTRIBUTE) {
        $undeclared = $closed ? $this->refused(VocabularyRoster::KIND_ATTRIBUTE, $qualifier['argument']['attribute'], $position, $owner) : NULL;
      }
      elseif (in_array($name, RelativeSelector::POSITIONS, TRUE) || in_array($name, RelativeSelector::NTH_POSITIONS, TRUE)) {
        continue;
      }
      else {
        $undeclared = $this->judgePseudoClass($name, $owner, $closed, $position);
      }
      if ($undeclared !== NULL) {
        return $undeclared;
      }
    }
    return NULL;
  }

  /**
   * Judges a predicate compound (a `:not()` argument or an `:is()` member).
   *
   * An implied subject judges its qualifiers from the qualified
   * compound's landing and argument owner; a subject lands in the
   * qualified compound's own context (the alternative subject at the
   * same position, never the landing's part) and judges its qualifiers
   * from there, owning them when it is a type.
   *
   * @return array{undeclared: array, owner: array}|null
   *   The refusal, or NULL when the member landed and is admitted.
   */
  protected function judgeMember(array $member, array $landing, array $argument_owner, array $node, array $owner, string $theme, bool $closed, int $position): ?array {
    if ($member['kind'] === NULL) {
      return $this->judgeQualifiers($member['qualifiers'], $landing, $argument_owner, $node, $owner, $theme, $closed, $position);
    }
    $member_landing = $this->land($member, $node, $owner, $theme);
    if ($member_landing === NULL) {
      return ['undeclared' => $this->undeclaredCompound($member, 0), 'owner' => $owner];
    }
    return $this->judgeQualifiers($member['qualifiers'], $member_landing, $member['kind'] === RelativeSelector::TYPE ? $member_landing : $owner, $node, $owner, $theme, $closed, $position);
  }

  /**
   * Judges a class qualifier: a declared part where one matches, else by mode.
   *
   * `li.active`, `&.open`, `> d-text.featured`: the class is a declared
   * part of the argument owner (the one part rule, land()) or a markup
   * class the page's JavaScript or a worn variant sets. A declared part
   * is admitted in every mode; a markup class on a strict site is
   * refused as VocabularyRoster::KIND_CLASS, named with its dot like a
   * part, and admitted on an open or guarded one.
   *
   * @return array{undeclared: array, owner: array}|null
   *   The refusal, or NULL when admitted.
   */
  protected function judgeClass(string $class, array $argument_owner, string $theme, bool $closed, int $position): ?array {
    $name = '.' . $class;
    if (!$closed || $this->land(['kind' => RelativeSelector::CLASS_PART, 'name' => $name], $argument_owner, $argument_owner, $theme) !== NULL) {
      return NULL;
    }
    // The corrective enumerates the parts the class was resolved against.
    return $this->refused(VocabularyRoster::KIND_CLASS, $name, $position, $argument_owner);
  }

  /**
   * Judges a pseudo-class qualifier that is no position: a state.
   *
   * A published condition spelled by the pseudo-class
   * (ConditionTypePluginManager::conditionForPseudoClass(): `:hover` on
   * any compound) is admitted in every mode, the pose dual depending on
   * it. Any other pseudo-class the wide selector tier knows (`a:visited`,
   * `input:checked` where no `checked` is published) is a state the
   * browser knows: refused on a strict site as VocabularyRoster::
   * KIND_PSEUDO_CLASS, admitted on an open or guarded one. A name the
   * tier does not know (`a:hovre`) is a typo in every mode: the same
   * record with `known` FALSE and the tier's nearest names in `similar`.
   *
   * @return array{undeclared: array, owner: array}|null
   *   The refusal, or NULL when admitted.
   */
  protected function judgePseudoClass(string $name, array $owner, bool $closed, int $position): ?array {
    if ($this->conditionTypes->conditionForPseudoClass($name) !== NULL) {
      return NULL;
    }
    if (!$this->wideSelectors->knowsPseudoClass($name)) {
      $refused = $this->refused(VocabularyRoster::KIND_PSEUDO_CLASS, $name, $position, $owner);
      $refused['undeclared'] += [
        'known' => FALSE,
        'similar' => NearMisses::rank($name, $this->wideSelectors->pseudoClasses()),
      ];
      return $refused;
    }
    return $closed ? $this->refused(VocabularyRoster::KIND_PSEUDO_CLASS, $name, $position, $owner) : NULL;
  }

  /**
   * The undeclared record of a compound whose subject landed nowhere.
   *
   * The compound itself plus its position in its chain; a tag the wide
   * selector tier does not know ("navv") carries `known` FALSE and the
   * tier's nearest names in `similar`, so the validator refuses it in
   * every mode where a known undeclared tag ("nav", "em") is admitted
   * by mode.
   */
  protected function undeclaredCompound(array $compound, int $position): array {
    $undeclared = $compound + ['position' => $position];
    if ($compound['kind'] === RelativeSelector::TAG && !$this->wideSelectors->knowsTag($compound['name'])) {
      $undeclared += [
        'known' => FALSE,
        'similar' => NearMisses::rank($compound['name'], $this->wideSelectors->tags()),
      ];
    }
    return $undeclared;
  }

  /**
   * The refusal record of a qualifier, in the undeclared compound's shape.
   */
  protected function refused(string $kind, string $name, int $position, array $owner): array {
    return [
      'undeclared' => [
        'combinator' => NULL,
        'kind' => $kind,
        'name' => $name,
        'qualifiers' => [],
        'position' => $position,
      ],
      'owner' => $owner,
    ];
  }

  /**
   * The root declaration a type compound lands on, or NULL when unknown.
   */
  protected function resolveType(string $type, string $theme): ?array {
    $address = RelativeSelector::typeAddress($type);
    if ($address->family === Address::FAMILY_BLOCK_TYPE && !in_array($address->id, $this->styleGuide->getBlockTypes($theme), TRUE)) {
      return NULL;
    }
    return $this->resolveRoot($address, $theme);
  }

}
