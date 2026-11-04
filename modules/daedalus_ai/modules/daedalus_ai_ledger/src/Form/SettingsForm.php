<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai_ledger\Form;

use Drupal\ai\AiProviderPluginManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\daedalus_ai_ledger\CostResolver;
use Drupal\daedalus_ai_ledger\UsageGate;
use Drupal\daedalus_ai_ledger\UsageRetention;
use Drupal\daedalus_ai_ledger\Window;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The price table, the spend windows and the retention.
 *
 * Both tables render their configured rows plus one blank row to add to;
 * a row with its key column (the model id, the window's period) emptied
 * is dropped on save. The price table's columns follow
 * CostResolver::BUCKETS then the per-call bucket, header and cells alike.
 * The caps table carries scope, period, cap and anchor, validated through
 * Window's own parsers, and under it one line per window saying about
 * how many million uncached input tokens of the site's default chat model
 * the cap comes to, so a budget stated to users in tokens and the dollar
 * figure underneath are seen together. The retention is one number of
 * days, with the floor the configured windows set stated beside it.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typedConfigManager,
    protected CostResolver $costResolver,
    protected UsageGate $usageGate,
    protected UsageRetention $usageRetention,
    protected AiProviderPluginManager $aiProvider,
  ) {
    parent::__construct($config_factory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('daedalus_ai_ledger.cost_resolver'),
      $container->get('daedalus_ai_ledger.usage_gate'),
      $container->get('daedalus_ai_ledger.usage_retention'),
      $container->get('ai.provider'),
    );
  }

  /**
   * The price table's columns after the model id.
   *
   * The token buckets in CostResolver::BUCKETS order, then the per-call
   * figure; header and cells follow the same list.
   *
   * @return list<string>
   *   The bucket keys in column order.
   */
  private static function priceColumns(): array {
    return [...array_values(CostResolver::BUCKETS), CostResolver::PER_CALL_BUCKET];
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [CostResolver::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'daedalus_ai_ledger_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(CostResolver::SETTINGS);

    $labels = [
      'input' => $this->t('Input ($/M)'),
      'cache_read' => $this->t('Cache read ($/M)'),
      'cache_write' => $this->t('Cache write ($/M)'),
      'output' => $this->t('Output ($/M)'),
      CostResolver::PER_CALL_BUCKET => $this->t('Per call ($)'),
    ];
    $form['prices'] = [
      '#type' => 'table',
      '#caption' => $this->t('Price table'),
      '#header' => [
        $this->t('Model id'),
        ...array_map(static fn (string $bucket) => $labels[$bucket], self::priceColumns()),
      ],
      '#prefix' => '<p>' . $this->t('USD per million tokens by model id, plus a per-call figure added once per leg on top of the tokens (blank is 0: the call itself is free). The caps count what these prices make of each leg; a bucket at zero stops counting it. A model missing from the table is priced at the row whose model id is <code>*</code>, and that row is what makes every leg count: edit it rather than delete it, and add a row for a model to price it on its own. A provider that reports its own cost is believed over this table. Clear a model id to drop its row.') . '</p>',
    ];
    $rows = $config->get(CostResolver::PRICES_KEY) ?? [];
    $rows[] = [];
    foreach (array_values($rows) as $delta => $row) {
      $form['prices'][$delta]['model'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Model id'),
        '#title_display' => 'invisible',
        '#default_value' => $row['model'] ?? '',
        '#size' => 32,
      ];
      foreach (self::priceColumns() as $bucket) {
        $form['prices'][$delta][$bucket] = [
          '#type' => 'number',
          '#title' => $labels[$bucket],
          '#title_display' => 'invisible',
          '#default_value' => $row[$bucket] ?? '',
          '#min' => 0,
          '#step' => 'any',
          '#size' => 10,
        ];
      }
    }

    $form['caps'] = [
      '#type' => 'table',
      '#caption' => $this->t('Spend windows'),
      '#header' => [
        $this->t('Scope'),
        $this->t('Period'),
        $this->t('Cap (USD)'),
        $this->t('Anchor'),
      ],
      '#prefix' => '<p>' . $this->t('A window whose priced legs reach its cap refuses every turn until it clears. A site window sums every leg on the site and refuses everyone; a user window sums the account\'s own legs, and users with the "Bypass AI usage caps" permission skip user windows only. Site windows are checked first. No windows means unlimited. Clear a period to drop its row.') . '</p>',
    ];
    $windows = $config->get(UsageGate::CAPS_KEY) ?? [];
    $windows[] = ['scope' => Window::SCOPE_USER, 'period' => '', 'usd' => '', 'anchor' => ''];
    foreach (array_values($windows) as $delta => $window) {
      $form['caps'][$delta]['scope'] = [
        '#type' => 'select',
        '#title' => $this->t('Scope'),
        '#title_display' => 'invisible',
        '#options' => [
          Window::SCOPE_SITE => $this->t('Site'),
          Window::SCOPE_USER => $this->t('User'),
        ],
        '#default_value' => $window['scope'] ?? Window::SCOPE_USER,
      ];
      $form['caps'][$delta]['period'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Period'),
        '#title_display' => 'invisible',
        '#default_value' => $window['period'] ?? '',
        '#description' => $this->t('An ISO 8601 duration: PT5H for five hours, P7D for seven days, P1M for a month.'),
        '#size' => 10,
      ];
      $form['caps'][$delta]['usd'] = [
        '#type' => 'number',
        '#title' => $this->t('Cap (USD)'),
        '#title_display' => 'invisible',
        '#default_value' => $window['usd'] ?? '',
        '#min' => 0,
        '#step' => 'any',
        '#size' => 10,
      ];
      $form['caps'][$delta]['anchor'] = [
        '#type' => 'date',
        '#title' => $this->t('Anchor'),
        '#title_display' => 'invisible',
        '#default_value' => $window['anchor'] ?? '',
        '#description' => $this->t('Optional: the day the window resets on, such as a billing day; the boundaries step from it by the period. Empty to roll over the last period.'),
      ];
    }

    $equivalents = $this->tokenEquivalents($config->get(UsageGate::CAPS_KEY) ?? []);
    if ($equivalents !== []) {
      $form['caps_tokens'] = [
        '#theme' => 'item_list',
        '#items' => $equivalents,
        '#attributes' => ['class' => ['daedalus-ai-ledger-cap-tokens']],
      ];
    }

    $floor_days = (int) ceil($this->usageRetention->windowFloorSeconds() / 86400);
    $form[UsageRetention::RETENTION_KEY] = [
      '#type' => 'number',
      '#title' => $this->t('Retention (days)'),
      '#default_value' => $this->usageRetention->configuredDays(),
      '#min' => 0,
      '#step' => 1,
      '#required' => TRUE,
      '#description' => $floor_days > 0
        ? $this->t('Usage rows older than this many days are deleted on cron; 0 keeps every row. The windows above need @days days of rows, so a smaller value is raised to that.', ['@days' => $floor_days])
        : $this->t('Usage rows older than this many days are deleted on cron; 0 keeps every row. Never shorter than the longest configured window, so a window is never summed over a hole.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * One line per window: the cap as uncached input tokens of the chat default.
   *
   * Read from the price table for the model `ai.settings` names as the
   * chat default; nothing when the model has no row of its own (the
   * fallback row prices it, but its figure is not the model's) or its
   * input bucket is zero.
   *
   * @param list<array<string, mixed>> $caps
   *   The configured caps.
   *
   * @return list<\Drupal\Core\StringTranslation\TranslatableMarkup>
   *   The lines, in cap order.
   */
  private function tokenEquivalents(array $caps): array {
    $default = $this->aiProvider->getDefaultProviderForOperationType('chat');
    $model = is_array($default) ? ($default['model_id'] ?? NULL) : NULL;
    if (!is_string($model) || $model === '' || $this->costResolver->pricedBy($model) !== $model) {
      return [];
    }
    $input = $this->costResolver->priceFor($model)['input'] ?? 0.0;
    if ($input <= 0.0) {
      return [];
    }
    $lines = [];
    foreach ($caps as $cap) {
      if (!is_array($cap) || !isset($cap['usd']) || !is_numeric($cap['usd'])) {
        continue;
      }
      $millions = (float) $cap['usd'] / $input;
      $lines[] = $this->t('@usd USD is about @n million uncached input tokens of @model', [
        '@usd' => rtrim(rtrim(number_format((float) $cap['usd'], 2, '.', ''), '0'), '.'),
        '@n' => rtrim(rtrim(number_format($millions, 1, '.', ''), '0'), '.'),
        '@model' => $model,
      ]);
    }
    return $lines;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $seen = [];
    foreach ($form_state->getValue('prices') ?? [] as $delta => $row) {
      $model = trim((string) $row['model']);
      if ($model === '') {
        continue;
      }
      if (isset($seen[$model])) {
        $form_state->setErrorByName("prices][$delta][model", $this->t('The model %model is priced twice.', ['%model' => $model]));
      }
      $seen[$model] = TRUE;
      // The four token buckets are required (a missing one with usage would
      // leave the leg unpriced); the per-call figure is optional and blank
      // saves as 0. A `*` model id is the fallback row and is as valid as
      // any other id.
      foreach (CostResolver::BUCKETS as $bucket) {
        if ($row[$bucket] === '' || $row[$bucket] === NULL) {
          $form_state->setErrorByName("prices][$delta][$bucket", $this->t('Every bucket of %model needs a price; use 0 to stop counting one.', ['%model' => $model]));
        }
      }
    }
    $timezone = $this->usageGate->siteTimezone();
    foreach ($form_state->getValue('caps') ?? [] as $delta => $window) {
      $period = trim((string) ($window['period'] ?? ''));
      if ($period === '') {
        continue;
      }
      if (!in_array($window['scope'] ?? NULL, Window::SCOPES, TRUE)) {
        $form_state->setErrorByName("caps][$delta][scope", $this->t('Every window needs a scope.'));
      }
      try {
        Window::parsePeriod($period);
      }
      catch (\InvalidArgumentException $e) {
        $form_state->setErrorByName("caps][$delta][period", $e->getMessage());
      }
      if ($window['usd'] === '' || $window['usd'] === NULL) {
        $form_state->setErrorByName("caps][$delta][usd", $this->t('Every window needs a cap.'));
      }
      $anchor = trim((string) ($window['anchor'] ?? ''));
      if ($anchor !== '') {
        try {
          Window::parseAnchor($anchor, $timezone);
        }
        catch (\InvalidArgumentException $e) {
          $form_state->setErrorByName("caps][$delta][anchor", $e->getMessage());
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $prices = [];
    foreach ($form_state->getValue('prices') ?? [] as $row) {
      $model = trim((string) $row['model']);
      if ($model === '') {
        continue;
      }
      $price = ['model' => $model];
      foreach (self::priceColumns() as $bucket) {
        $price[$bucket] = (float) $row[$bucket];
      }
      $prices[] = $price;
    }
    $caps = [];
    $timezone = $this->usageGate->siteTimezone();
    foreach ($form_state->getValue('caps') ?? [] as $window) {
      $period = trim((string) ($window['period'] ?? ''));
      if ($period === '') {
        continue;
      }
      // Validation parsed every field; the window writes its own row so
      // the stored shape is the one the gate reads.
      $caps[] = Window::fromRow([
        'scope' => $window['scope'],
        'period' => $period,
        'usd' => $window['usd'],
        'anchor' => trim((string) ($window['anchor'] ?? '')),
      ], $timezone)->toArray();
    }

    $this->config(CostResolver::SETTINGS)
      ->set(CostResolver::PRICES_KEY, $prices)
      ->set(UsageGate::CAPS_KEY, $caps)
      ->set(UsageRetention::RETENTION_KEY, (int) $form_state->getValue(UsageRetention::RETENTION_KEY))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
