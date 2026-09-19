<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Ajax;

use Drupal\Core\Ajax\CommandInterface;
use Drupal\Core\Ajax\CommandWithAttachedAssetsInterface;
use Drupal\Core\Ajax\CommandWithAttachedAssetsTrait;

/**
 * AJAX command for surgical element replacement.
 *
 * Renders the full content, then on the client extracts and replaces only the
 * elements matching the given selectors. Avoids full-page replacement which
 * causes layout reflow and scroll position loss.
 */
class UpdateElement implements CommandInterface, CommandWithAttachedAssetsInterface {

  use CommandWithAttachedAssetsTrait;

  /**
   * Constructs an UpdateElement command.
   *
   * @param array|string $content
   *   A render array or pre-rendered HTML string of the full entity/layout
   *   content.
   * @param array $selectors
   *   CSS selectors for elements to extract from the rendered content and
   *   replace on the live page.
   */
  public function __construct(
    protected array|string $content,
    protected array $selectors,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'updateElement',
      'content' => $this->getRenderedContent(),
      'selectors' => $this->selectors,
    ];
  }

}
