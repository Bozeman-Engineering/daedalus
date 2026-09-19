<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\Materials\Address;

/**
 * The scope-keyed schema fragment the variant and defaults lanes share.
 *
 * Variant styles and skin defaults are both scope-keyed: their rosters key
 * by the scope field's own values ("kind:container", "block_type:text")
 * rather than the style map's kinds.container spelling, and their fragment
 * closes with a catalog of the valid scope values instead of the style
 * map's current_values and anatomy pointers. This trait owns that shape
 * once, for a StyleMapFieldHandler subclass: the fragment assembly
 * (bindings, deviations, stylable entries, guidance, conditions, then the
 * lane's catalog entries), the scope roots (SCOPE_KINDS rather than every
 * kind, because regions are not addressable elements and host neither
 * defaults nor variants), and the root label (the address print, the write
 * grammar of the scope). A lane supplies its guidance sentence and its
 * catalog entries; the defaults lane also widens the roots with base tags.
 */
trait ScopeKeyedSchemaTrait {

  /**
   * {@inheritdoc}
   *
   * The lane's fragment mirrors the style map's materials-first shape (the
   * materials roster lands by the same alter hook) at the scope-key
   * grammar: the stylable entries and the deviations key by the scope
   * values, and the fragment closes with the lane's catalog entries, the
   * write grammar of its scope key, in every posture.
   */
  public function getSchemaAdditions(FieldDefinitionInterface $definition): array {
    $theme = $this->declarations->siteTheme();
    $rosters = $this->rosters($theme);
    $style_guide = [];
    if ($bindings = $this->bindingLines($theme)) {
      $style_guide['bindings'] = $bindings;
    }
    if ($deviations = $this->deviationLines($rosters, $theme)) {
      $style_guide['deviations'] = $deviations;
    }
    $style_guide['stylable'] = $this->stylableEntries($rosters, $theme);
    $style_guide['guidance'] = $this->scopeGuidance() . ' ' . $this->prose()->polaritySentence();
    $style_guide['conditions'] = $this->conditionsSchema();
    return ['style_guide' => array_merge($style_guide, $this->catalogEntries($rosters))];
  }

  /**
   * The lane's guidance sentences, ahead of the shared polarity sentence.
   *
   * Says what the lane validates against and what its scopes catalog is.
   */
  abstract protected function scopeGuidance(): string;

  /**
   * The entries that close the fragment, after conditions.
   *
   * The scopes catalog (the write grammar of the lane's scope key) and
   * anything that grammar leans on, in the order they are served.
   *
   * @param array<string, \Drupal\daedalus_stylish\Vocabulary\VocabularyRoster> $rosters
   *   Label => roster (rosters()).
   *
   * @return array<string, mixed>
   *   Schema key => value.
   */
  abstract protected function catalogEntries(array $rosters): array;

  /**
   * The roots every scope-keyed lane covers, in teaching order.
   *
   * StyleMapValidator::SCOPE_KINDS already excludes region: regions are not
   * addressable elements, so kind:region is neither a variant scope nor a
   * defaults scope.
   *
   * @return \Drupal\daedalus_blueprint\Materials\Address[]
   *   The scope kinds, then every declared block type, block plugin and
   *   layout.
   */
  protected function scopeRoots(?string $theme): array {
    $roots = [];
    foreach (StyleMapValidator::SCOPE_KINDS as $kind) {
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
   * {@inheritdoc}
   */
  protected function roots(?string $theme): array {
    return $this->scopeRoots($theme);
  }

  /**
   * {@inheritdoc}
   *
   * The scope key's own grammar: a root prints as its address.
   */
  protected function rootLabel(Address $root): string {
    return (string) $root;
  }

}
