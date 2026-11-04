<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinAnimationsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;

/**
 * The daedalus_skin_material_usage table: what references which skin material.
 *
 * ValueUsageBase's twin for the materials that live on the skin, all three
 * sections in ONE table: a token delivers as a custom property
 * (StyleGuide::tokenVariable(), --{prefix}-{name}) and is referenced as
 * var() of it anywhere a value string appears, self-delimiting and
 * property-blind; an animation delivers as @keyframes motion-{name}
 * (StyleGuide::animationName()) and is referenced as that bare ident in
 * a property-scoped slot (animation-name, or a name component of the
 * animation shorthand), so the scan is property-aware there
 * (KeyframesNames::inValue()) and keeps the idents in the motion
 * namespace alone (a theme's own keyframes is no skin material); a font
 * delivers as @font-face blocks and is referenced by its QUOTED family in
 * the property-scoped font slot (`font-family: "Inter", sans-serif`,
 * FontFamilies::inValue()), an unquoted family being a theme's or the
 * system's own, which the spine does not curate. The material column
 * stores the REFERENCE FORM verbatim as the value spells it ("--space-4",
 * "motion-fade_in", "\"Inter\"" with its quotes), never resolved to a
 * scale and name: which scale a variable belongs to is the guide's
 * answer, and the guide can change while the saved values do not, so a
 * verbatim row stays a pure function of the values; the three forms
 * cannot collide (a custom property opens with two dashes, a keyframes
 * ident with the motion prefix, a family with a quote), which is what
 * lets one column carry all three. EVERY custom property is tracked, a
 * theme's own included (there is no reserved namespace beside the
 * scales'); such a row simply matches no token.
 *
 * Skin TOKENS and ANIMATIONS are sources too: a token may reference
 * another token of its skin, and a keyframe step may reference a token
 * (both var(), property-less, scanTokens() / scanAnimations()); a
 * keyframes name cannot live in either (the property-scoped slot
 * decision, and a step refuses animation-name). A FAMILY lives in a token
 * only on the scale the guide binds to font-family (site.constraint_defaults
 * `font-family: {scale: font}`), which gives every token on it that
 * property: scanTokens() reads those as font-family values. The binding is
 * read at scan time, so a guide rebinding font-family to another scale
 * leaves the rows stale until the rebuild
 * (`drush daedalus_stylish:skin-material-usage:rebuild`), one more of the
 * accepted staleness edges the base names.
 *
 * Lookups (the usage count behind a card, the delete-in-use work list)
 * key on the material's reference form, which the skin material manager
 * resolves (SkinMaterialManager::variable(), StyleGuide::animationName(),
 * StyleGuide::fontFamily()).
 */
class SkinMaterialUsage extends ValueUsageBase {

  /**
   * The usage table name.
   */
  public const TABLE = 'daedalus_skin_material_usage';

  /**
   * The table's material column.
   */
  public const COLUMN = 'material';

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
   *
   * A var() means the same thing under every property; a keyframes name
   * only inside the two motion properties; a family only inside
   * font-family.
   */
  protected function scanValue(string $property, string $value): array {
    return [
      ...self::valueVariables($value),
      ...self::valueAnimations($property, $value),
      ...self::valueFamilies($property, $value),
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Token values are scalars or condition-keyed scalar maps inside the
   * stored records ({scale: {name: {value, ...}}}), one level flatter
   * than style entries; every value may carry a var(), and the values of
   * the font-bound scales carry families too.
   */
  protected function scanTokens(array $tokens): array {
    $names = [];
    $font_scales = $this->fontScales();
    foreach (SkinTokensItem::statedValues($tokens) as $scale => $scale_values) {
      $bound = in_array((string) $scale, $font_scales, TRUE);
      foreach ($scale_values as $value) {
        foreach (is_array($value) ? $value : [$value] as $conditioned) {
          if (is_scalar($conditioned)) {
            array_push($names, ...self::valueVariables((string) $conditioned));
            if ($bound) {
              array_push($names, ...self::valueFamilies('font-family', (string) $conditioned));
            }
          }
        }
      }
    }
    return $names;
  }

  /**
   * {@inheritdoc}
   *
   * Keyframe steps are property maps under offsets inside the stored
   * records ({name: {steps: {offset: {property: value}}}}); a var() in a
   * step is a token reference, a font-family step names families, and
   * nothing else in a step can reference a skin material (the steps seam
   * refuses animation-name).
   */
  protected function scanAnimations(array $animations): array {
    $names = [];
    foreach (SkinAnimationsItem::statedSteps($animations) as $steps) {
      foreach ($steps as $declarations) {
        if (!is_array($declarations)) {
          continue;
        }
        foreach ($declarations as $property => $value) {
          if (is_scalar($value)) {
            array_push($names, ...self::valueVariables((string) $value), ...self::valueFamilies((string) $property, (string) $value));
          }
        }
      }
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
   * The quoted families one CSS value references, in the reference form.
   *
   * Reads through the one property-scoped grammar (FontFamilies::
   * inValue()), so a family is extracted exactly the way delivery spells
   * it, and spells each the one way (StyleGuide::fontFamily(): double
   * quotes, escapes) so a stack's `'Inter'` and `"Inter"` are one row.
   * An unquoted component never counts; a value for any other property
   * references nothing.
   *
   * @return string[]
   *   The referenced families, quoted ("\"Inter\"").
   */
  public static function valueFamilies(string $property, string $value): array {
    return array_map(static fn (string $family): string => StyleGuide::fontFamily($family), FontFamilies::inValue($property, $value));
  }

  /**
   * The custom properties one CSS value references.
   *
   * Reads through the one value-level grammar (ValueUsageBase::VAR_PATTERN):
   * every var() is a candidate token reference, whatever scale, if any,
   * its variable resolves to at lookup.
   *
   * @return string[]
   *   The custom properties, leading dashes included, verbatim as the
   *   value spells them.
   */
  public static function valueVariables(string $value): array {
    if (!str_contains($value, 'var(')) {
      return [];
    }
    preg_match_all(self::VAR_PATTERN, $value, $matches);
    return $matches[1];
  }

  /**
   * The keyframes names one CSS value references, in the motion namespace.
   *
   * Reads through the one property-scoped grammar (KeyframesNames) and the
   * one namespace rule (StyleGuide::animationReference()), so a name
   * outside the motion namespace (a theme's own keyframes) never counts
   * and a name is extracted exactly the way validation and delivery would
   * resolve it. A value for any other property references nothing.
   *
   * @return string[]
   *   The keyframes names, verbatim as the value spells them
   *   ("motion-fade_in").
   */
  public static function valueAnimations(string $property, string $value): array {
    $names = [];
    foreach (KeyframesNames::inValue($property, $value) as $ident) {
      if (StyleGuide::animationReference($ident) !== NULL) {
        $names[] = $ident;
      }
    }
    return $names;
  }

  /**
   * Respells one custom property's var() references in a CSS value.
   *
   * The token rename's rewrite: the variable inside every var() that
   * opens with it becomes the new variable and nothing else moves, the
   * fallback included (the reference is not gone, it has a new name). The
   * scan's grammar guarantees the match is the whole property name: what
   * follows it is the expression's comma or its closing parenthesis.
   */
  public static function renameValue(string $value, string $variable, string $replacement): string {
    if (!str_contains($value, 'var(')) {
      return $value;
    }
    return preg_replace(
      '/(var\(\s*)' . preg_quote($variable, '/') . '(?=\s*[,)])/',
      '${1}' . $replacement,
      $value,
    );
  }

  /**
   * Rewrites one keyframes name in a motion value, in place.
   *
   * The animation rename's and delete's one substitution rule
   * (KeyframesNames::replaceName()): every name component spelling the
   * keyframes name becomes the replacement (the new keyframes name on a
   * rename, `none` on a delete: the honest residue, the element keeps its
   * timing declarations and animates nothing), in whichever position the
   * property's grammar puts it, and everything else in the value is
   * preserved byte for byte.
   */
  public static function replaceAnimation(string $property, string $value, string $keyframes, string $replacement): string {
    return KeyframesNames::replaceName($property, $value, $keyframes, $replacement);
  }

}
