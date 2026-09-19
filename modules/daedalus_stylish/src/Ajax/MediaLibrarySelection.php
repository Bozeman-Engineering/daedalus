<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Delivers a media library selection to the waiting style picker.
 *
 * The one selection command of the daedalus_stylish opener contract: the client-side
 * handler (js/src/media-library.js) routes it to the picker that opened the
 * library. The payload is everything a pick needs and nothing more — the
 * semantic token the picker commits through update_style, the uuid it keys
 * the client's uuid -> URL table by, and the server-resolved preview URL so
 * the staged preview is instant (NULL when the media resolves to no image
 * file; the client then degrades the preview to "none", exactly like the
 * server's emit gate).
 *
 * @see \Drupal\daedalus_stylish\DaedalusStylishMediaLibraryOpener::getSelectionResponse()
 * @see js/src/media-library.js
 */
class MediaLibrarySelection implements CommandInterface {

  /**
   * Constructs the selection command.
   *
   * @param string $uuid
   *   The selected media entity's uuid, lowercased.
   * @param string $token
   *   The canonical style token, url('media:<uuid>').
   * @param string|null $url
   *   The resolved preview URL, or NULL when the media has no image file.
   */
  public function __construct(
    protected string $uuid,
    protected string $token,
    protected ?string $url,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'daedalusStylishMediaLibrarySelection',
      'uuid' => $this->uuid,
      'token' => $this->token,
      'url' => $this->url,
    ];
  }

}
