<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_stylish\Entity\MachineNameInterface;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;

/**
 * The daedalus_font_usage table: which entities name which font families.
 *
 * ValueUsageBase's family twin. A font is referenced by its quoted family
 * in a property-scoped slot (`font-family: "Inter", sans-serif`), never
 * through var(), so the scan is property-aware: FontFamilies::inValue()
 * finds the quoted components of a font-family value, and an unquoted
 * family (a theme's or the system's own) never counts. The family column
 * stores the name bare, verbatim as the quoted component spells it, so
 * the counts and work lists key on a font's family, not its machine name
 * (countReferences() and references() read it off the entity).
 *
 * Skin TOKENS are a source here, unlike the animation twin: a token has
 * no property of its own, but a scale the guide binds to font-family
 * (site.constraint_defaults `font-family: {scale: font}`) gives every
 * token on it that property, and the typography row wears such a token
 * as its stack. So scanTokens() reads the tokens of the font-bound
 * scales as font-family values and nothing else. The binding is read at
 * scan time, so a guide rebinding font-family to another scale leaves the
 * rows stale until the rebuild (`drush daedalus_stylish:font-usage:rebuild`), one
 * more of the accepted staleness edges the base names.
 */
class FontUsage extends ValueUsageBase {

  /**
   * The usage table name.
   */
  public const TABLE = 'daedalus_font_usage';

  /**
   * The table's material column.
   */
  public const COLUMN = 'family';

  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    EntityFieldManagerInterface $entityFieldManager,
    HostResolver $hostResolver,
    protected StyleGuide $styleGuide,
  ) {
    parent::__construct($database, $entityTypeManager, $entityFieldManager, $hostResolver);
  }

  /**
   * {@inheritdoc}
   */
  protected function scanValue(string $property, string $value): array {
    return self::valueFamilies($property, $value);
  }

  /**
   * {@inheritdoc}
   *
   * The font-bound scales' tokens, each value read as a font-family value
   * (every condition entry of a conditioned value).
   */
  protected function scanTokens(array $tokens): array {
    $names = [];
    foreach (self::fontScaleValues($tokens, $this->fontScales()) as $value) {
      array_push($names, ...self::valueFamilies('font-family', $value));
    }
    return $names;
  }

  /**
   * The token scales the guide binds to font-family.
   *
   * The site's binding (site.constraint_defaults), the one every
   * typography row reads its stacks from; a scale bound only on one node
   * is that node's business and not a site-wide family source.
   *
   * @return string[]
   *   The scale machine names; [] when nothing binds font-family.
   */
  public function fontScales(): array {
    $scale = $this->styleGuide->constraintDefaults()['font-family']['scale'] ?? NULL;
    return is_string($scale) && $scale !== '' ? [$scale] : [];
  }

  /**
   * The scalar values of a token map's font-bound scales.
   *
   * @param array $tokens
   *   A stored token layer ({scale: {name: record}}).
   * @param string[] $scales
   *   The font-bound scales (fontScales()).
   *
   * @return string[]
   *   Every stated value, a conditioned value contributing each entry.
   */
  public static function fontScaleValues(array $tokens, array $scales): array {
    $values = [];
    foreach (SkinTokensItem::statedValues($tokens) as $scale => $scale_values) {
      if (!in_array((string) $scale, $scales, TRUE)) {
        continue;
      }
      foreach ($scale_values as $value) {
        foreach (is_array($value) ? $value : [$value] as $conditioned) {
          if (is_scalar($conditioned)) {
            $values[] = (string) $conditioned;
          }
        }
      }
    }
    return $values;
  }

  /**
   * {@inheritdoc}
   *
   * Keyed by the family, the name a font-family value spells.
   */
  public function countReferences(MachineNameInterface $material): int {
    assert($material instanceof DaedalusStylishFontInterface);
    return $this->countReferencesTo($material->getFamily());
  }

  /**
   * {@inheritdoc}
   *
   * Keyed by the family, the name a font-family value spells.
   */
  public function references(MachineNameInterface $material): array {
    assert($material instanceof DaedalusStylishFontInterface);
    return $this->referencesTo($material->getFamily());
  }

  /**
   * The families one CSS value references (the scan direction).
   *
   * Reads through the one property-scoped grammar (FontFamilies), so a
   * family is extracted exactly the way delivery spells it and an
   * unquoted component never counts. A value for any other property
   * references nothing.
   *
   * @return string[]
   *   The referenced families, bare, verbatim as the quoted component
   *   spells them.
   */
  public static function valueFamilies(string $property, string $value): array {
    return FontFamilies::inValue($property, $value);
  }

  /**
   * Rewrites one family out of a CSS value.
   *
   * The write direction of the value-level grammar, the delete-in-use
   * batch's one substitution rule: every quoted component naming the
   * family leaves the stack and everything else keeps its bytes
   * (FontFamilies::removeFamily()); a stack emptied by it becomes the
   * initial value, the honest residue of a family no @font-face declares.
   */
  public static function rewriteValue(string $property, string $value, string $family): string {
    return FontFamilies::removeFamily($property, $value, $family);
  }

}
