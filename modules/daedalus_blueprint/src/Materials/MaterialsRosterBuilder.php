<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Utility\WorkspaceMemoTrait;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Builds the site's materials roster from the tagged providers.
 *
 * The one derivation behind every material surface: the dialect catalog,
 * the cached prompt, the schema, the validators, the client payload and
 * the delivery CSS all ask here. Memoized per request and per workspace:
 * inside a workspace the providers' storages answer with staged revisions
 * (a workspace-minted swatch previews in that workspace only), so the
 * memo keys on the active workspace and the roster's cache metadata
 * carries the workspace context. A material write mid-request (the
 * providers' entity hooks) calls reset() so a gesture that mints and
 * references in one request never sees the pre-write roster.
 *
 * The roster is the ACTIVE set: a provider serves every record it has,
 * and build() keeps the global ones (no design system) and the active
 * design system's, dropping every other system's members here, once,
 * so the validator, the stylesheets, the client guide, the dialect
 * catalog, the schema and the gestures agree on what the site offers.
 * The partition rides the pointer's config tag; a switch mid-request
 * resets the memo (ActiveDesignSystemSubscriber).
 */
class MaterialsRosterBuilder {

  use WorkspaceMemoTrait;

  /**
   * The providers, highest priority first.
   *
   * @var array<int, array{priority: int, provider: \Drupal\daedalus_blueprint\Materials\MaterialsProviderInterface}>
   */
  protected array $providers = [];

  /**
   * Built rosters keyed by active workspace ('' outside every workspace).
   *
   * @var array<string, \Drupal\daedalus_blueprint\Materials\MaterialsRoster>
   */
  protected array $memo = [];

  /**
   * Per-system rosters keyed by "{workspace}:{design system}" (buildFor()).
   *
   * @var array<string, \Drupal\daedalus_blueprint\Materials\MaterialsRoster>
   */
  protected array $systemMemo = [];

  public function __construct(
    protected ActiveDesignSystem $activeDesignSystem,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * Registers a provider (the service collector's call).
   */
  public function addProvider(MaterialsProviderInterface $provider, int $priority = 0): void {
    $this->providers[] = ['priority' => $priority, 'provider' => $provider];
    usort($this->providers, static fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);
  }

  /**
   * Builds (and memoizes) the roster for the current workspace.
   */
  public function build(): MaterialsRoster {
    $key = $this->workspaceKey();
    return $this->memo[$key] ??= $this->partition($this->activeDesignSystem->activeId());
  }

  /**
   * Builds (and memoizes) the roster a design system's members validate in.
   *
   * The global records plus the named system's, whether or not it is
   * active: what the site WOULD offer with that system active. The
   * validators' universe for a member (a shipped package lands while
   * another system is active, and its variant must still resolve its own
   * system's tokens, swatches and animations); every other consumer reads
   * build(), the active set. Memoized beside it and dropped by the same
   * reset().
   */
  public function buildFor(string $design_system): MaterialsRoster {
    $key = $this->workspaceKey() . ':' . $design_system;
    return $this->systemMemo[$key] ??= $this->partition($design_system);
  }

  /**
   * The global records plus one system's, in provider order.
   *
   * @param string|null $design_system
   *   The system whose members join the global ones; NULL keeps the
   *   global records alone (no system active).
   */
  protected function partition(?string $design_system): MaterialsRoster {
    $records = [];
    $metadata = (new CacheableMetadata())->addCacheTags(['config:' . ActiveDesignSystem::CONFIG_NAME]);
    foreach ($this->providers as ['provider' => $provider]) {
      foreach ($provider->records() as $record) {
        if ($record->designSystem === NULL || $record->designSystem === $design_system) {
          $records[] = $record;
        }
      }
      $metadata->addCacheableDependency($provider->cacheMetadata());
    }
    if ($this->workspaceManager !== NULL) {
      $metadata->addCacheContexts(['workspace']);
    }
    return new MaterialsRoster($records, $metadata);
  }

  /**
   * Every record that is a member of a design system, active or not.
   *
   * The unpartitioned read the unit's guards need (may this system's
   * package uninstall, may the system be deleted): the roster itself
   * never carries an inactive system's members. Not memoized; the
   * guards run once per uninstall or delete.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   The system's records, in provider order.
   */
  public function members(string $design_system): array {
    $members = [];
    foreach ($this->providers as ['provider' => $provider]) {
      foreach ($provider->records() as $record) {
        if ($record->designSystem === $design_system) {
          $members[] = $record;
        }
      }
    }
    return $members;
  }

  /**
   * The providers' gesture lines, one per kind, in MaterialKind order.
   *
   * Two providers teaching one kind would put two gestures on the prompt
   * for one material; refused loudly, like duplicate ids on the roster.
   *
   * @return array<string, string>
   *   Kind value => sentence, for the kinds any provider serves a gesture.
   */
  public function gestureLines(): array {
    $lines = [];
    foreach ($this->providers as ['provider' => $provider]) {
      foreach ($provider->gestureLines() as $kind => $line) {
        MaterialKind::from($kind);
        if (isset($lines[$kind])) {
          throw new \LogicException(sprintf('Two materials providers teach a %s gesture.', $kind));
        }
        $lines[$kind] = $line;
      }
    }
    $ordered = [];
    foreach (MaterialKind::cases() as $case) {
      if (isset($lines[$case->value])) {
        $ordered[$case->value] = $lines[$case->value];
      }
    }
    return $ordered;
  }

  /**
   * The provider that performs a kind's gestures, or NULL.
   *
   * The provider that teaches a kind's gesture line is the provider that
   * performs it (one source for the prompt and the dispatch); a kind no
   * provider teaches has no gesture (tokens: a skin write, not a material
   * gesture).
   */
  public function gestureProvider(MaterialKind $kind): ?MaterialsProviderInterface {
    foreach ($this->providers as ['provider' => $provider]) {
      if (isset($provider->gestureLines()[$kind->value])) {
        return $provider;
      }
    }
    return NULL;
  }

  /**
   * Drops every memoized roster (a material changed mid-request).
   */
  public function reset(): void {
    $this->memo = [];
    $this->systemMemo = [];
  }

}
