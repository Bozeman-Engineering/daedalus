<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Utility\FiberResumeType;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Utility\WorkspaceMemoTrait;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Judges the offered variants under the site's CURRENT law.
 *
 * A variant is validated when it is written (the importer on a changed
 * source, the managers on a save), and that write-time verdict holds only
 * while the law it was judged under stands: the active design system
 * (and with it the enforcement mode it carries) and the layered
 * declarations both move over a variant's life, and a published variant
 * born under open law lives on
 * with values strict law refuses. Legality is therefore a fact about
 * (variant, current law), derived here once per law change and never per
 * write or per wearer: withheld() runs the same validation a save runs
 * (StyleMapValidator::validateAt() with the save's context) over
 * every published variant the site offers (global, or the active
 * system's member: the roster's own partition rule, restated here
 * because the roster is built from the answer), and names the ones that
 * fail with their errors. Conformance is the WHOLE save gate, not value
 * law alone: the structural gates (a dangling, mis-scoped or unoffered
 * nested reference, a reference cycle, a root-entry reference on the
 * variant's own axis) withhold a variant exactly as a refused value does,
 * so a variant written around the gate (a direct entity save) is not
 * offered either, and apply distributes nested references without
 * integrity guards of its own. The provider withholds those records from
 * the roster, so a nonconforming variant leaves the site exactly as an
 * inactive system's member does: not offered, not delivered, refused for
 * new wearing, a stale row on its existing wearers.
 *
 * The universe the judge validates in is the provider's own UNJUDGED
 * records (tokens, swatches, every offered variant, animations), handed
 * to the validator outright (withUniverse()) instead of read from the
 * roster: the roster withholds what this verdict names, so a judge that
 * read build() would recurse into the roster being built from it. The
 * active partition of those records IS the active system's universe (its
 * members plus the globals, the active skin's tokens), so judging every
 * candidate as a global in it gives the member run's verdict without
 * MaterialsRosterBuilder::buildFor() ever being called; a member of an
 * inactive system is never judged (it is not offered either way). A
 * step that reaches the builder mid-judgement from the judging flow is a
 * trailhead, refused loudly rather than latched around; a judgement
 * parked in another fiber while a second placeholder's build asks is
 * interleaving, not recursion, and the asker waits for it (see
 * $judging).
 *
 * The verdict is a cache-backed derived value, not a persisted index:
 * memoized per request under the law it was judged for (workspace,
 * enforcement mode, active design system, so an enforcement edit on the
 * active system flips the answer within the request that made it) and
 * persisted in cache.default under the provider's cache metadata
 * (config:daedalus_blueprint.settings for the active system and
 * design_system_list for its enforcement mode, an entity property,
 * daedalus_ui_page_builder and config:system.theme for the
 * declarations, the three material list tags for the entities, a cache
 * rebuild for code), so every law input invalidates it through the
 * tags it already busts, with no subscriber wiring. The request memo is
 * reset with the variant repository's (_daedalus_variant_change(),
 * VariantWorkspaceSubscriber): a gesture that saves a variant and
 * re-reads the roster in one request judges the fresh entity.
 */
final class VariantConformance {

  use WorkspaceMemoTrait;

  /**
   * The cache id prefix; the law key follows.
   */
  public const CACHE_ID = 'daedalus_stylish.variant_conformance';

  /**
   * Verdicts per law key: machine name => the errors that withhold it.
   *
   * @var array<string, array<string, \Drupal\daedalus_blueprint\Result\BlueprintError[]>>
   */
  protected array $memo = [];

  /**
   * The flows with a judgement in flight (FiberFlow::id() => TRUE).
   *
   * The recursion guard, per flow: a judgement that reaches the roster
   * builder from its own flow is a validation step that read build()
   * instead of the judge's universe, and is refused. A judgement parked
   * in another fiber is not that: the renderer interleaves placeholder
   * fibers and core yields a fiber from inside the variant load the
   * judge makes (the alias manager, views data, the theme registry), so
   * a second placeholder's build can ask while the first's judgement
   * waits. That asker yields once for the parked verdict and judges
   * itself only if it is still not in (core's own answer to the same
   * interleaving, LocalTaskManager::getLocalTasks()); judging twice is
   * the same verdict written twice.
   *
   * @var array<int, true>
   */
  protected array $judging = [];

  /**
   * Constructs the conformance judge.
   *
   * @param \Closure $provider
   *   Resolves to daedalus_stylish.materials_provider. A closure: the provider
   *   withholds this verdict's names, so it holds the judge.
   * @param \Closure $validator
   *   Resolves to daedalus_stylish.style_map_validator. A closure: the validator's
   *   universe is the roster the provider feeds.
   * @param \Drupal\daedalus_stylish\VariantRepository $variantRepository
   *   The variant repository.
   * @param \Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem $activeDesignSystem
   *   The active design system.
   * @param \Drupal\daedalus_stylish\StyleGuide $styleGuide
   *   The style guide.
   * @param \Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations $declarations
   *   The page builder declarations.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend the verdicts persist in.
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   The workspace manager, when workspaces is installed.
   */
  public function __construct(
    protected \Closure $provider,
    protected \Closure $validator,
    protected VariantRepository $variantRepository,
    protected ActiveDesignSystem $activeDesignSystem,
    protected StyleGuide $styleGuide,
    protected PageBuilderDeclarations $declarations,
    protected CacheBackendInterface $cache,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * The offered variants the current law refuses, with the errors.
   *
   * @return array<string, \Drupal\daedalus_blueprint\Result\BlueprintError[]>
   *   Machine name => the validation errors (paths relative to the
   *   variant's styles, as validateAt() roots them), in roster
   *   order; [] when every offered variant conforms.
   */
  public function withheld(): array {
    $key = $this->lawKey();
    if (isset($this->memo[$key])) {
      return $this->memo[$key];
    }
    $flow = FiberFlow::id();
    if (isset($this->judging[$flow])) {
      throw new \LogicException('Variant conformance was asked for while it was being judged: a validation step reached the materials roster instead of the judge\'s universe (StyleMapValidator::withUniverse()).');
    }
    $cid = self::CACHE_ID . ':' . $key;
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE) {
      return $this->memo[$key] = $cached->data;
    }
    if ($this->judging !== [] && \Fiber::getCurrent() !== NULL) {
      // Another fiber's judgement is parked mid-run (see $judging): yield
      // once so it can finish, and take its verdict if it did.
      \Fiber::suspend(FiberResumeType::Immediate);
      if (isset($this->memo[$key])) {
        return $this->memo[$key];
      }
    }
    $this->judging[$flow] = TRUE;
    try {
      [$withheld, $tags] = $this->judge();
    }
    finally {
      unset($this->judging[$flow]);
    }
    $this->cache->set($cid, $withheld, Cache::PERMANENT, $tags);
    return $this->memo[$key] = $withheld;
  }

  /**
   * The withheld variants' refusals in the wire shape every reason carries.
   *
   * The one spelling of "why is it withheld" outside PHP: the reference
   * refusal's `refused` context (StyleMapValidator's
   * STYLE_VARIANT_NONCONFORMING) and the client guide's `withheld`
   * payload (the wear bar's stale-row reason) both serialize this, so a
   * model patching after the refusal and an editor reading the row see
   * the same triples.
   *
   * @return array<string, list<array{path: string, code: string, message: string}>>
   *   Machine name => the refusals, in verdict order; [] when every
   *   offered variant conforms.
   */
  public function refusals(): array {
    return array_map(
      static fn (array $errors): array => array_map(self::refusal(...), $errors),
      $this->withheld(),
    );
  }

  /**
   * One verdict error as its {path, code, message} triple.
   *
   * The path is relative to the variant's styles (validateAt() roots it
   * there), so "default.border-radius" names the worn value, not
   * the wearer's.
   *
   * @return array{path: string, code: string, message: string}
   *   The triple.
   */
  public static function refusal(BlueprintError $error): array {
    return ['path' => $error->getPath(), 'code' => $error->getCode(), 'message' => $error->getMessage()];
  }

  /**
   * Forgets the request's verdicts (a variant changed mid-request).
   *
   * The persistent entry is not touched: the write that calls this
   * invalidates daedalus_variant_list itself.
   */
  public function reset(): void {
    $this->memo = [];
  }

  /**
   * Judges every offered variant in the provider's unjudged universe.
   *
   * @return array{0: array<string, \Drupal\daedalus_blueprint\Result\BlueprintError[]>, 1: string[]}
   *   The withheld names with their errors, and the cache tags the
   *   verdict rides.
   */
  protected function judge(): array {
    $provider = ($this->provider)();
    assert($provider instanceof DaedalusStylishMaterialsProvider);
    $active = $this->activeDesignSystem->activeId();
    $records = array_values(array_filter(
      $provider->unjudgedRecords(),
      static fn (MaterialRecord $record): bool => $record->designSystem === NULL || $record->designSystem === $active,
    ));
    $metadata = $provider->cacheMetadata();
    $universe = new MaterialsRoster($records, $metadata);
    $variants = [];
    foreach ($this->variantRepository->publishedVariants() as $variant) {
      $variants[$variant->getMachineName()] = $variant;
    }
    $theme = $this->declarations->siteTheme();
    $validator = ($this->validator)();
    assert($validator instanceof StyleMapValidator);
    $withheld = $validator->withUniverse($universe, function () use ($universe, $variants, $theme, $validator): array {
      $withheld = [];
      foreach (array_keys($universe->variants()) as $name) {
        $variant = $variants[$name];
        // The save's own context (VariantManager::validateStyles()), minus
        // the membership: the universe already is the member's.
        $errors = $validator->validateAt($variant->getStyles(), Address::fromString($variant->getScope()), [
          'theme' => $theme,
          'variant_axis' => $variant->getAxis(),
          'variant_identity' => array_filter([$variant->getMachineName(), (string) $variant->uuid()]),
        ]);
        if ($errors !== []) {
          $withheld[$name] = $errors;
        }
      }
      return $withheld;
    });
    return [$withheld, $metadata->getCacheTags()];
  }

  /**
   * The law the verdict is judged under, as a memo and cache key.
   *
   * Workspace (the storages answer per window), enforcement mode and
   * active design system: the inputs a request can change and read back
   * in the same request. The declarations are code and change with a
   * container rebuild.
   */
  protected function lawKey(): string {
    return $this->workspaceKey() . ':' . $this->styleGuide->enforcement() . ':' . ($this->activeDesignSystem->activeId() ?? '');
  }

}
