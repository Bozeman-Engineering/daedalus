<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\FieldHandler;

/**
 * A media reference field handler that can place a file the conversation holds.
 *
 * The one file-placement vocabulary, stated by the handler and read by
 * every door: the JSON door composes its {_file, alt_text} grammar around
 * it, the page language its file="" attribute (DialectCatalogBuilder
 * publishes the attribute only while the field's handler implements
 * this). The guidance is door-neutral on purpose: it says what a name is
 * and what placing does, never how either door spells the write.
 */
interface MediaFromFileInterface {

  /**
   * What to write as the file name, and what placing it does.
   *
   * Door-neutral: names no key and no attribute. Says the name is the
   * handle the conversation knows the file under and that the reference
   * is final at once (the file becomes media on this write).
   */
  public function getFileGuidance(): string;

}
