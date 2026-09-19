<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\PropertyGroup;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\PropertyGroup;
use Drupal\daedalus_stylish\PropertyGroupBase;

/**
 * Transitions and animations: how an element moves between and into states.
 *
 * The curated home of transition-* and animation-*, the two wearing halves
 * of motion (Carbon's word: the `transition` token scale is the site's
 * motion tokens). Curating them takes ownership away from the wide tier's
 * Animations and Transitions derivatives (weight 80 beats wide:* at 100
 * in (weight, id) order), which makes them advanced-mode rows, strict-site
 * declarable, and gives animation-name the `animation_name` type the
 * animation material's reference grammar hangs on: a motion-{name} ident
 * names a site animation (StyleGuide::animationName()), validated against
 * the published roster and offered from it.
 *
 * Shorthands and easing functions are 'text' (multi-part grammars the
 * client's csstree lints); the longhands are typed so the validator and
 * the value space read them exactly.
 *
 * The WHEN of an animation is a trigger, a property rather than a
 * condition (a condition is a parameterless name; a trigger carries how
 * far in and once-or-every-time). The two shorthands of the CSS Animation
 * Triggers draft are curated verbatim: timeline-trigger names a trigger
 * and its activation range (`--in-view view() entry 20% exit 100%`: the
 * end point is part of the range, since a lone start natively activates
 * only while the element crosses its entry band and an element already
 * in view would never play), and animation-trigger binds the element's
 * animation to it by name (`--in-view play-once`). Their longhands stay
 * wide-tier until the draft's names settle. Where the browser lacks
 * animation-trigger, the printer's @supports hold and the
 * daedalus_stylish/motion_triggers runtime honor the same declarations.
 */
#[PropertyGroup(
  id: 'motion',
  label: new TranslatableMarkup('Motion'),
  weight: 80,
)]
final class Motion extends PropertyGroupBase {

  /**
   * The easing keywords transition- and animation-timing-function share.
   */
  private const EASINGS = ['ease', 'linear', 'ease-in', 'ease-out', 'ease-in-out', 'step-start', 'step-end'];

  /**
   * {@inheritdoc}
   */
  public function getProperties(): array {
    return [
      'transition' => [
        'label' => $this->t('Transition'),
        'type' => 'text',
        'example' => 'color 150ms ease-out',
        'keywords' => ['none'],
      ],
      'transition-property' => [
        'label' => $this->t('Transition property'),
        'type' => 'ident',
        'list' => TRUE,
        'keywords' => ['none', 'all'],
      ],
      'transition-duration' => ['label' => $this->t('Transition duration'), 'type' => 'dimension', 'list' => TRUE],
      'transition-delay' => [
        'label' => $this->t('Transition delay'),
        'type' => 'dimension',
        'list' => TRUE,
        'negative' => TRUE,
      ],
      'transition-timing-function' => [
        'label' => $this->t('Transition easing'),
        'type' => 'text',
        'example' => 'cubic-bezier(0.4, 0, 0.2, 1)',
        'keywords' => self::EASINGS,
      ],
      'transition-behavior' => [
        'label' => $this->t('Transition behavior'),
        'type' => 'keyword',
        'list' => TRUE,
        'keywords' => ['normal', 'allow-discrete'],
      ],
      'animation' => [
        'label' => $this->t('Animation'),
        'type' => 'text',
        'example' => 'motion-fade_in 600ms ease-out both',
        'keywords' => ['none'],
      ],
      'animation-name' => [
        'label' => $this->t('Animation name'),
        'type' => 'animation_name',
        'list' => TRUE,
        'keywords' => ['none'],
      ],
      'animation-duration' => [
        'label' => $this->t('Animation duration'),
        'type' => 'dimension',
        'list' => TRUE,
        'keywords' => ['auto'],
      ],
      'animation-delay' => [
        'label' => $this->t('Animation delay'),
        'type' => 'dimension',
        'list' => TRUE,
        'negative' => TRUE,
      ],
      'animation-timing-function' => [
        'label' => $this->t('Animation easing'),
        'type' => 'text',
        'example' => 'cubic-bezier(0.4, 0, 0.2, 1)',
        'keywords' => self::EASINGS,
      ],
      'animation-iteration-count' => [
        'label' => $this->t('Animation iterations'),
        'type' => 'number',
        'list' => TRUE,
        'keywords' => ['infinite'],
      ],
      'animation-direction' => [
        'label' => $this->t('Animation direction'),
        'type' => 'keyword',
        'list' => TRUE,
        'keywords' => ['normal', 'reverse', 'alternate', 'alternate-reverse'],
      ],
      'animation-fill-mode' => [
        'label' => $this->t('Animation fill mode'),
        'type' => 'keyword',
        'list' => TRUE,
        'keywords' => ['none', 'forwards', 'backwards', 'both'],
      ],
      'animation-play-state' => [
        'label' => $this->t('Animation play state'),
        'type' => 'keyword',
        'list' => TRUE,
        'keywords' => ['running', 'paused'],
      ],
      'animation-composition' => [
        'label' => $this->t('Animation composition'),
        'type' => 'keyword',
        'list' => TRUE,
        'keywords' => ['replace', 'add', 'accumulate'],
      ],
      'timeline-trigger' => [
        'label' => $this->t('Trigger'),
        'type' => 'text',
        'example' => '--in-view view() entry 20% exit 100%',
        'keywords' => ['none'],
      ],
      'animation-trigger' => [
        'label' => $this->t('On trigger'),
        'type' => 'text',
        'example' => '--in-view play-once',
        'keywords' => ['none'],
      ],
    ];
  }

}
