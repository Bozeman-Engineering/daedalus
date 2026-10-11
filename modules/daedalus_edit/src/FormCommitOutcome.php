<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Result\BlueprintResult;

/**
 * What one form-transport commit did, carried from submit to response.
 *
 * A Drupal form runs its submit handlers and its AJAX callback as two
 * steps of one request; the handler is the write (FormCommit::commit()),
 * the callback is the answer (FormCommit::respond()). This is the hand-off
 * between them, stashed in form state under FormCommit::OUTCOME.
 */
final class FormCommitOutcome {

  /**
   * Constructs the outcome.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $root
   *   The subject's root host, the working copy the batch ran on.
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResult $result
   *   The batch result, success or failure.
   * @param string|null $langcode
   *   The translation the commit addressed.
   * @param string|null $persisted
   *   Where a successful result was persisted ('tempstore' or 'database'),
   *   NULL for a failed one.
   */
  public function __construct(
    public readonly HostInterface $root,
    public readonly BlueprintResult $result,
    public readonly ?string $langcode,
    public readonly ?string $persisted,
  ) {}

}
