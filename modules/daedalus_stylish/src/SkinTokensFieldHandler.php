<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * The daedalus_blueprint field handler for a skin's tokens.
 *
 * The blueprint lane of the one skin-tokens road: serializes the tokens as
 * a structured scale-keyed map (not an opaque JSON string) and validates
 * writes through the token seam (StyleMapValidator::validateTokens()) — the
 * same gate the skin admin form and the disk lane converge on, so an AI
 * authoring a skin through the generic create/update tools is validated
 * identically to every other surface.
 *
 * A sibling of StyleMapFieldHandler, deliberately: the key grammar differs
 * ({scale: {name: value}} against the site's declared token scales, vs
 * selectable keys against element declarations) and validation needs no
 * scope or host entity at all — token scales are site vocabulary.
 */
class SkinTokensFieldHandler extends StyleMapFieldHandler {

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'daedalus_skin_tokens';
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
        new BlueprintError("fields.$field_name", 'INVALID_SKIN_TOKENS', 'The skin tokens are not an object of token scales.', [], 'Provide an object mapping declared scale names to token maps, e.g. {"space": {"4": "1rem"}, "color": {"accent": {"default": "#1a1a2e", "dark": "#e8e8f0"}}}.'),
      ]);
    }

    $errors = $this->validator->validateTokens($value);
    if ($errors) {
      return new FieldHandlerResult(NULL, array_map(
        static fn (BlueprintError $error) => $error->withPath("fields.$field_name." . $error->getPath()),
        $errors,
      ));
    }

    // Compound condition keys were accepted in any part order; storage
    // keeps the canonical spelling (axis-declaration order), the same
    // normalization the style map's write paths apply.
    $value = $this->conditionTypes->normalizeTokensConditions($value);

    // Canonical storage matches SkinTokensItem::setStyleMap(): JSON text,
    // and an empty map is no value at all.
    return new FieldHandlerResult($value === [] ? NULL : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    return 'A skin\'s design tokens: an object keyed by TOKEN SCALE (the site declares scales in code; the field schema\'s token_scales key lists them with each scale\'s label, value shape, and CSS variable prefix), each scale mapping TOKEN NAMES to values, e.g. {"space": {"4": "1rem"}, "color": {"accent": {"default": "#1a1a2e", "dark": "#e8e8f0"}}}. Names are minted here — lowercase letters, digits, and inner hyphens — and each token delivers as the CSS custom property --{prefix}-{name}, so style values reference it as var(--space-4). A value is a plain string, validated against its scale\'s shape, or a condition-keyed map whose "default" entry is required and always applies; the other keys are style conditions on the width, scheme, and motion axes only ("narrow", "medium", "dark", "reduced-motion", compounds like "narrow+dark") — interaction states cannot condition a token. Skins share one token vocabulary: switching skins swaps the VALUES behind the same names, so prefer re-valuing an existing token over minting a near-duplicate.';
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaAdditions(FieldDefinitionInterface $definition): array {
    $theme = $this->declarations->siteTheme();
    $scales = [];
    foreach ($this->styleGuide->getTokenScales($theme) as $scale => $declaration) {
      $entry = [
        'label' => (string) $declaration['label'],
        'shape' => $declaration['shape'],
        // Serve the prefix resolved: consumers derive --{prefix}-{name}
        // without knowing the default-to-scale-key rule.
        'prefix' => StyleGuide::tokenPrefix($scale, $declaration),
      ];
      if (isset($declaration['keywords'])) {
        $entry['keywords'] = $declaration['keywords'];
      }
      $scales[$scale] = $entry;
    }
    return [
      'token_scales' => [
        'guidance' => $scales === []
          ? 'This site declares no token scales, so no tokens can be minted; scales are code (the site declaration\'s token_scales section).'
          : 'The declared token scales. Tokens are minted per scale as {name: value}; each delivers as the CSS custom property --{prefix}-{name} and its values validate against the scale\'s shape.',
        'scales' => $scales,
        'conditions' => $this->tokenConditionsSchema(),
      ],
    ];
  }

  /**
   * The condition vocabulary token values may vary on.
   *
   * The style map's condition table narrowed to the token axes
   * (StyleMapValidator::TOKEN_CONDITION_AXES): width conditions compile as
   * container queries against the page-level width container, scheme and
   * motion as media queries (user preferences); interaction states are
   * per-element and never condition a token.
   *
   * @return array
   *   Condition name => {wrapper, axis} ("always applies" for default).
   */
  protected function tokenConditionsSchema(): array {
    $conditions = ['default' => 'always applies'];
    foreach ($this->conditionTypes->getConditions() as $name => $condition_definition) {
      if (in_array($condition_definition['axis'], StyleMapValidator::TOKEN_CONDITION_AXES, TRUE)) {
        $conditions[$name] = ['wrapper' => $condition_definition['wrapper'], 'axis' => $condition_definition['axis']];
      }
    }
    return $conditions;
  }

}
