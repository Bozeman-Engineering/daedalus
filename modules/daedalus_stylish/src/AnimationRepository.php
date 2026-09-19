<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Utility\WorkspaceMemoTrait;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Resolves animation references through a request-lifetime identity map.
 *
 * The swatch repository's twin for the motion material, for the same cost
 * model: reference resolution (machine name first, then uuid) is a
 * per-call entity query, and delivery, the usage scan and the gestures ask
 * for the same handful of animations over and over. Each distinct
 * reference resolves at most once per request, misses included, and the
 * published set loads once.
 *
 * Both memos key on the active workspace (WorkspaceMemoTrait): a compile
 * runs outside the workspace right after a staged save and the preview
 * renders inside it right after, and each must see its own window's
 * revisions.
 *
 * Staleness contract: the memo lives until an animation is saved or
 * deleted. The animation entity hooks reset it (before the recompile,
 * which reads through it), and the workspace subscriber resets it on
 * publish (which fires no per-entity hooks), so a gesture that mints an
 * animation and wears it in one request can never see the pre-write
 * universe.
 */
class AnimationRepository {

  use WorkspaceMemoTrait;

  /**
   * Resolved references per workspace key, including misses (NULL).
   *
   * @var array<string, array<string, \Drupal\daedalus_stylish\DaedalusStylishAnimationInterface|null>>
   */
  protected array $memo = [];

  /**
   * The published animations per workspace key, label-sorted.
   *
   * @var array<string, \Drupal\daedalus_stylish\DaedalusStylishAnimationInterface[]>
   */
  protected array $published = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * Resolves an animation reference: machine name first, then uuid.
   *
   * The one resolution semantics for every consumer of an animation
   * reference: machine names are the authorable identity (they ARE the
   * keyframes ident tail), uuids the fallback for opaque callers.
   */
  public function resolve(string $reference): ?DaedalusStylishAnimationInterface {
    $key = $this->workspaceKey();
    if (array_key_exists($reference, $this->memo[$key] ?? [])) {
      return $this->memo[$key][$reference];
    }
    $storage = $this->entityTypeManager->getStorage('daedalus_animation');
    $animations = $storage->loadByProperties(['machine_name' => $reference])
      ?: $storage->loadByProperties(['uuid' => $reference]);
    $animation = reset($animations);
    return $this->memo[$key][$reference] = $animation instanceof DaedalusStylishAnimationInterface ? $animation : NULL;
  }

  /**
   * The published animations: the motion vocabulary as the wearers see it.
   *
   * ALL published animations are live (no active pointer), so this list is
   * the whole wear vocabulary: what the materials roster's animation
   * records are read from, in the order they keep (label-sorted), loaded
   * once per request and workspace. Inside a workspace the storage answers
   * with the workspace's active revisions, so staged mints and edits
   * preview here.
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishAnimationInterface[]
   *   The published animations, label-sorted, the unique machine name
   *   breaking label ties so the order (and the content-addressed file
   *   compiled from it) is deterministic across databases.
   */
  public function publishedAnimations(): array {
    $key = $this->workspaceKey();
    if (!isset($this->published[$key])) {
      $storage = $this->entityTypeManager->getStorage('daedalus_animation');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->sort('label')
        ->sort('machine_name')
        ->execute();
      $this->published[$key] = array_values(array_filter(
        $storage->loadMultiple($ids),
        static fn ($animation): bool => $animation instanceof DaedalusStylishAnimationInterface,
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
