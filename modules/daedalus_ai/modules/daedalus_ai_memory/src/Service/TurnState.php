<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Service;

use Drupal\ai\OperationType\Chat\ChatInput;
use Drupal\ai\OperationType\Chat\ChatOutput;
use Drupal\ai_agents\PluginInterfaces\AiAgentInterface;
use Drupal\daedalus_ai_memory\Segment\SegmentBudget;
use Drupal\daedalus_ai_memory\Transcript\Rendering;
use Drupal\daedalus_ai_memory\Transcript\Transcript;

/**
 * What the runner carries through one request's legs.
 *
 * One of these lives for one runAsAgent() call: the agent wrapper, the
 * transcript it runs on, the provider and model the legs run on, the
 * budget the segment runs under, and the per-leg bookkeeping the request
 * and response listeners update (how much of the wrapper's history the
 * last rendering covered, the output of the last leg for its opaque
 * content, the last leg's input for a carry-over's prefix, the entries the
 * last leg absorbed for the close decision).
 */
final class TurnState {

  /**
   * The rendering the wrapper's history was last set from.
   */
  public ?Rendering $rendering = NULL;

  /**
   * How many messages of the wrapper's history the last rendering covered.
   *
   * What the wrapper holds past this count is what a leg appended and the
   * transcript has not absorbed yet.
   */
  public int $renderedCount = 0;

  /**
   * How many legs this request has sent.
   */
  public int $legs = 0;

  /**
   * The last leg's output, until its assistant message is absorbed.
   */
  public ?ChatOutput $lastOutput = NULL;

  /**
   * The last leg's input as the wrapper sent it, for a carry-over's prefix.
   */
  public ?ChatInput $lastInput = NULL;

  /**
   * Whether the leg being prepared saw its prompt prefix change.
   *
   * Set at the request event when the segment's prefix hash moved, read
   * and cleared at the provider's pre-generate event for the same call,
   * which tags the leg with UsageLedger::PREFIX_DRIFT_TAG for its row.
   */
  public bool $prefixDrifted = FALSE;

  /**
   * The entries the last absorb appended, for the close decision.
   *
   * @var \Drupal\daedalus_ai_memory\Transcript\Entry[]
   */
  public array $absorbed = [];

  /**
   * The files delivered with the user's turn, sent on its first leg only.
   *
   * @var \Drupal\ai\OperationType\GenericType\FileBaseInterface[]
   */
  public array $turnFiles = [];

  /**
   * The files the wrapper's interjected user messages carried for one leg.
   *
   * Collected by each absorb from the user-role messages of the absorbed
   * slice (the screenshots message ai_agents appends after a tool returned
   * images) and emptied by the next, so the leg prepared right after the
   * absorb renders with them, placed on the rendering's newest user
   * message, and no later leg does. The transcript keeps holding no bytes.
   *
   * @var \Drupal\ai\OperationType\GenericType\FileBaseInterface[]
   */
  public array $legFiles = [];

  /**
   * Constructs the state.
   *
   * @param string $jobId
   *   The job id: the conversation's identity and the agent's runner id.
   * @param \Drupal\ai_agents\PluginInterfaces\AiAgentInterface $agent
   *   The agent wrapper the legs run through.
   * @param \Drupal\daedalus_ai_memory\Transcript\Transcript $transcript
   *   The conversation's transcript.
   * @param object $provider
   *   The provider plugin (its proxy) the legs run on.
   * @param string $modelId
   *   The model id the legs run on.
   * @param \Drupal\daedalus_ai_memory\Segment\SegmentBudget $budget
   *   The budget the current segment runs under.
   * @param bool $filterInert
   *   Whether the agent disables transport hostname filtering, so that a
   *   rendering (which rebuilds tool calls through the filter) runs under
   *   full trust.
   */
  public function __construct(
    public readonly string $jobId,
    public readonly AiAgentInterface $agent,
    public readonly Transcript $transcript,
    public readonly object $provider,
    public readonly string $modelId,
    public readonly SegmentBudget $budget,
    public readonly bool $filterInert,
  ) {
  }

}
