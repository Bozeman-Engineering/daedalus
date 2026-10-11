<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Layout\LayoutPluginManagerInterface;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\FieldBlock;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;

/**
 * What a blueprint needs of a host, and whether a host has it.
 *
 * A saved blueprint's document names the material it is made of: the
 * fields of the subject it places as field blocks, the inline block types,
 * the block plugins, the layouts its containers are born as and the
 * variants its elements wear. derive() reads them off a definition's
 * `layout` (the lean node tree the instantiator places, the one grammar
 * both discovery lanes serve) into one requirements map, and the author's
 * `bundles` allow-list rides beside them as a requirement of its own, as
 * does the blueprint TYPE's frame-only rule (the site chrome is the
 * frame's material: a header or footer is shown on every page by the
 * frame and reaches a page only through a slot claim, never a placement,
 * so a frame-only blueprint needs the site frame as its host). A
 * blueprint FITS a host when the host's subject bundle can place every
 * field, the site has every plugin, block type and layout, the
 * allow-list, if set, names the subject, and a frame-only blueprint sits
 * on the frame (the variants it wears are judged by the style spine's
 * apply gate at placement, see missing()).
 *
 * One judgement, three consumers: the roster record carries the derived
 * map (MaterialRecord::$requires, server-side), the canvas offer reads the
 * fit as one of its three conditions (CanvasOffer: what both halves of
 * the delivery keep for a canvas, the Add panel's roster), and
 * place_blueprint refuses a blueprint that does not fit before
 * instantiating anything (BLUEPRINT_DOES_NOT_FIT, naming the missing
 * requirement). The instantiator's FIELD_NOT_ON_HOST stays the
 * field-level backstop under it. The roster the prompt and the schema
 * read is never filtered: the AI's always-on list is the site's.
 *
 * The class lives in the spine, not the library: the delivery is spine
 * machinery that may not import a library class, and the facts judged
 * (the tree grammar, field block derivatives, block and layout plugins)
 * are the spine's own. The library derives and asks; nothing there knows
 * the rule.
 */
final class BlueprintRequirements {

  /**
   * The requirement kinds, the keys of a requirements map.
   *
   * Each holds a list of ids; derive() answers every key, [] where the
   * tree needs nothing of that kind.
   */
  public const FIELDS = 'fields';
  public const BLOCK_TYPES = 'block_types';
  public const PLUGINS = 'plugins';
  public const LAYOUTS = 'layouts';
  public const VARIANTS = 'variants';
  public const BUNDLES = 'bundles';
  public const KINDS = [self::FIELDS, self::BLOCK_TYPES, self::PLUGINS, self::LAYOUTS, self::VARIANTS, self::BUNDLES];

  /**
   * The frame kind: present in a map only when the blueprint is frame-only.
   *
   * A blueprint of a frame-only type (the library's `site_chrome`) needs
   * the site frame as its host and nothing else satisfies it: the one id
   * it lists is the frame's entity type. Absent from a map (and from
   * KINDS) for every other blueprint, so a reader that walks the map
   * sees the kind only where it binds.
   */
  public const FRAME = 'frame';

  public function __construct(
    private readonly BlockManagerInterface $blockManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?LayoutPluginManagerInterface $layoutManager = NULL,
  ) {}

  /**
   * The requirements a definition's layout spells, plus its allow-list.
   *
   * One walk over the tree: a container needs its layout, a block node
   * its field, block type or plugin, and every node's authored wearings
   * are variants it needs (derived references, a composition's
   * distribution, re-derive at placement and need nothing). Region nodes
   * are grouping; a nested blueprint reference's needs are judged when it
   * places. Ids are unique and in first-seen order.
   *
   * @param array $layout
   *   The root nodes, in the served tree grammar.
   * @param string[] $bundles
   *   The `entity_type:bundle` allow-list, [] for any host that fits.
   * @param bool $frame_only
   *   Whether the blueprint's type is frame-only (the site chrome): TRUE
   *   adds the FRAME kind, satisfied by the site frame alone.
   *
   * @return array<string, string[]>
   *   The requirements map, every kind of KINDS present, FRAME only when
   *   the blueprint is frame-only.
   */
  public static function derive(array $layout, array $bundles = [], bool $frame_only = FALSE): array {
    $requires = array_fill_keys(self::KINDS, []);
    $requires[self::BUNDLES] = array_values(array_unique(array_map('strval', $bundles)));
    self::walk($layout, $requires);
    if ($frame_only) {
      $requires[self::FRAME] = [FrameInterface::ENTITY_TYPE_ID];
    }
    return $requires;
  }

  /**
   * What a host lacks of a requirements map, [] when the blueprint fits.
   *
   * Fields are judged against the host's subject (core derived a field
   * block for the field on the subject's bundle: the instantiator's own
   * rule), plugins and layouts against their managers, block types against
   * the site's block content types, the allow-list against the subject's
   * `entity_type:bundle`, and the frame kind against the host itself (a
   * FrameHost satisfies it; a page, a template or a blueprint canvas
   * never does, whatever their subject). Variants are NOT judged here: whether
   * a wearing applies is the style spine's apply gate at placement
   * (existence, ownership, axis, scope), rules a fit cannot pre-judge
   * without a second spelling of them; the map carries them for readers
   * that ask what a blueprint is made of.
   *
   * @param array $requires
   *   A requirements map (derive()); a kind absent reads as nothing needed.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the blueprint would be placed on.
   *
   * @return array<string, string[]>
   *   The missing ids per kind, kinds with nothing missing left out; for
   *   BUNDLES the allow-list itself when the subject is not on it, for
   *   FRAME the frame's entity type when the host is not the site frame.
   */
  public function missing(array $requires, HostInterface $host): array {
    // A host with no subject (the site frame) has no fields to place and
    // is on no allow-list: every field the blueprint needs is missing, and
    // a bundle-limited blueprint never fits it.
    $subject = $host->subject();
    $entity_type = $subject?->getEntityTypeId();
    $bundle = $subject?->bundle();
    $missing = [];

    // The frame kind first: a frame-only blueprint off the frame is
    // refused for being off the frame, whatever else the host has.
    $frame = $requires[self::FRAME] ?? [];
    if ($frame !== [] && !$host instanceof FrameHost) {
      $missing[self::FRAME] = array_values($frame);
    }

    $fields = array_filter(
      $requires[self::FIELDS] ?? [],
      fn (string $field): bool => $subject === NULL || !$this->blockManager->hasDefinition(FieldBlock::pluginId($entity_type, $bundle, $field)),
    );
    $plugins = array_filter(
      $requires[self::PLUGINS] ?? [],
      fn (string $plugin_id): bool => !$this->blockManager->hasDefinition($plugin_id),
    );
    $layouts = array_filter(
      $requires[self::LAYOUTS] ?? [],
      fn (string $layout_id): bool => $this->layoutManager === NULL || !$this->layoutManager->hasDefinition($layout_id),
    );
    $block_types = $requires[self::BLOCK_TYPES] ?? [];
    if ($block_types !== []) {
      $known = $this->entityTypeManager->hasDefinition('block_content_type')
        ? array_keys($this->entityTypeManager->getStorage('block_content_type')->loadMultiple($block_types))
        : [];
      $block_types = array_filter($block_types, static fn (string $type): bool => !in_array($type, $known, TRUE));
    }
    $bundles = $requires[self::BUNDLES] ?? [];
    if ($bundles !== [] && $subject !== NULL && in_array($entity_type . ':' . $bundle, $bundles, TRUE)) {
      $bundles = [];
    }

    foreach ([
      self::FIELDS => $fields,
      self::BLOCK_TYPES => $block_types,
      self::PLUGINS => $plugins,
      self::LAYOUTS => $layouts,
      self::BUNDLES => $bundles,
    ] as $kind => $ids) {
      if ($ids !== []) {
        $missing[$kind] = array_values($ids);
      }
    }
    return $missing;
  }

  /**
   * A host's subject as a refusal spells it: `entity_type:bundle`.
   *
   * The one spelling every fit refusal uses (the placement doors, the
   * starts, describe()). A host with no subject (the site frame) is spelled
   * by its own entity type, since there is no kind to name.
   */
  public static function spellSubject(HostInterface $host): string {
    $subject = $host->subject();
    return $subject === NULL
      ? $host->entityTypeId()
      : $subject->getEntityTypeId() . ':' . $subject->bundle();
  }

  /**
   * Whether a host has everything a requirements map asks for.
   */
  public function fits(array $requires, HostInterface $host): bool {
    return $this->missing($requires, $host) === [];
  }

  /**
   * The missing requirements as one sentence fragment, for a refusal.
   *
   * "field body (not placeable on node:page) and layout layout_fancy (not
   * on this site)"; the allow-list reads "it is for node:article only";
   * the frame kind reads "the site frame" (the chrome is placed there and
   * reaches a page through a slot claim alone).
   *
   * @param array<string, string[]> $missing
   *   What missing() answered.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host judged.
   */
  public static function describe(array $missing, HostInterface $host): string {
    $spelled = self::spellSubject($host);
    $parts = [];
    foreach ($missing as $kind => $ids) {
      $list = implode(', ', $ids);
      $parts[] = match ($kind) {
        self::FIELDS => sprintf('%s %s (not placeable on %s)', count($ids) === 1 ? 'the field' : 'the fields', $list, $spelled),
        self::BLOCK_TYPES => sprintf('%s %s (not on this site)', count($ids) === 1 ? 'the block type' : 'the block types', $list),
        self::PLUGINS => sprintf('%s %s (not on this site)', count($ids) === 1 ? 'the block plugin' : 'the block plugins', $list),
        self::LAYOUTS => sprintf('%s %s (not on this site)', count($ids) === 1 ? 'the layout' : 'the layouts', $list),
        self::BUNDLES => sprintf('it is for %s only', $list),
        self::FRAME => 'the site frame',
        default => sprintf('%s %s', $kind, $list),
      };
    }
    return implode(' and ', $parts);
  }

  /**
   * What the judgement depends on beyond the roster, for a cached render.
   *
   * The roster's own metadata covers the blueprints themselves; plugin
   * definitions rebuild with every cache clear; the block content types
   * and the subject's fields are config lists.
   */
  public function cacheMetadata(): CacheableMetadata {
    return (new CacheableMetadata())->addCacheTags(['config:block_content_type_list', 'config:field_config_list']);
  }

  /**
   * Collects one node list's needs into the map, recursively.
   */
  private static function walk(array $nodes, array &$requires): void {
    foreach ($nodes as $node) {
      if (!is_array($node)) {
        continue;
      }
      switch ($node['kind'] ?? NULL) {
        case 'container':
          if (is_string($node['layout_id'] ?? NULL) && $node['layout_id'] !== '') {
            self::need($requires, self::LAYOUTS, $node['layout_id']);
          }
          break;

        case 'block':
          if (is_string($node['field'] ?? NULL) && $node['field'] !== '') {
            self::need($requires, self::FIELDS, $node['field']);
          }
          elseif (is_string($node['block_type'] ?? NULL) && $node['block_type'] !== '') {
            self::need($requires, self::BLOCK_TYPES, $node['block_type']);
          }
          elseif (is_string($node['plugin_id'] ?? NULL) && $node['plugin_id'] !== '') {
            self::need($requires, self::PLUGINS, $node['plugin_id']);
          }
          break;
      }
      foreach (is_array($node['styles'] ?? NULL) ? $node['styles'] : [] as $entry) {
        $variants = is_array($entry) ? ($entry['variants'] ?? []) : [];
        foreach (is_array($variants) && array_is_list($variants) ? $variants : [] as $reference) {
          $name = DocumentInstantiator::authoredVariant($reference);
          if (is_string($name)) {
            self::need($requires, self::VARIANTS, $name);
          }
        }
      }
      if (is_array($node['children'] ?? NULL)) {
        self::walk($node['children'], $requires);
      }
    }
  }

  /**
   * Records one id under a kind, once.
   */
  private static function need(array &$requires, string $kind, string $id): void {
    if (!in_array($id, $requires[$kind], TRUE)) {
      $requires[$kind][] = $id;
    }
  }

}
