<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRosterBuilder;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SwatchValueItem;

/**
 * The daedalus_blueprint field handler for a swatch's value.
 *
 * The blueprint lane of the one swatch-value road: serializes the value as
 * structured data (a CSS string or a condition-keyed map, not an opaque
 * JSON string) and validates writes through the value seam
 * (StyleMapValidator::validateSwatchValue()) — the same gate the palette
 * gestures (SwatchManager) and disk-shipped swatches converge on, so an AI
 * minting a swatch through the generic create/update tools is validated
 * identically to every other surface. Also the AI-minting governance gate:
 * a create-lane write refuses when daedalus_stylish.settings ai_minting is off
 * (see doDeserialize), because the blueprint lane is where AI mints enter.
 *
 * A sibling of StyleMapFieldHandler, deliberately: the grammar is a single
 * named VALUE, not a map of keys, and validation dispatches to the sibling
 * kind field's plugin — read from the submitted fields first (the kind this
 * write is establishing), then from the entity being written, so blueprint
 * payload key order never matters.
 */
class SwatchValueFieldHandler extends StyleMapFieldHandler {

  use DesignSystemOptionTrait;

  /**
   * Constructs the swatch value field handler.
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
   * @param \Closure $importer
   *   Service closure resolving the swatch importer, the distro lock's
   *   disk read (does the provider ship this entry right now?). Lazy
   *   because field handlers instantiate with the handler manager the
   *   deserializer needs, and the importer injects that deserializer —
   *   the same cycle the variant styles handler breaks.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
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
    protected \Closure $importer,
    protected ConfigFactoryInterface $configFactory,
    protected DistroLock $distroLock,
  ) {
    parent::__construct($validator, $styleGuide, $declarations, $conditionTypes, $valueResolver, $rosterBuilder, $vocabulary);
  }

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'daedalus_swatch_value';
  }

  /**
   * {@inheritdoc}
   */
  protected function doSerialize(FieldItemListInterface $items, FieldDefinitionInterface $definition): array {
    $item = $items->first();
    return $item instanceof SwatchValueItem ? [$item->getSwatchValue()] : [];
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    $field_name = $definition->getName();
    $entity = $options['entity'] ?? NULL;
    $is_mint = !$entity instanceof DaedalusStylishSwatchInterface || $entity->isNew();

    // The AI-minting governance gate, its own axis beside strict (strict
    // governs consumers; whether AI may EXTEND the vocabulary is site
    // policy). Blueprint-lane creates are where AI swatch minting enters
    // the spine, whichever consumer initiated them (the create_entity
    // tool, the HTTP lane), and every viable mint writes this field (a
    // valueless swatch refuses in preSave), so this is the one
    // enforcement point. The other lanes never pass here gated: humans
    // mint through the palette gestures (SwatchManager::mint, the
    // "create swatches" permission) and disk ships through the importer,
    // whose shipped_sync option is the standing disk exception. Only an
    // explicit FALSE closes the gate: sites predating the key keep the
    // open lane until daedalus_stylish_post_update_ai_minting_default() seeds it.
    if ($is_mint && empty($options['shipped_sync']) && $this->configFactory->get('daedalus_stylish.settings')->get('ai_minting') === FALSE) {
      return new FieldHandlerResult(NULL, [
        new BlueprintError("fields.$field_name", 'STYLE_SWATCH_AI_MINTING_DISABLED', 'AI swatch minting is disabled on this site.', [], 'Reference a published palette swatch or use a literal value instead of creating a daedalus_swatch; a person with the "create swatches" permission can mint it in the palette, or an administrator can enable ai_minting in daedalus_stylish.settings.'),
      ]);
    }

    // The distro lock's blueprint-lane gate: a swatch with a LIVING disk
    // source (its provider ships the entry right now) is read-only, so a
    // generic update addressing it refuses before any value validation —
    // the same lock SwatchManager::checkShippedLock() enforces on the
    // gestures. The importer's own sync passes shipped_sync (SwatchImporter
    // deserializes through this handler too; disk is the one author the
    // lock protects, so its writes are the exception, not a bypass). The
    // gate lifts with the distro lock (DistroLock): a development site
    // authoring the package edits its shipped swatches here.
    if ($this->distroLock->holds() && $entity instanceof DaedalusStylishSwatchInterface && !$entity->isNew() && empty($options['shipped_sync']) && ($this->importer)()->getShippedData($entity) !== NULL) {
      return new FieldHandlerResult(NULL, [
        new BlueprintError("fields.$field_name", 'STYLE_SWATCH_SHIPPED_LOCKED', sprintf('The "%s" swatch is shipped by the "%s" module; shipped swatches are read-only.', $entity->getMachineName(), (string) $entity->getProvider()), ['provider' => (string) $entity->getProvider()], 'Mint a new swatch and reference that instead, or change the shipped defaults in the module\'s swatches file.'),
      ]);
    }

    if (!is_string($value) && (!is_array($value) || array_is_list($value))) {
      return new FieldHandlerResult(NULL, [
        new BlueprintError("fields.$field_name", 'INVALID_SWATCH_VALUE', 'The swatch value is not a CSS value.', [], 'Provide a string ("#1a1a2e") or a condition-keyed map with a "default" entry ({"default": "#1a1a2e", "dark": "#e8e8f0"}).'),
      ]);
    }

    // The kind owns the value's shape: read it from the submitted sibling
    // field first (the kind this write is establishing), then from the
    // entity being written.
    $kind = $options['fields']['kind'] ?? NULL;
    if (!is_string($kind) || $kind === '') {
      $kind = $entity instanceof DaedalusStylishSwatchInterface ? $entity->getKind() : '';
    }

    $errors = $this->validator->validateSwatchValue($kind, $value, ['design_system' => $this->submittedDesignSystem($options)]);
    if ($errors) {
      // The seam's paths live in the gesture's parameter space ('kind',
      // 'value', 'value.{condition}'); re-root them onto the blueprint's
      // fields object — the kind error lands on the sibling field it
      // belongs to.
      return new FieldHandlerResult(NULL, array_map(
        static fn (BlueprintError $error) => $error->withPath('fields.' . $error->getPath()),
        $errors,
      ));
    }

    // Compound condition keys were accepted in any part order; storage
    // keeps the canonical spelling, the same normalization every other
    // write path applies.
    if (is_array($value)) {
      $value = $this->conditionTypes->normalizeValueConditions($value);
    }

    // Canonical storage matches SwatchValueItem::setSwatchValue(): JSON
    // text, and an empty value is no value at all (the entity's preSave
    // refuses to save a valueless swatch).
    return new FieldHandlerResult($value === '' || $value === [] ? NULL : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    // The lock sentence follows the distro lock: on a development site
    // (the gate lifted) shipped swatches update like any other, so the
    // guidance says nothing about them.
    $lock = $this->distroLock->holds()
      ? ' Module-shipped swatches are READ-ONLY (the distro lock): updating their value is refused; mint a NEW swatch with the value you want and reference that instead.'
      : '';
    return 'A swatch\'s CSS value: a plain string ("#e94560", "linear-gradient(160deg, #1a1a2e, #e94560)"), or a condition-keyed map whose "default" entry is required and always applies, the other keys style conditions on the width/scheme/motion axes only ({"default": "#1a1a2e", "dark": "#e8e8f0"}). The sibling kind field decides the value\'s shape: "color" accepts any CSS color, "gradient" one or more comma-separated gradient functions. var() references to skin tokens or other published swatches are valid values. The swatch delivers as the CSS custom property --swatch-{machine_name}, so style values reference it as var(--swatch-{machine_name}); editing the value restyles every element referencing it.' . $lock;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaAdditions(FieldDefinitionInterface $definition): array {
    return [];
  }

}
