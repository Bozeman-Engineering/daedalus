<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * The AI context budget: what the site shares with the AI, against a ceiling.
 *
 * The spine's one meter and one guard over sharing. The meter: every
 * service tagged daedalus_blueprint.materials_weight_source (the
 * renderers that already produce every material line the model reads:
 * the schema's materials fragment here, the cached brief in
 * daedalus_blueprint_ai) answers its characters by record, a shared
 * figure per kind and a system figure (MaterialsWeights); reading() sums
 * them against the site's budget
 * (daedalus_blueprint.settings: ai_context_budget_tokens, the ceiling in
 * estimated tokens; ai_context_characters_per_token, the ratio) into a
 * BudgetReading. The guard: assertRoom() refuses, while the site is
 * full, a gesture that would share more (an offered capture, an update
 * turning offered on; the providers call it, so the AI's
 * ops and every dialog answer alike) with BlueprintError code
 * AI_CONTEXT_FULL, the hint stating the reading and the usage dialog's
 * path, one wording on every door. The check reads the current reading,
 * never a predicted delta: nothing weighs a material before it exists.
 *
 * Measurement is local and cheap: characters, at a fixed ratio, labelled
 * an estimate; no provider call, no tokenizer. The reading is cached on
 * the sources' metadata (the roster's tags, at least) and the config's,
 * varied by the sources' contexts, so a material's save or a budget
 * change re-derives it and a gesture's check is one cache read.
 */
class MaterialsBudget {

  /**
   * The BlueprintError code every refusal of a share carries.
   */
  public const ERROR_CODE = 'AI_CONTEXT_FULL';

  /**
   * The permission that opens the usage reading and dialog.
   */
  public const PERMISSION = 'view ai context usage';

  /**
   * The usage dialog's path (route daedalus_blueprint.ai_context).
   *
   * Named here, not resolved from the route, so the refusal's hint can
   * state it from inside a gesture with no request in hand; the routing
   * declares the same path.
   */
  public const DIALOG_PATH = '/daedalus-blueprint/ai-context';

  /**
   * The config object the budget reads.
   */
  public const CONFIG_NAME = 'daedalus_blueprint.settings';

  /**
   * The budget key: the ceiling, in estimated tokens.
   */
  public const KEY_BUDGET = 'ai_context_budget_tokens';

  /**
   * The ratio key: characters per token the estimate divides by.
   */
  public const KEY_RATIO = 'ai_context_characters_per_token';

  /**
   * The shipped defaults, the post-update's seed on existing sites.
   */
  public const INSTALL_DEFAULTS = [
    self::KEY_BUDGET => 24000,
    self::KEY_RATIO => 4,
  ];

  /**
   * The cache id's prefix; the sources' context keys follow.
   */
  protected const CACHE_ID = 'daedalus_blueprint:ai_context_reading';

  /**
   * The sources, in priority order.
   *
   * @var array<int, array{priority: int, source: \Drupal\daedalus_blueprint\Materials\MaterialsWeightSourceInterface}>
   */
  protected array $sources = [];

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected CacheBackendInterface $cache,
    protected CacheContextsManager $cacheContextsManager,
  ) {}

  /**
   * Registers a source (the service collector's call).
   */
  public function addSource(MaterialsWeightSourceInterface $source, int $priority = 0): void {
    $this->sources[] = ['priority' => $priority, 'source' => $source];
    usort($this->sources, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);
  }

  /**
   * The current reading: every source summed against the budget.
   *
   * @throws \LogicException
   *   When the settings carry no budget: a site that predates the keys
   *   and has not run its updates
   *   (daedalus_blueprint_post_update_ai_context_budget).
   */
  public function reading(): BudgetReading {
    $config = $this->configFactory->get(self::CONFIG_NAME);
    $budget = $config->get(self::KEY_BUDGET);
    $ratio = $config->get(self::KEY_RATIO);
    if (!is_int($budget) || !is_int($ratio)) {
      throw new \LogicException(sprintf('%s carries no AI context budget (%s, %s): run the module updates.', self::CONFIG_NAME, self::KEY_BUDGET, self::KEY_RATIO));
    }
    $metadata = $this->cacheMetadata()->merge(CacheableMetadata::createFromObject($config));
    return new BudgetReading($this->weights(), $budget, $ratio, $metadata);
  }

  /**
   * Refuses a gesture that would share more while the site is full.
   *
   * @param string $path
   *   The gesture parameter that shares, in the gesture's parameter
   *   space: `offered` for a capture or an update turning it on.
   *
   * @throws \Drupal\daedalus_blueprint\Materials\MaterialGestureException
   *   With one AI_CONTEXT_FULL error, the reading as its context, the
   *   hint naming what to do and the usage dialog's path.
   */
  public function assertRoom(string $path = 'offered'): void {
    $reading = $this->reading();
    if (!$reading->over) {
      return;
    }
    throw new MaterialGestureException([
      new BlueprintError(
        $path,
        self::ERROR_CODE,
        sprintf(
          'The AI context budget is full: the site shares an estimated %s tokens of %s (%s characters at %d per token), so sharing more with the AI is refused.',
          number_format($reading->estimatedTokens),
          number_format($reading->budgetTokens),
          number_format($reading->total),
          $reading->charactersPerToken,
        ),
        $reading->toArray(),
        sprintf('Turn "Offer to the AI" off on some materials, or raise the budget; what the AI holds, by kind, is at %s.', self::DIALOG_PATH),
      ),
    ]);
  }

  /**
   * Every source's weights, summed, cached on the sources' metadata.
   */
  protected function weights(): MaterialsWeights {
    $metadata = $this->cacheMetadata();
    $contexts = $metadata->getCacheContexts();
    $cid = self::CACHE_ID;
    if ($contexts !== []) {
      $cid .= ':' . implode(':', $this->cacheContextsManager->convertTokensToKeys($contexts)->getKeys());
    }
    $cached = $this->cache->get($cid);
    if ($cached) {
      return $cached->data;
    }
    $weights = new MaterialsWeights([], [], 0);
    foreach ($this->sources as ['source' => $source]) {
      $weights = $weights->add($source->weights());
    }
    $this->cache->set($cid, $weights, Cache::PERMANENT, $metadata->getCacheTags());
    return $weights;
  }

  /**
   * What every source's weights derive from, merged, without rendering.
   */
  protected function cacheMetadata(): CacheableMetadata {
    $metadata = new CacheableMetadata();
    foreach ($this->sources as ['source' => $source]) {
      $metadata = $metadata->merge($source->cacheMetadata());
    }
    return $metadata;
  }

}
