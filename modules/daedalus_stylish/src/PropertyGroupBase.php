<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Component\Uuid\Uuid;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Data-driven base class for property group plugins.
 *
 * A concrete group normally only implements getProperties(); validation and
 * the AI catalog's property-name lists derive from the property
 * definitions. Validation is
 * deliberately pragmatic about CSS grammar (the client's csstree lexer and
 * the browser are the precise grammar checks) and strict about the style
 * guide's constraints, which are the enforcement product and must never
 * trust the client.
 *
 * Value semantics baked in here:
 * - An "allowed" constraint is exhaustive: it defines the complete value
 *   space, matched verbatim, including var() palette entries and any
 *   keywords — nothing outside the list passes.
 * - Otherwise var()/calc() values pass structurally and skip range checks
 *   (semantic values pass through untouched), and CSS-wide keywords
 *   (inherit, initial, unset, revert) pass.
 * - A range-constrained value that cannot be compared to its bound (150% vs
 *   a 2rem max) is a violation, not a bypass — anything else would let
 *   values tunnel through strict-mode guides by switching units.
 * - Media tokens (url('media:<uuid>'), see MediaToken) are validated first
 *   on media-flagged properties — uuid shape, the media exists, its source
 *   is an image or vector — then substituted out so the remainder validates
 *   exactly as a token-free value would. Properties without the flag reject
 *   them.
 */
abstract class PropertyGroupBase extends PluginBase implements PropertyGroupInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * Units convertible to a shared px basis (1rem = 1em = 16px, 1pt = 4/3px).
   *
   * Em is treated as rem for comparison purposes: guide bounds are authored
   * against the root font size.
   */
  protected const PX_BASIS = ['px' => 1.0, 'pt' => 4 / 3, 'rem' => 16.0, 'em' => 16.0];

  /**
   * Units accepted in dimension values.
   *
   * Length and viewport units plus the time, angle, and flex units the
   * wide property tier's classifier maps onto the dimension type
   * (transition-duration, rotate, grid tracks). Cross-family bounds are
   * incomparable and fail honestly via STYLE_VALUE_NOT_COMPARABLE.
   */
  protected const UNITS = [
    'px',
    'rem',
    'em',
    '%',
    'vw',
    'vh',
    'vmin',
    'vmax',
    'ch',
    'ex',
    'pt',
    'svh',
    'svw',
    'dvh',
    'dvw',
    'lvh',
    'lvw',
    's',
    'ms',
    'deg',
    'grad',
    'rad',
    'turn',
    'fr',
  ];

  /**
   * Values that pass any property's grammar (CSS-wide keywords).
   */
  protected const CSS_WIDE_KEYWORDS = ['inherit', 'initial', 'unset', 'revert', 'revert-layer'];

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityRepositoryInterface $entityRepository,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity.repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function label(): TranslatableMarkup {
    return $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   *
   * No families: every name the group owns is granted on its own.
   */
  public function getShorthands(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function validate(string $property, string $value, array $constraints = []): array {
    $definitions = $this->getProperties();
    if (!isset($definitions[$property])) {
      return [
        new BlueprintError($property, 'INVALID_STYLE_PROPERTY', sprintf('"%s" is not a property of the %s group.', $property, $this->getPluginId()), ['valid_properties' => array_keys($definitions)], 'Use one of the listed properties.'),
      ];
    }
    $value = trim($value);
    if ($value === '' || preg_match('/[;{}<>]|[\x00-\x1F]/', $value)) {
      return [
        new BlueprintError($property, 'INVALID_STYLE_VALUE', sprintf('"%s" is not a valid value for %s.', $value, $property), [], 'Provide a single CSS declaration value with no braces or semicolons.'),
      ];
    }
    $definition = $definitions[$property];

    // Media tokens are spine-level grammar: their shape and the media they
    // reference are validated before any guide constraint, so a broken
    // reference can never tunnel through as passable text.
    $has_media = MediaToken::mentions($value);
    if ($has_media) {
      if (empty($definition['media'])) {
        return [
          new BlueprintError($property, 'INVALID_STYLE_VALUE', sprintf('Media references are not valid for %s.', $property), [], 'Only media-capable properties accept url(\'media:<uuid>\') values.'),
        ];
      }
      $errors = $this->validateMediaTokens($property, $value);
      if ($errors !== []) {
        return $errors;
      }
    }

    // An allowed list is the complete value space — matched verbatim, nothing
    // else passes, no further checks needed.
    if (isset($constraints['allowed'])) {
      if (in_array($value, $constraints['allowed'], TRUE)) {
        return [];
      }
      return [
        new BlueprintError($property, 'STYLE_VALUE_NOT_ALLOWED', sprintf('"%s" is not an allowed value for %s.', $value, $property), ['allowed' => $constraints['allowed']], sprintf('The style guide allows only: %s.', implode(', ', $constraints['allowed']))),
      ];
    }

    // Pass-throughs that no PHP-side grammar or range check can evaluate.
    if (in_array(strtolower($value), self::CSS_WIDE_KEYWORDS, TRUE) || str_contains($value, 'var(') || str_starts_with($value, 'calc(')) {
      return [];
    }

    // Tokens are already validated; substitute them out ("none" is sound in
    // an <image> slot) so the remainder validates exactly as today.
    if ($has_media) {
      $value = MediaToken::substitute($value, static fn (): string => 'none');
    }

    // A list definition accepts comma or space separated repetition of the
    // base type; each component validates (and range-checks) on its own.
    // Function arguments never split — "cubic-bezier(0.4, 0, 0.2, 1)" is
    // one component.
    if (!empty($definition['list'])) {
      $components = $this->splitList($value);
      if ($components === NULL || $components === []) {
        return [
          new BlueprintError($property, 'INVALID_STYLE_VALUE', sprintf('"%s" is not a valid value for %s.', $value, $property), [], sprintf('Provide a comma or space separated list of %s values.', $definition['type'])),
        ];
      }
      $errors = [];
      foreach ($components as $component) {
        $errors = array_merge($errors, $this->validateScalar($property, $component, $definition, $constraints));
      }
      return $errors;
    }
    return $this->validateScalar($property, $value, $definition, $constraints);
  }

  /**
   * Validates a single value against a bare shape, outside any property.
   *
   * The token-value seam: skin token values validate through the same
   * scalar paths as property values (StyleGuide::TOKEN_SHAPES names the
   * property machinery's shapes directly), so the two grammars can never
   * drift. Runs the same value hygiene ban and pass-throughs as validate()
   * — CSS-wide keywords, var() (tokens may reference tokens), calc() — then
   * dispatches to validateScalar() with a synthesized definition. Negative
   * values are allowed: tokens are vocabulary (tracking tokens are
   * negative), not range-constrained properties. Media references are
   * rejected: a token is a value, and the media resolution machinery rides
   * property definitions the token lane does not have.
   *
   * @param string $path
   *   The error path (doubles as the "property" name in messages), e.g.
   *   "space.4" for the token "4" on the "space" scale.
   * @param string $value
   *   The value to validate.
   * @param string $shape
   *   One of StyleGuide::TOKEN_SHAPES: dimension, number, color, keyword,
   *   or text.
   * @param array $keywords
   *   The closed vocabulary for the keyword shape; ignored otherwise.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at the given path; empty when the value fits the shape.
   */
  public function validateShape(string $path, string $value, string $shape, array $keywords = []): array {
    $value = trim($value);
    if ($value === '' || preg_match('/[;{}<>]|[\x00-\x1F]/', $value)) {
      return [
        new BlueprintError($path, 'INVALID_STYLE_VALUE', sprintf('"%s" is not a valid value for %s.', $value, $path), [], 'Provide a single CSS declaration value with no braces or semicolons.'),
      ];
    }
    if (MediaToken::mentions($value)) {
      return [
        new BlueprintError($path, 'INVALID_STYLE_VALUE', sprintf('Media references are not valid for %s.', $path), [], 'Token values are plain CSS; only media-capable style properties accept url(\'media:<uuid>\') values.'),
      ];
    }
    if (in_array(strtolower($value), self::CSS_WIDE_KEYWORDS, TRUE) || str_contains($value, 'var(') || str_starts_with($value, 'calc(')) {
      return [];
    }
    return $this->validateScalar($path, $value, [
      'label' => $path,
      'type' => $shape,
      'keywords' => $keywords,
      'negative' => TRUE,
    ], []);
  }

  /**
   * Validates one component against the definition's type.
   *
   * A definition may carry its own range (opacity's 0..1): the property's
   * grammar bound, in force on every site whatever the enforcement mode,
   * the way `negative` already bounds the sign. A guide constraint block
   * for the property layers over it per key (a site narrows the range).
   */
  protected function validateScalar(string $property, string $value, array $definition, array $constraints): array {
    $constraints += array_intersect_key($definition, ['min' => TRUE, 'max' => TRUE]);
    return match ($definition['type']) {
      'dimension' => $this->validateDimension($property, $value, $definition, $constraints),
      'number' => $this->validateNumber($property, $value, $definition, $constraints),
      'keyword' => $this->validateKeyword($property, $value, $definition),
      'ident' => $this->validateIdent($property, $value, $definition),
      'animation_name' => $this->validateAnimationName($property, $value, $definition),
      'color' => $this->validateColor($property, $value),
      // 'any' is the wide tier's honest tail: name membership plus value
      // hygiene (the ;{}<> ban already ran; validateText adds the
      // script-scheme scan). The real grammar is client UX via csstree,
      // never the line of defense — an ungrammatical value is inert.
      'any', 'family', 'text' => $this->validateText($property, $value),
    };
  }

  /**
   * Splits a list value into components at top-level commas and whitespace.
   *
   * Parenthesized groups stay intact (function notation keeps its inner
   * commas and spaces). Returns NULL for a malformed list: an empty comma
   * segment (leading, trailing, or doubled comma) or unbalanced parentheses.
   *
   * @return string[]|null
   *   The components, or NULL when the list itself is malformed.
   */
  protected function splitList(string $value): ?array {
    $components = [];
    $current = '';
    $depth = 0;
    $pending_comma = FALSE;
    $length = strlen($value);
    for ($i = 0; $i < $length; $i++) {
      $char = $value[$i];
      if ($char === '(') {
        $depth++;
      }
      elseif ($char === ')' && --$depth < 0) {
        return NULL;
      }
      if ($depth === 0 && ($char === ',' || ctype_space($char))) {
        if ($current !== '') {
          $components[] = $current;
          $current = '';
          $pending_comma = FALSE;
        }
        if ($char === ',') {
          if ($pending_comma || $components === []) {
            return NULL;
          }
          $pending_comma = TRUE;
        }
        continue;
      }
      $current .= $char;
    }
    if ($depth !== 0) {
      return NULL;
    }
    if ($current !== '') {
      $components[] = $current;
      $pending_comma = FALSE;
    }
    return $pending_comma ? NULL : $components;
  }

  /**
   * Validates every media token in a value.
   *
   * Three gates per token — the payload is a uuid, the media exists, its
   * source field type qualifies (MediaValueResolver::SOURCE_FIELD_TYPES:
   * image or vector) — plus a whole-value remnant scan: media-scheme text
   * the token grammar cannot parse (bare "media:" outside url(), unclosed
   * or mismatched-quote url()) is an error, never a pass-through, because
   * an unparsed token would reach the printers as a literal.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors with the path set to the property name. Empty when every
   *   reference resolves to image or vector media.
   */
  protected function validateMediaTokens(string $property, string $value): array {
    $errors = [];
    foreach (MediaToken::extract($value) as $uuid) {
      if (!Uuid::isValid($uuid)) {
        $errors[] = new BlueprintError($property, 'INVALID_MEDIA_REFERENCE', sprintf('"media:%s" in %s is not a valid media reference.', $uuid, $property), [], 'Reference media as url(\'media:<uuid>\') using the media entity\'s uuid.');
        continue;
      }
      if (!$this->entityTypeManager->hasDefinition('media')) {
        $errors[] = new BlueprintError($property, 'MEDIA_NOT_FOUND', sprintf('The media reference in %s cannot be resolved because media support is not installed.', $property), [], 'Install the Media module to reference media entities in style values.');
        continue;
      }
      $media = $this->entityRepository->loadEntityByUuid('media', $uuid);
      if (!$media instanceof MediaInterface) {
        $errors[] = new BlueprintError($property, 'MEDIA_NOT_FOUND', sprintf('No media entity with uuid "%s" exists for %s.', $uuid, $property), [], 'Reference an existing media entity by its uuid.');
        continue;
      }
      $source_field = $media->getSource()->getSourceFieldDefinition($media->bundle->entity);
      if (!in_array($source_field?->getType(), MediaValueResolver::SOURCE_FIELD_TYPES, TRUE)) {
        $errors[] = new BlueprintError($property, 'MEDIA_NOT_IMAGE', sprintf('The media "%s" (%s) is not an image or vector, so it cannot be used in %s.', $media->label(), $uuid, $property), [], 'Reference a media entity whose source is an image or SVG.');
      }
    }
    if (MediaToken::hasMalformedReference($value)) {
      $errors[] = new BlueprintError($property, 'INVALID_MEDIA_REFERENCE', sprintf('The value of %s contains a malformed media reference.', $property), [], 'Reference media as url(\'media:<uuid>\') — quoted or unquoted, wrapped in url(), one media uuid per token.');
    }
    return $errors;
  }

  /**
   * Validates a dimension value (optionally shorthand) and its constraints.
   */
  protected function validateDimension(string $property, string $value, array $definition, array $constraints): array {
    // CSS math functions are unsupported values: without
    // this check a clamp() value splits on its inner whitespace and fails
    // the arity check below with a corrective that misdiagnoses the problem
    // as "too many values". Name the rule instead.
    if (preg_match('/(?:^|[\s(,])(clamp|calc|min|max)\s*\(/i', $value)) {
      return [
        new BlueprintError($property, 'INVALID_STYLE_VALUE', sprintf('"%s" is not a supported value for %s: CSS math functions are not supported.', $value, $property), [], 'Use a token or a single length; clamp()/calc() are not supported.'),
      ];
    }
    $components = preg_split('/\s+/', $value);
    $arity = $definition['repeat'] ?? 1;
    if (count($components) > $arity) {
      return [
        new BlueprintError($property, 'INVALID_STYLE_VALUE', sprintf('"%s" has too many values for %s.', $value, $property), [], sprintf('%s accepts at most %d space-separated value(s).', $property, $arity)),
      ];
    }
    $errors = [];
    foreach ($components as $component) {
      if (in_array(strtolower($component), $definition['keywords'] ?? [], TRUE)) {
        continue;
      }
      $parsed = $this->parseDimension($component, !empty($definition['negative']), !empty($definition['number']));
      if ($parsed === NULL) {
        $errors[] = new BlueprintError($property, 'INVALID_STYLE_VALUE', sprintf('"%s" is not a valid value for %s.', $component, $property), [], sprintf('Provide a CSS dimension such as "1.5rem"%s%s.', !empty($definition['number']) ? ' or a unitless number' : '', !empty($definition['negative']) ? '' : ' (negative values are not valid here)'));
        continue;
      }
      [$number, $unit] = $parsed;
      if (isset($constraints['units']) && $unit !== '' && !in_array($unit, $constraints['units'], TRUE)) {
        $errors[] = new BlueprintError($property, 'STYLE_UNIT_NOT_ALLOWED', sprintf('The unit of "%s" is not allowed for %s.', $component, $property), ['units' => $constraints['units']], sprintf('The style guide allows only these units: %s.', implode(', ', $constraints['units'])));
        continue;
      }
      $errors = array_merge($errors, $this->checkRange($property, $component, $number, $unit, $constraints));
    }
    return $errors;
  }

  /**
   * Validates a bare number value and its constraints.
   */
  protected function validateNumber(string $property, string $value, array $definition, array $constraints): array {
    if (in_array(strtolower($value), $definition['keywords'] ?? [], TRUE)) {
      return [];
    }
    if (!is_numeric($value) || (empty($definition['negative']) && (float) $value < 0)) {
      $keywords = !empty($definition['keywords']) ? sprintf(' or one of: %s', implode(', ', $definition['keywords'])) : '';
      return [
        new BlueprintError($property, 'INVALID_STYLE_VALUE', sprintf('"%s" is not a valid value for %s.', $value, $property), [], sprintf('Provide a number%s.', $keywords)),
      ];
    }
    return $this->checkRange($property, $value, (float) $value, '', $constraints);
  }

  /**
   * Validates a custom-ident value ('ident' type).
   *
   * A CSS identifier: letters, digits, hyphens, underscores, not starting
   * with a digit. Per the <custom-ident> grammar, CSS-wide keywords and
   * "default" are excluded (they would be ambiguous as identifiers — a
   * whole-value "inherit" already passed at the pass-through gate above),
   * as are "--"-prefixed names (reserved for custom properties). The
   * definition's keywords ride along as extra accepted values.
   */
  protected function validateIdent(string $property, string $value, array $definition): array {
    if (in_array(strtolower($value), $definition['keywords'] ?? [], TRUE)) {
      return [];
    }
    $reserved = array_merge(self::CSS_WIDE_KEYWORDS, ['default']);
    if (!in_array(strtolower($value), $reserved, TRUE) && preg_match('/^-?[A-Za-z_][A-Za-z0-9_-]*$/', $value)) {
      return [];
    }
    return [
      new BlueprintError($property, 'INVALID_STYLE_VALUE', sprintf('"%s" is not a valid identifier for %s.', $value, $property), [], 'Provide a CSS identifier: letters, digits, hyphens, and underscores, not starting with a digit ("default" and "--" prefixes are reserved).'),
    ];
  }

  /**
   * Validates a keyframes name ('animation_name' type).
   *
   * The ident grammar exactly (a keyframes name IS a custom-ident), with
   * the definition's keywords (`none`) riding along; the type is its own
   * so the reference layer can tell the property apart: which animation a
   * motion-{name} ident resolves to is StyleMapValidator's question
   * (checkAnimationReference(), against the published roster), and the
   * client offers the roster in its value space. This only says whether
   * the spelling could be a name at all.
   */
  protected function validateAnimationName(string $property, string $value, array $definition): array {
    if ($this->validateIdent($property, $value, $definition) === []) {
      return [];
    }
    return [
      new BlueprintError($property, 'INVALID_STYLE_VALUE', sprintf('"%s" is not a keyframes name for %s.', $value, $property), [], 'Provide a keyframes name: a site animation\'s motion-{name} spelling (see the materials roster), "none", or a theme keyframes identifier (letters, digits, hyphens, and underscores, not starting with a digit; "default" and "--" prefixes are reserved).'),
    ];
  }

  /**
   * Validates a keyword value against the definition's keyword list.
   */
  protected function validateKeyword(string $property, string $value, array $definition): array {
    if (in_array(strtolower($value), $definition['keywords'], TRUE)) {
      return [];
    }
    return [
      new BlueprintError($property, 'INVALID_STYLE_VALUE', sprintf('"%s" is not a valid value for %s.', $value, $property), ['keywords' => $definition['keywords']], sprintf('Use one of: %s.', implode(', ', $definition['keywords']))),
    ];
  }

  /**
   * Validates a permissive text value ('family' and 'text' types).
   *
   * Multi-part grammars (shadows, gradients, grid templates, font stacks)
   * are beyond what PHP-side structural checks can usefully model — the
   * client's csstree lexer is the precise grammar check, and "allowed"
   * constraints are the enforcement net. The value hygiene ban on ;{}<> and
   * control characters already ran; this adds a script-scheme scan, since
   * these values reach a <style> element.
   */
  protected function validateText(string $property, string $value): array {
    if (preg_match('/expression\s*\(|(?:javascript|vbscript)\s*:/i', $value)) {
      return [
        new BlueprintError($property, 'INVALID_STYLE_VALUE', sprintf('"%s" is not a valid value for %s.', $value, $property), [], 'Script URL schemes and expression() are not valid in style values.'),
      ];
    }
    return [];
  }

  /**
   * Validates a color value.
   *
   * Deliberately permissive on named colors — the client's csstree lexer is
   * the precise grammar check; palettes ("allowed") are the enforcement net.
   */
  protected function validateColor(string $property, string $value): array {
    $valid = preg_match('/^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value)
      || preg_match('/^(rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|color|light-dark|color-mix)\(/i', $value)
      || preg_match('/^[a-z]{3,25}$/i', $value);
    if ($valid) {
      return [];
    }
    return [
      new BlueprintError($property, 'INVALID_STYLE_VALUE', sprintf('"%s" is not a valid color for %s.', $value, $property), [], 'Provide a CSS color such as "#1a1a2e", "rgb(26 26 46)", or a named color.'),
    ];
  }

  /**
   * Checks one parsed component against min/max constraints.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Range violations, including a STYLE_VALUE_NOT_COMPARABLE error when the
   *   value's unit cannot be compared to the bound's.
   */
  protected function checkRange(string $property, string $component, float $number, string $unit, array $constraints): array {
    $errors = [];
    foreach (['min' => -1, 'max' => 1] as $bound => $violation_sign) {
      if (!isset($constraints[$bound])) {
        continue;
      }
      $comparison = $this->compare($number, $unit, (string) $constraints[$bound]);
      if ($comparison === NULL) {
        $errors[] = new BlueprintError($property, 'STYLE_VALUE_NOT_COMPARABLE', sprintf('"%s" cannot be checked against the %s of %s for %s.', $component, $bound, $constraints[$bound], $property), [$bound => $constraints[$bound]], sprintf('Use a unit comparable to %s (px, pt, rem, and em are interchangeable).', $constraints[$bound]));
      }
      elseif ($comparison === $violation_sign) {
        $errors[] = new BlueprintError($property, $bound === 'max' ? 'STYLE_VALUE_ABOVE_MAX' : 'STYLE_VALUE_BELOW_MIN', sprintf('"%s" is %s the %s of %s for %s.', $component, $bound === 'max' ? 'above' : 'below', $bound, $constraints[$bound], $property), [$bound => $constraints[$bound]], sprintf('The style guide requires a value %s %s.', $bound === 'max' ? 'of at most' : 'of at least', $constraints[$bound]));
      }
    }
    return $errors;
  }

  /**
   * Compares a parsed value to a bound expressed as a CSS dimension or number.
   *
   * @return int|null
   *   -1, 0, or 1 (spaceship semantics), or NULL when not comparable.
   */
  protected function compare(float $number, string $unit, string $bound): ?int {
    $parsed = $this->parseDimension($bound, TRUE, TRUE);
    if ($parsed === NULL) {
      return NULL;
    }
    [$bound_number, $bound_unit] = $parsed;
    if ($unit === $bound_unit) {
      return $number <=> $bound_number;
    }
    if (isset(self::PX_BASIS[$unit], self::PX_BASIS[$bound_unit])) {
      return $number * self::PX_BASIS[$unit] <=> $bound_number * self::PX_BASIS[$bound_unit];
    }
    return NULL;
  }

  /**
   * Parses a single CSS dimension component.
   *
   * @return array|null
   *   [number, unit] — unit is '' for a bare number or unitless zero — or
   *   NULL when the component is not a valid dimension.
   */
  protected function parseDimension(string $component, bool $allow_negative, bool $allow_number): ?array {
    if (!preg_match('/^(-?(?:\d+(?:\.\d+)?|\.\d+))(' . implode('|', self::UNITS) . ')?$/', $component, $matches)) {
      return NULL;
    }
    $number = (float) $matches[1];
    $unit = $matches[2] ?? '';
    if ($number < 0 && !$allow_negative) {
      return NULL;
    }
    if ($unit === '' && $number != 0 && !$allow_number) {
      return NULL;
    }
    return [$number, $unit];
  }

}
