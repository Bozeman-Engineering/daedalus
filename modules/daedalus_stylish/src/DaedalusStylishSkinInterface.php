<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\RevisionLogInterface;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemMemberInterface;
use Drupal\daedalus_stylish\Entity\MachineNameInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * A skin: a site's look as daedalus_stylish data.
 *
 * The site-scope host of the styling tiers that have no per-element home:
 * design token VALUES (the tokens field) and SITE ELEMENT DEFAULTS (the
 * defaults field — live per-scope styles like "sections constrain to
 * 80rem", overridden by any per-entity map by cascade). Token NAMES and
 * values are data here; the scales they validate against are code — the
 * site declaration's token_scales section (StyleGuide::getTokenScales()).
 * Defaults are data too; the scopes they validate against are the style
 * guide's (plus the closed base:{tag} bare-markup family,
 * StyleGuide::BASE_TAGS).
 *
 * Skins are content on the variant pattern, not config: revisionable and
 * publishable, which is exactly what makes them workspace-aware — editing
 * the active skin inside a workspace stages the whole site's restyle and
 * publishes with it, and on plain sites a bad edit is one revision revert
 * away. A site may hold multiple skins with ONE active: the active design
 * system's default_skin, by machine name (derived by SkinManager, never
 * stored on its own); switching design systems is a live admin action.
 *
 * Token vocabulary is shared, deliberately: tokens deliver as
 * --{prefix}-{name} custom properties derived from the SCALE, never from
 * the skin, so skins swap by supplying different VALUES for the same names
 * and every var() reference keeps working across the switch.
 */
interface DaedalusStylishSkinInterface extends ShippedSourceInterface, ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface, RevisionLogInterface, EntityOwnerInterface, MachineNameInterface, DesignSystemMemberInterface {

  /**
   * Returns the stable reference name.
   *
   * A design system's default_skin and skin YAML files reference skins by
   * this name: content ids are environment-local and uuids are opaque to
   * YAML authors and AI alike.
   */
  public function getMachineName(): string;

  /**
   * Returns the skin's token values.
   *
   * @return array
   *   {scale: {name: value}} — scale keys from the site's declared token
   *   scales, token names minted by this skin, each value either a string
   *   (unconditional) or a condition-keyed map whose 'default' entry
   *   always applies (width conditions compile as @container,
   *   scheme/motion as @media). Empty array when the skin mints no tokens.
   */
  public function getTokens(): array;

  /**
   * Sets the skin's token values.
   *
   * @param array $tokens
   *   Scale-keyed token values; see getTokens().
   */
  public function setTokens(array $tokens): static;

  /**
   * Returns the skin's site element defaults.
   *
   * @return array
   *   {scope: {relative key: entry}} — scope keys from the defaults scope
   *   vocabulary ("base:{tag}", "block_type:{bundle}", "layout:{layout_id}",
   *   "kind:{entity|container|field|block}"), relative keys '' (the
   *   scope's element) or '::.class-name' (a declared part), each entry a
   *   condition-keyed property map. LIVE semantics: these restyle existing
   *   placements through the compiled skin stylesheet, and any per-entity
   *   style map value beats them by cascade. Empty array when the skin
   *   carries no defaults.
   */
  public function getDefaults(): array;

  /**
   * Sets the skin's site element defaults.
   *
   * @param array $defaults
   *   Scope-keyed element-relative styles; see getDefaults().
   */
  public function setDefaults(array $defaults): static;

  /**
   * Returns the module that ships this skin, or NULL.
   *
   * NULL means the skin was created in the UI or by AI: it has no disk
   * source, so no revert-to-defaults is ever offered for it.
   */
  public function getProvider(): ?string;

  /**
   * Returns the hash of the shipped payload at the last import/sync, or NULL.
   */
  public function getSourceHash(): ?string;

  /**
   * Records the disk source this skin was imported or synced from.
   *
   * @param string $provider
   *   The module whose MODULE.daedalus_stylish.skins.yml ships the skin.
   * @param array $shipped_data
   *   The shipped payload — {tokens: ..., defaults: ...} — exactly as
   *   shipped; its hash becomes the stored source_hash both comparisons
   *   run against.
   */
  public function setShippedSource(string $provider, array $shipped_data): static;

  /**
   * Whether this skin has a disk source (was shipped by a module).
   */
  public function isShipped(): bool;

  /**
   * Whether the skin's data has diverged from the shipped defaults.
   */
  public function isModified(): bool;

  /**
   * Whether the shipped defaults have changed since the last import/sync.
   *
   * @param array $shipped_data
   *   The payload ({tokens, defaults}) as currently shipped on disk.
   */
  public function hasNewDefaults(array $shipped_data): bool;

}
