<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Utility\WorkspaceMemoTrait;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Resolves variant references through a request-lifetime identity map.
 *
 * Reference resolution (machine name first, then uuid) is a per-call entity
 * query, and delivery resolves references from the innermost render loop:
 * every independently cached fragment re-walks the root style map, so a page
 * render asks for the same handful of variants thousands of times. The
 * repository makes the cost model a property of the resolver instead of a
 * discipline every caller must remember: each distinct reference resolves at
 * most once per request, misses included (a dangling reference is otherwise
 * TWO queries, machine name then uuid, on every ask forever).
 *
 * Both memos key on the active workspace (WorkspaceMemoTrait): the compile
 * loads the roster's variants outside the workspace right after a staged
 * save, and the workspace preview loads the same references inside it
 * right after; each must see its own window's revisions.
 *
 * Staleness contract: the memo lives until a variant is saved or deleted.
 * _daedalus_variant_change() resets it from the variant entity's own
 * insert/update/delete hooks (before the recompile, which reads through
 * it), and VariantWorkspaceSubscriber resets it on workspace publish (which
 * fires no per-entity hooks), so a gesture that writes a variant and
 * re-reads it in one request can never see the pre-write entity.
 */
class VariantRepository {

  use WorkspaceMemoTrait;

  /**
   * Resolved references per workspace key, including misses (NULL).
   *
   * @var array<string, array<string, \Drupal\daedalus_stylish\DaedalusStylishVariantInterface|null>>
   */
  protected array $memo = [];

  /**
   * The published variants per workspace key, weight-then-label sorted.
   *
   * @var array<string, \Drupal\daedalus_stylish\DaedalusStylishVariantInterface[]>
   */
  protected array $published = [];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * Resolves a variant reference: machine name first, then uuid.
   *
   * The one resolution semantics behind StyleMapValidator::loadVariant();
   * see that method for why the order is load-bearing.
   */
  public function resolve(string $reference): ?DaedalusStylishVariantInterface {
    $key = $this->workspaceKey();
    if (array_key_exists($reference, $this->memo[$key] ?? [])) {
      return $this->memo[$key][$reference];
    }
    $storage = $this->entityTypeManager->getStorage('daedalus_variant');
    $variants = $storage->loadByProperties(['machine_name' => $reference])
      ?: $storage->loadByProperties(['uuid' => $reference]);
    $variant = reset($variants);
    return $this->memo[$key][$reference] = $variant instanceof DaedalusStylishVariantInterface ? $variant : NULL;
  }

  /**
   * The published variants: the set the materials roster is read from.
   *
   * All published variants are applicable vocabulary (scope gating happens
   * at apply time), so this list is what the roster's variant records are
   * read from, in the order they keep: weight-then-label (the order every
   * surface lists variants in), loaded once per
   * request and workspace; the swatch repository's publishedSwatches()
   * twin. Inside a workspace the storage answers with the workspace's
   * active revisions, so staged edits preview here.
   *
   * The order must be a total order, so label ties break on the unique
   * machine name. Without that the database decides the order of
   * same-label variants (the shipped block_/container_ motion pairs share
   * "Rise", "Fade in", "Scale in" at weight 0) and its answer drifts
   * between requests; the roster is printed into the AI's cached system
   * prompt, and a drifting order rewrites that prefix on every other
   * turn, throwing the whole prompt cache away (measured: consecutive
   * sidebar polls alternated two byte orders and read 0 cached tokens).
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishVariantInterface[]
   *   The published variants, sorted by weight, label, then machine name.
   */
  public function publishedVariants(): array {
    $key = $this->workspaceKey();
    if (!isset($this->published[$key])) {
      $storage = $this->entityTypeManager->getStorage('daedalus_variant');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('status', 1)
        ->sort('weight')
        ->sort('label')
        ->sort('machine_name')
        ->execute();
      $this->published[$key] = array_values(array_filter(
        $storage->loadMultiple($ids),
        static fn ($variant): bool => $variant instanceof DaedalusStylishVariantInterface,
      ));
    }
    return $this->published[$key];
  }

  /**
   * Forgets every resolved reference and the published roster.
   */
  public function reset(): void {
    $this->memo = [];
    $this->published = [];
  }

}
