<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

/**
 * How a held file reaches the model, read off its MIME type.
 *
 * The chat's file surfaces (the ATTACHMENTS block, view_file) agree on one
 * split: a raster image travels as an image the provider sends as an image
 * block, a textual file travels as text inlined or read on demand (SVG is
 * text: the model reads and writes its markup), a PDF travels as a document
 * block in the turn it arrives and never again, and anything else is named
 * but never delivered. The lane's allowlist keeps Other rare; it exists so
 * an unforeseen MIME degrades to a name rather than to bytes the provider
 * would silently drop.
 */
enum FileKind {

  case Image;
  case Text;
  case Pdf;
  case Other;

  /**
   * The textual MIME types outside text/*.
   */
  private const TEXTUAL_MIMES = ['application/json', 'image/svg+xml'];

  /**
   * Classifies a MIME type.
   */
  public static function fromMime(string $mime): self {
    $mime = strtolower(trim($mime));
    if ($mime === 'application/pdf') {
      return self::Pdf;
    }
    if (str_starts_with($mime, 'text/') || in_array($mime, self::TEXTUAL_MIMES, TRUE)) {
      return self::Text;
    }
    if (str_starts_with($mime, 'image/')) {
      return self::Image;
    }
    return self::Other;
  }

}
