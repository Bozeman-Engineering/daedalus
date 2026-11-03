<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\File;

use Drupal\file\Upload\FormUploadedFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A request's upload answering the lane's canonical filename.
 *
 * Core's upload handler reads the name it saves under from
 * getClientOriginalName(); this wrapper answers the name FileLane
 * canonicalised and forwards everything else to core's FormUploadedFile. It
 * extends FormUploadedFile rather than implementing the interface beside it
 * because the handler routes the move by `instanceof FormUploadedFile`: a
 * sibling wrapper would silently fall to FileSystem::move() and lose the
 * secure move_uploaded_file() road a request's upload must take.
 *
 * @internal
 *   The lane's own; fromRequest() is the one constructor call.
 */
final class CanonicalFormUploadedFile extends FormUploadedFile {

  public function __construct(
    UploadedFile $uploadedFile,
    private readonly string $canonicalName,
  ) {
    parent::__construct($uploadedFile);
  }

  /**
   * {@inheritdoc}
   */
  public function getClientOriginalName(): string {
    return $this->canonicalName;
  }

}
