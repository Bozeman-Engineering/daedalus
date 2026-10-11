<?php

declare(strict_types=1);

namespace Drupal\daedalus_ai;

use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Routing\RouteMatch;
use Drupal\daedalus_blueprint\Access\BlueprintAccessChecker;
use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\DisplayHost;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_ui\DaedalusUi;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Matcher\RequestMatcherInterface;

/**
 * Resolves a Drupal route path to the host that page is about.
 *
 * The path rides in the client's DeepChat request ("the page the user is
 * on"). Resolution delegates to daedalus_ui's derivation spine
 * (deriveHostFromRoute), so chat context answers the same route grammar as
 * every other page builder consumer: canonical entity pages (a page, the
 * site frame's canvas), core's manage-layout routes (an entity's overrides,
 * a display's defaults: the template surface), and any future editing
 * route, instead of a bespoke entity.*.canonical regex that goes stale as
 * routes are added.
 *
 * The answer is a host, not an entity: a template route is about a
 * display (a document host with no fields), and every consumer of the
 * current page (the prompt line, the page print, the selection, the
 * sub-agent token) keys on what the host says about itself.
 */
class RouteEntityResolver {

  /**
   * Per-request cache of resolved hosts keyed by path.
   *
   * @var array<string, \Drupal\daedalus_blueprint\Host\HostInterface|null>
   */
  protected array $cache = [];

  public function __construct(
    protected PathValidatorInterface $pathValidator,
    protected BlueprintAccessChecker $accessChecker,
    protected RequestMatcherInterface $router,
    protected DaedalusUi $daedalusUi,
    protected VerdictStamper $verdictStamper,
  ) {}

  /**
   * Resolves a path to the host its page is about.
   *
   * @param string $path
   *   A Drupal internal path (e.g., "/node/5" or a template's manage-layout
   *   path).
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The host, or NULL if the path does not resolve to one the current
   *   user may view.
   */
  public function resolve(string $path): ?HostInterface {
    if (array_key_exists($path, $this->cache)) {
      return $this->cache[$path];
    }

    $this->cache[$path] = $this->doResolve($path);
    return $this->cache[$path];
  }

  /**
   * The current-page facts a resolved host contributes to the prompt.
   *
   * One description for every consumer (the "Current page" reminder, the
   * sub-agent token): identity as the tools address it (entity_type and
   * entity_id are what page_edit takes), the document's kind (the type and
   * bundle the host renders: an entity's own, a template's target, a
   * blueprint's subject), for a template the sentence that names it,
   * since a display's own label is its config id and the model needs to
   * know what editing it reaches, for a saved blueprint the sentence that
   * names it and what it is for, since its document is material for hosts
   * of another kind, and the host-level verdict: what the current user may
   * do at the root (its fields, its own style key, its top-level flow)
   * with every refusal explained, so the current-page line can say in
   * advance that a page renders its template, that a translation is
   * frozen, that a workspace is required, or that a permission is missing.
   *
   * The two wordings key on the host class (a display host is a template,
   * a blueprint host a blueprint), never on fields(): a blueprint answers
   * its entity's fields like a page and is still not one.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   A resolved host.
   *
   * @return array{entity_type: string, entity_id: string, uuid: string, bundle: string, label: string, kind: ?array{entity_type: string, bundle: string}, template: ?string, blueprint: ?string, frame: ?string, verdict: array}
   *   The facts; `kind` is the document's kind read through the host's
   *   display, NULL for a host with none (the site frame); `template` is
   *   the template's name for a display host ("the default template for
   *   page nodes") and NULL otherwise; `blueprint` is the blueprint's name
   *   for a blueprint host ("the block blueprint \"Card\" for page nodes")
   *   and NULL otherwise; `frame` is the frame's name for a frame host
   *   ("the site frame of the Stark theme") and NULL otherwise; all three
   *   are NULL for a page; `verdict` is the root's verdict set
   *   (VerdictStamper::verdicts()).
   */
  public function describe(HostInterface $host): array {
    $display = $host->display();
    return [
      'entity_type' => $host->entityTypeId(),
      'entity_id' => (string) $host->id(),
      'uuid' => $host->uuid(),
      'bundle' => $host->bundle(),
      'label' => $host->label(),
      'kind' => $display === NULL ? NULL : [
        'entity_type' => $display->getTargetEntityTypeId(),
        'bundle' => $display->getTargetBundle(),
      ],
      'template' => $host instanceof DisplayHost && $display !== NULL
        ? sprintf('the %s template for %s %ss', $display->getMode(), $display->getTargetBundle(), $display->getTargetEntityTypeId())
        : NULL,
      'blueprint' => $host instanceof BlueprintHost && $display !== NULL
        ? sprintf('the %s blueprint "%s" for %s %ss', $host->scope(), $host->label(), $display->getTargetBundle(), $display->getTargetEntityTypeId())
        : NULL,
      'frame' => $host instanceof FrameHost
        ? sprintf('the site frame of the %s theme', $host->label())
        : NULL,
      'verdict' => $this->verdictStamper->verdicts($host, $host->uuid()),
    ];
  }

  /**
   * Performs the actual path-to-host resolution.
   */
  protected function doResolve(string $path): ?HostInterface {
    // The access-aware validation gate: a path the current user cannot route
    // to (or that routes nowhere) never resolves.
    $url = $this->pathValidator->getUrlIfValid($path);
    if (!$url || !$url->isRouted()) {
      return NULL;
    }

    // Re-match without access checks purely to UPCAST the route parameters
    // (the access gate already ran above): Url carries only raw parameters,
    // and the derivation spine reads upcast entities off a route match.
    $request = Request::create($path);
    $request->attributes->add($this->router->matchRequest($request));
    $host = $this->daedalusUi->deriveHostFromRoute(RouteMatch::createFromRequest($request));

    // The path is client-supplied (it rides in the DeepChat request), so route
    // access is not a proxy for view access here. Only surface the host as
    // prompt context if the current user may actually view it (a template:
    // administer its display).
    if ($host !== NULL && $this->accessChecker->hostAccess($host, 'view')) {
      return $host;
    }

    return NULL;
  }

}
