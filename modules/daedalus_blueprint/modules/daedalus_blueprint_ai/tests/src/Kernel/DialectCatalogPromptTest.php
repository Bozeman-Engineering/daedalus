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
 * worn), spelled from the catalog's top-level-only axes. Every element
 * line ends with its renders from the catalog's card (dialect writes
 * paired with what each renders as), and the STYLES roster labels every
 * part. Pinned verbatim so a spelling drift fails here instead of
 * silently in the prompt.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
class DialectCatalogPromptTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * 'key' and 'file' are ai dependencies (the PAGES line asks every
   * visible bundle's unsaved host whether it is a page, and ai_file's
   * fields reference file).
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'file',
    'ai',
    'daedalus_blueprint',
    'filter',
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
      $this->container->get('daedalus_blueprint.bundle_list_builder'),
    ) extends DialectCatalogPrompt {

      /**
       * The PAGES lines for a page-bundle projection.
       */
      public function pages(array $pages): array {
        return $this->pagesLines($pages);
      }

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

      /**
       * One block type's component reference line.
       */
      public function blockType(DialectCatalog $catalog, string $type): string {
        return $this->blockTypeLine($catalog, $type, $catalog->blockType($type));
      }

    };
  }

  /**
   * The shipped text block's card: the default paragraph, the link, the tag.
   */
  private const TEXT_CARD = [
    'renders' => [
      '<d-text>…</d-text>' => '<p>…</p>',
      '<d-text url="…">…</d-text>' => '<p><a class="text-block-link" href="…">…</a></p>',
      '<d-text tag="h1" url="…">…</d-text>' => '<h1><a class="text-block-link" href="…">…</a></h1>',
    ],
    'shells' => [],
  ];

  /**
   * The shipped image block's card.
   *
   * Four shapes, the picture in every one with media.
   */
  private const IMAGE_CARD = [
    'renders' => [
      '<d-image/>' => '<div></div>',
      '<d-image media="…"/> (raster media)' => '<img class="image-block-image">',
      '<d-image media="…"/> (vector media)' => '<svg class="image-block-image"></svg>',
      '<d-image media="…" url="…"/> (raster media)' => '<a href="…"><img class="image-block-image"></a>',
    ],
    'shells' => [],
  ];

  /**
   * The shipped container layout's card: shapes by depth and emptiness.
   */
  private const CONTAINER_CARD = [
    'renders' => [
      '<d-container>…</d-container> (top-level)' => '<section>…</section>',
      '<d-container>…</d-container> (nested)' => '<div>…</div>',
      '<d-container></d-container> (top-level)' => '<section data-container-empty=""></section>',
      '<d-container></d-container> (nested)' => '<div data-container-empty=""></div>',
    ],
    'shells' => [],
  ];

  /**
   * The fallback card of a tagged bundle no layer declares.
   */
  private const FALLBACK_CARD = [
    'renders' => [],
    'shells' => ['block', 'field'],
  ];

  /**
   * The block types the renders are pinned over.
   */
  private const BLOCK_TYPES = [
    'text' => [
      'attributes' => [
        'tag' => ['field' => 'field_text_tag', 'values' => ['h1', 'h2', 'p']],
        'url' => ['field' => 'field_text_url', 'kind' => 'link'],
      ],
      'body' => ['field' => 'field_text_text', 'kind' => 'plain'],
    ],
    'image' => [
      'attributes' => ['url' => ['field' => 'field_image_url', 'kind' => 'link']],
      'void' => TRUE,
    ],
    'notes' => [
      'attributes' => [],
      'body' => ['field' => 'field_notes_text', 'kind' => 'plain'],
    ],
  ];

  /**
   * A catalog over the pinned block types and the given cards.
   */
  protected function cardsCatalog(array $cards): DialectCatalog {
    return new DialectCatalog(
      containerLayoutId: 'layout_onecol',
      axes: [],
      blockTypes: self::BLOCK_TYPES,
      conditions: [],
      cards: $cards,
    );
  }

  /**
   * Every element line ends with its renders, from its card.
   *
   * The renders are the card's pairs printed as they are (the dialect
   * write, an arrow, the HTML it renders as; semicolons between), never
   * an authored sentence: the text block's three, the image's four, the
   * container's four after the birth rule, the fallback's shells as the
   * one sentence; and a catalog carrying no card for an element leaves
   * its line as it was.
   */
  public function testRenders(): void {
    $prompt = $this->prompt();
    $catalog = $this->cardsCatalog([
      'd-container' => self::CONTAINER_CARD,
      'd-text' => self::TEXT_CARD,
      'd-image' => self::IMAGE_CARD,
      'd-notes' => self::FALLBACK_CARD,
    ]);
    $this->assertSame(
      '- <d-text tag(h1|h2|p) url(link)>plain text body</d-text> (the body is field field_text_text) renders: <d-text>…</d-text> → <p>…</p>; <d-text url="…">…</d-text> → <p><a class="text-block-link" href="…">…</a></p>; <d-text tag="h1" url="…">…</d-text> → <h1><a class="text-block-link" href="…">…</a></h1>',
      $prompt->blockType($catalog, 'text'),
    );
    $this->assertSame(
      '- <d-image url(link) /> renders: <d-image/> → <div></div>; <d-image media="…"/> (raster media) → <img class="image-block-image">; <d-image media="…"/> (vector media) → <svg class="image-block-image"></svg>; <d-image media="…" url="…"/> (raster media) → <a href="…"><img class="image-block-image"></a>',
      $prompt->blockType($catalog, 'image'),
    );
    $this->assertSame(
      '- <d-notes>plain text body</d-notes> (the body is field field_notes_text) renders through the theme\'s block template, each field in its field shell.',
      $prompt->blockType($catalog, 'notes'),
    );
    $this->assertSame(
      '- <d-container> — the one grouping element, nestable; children stack unless layout says otherwise, and every direct child of a columns container is a column. An omitted axis is not worn (a document or fragment is a total statement: nothing is stamped at birth). It renders: <d-container>…</d-container> (top-level) → <section>…</section>; <d-container>…</d-container> (nested) → <div>…</div>; <d-container></d-container> (top-level) → <section data-container-empty=""></section>; <d-container></d-container> (nested) → <div data-container-empty=""></div>',
      $prompt->container($catalog),
    );

    // No card, no renders: the line is what it was.
    $bare = $this->cardsCatalog([]);
    $this->assertSame('- <d-text tag(h1|h2|p) url(link)>plain text body</d-text> (the body is field field_text_text)', $prompt->blockType($bare, 'text'));
    $this->assertSame('- <d-image url(link) />', $prompt->blockType($bare, 'image'));
    $this->assertStringEndsWith('nothing is stamped at birth).', $prompt->container($bare));
  }

  /**
   * The STYLES roster labels every part by owner, a plugin's under d-block.
   *
   * The condition a part exists under is not restated here: its owner's
   * renders show it (the anchor appears in the linked write's pair).
   */
  public function testStylesRosterLabelsParts(): void {
    $tags = ['p'];
    $catalog = new DialectCatalog(
      containerLayoutId: 'layout_onecol',
      axes: [],
      blockTypes: ['image' => ['attributes' => [], 'void' => TRUE]],
      conditions: [],
      selectors: [
        'd-container' => ['parts' => [], 'tags' => $tags],
        'd-image' => ['parts' => ['.image-block-image' => 'Image'], 'tags' => $tags],
        'd-block' => [
          'parts' => ['.site-logo' => 'Logo', '.site-slogan' => 'Slogan', '.menu' => 'Menu'],
          'tags' => $tags,
        ],
      ],
      cards: ['d-image' => self::IMAGE_CARD],
    );
    $this->assertStringContainsString(
      '.part (declared parts: d-image .image-block-image (Image); d-block .site-logo (Logo), .site-slogan (Slogan), .menu (Menu))',
      $this->prompt()->styles($catalog),
    );
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
   * The file grammar rides the rendered section beside generate.
   *
   * Three sentences, one per surface the handle appears on: the ELEMENTS
   * header gives file="" (and generate="") its word, the values line
   * teaches url('file:<name>') beside url('generate:<prompt>'), and the
   * op set teaches the file op with the document form of the same
   * declaration (<d-file>). Unconditional like generate's teaching: the
   * op is in the page language on every site.
   */
  public function testFileGrammarRidesTheSection(): void {
    $rendered = $this->container->get('daedalus_blueprint_ai.dialect_catalog_prompt')->render();
    $this->assertStringContainsString('; file takes the name of a file this conversation holds, generate a prompt for a new image):', $rendered);
    $this->assertStringContainsString("never wait for it); url('file:<name>') as a layer or the whole value places a file this conversation holds (it becomes media at once). Always single-quote url() arguments", $rendered);
    $this->assertStringContainsString('- file {name, type, content}: declares bytes you author (an SVG, a stylesheet, CSV, text; never binary) as a file this conversation holds under name, type its MIME (image/svg+xml, text/css, text/csv, text/plain); put it ahead of the ops that place it with file="name" or url(\'file:name\'). In a page_create document the same declaration is a top-level <d-file name="..." type="...">the bytes</d-file> beside <style>.', $rendered);
    $ops = $this->container->get('daedalus_blueprint.operation_handler_registry')->getPageLanguageOperations();
    $this->assertContains('file', $ops, 'The file declaration is a page-language op.');
    $this->assertStringContainsString(sprintf('These %d ops are the entire edit grammar', count($ops)), $rendered);
  }

  /**
   * The PAGES line names the page bundles, and only them, from the projection.
   *
   * The one always-on home of the bundle fact: each page bundle in the
   * spelling every page surface uses (type/bundle "Label"), a locked
   * bundle marked as rendering its template, the description whole on one
   * line with its markup and line breaks gone, a bundle with no
   * description ending at its name; with no page bundle the line says so,
   * which is what this fixture's site prints (no content type is Layout
   * Builder enabled here, so nothing is a page) between the opening
   * paragraph and ELEMENTS.
   */
  public function testPagesLine(): void {
    $prompt = $this->prompt();
    $this->assertSame([
      'PAGES (the bundles page_create makes a page of; entity_type/bundle "Label": what its pages are for; every other bundle is eb_list_bundles\'):',
      '- node/page "Page": Use pages to build with the page builder.',
      '- node/landing "Landing" (renders its template): A page inside the site template. Fill its slots.',
      '- blueprint/section "Section"',
    ], $prompt->pages([
      'node/page' => [
        'entity_type' => 'node',
        'bundle' => 'page',
        'label' => 'Page',
        'description' => "Use <em>pages</em> to build\n  with the page builder.",
        'renders_template' => FALSE,
      ],
      'node/landing' => [
        'entity_type' => 'node',
        'bundle' => 'landing',
        'label' => 'Landing',
        'description' => 'A page inside the site template. Fill its slots.',
        'renders_template' => TRUE,
      ],
      'blueprint/section' => [
        'entity_type' => 'blueprint',
        'bundle' => 'section',
        'label' => 'Section',
        'description' => '',
        'renders_template' => FALSE,
      ],
    ]));
    $none = 'PAGES: none on this site. No bundle\'s entities render as a page document, so page_create has nothing to make; eb_list_bundles lists what exists.';
    $this->assertSame([$none], $prompt->pages([]));
    $this->assertSame([], $this->container->get('daedalus_blueprint.bundle_list_builder')->getPageBundles(), 'Nothing in this fixture is a page bundle.');
    $rendered = $this->container->get('daedalus_blueprint_ai.dialect_catalog_prompt')->render();
    $this->assertStringContainsString("Never author id, data-*, or src.\n\n" . $none . "\n\nELEMENTS (", $rendered);
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
      conditions: ['hover' => ['wrapper' => ':hover', 'axis' => 'state']],
      selectors: [
        'd-container' => ['parts' => ['.section-content' => 'Content'], 'tags' => $tags],
        'd-text' => ['parts' => ['.text-block-link' => 'Link'], 'tags' => $tags],
        'd-image' => ['parts' => [], 'tags' => $tags],
        'd-block' => ['parts' => [], 'tags' => $tags],
      ],
      instances: ['.s-*' => ['label' => 'Text element', 'tags' => ['p' => 'Paragraph']]],
      cards: ['d-text' => self::TEXT_CARD],
    );
    $this->assertSame(
      'STYLES: style="" is the element\'s own base look, plain CSS declarations. Every other rule is real CSS: a page_create document carries them in one trailing <style> block, a page_edit batch in the style op. A selector is #id plus an optional relative selector into #id\'s own subtree: > * (its direct children), > d-text (the d-text children; types: d-image, d-text, d-container, d-block), p (every p inside it, present and future; tags: p, h2, a), .part (declared parts: d-container .section-content (Content); d-text .text-block-link (Link)), joined by a space (descendant), > (direct child), + (the next sibling) or ~ (any later sibling), as in #section > d-text .text-block-link and #section > p + p (a p right after a p among its children). A compound narrows by :has() (a relative selector from it: #section > d-text:has(a) the d-text children that contain an a, #section > d-text:has(+ d-image) the one right before a d-image) and :not() (one compound it is not: #section > *:not(d-image) every child but the d-image ones). Style a container\'s children with ONE rule on the container, never the same look copied onto each child; the nearest element\'s own rule wins (#child beats #section > d-text on that child). A rule also wears a variant on every element it matches, as a declaration in its plain block, the attribute\'s twin: class: {class} (style classes, space-separated) or {axis}: {value} (an axis attribute of the matched element), as in #section > d-text { class: {class}; color: red; }; never under a state or wrapper, never a composition or a top-level-only axis; take it off with remove naming class or the axis. Rich text styles by element: the s-* classes in a body are element identities; #id .s-x styles that ONE element, #id .s-x > * one list\'s items; author your own s-* classes on the elements you mean to style and KEEP the s-* classes on elements you keep when rewriting a body (a dropped class orphans its styles); other tags are the text format\'s, not styling targets. A selector may end in ::before or ::after, the generated box of what it addresses (#id::after the element\'s own box, #section > d-text::before each d-text child\'s), a rule of its own like the others: a box is born with content: "" (its existence switch; content takes "", none or one media reference url(\'media:<uuid>\') (the image at its own size; background-image on the box sizes and layers) and nothing else, since text belongs in the element\'s content), and states go before it (#id:hover::after); any other pseudo-element the browser knows ends a selector the same way (#id li::marker, #id input::placeholder, #id::selection, #id p::first-letter, #id p::first-line), stylable with the properties its specification allows and nothing else. A compound also narrows by a class (#section.open > d-text the children while the element wears a class, #section > d-text.active a child wearing one), an attribute (#section [aria-expanded="true"] > *, #section > d-text[aria-current="page"]; the operators =, ~=, |=, ^=, $=, *=) or a position pseudo-class (:first-child, :not(:last-child), :nth-child(2n+1)), each qualifying the compound it stands on, like :has() and :not() (#section > *:not(:last-child), #section:first-child > d-text); a published state on the LAST compound is the rule\'s condition (#section > d-text:hover) and a state on any compound before it qualifies that compound (#section:hover > d-text the children while the element is hovered, #section > d-text:hover a what a hovered d-text contains); a pseudo-class the site does not publish stays selector text (:visited, :checked on their compound). A selector list is ONE rule, folded into :is() where its members differ (#section > d-text, #section > d-image { } is #section > *:is(d-image, d-text)); :is() is any of several compounds at one position (#section:is(:hover, :focus-within) > d-text the children while the element is hovered or holds focus; :where() reads as :is()), and a list whose members share no shape, or whose fold would put two states on the last compound, is written as separate rules. The published states and wrappers:',
      $this->prompt()->styles($full),
    );
    $this->assertSame(
      'STYLES: style="" is the element\'s own base look, plain CSS declarations. Every other rule is real CSS: a page_create document carries them in one trailing <style> block, a page_edit batch in the style op. A selector is #id plus an optional relative selector into #id\'s own subtree: > * (its direct children), > d-container (the d-container children; types: d-container, d-block), joined by a space (descendant), > (direct child), + (the next sibling) or ~ (any later sibling), as in #section > d-container > * and #section > d-container + d-container (a d-container right after a d-container among its children). A compound narrows by :has() (a relative selector from it: #section > d-container:has(d-block) the d-container children that contain a d-block, #section > d-container:has(+ d-block) the one right before a d-block) and :not() (one compound it is not: #section > *:not(d-block) every child but the d-block ones). Style a container\'s children with ONE rule on the container, never the same look copied onto each child; the nearest element\'s own rule wins (#child beats #section > d-container on that child). A rule also wears a variant on every element it matches, as a declaration in its plain block, the attribute\'s twin: class: {class} (style classes, space-separated) or {axis}: {value} (an axis attribute of the matched element), as in #section > d-container { class: {class}; color: red; }; never under a state or wrapper, never a composition or a top-level-only axis; take it off with remove naming class or the axis. A selector may end in ::before or ::after, the generated box of what it addresses (#id::after the element\'s own box, #section > d-container::before each d-container child\'s), a rule of its own like the others: a box is born with content: "" (its existence switch; content takes "", none or one media reference url(\'media:<uuid>\') (the image at its own size; background-image on the box sizes and layers) and nothing else, since text belongs in the element\'s content), and states go before it (#id:hover::after); any other pseudo-element the browser knows ends a selector the same way (#id li::marker, #id input::placeholder, #id::selection, #id p::first-letter, #id p::first-line), stylable with the properties its specification allows and nothing else. A compound also narrows by a class (#section.open > d-container the children while the element wears a class, #section > d-container.active a child wearing one), an attribute (#section [aria-expanded="true"] > *, #section > d-container[aria-current="page"]; the operators =, ~=, |=, ^=, $=, *=) or a position pseudo-class (:first-child, :not(:last-child), :nth-child(2n+1)), each qualifying the compound it stands on, like :has() and :not() (#section > *:not(:last-child), #section:first-child > d-container); a published state on the LAST compound is the rule\'s condition (#section > d-container:hover) and a state on any compound before it qualifies that compound (#section:hover > d-container the children while the element is hovered, #section > d-container:hover d-block what a hovered d-container contains); a pseudo-class the site does not publish stays selector text (:visited, :checked on their compound). A selector list is ONE rule, folded into :is() where its members differ (#section > d-container, #section > d-block { } is #section > *:is(d-block, d-container)); :is() is any of several compounds at one position (#section:is(:hover, :focus-within) > d-container the children while the element is hovered or holds focus; :where() reads as :is()), and a list whose members share no shape, or whose fold would put two states on the last compound, is written as separate rules. The published states and wrappers:',
      $this->prompt()->styles($this->catalog([])),
    );
  }

  /**
   * The conditions line: states, width bands named base-out, preferences.
   *
   * Each wrapper prints beside its condition name (the name is what a
   * person says, the wrapper what the page must spell), the width bands
   * are taught base-out with one narrow example, and the line grows by
   * data: a family the catalog lacks prints nothing. The closing sentence
   * is the styling layer's condition sentence for the posture
   * (VocabularyProse::conditionSentence(), the same words the JSON door's
   * description carries): strict closes the set to the references, open
   * and guarded admit the literal lane the browser knows. Every
   * pseudo-class condition is a state: where an element sits among its
   * siblings is selector grammar (a position qualifier), so no position
   * family prints here.
   */
  public function testConditionsLine(): void {
    $conditions = [
      'hover' => ['wrapper' => ':hover', 'axis' => 'state'],
      'focus-visible' => ['wrapper' => ':focus-visible', 'axis' => 'state'],
      'narrow' => ['wrapper' => '@container (max-width: 767px)', 'axis' => 'width'],
      'medium' => ['wrapper' => '@container (min-width: 768px) and (max-width: 1023px)', 'axis' => 'width'],
      'dark' => ['wrapper' => '@media (prefers-color-scheme: dark)', 'axis' => 'scheme'],
      'reduced-motion' => ['wrapper' => '@media (prefers-reduced-motion: reduce)', 'axis' => 'motion'],
    ];
    $closed = ' The conditions are the published names, references whose CSS the site owns (a threshold change in the design system rewrites no style); on this site no other condition exists: a feature query, supports test or pseudo-class of your own is refused, with the published names on its axis.';
    $this->assertSame(
      'states :hover, :focus-visible; width bands narrow @container (max-width: 767px), medium @container (min-width: 768px) and (max-width: 1023px) (base-out: the default, no wrapper, IS the wide look and each band adapts it downward; @container (max-width: 767px) { #hero-title { font-size: 2.5rem } } steps a headline down on phones; there is no wide wrapper); preferences dark @media (prefers-color-scheme: dark), reduced-motion @media (prefers-reduced-motion: reduce).' . $closed,
      $this->prompt()->conditions($this->catalog([], $conditions)),
    );
    $this->assertSame(
      'states :hover; width bands narrow @container (max-width: 767px) (base-out: the default, no wrapper, IS the wide look and each band adapts it downward; @container (max-width: 767px) { #hero-title { font-size: 2.5rem } } steps a headline down on phones; there is no wide wrapper).' . $closed,
      $this->prompt()->conditions($this->catalog([], [
        'hover' => ['wrapper' => ':hover', 'axis' => 'state'],
        'narrow' => ['wrapper' => '@container (max-width: 767px)', 'axis' => 'width'],
      ])),
    );
    $this->assertSame(
      'states :hover.' . $closed,
      $this->prompt()->conditions($this->catalog([], ['hover' => ['wrapper' => ':hover', 'axis' => 'state']])),
    );
    $this->assertSame(
      'preferences dark @media (prefers-color-scheme: dark).' . $closed,
      $this->prompt()->conditions($this->catalog([], [
        'dark' => ['wrapper' => '@media (prefers-color-scheme: dark)', 'axis' => 'scheme'],
      ])),
    );
    // An open site teaches the literal lane beside the references, in the
    // styling layer's words.
    $this->assertSame(
      'states :hover. The conditions are the published names, references whose CSS the site owns (a threshold change in the design system rewrites no style) and, beside them, any literal the browser knows, written as its own CSS: a @media or @container feature query ("@container (min-width: 500px)", "@media (hover: hover)"), a @supports test or a pseudo-class state (":checked"); a literal equal to a published wrapper IS that reference, a reference is preferred where one says the same, and clauses on different axes join with "+".',
      $this->prompt()->conditions($this->catalog([], ['hover' => ['wrapper' => ':hover', 'axis' => 'state']], StyleGuide::ENFORCEMENT_OPEN)),
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
   * The cached blueprint sentence carries the contract, grows by data.
   *
   * With no blueprint in the roster the block line says nothing about
   * blueprints; with one it states the two placement shapes (the
   * reference for a copy as is, the printed fragment with the author's
   * content under blueprint="id"), where a placement lands (a section
   * at the top level only, anything else wherever a container or block
   * sits), that the reference tag takes no style or class, that owned
   * looks are wearable inside a placement and address children by
   * position, that a saved blueprint is a page of its own read and edited
   * through the page tools as entity_type blueprint, and that a blueprint
   * is a component: placed with its stamp when the section's job is its
   * job, or the section built new, never retyped without the stamp. The
   * blueprints skill that carried these is gone; this line is their one
   * home, the old "never add it by hand" is gone with the contract that
   * forbade authoring the label, and the old "examples to build with or
   * beside, never a boundary" is gone with the third outcome it licensed
   * (a retyped blueprint without its label is a hand-rolled section the site
   * cannot tell apart).
   */
  public function testBlockLineCarriesBlueprintPlacement(): void {
    $bare = $this->prompt()->block($this->rosterCatalog([]));
    $this->assertStringStartsWith('- <d-block field="name"/> renders one of the page\'s own fields', $bare);
    $this->assertStringContainsString('<d-block plugin="plugin_id"/> renders an existing plugin block.', $bare);
    $this->assertStringNotContainsString('d-blueprint', $bare);

    $line = $this->prompt()->block($this->rosterCatalog([
      new MaterialRecord(
        kind: MaterialKind::Blueprint,
        id: 'hero',
        label: 'Hero',
        description: 'A hero section.',
        spellings: [MaterialRecord::SLOT_BLUEPRINT => 'hero'],
        blueprintScope: MaterialRecord::SCOPE_BLOCK,
      ),
    ]));
    $this->assertStringStartsWith($bare, $line);
    $this->assertStringContainsString('<d-blueprint name="blueprint_id"/> places a saved blueprint (the design system\'s BLUEPRINTS below) as is', $line);
    $this->assertStringContainsString('a section (top-level only) at the document\'s top level, never inside a container; any other blueprint wherever a container or block may sit; the reference tag itself takes no style or class', $line);
    $this->assertStringContainsString('To place one with your own content, write its printed fragment (the schema prints each in full, one group alone with its group parameter) with your content and blueprint="blueprint_id" on the root: that label is what makes the element a placement, so keep it when you rewrite one', $line);
    $this->assertStringContainsString('Inside a placement the blueprint\'s owned looks (listed under it) are wearable; they address the placement\'s children by position, so keep the shape where you keep the look', $line);
    $this->assertStringContainsString('A blueprint saved on this site is a page of its own: page_read (entity_type blueprint, its entity_id) prints its body with ids and page_edit changes it for every later placement; a shipped one is read-only.', $line);
    $this->assertStringEndsWith('a shipped file cannot be linked. A blueprint is a component: place it with the stamp when the section\'s job is its job, or build the section new; never retype one without its stamp.', $line);
    $this->assertStringNotContainsString('by hand', $line);
    foreach (['pre-approved', 'beside', 'never a boundary', 'hand-rolled', 'on brand'] as $retired) {
      $this->assertStringNotContainsStringIgnoringCase($retired, $line, sprintf('The retired "%s" is gone from the block line.', $retired));
    }

    // A page blueprint is a start, never a placement: a roster holding
    // only page scope leaves the block line bare, since there is nothing
    // <d-blueprint> could place and no BLUEPRINTS block below.
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
    $this->assertStringEndsWith('wear it again.', $line, 'A roster whose every look an element places has no elsewhere clause.');

    // A look no element roster places points at the brief's OTHER LOOKS,
    // which carries each scope itself: the schema is no longer where a
    // scope is read.
    $elsewhere = $this->prompt()->classes($this->rosterCatalog([
      new MaterialRecord(
        kind: MaterialKind::Variant,
        id: 'link_quiet',
        label: 'Quiet link',
        group: 'style',
        spellings: [MaterialRecord::SLOT_CLASS => 'link_quiet'],
        scope: Address::fromString('base:a'),
      ),
    ]));
    $this->assertStringEndsWith('wear it again. A variant outside the per-element rosters above applies where its scope fits (the brief\'s OTHER LOOKS, each with its scope).', $elsewhere);
    $this->assertStringNotContainsString('schema', $elsewhere);
  }

  /**
   * The container line states the birth rule from the catalog's axes.
   *
   * The dialect is a total statement (every document and fragment runs as
   * a dressed batch), so an omitted axis is not worn. With a width axis
   * spelling "site" the line names width="site" as the section's statement
   * and lists the other top-level-only axes (the house axes a section wears);
   * without that spelling it names the top-level-only axes alone; with no
   * axes at all only the omission rule prints. A depth-blind axis (layout)
   * is never a section axis.
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
      '- <d-container width(site|full; top-level only) surface(plain|tint; top-level only) layout(columns)> — the one grouping element, nestable; children stack unless layout says otherwise, and every direct child of a columns container is a column. An omitted axis is not worn (a document or fragment is a total statement: nothing is stamped at birth), so a section states width="site" (the site\'s content column; omit it only for a deliberately full-bleed section) and its other top-level-only axes (surface).',
      $this->prompt()->container($house),
    );

    $width_only = $this->axisCatalog(['width'], [
      new MaterialRecord(kind: MaterialKind::Variant, id: 'container_site', label: 'Site', group: 'width', spellings: [$slot('width') => 'site'], scope: Address::fromString('kind:container'), applies: $root),
    ]);
    $this->assertStringEndsWith(', so a section states width="site" (the site\'s content column; omit it only for a deliberately full-bleed section).', $this->prompt()->container($width_only));

    $no_site = $this->axisCatalog(['width'], [
      new MaterialRecord(kind: MaterialKind::Variant, id: 'container_wide', label: 'Wide', group: 'width', spellings: [$slot('width') => 'wide'], scope: Address::fromString('kind:container'), applies: $root),
    ]);
    $this->assertStringEndsWith(', so a section states each of its top-level-only axes (width) itself.', $this->prompt()->container($no_site));

    $this->assertSame(
      '- <d-container> — the one grouping element, nestable; children stack unless layout says otherwise, and every direct child of a columns container is a column. An omitted axis is not worn (a document or fragment is a total statement: nothing is stamped at birth).',
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
    $this->assertSame('use <d-text url="...">; add button="primary" for a call-to-action', DialectParser::sugarCorrective('a', $axis));
    $this->assertSame('use <d-text url="..." button="primary">', DialectParser::sugarCorrective('button', $axis));

    $class = $catalog([
      new MaterialRecord(kind: MaterialKind::Variant, id: 'button', label: 'Button', group: 'style', spellings: [MaterialRecord::SLOT_CLASS => 'button'], scope: Address::fromString('block_type:text')),
    ]);
    $this->assertSame('use <d-text url="...">; add class="button" for a call-to-action', DialectParser::sugarCorrective('a', $class));
    $this->assertSame('use <d-text url="..." class="button">', DialectParser::sugarCorrective('button', $class));

    $bare = $catalog([]);
    $this->assertSame('use <d-text url="...">', DialectParser::sugarCorrective('a', $bare));
    $this->assertSame('use <d-text url="...">', DialectParser::sugarCorrective('button', $bare));
    $this->assertSame('use <d-text tag="h2">', DialectParser::sugarCorrective('h2', $bare));
    $this->assertNull(DialectParser::sugarCorrective('d-text', $bare));
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
