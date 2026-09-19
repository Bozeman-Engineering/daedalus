<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Compiles the published animations into one shared stylesheet file.
 *
 * The motion material's delivery half: every published animation prints
 * as one named @keyframes at-rule (`@keyframes motion-{name}`, the ONE
 * derivation rule, StyleGuide::animationName()) into a single hash-busted
 * public file. The "daedalus_stylish/animations" library resolves the current file
 * at library-discovery time, the exact posture of the swatch, skin and
 * variants libraries. Keyframes are the first thing daedalus_stylish delivers that
 * is not a selector block: the at-rule sits outside any @scope and is
 * referenced by its bare name from a value, so no scope element, no
 * condition wrappers and no page-container rule are involved.
 *
 * The published set is the AnimationRepository's (publishedAnimations(),
 * label-sorted, then machine name): the same read the materials roster's
 * animation records come from, so the animations this file carries are
 * exactly the animations the validator admits and the prompt teaches. ALL
 * published animations compile (all-published-are-live, no active
 * pointer): motion is a namespace, names never compete, and an
 * unpublished animation simply stops delivering.
 *
 * The file sorts between the swatch file (-20) and the skin file (-10) at
 * library weight -15: a keyframes body may reference swatches and tokens
 * via var() (resolved lazily on the animated element, so order is
 * readability, not correctness), and skin defaults and variants may wear
 * animations, so the definitions lead their wearers in the delivered
 * document.
 *
 * The workspace-preview edge mirrors the swatch file: this file
 * always holds the LIVE (default-revision, published) animations. Inside a
 * workspace staged revisions (and workspace-minted animations) inline per
 * page (workspaceInlineCss(), embedded by daedalus_stylish_page_top()); later in
 * the document than the compiled <link>, a redefinition of the same
 * keyframes name wins (the last @keyframes of a name in document order
 * takes effect). The repository memoizes per workspace, so the inline
 * preview reads the staged set and the compile (outside the workspace)
 * the live one. Publishing the workspace recompiles the file
 * (AnimationWorkspaceSubscriber).
 *
 * The file mechanics (content-addressed name, state key, cache tag +
 * library reset, the accepted staleness class) live on
 * CompiledStylesheetBase. compile() runs on animation insert/update/delete
 * (_daedalus_animation_change(), after the repository reset so the compile
 * reads the post-write set) and on workspace publish. No media hook:
 * validateAnimationSteps() refuses media tokens inside a keyframe, so
 * referencedMediaUuids() records nothing and referencesMedia() never gates
 * a recompile.
 */
class AnimationStylesheet extends CompiledStylesheetBase {

  /**
   * Invalidated on every recompile.
   *
   * Rendered pages attach the "daedalus_stylish/animations" library and carry this
   * tag (daedalus_stylish_page_top()), so animation saves reach already-cached
   * pages.
   */
  public const CACHE_TAG = 'daedalus_stylish_animations';

  /**
   * State key holding the current stylesheet URI (content-addressed).
   */
  public const STATE_KEY = 'daedalus_stylish.animation_stylesheet_uri';

  /**
   * State key holding the media uuids the compiled animations reference.
   *
   * Always empty (keyframe steps refuse media tokens), recorded anyway
   * because the base contract writes it on every compile.
   */
  public const MEDIA_STATE_KEY = 'daedalus_stylish.animation_stylesheet_media';

  /**
   * The compiled file's basename prefix.
   */
  public const FILE_PREFIX = 'animations';

  public function __construct(
    protected CssPrinter $printer,
    protected AnimationRepository $animationRepository,
    protected EntityTypeManagerInterface $entityTypeManager,
    StateInterface $state,
    FileSystemInterface $fileSystem,
    StreamWrapperManagerInterface $streamWrapperManager,
    LibraryDiscoveryInterface $libraryDiscovery,
    ?WorkspaceManagerInterface $workspaceManager,
  ) {
    parent::__construct($state, $fileSystem, $streamWrapperManager, $libraryDiscovery, $workspaceManager);
  }

  /**
   * Prints every published animation's @keyframes at-rule.
   *
   * Deterministic (content-addressed output): the repository's published
   * set comes label-sorted with the unique machine name as tiebreak, and
   * each animation prints its canonical steps in stored (ascending offset)
   * order, so the same animations always produce the same file.
   *
   * @return string
   *   CSS text, '' when no published animation prints.
   */
  public function css(): string {
    return $this->printAnimations($this->animationRepository->publishedAnimations());
  }

  /**
   * Prints animations as named @keyframes at-rules, one block each.
   *
   * The one printing path behind the compiled file and the workspace
   * inline preview, so the two can never emit different CSS for the same
   * revisions. The name is the one derivation rule read forward
   * (StyleGuide::animationName()); the printer owns the emit gate (name
   * charset, offset shape, declaration hygiene) and drops what fails it.
   *
   * @param \Drupal\daedalus_stylish\DaedalusStylishAnimationInterface[] $animations
   *   The animations to print.
   *
   * @return string
   *   CSS text, '' when nothing printed.
   */
  protected function printAnimations(array $animations): string {
    $blocks = [];
    foreach ($animations as $animation) {
      $block = $this->printer->printKeyframes(StyleGuide::animationName($animation->getMachineName()), $animation->getSteps());
      if ($block !== '') {
        $blocks[] = $block;
      }
    }
    return implode("\n", $blocks);
  }

  /**
   * Inline CSS for the staged animations inside a workspace.
   *
   * The workspace-preview edge at page level, the swatch file's exact
   * shape: the compiled file is per-site and library assets cannot vary by
   * workspace, so inside a workspace the (possibly staged) published
   * animations print into a page-level <style> tag (daedalus_stylish_page_top())
   * as the same named at-rules, and the later definition of a keyframes
   * name wins. Workspace-minted animations are covered too: the repository
   * queried inside the workspace reads the workspace's tracked revisions.
   *
   * @param array $cache_tags
   *   Cache tags accumulator; the animation LIST tag merges in (invalidated
   *   by any animation save, so staged edits, mints and unpublishes
   *   invalidate the workspace-context page cache entries).
   *
   * @return string
   *   CSS text, '' outside an active workspace or with nothing to print.
   */
  public function workspaceInlineCss(array &$cache_tags): string {
    if ($this->workspaceManager === NULL || !$this->workspaceManager->hasActiveWorkspace()) {
      return '';
    }
    $cache_tags = Cache::mergeTags($cache_tags, $this->entityTypeManager->getDefinition('daedalus_animation')->getListCacheTags());
    return $this->printAnimations($this->animationRepository->publishedAnimations());
  }

  /**
   * Scans the compiled animations for media token payloads: none, by design.
   *
   * Keyframe steps refuse media tokens (validateAnimationSteps()), so
   * nothing is ever recorded and the media-change hooks never gate a
   * recompile on this file.
   *
   * @return string[]
   *   Always empty.
   */
  protected function referencedMediaUuids(): array {
    return [];
  }

}
