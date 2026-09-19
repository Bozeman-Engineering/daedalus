<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\EventSubscriber;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\Validation\FileValidationEvent;
use enshrined\svgSanitize\Sanitizer;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Validator\ConstraintViolation;

/**
 * Sanitizes SVG files at upload time.
 *
 * One layer of the layered SVG security stack (trusted roles, upload-time
 * sanitize, render-time sanitize where inline, inert <img>/background
 * rendering by default, files-directory CSP at provisioning). Never the only
 * layer: the sanitizer has a bypass history (e.g. CVE-2025-55166, fixed in
 * enshrined/svg-sanitize 0.22.0), so the other layers stay load-bearing.
 *
 * Every upload lane funnels through this event: FileUploadHandler (REST,
 * JSON:API, daedalus_ui MediaUpload) and form widgets via
 * file_save_upload() both call the file.validator service, which dispatches
 * FileValidationEvent while the uploaded content sits at the file entity's
 * URI (the PHP temp path during uploads), BEFORE the move to its destination
 * and before the entity is saved. Rewriting the file here means only
 * sanitized markup ever reaches the files directory. Programmatic saves that
 * bypass the validator (e.g. FileRepository::writeData()) are trusted code
 * paths and are covered by the render-time layers instead.
 */
final class SvgSanitizer implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      FileValidationEvent::class => 'sanitize',
    ];
  }

  /**
   * Sanitizes SVG file content in place, or rejects it as a violation.
   */
  public function sanitize(FileValidationEvent $event): void {
    // Already-rejected uploads never reach the filesystem; nothing to clean.
    if (count($event->violations) > 0) {
      return;
    }

    $file = $event->file;
    if (!$this->isSvg($file->getFilename() ?? '', $file->getMimeType() ?? '')) {
      return;
    }

    $uri = $file->getFileUri();
    $dirty = is_file($uri) ? file_get_contents($uri) : FALSE;
    if ($dirty === FALSE) {
      $this->reject($event, $this->t('The SVG file could not be read for sanitizing.'));
      return;
    }

    $sanitizer = new Sanitizer();
    // Remote references leak viewer data and open SSRF; strip them.
    $sanitizer->removeRemoteReferences(TRUE);
    $clean = $sanitizer->sanitize($dirty);
    if ($clean === FALSE || $clean === '') {
      // Unparseable XML: the sanitizer cannot vouch for it, so it does not
      // enter the site.
      $this->reject($event, $this->t('The SVG file could not be parsed and was rejected for security reasons.'));
      return;
    }

    if ($clean !== $dirty) {
      if (file_put_contents($uri, $clean) === FALSE) {
        $this->reject($event, $this->t('The sanitized SVG file could not be written.'));
        return;
      }
      // The recorded size was measured before sanitizing.
      $file->setSize(strlen($clean));
    }
  }

  /**
   * Determines whether the file is an SVG.
   *
   * Matches case-insensitively (a mixed-case extension was an actual
   * sanitizer-bypass vector, CVE-2025-55166) on either the filename or the
   * mime type, because programmatic validator calls may carry a URI-derived
   * name while upload lanes carry the client name plus a guessed mime type.
   */
  private function isSvg(string $filename, string $mimeType): bool {
    return preg_match('/\.svg$/i', $filename) === 1
      || strtolower($mimeType) === 'image/svg+xml';
  }

  /**
   * Adds a violation rejecting the file.
   */
  private function reject(FileValidationEvent $event, \Stringable $message): void {
    $event->violations->add(new ConstraintViolation($message, NULL, [], '', '', ''));
  }

}
