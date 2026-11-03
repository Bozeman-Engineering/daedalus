<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Vocabulary;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\StyleGuide;

/**
 * The site's value spaces, derived once for every surface that teaches them.
 *
 * The bindings are the site-wide value space per property (the authored
 * constraint_defaults, StyleGuide::constraintDefaults(): "font-size takes
 * the text scale"); the deviations are the places whose own value space
 * narrows below a binding (a part capping font-size, a block type curating
 * a scale to a few stops), the law VocabularyRoster::valueSpaces() carries
 * and the validator judges that address by. Both are DATA here; the one
 * prose generator (VocabularyProse) renders them, so the JSON door's
 * style_guide (StyleMapFieldHandler and the scope-keyed lanes) and the
 * design system brief (through the dialect catalog, which carries both
 * beside the rosters) print one text from one derivation. The walk from
 * a root to every declared place under it (its children pseudo-part and
 * its parts, at any authored depth, flat) is the roster's answer, asked
 * here and nowhere else.
 */
final class VocabularyValueSpaces {

  public function __construct(
    protected StyleGuide $styleGuide,
    protected VocabularyRosterBuilder $vocabulary,
  ) {}

  /**
   * The site-wide bindings: property => its value space.
   *
   * Every authored constraint_defaults block, keyed by CSS property in
   * authoring order (each shorthand's block followed by the longhands it
   * expands to), as authored ({scale, stops, allowed, min, max, units}).
   * Only what the site declares site-wide: a node's own block is that
   * node's value space and rides the deviations.
   *
   * @param string|null $theme
   *   The theme the guide is read for, or NULL for the default.
   *
   * @return array<string, array>
   *   Property => value space; [] when the site declares no site-wide
   *   blocks.
   */
  public function bindings(?string $theme = NULL): array {
    return $this->styleGuide->constraintDefaults($theme);
  }

  /**
   * The universal roots, in teaching order.
   *
   * What the style map and the dialect address: the universal kinds, then
   * every declared block type, block plugin and layout. A lane with its
   * own root set (the scope-keyed lanes) builds its own list and hands it
   * to rosters().
   *
   * @param string|null $theme
   *   The theme the declarations are read for, or NULL for the default.
   *
   * @return \Drupal\daedalus_blueprint\Materials\Address[]
   *   The root addresses.
   */
  public function roots(?string $theme = NULL): array {
    $roots = [];
    foreach (PageBuilderDeclarations::KINDS as $kind) {
      $roots[] = Address::kind($kind);
    }
    foreach ($this->styleGuide->getBlockTypes($theme) as $bundle) {
      $roots[] = Address::blockType($bundle);
    }
    foreach ($this->styleGuide->getBlockPlugins($theme) as $plugin_id) {
      $roots[] = Address::blockPlugin($plugin_id);
    }
    foreach ($this->styleGuide->getLayouts($theme) as $layout_id) {
      $roots[] = Address::layout($layout_id);
    }
    return $roots;
  }

  /**
   * A root and every declared place under it, the roster deciding.
   *
   * @param \Drupal\daedalus_blueprint\Materials\Address $root
   *   The root address.
   * @param string|null $theme
   *   The theme the declarations are read for, or NULL for the default.
   *
   * @return \Drupal\daedalus_blueprint\Materials\Address[]
   *   The root, its children pseudo-part when declared, then each part
   *   with its own children pseudo-part when declared.
   */
  public function addressesUnder(Address $root, ?string $theme = NULL): array {
    $addresses = [$root];
    $roster = $this->vocabulary->build($root, $theme);
    if ($roster->childrenDeclared()) {
      $addresses[] = Address::of($root->family, $root->id, '> *');
    }
    foreach (array_keys($roster->parts()) as $selector) {
      $part = Address::of($root->family, $root->id, (string) $selector);
      $addresses[] = $part;
      if ($this->vocabulary->build($part, $theme)->childrenDeclared()) {
        $addresses[] = Address::of($root->family, $root->id, $selector . ' > *');
      }
    }
    return $addresses;
  }

  /**
   * The rosters over some roots: every root and every place under it.
   *
   * @param \Drupal\daedalus_blueprint\Materials\Address[] $roots
   *   The roots, in teaching order (roots(), or a lane's own set).
   * @param string|null $theme
   *   The theme the rosters are built for, or NULL for the default.
   *
   * @return array<string, \Drupal\daedalus_stylish\Vocabulary\VocabularyRoster>
   *   Address print => roster: each root in order, followed by its
   *   children pseudo-part and its declared parts (at any authored
   *   depth, flat) with their own children.
   */
  public function rosters(array $roots, ?string $theme = NULL): array {
    $rosters = [];
    foreach ($roots as $root) {
      foreach ($this->addressesUnder($root, $theme) as $address) {
        $rosters[(string) $address] = $this->vocabulary->build($address, $theme);
      }
    }
    return $rosters;
  }

  /**
   * The deviations: only the places that narrow below the bindings.
   *
   * Each roster's value spaces (VocabularyRoster::valueSpaces()) minus
   * every property whose space merely repeats the site's own binding,
   * keyed as the rosters are keyed (the address print from rosters(), or
   * a lane's own labels); a place that deviates nowhere is absent, and
   * the whole map is [] on a site whose nodes constrain nothing beyond
   * the bindings.
   *
   * @param array<string, \Drupal\daedalus_stylish\Vocabulary\VocabularyRoster> $rosters
   *   Key => roster.
   * @param string|null $theme
   *   The theme the rosters were built for, or NULL for the default.
   *
   * @return array<string, array<string, array>>
   *   Key => [property => value space], in roster order.
   */
  public function deviations(array $rosters, ?string $theme = NULL): array {
    $bindings = $this->bindings($theme);
    $deviations = [];
    foreach ($rosters as $key => $roster) {
      $spaces = [];
      foreach ($roster->valueSpaces() as $property => $space) {
        if (array_key_exists($property, $bindings) && $bindings[$property] == $space) {
          continue;
        }
        $spaces[$property] = $space;
      }
      if ($spaces !== []) {
        $deviations[$key] = $spaces;
      }
    }
    return $deviations;
  }

}
