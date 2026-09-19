<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

/**
 * Projects the page builder declarations into the client anatomy payload.
 *
 * Two client consumers read the definition tier through this one payload:
 * the page-elements markup view composes its DOM sketch from the elements
 * trees (compose.js), and the element resolver's client twin
 * (js/edit_mode/blueprint/element-resolver.js) resolves editing previews
 * against the full markup model — the same effective declarations the PHP
 * resolver consumes (tag, root.forms, present conditions, attributes,
 * contains), shipped as authored so the twin cannot diverge on its input.
 * Declaration-shaped, not selection-shaped, up front, the same way the
 * blueprint mirror ships up front: the client follows the model without a
 * round trip. One payload per page, attached as
 * drupalSettings.daedalusUi.anatomy by the page-elements sidebar
 * plugin.
 *
 * THE MARKUP MODEL ONLY rides here — the definition tier ("what a type CAN
 * render as"). Instance facts (which parts ARE present for an element's
 * current data, fields existence, presentation) reach the client as
 * per-instance `_element` annotations on the delivered blueprint, and
 * foreign sections (styling property groups, controls, default variants)
 * belong to their own modules' payloads — this projection strips them, so
 * the payload cannot become a second channel for facts that already have
 * one.
 *
 * Theme resolution is PageBuilderDeclarations::siteTheme(), the one
 * every declaration-tier consumer resolves (the AI schema fragment this
 * same projection feeds, the style guide payload, the materials roster,
 * the validators): the page builder edits the site's front pages, and the
 * client twin and the AI must read one anatomy for one blueprint whatever
 * route the sidebar happens to render on (an admin-theme route renders the
 * same site's page; the active theme is a render fact, not a declaration
 * fact). Live renders stay the other lane: the resolver resolving markup
 * at render time reads the active theme through the getters' default.
 */
class ClientAnatomy {

  /**
   * Cache tags the payload derives from.
   *
   * Attach these wherever the payload is attached: the layered declarations
   * rebuild under the page builder tag, and the site default theme (the
   * resolution) is system.theme config.
   *
   * @var string[]
   */
  public const CACHE_TAGS = ['daedalus_ui_page_builder', 'config:system.theme'];

  public function __construct(
    protected PageBuilderDeclarations $declarations,
  ) {}

  /**
   * Builds the client payload for the site default theme's declarations.
   *
   * @return array
   *   - kinds: anatomy per element kind that declares any.
   *   - blockTypes: anatomy per declared block type, EFFECTIVE (the
   *     universal block kind layered underneath) — an undeclared bundle
   *     falls back to kinds.block client-side, which is already the
   *     effective declaration.
   *   - blockPlugins: anatomy per declared plugin block, keyed by BASE
   *     plugin id, EFFECTIVE the same way against kinds.block.
   *   - layouts: anatomy per declared section layout, EFFECTIVE the same
   *     way against kinds.container.
   *   - instances: the site's declared instance namespaces
   *     (PageBuilderDeclarations::getInstances()), pattern selector =>
   *     {label, tags} — the content-borne identity-class vocabulary
   *     (markup-verify derives its prefix terms here; instance FACTS still
   *     ride the `_element.markup.instances` annotations, labels stamped).
   *     Foreign sections (styling groups, controls) are stripped exactly
   *     as everywhere else in this payload.
   *   Each anatomy carries the declaration's markup model as authored
   *   (composed, tag, root's ordered forms, and the elements tree with
   *   each part's label, contains, tag or ordered forms, present
   *   condition, attributes, and nested elements), exactly the input shape
   *   the element resolver (PHP and its client twin) consumes.
   */
  public function build(): array {
    $theme = $this->declarations->siteTheme();
    $kinds = [];
    foreach (PageBuilderDeclarations::KINDS as $kind) {
      if ($anatomy = $this->anatomy($this->declarations->getKind($kind, $theme))) {
        $kinds[$kind] = $anatomy;
      }
    }
    $block_types = [];
    foreach ($this->declarations->getBlockTypes($theme) as $bundle) {
      if ($anatomy = $this->anatomy($this->declarations->getBlockType($bundle, $theme))) {
        $block_types[$bundle] = $anatomy;
      }
    }
    $block_plugins = [];
    foreach ($this->declarations->getBlockPlugins($theme) as $plugin_id) {
      if ($anatomy = $this->anatomy($this->declarations->getBlockPlugin($plugin_id, $theme))) {
        $block_plugins[$plugin_id] = $anatomy;
      }
    }
    $layouts = [];
    foreach ($this->declarations->getLayouts($theme) as $layout_id) {
      if ($anatomy = $this->anatomy($this->declarations->getLayout($layout_id, $theme))) {
        $layouts[$layout_id] = $anatomy;
      }
    }
    $instances = [];
    foreach ($this->declarations->getInstances($theme) as $pattern => $declaration) {
      if (!is_array($declaration)) {
        continue;
      }
      $entry = [];
      if (isset($declaration['label'])) {
        $entry['label'] = (string) $declaration['label'];
      }
      if (is_array($declaration['tags'] ?? NULL)) {
        $entry['tags'] = array_map('strval', $declaration['tags']);
      }
      $instances[(string) $pattern] = $entry;
    }
    return [
      'kinds' => $kinds,
      'blockTypes' => $block_types,
      'blockPlugins' => $block_plugins,
      'layouts' => $layouts,
      'instances' => $instances,
    ];
  }

  /**
   * The markup model of one effective declaration, [] when it has none.
   */
  protected function anatomy(array $declaration): array {
    $anatomy = [];
    // The declaration's own markup-model keys ride as authored: the client
    // twin resolves against the same shape the PHP resolver consumes, so
    // any re-spelling here would be a second grammar.
    if (!empty($declaration['composed'])) {
      $anatomy['composed'] = TRUE;
    }
    if (array_key_exists('tag', $declaration)) {
      $anatomy['tag'] = $declaration['tag'];
    }
    if (isset($declaration['root'])) {
      $anatomy['root'] = $declaration['root'];
    }
    if (($elements = $this->projectElements($declaration['elements'] ?? NULL)) !== []) {
      $anatomy['elements'] = $elements;
    }
    return $anatomy;
  }

  /**
   * Projects one elements: tree, keeping the markup-model vocabulary.
   */
  protected function projectElements(mixed $elements): array {
    if (!is_array($elements)) {
      return [];
    }
    $projected = [];
    foreach ($elements as $selector => $part) {
      if (!is_array($part)) {
        continue;
      }
      $projection = [];
      if (isset($part['label'])) {
        $projection['label'] = (string) $part['label'];
      }
      if (isset($part['contains'])) {
        $projection['contains'] = $part['contains'];
      }
      // The part's markup model as authored: the tag (or, for a
      // multi-form part, the ordered forms list, per-form labels
      // included: the one element grammar, where an element is tag or
      // forms, root and part alike), the present CONDITION (a
      // definition-tier rule — which data fact decides the part; the
      // fact's per-instance ANSWER still reaches the client as `_element`
      // annotations), and the declared attributes; the resolver twin
      // consumes the whole model.
      if (array_key_exists('tag', $part)) {
        $projection['tag'] = $part['tag'];
      }
      if (isset($part['forms'])) {
        $projection['forms'] = $part['forms'];
      }
      if (isset($part['present'])) {
        $projection['present'] = $part['present'];
      }
      if (isset($part['attributes'])) {
        $projection['attributes'] = $part['attributes'];
      }
      if (($children = $this->projectElements($part['elements'] ?? NULL)) !== []) {
        $projection['elements'] = $children;
      }
      $projected[(string) $selector] = $projection;
    }
    return $projected;
  }

}
