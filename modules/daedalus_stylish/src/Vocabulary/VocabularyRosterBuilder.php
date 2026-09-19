<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Vocabulary;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
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
 * loudly). A part suffix resolves by the one part rule (an exact part in
 * the elements tree at any depth, then an owner pattern part, then the
 * site instance namespaces), a bare tag suffix by the tag tier (the base
 * section's declaration of that tag), and the children suffix by the
 * element's or part's `children` section; a place the declaration does
 * not carry builds a roster with declared() FALSE and no grant.
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
    $node = $root;
    $declared = TRUE;
    if ($address->part !== NULL) {
      $node = $this->resolvePart($root, $address->part, $theme);
      $declared = $node !== NULL;
    }
    if ($declared && $address->children) {
      $children = $node['children'] ?? NULL;
      $node = is_array($children) ? $children : NULL;
      $declared = $node !== NULL;
    }
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
   * The declaration a part suffix names under a root, or NULL.
   *
   * A class part ("::.name") resolves by the one part rule: the exact
   * part anywhere in the elements tree, then an owner pattern part, then
   * the site instance namespaces. A bare tag ("::p") is the tag tier: the
   * site's base declaration of that tag, the one declaration of a prose
   * tag's styleability.
   */
  protected function resolvePart(array $root, string $part, string $theme): ?array {
    if (!str_starts_with($part, '.')) {
      return $this->styleGuide->baseTagElement($part, $theme);
    }
    return PageBuilderDeclarations::findElement($root, $part)
      ?? PageBuilderDeclarations::findPatternElement($root, $part)
      ?? $this->styleGuide->instanceElement($part, $theme);
  }

}
