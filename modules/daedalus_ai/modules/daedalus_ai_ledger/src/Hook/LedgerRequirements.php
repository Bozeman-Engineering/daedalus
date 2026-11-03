<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger\Hook;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\daedalus_ai_ledger\CostResolver;
use Drupal\daedalus_ai_ledger\PrefixReport;
use Drupal\daedalus_ai_ledger\UsageLedger;

/**
 * Status report entries for the Daedalus AI Ledger.
 *
 * The agent runner keeps a conversation's prompt prefix (the tool list and
 * the system prompt) byte-stable so the provider reads it from cache on
 * every leg; any subscriber on the wrapper's prompt events can vary it per
 * leg and silently rewrite that cache, and the cost is paid in money, at
 * the uncached price, on every leg after the change. The runner logs the
 * leg, but a logger needs a backend somebody reads; the status report is
 * the one place a Drupal admin already looks, so this is where the ledger
 * rows (prefix_drift, prefix_loss) become a warning naming the damage in
 * tokens over the last UsageLedger::REPORT_WINDOW seconds. The same entry
 * counts the conversations rebuilt from the visible chat in that window
 * (the rebuilt rows): the one measurement of the "AI forgot" experience,
 * stated as a number at whatever severity the drift set, since the expiry
 * rebuild is legitimate.
 *
 * A second entry reads the same window for the models that ran legs with
 * no price row of their own: a leg on such a model was priced at the
 * fallback row's rates (the `*` row, whose figures are nobody's) or, with
 * no fallback row either, at nothing, so the usage caps never saw it. The
 * question is answered against the price table as it is now, so the
 * entry clears the moment the rows are added.
 */
class LedgerRequirements {

  use StringTranslationTrait;

  public function __construct(
    protected UsageLedger $usageLedger,
    protected CostResolver $costResolver,
    protected TimeInterface $time,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $since = $this->time->getRequestTime() - UsageLedger::REPORT_WINDOW;
    return [
      'daedalus_ai_ledger_prefix_drift' => $this->prefixDrift($this->usageLedger->prefixReport($since)),
      'daedalus_ai_ledger_prices' => $this->priceTable($this->usageLedger->modelsWithoutRow($since), $this->usageLedger->legCount($since)),
    ];
  }

  /**
   * The prompt cache entry: drift as a warning, expiry and rebuilds as numbers.
   *
   * @param \Drupal\daedalus_ai_ledger\PrefixReport $report
   *   The window's report.
   *
   * @return array
   *   The requirement, without its key.
   */
  protected function prefixDrift(PrefixReport $report): array {
    $entry = ['title' => $this->t('Daedalus AI prompt cache')];
    $days = intdiv(UsageLedger::REPORT_WINDOW, 86400);
    $sentences = [];

    if (!$report->measured()) {
      $entry['value'] = $this->t('No conversations measured yet');
      $entry['severity'] = RequirementSeverity::Info;
    }
    elseif ($report->drifted()) {
      $conversations = $this->formatPlural($report->driftedConversations, '1 conversation', '@count conversations');
      $entry['value'] = $this->formatPlural(
        $report->driftedLegs,
        '1 leg re-sent a rewritten prompt prefix in @conversations (@tokens tokens re-paid)',
        '@count legs re-sent a rewritten prompt prefix in @conversations (@tokens tokens re-paid)',
        ['@conversations' => $conversations, '@tokens' => $report->driftedTokens],
      );
      $more = $report->driftedConversations - count($report->driftedConversationIds);
      $ids = implode(', ', $report->driftedConversationIds);
      $named = $more > 0
        ? $this->formatPlural($more, '@ids and 1 more', '@ids and @count more', ['@ids' => $ids])
        : $ids;
      $sentences[] = $this->t('In the last @days days something changed the system prompt or the tool list between the legs of a conversation, so the provider could not read the cached prompt and re-read the whole conversation at the uncached price on those legs. Conversations affected: @conversations. Find what varies the prompt per leg (a module that appends to the system prompt or alters the tool list on some requests and not others) and make it the same on every leg; the agent runner logs the exact leg under the daedalus_ai_memory channel. A fixed break clears from this report by itself after @days days.', [
        '@days' => $days,
        '@conversations' => $named,
      ]);
      $entry['severity'] = RequirementSeverity::Warning;
    }
    else {
      $entry['value'] = $this->formatPlural(
        $report->measuredLegs,
        'No prefix drift in 1 leg over the last @days days',
        'No prefix drift in @count legs over the last @days days',
        ['@days' => $days],
      );
      if ($report->expiryTokens > 0) {
        $sentences[] = $this->t('@tokens tokens were re-sent after the provider cache expired between turns (the cache TTL ran out while a user was away from the conversation; nothing rewrote the prompt). The number is the cost of the pause, not a defect; it is the data for choosing the cache TTL.', [
          '@tokens' => $report->expiryTokens,
        ]);
      }
      $entry['severity'] = RequirementSeverity::OK;
    }

    if ($report->rebuilt()) {
      $sentences[] = $this->formatPlural(
        $report->rebuiltConversations,
        "1 conversation was rebuilt from the visible chat (the agent's record had expired or could not be read).",
        "@count conversations were rebuilt from the visible chat (the agent's record had expired or could not be read).",
      );
    }
    if ($sentences !== []) {
      $entry['description'] = self::join($sentences, ' ');
    }
    return $entry;
  }

  /**
   * The price table entry: models used in the window with no row of their own.
   *
   * @param array<string, int> $models
   *   Legs per model without a row, the model seen most recently first.
   * @param int $legs
   *   How many legs landed in the window on any model.
   *
   * @return array
   *   The requirement, without its key.
   */
  protected function priceTable(array $models, int $legs): array {
    $entry = ['title' => $this->t('Daedalus AI price table')];
    $days = intdiv(UsageLedger::REPORT_WINDOW, 86400);

    if ($models === []) {
      if ($legs === 0) {
        $entry['value'] = $this->t('No AI usage measured yet');
        $entry['severity'] = RequirementSeverity::Info;
      }
      else {
        $entry['value'] = $this->t('Every model used in the last @days days has a price row', ['@days' => $days]);
        $entry['severity'] = RequirementSeverity::OK;
      }
      return $entry;
    }

    $items = [];
    foreach ($models as $model => $count) {
      $counted = $this->costResolver->pricedBy($model) === CostResolver::FALLBACK_MODEL
        ? $this->t('counted at the fallback row')
        : $this->t('counted at nothing');
      $items[] = $this->formatPlural($count, '@model (1 leg, @counted)', '@model (@count legs, @counted)', [
        '@model' => $model,
        '@counted' => $counted,
      ]);
    }
    $entry['value'] = $this->formatPlural(
      array_sum($models),
      '1 leg on @models without a price row',
      '@count legs on @models without a price row',
      ['@models' => $this->formatPlural(count($models), '1 model', '@count models')],
    );
    $entry['description'] = $this->t('In the last @days days legs ran on models the price table has no row for: @models. A leg counted at the fallback row (the row whose model is <code>*</code>) was priced at that row\'s rates, which are nobody\'s real rates; a leg counted at nothing was priced at nothing and the usage caps did not see it. Add a row for each of these models, with its own rates, at the <a href=":url">Daedalus AI Ledger settings</a>; this entry clears as soon as the rows exist.', [
      '@days' => $days,
      '@models' => self::join($items, ', '),
      ':url' => Url::fromRoute('daedalus_ai_ledger.settings')->toString(),
    ]);
    $entry['severity'] = RequirementSeverity::Warning;
    return $entry;
  }

  /**
   * Joins translated pieces with a glue, as one piece of markup.
   *
   * @param list<\Drupal\Component\Render\MarkupInterface> $pieces
   *   The pieces, each already safe markup.
   * @param string $glue
   *   What goes between them.
   */
  private static function join(array $pieces, string $glue): MarkupInterface {
    if (count($pieces) === 1) {
      return $pieces[0];
    }
    $placeholders = [];
    foreach ($pieces as $index => $piece) {
      $placeholders['@piece_' . $index] = $piece;
    }
    return new FormattableMarkup(implode($glue, array_keys($placeholders)), $placeholders);
  }

}
