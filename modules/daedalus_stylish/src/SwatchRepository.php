<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Utility\WorkspaceMemoTrait;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Resolves swatch references through a request-lifetime identity map.
 *
 * The variant repository's twin at value scope, and for the same cost
 * model: reference resolution (machine name first, then uuid) is a
 * per-call entity query, and both validation (every var(--swatch-*) in a
 * map checks against the published universe) and delivery ask for the same
 * handful of swatches over and over. Each distinct reference resolves at
 * most once per request, misses included, and the published set loads
 * once.
 *
 * Both memos key on the active workspace (WorkspaceMemoTrait): a compile
 * runs outside the workspace right after a staged save and the preview
 * renders inside it right after, and each must see its own window's
 * revisions.
 *
 * Staleness contract: the memo lives until a swatch is saved or deleted.
 * _daedalus_swatch_change() resets it from the swatch entity's own
 * insert/update/delete hooks (before the recompile, which reads through
 * it), and SwatchWorkspaceSubscriber resets it on workspace publish (which
 * fires no per-entity hooks), so a gesture that mints a swatch and
 * references it in one request can never see the pre-write universe.
 */
class SwatchRepository {

  use WorkspaceMemoTrait;

  /**
   * Resolved references per workspace key, including misses (NULL).
   *
   * @var array<string, array<string, \Drupal\daedalus_stylish\DaedalusStylishSwatchInterface|null>>
   */
  protected array $memo = [];

  /**
   * The published swatches per workspace key, label-sorted.
   *
   * @var array<string, \Drupal\daedalus_stylish\DaedalusStylishSwatchInterface[]>
   */
  protected array $published = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * Resolves a swatch reference: machine name first, then uuid.
   *
   * The one resolution semantics for every consumer of a swatch reference
   * — machine names are the authorable identity (they ARE the custom
   * property tail), uuids the fallback for opaque callers.
   */
  public function resolve(string $reference): ?DaedalusStylishSwatchInterface {
    $key = $this->workspaceKey();
    if (array_key_exists($reference, $this->memo[$key] ?? [])) {
      return $this->memo[$key][$reference];
    }
    $storage = $this->entityTypeManager->getStorage('daedalus_swatch');
    $swatches = $storage->loadByProperties(['machine_name' => $reference])
      ?: $storage->loadByProperties(['uuid' => $reference]);
    $swatch = reset($swatches);
    return $this->memo[$key][$reference] = $swatch instanceof DaedalusStylishSwatchInterface ? $swatch : NULL;
  }

  /**
   * The published swatches: the palette as the applying surfaces see it.
   *
   * ALL published swatches are live (no active pointer), so this list is
   * the whole apply vocabulary: what the materials roster's swatch records
   * are read from, in the order they keep (label-sorted, picker order),
   * loaded once per request and workspace. Inside a workspace the storage
   * answers with the workspace's active revisions, so staged mints and
   * edits preview here.
   *
   * Label ties break on the unique machine name so the order is total:
   * the roster is printed into the AI's cached system prompt, and an
   * order the database decides drifts between requests and rewrites that
   * cached prefix (see VariantRepository::publishedVariants()).
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishSwatchInterface[]
   *   The published swatches, sorted by label, then machine name.
   */
  public function publishedSwatches(): array {
    $key = $this->workspaceKey();
    if (!isset($this->published[$key])) {
      $storage = $this->entityTypeManager->getStorage('daedalus_swatch');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->sort('label')
        ->sort('machine_name')
        ->execute();
      $this->published[$key] = array_values(array_filter(
        $storage->loadMultiple($ids),
        static fn ($swatch): bool => $swatch instanceof DaedalusStylishSwatchInterface,
      ));
    }
    return $this->published[$key];
  }

  /**
   * Forgets every resolved reference and the published universe.
   */
  public function reset(): void {
    $this->memo = [];
    $this->published = [];
  }

}
