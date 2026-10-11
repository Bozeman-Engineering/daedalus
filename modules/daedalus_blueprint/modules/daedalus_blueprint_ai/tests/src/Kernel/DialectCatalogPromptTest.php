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
      public function styles(DialectCatalog $catalog): string {
        return $this->stylesLine($catalog);
      }

      /**
       * The container's component reference line.
       */
      public function container(DialectCatalog $catalog): string {
        return $this->containerLine($catalog);
      }

      /**
       * The block element line, the blueprint sentence beside it.
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
   * A catalog over a hand-built roster of variant and blueprint records.
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
   * @param string[] $facts
   *   The condition names among $conditions that are structural facts.
   */
  protected function catalog(array $stylable, array $conditions = [], string $enforcement = StyleGuide::ENFORCEMENT_STRICT, array $facts = []): DialectCatalog {
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
      facts: $facts,
    );
  }

  /**
   * The file grammar rides the rendered section beside generate.
   *
   * Three sentences, one per surface the handle appears on: the ELEMENTS
   * header gives file="" (and generate="") its word, the values line
   * teaches url('file:<name>') beside url('generate:<prompt>'), and the
   * op set teaches the file op with the document form of the same
   * declaration (<pb-file>). Unconditional like generate's teaching: the
   * op is in the page language on every site.
   */
  public function testFileGrammarRidesTheSection(): void {
    $rendered = $this->container->get('daedalus_blueprint_ai.dialect_catalog_prompt')->render();
    $this->assertStringContainsString('; file takes the name of a file this conversation holds, generate a prompt for a new image):', $rendered);
    $this->assertStringContainsString("never wait for it); url('file:<name>') as a layer or the whole value places a file this conversation holds (it becomes media at once). Always single-quote url() arguments", $rendered);
    $this->assertStringContainsString('- file {name, type, content}: declares bytes you author (an SVG, a stylesheet, CSV, text; never binary) as a file this conversation holds under name, type its MIME (image/svg+xml, text/css, text/csv, text/plain); put it ahead of the ops that place it with file="name" or url(\'file:name\'). In a page_create document the same declaration is a top-level <pb-file name="..." type="...">the bytes</pb-file> beside <style>.', $rendered);
    $ops = $this->container->get('daedalus_blueprint.operation_handler_registry')->getPageLanguageOperations();
    $this->assertContains('file', $ops, 'The file declaration is a page-language op.');
    $this->assertStringContainsString(sprintf('These %d ops are the entire edit grammar', count($ops)), $rendered);
  }

  /**
   * The STYLES line teaches the selector grammar from the catalog.
   *
   * Both doors the rules ride are named ("through the style op" read as
   * edit-only and left a new page with no responsive answer). The
   * relative-selector shapes print with the site's own spellings: the
   * block types as types (the spine's list, the two kinds after them,
   * the text type leading the examples when the site has one), the
   * base tags, the declared parts grouped by owner, each family
   * absent from the catalog printing nothing; the sibling combinators
   * and the two qualifiers with examples in the site's spellings (the
   * example tag, the image type, another type without them); the one
   * rule the grammar exists for (style the children from the container,
   * never the look
   * copied onto each) and the one precedence sentence ride every site;
   * the rich-text identity classes ride only where the spine declares a
   * namespace, with the tag tier folded into the tag shape; the
   * generated boxes (::before / ::after, born with content: "", which
   * takes "", none or one media reference; states before the box) ride
   * every site, spelled on the example type.
   */
  public function testStylesLineTeachesTheGrammar(): void {
    $tags = ['p', 'h2', 'a'];
    $full = new DialectCatalog(
      containerLayoutId: 'layout_onecol',
      axes: [],
      blockTypes: ['image' => ['attributes' => [], 'void' => TRUE], 'text' => ['attributes' => []]],
      conditions: ['hover' => ':hover'],
      selectors: [
        'pb-container' => ['parts' => ['.band-content' => 'Content'], 'tags' => $tags],
        'pb-text' => ['parts' => ['.text-block-link' => 'Link'], 'tags' => $tags],
        'pb-image' => ['parts' => [], 'tags' => $tags],
        'pb-block' => ['parts' => [], 'tags' => $tags],
      ],
      instances: ['.s-*' => ['label' => 'Text element', 'tags' => ['p' => 'Paragraph']]],
    );
    $this->assertSame(
      'STYLES: style="" is the element\'s own base look, plain CSS declarations. Every other rule is real CSS: a page_create document carries them in one trailing <style> block, a page_edit batch in the style op. A selector is #id plus an optional relative selector into #id\'s own subtree: > * (its direct children), > pb-text (the pb-text children; types: pb-image, pb-text, pb-container, pb-block), p (every p inside it, present and future; tags: p, h2, a), .part (declared parts: pb-container .band-content; pb-text .text-block-link), joined by a space (descendant), > (direct child), + (the next sibling) or ~ (any later sibling), as in #band > pb-text .text-block-link and #band > p + p (a p right after a p among its children). A compound narrows by :has() (a relative selector from it: #band > pb-text:has(a) the pb-text children that contain an a, #band > pb-text:has(+ pb-image) the one right before a pb-image) and :not() (one compound it is not: #band > *:not(pb-image) every child but the pb-image ones). Style a container\'s children with ONE rule on the container, never the same look copied onto each child; the nearest element\'s own rule wins (#child beats #band > pb-text on that child). A rule also wears a variant on every element it matches, as a declaration in its plain block, the attribute\'s twin: class: {class} (style classes, space-separated) or {axis}: {value} (an axis attribute of the matched element), as in #band > pb-text { class: {class}; color: red; }; never under a state or wrapper, never a composition or a top-level-only axis; take it off with remove naming class or the axis. Rich text styles by element: the s-* classes in a body are element identities; #id .s-x styles that ONE element, #id .s-x > * one list\'s items; author your own s-* classes on the elements you mean to style and KEEP the s-* classes on elements you keep when rewriting a body (a dropped class orphans its styles); other tags are the text format\'s, not styling targets. A selector may end in ::before or ::after, the generated box of what it addresses (#id::after the element\'s own box, #band > pb-text::before each pb-text child\'s), a rule of its own like the others: a box is born with content: "" (its existence switch; content takes "", none or one media reference url(\'media:<uuid>\') (the image at its own size; background-image on the box sizes and layers) and nothing else, since text belongs in the element\'s content), and states go before it (#id:hover::after). States and position facts are pseudo-classes on the last compound, after its qualifiers (#band > pb-text:hover), inside the published wrappers:',
      $this->prompt()->styles($full),
    );
    $this->assertSame(
      'STYLES: style="" is the element\'s own base look, plain CSS declarations. Every other rule is real CSS: a page_create document carries them in one trailing <style> block, a page_edit batch in the style op. A selector is #id plus an optional relative selector into #id\'s own subtree: > * (its direct children), > pb-container (the pb-container children; types: pb-container, pb-block), joined by a space (descendant), > (direct child), + (the next sibling) or ~ (any later sibling), as in #band > pb-container > * and #band > pb-container + pb-container (a pb-container right after a pb-container among its children). A compound narrows by :has() (a relative selector from it: #band > pb-container:has(pb-block) the pb-container children that contain a pb-block, #band > pb-container:has(+ pb-block) the one right before a pb-block) and :not() (one compound it is not: #band > *:not(pb-block) every child but the pb-block ones). Style a container\'s children with ONE rule on the container, never the same look copied onto each child; the nearest element\'s own rule wins (#child beats #band > pb-container on that child). A rule also wears a variant on every element it matches, as a declaration in its plain block, the attribute\'s twin: class: {class} (style classes, space-separated) or {axis}: {value} (an axis attribute of the matched element), as in #band > pb-container { class: {class}; color: red; }; never under a state or wrapper, never a composition or a top-level-only axis; take it off with remove naming class or the axis. A selector may end in ::before or ::after, the generated box of what it addresses (#id::after the element\'s own box, #band > pb-container::before each pb-container child\'s), a rule of its own like the others: a box is born with content: "" (its existence switch; content takes "", none or one media reference url(\'media:<uuid>\') (the image at its own size; background-image on the box sizes and layers) and nothing else, since text belongs in the element\'s content), and states go before it (#id:hover::after). States and position facts are pseudo-classes on the last compound, after its qualifiers (#band > pb-container:hover), inside the published wrappers:',
      $this->prompt()->styles($this->catalog([])),
    );
  }

  /**
   * The conditions line: states, width bands named base-out, preferences.
   *
   * Each wrapper prints beside its condition name (the name is what a
   * person says, the wrapper what the page must spell), the width bands
   * are taught base-out with one narrow example, and the line grows by
   * data: a family the catalog lacks prints nothing, and a catalog with
   * states alone carries no wrapper closure. The position facts print
   * between the states and the bands by their wrappers, the shipped ten
   * (the four negations spelled as :not() wrappers) in catalog order.
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
    $facts = [
      'first-child' => ':first-child',
      'last-child' => ':last-child',
      'only-child' => ':only-child',
      'empty' => ':empty',
      'odd' => ':nth-child(odd)',
      'even' => ':nth-child(even)',
      'not-first-child' => ':not(:first-child)',
      'not-last-child' => ':not(:last-child)',
      'not-only-child' => ':not(:only-child)',
      'not-empty' => ':not(:empty)',
    ];
    $this->assertSame(
      'states :hover; position facts :first-child, :last-child, :only-child, :empty, :nth-child(odd), :nth-child(even), :not(:first-child), :not(:last-child), :not(:only-child), :not(:empty) (the addressed element\'s place among its siblings, spelled like a state: #band > pb-text:first-child); width bands narrow @container (max-width: 767px) (base-out: the default, no wrapper, IS the wide look and each band adapts it downward; @container (max-width: 767px) { #hero-title { font-size: 2.5rem } } steps a headline down on phones; there is no wide wrapper). Wrappers verbatim: no other @media/@container preludes exist.',
      $this->prompt()->conditions($this->catalog([], ['hover' => ':hover'] + $facts + ['narrow' => '@container (max-width: 767px)'], facts: array_keys($facts))),
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
   * The cached blueprint sentence carries the blueprint contract, grows by data.
   *
   * With no blueprint in the roster the block line says nothing about
   * blueprints; with one it states the two placement shapes (the
   * reference for a copy as is, the printed fragment with the author's
   * content under blueprint="id"), where a placement lands (a page band
   * at the top level only, anything else wherever a container or block
   * sits), that the reference tag takes no style or class, that owned
   * looks are wearable inside a placement and address children by
   * position, that a saved blueprint is a page of its own read and edited
   * through the page tools as entity_type blueprint, and that blueprints
   * are blocks and examples, never a boundary. The blueprints skill that
   * carried these is gone; this line is their one home, and the old
   * "never add it by hand" is gone with the contract that forbade
   * authoring the label.
   */
  public function testBlockLineCarriesBlueprintPlacement(): void {
    $bare = $this->prompt()->block($this->rosterCatalog([]));
    $this->assertStringStartsWith('- <pb-block field="name"/> renders one of the page\'s own fields', $bare);
    $this->assertStringContainsString('<pb-block plugin="plugin_id"/> renders an existing plugin block.', $bare);
    $this->assertStringNotContainsString('pb-blueprint', $bare);

    $line = $this->prompt()->block($this->rosterCatalog([
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'hero',
        label: 'Hero',
        description: 'A hero band.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'hero'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
      ),
    ]));
    $this->assertStringStartsWith($bare, $line);
    $this->assertStringContainsString('<pb-blueprint name="blueprint_id"/> places a saved blueprint (the design system\'s BLUEPRINTS below) as is', $line);
    $this->assertStringContainsString('a page band (top-level only) at the document\'s top level, never inside a container; any other blueprint wherever a container or block may sit; the reference tag itself takes no style or class', $line);
    $this->assertStringContainsString('To place one with your own content, write its printed fragment (the schema prints the favorites in full, a group with its group parameter) with your content and blueprint="blueprint_id" on the root: that label is what makes the element a placement, so keep it when you rewrite one', $line);
    $this->assertStringContainsString('Inside a placement the blueprint\'s owned looks (listed under it) are wearable; they address the placement\'s children by position, so keep the shape where you keep the look', $line);
    $this->assertStringContainsString('A blueprint saved on this site is a page of its own: page_read (entity_type blueprint, its entity_id) prints its body with ids and page_edit changes it for every later placement; a shipped one is read-only.', $line);
    $this->assertStringContainsString('Blueprints are pre-approved blocks and examples to build with or beside, never a boundary', $line);
    $this->assertStringNotContainsString('by hand', $line);

    // A page blueprint is a start, never a placement: a roster holding
    // only page scope leaves the block line bare, since there is nothing
    // <pb-blueprint> could place and no BLUEPRINTS block below.
    $page_only = $this->prompt()->block($this->rosterCatalog([
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'landing',
        label: 'Landing',
        description: 'A whole landing page.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'landing'],
        blueprintScope: MaterialRecord::SCOPE_PAGE,
      ),
    ]));
    $this->assertSame($bare, $page_only);
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
      new MaterialRecord(kind: MaterialKind::Variant, id: 'columns', label: 'Columns', group: 'layout', spellings: [$slot('layout') => 'columns'], scope: Address::fromString('kind:container')),
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
