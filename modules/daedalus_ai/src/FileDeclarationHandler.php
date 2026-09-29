<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

use Drupal\daedalus_blueprint\Dialect\FileDeclarationException;
use Drupal\daedalus_blueprint\Dialect\FileDeclarationHandlerInterface;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\daedalus_ui\File\FileLaneException;

/**
 * Saves bytes the model authors in a batch and holds them for its thread.
 *
 * The page language's file op and a document's <pb-file> declaration reach
 * this seam from the DOM operation handler. The bytes go through the one
 * file lane (validators, the FileValidationEvent chain with the SVG
 * sanitizer, the size ceiling) as a temporary file into the attachment
 * directory, narrowed to the textual kinds a model authors (an SVG, a
 * stylesheet, CSV rows, text; never binary), and the attachment store then
 * holds the file for the conversation under the DECLARED name, whatever
 * filename the lane saved it as (it renames on collision): the name is the
 * handle the model wrote and will write again (file="name",
 * url('file:name'), view_file("name")).
 *
 * The hold is on the alias overlay only (Attachments::hold(), no persist):
 * the outermost batch settles it, so a rolled-back batch leaves no name
 * behind. The file.usage row the hold registers survives a rollback (it is
 * not batch state), so a declaration whose batch failed stays held,
 * unnamed, until Clear History or the cron sweep releases the thread's
 * files; a landed declaration is media once placed and held by name until
 * then.
 */
final class FileDeclarationHandler implements FileDeclarationHandlerInterface {

  /**
   * The extensions a model may author: the lane's textual kinds minus pdf.
   *
   * Core's MIME guesses for these are the types a declaration states:
   * image/svg+xml, text/css, text/csv, text/plain, text/markdown,
   * application/json, text/html.
   */
  public const AUTHORED_EXTENSIONS = ['svg', 'css', 'csv', 'txt', 'md', 'json', 'html'];

  public function __construct(
    private readonly FileLane $lane,
    private readonly Attachments $attachments,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function declare(string $conversation, string $name, string $type, string $content): void {
    try {
      $file = $this->lane->fromBytes($name, $type, $content, Attachments::DIRECTORY, FALSE, self::AUTHORED_EXTENSIONS);
    }
    catch (FileLaneException $e) {
      throw new FileDeclarationException($e->messages, $e);
    }
    $this->attachments->hold($file, $conversation, $name);
  }

}
