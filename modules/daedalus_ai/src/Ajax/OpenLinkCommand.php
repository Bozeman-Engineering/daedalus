<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Ajax;

/**
 * AJAX command to open a URL in a new browser tab.
 *
 * End-of-turn: the client handler navigates the current document, which
 * would kill the continuation loop mid-turn, so the response listener holds
 * it until the turn's final response. It sorts AFTER TriggerDeferredCommand
 * so the deferred batch is stashed in localStorage before navigation.
 */
class OpenLinkCommand implements EndOfTurnCommandInterface {

  /**
   * Constructs an OpenLinkCommand.
   *
   * @param string $url
   *   The URL to open.
   * @param string $title
   *   The link title for display.
   */
  public function __construct(
    protected readonly string $url,
    protected readonly string $title,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'daedalusAiOpenLink',
      'url' => $this->url,
      'title' => $this->title,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function endOfTurnOrder(): int {
    return 10;
  }

}
