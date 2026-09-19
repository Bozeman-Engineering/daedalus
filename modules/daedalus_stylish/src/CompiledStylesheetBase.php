<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Asset\LibraryDiscoveryInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheCollector;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StreamWrapper\LocalStream;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Base for services compiling CSS into one shared, hash-busted public file.
 *
 * The shared delivery mechanics behind VariantStylesheet and DefaultsStylesheet
 * (and any future compiled stylesheet): the subclass's css() prints once
 * into a single public file whose name embeds a content hash, so browsers
 * cache it cross-page and every regeneration is a new URL. A library
 * resolves the current file at library-discovery time
 * (hook_library_info_build(), through libraryPath()), which is why cached
 * renders that attached the library never go stale on the library NAME;
 * compile() invalidates 'library_info' and the subclass's CACHE_TAG so the
 * next request's HTML carries the new <link>.
 *
 * The compiled file always holds the LIVE (default-revision, published)
 * content: compile() runs the subclass's printing outside any active
 * workspace, so a save inside a workspace never leaks a staged revision
 * into the shared public file (workspace previews inline their staged CSS
 * per page instead; see each subclass's workspaceInlineCss()).
 *
 * Media tokens resolve to concrete file URLs baked into the static file,
 * and cache tags cannot bust a static file, so every compile records the
 * RAW stored media token payloads (referencedMediaUuids()) under
 * MEDIA_STATE_KEY: media insert/update/delete hooks consult the list
 * (referencesMedia()) and recompile when the compiled CSS references the
 * changed media. The raw payloads are scanned rather than the resolved
 * output because the list is the hooks' gate, not a resolution: a payload
 * whose media was deleted after the compile must stay on the list so the
 * delete recompiles (the token degrades to "none") and a later insert of
 * the same uuid (a restore, a content sync) recompiles again. A reference
 * to media that never existed is not this list's concern: the judge
 * refuses it at save (PropertyGroupBase MEDIA_NOT_FOUND).
 *
 * The accepted staleness class: a live page already open in a tab
 * when a recompile happens keeps showing the stylesheet it loaded until the
 * tab reloads. CACHE_TAG regenerates the HTML (and its <link>) on the next
 * request, but nothing pushes into open tabs. External page caches holding
 * the old URL degrade harder (see compile()). Editing surfaces are immune:
 * edit mode has no compiled-file dependency at all.
 *
 * Subclasses provide css() and referencedMediaUuids(), plus four constants:
 * - CACHE_TAG: invalidated on every recompile.
 * - STATE_KEY: state key holding the current stylesheet URI.
 * - MEDIA_STATE_KEY: state key holding the referenced media uuids.
 * - FILE_PREFIX: the compiled file's basename prefix ('variants', 'defaults').
 */
abstract class CompiledStylesheetBase {

  /**
   * Directory the compiled stylesheets are written to.
   */
  public const DIRECTORY = 'public://daedalus_stylish';

  public function __construct(
    protected StateInterface $state,
    protected FileSystemInterface $fileSystem,
    protected StreamWrapperManagerInterface $streamWrapperManager,
    protected LibraryDiscoveryInterface $libraryDiscovery,
    protected ?WorkspaceManagerInterface $workspaceManager,
  ) {}

  /**
   * Prints the compiled stylesheet's full CSS.
   *
   * Must be deterministic (content-addressed output): the same source
   * always produces the same file name. Always prints the LIVE
   * (default-revision, published) content; compile() guarantees the
   * outside-workspace window.
   *
   * @return string
   *   CSS text, '' when nothing prints.
   */
  abstract public function css(): string;

  /**
   * Scans the compiled content's RAW stored values for media token payloads.
   *
   * The stored tokens, not the resolved output: the list gates the media
   * hooks, so a payload whose media is gone must stay on it (see the class
   * docblock). Runs only inside compile(), where the extra load is noise.
   *
   * @return string[]
   *   Lowercased token payloads, deduplicated.
   */
  abstract protected function referencedMediaUuids(): array;

  /**
   * Recompiles the stylesheet file and invalidates its consumers.
   *
   * The subclass's entity/settings/workspace hooks decide WHEN this runs;
   * this method owns the HOW. Always compiles against the LIVE revisions:
   * a save inside a workspace creates a staged revision that must not leak
   * into the shared public file (the workspace preview inlines it instead).
   */
  public function compile(): void {
    // The media uuid scan reads the same live revisions as the CSS, so both
    // run inside the same outside-workspace window.
    $compilation = fn (): array => [$this->css(), $this->referencedMediaUuids()];
    [$css, $media_uuids] = $this->workspaceManager !== NULL
      ? $this->workspaceManager->executeOutsideWorkspace($compilation)
      : $compilation();
    $this->state->set(static::MEDIA_STATE_KEY, $media_uuids);

    $previous = $this->state->get(static::STATE_KEY);
    $uri = NULL;
    if ($css !== '') {
      $uri = static::DIRECTORY . '/' . static::FILE_PREFIX . '-' . substr(hash('sha256', $css), 0, 16) . '.css';
      $directory = static::DIRECTORY;
      $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $this->fileSystem->saveData($css . "\n", $uri, FileExists::Replace);
    }
    $this->state->set(static::STATE_KEY, $uri);

    // The content-addressed name makes the old file garbage the moment the
    // tag invalidation below rebuilds internal caches. External page caches
    // holding the old URL degrade to missing CSS until they expire —
    // the same accepted staleness class as the stale-<link> edge (see the
    // class docblock: an open tab keeps its already-loaded CSS regardless of
    // this delete; the delete only bites HTML served with the old URL after
    // the recompile, which internal caches never do thanks to CACHE_TAG and
    // external ones may until expiry).
    if (is_string($previous) && $previous !== $uri) {
      try {
        $this->fileSystem->delete($previous);
      }
      catch (FileException) {
        // Already gone; nothing to clean.
      }
    }

    // library_info: the library definition embeds the hashed filename. The
    // tag clears the persistent cache; the collector's in-request static is
    // reset too so THIS request (a mutation response rendering styled
    // markup, e.g.) already resolves the new file.
    Cache::invalidateTags(['library_info', static::CACHE_TAG]);
    if ($this->libraryDiscovery instanceof CacheCollector) {
      $this->libraryDiscovery->reset();
    }
  }

  /**
   * The current stylesheet's DRUPAL_ROOT-relative path for the library key.
   *
   * A library CSS key with a leading slash is, by core's contract
   * (LibraryDiscoveryParser::buildByExtension(), "An absolute path maps to
   * DRUPAL_ROOT / base_path()"), a path under DRUPAL_ROOT: the parser
   * strips the slash and the asset renderer prepends base_path() once at
   * render. So this is NOT a browser URL: a file_url_generator string
   * already carries base_path(), and handing that to the library doubles
   * the base path on every subdirectory install (d.o CI serves the site at
   * /web; the four compiled files 404'd there while the module's static
   * files did not). Built from the stream wrapper's directory path
   * (sites/<site>/files, request-independent), never by stripping the base
   * path off a generated URL.
   *
   * @return string|null
   *   The path ('/sites/default/files/daedalus_stylish/<prefix>-<hash>.css'),
   *   or NULL when no compiled stylesheet exists (nothing printed on the
   *   last compile, or no compile has ever run).
   */
  public function libraryPath(): ?string {
    $uri = $this->state->get(static::STATE_KEY);
    if (!is_string($uri) || !file_exists($uri)) {
      return NULL;
    }
    $wrapper = $this->streamWrapperManager->getViaUri($uri);
    if (!$wrapper instanceof LocalStream) {
      throw new \LogicException(sprintf('The compiled stylesheet %s is not on a local stream wrapper; the library key needs a DRUPAL_ROOT-relative path.', $uri));
    }
    return '/' . $wrapper->getDirectoryPath() . '/' . StreamWrapperManager::getTarget($uri);
  }

  /**
   * Whether the compiled stylesheet references a media entity.
   *
   * Answered from the MEDIA_STATE_KEY list the last compile recorded — the
   * media-change hooks' gate for triggering a recompile.
   */
  public function referencesMedia(string $uuid): bool {
    $uuids = $this->state->get(static::MEDIA_STATE_KEY, []);
    return is_array($uuids) && in_array(strtolower($uuid), $uuids, TRUE);
  }

}
