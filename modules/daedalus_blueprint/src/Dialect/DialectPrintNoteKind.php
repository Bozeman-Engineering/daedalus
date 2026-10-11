<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

/**
 * The kinds of thing a print holds outside the authored grammar.
 *
 * A note's kind names the grain of the gap, which is what decides how
 * the thing prints in the read-only register and what a reader is told
 * about it: a section whose layout is not the container prints its
 * regions as facts; a field the language cannot spell is named, not
 * printed; a field printed with less than it holds is marked partial; a
 * style entry the grammar cannot spell rides as a comment; what a slot
 * door shows (another document, by reference: HostPrinter's read of the
 * spine's showing) is no fact of the printed document at all, so it is
 * told, one line per door, and never printed. The cases are closed on
 * purpose: a reading note per kind present is how every consumer of a
 * print spells the same teaching, so a new kind is a new case AND a new
 * sentence.
 */
enum DialectPrintNoteKind: string {

  case Layout = 'layout';
  case UnprintedField = 'unprinted_field';
  case PartialField = 'partial_field';
  case UnprintedStyle = 'unprinted_style';
  case Showing = 'showing';

}
