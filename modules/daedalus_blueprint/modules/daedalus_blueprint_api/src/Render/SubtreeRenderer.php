<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_api\Render;

use Drupal\Component\Utility\Html;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\Core\Render\AttachmentsResponseProcessorInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders the markup half of the mutation-response contract.
 *
 * Renders the mutated host's canvas server-side through the canvas-builder
 * socket (theme fidelity: the swapped markup is the real render, never a
 * client approximation) and extracts the smallest subtrees that reflect
 * the change, keyed by selectable key. The client
 * replaces each element whose `data-selectable` attribute matches a returned
 * key.
 *
 * Extraction is all-or-fallback: if any mapped subtree is missing from the
 * rendered markup — attribution not stamped yet, or the element not visible in
 * this view mode — the whole-entity markup is returned alone under the root
 * entity key, since it subsumes every target.
 *
 * Asset attachments bubbled during the render are resolved into AJAX asset
 * commands (add_css / add_js / settings) against the request's
 * `ajax_page_state`, for the client to play *before* swapping markup so newly
 * placed blocks' CSS and JS are present.
 *
 * The render runs under MutationRenderScope so hooks riding the entity
 * render can tell it from a page load: a boot-only delivery (a drupalSettings
 * envelope the client reads once) stands down instead of rebuilding itself
 * into the asset commands of every mutation.
 */
class SubtreeRenderer {

  /**
   * Constructs a SubtreeRenderer.
   *
   * @param \Drupal\daedalus_blueprint_api\Render\CanvasBuilderInterface $canvasBuilder
   *   The canvas-builder socket: the host's canvas as a render array.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer.
   * @param \Drupal\daedalus_blueprint_api\Render\TargetKeyMapper $targetKeyMapper
   *   The target-to-selectable-key mapper.
   * @param \Drupal\Core\Render\AttachmentsResponseProcessorInterface $attachmentsProcessor
   *   The AJAX response attachments processor.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack, source of the request's `ajax_page_state` and the
   *   request the mutation-render flag is raised on.
   * @param \Drupal\daedalus_blueprint\Host\HostResolver $hostResolver
   *   The host resolver: a translation of the host's entity is rendered as
   *   the host of that translation.
   */
  public function __construct(
    protected CanvasBuilderInterface $canvasBuilder,
    protected RendererInterface $renderer,
    protected TargetKeyMapper $targetKeyMapper,
    protected AttachmentsResponseProcessorInterface $attachmentsProcessor,
    protected RequestStack $requestStack,
    protected HostResolver $hostResolver,
  ) {}

  /**
   * Renders the re-render subtrees for a mutation.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The mutated host (the working copy, not a fresh load).
   * @param array<int, array{uuid: string, field_name: string}|string> $targets
   *   The immediate change targets from the blueprint result; a string
   *   target is a selectable key used verbatim.
   * @param string $view_mode
   *   The view mode the client is displaying.
   * @param string|null $langcode
   *   The language being edited, or NULL for the entity's default.
   *
   * @return array{markup: array<string, string>, attachments: array}
   *   Markup keyed by selectable key, and AJAX asset commands to play before
   *   the swap. Both empty when no canvas builder renders the host.
   */
  public function render(HostInterface $host, array $targets, string $view_mode = 'full', ?string $langcode = NULL): array {
    if (!$this->renders($host)) {
      return ['markup' => [], 'attachments' => []];
    }

    $keys = $this->targetKeyMapper->map($host, $targets);
    $canvas = $this->canvas($host, $view_mode, $langcode);

    return [
      'markup' => $this->extractSubtrees($canvas['html'], $keys, $host->uuid()),
      'attachments' => $this->buildAssetCommands($canvas['attachments']),
    ];
  }

  /**
   * Whether a canvas builder renders the host at all.
   *
   * A config entity's host (the config backend's) has no canvas; a door
   * that takes any host asks this before canvas().
   */
  public function renders(HostInterface $host): bool {
    return $this->canvasBuilder->applies($host);
  }

  /**
   * Renders a host's whole canvas, as the mutation response does.
   *
   * The one render every door over a working copy shares: the mutation
   * response cuts its swapped subtrees out of it, and the peek door cuts
   * the one element it shows. The render runs under the mutation-render
   * flag (the canvas build is lazy, its hooks fire inside
   * renderInIsolation, so the flag spans both, and MutationRenderScope
   * restores it however the render ends) and writes no render cache
   * entry: the working copy may differ from the saved entity, and a live
   * page must never serve it.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host (the working copy, not a fresh load).
   * @param string $view_mode
   *   The view mode to render.
   * @param string|null $langcode
   *   The language to render, or NULL for the entity's default; a
   *   translation of the host's entity is rendered as the host of that
   *   translation.
   *
   * @return array{html: string, attachments: array}
   *   The whole-canvas markup and the attachments the render bubbled.
   *
   * @throws \LogicException
   *   When no canvas builder renders the host; ask
   *   CanvasBuilderInterface::applies() first at a door that takes any
   *   host.
   */
  public function canvas(HostInterface $host, string $view_mode = 'full', ?string $langcode = NULL): array {
    $entity = $host->entity();
    if ($langcode !== NULL && $entity instanceof TranslatableInterface && $entity->hasTranslation($langcode)) {
      $host = $this->hostResolver->fromEntity($entity->getTranslation($langcode));
    }

    [$html, $attachments] = MutationRenderScope::during($this->requestStack->getCurrentRequest(), function () use ($host, $view_mode): array {
      $build = $this->canvasBuilder->build($host, $view_mode);
      unset($build['#cache']['keys']);
      $html = (string) $this->renderer->renderInIsolation($build);
      return [$html, BubbleableMetadata::createFromRenderArray($build)->getAttachments()];
    });

    return ['html' => $html, 'attachments' => $attachments];
  }

  /**
   * Extracts the subtree markup for each selectable key.
   *
   * @param string $html
   *   The whole-entity rendered markup.
   * @param string[] $keys
   *   The selectable keys to extract.
   * @param string $entity_uuid
   *   The root entity UUID.
   *
   * @return array<string, string>
   *   Outer HTML keyed by selectable key; the whole-entity markup under the
   *   root entity key when extraction is not possible.
   */
  protected function extractSubtrees(string $html, array $keys, string $entity_uuid): array {
    $entity_key = SelectableKey::entity($entity_uuid);
    $markup = [];

    if (!in_array($entity_key, $keys, TRUE)) {
      $dom = Html::load($html);
      $xpath = new \DOMXPath($dom);
      foreach ($keys as $key) {
        $node = $this->findSubtree($xpath, $key);
        if ($node === NULL) {
          // Whether a key is stamped is a render-time fact — a composed
          // block renders its fields itself, so a block-field key may name
          // an element that never exists. Degrade to the nearest stamped
          // ancestor key (its subtree subsumes the target) before falling
          // back to the whole entity.
          $key = $this->ancestorKey($key);
          $node = $key === NULL ? NULL : $this->findSubtree($xpath, $key);
        }
        if ($node === NULL) {
          $markup = [];
          break;
        }
        $markup[$key] = (string) $dom->saveHTML($node);
      }
    }

    return $markup === [] ? [$entity_key => $html] : $markup;
  }

  /**
   * The outer HTML of the element a rendered fragment stamps with a key.
   *
   * The single-key twin of the extraction render() performs over the entity
   * markup, for a subscriber that renders a fragment of its own (a render
   * that lives outside the entity, such as the theme's page title) and
   * needs it in the contract's shape: the keyed element alone, so the
   * client's swap replaces exactly the element that carries the key. The
   * peek door cuts the element it shows out of canvas() with it.
   *
   * @param string $html
   *   The rendered fragment.
   * @param string $key
   *   The selectable key to extract.
   *
   * @return string|null
   *   The keyed element's outer HTML, or NULL when nothing in the fragment
   *   carries the key.
   */
  public function subtree(string $html, string $key): ?string {
    $dom = Html::load($html);
    $node = $this->findSubtree(new \DOMXPath($dom), $key);
    return $node === NULL ? NULL : (string) $dom->saveHTML($node);
  }

  /**
   * Finds the rendered subtree stamped with a selectable key.
   */
  protected function findSubtree(\DOMXPath $xpath, string $key): ?\DOMNode {
    return $xpath->query('//*[@' . SelectableKey::ATTRIBUTE . '="' . $key . '"]')->item(0);
  }

  /**
   * The nearest ancestor selectable key that render stamps independently.
   *
   * Only the block-field form has a stamped ancestor short of the entity
   * root: the block root. Every other form's containing key IS the root,
   * which the caller's whole-entity fallback already covers.
   *
   * @param string $key
   *   The selectable key that had no rendered element.
   *
   * @return string|null
   *   The ancestor key, or NULL when only the root contains the target.
   */
  protected function ancestorKey(string $key): ?string {
    $parts = explode(':', $key);
    if ($parts[0] === 'block' && count($parts) === 3) {
      return SelectableKey::block($parts[1]);
    }
    return NULL;
  }

  /**
   * Resolves render attachments into AJAX asset commands.
   *
   * Reuses core's attachments processor, which diffs the attached libraries
   * against the current request's `ajax_page_state` so already-loaded assets
   * are not sent again.
   *
   * @param array $attachments
   *   The attachments bubbled during rendering.
   *
   * @return array
   *   Rendered AJAX command arrays (add_css, add_js, settings).
   */
  protected function buildAssetCommands(array $attachments): array {
    if ($attachments === []) {
      return [];
    }
    // No page state means the diff cannot tell "nothing loaded" from "state
    // not sent" and would re-ship the entire script stack: re-executing
    // drupal.js resets window.Drupal (destroying the client app), and the
    // settings command's ajaxPageState, unioned against an empty
    // already-loaded set, would overwrite the client's list with a poisoned
    // subset. A swap missing new assets degrades; a full re-ship kills the
    // session — so a stateless request gets markup with no asset commands.
    $request = $this->requestStack->getCurrentRequest();
    $page_state = $request?->attributes->get('ajax_page_state') ?? $request?->query->all()['ajax_page_state'] ?? $request?->request->all()['ajax_page_state'] ?? NULL;
    if ($page_state === NULL) {
      return [];
    }
    $response = new AjaxResponse();
    $response->setAttachments($attachments);
    $this->attachmentsProcessor->processAttachments($response);
    // An asset command with no assets is not just a no-op: core's client
    // implementation resolves add_css/add_js through loadjs.ready(), whose
    // callback never fires for an empty bundle list, so an empty command
    // hangs every command queued behind it, including the markup swap this
    // renderer exists to deliver. Core never emits one itself, but the
    // attachments processor's collection renderers are decoratable, and a
    // decorator producing non-link elements (they are dropped from the
    // command's attribute list) can empty a command out.
    return array_values(array_filter(
      $response->getCommands(),
      fn (array $command): bool => !in_array($command['command'] ?? NULL, ['add_css', 'add_js'], TRUE) || !empty($command['data']),
    ));
  }

}
