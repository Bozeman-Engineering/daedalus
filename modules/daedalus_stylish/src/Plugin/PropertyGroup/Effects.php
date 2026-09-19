<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\PropertyGroup;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\PropertyGroup;
use Drupal\daedalus_stylish\PropertyGroupBase;

/**
 * Transforms and opacity: how an element is displaced, scaled, and faded.
 *
 * The minimal curated home of the effects a motion treatment moves
 * through. The hover axis's Lift lifts with a transform, an entrance
 * override wants opacity, and neither may hang off the wide tier: curating
 * them (weight 80 beats wide:* at 100 in (weight, id) order, so this
 * group takes transform and the individual transform properties from the
 * wide Transforms derivative and opacity from the wide Color one) makes
 * them advanced-mode rows and strict-site declarable. Filters, blend
 * modes, and clip paths stay wide-tier.
 *
 * transform and the individual transform properties (translate, scale,
 * rotate) are 'text': multi-part grammars the client's csstree lints.
 * opacity is a bare number whose range is the property's own grammar
 * (the browser clamps to 0..1), so the definition carries the bound.
 */
#[PropertyGroup(
  id: 'effects',
  label: new TranslatableMarkup('Effects'),
  weight: 80,
)]
final class Effects extends PropertyGroupBase {

  /**
   * {@inheritdoc}
   */
  public function getProperties(): array {
    return [
      'transform' => [
        'label' => $this->t('Transform'),
        'type' => 'text',
        'example' => 'translateY(-4px) scale(1.02)',
        'keywords' => ['none'],
      ],
      'translate' => ['label' => $this->t('Translate'), 'type' => 'text', 'example' => '0 -4px', 'keywords' => ['none']],
      'scale' => ['label' => $this->t('Scale'), 'type' => 'text', 'example' => '1.02', 'keywords' => ['none']],
      'rotate' => ['label' => $this->t('Rotate'), 'type' => 'text', 'example' => '3deg', 'keywords' => ['none']],
      'opacity' => ['label' => $this->t('Opacity'), 'type' => 'number', 'max' => 1],
    ];
  }

}
