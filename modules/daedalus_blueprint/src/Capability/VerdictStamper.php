<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Capability;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\daedalus_blueprint\Capability\Policy\OwnershipPolicy;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Host\InnerRender;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;

/**
 * Stamps what an actor may do onto every read of a document.
 *
 * Enforcement and advertisement are the same answer: the op door asks the
 * resolver before a write, and every read carries the same verdicts so no
 * surface (the canvas, the AI's page print, read_element, the HTTP API)
 * interprets a cause such as ownership on its own. The one key is
 * `_verdict`, on the blueprint's root (the host: its fields, its own
 * style key, its top-level flow) and on every container and block node
 * of the lean node tree the read carries (the full read, the summary, an
 * element with its subtree; region nodes are transparent):
 * - `can`: every capability (content, configure, style, arrange, insert)
 *   to TRUE or FALSE, in the vocabulary's order.
 * - `refused`: one entry per distinct refusal, `{code, capabilities}`,
 *   the capabilities it refuses in the vocabulary's order. The root's
 *   entries also carry `message`, `hint` and `context` (the owner, the
 *   portal when the actor may open it): host-level refusals are what a
 *   surface explains (the notice at the top of the page elements panel,
 *   the AI's current-page line); an element's are what the op door would
 *   answer, and the element is not actionable, so its entry carries the
 *   code alone. An allowed capability is not an entry.
 *
 * A delivery annotation like `_element`: never stored, ignored on echo
 * (the structural validator names it among the root keys, the write
 * grammar ignores every node key beginning with `_`). The host's fields
 * carry no stamp:
 * the root's content verdict is theirs, and field edit access stays with
 * the field handlers that answer it. The lean tree builder itself stays
 * actor-free (seeding and blueprints build trees for nobody); a consumer
 * printing for an actor stamps the tree it built.
 *
 * Each stamp walks the document once for one host and one actor and asks
 * the resolver per target; nothing is memoized across calls, because a
 * memo keyed by actor goes stale the moment a request changes what an
 * actor may do (a test granting a role, a seed landing a document) and a
 * read happens once per request anyway.
 */
final class VerdictStamper {

  /**
   * The annotation key a stamped node carries.
   */
  public const KEY = '_verdict';

  public function __construct(
    private readonly CapabilityResolver $resolver,
    private readonly AccountProxyInterface $currentUser,
    private readonly NodeTreeBuilder $treeBuilder,
    private readonly HostResolver $hostResolver,
    private readonly OwnershipPolicy $ownershipPolicy,
    private readonly InnerRender $innerRender,
  ) {}

  /**
   * What a join of the render stack shows on a canvas, for an actor.
   *
   * The one answer per door or linked placement, per canvas, per actor
   * (Showing), which every surface saying what is behind a door adapts:
   * the canvas's stamps, the element annotation, the tree's shown-document
   * row, the verbs, the AI's page print. NULL for any node that is no
   * join: a section, an ordinary block, a slot block whose slot the
   * holder's tier does not declare (OwnershipPolicy::declaredSlotOf()).
   *
   * A linked placement (OwnershipPolicy::linkedOf()) shows its blueprint
   * by reference: DEFAULT when the name resolves to a published blueprint
   * (the portal when the actor may update it), NOTHING otherwise; never
   * claimable, its Detach being a verb of its own. A slot block shows, in
   * order: ROUTE when it is the frame slot hosting the route's output for
   * this canvas (InnerRender::routeSlot() with the canvas as the route
   * host: main on a page, the door defaulting to the blueprint on its
   * canvas; the document is the canvas, none on a host-less route or the
   * frame's own canvas); FILL when a document beneath the canvas fills it
   * (OwnershipPolicy::fillOf(); the document is the filler); DEFAULT when
   * its default names a published blueprint and nothing fills (the
   * document is the blueprint host, the portal when the actor may update
   * it); else NOTHING. `claimable` is DEFAULT and the door's insert verdict
   * seen from this canvas (verdictsOn()), the same door the claim op
   * (detach_blueprint with `slot`) would judge.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $canvas
   *   The canvas's host (the route host), or NULL on a route with none.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $holder
   *   The host whose document holds the node.
   * @param string $target
   *   The node's uuid.
   * @param \Drupal\Core\Session\AccountInterface|null $actor
   *   The actor, the current user when NULL.
   *
   * @return \Drupal\daedalus_blueprint\Capability\Showing|null
   *   The showing, or NULL for a node that is no join.
   */
  public function showing(?HostInterface $canvas, HostInterface $holder, string $target, ?AccountInterface $actor = NULL): ?Showing {
    $actor ??= $this->currentUser->getAccount();
    $linked = $this->ownershipPolicy->linkedOf($holder, $target);
    if ($linked !== NULL) {
      return $this->shownBlueprint($linked, $actor, FALSE);
    }
    $slot = $this->ownershipPolicy->declaredSlotOf($holder, $target);
    if ($slot === NULL) {
      return NULL;
    }
    if ($holder instanceof FrameHost && $slot->slot() === $this->innerRender->routeSlot($holder, $canvas)) {
      $document = $canvas === NULL || self::sameHost($canvas, $holder) ? NULL : Showing::documentOf($canvas);
      return new Showing(Showing::ROUTE, $document, NULL, NULL, FALSE);
    }
    $fill = $this->ownershipPolicy->fillOf($slot, $canvas);
    if ($fill !== NULL) {
      return new Showing(Showing::FILL, Showing::documentOf($fill->host), NULL, NULL, FALSE);
    }
    $default = $slot->default();
    if ($default === NULL) {
      return Showing::nothing();
    }
    return $this->shownBlueprint($default, $actor, fn (): bool => $this->verdictsOn($canvas, $holder, $target, $actor)['can'][Capability::Insert->value]);
  }

  /**
   * The showing of a blueprint named by reference: DEFAULT, or NOTHING.
   *
   * @param string $name
   *   The blueprint's machine name.
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   The actor.
   * @param bool|\Closure $claimable
   *   Whether the canvas may claim it, asked only when the name resolves.
   */
  private function shownBlueprint(string $name, AccountInterface $actor, bool|\Closure $claimable): Showing {
    $blueprint = $this->innerRender->blueprintHost($name);
    if ($blueprint === NULL) {
      return Showing::nothing();
    }
    return new Showing(
      Showing::DEFAULT,
      Showing::documentOf($blueprint),
      $name,
      $this->ownershipPolicy->portalTo($blueprint, $actor),
      is_bool($claimable) ? $claimable : $claimable(),
    );
  }

  /**
   * The verdict set on a node of a document a canvas shows.
   *
   * A render is a stack of hosts. A node of the canvas host's own stack
   * (its own document, or the template it renders inside) is the resolver's
   * question, verdicts(); a node of any other document the render shows
   * (the site frame around the route, the document of a linked blueprint)
   * is nowhere in the canvas host's tree, so the resolver would answer for
   * a target it cannot find. The render knows which host's document holds
   * the node (the inner host of the primitive, daedalus_layout's root
   * entity context), names it as the holder, and this one door answers
   * for both: the holder's membership in the canvas's stack decides, and a
   * foreign node is refused everything with the ownership policy's portal
   * (OwnershipPolicy::foreignNode()).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $canvas
   *   The canvas's host (the route host), or NULL on a route with none (a
   *   view page inside the frame): then every node is foreign.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $holder
   *   The host whose document holds the node.
   * @param string $target
   *   The node's uuid.
   * @param \Drupal\Core\Session\AccountInterface|null $actor
   *   The actor, the current user when NULL.
   *
   * @return array{can: array<string, bool>, refused: list<array{code: string, capabilities: list<string>, message: string, hint: string, context: array}>}
   *   The set, in verdicts()' shape.
   */
  public function verdictsOn(?HostInterface $canvas, HostInterface $holder, string $target, ?AccountInterface $actor = NULL): array {
    $actor ??= $this->currentUser->getAccount();
    if ($canvas !== NULL && $this->inStack($canvas, $holder)) {
      return $this->verdicts($canvas, $target, $actor);
    }
    $refusal = $this->ownershipPolicy->foreignNode($holder, $actor, $target, $canvas)->toArray();
    $can = array_fill_keys(Capability::names(), FALSE);
    $refused = [['code' => $refusal['code'], 'capabilities' => Capability::names()] + $refusal];
    // A slot door of the site frame is the canvas host's own door: insert
    // on it is the canvas's gesture (the host fills the slot from here),
    // judged by the resolver as any insert on the canvas is; the door's
    // other capabilities stay the frame's.
    if ($this->ownershipPolicy->fillsFrameDoor($canvas, $holder, $target)) {
      $insert = Capability::Insert->value;
      $verdict = $this->resolver->verdict($canvas, $actor, Capability::Insert, $target);
      $can[$insert] = $verdict->isAllowed();
      $refused[0]['capabilities'] = array_values(array_diff(Capability::names(), [$insert]));
      $door_refusal = $verdict->refusal();
      if ($door_refusal !== NULL) {
        $refused[] = ['code' => $door_refusal->code, 'capabilities' => [$insert]] + $door_refusal->toArray();
      }
    }
    return ['can' => $can, 'refused' => $refused];
  }

  /**
   * Whether a holder's document is one the canvas host's tree holds.
   *
   * The canvas host's own document, the document of the owner it is
   * rendered inside (a locked page's template), or the document its
   * render shows (the template a never-overridden page renders live, the
   * template the canvas's sample subject renders): the hosts whose nodes
   * the canvas host's tree carries and the resolver judges.
   */
  private function inStack(HostInterface $canvas, HostInterface $holder): bool {
    foreach ([$canvas, $this->hostResolver->foreignOwner($canvas), $this->hostResolver->renderHost($canvas)] as $member) {
      if ($member !== NULL && self::sameHost($member, $holder)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Whether two host objects address the same document.
   */
  private static function sameHost(HostInterface $a, HostInterface $b): bool {
    return $a->entityTypeId() === $b->entityTypeId() && $a->id() === $b->id();
  }

  /**
   * Whether an actor may do something to anything on a host.
   *
   * The question a surface asks before it mounts: the style inspector
   * exists where style is allowed somewhere, the Add panel where insert
   * is, Edit Mode where anything is. Asked of the root first (the common
   * case answers there in one verdict), then of every element of the
   * document the host renders, the same targets the read stamp walks.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   * @param \Drupal\daedalus_blueprint\Capability\Capability|null $capability
   *   The capability asked, or NULL for any of the five.
   * @param \Drupal\Core\Session\AccountInterface|null $actor
   *   The actor, the current user when NULL.
   *
   * @return bool
   *   TRUE when the root or any element allows the capability (or, with
   *   none named, any capability at all).
   */
  public function allowsAnywhere(HostInterface $host, ?Capability $capability = NULL, ?AccountInterface $actor = NULL): bool {
    $actor ??= $this->currentUser->getAccount();
    $capabilities = $capability === NULL ? Capability::cases() : [$capability];
    foreach ($this->targets($host) as $target) {
      foreach ($capabilities as $asked) {
        if ($this->resolver->verdict($host, $actor, $asked, $target)->isAllowed()) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * The targets of a host, lazily: the root, then every element uuid.
   *
   * @return \Generator<string>
   */
  private function targets(HostInterface $host): \Generator {
    yield $host->uuid();
    yield from $this->elementUuids($this->treeBuilder->build($host));
  }

  /**
   * The uuids of every container and block node of a lean tree, in order.
   *
   * @return \Generator<string>
   */
  private function elementUuids(array $nodes): \Generator {
    foreach ($nodes as $node) {
      if (!is_array($node)) {
        continue;
      }
      $uuid = $node['uuid'] ?? NULL;
      if (is_string($uuid) && $uuid !== '' && ($node['kind'] ?? '') !== 'region') {
        yield $uuid;
      }
      if (isset($node['children']) && is_array($node['children'])) {
        yield from $this->elementUuids($node['children']);
      }
    }
  }

  /**
   * The verdict set of an actor on one target.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the gesture would be made on (the translation when one is
   *   read).
   * @param string $target
   *   The element uuid, or the host's uuid for the root.
   * @param \Drupal\Core\Session\AccountInterface|null $actor
   *   The actor, the current user when NULL.
   *
   * @return array{can: array<string, bool>, refused: list<array{code: string, capabilities: list<string>, message: string, hint: string, context: array}>}
   *   The set, its refusals explained (the root's spelling).
   */
  public function verdicts(HostInterface $host, string $target, ?AccountInterface $actor = NULL): array {
    $actor ??= $this->currentUser->getAccount();
    $can = [];
    $refused = [];
    foreach (Capability::cases() as $capability) {
      $verdict = $this->resolver->verdict($host, $actor, $capability, $target);
      $can[$capability->value] = $verdict->isAllowed();
      $refusal = $verdict->refusal();
      if ($refusal === NULL) {
        continue;
      }
      $facts = $refusal->toArray();
      $key = $facts['code'] . "\0" . $facts['message'];
      $refused[$key] ??= ['code' => $facts['code'], 'capabilities' => []] + $facts;
      $refused[$key]['capabilities'][] = $capability->value;
    }
    return ['can' => $can, 'refused' => array_values($refused)];
  }

  /**
   * Stamps a serialized blueprint: its root and every node of its layout.
   *
   * @param array $blueprint
   *   The blueprint (BlueprintSerializer's full or summary shape).
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host it was read from.
   * @param \Drupal\Core\Session\AccountInterface|null $actor
   *   The actor, the current user when NULL.
   *
   * @return array
   *   The blueprint, `_verdict` on its root and on every layout node.
   */
  public function stampBlueprint(array $blueprint, HostInterface $host, ?AccountInterface $actor = NULL): array {
    $actor ??= $this->currentUser->getAccount();
    $blueprint[self::KEY] = $this->verdicts($host, $host->uuid(), $actor);
    if (isset($blueprint['layout']) && is_array($blueprint['layout'])) {
      $blueprint['layout'] = $this->stampNodes($blueprint['layout'], $host, $actor);
    }
    return $blueprint;
  }

  /**
   * Stamps one serialized element and the nodes it holds.
   *
   * The read_element shape: a container node with its subtree, a block
   * node, or a paragraph; each node with a uuid is a target.
   *
   * @param array $element
   *   The element.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host it was read from.
   * @param \Drupal\Core\Session\AccountInterface|null $actor
   *   The actor, the current user when NULL.
   *
   * @return array
   *   The element, stamped.
   */
  public function stampElement(array $element, HostInterface $host, ?AccountInterface $actor = NULL): array {
    return $this->stampNode($element, $host, $actor ?? $this->currentUser->getAccount());
  }

  /**
   * Stamps a lean node tree (NodeTreeBuilder's shape).
   *
   * @param array $tree
   *   The ordered top-level nodes.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host it was built from.
   * @param \Drupal\Core\Session\AccountInterface|null $actor
   *   The actor, the current user when NULL.
   *
   * @return array
   *   The tree, every container and block node stamped; region nodes are
   *   transparent.
   */
  public function stampTree(array $tree, HostInterface $host, ?AccountInterface $actor = NULL): array {
    return $this->stampNodes($tree, $host, $actor ?? $this->currentUser->getAccount());
  }

  /**
   * The host-level refusals as sentences, one per distinct refusal.
   *
   * The spine's words for a surface that explains a frozen page (the AI's
   * current-page line, page_read's header): the refusal's sentence and its
   * corrective, which names where the gesture belongs.
   *
   * @param array $verdict
   *   A root verdict set (verdicts()).
   *
   * @return string[]
   *   The sentences, in capability order; empty when nothing is refused.
   */
  public static function explain(array $verdict): array {
    $sentences = [];
    foreach ($verdict['refused'] ?? [] as $refusal) {
      $sentence = trim((string) ($refusal['message'] ?? ''));
      $hint = trim((string) ($refusal['hint'] ?? ''));
      if ($hint !== '') {
        $sentence = trim($sentence . ' ' . $hint);
      }
      if ($sentence !== '') {
        $sentences[] = $sentence;
      }
    }
    return $sentences;
  }

  /**
   * Stamps a list of lean nodes.
   */
  private function stampNodes(array $nodes, HostInterface $host, AccountInterface $actor): array {
    foreach ($nodes as $index => $node) {
      if (is_array($node)) {
        $nodes[$index] = $this->stampNode($node, $host, $actor);
      }
    }
    return $nodes;
  }

  /**
   * Stamps one node and recurses into its children.
   *
   * A node with a uuid is a target (a region node has none and is
   * transparent); `children` is the tree's one child axis.
   */
  private function stampNode(array $node, HostInterface $host, AccountInterface $actor): array {
    $uuid = $node['uuid'] ?? NULL;
    if (is_string($uuid) && $uuid !== '' && ($node['kind'] ?? '') !== 'region') {
      $node[self::KEY] = $this->elementVerdict($host, $uuid, $actor);
    }
    if (isset($node['children']) && is_array($node['children'])) {
      $node['children'] = $this->stampNodes($node['children'], $host, $actor);
    }
    return $node;
  }

  /**
   * An element's stamp: the codes alone, the explanation being the root's.
   */
  private function elementVerdict(HostInterface $host, string $uuid, AccountInterface $actor): array {
    $verdict = $this->verdicts($host, $uuid, $actor);
    $by_code = [];
    foreach ($verdict['refused'] as $refusal) {
      $by_code[$refusal['code']] ??= ['code' => $refusal['code'], 'capabilities' => []];
      $by_code[$refusal['code']]['capabilities'] = array_values(array_unique(array_merge(
        $by_code[$refusal['code']]['capabilities'],
        $refusal['capabilities'],
      )));
    }
    return ['can' => $verdict['can'], 'refused' => array_values($by_code)];
  }

}
