<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * The daedalus_blueprint field handler for a skin's package-layer defaults.
 *
 * The blueprint lane's READ of a skin's defaults: serializes the package
 * layer as a structured scope-keyed map (not an opaque JSON string) and
 * projects the defaults grammar onto the field schema (the scope catalog,
 * what each scope may style). It has no write: a skin's defaults are
 * written one scope at a time through the update_default operation
 * (DaedalusStylishOperationHandler), the one door the inspector's skin
 * session and the AI share, which lands in the layer the site may write
 * and validates through the defaults seam
 * (StyleMapValidator::validateDefaults()). A whole-field write here
 * refuses and names that door. The disk lane (SkinImporter) lands shipped
 * defaults through the entity, validated by the same seam.
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
   * The refusal code of a whole-field defaults write.
   */
  public const OP_ONLY = 'SKIN_DEFAULTS_OP_ONLY';

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'daedalus_skin_defaults';
  }

  /**
   * {@inheritdoc}
   *
   * Every write refuses: the field is the package layer, which the disk
   * lane writes, and the site's own defaults go through update_default.
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    return new FieldHandlerResult(NULL, [
      new BlueprintError('fields.' . $definition->getName(), self::OP_ONLY, 'A skin\'s defaults are not written as a whole field; the update_default operation sets them one scope at a time.', [], 'Submit update_default in a batch with "scope" (e.g. "base:h2", "kind:container"), "properties" (null unsets), and optionally "key" ("::" plus a relative selector) and "condition"; on a consuming site it lands in the skin\'s site layer over what the package ships.'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    return $this->defaultsGuidance() . ' READ-ONLY here: a skin\'s defaults are written through the update_default operation, one scope at a time (null unsets), never as a whole field.';
  }

  /**
   * The defaults grammar, the guidance's constant part.
   */
  protected function defaultsGuidance(): string {
    return 'A skin\'s site element defaults: an object keyed by SCOPE, each scope mapping element-relative keys ("" for the scope\'s element, "::" plus a relative selector under it: "::.class-name" a declared part, "::p" its paragraphs, "::> *" its children, "::> pb-text" the text blocks among them) to condition-keyed CSS properties, e.g. {"kind:container": {"": {"default": {"gap": "1.5rem"}}}, "base:a": {"": {"default": {"color": "var(--color-accent)"}}}}. Scopes are "base:{tag}" (bare prose markup; the field schema\'s style_guide.base_tags key lists the closed tag vocabulary), "block_type:{bundle}", "block_plugin:{plugin_id}" (plugin blocks such as menus and site branding, by BASE plugin id — "how menus look" site-wide is one entry here), "layout:{layout_id}", or "kind:{entity|container|field|block}" — kind:region cannot host defaults (regions are not addressable elements). Defaults are LIVE: changing them restyles every existing placement of the scope, and any per-entity style map value overrides them by cascade (unsetting the override reveals the default again). Slot paths and variant references are rejected: a default styles the scope\'s element itself and carries its styles directly. The field schema\'s style_guide key says what is valid (its scopes catalog, bindings and deviations lines, and guidance paragraph).';
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
  protected function stylableGrammar(?string $theme): string {
    return 'What each scope\'s element may style, by property name: an entry per scope value, its declared sub-elements ("{scope}::.class-name") and its direct children ("{scope}::> *"). A block type, block plugin, or layout scope absent here at one of those places styles exactly what its kind scope does there (kind:block; kind:container for layouts); a sub-element or children key absent here is not declared there. ' . $this->selectorGrammar('{scope}', $theme);
  }

}
