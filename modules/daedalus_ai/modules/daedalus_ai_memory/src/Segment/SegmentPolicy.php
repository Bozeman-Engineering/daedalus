<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_memory\Segment;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\daedalus_ai\Provider\ContextCeilingInterface;
use Drupal\daedalus_ai\Provider\ProviderCapabilities;
use Drupal\daedalus_ai_memory\Transcript\Entry;
use Drupal\daedalus_ai_memory\Transcript\EntryType;
use Drupal\daedalus_ai_memory\Transcript\ResultKey;
use Drupal\daedalus_ai_memory\Transcript\Segment;
use Drupal\daedalus_blueprint\SkillsResolverInterface;

/**
 * Decides when a segment closes, and under what budget it runs.
 *
 * A segment closes on budget, on a reframe or at the provider's hard
 * limit, never on a message count:
 *
 * - Budget: the last leg's measured prompt size (what the provider billed:
 *   input, cached reads and cache writes together, read from the usage
 *   ledger's newest row for the conversation, never estimated) passed the
 *   effective budget. Decided when a turn has finished, so a turn is never
 *   cut in half; the carry-over is generated while the prefix is still
 *   cached and the next user turn opens the new segment.
 * - Ceiling: the measurement passed the hard limit, the smallest bound the
 *   provider declares (its context ceiling, the model's maximum input)
 *   less the headroom. Decided at a finished turn like Budget and named
 *   before it. The runner asks pastLimit() at the end of every leg it
 *   sees as a request (the sidebar runs one leg per request) and ends an
 *   unfinished turn that passed the limit, so the close lands while the
 *   prompt still fits under the window with the headroom as the margin
 *   for the carry-over call; one leg later the provider would refuse.
 * - Reframe: a skill declaring `reframe: true` (the design doctrine)
 *   answered in the leg just absorbed, and the
 *   segment was already past the reframe minimum. The current turn is
 *   re-sent after the carry-over, so the doctrine the model just pulled
 *   sits near the request instead of under the conversation's bulk, and
 *   the model keeps the turn it is in. The minimum keeps a small segment
 *   from closing on every skill, and the check reads only the entries
 *   absorbed in the last leg, so a re-sent skill result cannot close the
 *   segment it was re-sent into.
 *
 * The effective budget is the smallest of the configured budget (the
 * default `segment_budget_tokens`, or the model's row in
 * `segment_budget_overrides`, either clamped up to
 * `segment_budget_minimum_tokens` so a value set under the prompt's own
 * floor cannot close every finished turn), and the provider's bounds less
 * `segment_headroom_tokens`: the ceiling a provider declaring
 * ContextCeilingInterface answers for the model, and the model's maximum
 * input (the headroom is the room a turn may grow after the last
 * measurement plus the carry-over call, since the budget is checked
 * between turns). The effective budget never exceeds the hard limit.
 *
 * The policy is pure over its inputs: the runner reads the ledger row
 * (LastLeg::fromRow()) and hands it in, so the decision is unit-tested
 * without a database and the runner owns the one read.
 */
final class SegmentPolicy {

  public const CONFIG = 'daedalus_ai_memory.settings';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly SkillsResolverInterface $skillsResolver,
  ) {}

  /**
   * The budget a segment runs under on a provider and model.
   *
   * @param object $provider
   *   The provider plugin (or its proxy) the legs run on.
   * @param string $modelId
   *   The model id.
   */
  public function budget(object $provider, string $modelId): SegmentBudget {
    $config = $this->configFactory->get(self::CONFIG);
    $configured = (int) $config->get('segment_budget_tokens');
    foreach ($config->get('segment_budget_overrides') ?? [] as $override) {
      if (($override['model'] ?? NULL) === $modelId) {
        $configured = (int) $override['tokens'];
      }
    }

    $plugin = ProviderCapabilities::plugin($provider);
    $ceiling = ProviderCapabilities::as($provider, ContextCeilingInterface::class)?->contextCeiling($modelId);
    $max_input = method_exists($plugin, 'getMaxInputTokens') ? (int) $plugin->getMaxInputTokens($modelId) : NULL;

    return new SegmentBudget(
      $configured,
      $ceiling,
      $max_input === NULL || $max_input < 1 ? NULL : $max_input,
      (int) $config->get('segment_headroom_tokens'),
      (int) $config->get('segment_budget_minimum_tokens'),
    );
  }

  /**
   * Whether the segment closes after the leg just absorbed, and why.
   *
   * @param \Drupal\daedalus_ai_memory\Transcript\Segment $segment
   *   The current segment.
   * @param \Drupal\daedalus_ai_memory\Segment\LastLeg|null $lastLeg
   *   The conversation's newest ledger row, or NULL before any leg.
   * @param \Drupal\daedalus_ai_memory\Segment\SegmentBudget $budget
   *   The budget the segment runs under.
   * @param \Drupal\daedalus_ai_memory\Transcript\Entry[] $absorbed
   *   The entries the leg just absorbed (Transcript::absorb()'s return).
   * @param bool $turnFinished
   *   Whether the agent finished the user's turn with this leg.
   *
   * @return \Drupal\daedalus_ai_memory\Segment\CloseReason|null
   *   The reason to close, or NULL to keep the segment open.
   */
  public function closeReason(Segment $segment, ?LastLeg $lastLeg, SegmentBudget $budget, array $absorbed, bool $turnFinished): ?CloseReason {
    $measured = $lastLeg?->measures($segment->number());
    if ($measured === NULL) {
      return NULL;
    }
    if ($measured > $this->reframeMinimum() && $this->reframed($absorbed)) {
      return CloseReason::Reframe;
    }
    if ($turnFinished && $this->pastLimit($segment, $lastLeg, $budget)) {
      return CloseReason::Ceiling;
    }
    if ($turnFinished && $measured > $budget->effective()) {
      return CloseReason::Budget;
    }
    return NULL;
  }

  /**
   * Whether the segment's last leg measured past the provider's hard limit.
   *
   * FALSE when the segment is unmeasured (no row, a row from another
   * segment, an unreported prompt size) or the provider declares no bound.
   *
   * @param \Drupal\daedalus_ai_memory\Transcript\Segment $segment
   *   The current segment.
   * @param \Drupal\daedalus_ai_memory\Segment\LastLeg|null $lastLeg
   *   The conversation's newest ledger row, or NULL before any leg.
   * @param \Drupal\daedalus_ai_memory\Segment\SegmentBudget $budget
   *   The budget the segment runs under.
   */
  public function pastLimit(Segment $segment, ?LastLeg $lastLeg, SegmentBudget $budget): bool {
    $measured = $lastLeg?->measures($segment->number());
    $limit = $budget->hardLimit();
    return $measured !== NULL && $limit !== NULL && $measured > $limit;
  }

  /**
   * Whether invoking a skill reframes the work.
   *
   * A skill the resolver does not know for the acting account (unknown,
   * gated, or a member of an inactive design system) cannot have been
   * invoked through the tool, so it reframes nothing.
   *
   * @param string $skillId
   *   The skill id.
   */
  public function reframes(string $skillId): bool {
    return (bool) ($this->skillsResolver->getSkill($skillId)['reframe'] ?? FALSE);
  }

  /**
   * The size past which a reframing skill closes the segment.
   */
  public function reframeMinimum(): int {
    return (int) $this->configFactory->get(self::CONFIG)->get('reframe_minimum_tokens');
  }

  /**
   * Whether a reframing skill answered among the absorbed entries.
   *
   * @param \Drupal\daedalus_ai_memory\Transcript\Entry[] $absorbed
   *   The entries the leg just absorbed.
   */
  private function reframed(array $absorbed): bool {
    $prefix = ResultKey::SKILL . ':';
    foreach ($absorbed as $entry) {
      if (!$entry instanceof Entry) {
        throw new \InvalidArgumentException('The absorbed entries are Entry objects.');
      }
      if ($entry->type !== EntryType::ToolResult || $entry->key === NULL || !str_starts_with($entry->key, $prefix)) {
        continue;
      }
      if ($this->reframes(substr($entry->key, strlen($prefix)))) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
