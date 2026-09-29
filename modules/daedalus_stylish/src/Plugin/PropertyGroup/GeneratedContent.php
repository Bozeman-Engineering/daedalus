<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\PropertyGroup;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_stylish\Attribute\PropertyGroup;
use Drupal\daedalus_stylish\MediaToken;
use Drupal\daedalus_stylish\PropertyGroupBase;

/**
 * The existence switch of a generated box: `content`.
 *
 * A pseudo-element ("&::before", ".quote::after") exists exactly while
 * the cascade sets its `content`, so this group is granted at every
 * pseudo-element landing of the roster walk (declared or derived) and
 * nowhere else: `content` has no meaning on a real box, and a write of
 * it outside a pseudo-element is refused in every enforcement mode
 * (StyleMapValidator's STYLE_PROPERTY_PSEUDO_ONLY). Curated above the
 * wide tier's "Generated Content" area (weight 90 beats 100), whose
 * `any`-typed copy would have admitted every string.
 *
 * The grammar is `none | "" | one media reference` (Tim, before-and-after
 * fork a, revised 2026-09-26): no strings, `attr()`, counters or `quotes`
 * (text is content and belongs in a field, where it is translated and
 * searched, and generated text is read by screen readers while invisible
 * to every content surface), and no plain `url()`, gradient, `image-set()`
 * or `/ "alt"` form. The one image road is the media token,
 * `url('media:<uuid>')`, as the WHOLE value: `content` carries the media
 * flag, so it rides the same machinery as `background-image` (the picker,
 * the token gates, image styles, `_generate`, preview and delivery) and
 * costs no second road. Accepted and documented rather than guarded: a
 * content image renders as replaced content at its intrinsic size (an
 * image style is the way to size it; `background-image` on the same box
 * sizes, positions and layers), and it is exposed to assistive tech as an
 * unlabelled image. The closure is otherwise exact: the base class's
 * pass-throughs (CSS-wide keywords, `var()`, `calc()`) are not admitted,
 * since a token of text shape could smuggle a string.
 */
#[PropertyGroup(
  id: 'generated_content',
  label: new TranslatableMarkup('Generated content'),
  weight: 90,
  pseudo: TRUE,
)]
final class GeneratedContent extends PropertyGroupBase {

  /**
   * The one property: the existence switch.
   */
  public const CONTENT = 'content';

  /**
   * The box present and empty: the value a generated box is born with.
   *
   * Two quote characters, the spelling the validator accepts and the
   * birth seed DaedalusStylishOperationHandler writes at "default" when
   * an op creates a pseudo-element entry that carries no content.
   */
  public const BOX = '""';

  /**
   * No box: the deliberate way to make a generated box disappear.
   */
  public const NONE = 'none';

  /**
   * The closed keyword list: the box present and empty, or absent.
   *
   * The third spelling, one media reference, is a token and never a
   * keyword; it is judged by the media gates, not by this list.
   */
  public const VALUES = [self::BOX, self::NONE];

  /**
   * {@inheritdoc}
   */
  public function getProperties(): array {
    return [
      self::CONTENT => [
        'label' => $this->t('Content'),
        'type' => 'keyword',
        'keywords' => self::VALUES,
        'media' => TRUE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * The closed grammar, verbatim: `""` or `none` (case-insensitive on
   * the keyword), or exactly one media token and nothing beside it,
   * whatever constraints ride along. The token is handed to the base
   * class, whose media gates judge it (uuid shape, the media exists, its
   * source is an image or vector) before substituting `none` for it and
   * landing on the keyword grammar; every other value, a second token or
   * anything beside the one included, is refused here with the three
   * spellings.
   */
  public function validate(string $property, string $value, array $constraints = []): array {
    if ($property !== self::CONTENT) {
      return parent::validate($property, $value, $constraints);
    }
    $value = trim($value);
    if ($value === self::BOX || strtolower($value) === self::NONE) {
      return [];
    }
    if (MediaToken::mentions($value) && count(MediaToken::extract($value)) === 1 && trim(MediaToken::substitute($value, static fn (): string => '')) === '') {
      return parent::validate($property, $value, $constraints);
    }
    return [
      new BlueprintError(
        $property,
        'INVALID_STYLE_VALUE',
        sprintf('"%s" is not a valid value for content.', $value),
        ['keywords' => self::VALUES],
        'content is the existence switch of a generated box and takes exactly "" (the box exists, empty), none (no box) or one media reference url(\'media:<uuid>\') as the whole value (the image renders at its own size; an image style sizes it, and background-image on the same box sizes, positions and layers). Nothing else passes. Text belongs in a field, not in generated content (it is translated, searched and read aloud there); a plain url(), a gradient, image-set(), an alt suffix or a second reference is refused.',
      ),
    ];
  }

}
