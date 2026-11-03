<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\FieldHandler\FieldHandlerResult;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * The daedalus_blueprint field handler for a skin's package-layer fonts.
 *
 * The blueprint lane's READ of a skin's type: serializes the package layer
 * as a structured name-keyed map of records (not an opaque JSON string).
 * It has no write: a skin's fonts are minted, changed and removed one at
 * a time through the material gestures (save_material / update_material /
 * remove_material with kind font, SkinMaterialManager), the one door the
 * skin tab and the AI share, which lands in the layer the site may write
 * and validates through the font seam (FontValidator::validateFonts(),
 * which resolves every face's file). A whole-field write here refuses and
 * names that door, the animations handler's posture
 * (SkinAnimationsFieldHandler). The disk lane (SkinImporter) lands shipped
 * fonts through the entity, their files through the font store, validated
 * by the same seam.
 */
class SkinFontsFieldHandler extends StyleMapFieldHandler {

  /**
   * The refusal code of a whole-field fonts write.
   */
  public const OP_ONLY = 'SKIN_FONTS_OP_ONLY';

  /**
   * {@inheritdoc}
   */
  public function applies(FieldDefinitionInterface $definition): bool {
    return $definition->getType() === 'daedalus_skin_fonts';
  }

  /**
   * {@inheritdoc}
   *
   * Every write refuses: the field is the package layer, which the disk
   * lane writes, and the site's own fonts go through the material
   * gestures.
   */
  protected function doDeserialize(mixed $value, FieldDefinitionInterface $definition, array $options = []): FieldHandlerResult {
    return new FieldHandlerResult(NULL, [
      new BlueprintError('fields.' . $definition->getName(), self::OP_ONLY, 'A skin\'s fonts are not written as a whole field; the material gestures mint, change and remove them one at a time.', [], 'Submit save_material / update_material / remove_material with "kind": "font" in a batch ("label" the name, "family" the name stacks reference, "faces" the uploaded files, "description", "offered"); on a consuming site it lands in the skin\'s site layer over what the package ships.'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getGuidance(): ?string {
    return 'A skin\'s fonts: an object keyed by FONT NAME (lowercase letters, digits, inner hyphens or underscores; the roster id), each a record {"family": "Inter", "faces": [{"weight": "400", "style": "normal", "source": {"file": "<uuid>"}}], "description": "...", "offered": true}. family is the name a font-family stack references (the compiled @font-face quotes it: "Inter"); faces is a non-empty list, one per weight and style, each naming an uploaded font file (woff2 or woff) by uuid. READ-ONLY here: a skin\'s fonts are written through the material gestures (save_material, update_material, remove_material with kind font), one at a time, never as a whole field.';
  }

  /**
   * {@inheritdoc}
   */
  public function getSchemaAdditions(FieldDefinitionInterface $definition): array {
    return [];
  }

}
