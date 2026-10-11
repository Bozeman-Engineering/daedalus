<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\ContainerLayout;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\layout_builder\Section;

/**
 * Resolves a variant's slot keys over an element's staged subtree.
 *
 * The server twin of subtreeSlotTable() in js/src/variant-scope.js: the
 * same walk over the same staged layout. Capture (client-side) translates
 * a composition's concrete child keys into a variant's positional slot
 * keys; resolveSlotKeys() is the INVERSE translation apply-time
 * distribution needs — a variant's slot keys back to the concrete map
 * keys they address on this staged layout, so each resolved child's own
 * entry receives its {variant, fragment} reference. Position becomes
 * identity at that mutation moment and only there: after binding, the
 * references are identity-bound, so detach and delivery read them and
 * never resolve slots (subtreeKeys(), the membership half, still serves
 * the group gestures on both apply and detach).
 *
 * The walk mirrors the capture contract:
 * - Only INLINE placements are addressable (block(type,n) types are the
 *   bundles StyleMapValidator::resolveBlockBundle() resolves, inline
 *   placements only) — a non-inline placement is transparent both to
 *   capture and to this walk.
 * - field(name) paths ride their block (fields are not in the layout
 *   tree).
 * - Nested containers are components embedding a Section value (containers
 *   all the way down); container(n) counts them positionally in the
 *   parent's ordered flow and recursion opens their children under the
 *   container's path.
 * - Only CONTAINER layouts (ContainerLayout::isContainer()) carry a slot
 *   space: their children are one ordered flow the positional grammar can
 *   address. A multi-region layout's children live in named slots the
 *   grammar no longer speaks, so it contributes no targets (it is still
 *   addressable AS a child of a container via container(n)).
 */
class SubtreeSlotKeys {

  protected const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

  /**
   * Constructs the resolver; its dependencies are optional for tests only.
   *
   * The adapter and the layout manager ship with daedalus_stylish's hard
   * dependencies (daedalus_blueprint and layout_builder's
   * layout_discovery); they are optional only so guide-focused kernel
   * tests can boot without the layout_builder chain.
   */
  public function __construct(
    protected ?DaedalusLayoutAdapter $daedalusLayoutAdapter = NULL,
    protected ?LayoutPluginManagerInterface $layoutManager = NULL,
  ) {}

  /**
   * Resolves an element's slot keys to the map keys they address, staged.
   *
   * The inverse of the capture translation: capture turns a child's map key
   * into a positional slot path; this turns a variant's slot path back into
   * the concrete key of the child it addresses on THIS element's staged
   * subtree — where apply-time distribution binds the child's {variant,
   * fragment} reference. Apply is the ONE consumer: once bound, references
   * are identity-bound and every later gesture (detach included) reads
   * them without resolving slots. A trailing field(name) segment rides its
   * block's resolution, exactly like the client fit walk (fields are not
   * in the layout tree).
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose staged document resolves the paths.
   * @param string $root_key
   *   The element the slot keys are relative to (container:{uuid} or
   *   block:{uuid}; anything else carries no slot space).
   * @param string[] $relatives
   *   Element-relative style keys holding a slot path, optionally with a
   *   '::.class' part suffix. Path-less keys ('' and '::.class') are not
   *   slot keys and are skipped.
   *
   * @return string[]
   *   Resolved keys by relative key. A slot the staged layout does not
   *   carry is omitted — it has no element to bind to, and binds nothing
   *   forever (bind-once).
   */
  public function resolveSlotKeys(HostInterface $host, string $root_key, array $relatives): array {
    $walk = $relatives === [] ? NULL : $this->walk($host, $root_key);
    if ($walk === NULL) {
      return [];
    }

    $resolved = [];
    foreach ($relatives as $relative) {
      $relative = (string) $relative;
      $path = strstr($relative, '::', TRUE);
      $part = $path === FALSE ? '' : substr($relative, strlen($path));
      $path = $path === FALSE ? $relative : $path;
      if ($path === '') {
        continue;
      }
      $key = $walk['targets'][$path] ?? NULL;
      if ($key === NULL && preg_match('~^(?:(?<block>.+)/)?field\((?<field>[a-z0-9_]+)\)$~', $path, $matches)) {
        // field(name) rides its block: a bare field path opens on a plain
        // block root, a nested one on the block its prefix resolves to.
        $block = $matches['block'] === '' ? $walk['root_block'] : ($walk['targets'][$matches['block']] ?? NULL);
        if (is_string($block) && preg_match('/^block:' . self::UUID . '$/', $block)) {
          $key = $block . ':' . $matches['field'];
        }
      }
      if ($key !== NULL) {
        $resolved[$relative] = $key . $part;
      }
    }
    return $resolved;
  }

  /**
   * The concrete element keys an element's staged subtree currently holds.
   *
   * The membership set for group gestures: a gesture on a composition root
   * (same-axis replace removing a distributed variant, detach-all) scopes
   * to the CURRENT subtree, and this is that subtree as element keys — the
   * root itself plus every nested container and inline block the
   * walk finds. Field keys (block:{uuid}:{field}) and part keys
   * ({key}::.class) are not listed; they belong to the subtree exactly
   * when their element's key does, so membership tests match them by
   * their element prefix.
   *
   * @return string[]
   *   The element keys, [] when the root carries no slot space or the
   *   layout cannot be resolved.
   */
  public function subtreeKeys(HostInterface $host, string $root_key): array {
    $walk = $this->walk($host, $root_key);
    if ($walk === NULL) {
      return [];
    }
    return array_values(array_unique([$root_key, ...array_values($walk['targets'])]));
  }

  /**
   * The slot path each concrete key of an element's staged subtree holds.
   *
   * The capture-direction translation (the client's subtreeSlotTable()):
   * a nested container's or inline block's map key => its positional slot
   * path relative to the root ("container(1)/block(text,2)"), the inverse
   * of resolveSlotKeys(). A plain block root carries itself with the empty
   * path, so its block:{uuid}:{field} keys translate to bare field(name)
   * paths. Field keys ride their block (VariantCapture appends the
   * field(name) segment); part suffixes ride their base key.
   *
   * @return array<string, string>
   *   Concrete key => slot path; [] when the root carries no slot space or
   *   the layout cannot be resolved.
   */
  public function slotPaths(HostInterface $host, string $root_key): array {
    $walk = $this->walk($host, $root_key);
    if ($walk === NULL) {
      return [];
    }
    $paths = array_flip($walk['targets']);
    if ($walk['root_block'] !== NULL) {
      $paths[$walk['root_block']] = '';
    }
    return $paths;
  }

  /**
   * Walks the root's staged subtree into the slot-space structures.
   *
   * The 'targets' table maps each formatted slot path to the concrete map
   * key it addresses (nested containers and inline blocks; fields resolve
   * through their block in resolveSlotKeys()). 'root_block' carries a
   * plain block root's own key, the base bare field(name) paths open on.
   *
   * @return array{targets: array<string, string>, root_block: ?string}|null
   *   The structures, or NULL when the layout cannot be resolved — an
   *   out-of-layout element degrades to root-and-parts behavior, never an
   *   error.
   */
  protected function walk(HostInterface $host, string $root_key): ?array {
    if ($this->daedalusLayoutAdapter === NULL) {
      return NULL;
    }
    if (!preg_match('/^(container|block):(' . self::UUID . ')$/', $root_key, $matches)) {
      return NULL;
    }
    [, $form, $root_uuid] = $matches;
    $storage = $host->sectionStorage();
    if ($storage === NULL) {
      return NULL;
    }

    $acc = [
      'targets' => [],
      'root_block' => NULL,
    ];
    if ($form === 'container') {
      $section = $this->findSection($host, $root_uuid, $storage);
      if ($section === NULL) {
        return NULL;
      }
      $this->walkSection($section, '', $acc);
    }
    else {
      // A block root opens at its fields: block:{uuid}:{field} keys
      // translate to field(name) paths and back.
      $acc['root_block'] = $root_key;
    }
    return $acc;
  }

  /**
   * Resolves a section by daedalus_layout uuid, root-level or nested, staged.
   */
  protected function findSection(HostInterface $host, string $uuid, object $storage): ?Section {
    foreach ($this->daedalusLayoutAdapter->rootList($storage)->getSections() as $section) {
      if ($this->daedalusLayoutAdapter->getSectionUuid($section) === $uuid) {
        return $section;
      }
    }
    return $this->daedalusLayoutAdapter->locateNestedSection($host, $uuid, $storage)['section'] ?? NULL;
  }

  /**
   * Collects a container's slot targets, recursively.
   *
   * The positional counting mirrors the capture walk (subtreeSlotTable in
   * variant-scope.js): blocks per bundle, nested containers positionally,
   * one ordered flow in weight order — only inline blocks and embedded
   * sections are addressable, so only they advance their counts. Only
   * container layouts carry a slot space: a multi-region section's
   * children live in named slots the grammar no longer speaks, so it
   * contributes nothing (its own container(n) target was added by its
   * parent's walk).
   */
  protected function walkSection(Section $section, string $prefix, array &$acc): void {
    $definition = $this->layoutManager?->getDefinition($section->getLayoutId(), FALSE);
    if (!ContainerLayout::isContainer($definition)) {
      return;
    }
    $counts = [];
    $containers = 0;
    foreach ($this->sectionFlow($section, ContainerLayout::storageRegion($definition)) as $component) {
      $embedded = $this->daedalusLayoutAdapter->embeddedSection($component);
      if ($embedded !== NULL) {
        $containers++;
        $container_path = ($prefix === '' ? '' : $prefix . '/') . sprintf('container(%d)', $containers);
        $embedded_uuid = $this->daedalusLayoutAdapter->getSectionUuid($embedded);
        if ($embedded_uuid !== NULL) {
          $acc['targets'][$container_path] = "container:$embedded_uuid";
        }
        $this->walkSection($embedded, $container_path, $acc);
        continue;
      }
      $bundle = $this->daedalusLayoutAdapter->getInlineBlockType($component);
      if ($bundle === NULL) {
        // Non-inline placements are transparent: no slot.
        continue;
      }
      $counts[$bundle] = ($counts[$bundle] ?? 0) + 1;
      // The grammar's machine-name charset gates what may print into a
      // slot path.
      if (preg_match('/^[a-z0-9_]+$/', $bundle)) {
        $acc['targets'][($prefix === '' ? '' : $prefix . '/') . sprintf('block(%s,%d)', $bundle, $counts[$bundle])] = 'block:' . $component->getUuid();
      }
    }
  }

  /**
   * A container's ordered component flow.
   *
   * The storage region's components in weight order, then any component
   * sitting in a stale region name (broken data is still walked, exactly
   * like the membership walk always did), keeping deterministic order.
   *
   * @return \Drupal\layout_builder\SectionComponent[]
   *   The components, weight-ordered per region bucket.
   */
  protected function sectionFlow(Section $section, string $storage_region): array {
    $regions = [$storage_region];
    foreach ($section->getComponents() as $component) {
      $regions[] = $component->getRegion();
    }
    $flow = [];
    foreach (array_unique($regions) as $region) {
      array_push($flow, ...array_values($section->getComponentsByRegion($region)));
    }
    return $flow;
  }

}
