<?php

declare(strict_types=1);

namespace Drupal\daedalus_tempstore\History;

/**
 * A staged write whose snapshot is larger than a history entry may be.
 *
 * Thrown by the recorder before the write lands, so the staged state and
 * its timeline stay in step: nothing was staged and nothing was recorded.
 */
class HistoryEntryRefusedException extends \RuntimeException {

  /**
   * Builds the refusal for a snapshot of a given size.
   *
   * @param int $bytes
   *   The compressed snapshot's size.
   * @param int $ceiling
   *   The largest size an entry may have.
   */
  public static function tooLarge(int $bytes, int $ceiling): self {
    return new self(sprintf('The unsaved copy is too large to keep (%d bytes compressed, the ceiling is %d). Nothing was changed.', $bytes, $ceiling));
  }

}
