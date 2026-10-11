<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Translates a host's style map into rendered output.
 *
 * The style map always stores semantic CSS values; backends translate at
 * render time, so switching backends is a service swap
 * (the daedalus_stylish.style_backend alias), never a data migration. The shipped
 * backend prints @scope CSS into an inline <style> element; a utility-class
 * backend (e.g. Tailwind) would instead stamp classes on elements through
 * other seams and return nothing here.
 */
interface StyleBackendInterface {

  /**
   * Builds the render addition that delivers a host's styles.
   *
   * Called from the render root's delivery (hook_entity_view() for an
   * entity, the canvas root alter for a template host), and the result is
   * embedded in that root's own build — so it rides the render cache
   * (invalidated by ordinary entity tags on style changes) and travels with
   * subtree swaps, which is how every mutation path (widgets, code editor,
   * AI) delivers updated CSS without a dedicated field in the response
   * contract. A template's shared element styles ride the page head once
   * per display instead (daedalus_stylish_entity_view()).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose styles are delivered: the map's owner, whose roster
   *   resolves the values and whose uuid identifies the tag.
   * @param array $map
   *   The decoded, non-empty style map to print.
   *
   * @return array
   *   A render array, or [] when this backend delivers styles another way
   *   (or nothing in the map is printable).
   */
  public function build(HostInterface $host, array $map): array;

}
