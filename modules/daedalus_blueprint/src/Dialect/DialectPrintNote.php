<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

/**
 * One thing a print holds that the authored grammar could not spell.
 *
 * The printer records a note wherever it printed in the read-only
 * register instead of the authored one (a data-* fact, a CSS comment),
 * so the print stays total over the data and its result says exactly
 * where it is less than the tree. The element id is the printed id the
 * note is about, NULL when the gap is not one element's (a fragment
 * whose nodes carry rules). The detail is one sentence a reader can act
 * on, spelled by the printer so every consumer repeats it alike. A
 * layout note also carries the section's region names, structured, so
 * the reading notes can tell a section with one region (its children
 * need no address) from one with several (an insert or move names the
 * region) without parsing the detail.
 */
final class DialectPrintNote {

  public function __construct(
    public readonly DialectPrintNoteKind $kind,
    public readonly ?string $elementId,
    public readonly string $detail,
    public readonly array $regions = [],
  ) {}

}
