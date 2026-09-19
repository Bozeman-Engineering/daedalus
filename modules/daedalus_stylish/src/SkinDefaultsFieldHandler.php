<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * The daedalus_blueprint field handler for a skin's site defaults.
 *
 * The blueprint lane of the one skin-defaults road: serializes the defaults
 * as a structured scope-keyed map (not an opaque JSON string) and validates
 * writes through the defaults seam (StyleMapValidator::validateDefaults(),
 * riding the scope-only seam) — the same gate the skin admin form and the
 * disk lane converge on, so an AI authoring site defaults through the
 * generic create/update tools is validated identically to every other
 * surface.
 *
 * A sibling of SkinTokensFieldHandler, deliberately: the key grammar
 * differs ({scope: element-relative styles} against declared scopes, vs
 * {scale: {name: value}} against declared token scales), and the schema
 * projection differs too (the defaults scope catalog below vs the token
 * scales table).
 */
class SkinDefaultsFieldHandler extends StyleMapFieldHandler {

  use ScopeKeyedSchemaTrait;

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'daedalus_skin_defaults';
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    $field_name = $definition->getName();
    if (is_string($value)) {
      $value = json_decode($value, TRUE);
    }
    if (!is_array($value) || (array_is_list($value) && $value !== [])) {
      return new FieldHandlerResult(NULL, [
        new BlueprintError("fields.$field_name", 'INVALID_SKIN_DEFAULTS', 'The skin defaults are not an object of scopes.', [], 'Provide an object mapping scopes to element-relative styles, e.g. {"kind:container": {"": {"default": {"max-width": "80rem"}}}, "base:h2": {"": {"default": {"font-size": "var(--text-2xl)"}}}}.'),
      ]);
    }

    // Self-containment: the defaults' var() references validate against the
    // OWNING skin's tokens (the sibling field in this submission when
    // present, the entity's stored tokens otherwise), never the active
    // skin's: these defaults only ever deliver while their skin is active.
    $skin_tokens = $options['fields']['tokens'] ?? NULL;
    if (is_string($skin_tokens)) {
      $skin_tokens = json_decode($skin_tokens, TRUE);
    }
    if (!is_array($skin_tokens)) {
      $entity = $options['entity'] ?? NULL;
      $skin_tokens = $entity instanceof DaedalusStylishSkinInterface ? $entity->getTokens() : [];
    }

    $errors = $this->validator->validateDefaults($value, ['skin_tokens' => $skin_tokens]);
    if ($errors) {
      return new FieldHandlerResult(NULL, array_map(
        static fn (BlueprintError $error) => $error->withPath("fields.$field_name." . $error->getPath()),
        $errors,
      ));
    }

    // Compound condition keys were accepted in any part order; storage
    // keeps the canonical spelling (axis-declaration order) per scope, the
    // same normalization the style map's write paths apply.
    $value = array_map(
      fn (array $styles): array => $this->conditionTypes->normalizeMapConditions($styles),
      $value,
    );

    // Canonical storage matches SkinDefaultsItem::setStyleMap(): JSON text,
    // and an empty map is no value at all.
    return new FieldHandlerResult($value === [] ? NULL : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    return 'A skin\'s site element defaults: an object keyed by SCOPE, each scope mapping element-relative keys — "" for the scope\'s element, "::.class-name" for a declared part — to condition-keyed CSS properties, e.g. {"kind:container": {"": {"default": {"gap": "1.5rem"}}}, "base:a": {"": {"default": {"color": "var(--color-accent)"}}}}. Scopes are "base:{tag}" (bare prose markup; the field schema\'s style_guide.base_tags key lists the closed tag vocabulary), "block_type:{bundle}", "block_plugin:{plugin_id}" (plugin blocks such as menus and site branding, by BASE plugin id — "how menus look" site-wide is one entry here), "layout:{layout_id}", or "kind:{entity|container|field|block}" — kind:region cannot host defaults (regions are not addressable elements). Defaults are LIVE: changing them restyles every existing placement of the scope, and any per-entity style map value overrides them by cascade (unsetting the override reveals the default again). Slot paths and variant references are rejected: a default styles the scope\'s element itself and carries its styles directly. The field schema\'s style_guide key says what is valid (its scopes catalog, bindings and deviations lines, and guidance paragraph).';
  }

  /**
   * {@inheritdoc}
   */
  protected function scopeGuidance(): string {
    return 'A skin\'s defaults validate against the same guide as element styles: the materials, bindings, deviations and stylable entries here are the one vocabulary. scopes is the catalog of valid defaults scopes (a base:{tag} scope for any base_tags tag is also valid; a scope absent from the stylable entries, including a base_tags tag without one, declares nothing stylable).';
  }

  /**
   * {@inheritdoc}
   *
   * The defaults lane's catalog is the bare key list of THIS field's scope
   * values (kinds, block types, block plugins, layouts, and base:{tag} for
   * the bare prose markup a skin styles too), beside the closed base tag
   * vocabulary those base scopes draw on.
   */
  protected function catalogEntries(array $rosters): array {
    return [
      'base_tags' => StyleGuide::BASE_TAGS,
      'scopes' => array_values(array_map(strval(...), array_filter(array_keys($rosters), static fn (string $label): bool => Address::parse($label)?->isRoot() === TRUE))),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Base tags join the scope roots because a skin styles bare prose markup
   * too.
   */
  protected function roots(?string $theme): array {
    $roots = $this->scopeRoots($theme);
    foreach ($this->styleGuide->getBaseElements($theme) as $tag => $declaration) {
      if (is_array($declaration)) {
        $roots[] = Address::base((string) $tag);
      }
    }
    return $roots;
  }

  /**
   * {@inheritdoc}
   */
  protected function stylableGrammar(): string {
    return 'What each scope\'s element may style, by property name: an entry per scope value, its declared sub-elements ("{scope}::.class-name") and its direct children ("{scope}::>"). A block type, block plugin, or layout scope absent here at one of those places styles exactly what its kind scope does there (kind:block; kind:container for layouts); a sub-element or children key absent here is not declared there.';
  }

}
