<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\Core\Render\Markup;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\daedalus_ui\ModeState;

/**
 * The shipped style backend: @scope CSS in an inline <style> element.
 *
 * The tag is emitted inside the entity's wrapper markup rather than attached
 * to the page, which is load-bearing: it rides the entity's render
 * cache and arrives with every subtree swap of the entity. @scope preludes
 * name their roots by selector, so the tag's DOM position never affects what
 * the CSS targets. The client's live-preview tag appends later in the
 * document and wins the cascade during typing; the swapped markup then
 * carries this authoritative tag.
 */
class CssStyleBackend implements StyleBackendInterface {

  public function __construct(
    protected CssPrinter $printer,
    protected ConditionTypePluginManager $conditionTypes,
    protected VariantStylesheet $variantStylesheet,
    protected MediaValueResolver $mediaValueResolver,
    protected NodeTreeBuilder $treeBuilder,
    protected ?ModeState $modeState = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function build(HostInterface $host, array $map): array {
    // The map in its canonical order, the document's
    // (NodeTreeBuilder::styleMapOf()): node-less keys first, then the
    // host's nodes in document order. Storage keeps write order and this
    // is the one place delivery reads the map, so every lane below prints
    // the canonical order and the tag matches the client's preview byte
    // for byte (the client derives the same order from the mirror's tree,
    // styleMapOf in style-guide.js).
    // Delivery reads the document a host renders and never authors one.
    // A content host seeds an empty override from the display's template
    // on the first read of either document door, in memory, for the next
    // save to persist (the authoring posture), so the host handed here is
    // a RENDER-ONLY host (RenderStyles::hosts(): over a disposable clone
    // of a never-edited page's entity) and this read may seed it freely:
    // the ordering then walks the seeded document, the tag carries the
    // derived container and block keys the edit canvas renders a fresh
    // page under on arrival, and the request's entity stays as loaded.
    $map = $this->treeBuilder->orderedStyleMap($host, $map);
    // The enforcement posture, and the active design system whose members
    // the variant references below resolve against (the roster is
    // partitioned on it).
    $tags = ['config:daedalus_stylish.settings', 'config:daedalus_blueprint.settings'];
    // Media tokens resolve as a pre-print map transform — the printer stays
    // the dumb, equivalence-locked emit gate and never sees a token. The
    // referenced media's cache tags merge into $tags, so a file replace or
    // delete invalidates this entity's render cache entry. The variant
    // paths below resolve their own styles the same way inside
    // VariantStylesheet::printVariant().
    $css = $this->printer->printMap($this->mediaValueResolver->resolveMap($map, $tags, $host));
    // Variant references resolve per mode. EDIT mode treats a variant as a
    // preset: its styles print inline here, rooted at the referencing key's
    // data-selectable attribute — class stamping never reaches the canvas
    // (sections build inside the canvas element's #pre_render, after
    // hook_entity_view), and the editing spine addresses elements by key.
    // LIVE delivery of wear at ELEMENT keys stays class + compiled
    // stylesheet, with the workspace-preview edge: the compiled file holds
    // the LIVE variants, so inside a workspace the referenced variants'
    // (possibly staged) revisions inline here at the same variant-class
    // scope roots. Either way this tag is later in the document than the
    // compiled <link> and wins the cascade for identical selectors.
    // daedalus_stylish_entity_view() adds the daedalus_ui_mode cache
    // context whenever the map holds refs.
    // Wear at a RULE key has no element to class and no selectable
    // attribute of its own: in every mode it prints inside the rule
    // owner's @scope with the variant's suffixes continued under the rule
    // (VariantStylesheet::ruleWearCss()), the third lane beside the two
    // element lanes above.
    $variant_css = $this->variantStylesheet->ruleWearCss($map, $tags);
    $element_css = $this->modeState?->getMode() === 'edit'
      ? $this->variantStylesheet->editModeInlineCss($map, $tags)
      : $this->variantStylesheet->workspaceInlineCss($map, $tags);
    if ($element_css !== '') {
      $variant_css = $variant_css === '' ? $element_css : "$variant_css\n$element_css";
    }
    if ($variant_css !== '') {
      // Variant CSS prints FIRST inside the tag: a layered entry's own
      // condition blocks are OVERRIDES and beat its referenced variants
      // (own beats worn), which at identical selectors and specificity is
      // decided purely by document order. The tag as a whole still sits
      // later than the compiled <link>, so inline variant resolution keeps
      // winning over the shared file regardless of this internal order.
      $css = $css === '' ? $variant_css : "$variant_css\n$css";
    }
    if (($roots = $this->containerRootsCss($map)) !== '') {
      $css = $css === '' ? $roots : "$css\n$roots";
    }
    if ($css === '') {
      return [];
    }
    return [
      '#type' => 'html_tag',
      '#tag' => 'style',
      // Identifies the authoritative tag per host for the client (preview
      // reconciliation, debugging).
      '#attributes' => ['data-daedalus-stylish-entity' => $host->uuid()],
      // The printer is the emit gate — it enforces the validator's hygiene
      // and drops anything unsafe. Xss::filterAdmin would mangle CSS text.
      '#value' => Markup::create("\n" . $css . "\n"),
      '#weight' => -100,
      // Condition thresholds and the container-roots switch live in config;
      // inlined workspace variants add their entity tags. Everything bubbles
      // into the entity's render cache entry.
      '#cache' => ['tags' => $tags],
    ];
  }

  /**
   * CSS establishing the page-level width-band query root.
   *
   * Container queries silently never apply without an ancestor query
   * container (probed), so when this entity's map styles something
   * under a container condition the tag ships the one page-container rule
   * (CssPrinter::PAGE_CONTAINER_RULE). Width bands measure that single
   * page-level root everywhere, never the styled element's own ancestors:
   * per-element containment made narrow/medium resolve against whatever
   * column happened to enclose the element, which can never be WYSIWYG
   * under the stage-posed authoring model (the write UI poses the whole
   * canvas). Nearest-ancestor resolution still hands edit mode to the
   * posed stage automatically; see the constant's docblock.
   *
   * Demand-gated exactly like before: only maps using width conditions
   * emit it, and daedalus_stylish.settings container_roots turns it off for themes
   * that manage containment themselves. Variant-carried width conditions
   * need no rule here: the compiled variants file and the workspace inline
   * fallback ship their own copies (VariantStylesheet).
   *
   * @return string
   *   The page-container rule, or ''.
   */
  protected function containerRootsCss(array $map): string {
    return $this->conditionTypes->demandsContainerRoots($map) ? CssPrinter::PAGE_CONTAINER_RULE : '';
  }

}
