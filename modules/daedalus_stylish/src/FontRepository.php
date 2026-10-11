<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Utility\WorkspaceMemoTrait;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Resolves font references through a request-lifetime identity map.
 *
 * The animation repository's twin for the type material, for the same
 * cost model: reference resolution (machine name first, then uuid) is a
 * per-call entity query, and delivery, the roster and the gestures ask
 * for the same handful of fonts over and over. Each distinct reference
 * resolves at most once per request, misses included, and the published
 * set loads once.
 *
 * Both memos key on the active workspace (WorkspaceMemoTrait): a compile
 * runs outside the workspace right after a staged save and the preview
 * renders inside it right after, and each must see its own window's
 * revisions.
 *
 * Staleness contract: the memo lives until a font is saved or deleted.
 * The font entity hooks reset it (before the recompile, which reads
 * through it), and the workspace subscriber resets it on publish (which
 * fires no per-entity hooks).
 */
class FontRepository {

  use WorkspaceMemoTrait;

  /**
   * Resolved references per workspace key, including misses (NULL).
   *
   * @var array<string, array<string, \Drupal\daedalus_stylish\DaedalusStylishFontInterface|null>>
   */
  protected array $memo = [];

  /**
   * The published fonts per workspace key, label-sorted.
   *
   * @var array<string, \Drupal\daedalus_stylish\DaedalusStylishFontInterface[]>
   */
  protected array $published = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * Resolves a font reference: machine name first, then uuid.
   */
  public function resolve(string $reference): ?DaedalusStylishFontInterface {
    $key = $this->workspaceKey();
    if (array_key_exists($reference, $this->memo[$key] ?? [])) {
      return $this->memo[$key][$reference];
    }
    $storage = $this->entityTypeManager->getStorage('daedalus_font');
    $fonts = $storage->loadByProperties(['machine_name' => $reference])
      ?: $storage->loadByProperties(['uuid' => $reference]);
    $font = reset($fonts);
    return $this->memo[$key][$reference] = $font instanceof DaedalusStylishFontInterface ? $font : NULL;
  }

  /**
   * The published fonts: the type vocabulary as the stacks see it.
   *
   * ALL published fonts are live (no active pointer), so this list is the
   * whole vocabulary: what the materials roster's font records are read
   * from, in the order they keep (label-sorted), loaded once per request
   * and workspace. Inside a workspace the storage answers with the
   * workspace's active revisions, so staged mints and edits preview here.
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishFontInterface[]
   *   The published fonts, label-sorted, the unique machine name breaking
   *   label ties so the order (and the content-addressed file compiled
   *   from it) is deterministic across databases.
   */
  public function publishedFonts(): array {
    $key = $this->workspaceKey();
    if (!isset($this->published[$key])) {
      $storage = $this->entityTypeManager->getStorage('daedalus_font');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->sort('label')
        ->sort('machine_name')
        ->execute();
      $this->published[$key] = array_values(array_filter(
        $storage->loadMultiple($ids),
        static fn ($font): bool => $font instanceof DaedalusStylishFontInterface,
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
