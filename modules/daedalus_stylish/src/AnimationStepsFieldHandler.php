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
use Drupal\daedalus_stylish\Plugin\Field\FieldType\AnimationStepsItem;

/**
 * The daedalus_blueprint field handler for an animation's keyframe steps.
 *
 * The blueprint lane of the one animation-steps road: serializes the steps
 * as structured data (an offset-keyed map of declarations, not an opaque
 * JSON string) and validates writes through the steps seam
 * (StyleMapValidator::validateAnimationSteps()), the same gate the
 * Materials gestures (AnimationManager) and disk-shipped animations
 * converge on, so an AI minting an animation through the generic
 * create/update tools is validated identically to every other surface.
 * Also the AI-minting governance gate: a create-lane write refuses when
 * daedalus_stylish.settings ai_minting is off (see doDeserialize), because the
 * blueprint lane is where AI mints enter. The swatch value handler's twin
 * at structure scope.
 */
class AnimationStepsFieldHandler extends StyleMapFieldHandler {

  use DesignSystemOptionTrait;

  /**
   * Constructs the animation steps field handler.
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
   *   Service closure resolving the animation importer, the distro lock's
   *   disk read (does the provider ship this entry right now?). Lazy
   *   because field handlers instantiate with the handler manager the
   *   deserializer needs, and the importer injects that deserializer: the
   *   same cycle the swatch value handler breaks.
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
    return $definition->getType() === 'daedalus_animation_steps';
  }

  /**
   * {@inheritdoc}
   */
  protected function doSerialize(FieldItemListInterface $items, FieldDefinitionInterface $definition): array {
    $item = $items->first();
    return $item instanceof AnimationStepsItem ? [$item->getSteps()] : [];
  }

  /**
   * {@inheritdoc}
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    $field_name = $definition->getName();
    $entity = $options['entity'] ?? NULL;
    $is_mint = !$entity instanceof DaedalusStylishAnimationInterface || $entity->isNew();

    // The AI-minting governance gate, its own axis beside strict (strict
    // governs consumers; whether AI may EXTEND the vocabulary is site
    // policy). Blueprint-lane creates are where AI animation minting
    // enters the spine, whichever consumer initiated them, and every
    // viable mint writes this field (a stepless animation refuses in
    // preSave), so this is the one enforcement point. The other lanes
    // never pass here gated: humans mint through the Materials gestures
    // (AnimationManager::mint, the "create animations" permission) and
    // disk ships through the importer, whose shipped_sync option is the
    // standing disk exception. Only an explicit FALSE closes the gate.
    if ($is_mint && empty($options['shipped_sync']) && $this->configFactory->get('daedalus_stylish.settings')->get('ai_minting') === FALSE) {
      return new FieldHandlerResult(NULL, [
        new BlueprintError("fields.$field_name", 'STYLE_ANIMATION_AI_MINTING_DISABLED', 'AI animation minting is disabled on this site.', [], 'Wear a published animation (the MATERIALS roster) instead of creating a daedalus_animation; a person with the "create animations" permission can mint it in the Materials section, or an administrator can enable ai_minting in daedalus_stylish.settings.'),
      ]);
    }

    // The distro lock's blueprint-lane gate: an animation with a LIVING
    // disk source (its provider ships the entry right now) is read-only,
    // so a generic update addressing it refuses before any validation,
    // the same lock AnimationManager::checkShippedLock() enforces on the
    // gestures. The importer's own sync passes shipped_sync (disk is the
    // one author the lock protects, so its writes are the exception). The
    // gate lifts with the distro lock (DistroLock): a development site
    // authoring the package edits its shipped animations here.
    if ($this->distroLock->holds() && $entity instanceof DaedalusStylishAnimationInterface && !$entity->isNew() && empty($options['shipped_sync']) && ($this->importer)()->getShippedData($entity) !== NULL) {
      return new FieldHandlerResult(NULL, [
        new BlueprintError("fields.$field_name", 'STYLE_ANIMATION_SHIPPED_LOCKED', sprintf('The "%s" animation is shipped by the "%s" module; shipped animations are read-only.', $entity->getMachineName(), (string) $entity->getProvider()), ['provider' => (string) $entity->getProvider()], 'Mint a new animation and wear that instead, or change the shipped defaults in the module\'s animations file.'),
      ]);
    }

    if (is_string($value)) {
      $value = json_decode($value, TRUE);
    }
    $errors = $this->validator->validateAnimationSteps($value, ['design_system' => $this->submittedDesignSystem($options)]);
    if ($errors) {
      // The seam's paths live in the gesture's parameter space ('steps',
      // 'steps.{offset}', 'steps.{offset}.{property}'); re-root them onto
      // the blueprint's fields object.
      return new FieldHandlerResult(NULL, array_map(
        static fn (BlueprintError $error) => $error->withPath('fields.' . $error->getPath()),
        $errors,
      ));
    }

    // Canonical storage matches AnimationStepsItem::setSteps(): offsets
    // folded and sorted, JSON text.
    return new FieldHandlerResult(json_encode(AnimationStepsItem::canonicalizeSteps($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    // The lock sentence follows the distro lock: on a development site
    // (the gate lifted) shipped animations update like any other, so the
    // guidance says nothing about them.
    $lock = $this->distroLock->holds()
      ? ' Module-shipped animations are READ-ONLY (the distro lock): updating their steps is refused; mint a NEW animation with the steps you want and wear that instead.'
      : '';
    return 'An animation\'s keyframe steps: an object keyed by offset ("0%", "50%", "100%", or "from"/"to"), each mapping CSS properties to values ({"0%": {"opacity": "0", "transform": "translateY(1rem)"}, "100%": {"opacity": "1", "transform": "none"}}), at least one step. Any recognized property may animate; animation-* properties do not belong inside a step (animation-timing-function alone does, as the easing to the next step), and media references are not valid there. var() references to skin tokens or published swatches are valid values. The animation delivers as @keyframes motion-{machine_name}, so style values wear it as animation-name: motion-{machine_name} (or as the name in the animation shorthand); editing the steps restyles every element wearing it. A wearer should carry its own reduced-motion condition setting animation to none.' . $lock;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaAdditions(FieldDefinitionInterface $definition): array {
    return [];
  }

}
