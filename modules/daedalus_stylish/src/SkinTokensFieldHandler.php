<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRosterBuilder;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;

/**
 * The daedalus_blueprint field handler for a skin's package-layer tokens.
 *
 * The blueprint lane of the one skin-tokens road: serializes the tokens as
 * a structured scale-keyed map of records (not an opaque JSON string) and
 * validates writes through the token seam
 * (StyleMapValidator::validateTokens()) — the same gate the skin admin
 * form and the disk lane converge on, so an AI authoring a skin through
 * the generic create/update tools is validated identically to every other
 * surface. An authored door: a bare value is accepted as shorthand for
 * {value} and canonicalized before validation
 * (SkinTokensItem::canonicalizeTokens()), so stored tokens are records
 * whatever the writer spelled.
 *
 * The field is the PACKAGE layer, what a module ships, so the distro lock
 * gates the write (SkinPackageLockTrait): a shipped skin's package layer
 * is read-only on a consuming site, the importer's own sync passes
 * shipped_sync, and the developer gate lifts the lock. The site layer is
 * not on this spine (an internal field).
 *
 * A sibling of StyleMapFieldHandler, deliberately: the key grammar differs
 * ({scale: {name: record}} against the site's declared token scales, vs
 * selectable keys against element declarations) and validation needs no
 * scope or host entity at all — token scales are site vocabulary.
 */
class SkinTokensFieldHandler extends StyleMapFieldHandler {

  use SkinPackageLockTrait;

  /**
   * Constructs the skin tokens field handler.
   *
   * @param \Drupal\daedalus_stylish\StyleMapValidator $validator
   *   The style map validator.
   * @param \Drupal\daedalus_stylish\StyleGuide $styleGuide
   *   The style guide.
   * @param \Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations $declarations
   *   The page builder declarations.
   * @param \Drupal\daedalus_stylish\ConditionTypePluginManager $conditionTypes
   *   The condition type plugin manager.
   * @param \Drupal\daedalus_stylish\StyleValueResolver $valueResolver
   *   The style value resolver.
   * @param \Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder $rosterBuilder
   *   The materials roster builder.
   * @param \Drupal\daedalus_stylish\Vocabulary\VocabularyRosterBuilder $vocabulary
   *   The vocabulary roster builder.
   * @param \Drupal\daedalus_blueprint\Host\HostResolver $hostResolver
   *   The host resolver: the style map field's owner is the map's host.
   * @param \Closure $importer
   *   Service closure resolving the skin importer, the distro lock's disk
   *   read (does the provider ship this skin right now?). Lazy because
   *   field handlers instantiate with the handler manager the deserializer
   *   needs, and the importer injects that deserializer, the same cycle
   *   the variant styles handler breaks.
   * @param \Drupal\daedalus_blueprint\Materials\DistroLock $distroLock
   *   The distro lock: whether shipped materials are read-only on this
   *   site. Read live on every gate and guidance call, never cached.
   */
  public function __construct(
    StyleMapValidator $validator,
    StyleGuide $styleGuide,
    PageBuilderDeclarations $declarations,
    ConditionTypePluginManager $conditionTypes,
    StyleValueResolver $valueResolver,
    MaterialsRosterBuilder $rosterBuilder,
    VocabularyRosterBuilder $vocabulary,
    HostResolver $hostResolver,
    protected \Closure $importer,
    protected DistroLock $distroLock,
  ) {
    parent::__construct($validator, $styleGuide, $declarations, $conditionTypes, $valueResolver, $rosterBuilder, $vocabulary, $hostResolver);
  }

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
    if ($locked = $this->packageLockError($options, $field_name)) {
      return new FieldHandlerResult(NULL, [$locked]);
    }
    if (is_string($value)) {
      $value = json_decode($value, TRUE);
    }
    if (!is_array($value) || (array_is_list($value) && $value !== [])) {
      return new FieldHandlerResult(NULL, [
        new BlueprintError("fields.$field_name", 'INVALID_SKIN_TOKENS', 'The skin tokens are not an object of token scales.', [], 'Provide an object mapping declared scale names to token maps, e.g. {"space": {"4": "1rem"}, "color": {"accent": {"default": "#1a1a2e", "dark": "#e8e8f0"}}}.'),
      ]);
    }

    $value = SkinTokensItem::canonicalizeTokens($value);
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
    return $this->tokensGuidance() . $this->packageLockGuidance('tokens');
  }

  /**
   * The tokens grammar, the guidance's constant part.
   */
  protected function tokensGuidance(): string {
    return 'A skin\'s design tokens: an object keyed by TOKEN SCALE (the site declares scales in code; the field schema\'s token_scales key lists them with each scale\'s label, value shape, and CSS variable prefix), each scale mapping TOKEN NAMES to records, e.g. {"space": {"4": {"value": "1rem", "description": "The base step.", "offered": true}}, "color": {"accent": {"value": {"default": "#1a1a2e", "dark": "#e8e8f0"}}}}. A record carries value (required), description (prose: why the token exists and when to reach for it, "" when unwritten) and offered (true by default: rows bound to the scale offer the token as an everyday step and the roster advertises it; false keeps it minted and legal but offered nowhere); a bare value in place of a record is shorthand for {"value": ...}. Names are minted here — lowercase letters, digits, and inner hyphens — and each token delivers as the CSS custom property --{prefix}-{name}, so style values reference it as var(--space-4). A value is a plain string, validated against its scale\'s shape, or a condition-keyed map whose "default" entry is required and always applies; the other keys are style conditions on the width, scheme, and motion axes only ("narrow", "medium", "dark", "reduced-motion", compounds like "narrow+dark") — interaction states cannot condition a token. Skins share one token vocabulary: switching skins swaps the VALUES behind the same names, so prefer re-valuing an existing token over minting a near-duplicate.';
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
          : 'The declared token scales. Tokens are minted per scale as {name: {value, description, offered}} (a bare value is shorthand for {value}); each delivers as the CSS custom property --{prefix}-{name} and its value validates against the scale\'s shape.',
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
