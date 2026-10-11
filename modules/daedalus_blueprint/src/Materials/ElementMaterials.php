<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;

/**
 * What one element wears and where it was placed from, as records.
 *
 * The one read behind every "what is this element using" surface (the
 * ACTIVE SELECTION block, read-back tools): the element's style stack
 * names the variants it wears and how each wearing arrived, its lean node
 * carries the placement's blueprint stamp, and the roster knows their labels
 * and spellings, so a consumer gets records and renders them however its
 * surface speaks (MaterialsRoster::describe()). Reads the host it is
 * handed: callers decide whether that is the saved or the staged copy.
 */
class ElementMaterials {

  /**
   * The daedalus_stylish style stack, when daedalus_stylish is installed.
   *
   * Deliberately untyped (its class lives in the optional daedalus_stylish
   * module, and container compilation reflects every service class): the
   * one reader of an element's worn references, in delivery order with
   * each wearing's origin. Without it nothing wears anything: the style
   * map field type is daedalus_stylish's, so no entity carries one.
   *
   * @var object|null
   */
  protected ?object $styleStack;

  public function __construct(
    protected NodeTreeBuilder $treeBuilder,
    protected MaterialsRosterBuilder $rosterBuilder,
    ?object $styleStack = NULL,
  ) {
    $this->styleStack = $styleStack;
  }

  /**
   * The variants an address wears, in delivery order, each with its origin.
   *
   * The style stack's VARIANT-tier layers (StyleStack::stack()) at the
   * address it is given: an element key wears what its root entry
   * carries; a part key ('{root}::.class') wears what its root wears,
   * because the root's variants style the part; a rule key
   * ('{root}::> pb-text') wears what its own entry carries (delivered at
   * the rule's matches) beneath what its root wears at that selector.
   * Each layer is one wearing: a root wearing the editor applied, a
   * wearing a composition distributed (its origin named), or a fragment
   * of a composition (its slot named); a composition pair (root plus
   * fragment of one variant) is two wearings, exactly as it delivers. A
   * reference the roster no longer resolves (a deleted or unpublished
   * variant) delivers nothing and is not worn.
   *
   * The tier is the predicate, never "the layer names a variant": the
   * stack's ancestor tier carries a variant an ancestor's RULE wears
   * (its identity kept so the inspector can name it), delivered to this
   * element by the rule and never worn by it; reporting it here would
   * tell the AI the element wears what its section wears, and an
   * apply or detach at this key could never act on it.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose style map answers for the element.
   * @param string $key
   *   The address's selectable key (a style-map key: an element, a part
   *   or a rule).
   *
   * @return \Drupal\daedalus_blueprint\Materials\Wearing[]
   *   The wearings, bottom to top of the stack.
   */
  public function worn(HostInterface $host, string $key): array {
    if ($this->styleStack === NULL) {
      return [];
    }
    $map = $this->treeBuilder->styleMap($host);
    // References live on the root entry and on its rules' entries, so a
    // root the map carries nothing under wears nothing. The map's keys
    // are style-map keys by construction (the validator gates every
    // write), so the stack's key grammar never refuses one; a selectable
    // key the map never carries (a field delta) is answered here, never
    // asked of the stack.
    $pos = strpos($key, '::');
    $root_key = $pos === FALSE ? $key : substr($key, 0, $pos);
    $carried = FALSE;
    foreach ($map as $map_key => $entry) {
      $map_key = (string) $map_key;
      if (is_array($entry) && ($map_key === $root_key || str_starts_with($map_key, $root_key . '::'))) {
        $carried = TRUE;
        break;
      }
    }
    if (!$carried) {
      return [];
    }
    $roster = $this->rosterBuilder->build();
    $worn = [];
    foreach ($this->styleStack->stack($map, $key, ['host' => $host])['layers'] as $layer) {
      // The variant tier (StyleStack::TIER_VARIANT, spelled here because
      // the stack's module is optional): what this key's own carriers
      // wear.
      if ($layer['tier'] !== 'variant' || !$layer['resolved']) {
        continue;
      }
      $record = $roster->get(MaterialKind::Variant, $layer['variant']);
      if ($record !== NULL) {
        $worn[] = new Wearing($record, $layer['fragment'] ?? '', $layer['via'], $layer['viaLabel']);
      }
    }
    return $worn;
  }

  /**
   * The blueprint an element was placed from, or NULL.
   *
   * Reads the lean node's `blueprint` key (the serializer's read-only surface
   * of the instantiate stamp on a section's third-party settings or a
   * component's additional data) and resolves it against the roster. Only a
   * bare element key (`container:{uuid}` / `block:{uuid}`) names a
   * placement; a field or part key is not one. A stamp the roster no longer
   * serves (a deleted or unpublished blueprint) is residue, exactly as the
   * printer treats it: never named.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose document carries the element.
   * @param string $key
   *   The element's selectable key.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord|null
   *   The blueprint record, or NULL when the element is not a placement or
   *   its blueprint is gone.
   */
  public function placedFrom(HostInterface $host, string $key): ?MaterialRecord {
    if (!preg_match('/^(?:container|block):([^:]+)$/', $key, $match)) {
      return NULL;
    }
    $node = $this->findNode($this->treeBuilder->build($host), $match[1]);
    $blueprint = $node['blueprint'] ?? NULL;
    if (!is_string($blueprint) || $blueprint === '') {
      return NULL;
    }
    return $this->rosterBuilder->build()->get(MaterialKind::Blueprint, $blueprint);
  }

  /**
   * The style key of a placed element, or NULL when the page lacks it.
   *
   * The page language names an element by uuid; the style map keys it by
   * kind (`container:{uuid}` for a section at any depth, `block:{uuid}`
   * for a component, `entity:{uuid}` for the page itself). The one read
   * behind every gesture that takes a page-language element and needs its
   * style entry (a swatch captured from what it wears, a variant saved
   * from its look).
   */
  public function keyFor(HostInterface $host, string $uuid): ?string {
    if ($host->uuid() === $uuid) {
      return 'entity:' . $uuid;
    }
    $node = $this->findNode($this->treeBuilder->build($host), $uuid);
    return match ($node['kind'] ?? NULL) {
      'container' => 'container:' . $uuid,
      'block' => 'block:' . $uuid,
      default => NULL,
    };
  }

  /**
   * Finds the lean node with a uuid at any depth (regions are transparent).
   */
  protected function findNode(array $nodes, string $uuid): ?array {
    foreach ($nodes as $node) {
      if (!is_array($node)) {
        continue;
      }
      if (($node['uuid'] ?? NULL) === $uuid) {
        return $node;
      }
      $found = $this->findNode($node['children'] ?? [], $uuid);
      if ($found !== NULL) {
        return $found;
      }
    }
    return NULL;
  }

}
