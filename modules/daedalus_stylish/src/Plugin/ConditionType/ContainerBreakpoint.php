<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\ConditionType;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\ConditionType;
use Drupal\daedalus_stylish\ConditionTypeBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Container breakpoints: the page's width band, measured at one query root.
 *
 * The primary responsive axis. Semantically these are media queries against
 * the page the user is looking at; @container is only the MECHANISM,
 * because the edit-mode canvas is a constrained div and @media would read
 * the browser viewport (wrong when posing, and when sidebars shrink the
 * canvas). Every band measures the ONE page-level query root
 * (CssPrinter::PAGE_CONTAINER_SELECTOR live; the posed stage wins by
 * nearest-ancestor resolution in edit mode), never the styled element's
 * own enclosing column, so what the posed stage shows is exactly what a
 * matching page width delivers.
 *
 * The bands are base-at-top: the unconditioned (default) styles are the
 * canonical full-width look, and the conditions are downward adaptations
 * as the page tightens. "narrow" runs up to its ceiling, "medium"
 * is a true range from narrow + 1 through its own ceiling (the lower
 * edge derives, so the bands always tile exactly), and base is
 * everything above: no condition exists for it. Thresholds are
 * site-configurable (daedalus_stylish.settings breakpoints: the two ceilings).
 */
#[ConditionType(
  id: 'container_breakpoint',
  label: new TranslatableMarkup('Container breakpoint'),
  axis: 'width',
  weight: 20,
)]
final class ContainerBreakpoint extends ConditionTypeBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('config.factory'));
  }

  /**
   * {@inheritdoc}
   */
  public function getConditions(): array {
    $breakpoints = $this->configFactory->get('daedalus_stylish.settings')->get('breakpoints') ?? [];
    $narrow = (int) ($breakpoints['narrow'] ?? 767);
    $medium = (int) ($breakpoints['medium'] ?? 1023);
    return [
      'narrow' => [
        'label' => $this->t('Narrow'),
        'wrapper' => sprintf('@container (max-width: %dpx)', $narrow),
        'band' => ['max' => $narrow],
      ],
      'medium' => [
        'label' => $this->t('Medium'),
        'wrapper' => sprintf('@container (min-width: %dpx) and (max-width: %dpx)', $narrow + 1, $medium),
        'band' => ['min' => $narrow + 1, 'max' => $medium],
      ],
    ];
  }

}
