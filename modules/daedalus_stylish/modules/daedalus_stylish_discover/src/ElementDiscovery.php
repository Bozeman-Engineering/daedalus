<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish_discover;

use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Theme\ThemeInitializationInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\field_sample_value\SampleValueEntityGeneratorInterface;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;

/**
 * Discovers stylable sub-elements by rendering a block type with sample data.
 *
 * A block type's parts are the CSS classes its templates emit — the same
 * tree an authored MODULE.page_builder.block_type.BUNDLE.yml would declare
 * under "elements" (the style guide is coupled to markup). This service
 * finds it mechanically: create an UNSAVED block_content entity with
 * sample field values (field_sample_value generators first, core sample
 * items as the fallback), render it through the same view-builder path an
 * inline Layout Builder block uses, and keep the class names in the
 * STRUCTURE the markup shows — a nested shell tree with contains: slots
 * where field wrappers mount, ready to layer into the declarations as-is.
 *
 * Two consumers:
 * - daedalus_stylish_discover_page_builder_declarations_alter() merges the
 *   discovered selectors into the page builder declarations for block
 *   types with no authored elements — unlabeled but functional
 *   stylability without YAML.
 * - `drush daedalus_stylish:discover BUNDLE` (base module) prints an authored-YAML
 *   scaffold from the same discovery for a developer to label and commit.
 *
 * Results are cached per (theme, bundle) under the "daedalus_stylish_discover" tag;
 * the .module hooks invalidate it (and the declarations cache, which bakes
 * the merged result in) when block_content displays, fields, or bundles
 * change. Template-only changes need a cache rebuild, like any theme edit.
 */
class ElementDiscovery {

  /**
   * Cache tag on every discovery result.
   */
  public const CACHE_TAG = 'daedalus_stylish_discover';

  /**
   * The view mode a placed inline block renders with (InlineBlock default).
   */
  public const VIEW_MODE = 'full';

  /**
   * The single-class part rule (the sub-element key charset).
   *
   * A discovered selector becomes a "::.class" style-map key and prints into
   * CSS output, so anything outside this charset is unreachable as a key and
   * unsafe to emit — it is not a part.
   */
  private const CLASS_PATTERN = '/^[A-Za-z0-9_-]+$/';

  /**
   * The class core's field template stamps on a field's wrapper element.
   *
   * Seeing it marks the element as a FIELD's markup root: everything from there
   * down is the field kind's shell (daedalus_ui ships it), not the block
   * type's. Discovery records which slot the field mounts in — a contains claim
   * on the nearest classed ancestor — and goes no deeper. The capture is the
   * hyphenated field machine name (Html::getClass), reversed to underscores for
   * the field:NAME token.
   */
  private const FIELD_WRAPPER_PATTERN = '/^field--name-([a-z0-9-]+)$/';

  /**
   * TRUE while a sample entity is rendering.
   *
   * The sample render can itself trigger a declarations build (any code on
   * the render path asking the model a question, e.g. the collapse gate);
   * the build calls the alter hook, and the alter hook calls discovery —
   * this flag breaks that cycle: the alter returns early mid-discovery, so
   * the inner build simply lacks discovered elements (the sample render
   * never needs them).
   */
  private static bool $discovering = FALSE;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SampleValueEntityGeneratorInterface $sampleGenerator,
    protected RendererInterface $renderer,
    protected CacheBackendInterface $cache,
    protected ThemeManagerInterface $themeManager,
    protected ThemeInitializationInterface $themeInitialization,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected PageBuilderDeclarations $declarations,
  ) {}

  /**
   * Whether a discovery render is currently in progress.
   */
  public static function isDiscovering(): bool {
    return self::$discovering;
  }

  /**
   * Discovers the markup shell a block type's rendered sample offers.
   *
   * @param string $bundle
   *   The block_content bundle machine name.
   * @param string|null $theme
   *   The theme to render in — templates decide the classes, so discovery is
   *   per theme. Defaults to the site default theme (the validation/schema
   *   convention).
   * @param bool $refresh
   *   Skip the cache read (the result is still written), e.g. for a
   *   developer iterating on templates via drush.
   *
   * @return array
   *   A nested shell tree in the guide's elements: shape, minus the curation
   *   keys: class selectors ('.card__title') in markup order, each mapping
   *   to an array with optional "contains" (a field:NAME token or list of
   *   them, where field wrappers mount inside the part) and optional
   *   "elements" (the nested child parts). Empty when the markup has no
   *   qualifying classes or the block type cannot render — a block type that
   *   fails to render must never fail a declarations build.
   */
  public function discover(string $bundle, ?string $theme = NULL, bool $refresh = FALSE): array {
    $theme ??= (string) $this->configFactory->get('system.theme')->get('default');
    // The "2" is the result-shape version: nested trees replaced flat
    // selector lists, and a stale flat entry must miss, not parse as a tree.
    $cid = "daedalus_stylish_discover:2:$theme:$bundle";
    if (!$refresh && ($cached = $this->cache->get($cid))) {
      return $cached->data;
    }
    self::$discovering = TRUE;
    try {
      $selectors = $this->extractTree($this->renderSample($bundle, $theme));
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('daedalus_stylish_discover')->warning('Element discovery could not render block type %bundle: @message', [
        '%bundle' => $bundle,
        '@message' => $e->getMessage(),
      ]);
      $selectors = [];
    }
    finally {
      self::$discovering = FALSE;
    }
    $this->cache->set($cid, $selectors, CacheBackendInterface::CACHE_PERMANENT, [self::CACHE_TAG]);
    // Anything on the sample render path may have built (and cached) the
    // declarations while the recursion guard was up — such a build is
    // missing every discovered element and must not survive. Reset AFTER
    // caching the discovery result so the rebuild merges from cache instead
    // of re-rendering; inside an in-progress declarations build this is
    // harmless (the outer build writes its complete result after the alter
    // returns).
    $this->declarations->resetCache();
    return $selectors;
  }

  /**
   * Renders an unsaved sample entity of the bundle in the given theme.
   */
  protected function renderSample(string $bundle, string $theme): string {
    $entity = $this->sampleGenerator->createWithSampleValues('block_content', $bundle);
    // field_sample_value only populates fields with configured generators;
    // fill the rest of the DISPLAYED fields with core sample values so
    // their templates actually render (an empty field renders nothing).
    $display = EntityViewDisplay::collectRenderDisplay($entity, self::VIEW_MODE);
    foreach (array_keys($display->getComponents()) as $name) {
      if ($entity->hasField($name) && $entity->get($name)->isEmpty()) {
        try {
          $entity->get($name)->generateSampleItems();
        }
        catch (\Throwable) {
          // An unsampleable field renders empty; discovery degrades.
        }
      }
    }
    $build = $this->entityTypeManager->getViewBuilder('block_content')->view($entity, self::VIEW_MODE);

    $active_theme = $this->themeManager->getActiveTheme();
    $switched = $active_theme->getName() !== $theme;
    if ($switched) {
      $this->themeManager->setActiveTheme($this->themeInitialization->initTheme($theme));
    }
    try {
      // renderInIsolation(): no leakage into the caller's render context —
      // this render is a probe, its cacheability is not the page's.
      return (string) $this->renderer->renderInIsolation($build);
    }
    finally {
      if ($switched) {
        $this->themeManager->setActiveTheme($active_theme);
      }
    }
  }

  /**
   * Builds the nested shell tree from rendered markup.
   *
   * The walk is breadth-first so the SHALLOWEST occurrence of a class claims
   * its tree position and every deeper duplicate collapses into it (children
   * merge there) — the guide's uniqueness rule allows a class exactly once
   * per declaration tree. Ties at equal depth resolve to document order.
   * Classless wrapper elements are skipped through: their classed
   * descendants hoist to the nearest classed ancestor's part. An element
   * carrying several qualifying classes yields sibling parts at its
   * position, with the element's children mounting under the first — the
   * model has no grammar for one element wearing two selectors.
   *
   * A field wrapper element (FIELD_WRAPPER_PATTERN) ends the descent: its
   * markup is the field kind's shell, so the block type's tree records only
   * WHERE it mounts — a field:NAME contains claim on the nearest classed
   * ancestor. A field mounting outside any classed element needs no claim:
   * unclaimed children mount at the element root (the degenerate rule).
   *
   * "js-" classes are skipped — behavior hooks by convention, not styling
   * surface — as is anything outside the part-rule charset.
   */
  protected function extractTree(string $html): array {
    if (trim($html) === '') {
      return [];
    }
    $root = (object) ['contains' => [], 'children' => []];
    $nodes = [];
    $queue = [];
    $body = Html::load($html)->getElementsByTagName('body')->item(0);
    foreach ($body?->childNodes ?? [] as $child) {
      $queue[] = [$child, $root];
    }
    while ($queue) {
      [$element, $parent] = array_shift($queue);
      if (!$element instanceof \DOMElement) {
        continue;
      }
      $classes = preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
      foreach ($classes as $class) {
        if (preg_match(self::FIELD_WRAPPER_PATTERN, $class, $match)) {
          if ($parent !== $root) {
            $parent->contains['field:' . str_replace('-', '_', $match[1])] = TRUE;
          }
          // A field wrapper contributes no parts of its own and its subtree
          // never enters the queue.
          continue 2;
        }
      }
      $node = NULL;
      foreach ($classes as $class) {
        if (str_starts_with($class, 'js-') || !preg_match(self::CLASS_PATTERN, $class)) {
          continue;
        }
        $selector = ".$class";
        if (!isset($nodes[$selector])) {
          $nodes[$selector] = (object) ['contains' => [], 'children' => []];
          $parent->children[$selector] = $nodes[$selector];
        }
        $node ??= $nodes[$selector];
      }
      foreach ($element->childNodes as $child) {
        $queue[] = [$child, $node ?? $parent];
      }
    }
    return $this->exportTree($root);
  }

  /**
   * Converts a built shell node's children into the cacheable array shape.
   */
  protected function exportTree(object $node): array {
    $tree = [];
    foreach ($node->children as $selector => $child) {
      $part = [];
      if ($child->contains !== []) {
        $claims = array_keys($child->contains);
        $part['contains'] = count($claims) === 1 ? $claims[0] : $claims;
      }
      if ($child->children !== []) {
        $part['elements'] = $this->exportTree($child);
      }
      $tree[$selector] = $part;
    }
    return $tree;
  }

}
