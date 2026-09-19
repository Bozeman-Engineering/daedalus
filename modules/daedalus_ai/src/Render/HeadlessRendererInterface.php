<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Render;

/**
 * Renders a page in a headless browser and screenshots the canvas.
 */
interface HeadlessRendererInterface {

  /**
   * Renders a URL and returns a PNG screenshot of the stage.
   *
   * @param string $url
   *   The absolute page URL, canvas vector boot parameters included.
   * @param array $cookies
   *   Cookies to set before loading the page, as {name, value} arrays.
   * @param int|null $canvas_width
   *   The posed canvas width in pixels, or NULL for the full canvas.
   *
   * @return array{png: string, width: int, height: int, truncated: bool}
   *   The PNG binary, its pixel dimensions, and whether the page was
   *   taller than the capture ceiling.
   *
   * @throws \Drupal\daedalus_ai\Render\HeadlessRenderException
   *   When rendering is unavailable or fails.
   */
  public function render(string $url, array $cookies, ?int $canvas_width = NULL): array;

}
