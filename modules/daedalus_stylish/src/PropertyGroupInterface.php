<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A group of related CSS properties: definitions and validation.
 *
 * Values everywhere in Daedalus Stylish are semantic CSS ("padding: 2rem"), so a
 * group's job is to say which properties it owns, whether a given value is
 * structurally valid CSS for that property, and whether it satisfies the
 * style guide's constraints for the element being styled. The AI fragment
 * serves only the group IDS a declaration references (the stylable
 * section's set names): a model knows CSS, so the definitions' property
 * lists, types, enums, and examples stay server-side, enforced by
 * validate() and taught lazily by its corrective errors.
 *
 * Constraints arrive from the style guide YAML (per element, per property):
 * - allowed: exhaustive list of permitted values, matched verbatim. This is
 *   the palette mechanism; entries may be var() references.
 * - min / max: bounds for dimension or number values. Bounds and values are
 *   compared when convertible (px, pt, rem, em share a 16px/1rem basis) or
 *   when units match; a value that cannot be compared to its bound is a
 *   violation, not a bypass.
 * - units: list of permitted units for dimension values.
 * - image_style: for media-capable properties, the image style that
 *   url('media:<uuid>') references on this element resolve through at
 *   render (see MediaValueResolver; falls back to the daedalus_stylish.settings
 *   media_image_style site default, then the original file). Render-time
 *   delivery, not validation: validate() ignores it.
 */
interface PropertyGroupInterface {

  /**
   * The section heading shown in the inspector panel.
   */
  public function label(): TranslatableMarkup;

  /**
   * The CSS properties this group owns.
   *
   * @return array
   *   Property definitions keyed by CSS property name:
   *   - label: (TranslatableMarkup|string) widget label. The generated
   *     wide-tier groups carry plain strings (server-side vocabulary,
   *     generated data); every consumer casts to string.
   *   - type: (string) value type — one of 'dimension', 'number', 'keyword',
   *     'ident', 'animation_name', 'color', 'family', 'text', 'any'. 'ident' is
   *     a CSS custom-ident (will-change targets, transition properties):
   *     identifier grammar, with CSS-wide keywords, "default", and "--"
   *     prefixes reserved. 'animation_name' is the ident grammar for a
   *     keyframes name (animation-name), its own type because the name is a
   *     REFERENCE: a motion-{name} ident names a site animation
   *     (StyleGuide::animationName()) that the validator resolves against the
   *     published roster and the client offers from it, while a plain ident is
   *     a theme's own keyframes (a literal, refused on strict sites). 'text' is
   *     a permissive multi-part value (shadows, gradients, grid templates):
   *     value hygiene plus a script-scheme scan only, no structural grammar —
   *     the client's csstree and "allowed" constraints are the checks. 'any' is
   *     the wide tier's tail (shorthands, url- and function-valued grammars):
   *     validated exactly like 'text' server-side, with the definition's mdn
   *     'syntax' string carried into the AI schema as the grammar hint.
   *   - keywords: (string[]) keyword values accepted in addition to the type's
   *     grammar (for 'keyword', the complete list).
   *   - example: (string) for 'text', an example value quoted in the AI
   *     schema description.
   *   - repeat: (int) for 'dimension', the shorthand arity: up to this many
   *     space-separated values (e.g. 4 for padding).
   *   - list: (bool) the value may be a comma or space separated list of the
   *     type's values (transition-duration, will-change); each component
   *     validates and range-checks on its own, and function notation stays
   *     one component. Generalizes 'repeat' (which stays the capped-arity
   *     shorthand form). Defaults to FALSE.
   *   - negative: (bool) for 'dimension'/'number', whether negative values are
   *     valid CSS (margins yes, padding no). Defaults to FALSE.
   *   - number: (bool) for 'dimension', the dimension|number union: a bare
   *     unitless number is also valid (line-height, tab-size). Defaults to
   *     FALSE.
   *   - min, max: (string|int|float) for 'dimension'/'number', the property's
   *     own range (opacity: max 1): the grammar's bound, checked on every
   *     site whatever the enforcement mode, the way `negative` bounds the
   *     sign. A guide constraint block for the property layers over these
   *     per key. Both default to unbounded.
   *   - syntax: (string) for 'any', the property's mdn value definition
   *     syntax, quoted in the AI schema description (the server never
   *     matches it; the client's csstree lexer lints the same grammar from
   *     its own embedded tables).
   *   - area: (string) for generated wide-tier definitions, the CSS spec
   *     area the property belongs to (the derivative grouping).
   *   - media: (bool) whether the value's CSS <image>/<url> slots may
   *     reference media entities via url('media:<uuid>') tokens (see
   *     MediaToken). Tokens are validated (uuid shape, the media exists,
   *     its source is an image or vector) and substituted out before the
   *     type's own validation; storage keeps the token — resolution to a
   *     file URL is a pre-print transform. Defaults to FALSE.
   */
  public function getProperties(): array;

  /**
   * The shorthand families among this group's properties.
   *
   * Grants are stated over longhands: a `properties` allowlist names the
   * longhands an element may style, and a shorthand is granted wherever
   * every longhand it sets is granted (the vocabulary roster's family
   * rule, PropertyGroupPluginManager::expandDeclaredProperties()); an
   * allowlist naming a shorthand fails the guide build. A group that
   * declares no family has no shorthand vocabulary: each of its names is
   * granted on its own. Only a shorthand whose longhands the group owns
   * outright is a family here (padding over its four sides); a logical
   * spelling whose longhands are outside the vocabulary (padding-inline,
   * with no padding-inline-start) stays a plain name.
   *
   * @return array<string, string[]>
   *   Shorthand => the longhands it sets, every name owned by this group.
   */
  public function getShorthands(): array;

  /**
   * Validates one property value against CSS grammar and guide constraints.
   *
   * @param string $property
   *   A CSS property name this group owns.
   * @param string $value
   *   The semantic CSS value.
   * @param array $constraints
   *   This property's constraints from the effective style guide declaration
   *   (the {allowed, min, max, units} map), or [] when unconstrained.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors with the path set to the property name; the caller re-roots them
   *   onto the full style-map path with withPath(). Empty when valid.
   */
  public function validate(string $property, string $value, array $constraints = []): array;

}
