<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * The daedalus_blueprint field handler for a skin's package-layer animations.
 *
 * The blueprint lane's READ of a skin's motion: serializes the package
 * layer as a structured name-keyed map of records (not an opaque JSON
 * string). It has no write: a skin's animations are minted, changed and
 * removed one at a time through the material gestures (save_material /
 * update_material / remove_material with kind animation,
 * SkinMaterialManager), the one door the skin tab and the AI share, which
 * lands in the layer the site may write and validates through the
 * animations seam (StyleMapValidator::validateAnimations()). A
 * whole-field write here refuses and names that door, the defaults
 * handler's posture (SkinDefaultsFieldHandler, whose door is
 * update_default). The disk lane (SkinImporter) lands shipped animations
 * through the entity, validated by the same seam.
 */
class SkinAnimationsFieldHandler extends StyleMapFieldHandler {

  /**
   * The refusal code of a whole-field animations write.
   */
  public const OP_ONLY = 'SKIN_ANIMATIONS_OP_ONLY';

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'daedalus_skin_animations';
  }

  /**
   * {@inheritdoc}
   *
   * Every write refuses: the field is the package layer, which the disk
   * lane writes, and the site's own animations go through the material
   * gestures.
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    return new FieldHandlerResult(NULL, [
      new BlueprintError('fields.' . $definition->getName(), self::OP_ONLY, 'A skin\'s animations are not written as a whole field; the material gestures mint, change and remove them one at a time.', [], 'Submit save_material / update_material / remove_material with "kind": "animation" in a batch ("label" the name, "steps" the keyframes, "description", "offered"); on a consuming site it lands in the skin\'s site layer over what the package ships.'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    return 'A skin\'s animations: an object keyed by ANIMATION NAME (lowercase letters, digits, inner hyphens or underscores; the name is the tail of the delivered keyframes name motion-{name}), each a record {"steps": {"0%": {"opacity": "0"}, "100%": {"opacity": "1"}}, "description": "...", "offered": true}. steps maps keyframe offsets ("0%", "50%", "100%", or "from"/"to") to CSS properties, at least one step; animation-* properties do not belong inside a step (animation-timing-function alone does, as the easing to the next step), media references are not valid there, and var() references to the skin\'s own tokens are. Style values wear an animation as animation-name: motion-{name} (or as the name in the animation shorthand), and a wearer should carry its own reduced-motion condition setting animation to none. READ-ONLY here: a skin\'s animations are written through the material gestures (save_material, update_material, remove_material with kind animation), one at a time, never as a whole field.';
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaAdditions(FieldDefinitionInterface $definition): array {
    return [];
  }

}
