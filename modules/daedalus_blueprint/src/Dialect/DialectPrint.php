<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Dialect;

/**
 * The result of printing a tree, a fragment or a subtree's styles.
 *
 * The text is the print itself. The notes say where the text spells
 * something in the read-only register (data-* facts, CSS comments)
 * because the authored grammar has no spelling for it, or holds a fact of
 * the document the text cannot carry at all (what a slot door shows, from
 * the spine's showing: HostPrinter adds those): a print with no notes is
 * wholly authored, every spelling in it one the model may write back
 * verbatim, which is the contract a write template needs. A print
 * read by the model needs no such promise, only the text and what the
 * notes teach about it: the reading notes (one per kind of thing
 * present) head the text in forReader(), composed here so every reader
 * of a page (the page tools, the per-turn print) spells the teaching
 * alike and the cached prompt never has to.
 */
final class DialectPrint {

  /**
   * The reading notes' heading line.
   */
  protected const READING_NOTES_HEADER = 'Reading notes:';

  /**
   * Constructs a print result.
   *
   * @param string $text
   *   The print: a document, a fragment, or <style> content, in the
   *   printer's canonical form.
   * @param \Drupal\daedalus_blueprint\Dialect\DialectPrintNote[] $notes
   *   What the text holds outside the authored grammar, in print order.
   */
  public function __construct(
    public readonly string $text,
    public readonly array $notes = [],
  ) {}

  /**
   * Whether every spelling in the text is one the authored grammar owns.
   *
   * TRUE when the print carries no notes: the text is a write template,
   * parse(text) yields the tree it printed from.
   */
  public function isAuthored(): bool {
    return $this->notes === [];
  }

  /**
   * The sentences that teach a reader what the read-only register holds.
   *
   * One per kind of note present, in a fixed order: the layout sentence
   * (teaching at: {ref, position, region} and both refusal codes when a
   * section has more than one region; a single-region section needs no
   * address), the unprinted-field sentence (a set keeps such a field, a
   * replace loses it), the partial-field sentence (a set on that
   * attribute replaces the whole stored value) and the unprinted-style
   * sentence (style and set write only what they name, so the entry
   * stays); then the showing notes, which are not a teaching but the
   * facts themselves, one line per slot door of the printed document
   * saying what it shows and where that is edited (HostPrinter). Empty
   * for an authored print.
   *
   * @return string
   *   The heading, one bulleted sentence per kind and one bulleted line
   *   per door, newline-separated, or '' when the print carries no notes.
   */
  public function readingNotes(): string {
    if ($this->notes === []) {
      return '';
    }
    $kinds = [];
    $multi_region = FALSE;
    foreach ($this->notes as $note) {
      $kinds[$note->kind->value] = TRUE;
      if ($note->kind === DialectPrintNoteKind::Layout && count($note->regions) > 1) {
        $multi_region = TRUE;
      }
    }
    $lines = [self::READING_NOTES_HEADER];
    if (isset($kinds[DialectPrintNoteKind::Layout->value])) {
      $lines[] = $multi_region
        ? '- A <pb-container> carrying data-layout is a section on that layout rather than the container: its children print directly under it, data-regions lists its regions and each child\'s data-region names the one it sits in. An insert or move into such a section names the region as at: {ref, position, region}; afterbegin or beforeend on it without a region is refused (REGION_REQUIRED), a region anywhere else is refused too (REGION_NOT_APPLICABLE), and nothing creates such a section.'
        : '- A <pb-container> carrying data-layout is a section on that layout rather than the container, with one region, so its children print directly under it and at needs no region.';
    }
    if (isset($kinds[DialectPrintNoteKind::UnprintedField->value])) {
      $lines[] = '- data-unprinted names fields the element holds that the page language cannot spell: a set on the element keeps them, a replace rebuilds the element and loses them.';
    }
    if (isset($kinds[DialectPrintNoteKind::PartialField->value])) {
      $lines[] = '- data-partial names printed attributes whose stored value holds more than the print shows (a link\'s title or options): a set on that attribute replaces the whole stored value with what you write.';
    }
    if (isset($kinds[DialectPrintNoteKind::UnprintedStyle->value])) {
      $lines[] = '- A /* comment */ in the <style> block names a style entry on that element the page language cannot spell; style and set write only what they name, so it stays as it is.';
    }
    foreach ($this->notes as $note) {
      if ($note->kind === DialectPrintNoteKind::Showing) {
        $lines[] = '- ' . $note->detail;
      }
    }
    return implode("\n", $lines);
  }

  /**
   * The print as the model reads it: the reading notes, then the text.
   *
   * An authored print is its text alone; a print holding anything the
   * grammar cannot spell is headed by the sentences that teach it, once,
   * a blank line between them and the document.
   */
  public function forReader(): string {
    $notes = $this->readingNotes();
    return $notes === '' ? $this->text : $notes . "\n\n" . $this->text;
  }

}
