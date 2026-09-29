<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\daedalus_ai\Attachments;

/**
 * Sweeps the attachment store on cron.
 */
final class AttachmentsCron {

  public function __construct(
    private readonly Attachments $attachments,
  ) {}

  /**
   * Implements hook_cron().
   *
   * Releases the files of threads nobody cleared (see Attachments::sweep()),
   * so core's temporary-file garbage collection, which runs on the same
   * cron, can take them on a later run.
   */
  #[Hook('cron')]
  public function cron(): void {
    $this->attachments->sweep();
  }

}
