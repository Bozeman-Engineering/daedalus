<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The rewrite-then-delete Batch API job behind font deletion.
 *
 * ValueDeleteBatchBase's family twin, staged by FontManager::deleteFont()
 * for a font some value NAMES: every quoted component spelling the
 * family leaves its font-family stack (FontUsage::rewriteValue()), in the
 * host map, variant styles, skin defaults and the skin's tokens on the
 * scales the guide binds to font-family (FontUsage::fontScales(), the
 * one place a token carries the property).
 *
 * Removal rather than a stand-in: a stack is its own fallback list, so
 * dropping the family leaves the element exactly where a missing
 * font-face at-rule would have left it, on the next family the author wrote; a
 * stack that named nothing else becomes the initial value, the user
 * agent's default family, which is what the stale reference rendered.
 * Only behind the "delete fonts in use" grade.
 *
 * The subject is the font entity, but the usage table keys on its FAMILY
 * (the name a value spells), so nameOf() answers the family.
 */
final class FontDeleteBatch extends ValueDeleteBatchBase {

  /**
   * Batch operation: sweeps any late references, then deletes the font.
   */
  public static function deleteFont(int $font_id, array &$context): void {
    static::deleteMaterial($font_id, $context);
  }

  /**
   * {@inheritdoc}
   */
  protected static function entityTypeId(): string {
    return 'daedalus_font';
  }

  /**
   * {@inheritdoc}
   */
  protected static function usage(): ValueUsageBase {
    return \Drupal::service('daedalus_stylish.font_usage');
  }

  /**
   * {@inheritdoc}
   *
   * The family, the usage column's value.
   */
  protected static function nameOf(object $material): string {
    assert($material instanceof DaedalusStylishFontInterface);
    return $material->getFamily();
  }

  /**
   * {@inheritdoc}
   */
  protected static function rewriter(object $material): \Closure {
    assert($material instanceof DaedalusStylishFontInterface);
    $family = $material->getFamily();
    return static fn (string $property, string $value): string => FontUsage::rewriteValue($property, $value, $family);
  }

  /**
   * {@inheritdoc}
   *
   * The font-bound scales' tokens, each value rewritten as a font-family
   * value (the token walk hands the rewriter no property, so the family
   * property is supplied here); every other scale is untouched.
   */
  protected static function rewriteTokens(array $tokens, \Closure $rewrite, bool &$changed): array {
    $usage = static::usage();
    assert($usage instanceof FontUsage);
    $scales = $usage->fontScales();
    $bound = array_intersect_key($tokens, array_flip($scales));
    if ($bound === []) {
      return $tokens;
    }
    $as_family = static fn (string $property, string $value): string => $rewrite('font-family', $value);
    return static::rewriteTokenValues($bound, $as_family, $changed) + $tokens;
  }

  /**
   * {@inheritdoc}
   */
  protected static function revisionLogMessage(string $name): string {
    return sprintf('Removed the "%s" family from font-family stacks before its font\'s deletion.', $name);
  }

  /**
   * {@inheritdoc}
   */
  protected static function failureMessage(): TranslatableMarkup {
    return new TranslatableMarkup('Deleting the font failed; its references keep naming the family. Re-run the delete to finish.');
  }

  /**
   * {@inheritdoc}
   */
  protected static function successMessage(string $label, int $rewritten): TranslatableMarkup {
    return new TranslatableMarkup('Removed %label from font-family stacks in @count places and deleted it.', [
      '%label' => $label,
      '@count' => $rewritten,
    ]);
  }

}
