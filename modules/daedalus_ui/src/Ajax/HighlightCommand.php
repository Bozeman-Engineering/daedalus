<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * AJAX command to highlight elements on the page.
 */
class HighlightCommand implements CommandInterface {

  /**
   * Constructs a HighlightCommand.
   *
   * @param array $selectors
   *   CSS selectors of elements to highlight.
   */
  public function __construct(
    protected readonly array $selectors,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'highlight',
      'selectors' => $this->selectors,
    ];
  }

}
