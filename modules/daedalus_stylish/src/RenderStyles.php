<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_layout\RootEntityRegistry;

/**
 * The style maps a rendered entity shows: its own and its render host's.
 *
 * Styling follows the host. A rendered entity owns a style store of its
 * own (the content host's field: its root and field keys) and renders a
 * document some host owns (HostResolver::renderHost(): its own override, or
 * its display's template). The two coincide on an overridden page and come
 * apart on a template render, where the display's map styles the sections
 * and blocks of every un-overridden entity of the bundle. This service is
 * the one reading of that split, consumed by CSS delivery
 * (daedalus_stylish_entity_view()) and live attribution demand
 * (DaedalusStylishLiveAttribution) alike, so what is stamped is exactly
 * what is styled.
 *
 * A template render splits the display's map in two lanes:
 * - SHARED: the element entries (container, block, field keys), identical
 *   for every entity rendering the template. They deliver once per display
 *   per page.
 * - INLINE: the display's document-level entries (entity:{display uuid}
 *   and its parts), re-keyed onto the rendered entity's own root key: the
 *   entity's markup root is the document root of that render, in every
 *   mode (daedalus_ui stamps the same entity key there for editors), so
 *   the template's root styles apply there and the entity's own root
 *   entries layer over them (own beats template, per condition and
 *   property). The entity's own map is always inline: it is the entity's.
 *
 * Nested Layout Builder content (a block_content rendered inside a
 * section) shows its ROOT's maps: daedalus_layout's root entity registry
 * names the root, and the root's render host answers.
 *
 * A render reads and never authors. A content host seeds an empty
 * override from its display's template on the first read of either of
 * its document doors (ContentHost: the sections or the style map), in
 * memory, for the editor's next stage or save to persist, which is the
 * authoring posture. So a never-edited page is read here through a host
 * over a DISPOSABLE CLONE of its entity (readHost(), the deserializer's
 * validate_only posture): the seed runs on the clone, the print carries
 * the seeded keys (the derived container and block uuids the edit canvas
 * renders the page under on arrival), and the request's shared entity
 * stays as loaded, so a later save of it persists nothing the render did.
 * The lanes are still judged on the real entity (HostResolver::renderHost()
 * reads the override as saved): a never-edited page keeps rendering its
 * template's shared lane, and its inline lane gains the seeded keys.
 */
class RenderStyles {

  /**
   * The render-only hosts of this request, by entity object.
   *
   * @var \WeakMap<\Drupal\Core\Entity\EntityInterface, array{host: \Drupal\daedalus_blueprint\Host\HostInterface, stored: string|null}>
   */
  protected \WeakMap $readHosts;

  public function __construct(
    protected HostResolver $hostResolver,
    protected ?RootEntityRegistry $rootEntityRegistry = NULL,
  ) {
    $this->readHosts = new \WeakMap();
  }

  /**
   * The hosts a rendered entity's styles come from.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity visible at the render seam.
   * @param \Drupal\Core\Entity\Display\EntityViewDisplayInterface|null $display
   *   The display rendering it (hook_entity_view's), or NULL for the
   *   entity's own full-mode display.
   *
   * @return array{own: \Drupal\daedalus_blueprint\Host\HostInterface, document: \Drupal\daedalus_blueprint\Host\HostInterface}|null
   *   The entity's own host (a render-only host: over a disposable clone
   *   of a never-edited page's entity, see readHost()) and the host of
   *   the document it renders (the same object when the entity renders
   *   its own), or NULL when nothing hosts the entity (nor, for nested
   *   content, its root).
   */
  public function hosts(EntityInterface $entity, ?EntityViewDisplayInterface $display = NULL): ?array {
    if ($entity->getEntityTypeId() === 'block_content') {
      $root = $this->rootEntityRegistry?->getRoot($entity);
      if ($root !== NULL && $this->hostResolver->hosts($root)) {
        // A nested block renders inside its root's document; the root's
        // own display decides the host.
        return $this->hosts($root);
      }
    }
    if (!$this->hostResolver->hosts($entity)) {
      return NULL;
    }
    // The lanes are judged on the entity as loaded (its override as
    // saved), never on the render-only host, whose seed would make a
    // never-edited page look overridden.
    $host = $this->hostResolver->fromEntity($entity);
    $document = $this->hostResolver->renderHost($host, $display);
    $own = $this->readHost($host);
    return ['own' => $own, 'document' => $document === $host ? $own : $document];
  }

  /**
   * An entity's own style map as a render reads it.
   *
   * The map of the entity's own host, never a template's (a field key is
   * the field's own entity's), read through the render-only host so a
   * field render of a never-edited page seeds no request entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The rendered entity.
   *
   * @return array
   *   The map; [] for an entity nothing hosts or with no style store.
   */
  public function ownMap(EntityInterface $entity): array {
    if (!$this->hostResolver->hosts($entity)) {
      return [];
    }
    $host = $this->hostResolver->fromEntity($entity);
    if (!$host->hasStyleStore()) {
      return [];
    }
    return $this->readHost($host)->styleMap();
  }

  /**
   * The host a render reads an entity's store and document through.
   *
   * A host whose override holds anything cannot seed and is read as is;
   * core's blank section counts (a page started and emptied owns its
   * empty document, ContentHost::seed()). One whose first read would seed
   * (a truly empty override, the seed's own condition) is read through a
   * host over a clone of its entity: the seed lands on the clone, once per
   * entity object per request, so delivery's many reads of one render
   * (the two lanes, every live-attribution demand, every field) share one
   * seed. The clone is kept while the entity's stored map is the one it
   * was taken from, so a render, a save and a render again in one request
   * (a kernel test's shape) read the saved map.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The entity's host.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface
   *   The host to read through.
   */
  protected function readHost(HostInterface $host): HostInterface {
    $entity = $host->entity();
    if (!$host->hasDocument() || !$entity instanceof FieldableEntityInterface) {
      return $host;
    }
    if ($entity->get(ContentHost::LAYOUT_FIELD)->getSections() !== []) {
      return $host;
    }
    $store = $host->styleMapDefinition();
    $stored = $store === NULL ? NULL : $entity->get($store->getName())->value;
    $memo = $this->readHosts[$entity] ?? NULL;
    if ($memo !== NULL && $memo['stored'] === $stored) {
      return $memo['host'];
    }
    $read = $this->hostResolver->fromEntity(clone $entity);
    $this->readHosts[$entity] = ['host' => $read, 'stored' => $stored];
    return $read;
  }

  /**
   * The two lanes of styles a rendered entity shows.
   *
   * @param array{own: \Drupal\daedalus_blueprint\Host\HostInterface, document: \Drupal\daedalus_blueprint\Host\HostInterface} $hosts
   *   The hosts, as hosts() answers them.
   *
   * @return array{inline: array, shared: array}
   *   'inline': the map delivered inside the entity's own markup (its own
   *   map, layered over the template's re-keyed document-level entries on
   *   a template render); 'shared': the template's element entries,
   *   delivered once per display per page ([] when the entity renders its
   *   own document).
   */
  public function maps(array $hosts): array {
    $own = $hosts['own'];
    $document = $hosts['document'];
    $own_map = $own->hasStyleStore() ? $own->styleMap() : [];
    if ($document === $own || !$document->hasStyleStore()) {
      return ['inline' => $own_map, 'shared' => []];
    }
    ['root' => $root, 'elements' => $elements] = static::splitRoot($document->styleMap(), $document->uuid());
    return [
      'inline' => static::layer(static::rekeyRoot($root, $document->uuid(), $own->uuid()), $own_map),
      'shared' => $elements,
    ];
  }

  /**
   * Every key a rendered entity's styles address, both lanes.
   *
   * @return string[]
   *   The keys, [] when nothing styles the entity.
   */
  public function keys(EntityInterface $entity): array {
    $hosts = $this->hosts($entity);
    if ($hosts === NULL) {
      return [];
    }
    $maps = $this->maps($hosts);
    return array_keys($maps['shared'] + $maps['inline']);
  }

  /**
   * Splits a map into its document-level entries and its element entries.
   *
   * @param array $map
   *   The style map.
   * @param string $uuid
   *   The map host's uuid: entity:{uuid} and its parts are document-level.
   *
   * @return array{root: array, elements: array}
   *   The two partitions, keys preserved.
   */
  public static function splitRoot(array $map, string $uuid): array {
    $root_key = SelectableKey::entity($uuid);
    $root = [];
    $elements = [];
    foreach ($map as $key => $entry) {
      $key = (string) $key;
      if ($key === $root_key || str_starts_with($key, $root_key . '::')) {
        $root[$key] = $entry;
      }
      else {
        $elements[$key] = $entry;
      }
    }
    return ['root' => $root, 'elements' => $elements];
  }

  /**
   * Re-keys document-level entries from one host's root onto another's.
   *
   * @param array $root
   *   Document-level entries keyed entity:{from}[::part].
   * @param string $from
   *   The uuid the keys carry.
   * @param string $to
   *   The uuid they are re-keyed onto.
   *
   * @return array
   *   The entries keyed entity:{to}[::part].
   */
  public static function rekeyRoot(array $root, string $from, string $to): array {
    $rekeyed = [];
    $prefix = SelectableKey::entity($from);
    foreach ($root as $key => $entry) {
      $rekeyed[SelectableKey::entity($to) . substr((string) $key, strlen($prefix))] = $entry;
    }
    return $rekeyed;
  }

  /**
   * Layers one map over another: the upper wins per condition and property.
   *
   * Keys only one map holds pass through. On a shared key the entry is
   * merged one level deep: each condition block takes the upper's
   * properties over the lower's, and the upper's "variants" list replaces
   * the lower's when present (a reference list is one statement, not a
   * property bag).
   *
   * @param array $under
   *   The map beneath (the template's document-level entries).
   * @param array $over
   *   The map on top (the entity's own).
   *
   * @return array
   *   The layered map.
   */
  public static function layer(array $under, array $over): array {
    $layered = $under;
    foreach ($over as $key => $entry) {
      if (!isset($layered[$key]) || !is_array($entry) || !is_array($layered[$key])) {
        $layered[$key] = $entry;
        continue;
      }
      foreach ($entry as $condition => $value) {
        if ($condition === 'variants' || !is_array($value) || !is_array($layered[$key][$condition] ?? NULL)) {
          $layered[$key][$condition] = $value;
          continue;
        }
        $layered[$key][$condition] = $value + $layered[$key][$condition];
      }
    }
    return $layered;
  }

}
