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
 * type by that type's own root declaration (pb-{bundle} the bundle's,
 * pb-container the container kind's, pb-block the block kind's); `*`
 * (only after `>`) by the current landing's `children` section. A
 * compound the declarations do not carry ends the walk: the roster is
 * built with declared() FALSE, no grant, and undeclared() naming the
 * compound, so the validator can shape its corrective. A terminal
 * pseudo-element (`&::before`, `.quote::after`, `> pb-text::before`)
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
    ['node' => $node, 'undeclared' => $undeclared, 'owner' => $owner] = $this->walk($root, $address->selector, $theme);
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
   *   the owner;
   * - a type ("pb-text") by that type's own root declaration (the bundle
   *   declaration over the block kind; pb-container the container kind;
   *   pb-block the block kind), which also becomes the owner a following
   *   class part resolves against. A bundle no declaration covers
   *   (StyleGuide::getBlockTypes()) is an undeclared type, whatever the
   *   kind fallback would answer: a type compound names ONE spelling of
   *   the page language, and an unknown one selects nothing live;
   * - `*` (only after `>`, `+` or `~`) by the current landing's
   *   `children` section (declared like a part, beside the element's own
   *   property_groups).
   * A compound reached by a sibling combinator (`+`, `~`) resolves where
   * its predecessor did (its siblings are under the same parent): `> p +
   * *` lands on the owner's children, `> pb-text .link ~ .link` on the
   * same part. A compound's qualifiers are vocabulary to judge and never
   * move the landing: a `:has()` argument is walked from the qualified
   * compound's landing (a type's declaration owning the argument's
   * parts; a sibling lead in the argument resolves in the compound's own
   * context), and a `:not()` compound is judged in the qualified
   * compound's context (an implied subject, `:not(:has(a))`, judges only
   * its qualifiers from the qualified compound's landing). The first
   * compound the declarations do not carry, anywhere, ends the walk; the
   * landing is the last top-level compound's.
   *
   * A terminal pseudo-element lands after the chain, on the generated box
   * of the chain's landing (the root itself for the anchor, `&::before`):
   * the host's `pseudo` section names it (declared), else the box derives
   * its host's property_groups and is reported undeclared as a pseudo
   * "compound" (kind VocabularyRoster::KIND_PSEUDO, its name the
   * pseudo's, positioned after the chain) while still carrying the
   * derived node, so an open site grants it and a strict one refuses it.
   * The pseudo-only groups are granted on the box either way
   * (pseudoNode()). An undeclared compound in the chain ends the walk
   * before the box is reached: the host is corrected first.
   *
   * @param array $root
   *   The owner's effective root declaration.
   * @param \Drupal\daedalus_blueprint\Materials\RelativeSelector|null $selector
   *   The selector, or NULL for the root itself.
   * @param string $theme
   *   The theme.
   *
   * @return array{node: ?array, undeclared: ?array, owner: ?array}
   *   The landing node (NULL when undeclared, except a derived
   *   pseudo-element's, which carries the derivation), the undeclared
   *   compound (its kind, name and position in its chain, NULL when every
   *   compound landed) and the declaration a class part would resolve
   *   against where the walk ended (the owner or the last type's
   *   declaration: what a corrective enumerates declared parts of).
   */
  protected function walk(array $root, ?RelativeSelector $selector, string $theme): array {
    $compounds = $selector?->compounds ?? [];
    $walked = $this->walkChain($compounds, $root, $root, $root, $theme);
    $pseudo = $selector?->pseudo;
    if ($pseudo === NULL || $walked['node'] === NULL) {
      return $walked;
    }
    return [
      'node' => $this->pseudoNode($walked['node'], $pseudo),
      'undeclared' => $this->declaredPseudo($walked['node'], $pseudo) !== NULL ? NULL : [
        'combinator' => NULL,
        'kind' => VocabularyRoster::KIND_PSEUDO,
        'name' => $pseudo,
        'qualifiers' => [],
        'position' => count($compounds),
      ],
      'owner' => $walked['owner'],
    ];
  }

  /**
   * The declaration node a pseudo-element lands on.
   *
   * The host's `pseudo: {before: {property_groups: …}}` section when it
   * declares the box (declaredPseudo()); else the derivation: a node
   * granting exactly the host's property_groups (allowlists and
   * constraints included). The pseudo-only groups (the manager's
   * getPseudoGroups()) are granted last on both, unless the declaration
   * already lists one, whose position and constraints then stand: the box
   * must be switchable on, so no declaration has to say so. Public for
   * the one place an address cannot reach, like vocabulary(): a PATTERN
   * node's declared box (an owner pattern part or an instance namespace
   * carrying a pseudo section) is served by the client payload through
   * this derivation, so its answers equal the roster of any box it
   * covers.
   *
   * @param array $host
   *   The declaration the chain landed on.
   * @param string $pseudo
   *   The pseudo-element's name (RelativeSelector::PSEUDOS).
   *
   * @return array
   *   The box's declaration node.
   */
  public function pseudoNode(array $host, string $pseudo): array {
    $node = $this->declaredPseudo($host, $pseudo) ?? ['property_groups' => $host['property_groups'] ?? []];
    $groups = is_array($node['property_groups'] ?? NULL) ? $node['property_groups'] : [];
    foreach ($this->propertyGroups->getPseudoGroups() as $group_id) {
      $groups[$group_id] ??= [];
    }
    $node['property_groups'] = $groups;
    return $node;
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
   *
   * @return array{node: ?array, undeclared: ?array, owner: ?array}
   *   As walk() answers.
   */
  protected function walkChain(array $compounds, array $node, array $owner, array $previous, string $theme): array {
    foreach ($compounds as $position => $compound) {
      if (in_array($compound['combinator'], [RelativeSelector::NEXT, RelativeSelector::SUBSEQUENT], TRUE)) {
        $node = $previous;
      }
      $landing = $this->land($compound, $node, $owner, $theme);
      if ($landing === NULL) {
        return ['node' => NULL, 'undeclared' => $compound + ['position' => $position], 'owner' => $owner];
      }
      $undeclared = $this->judgeQualifiers($compound['qualifiers'], $landing, $compound['kind'] === RelativeSelector::TYPE ? $landing : $owner, $node, $owner, $theme);
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
   * qualified compound's landing and argument owner.
   *
   * @param array[] $qualifiers
   *   The qualifiers.
   * @param array $landing
   *   The declaration the qualified subject landed on.
   * @param array $argument_owner
   *   The declaration a class part in a `:has()` argument resolves
   *   against.
   * @param array $node
   *   The declaration the qualified compound resolved in (its context).
   * @param array $owner
   *   The declaration a class part resolved against in that context.
   * @param string $theme
   *   The theme.
   *
   * @return array{undeclared: array, owner: array}|null
   *   The first undeclared compound in the arguments (its position in its
   *   own chain) and the owner it resolved against, or NULL when every
   *   argument compound landed.
   */
  protected function judgeQualifiers(array $qualifiers, array $landing, array $argument_owner, array $node, array $owner, string $theme): ?array {
    foreach ($qualifiers as $qualifier) {
      if ($qualifier['name'] === RelativeSelector::HAS) {
        $walked = $this->walkChain($qualifier['argument'], $landing, $argument_owner, $node, $theme);
        $undeclared = $walked['undeclared'] === NULL ? NULL : ['undeclared' => $walked['undeclared'], 'owner' => $walked['owner']];
      }
      elseif ($qualifier['argument']['kind'] === NULL) {
        $undeclared = $this->judgeQualifiers($qualifier['argument']['qualifiers'], $landing, $argument_owner, $node, $owner, $theme);
      }
      else {
        $negated = $qualifier['argument'];
        $negated_landing = $this->land($negated, $node, $owner, $theme);
        if ($negated_landing === NULL) {
          return ['undeclared' => $negated + ['position' => 0], 'owner' => $owner];
        }
        $undeclared = $this->judgeQualifiers($negated['qualifiers'], $negated_landing, $negated['kind'] === RelativeSelector::TYPE ? $negated_landing : $owner, $node, $owner, $theme);
      }
      if ($undeclared !== NULL) {
        return $undeclared;
      }
    }
    return NULL;
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
