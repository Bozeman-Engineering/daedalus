<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * AJAX command to set a browser cookie.
 */
class SetCookieCommand implements CommandInterface {

  /**
   * Constructs a SetCookieCommand.
   *
   * @param string $name
   *   The cookie name.
   * @param string $value
   *   The cookie value.
   * @param string $path
   *   The cookie path.
   */
  public function __construct(
    protected readonly string $name,
    protected readonly string $value,
    protected readonly string $path = '/',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(): array {
    return [
      'command' => 'daedalusUiSetCookie',
      'name' => $this->name,
      'value' => $this->value,
      'path' => $this->path,
    ];
  }

}
