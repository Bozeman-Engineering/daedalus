<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DialectParser;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint_ai\DialectCatalogPrompt;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\Vocabulary\VocabularyProse;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRoster;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Snapshot of the cached prompt's STYLES lines over a fixture.
 *
 * The per-kind rosters are taught by property name in every posture
 * (the widest kind in full, the others as deltas against it), rendered
 * by the prose generator the catalog carries beside them; only the
 * sentence saying what happens outside a roster varies. One roster per
 * kind at every placement depth. The conditions line names
 * every wrapper beside its condition and teaches the width bands
 * base-out (the responsive grammar). The container line carries the
 * birth rule (the dialect is a total statement: an omitted axis is not
 * worn), spelled from the catalog's top-level-only axes. Pinned verbatim
 * so a spelling drift fails here instead of silently in the prompt.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
class DialectCatalogPromptTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * 'key' is an ai dependency.
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'daedalus_blueprint',
    'daedalus_blueprint_ai',
  ];

  /**
   * The real prompt service with its stylable projection exposed.
   */
  protected function prompt(): object {
    return new class(
      $this->container->get('daedalus_blueprint.dialect_catalog_builder'),
      $this->container->get('daedalus_blueprint.operation_handler_registry'),
      $this->container->get('daedalus_blueprint.materials_roster_builder'),
      $this->container->get('daedalus_blueprint.active_design_system'),
    ) extends DialectCatalogPrompt {

      /**
       * The stylable roster lines for a catalog.
       */
      public function stylable(DialectCatalog $catalog): array {
        return $this->stylableLines($catalog);
      }

      /**
       * The conditions line for a catalog.
       */
      public function conditions(DialectCatalog $catalog): string {
        return $this->conditionsLine($catalog);
      }

      /**
       * The STYLES line.
       */
      public function styles(bool $rich_text): string {
        return $this->stylesLine($rich_text);
      }

      /**
       * The container's component reference line.
       */
      public function container(DialectCatalog $catalog): string {
        return $this->containerLine($catalog);
      }

      /**
       * The block element line, the stencil sentence beside it.
       */
      public function block(DialectCatalog $catalog): string {
        return $this->blockLine($catalog);
      }

      /**
       * The class rule line.
       */
      public function classes(DialectCatalog $catalog): string {
        return $this->classLine($catalog);
      }

    };
  }

  /**
   * A catalog over a hand-built roster of variant and stencil records.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $records
   *   The roster's records.
   */
  protected function rosterCatalog(array $records): DialectCatalog {
    return new DialectCatalog(
      containerLayoutId: 'layout_onecol',
      axes: [],
      blockTypes: [],
      conditions: [],
      roster: new MaterialsRoster($records, new CacheableMetadata()),
    );
  }

  /**
   * A catalog carrying only the rosters and conditions under test.
   *
   * @param array<string, string[]> $stylable
   *   Map of kind => granted property names; each becomes the kind's vocabulary
   *   roster, hand-built the way a live site builds it from its
   *   declarations, with the prose generator at the given posture beside
   *   them (the way the styling layer contributes both).
   * @param array $conditions
   *   The condition declarations (name => CSS wrapper) the catalog carries.
   * @param string $enforcement
   *   The style guide's enforcement posture (a StyleGuide::ENFORCEMENT_*
   *   value).
   */
  protected function catalog(array $stylable, array $conditions = [], string $enforcement = StyleGuide::ENFORCEMENT_STRICT): DialectCatalog {
    $rosters = [];
    foreach ($stylable as $kind => $properties) {
      $rosters[$kind] = new VocabularyRoster(Address::kind($kind), 'prompt', ['stylable' => $properties], enforcement: $enforcement);
    }
    return new DialectCatalog(
      containerLayoutId: 'layout_onecol',
      axes: [],
      blockTypes: [],
      conditions: $conditions,
      stylable: $rosters,
      prose: new VocabularyProse($enforcement),
    );
  }

  /**
   * The STYLES line names both doors the conditioned rules ride.
   *
   * "Through the style op" read as edit-only and left a new page with no
   * responsive answer; the page_create document's trailing <style> block
   * is named beside the style op.
   */
  public function testStylesLineNamesBothDoors(): void {
    $line = $this->prompt()->styles(FALSE);
    $this->assertStringStartsWith('STYLES: style="" is the element\'s own base look, plain CSS declarations. Conditions and parts are real CSS rules: a page_create document carries them in one trailing <style> block, a page_edit batch in the style op. Selectors are limited to #id, #id .part-class, #id:state, #id > * (direct children), inside the published wrappers:', $line);
    $this->assertStringContainsString('(direct children), and the rich-text instance selectors below, inside the published wrappers:', $this->prompt()->styles(TRUE));
  }

  /**
   * The conditions line: states, width bands named base-out, preferences.
   *
   * Each wrapper prints beside its condition name (the name is what a
   * person says, the wrapper what the page must spell), the width bands
   * are taught base-out with one narrow example, and the line grows by
   * data: a family the catalog lacks prints nothing, and a catalog with
   * states alone carries no wrapper closure.
   */
  public function testConditionsLine(): void {
    $conditions = [
      'hover' => ':hover',
      'focus-visible' => ':focus-visible',
      'narrow' => '@container (max-width: 767px)',
      'medium' => '@container (min-width: 768px) and (max-width: 1023px)',
      'dark' => '@media (prefers-color-scheme: dark)',
      'reduced-motion' => '@media (prefers-reduced-motion: reduce)',
    ];
    $this->assertSame(
      'states :hover, :focus-visible; width bands narrow @container (max-width: 767px), medium @container (min-width: 768px) and (max-width: 1023px) (base-out: the default, no wrapper, IS the wide look and each band adapts it downward; @container (max-width: 767px) { #hero-title { font-size: 2.5rem } } steps a headline down on phones; there is no wide wrapper); preferences dark @media (prefers-color-scheme: dark), reduced-motion @media (prefers-reduced-motion: reduce). Wrappers verbatim: no other @media/@container preludes exist.',
      $this->prompt()->conditions($this->catalog([], $conditions)),
    );
    $this->assertSame(
      'states :hover.',
      $this->prompt()->conditions($this->catalog([], ['hover' => ':hover'])),
    );
    $this->assertSame(
      'preferences dark @media (prefers-color-scheme: dark). Wrappers verbatim: no other @media/@container preludes exist.',
      $this->prompt()->conditions($this->catalog([], ['dark' => '@media (prefers-color-scheme: dark)'])),
    );
  }

  /**
   * The widest kind in full, deltas for the rest.
   */
  public function testStylableLines(): void {
    $block = [
      'padding',
      'padding-top',
      'padding-bottom',
      'padding-left',
      'padding-right',
      'padding-inline',
      'margin',
      'gap',
      'color',
      'font-size',
    ];
    $container = ['padding-top', 'padding-bottom', 'padding-inline', 'margin', 'gap', 'color', 'font-size', 'display'];
    $lines = $this->prompt()->stylable($this->catalog(
      ['container' => $container, 'block' => $block, 'field' => $block],
    ));
    $this->assertSame([
      'Each element styles ONLY the properties its kind permits (everything else is refused):',
      '- block: ' . implode(', ', $block),
      // Kinds print widest first (a stable sort: field ties block and
      // keeps its contributed order).
      '- field: same as block',
      '- container: the block roster, minus padding, padding-left, padding-right; plus display',
    ], $lines);
  }

  /**
   * The roster is taught in every posture; only its first line varies.
   *
   * A guarded or open site teaches the same per-kind lines under the
   * sentence saying a recognized property outside the roster is accepted
   * (the strict line says everything else is refused). No posture is
   * ever named.
   */
  public function testRosterIsTaughtInEveryPosture(): void {
    $block = ['padding', 'margin', 'color'];
    $strict = $this->prompt()->stylable($this->catalog(['block' => $block, 'container' => ['padding', 'margin']]));
    foreach ([StyleGuide::ENFORCEMENT_GUARDED, StyleGuide::ENFORCEMENT_OPEN] as $mode) {
      $lines = $this->prompt()->stylable($this->catalog(['block' => $block, 'container' => ['padding', 'margin']], [], $mode));
      $this->assertSame('Each element kind\'s roster is the properties its design system names; any other recognized CSS property with a valid value is accepted too (an unrecognized name is refused, with suggestions), so prefer the roster:', $lines[0]);
      $this->assertSame(array_slice($strict, 1), array_slice($lines, 1), "The $mode roster lines are the strict lines below the sentence.");
      $this->assertDoesNotMatchRegularExpression('/\b(strict|guarded|enforcement)\b/i', implode("\n", $lines));
    }
    $this->assertSame('Each element styles ONLY the properties its kind permits (everything else is refused):', $strict[0]);
  }

  /**
   * No roster prints no lines, whatever the posture.
   */
  public function testNoRosterPrintsNothing(): void {
    $this->assertSame([], $this->prompt()->stylable($this->catalog([])));
    $this->assertSame([], $this->prompt()->stylable($this->catalog([], [], StyleGuide::ENFORCEMENT_GUARDED)));
  }

  /**
   * The cached stencil sentence carries the stencil contract, grows by data.
   *
   * With no stencil in the roster the block line says nothing about
   * stencils; with one it states the two placement shapes (the reference
   * for a copy as is, the printed fragment with the author's content
   * under stencil="id"), where a placement lands (a page band at the top
   * level only, anything else wherever a container or block sits), that
   * the reference tag takes no style or class, that owned looks are
   * wearable inside a placement and address children by position, and
   * that stencils are blocks and examples, never a boundary. The
   * stencils skill that carried these is gone; this line is their one
   * home, and the old "never add it by hand" is gone with the contract
   * that forbade authoring the label.
   */
  public function testBlockLineCarriesStencilPlacement(): void {
    $bare = $this->prompt()->block($this->rosterCatalog([]));
    $this->assertStringStartsWith('- <pb-block plugin="plugin_id"/> renders an existing plugin block.', $bare);
    $this->assertStringNotContainsString('pb-stencil', $bare);

    $line = $this->prompt()->block($this->rosterCatalog([
      new MaterialRecord(
        kind: MaterialKind::Stencil,
        id: 'hero',
        label: 'Hero',
        description: 'A hero band.',
        spellings: [MaterialRecord::SLOT_STENCIL => 'hero'],
        rootKind: MaterialRecord::ROOT_SECTION,
      ),
    ]));
    $this->assertStringStartsWith($bare, $line);
    $this->assertStringContainsString('<pb-stencil name="stencil_id"/> places a saved stencil (the design system\'s STENCILS below) as is', $line);
    $this->assertStringContainsString('a page band (top-level only) at the document\'s top level, never inside a container; any other stencil wherever a container or block may sit; the reference tag itself takes no style or class', $line);
    $this->assertStringContainsString('To place one with your own content, write its printed fragment (the schema shows each in full) with your content and stencil="stencil_id" on the root: that label is what makes the element a placement, so keep it when you rewrite one', $line);
    $this->assertStringContainsString('Inside a placement the stencil\'s owned looks (listed under it) are wearable; they address the placement\'s children by position, so keep the shape where you keep the look', $line);
    $this->assertStringContainsString('Stencils are pre-approved blocks and examples to build with or beside, never a boundary', $line);
    $this->assertStringNotContainsString('by hand', $line);
  }

  /**
   * The class line carries the re-apply clause where variants exist.
   *
   * A composition distributes over the children present when worn; the
   * re-resolve gesture (off, then on again) is stated once, on the cached
   * line, since the styling skill that carried it is gone.
   */
  public function testClassLineCarriesCompositionReapply(): void {
    $this->assertSame(
      'Utility classes do not exist: class is empty on this site (no saved style variants yet).',
      $this->prompt()->classes($this->rosterCatalog([])),
    );
    $line = $this->prompt()->classes($this->rosterCatalog([
      new MaterialRecord(
        kind: MaterialKind::Variant,
        id: 'hero_glow',
        label: 'Hero glow',
        group: 'style',
        spellings: [MaterialRecord::SLOT_CLASS => 'hero_glow'],
        scope: Address::fromString('kind:container'),
      ),
    ]));
    $this->assertStringStartsWith('Utility classes do not exist: class="" wears saved style variants by name', $line);
    $this->assertStringContainsString('A composition variant distributes over the children present when it is worn; children added later get nothing until you take the class off and wear it again.', $line);
  }

  /**
   * The container line states the birth rule from the catalog's axes.
   *
   * The dialect is a total statement (every document and fragment runs as
   * a dressed batch), so an omitted axis is not worn. With a width axis
   * spelling "site" the line names width="site" as the band's statement
   * and lists the other top-level-only axes (the house axes a band wears);
   * without that spelling it names the top-level-only axes alone; with no
   * axes at all only the omission rule prints. A depth-blind axis (layout)
   * is never a band axis.
   */
  public function testContainerLineStatesTheBirthRule(): void {
    $root = [MaterialRecord::APPLIES_DEPTH => MaterialRecord::DEPTH_ROOT];
    $slot = static fn (string $axis): string => MaterialRecord::attrSlot('kind:container', $axis);
    $house = $this->axisCatalog(['width', 'surface', 'layout'], [
      new MaterialRecord(kind: MaterialKind::Variant, id: 'container_site', label: 'Site', group: 'width', spellings: [$slot('width') => 'site'], scope: Address::fromString('kind:container'), applies: $root),
      new MaterialRecord(kind: MaterialKind::Variant, id: 'container_full', label: 'Full', group: 'width', spellings: [$slot('width') => 'full'], scope: Address::fromString('kind:container'), applies: $root),
      new MaterialRecord(kind: MaterialKind::Variant, id: 'surface_plain', label: 'Plain', group: 'surface', spellings: [$slot('surface') => 'plain'], scope: Address::fromString('kind:container'), applies: $root),
      new MaterialRecord(kind: MaterialKind::Variant, id: 'surface_tint', label: 'Tint', group: 'surface', spellings: [$slot('surface') => 'tint'], scope: Address::fromString('kind:container'), applies: $root),
      new MaterialRecord(kind: MaterialKind::Variant, id: 'row', label: 'Columns', group: 'layout', spellings: [$slot('layout') => 'columns'], scope: Address::fromString('kind:container')),
    ]);
    $this->assertSame(
      '- <pb-container width(site|full; top-level only) surface(plain|tint; top-level only) layout(columns)> — the one grouping element, nestable; children stack unless layout says otherwise, and every direct child of a columns container is a column. An omitted axis is not worn (a document or fragment is a total statement: nothing is stamped at birth), so a page band states width="site" (the site\'s content column; omit it only for a deliberately full-bleed band) and its other top-level-only axes (surface).',
      $this->prompt()->container($house),
    );

    $width_only = $this->axisCatalog(['width'], [
      new MaterialRecord(kind: MaterialKind::Variant, id: 'container_site', label: 'Site', group: 'width', spellings: [$slot('width') => 'site'], scope: Address::fromString('kind:container'), applies: $root),
    ]);
    $this->assertStringEndsWith(', so a page band states width="site" (the site\'s content column; omit it only for a deliberately full-bleed band).', $this->prompt()->container($width_only));

    $no_site = $this->axisCatalog(['width'], [
      new MaterialRecord(kind: MaterialKind::Variant, id: 'container_wide', label: 'Wide', group: 'width', spellings: [$slot('width') => 'wide'], scope: Address::fromString('kind:container'), applies: $root),
    ]);
    $this->assertStringEndsWith(', so a page band states each of its top-level-only axes (width) itself.', $this->prompt()->container($no_site));

    $this->assertSame(
      '- <pb-container> — the one grouping element, nestable; children stack unless layout says otherwise, and every direct child of a columns container is a column. An omitted axis is not worn (a document or fragment is a total statement: nothing is stamped at birth).',
      $this->prompt()->container($this->catalog([])),
    );
  }

  /**
   * The <a> and <button> sugar read the call-to-action hint off the catalog.
   *
   * The text element's button axis (its first value) leads, a class named
   * "button" on its roster follows, and a site offering neither gets the
   * bare form: the corrective never names a spelling the roster lacks
   * (class="button" was hard-coded and off the sample roster).
   */
  public function testCallToActionSugarDerives(): void {
    $text = ['text' => ['attributes' => ['tag' => ['field' => 'field_tag', 'values' => ['h1', 'p']]]]];
    $catalog = static fn (array $records, array $axes = []): DialectCatalog => new DialectCatalog(
      containerLayoutId: 'layout_onecol',
      axes: $axes,
      blockTypes: $text,
      conditions: [],
      roster: new MaterialsRoster($records, new CacheableMetadata()),
    );
    $slot = MaterialRecord::attrSlot('block_type:text', 'button');
    $axis = $catalog([
      new MaterialRecord(kind: MaterialKind::Variant, id: 'button_primary', label: 'Primary', group: 'button', spellings: [$slot => 'primary'], scope: Address::fromString('block_type:text')),
      new MaterialRecord(kind: MaterialKind::Variant, id: 'button_secondary', label: 'Secondary', group: 'button', spellings: [$slot => 'secondary'], scope: Address::fromString('block_type:text')),
    ], ['block_type:text' => ['button' => []]]);
    $this->assertSame('use <pb-text url="...">; add button="primary" for a call-to-action', DialectParser::sugarCorrective('a', $axis));
    $this->assertSame('use <pb-text url="..." button="primary">', DialectParser::sugarCorrective('button', $axis));

    $class = $catalog([
      new MaterialRecord(kind: MaterialKind::Variant, id: 'button', label: 'Button', group: 'style', spellings: [MaterialRecord::SLOT_CLASS => 'button'], scope: Address::fromString('block_type:text')),
    ]);
    $this->assertSame('use <pb-text url="...">; add class="button" for a call-to-action', DialectParser::sugarCorrective('a', $class));
    $this->assertSame('use <pb-text url="..." class="button">', DialectParser::sugarCorrective('button', $class));

    $bare = $catalog([]);
    $this->assertSame('use <pb-text url="...">', DialectParser::sugarCorrective('a', $bare));
    $this->assertSame('use <pb-text url="...">', DialectParser::sugarCorrective('button', $bare));
    $this->assertSame('use <pb-text tag="h2">', DialectParser::sugarCorrective('h2', $bare));
    $this->assertNull(DialectParser::sugarCorrective('pb-text', $bare));
  }

  /**
   * A catalog whose container carries the given axis variants.
   */
  protected function axisCatalog(array $order, array $records): DialectCatalog {
    return new DialectCatalog(
      containerLayoutId: 'layout_onecol',
      axes: ['kind:container' => array_fill_keys($order, [])],
      blockTypes: [],
      conditions: [],
      roster: new MaterialsRoster($records, new CacheableMetadata()),
    );
  }

}
