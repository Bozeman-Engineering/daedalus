<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\Presentation;

/**
 * A node's presentation decision: how the page builder presents it.
 *
 * Three modes:
 * - OPEN: children shown; structural containers (sections, regions,
 *   multi-field blocks like a CTA).
 * - UNIT: one element; composition exists and stays reachable by explicit
 *   drill-in, hidden by default (a shipped CTA, caption-bearing media).
 * - MERGED: one element with NO separate inside; the wrapper chain is
 *   absorbed into a single node. The canonical key is the OUTERMOST carrier
 *   (the block placement, so move/trash/duplicate/drag all arm); the edit
 *   target is the INNERMOST leaf; verbs are the union.
 */
final class Presentation {

  public const OPEN = 'open';
  public const UNIT = 'unit';
  public const MERGED = 'merged';

  /**
   * The DOM carrier: stamped at attribution beside the selectable key.
   *
   * Carries 'unit' or 'merged' only; OPEN stamps nothing, so the
   * attribute's presence means "presents as one element". The canvas
   * resolver reads this; the panel reads the delivered blueprint's
   * `_element.presentation` annotations. The same policy decided both, so
   * they agree by construction.
   */
  public const ATTRIBUTE = 'data-presentation';

  /**
   * Constructs a Presentation.
   *
   * @param string $mode
   *   One of the mode constants.
   * @param string|null $label
   *   For MERGED: the label the single node presents under (the absorbed
   *   content's definition label, e.g. "Text", "Image"). NULL means the
   *   node keeps its own label (e.g. the component's configuration label).
   * @param string|null $editTarget
   *   The innermost leaf editing routes to — a field machine name, or
   *   'label' when the displayed configuration label is the content
   *   (heading-style blocks). Set for MERGED nodes, and on the OPEN root
   *   node when its label field presents as the entity row
   *   (ElementModel::rootPresentation). NULL when nothing routable exists.
   */
  public function __construct(
    public readonly string $mode,
    public readonly ?string $label = NULL,
    public readonly ?string $editTarget = NULL,
  ) {}

  /**
   * The annotation array shape delivered on blueprint nodes.
   *
   * @return array
   *   Associative array with 'mode' and, when set, 'label' / 'edit_target'.
   */
  public function toArray(): array {
    $annotation = ['mode' => $this->mode];
    if ($this->label !== NULL) {
      $annotation['label'] = $this->label;
    }
    if ($this->editTarget !== NULL) {
      $annotation['edit_target'] = $this->editTarget;
    }
    return $annotation;
  }

}
