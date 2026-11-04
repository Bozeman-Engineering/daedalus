<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The rewrite-then-delete and rewrite-then-rename batches of a skin material.
 *
 * ValueDeleteBatchBase's twin for the materials that are facts of a skin,
 * all three sections: staged by SkinMaterialManager::deleteToken() /
 * deleteAnimation() / deleteFont() for a REFERENCED material and by
 * rename() / renameAnimation() for a referenced material's rename (a
 * font's rename stages nothing: stacks name the family, not the name).
 * The subject is the material's handle (SkinToken, SkinAnimation or
 * SkinFont), loaded by its batch id (the skin's entity id, the section,
 * the material's roster id and, for a rename, the new name), so the
 * material is re-read from the skin each operation and the job ends
 * harmlessly when someone else finished it.
 *
 * Delete: each var() of a token's custom property, fallback included,
 * becomes the token's literal value (SkinToken::literal(), the default
 * entry of a conditioned value); each motion-{name} component of an
 * animation becomes `none` (a keyframes block has no literal form inside
 * a value, and the wearer keeps its timing declarations); each quoted
 * component of a font-family stack naming a font's family leaves the
 * stack (FontFamilies::removeFamily(): the element falls back to the next
 * family the author wrote, an emptied stack to `initial`); in the host
 * map, variant styles, and the skin's tokens, animations AND defaults;
 * then the material goes through the skin material manager, in the layer
 * the site may write. Rename: each reference is respelled to the new
 * custom property or keyframes name, fallbacks kept (the reference lives
 * on under its new name); then the record moves.
 *
 * A rewritten SHIPPED variant or skin reads 'modified' afterwards: an
 * honest divergence from its disk source, preferred over leaving a
 * reference that can no longer resolve.
 */
final class SkinMaterialDeleteBatch extends ValueDeleteBatchBase {

  /**
   * Batch operation: sweeps any late references, then deletes the material.
   */
  public static function deleteSkinMaterial(string $id, array &$context): void {
    static::deleteMaterial($id, $context);
  }

  /**
   * Batch operation: sweeps any late references, then renames the material.
   *
   * The rename batch's tail, deleteMaterial()'s twin: the late work list
   * is respelled, then the record moves under its new name.
   */
  public static function renameSkinMaterial(string $id, array &$context): void {
    $material = static::load($id);
    if ($material === NULL || $material->renameTo === NULL) {
      return;
    }
    static::sweep($material, $context);
    $manager = \Drupal::service('daedalus_stylish.skin_material_manager');
    if ($material instanceof SkinToken) {
      $manager->rename($material->skin, $material->scale, $material->name, $material->renameTo, FALSE);
      $context['results']['renamed'] = $material->scale . '/' . $material->renameTo;
      return;
    }
    assert($material instanceof SkinAnimation);
    $manager->renameAnimation($material->skin, $material->name, $material->renameTo, FALSE);
    $context['results']['renamed'] = $material->renameTo;
  }

  /**
   * Batch finish callback of the rename job: one loud summary.
   */
  public static function renameFinished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    if (!$success || !isset($results['renamed'])) {
      $messenger->addError(new TranslatableMarkup('Renaming the material failed; its references keep the old name. Re-run the rename to finish.'));
      return;
    }
    $messenger->addStatus(new TranslatableMarkup('Renamed the material to %id and respelled its references in @count places.', [
      '%id' => $results['renamed'],
      '@count' => $results['rewritten'] ?? 0,
    ]));
  }

  /**
   * Splits a batch id into the skin's entity id, section, id and rename target.
   *
   * @return array{0: int, 1: string, 2: string, 3: string|null}|null
   *   [skin id, section, material id, new name or NULL], or NULL when the
   *   id is not one a handle's batchId() produced.
   */
  public static function parseBatchId(string $id): ?array {
    $parts = explode(':', $id);
    $sections = [
      SkinMaterialManager::TOKENS,
      SkinMaterialManager::ANIMATIONS,
      SkinMaterialManager::FONTS,
    ];
    if (count($parts) < 3 || count($parts) > 4 || !ctype_digit($parts[0]) || !in_array($parts[1], $sections, TRUE) || $parts[2] === '') {
      return NULL;
    }
    return [(int) $parts[0], $parts[1], $parts[2], $parts[3] ?? NULL];
  }

  /**
   * {@inheritdoc}
   *
   * The entity the batch loads by id is the SKIN; the material is picked
   * from it (load()).
   */
  protected static function entityTypeId(): string {
    return 'daedalus_skin';
  }

  /**
   * {@inheritdoc}
   */
  protected static function usage(): ValueUsageBase {
    return \Drupal::service('daedalus_stylish.skin_material_usage');
  }

  /**
   * {@inheritdoc}
   *
   * The material's handle from its batch id: NULL when the skin is gone or
   * no longer mints the material (someone else finished the job).
   */
  protected static function load(int|string $id): ?object {
    $address = self::parseBatchId((string) $id);
    if ($address === NULL) {
      return NULL;
    }
    [$skin_id, $section, $material_id, $rename_to] = $address;
    $skin = \Drupal::entityTypeManager()->getStorage(static::entityTypeId())->load($skin_id);
    if (!$skin instanceof DaedalusStylishSkinInterface) {
      return NULL;
    }
    $manager = \Drupal::service('daedalus_stylish.skin_material_manager');
    if ($section === SkinMaterialManager::ANIMATIONS) {
      return $manager->resolveAnimation($skin, $material_id, $rename_to);
    }
    if ($section === SkinMaterialManager::FONTS) {
      return $manager->resolveFont($skin, $material_id);
    }
    $token = SkinMaterialManager::parseId($material_id);
    return $token === NULL ? NULL : $manager->resolve($skin, $token[0], $token[1], $rename_to);
  }

  /**
   * {@inheritdoc}
   */
  protected static function nameOf(object $material): string {
    if ($material instanceof SkinToken) {
      return $material->variable;
    }
    if ($material instanceof SkinFont) {
      return $material->spelling;
    }
    assert($material instanceof SkinAnimation);
    return $material->keyframes;
  }

  /**
   * {@inheritdoc}
   */
  protected static function labelOf(object $material): string {
    assert($material instanceof SkinToken || $material instanceof SkinAnimation || $material instanceof SkinFont);
    return $material->id();
  }

  /**
   * {@inheritdoc}
   *
   * The material goes through the manager, which decides the layer and
   * finds no reference left to refuse over.
   */
  protected static function remove(object $material): void {
    $manager = \Drupal::service('daedalus_stylish.skin_material_manager');
    if ($material instanceof SkinToken) {
      $manager->deleteToken($material->skin, $material->scale, $material->name, FALSE);
      return;
    }
    if ($material instanceof SkinFont) {
      $manager->deleteFont($material->skin, $material->name, FALSE);
      return;
    }
    assert($material instanceof SkinAnimation);
    $manager->deleteAnimation($material->skin, $material->name, FALSE);
  }

  /**
   * {@inheritdoc}
   *
   * The literal rewrite for a token delete, the respelling for a token
   * rename (a token has no property, and a var() needs none); the `none`
   * rewrite for an animation delete, the respelling for an animation
   * rename, both inside the motion properties alone; the family removal
   * for a font delete, inside font-family alone.
   */
  protected static function rewriter(object $material): \Closure {
    if ($material instanceof SkinFont) {
      $family = $material->family();
      return static fn (string $property, string $value): string => FontFamilies::removeFamily($property, $value, $family);
    }
    if ($material instanceof SkinToken) {
      $variable = $material->variable;
      if ($material->renameTo !== NULL) {
        $replacement = \Drupal::service('daedalus_stylish.skin_material_manager')->variable($material->scale, $material->renameTo);
        return static fn (string $property, string $value): string => SkinMaterialUsage::renameValue($value, $variable, $replacement);
      }
      $literal = $material->literal();
      return static fn (string $property, string $value): string => ValueUsageBase::rewriteVariable($value, $variable, $literal);
    }
    assert($material instanceof SkinAnimation);
    $keyframes = $material->keyframes;
    $replacement = $material->renameTo === NULL ? 'none' : StyleGuide::animationName($material->renameTo);
    return static fn (string $property, string $value): string => SkinMaterialUsage::replaceAnimation($property, $value, $keyframes, $replacement);
  }

  /**
   * {@inheritdoc}
   *
   * A var() lives in a token value as it does anywhere: the shared
   * property-blind walk. A family lives only in the tokens of the scales
   * the guide binds to font-family (SkinMaterialUsage::fontScales(), the
   * one place a token carries the property), each value rewritten as a
   * font-family value; every other scale is untouched.
   */
  protected static function rewriteTokens(object $material, array $tokens, \Closure $rewrite, bool &$changed): array {
    if (!$material instanceof SkinFont) {
      return static::rewriteTokenValues($tokens, $rewrite, $changed);
    }
    $usage = static::usage();
    assert($usage instanceof SkinMaterialUsage);
    $bound = array_intersect_key($tokens, array_flip($usage->fontScales()));
    if ($bound === []) {
      return $tokens;
    }
    return static::rewriteTokenValues($bound, $rewrite, $changed, 'font-family') + $tokens;
  }

  /**
   * {@inheritdoc}
   *
   * A var() lives in a keyframe step's value as it does anywhere; a step's
   * property is kept for the rewrite (a keyframes name never lives there,
   * the steps seam refuses animation-name, so the motion rewrite is inert
   * on steps by construction; a font-family step carries a family, so the
   * family rewrite reaches it).
   */
  protected static function rewriteAnimations(object $material, array $animations, \Closure $rewrite, bool &$changed): array {
    return static::rewriteAnimationSteps($animations, $rewrite, $changed);
  }

  /**
   * {@inheritdoc}
   */
  protected static function revisionLogMessage(string $name): string {
    return sprintf('Rewrote %s references before the skin material changed.', $name);
  }

  /**
   * {@inheritdoc}
   */
  protected static function failureMessage(): TranslatableMarkup {
    return new TranslatableMarkup('Deleting the material failed; its references keep resolving it. Re-run the delete to finish.');
  }

  /**
   * {@inheritdoc}
   */
  protected static function successMessage(string $label, int $rewritten): TranslatableMarkup {
    return new TranslatableMarkup('Rewrote %label references in @count places and deleted it.', [
      '%label' => $label,
      '@count' => $rewritten,
    ]);
  }

}
