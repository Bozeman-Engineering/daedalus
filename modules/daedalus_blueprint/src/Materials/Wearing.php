<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * One variant an element wears, with the origin of the wearing.
 *
 * A worn reference is a material plus a placement fact: the element wears
 * the variant AT ITS ROOT (the editor applied it here), VIA a composition
 * (that composition's apply distributed the reference onto this element,
 * and switching or removing the composition on its root takes it away
 * again), or as a FRAGMENT of a composition (the element carries one slot
 * of the composition's captured subtree). The record is the roster's fact
 * about the variant; the rest is the element's, read from its style
 * stack's reference layers (ElementMaterials::worn()), so a surface that
 * names what an element wears can say where each wearing came from
 * instead of reading every reference as applied here.
 */
final class Wearing {

  /**
   * Constructs a wearing.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord $record
   *   The worn variant's roster record.
   * @param string $fragment
   *   The composition slot key this element wears ('' for a root wearing).
   * @param string|null $via
   *   The machine name of the composition whose apply distributed this
   *   wearing onto the element, or NULL for a wearing applied here.
   * @param string|null $viaLabel
   *   That composition's roster label, or NULL when there is no origin or
   *   the origin no longer resolves (a stale origin is inert, never hidden).
   */
  public function __construct(
    public readonly MaterialRecord $record,
    public readonly string $fragment = '',
    public readonly ?string $via = NULL,
    public readonly ?string $viaLabel = NULL,
  ) {}

  /**
   * Whether the element wears a fragment of the variant, not its root.
   */
  public function isFragment(): bool {
    return $this->fragment !== '';
  }

}
