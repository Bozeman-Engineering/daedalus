<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Dialect;

// cspell:ignore buton lowd
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DialectCssParser;
use Drupal\daedalus_blueprint\Dialect\DialectParser;
use Drupal\daedalus_blueprint\Dialect\DialectPrinter;
use Drupal\daedalus_blueprint\Dialect\DialectPrintNoteKind;
use Drupal\daedalus_blueprint\Dialect\Event\DialectCatalogBuildEvent;
use Drupal\daedalus_blueprint\Dialect\DomOperationHandler;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRoster;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The dialect spec, as a test suite.
 *
 * The accept side is the worked documents in the pb- spelling, the
 * reject side is the adversarial list plus the standing rules (pb-
 * prefix mandatory, clamp()/calc() unsupported). This suite IS the spec
 * for documents, fragments, and the op-batch envelope, validated by the
 * DOM op handler's entity-free surface, fluency spills (descendant
 * selectors, compound #id.class) included. Beyond the corpus it pins the
 * canonical print (byte-exact, a live page's tree shape), print(parse(x))
 * idempotence for every accepted document, and line numbers on
 * structural errors.
 */
#[CoversClass(DialectParser::class)]
#[CoversClass(DialectPrinter::class)]
#[CoversClass(DomOperationHandler::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class DialectSpecTest extends BlueprintKernelTestBase {

  /**
   * The ideal document: every grammar tier at once, in the pb- spelling.
   */
  protected const IDEAL = <<<HTML
<pb-container id="hero" width="full" layout="columns"
              style="padding-block: var(--space-16)">
  <pb-text id="hero-title" tag="h1" style="text-align: center">Ship faster</pb-text>
  <pb-image alt="Sunrise over the bay" generate="..." />
  <pb-formatted-text><p>Body copy <a href="/about">here</a>.</p></pb-formatted-text>
</pb-container>
<style>
  #hero-title:hover { color: var(--color-accent); }
  @container (max-width: 767px) { #hero-title { text-align: left; } }
  #hero pb-text .text-block-link { padding: var(--space-2) var(--space-4); }
</style>
HTML;

  /**
   * The dialect parser under test.
   */
  protected DialectParser $parser;

  /**
   * The dialect printer under test.
   */
  protected DialectPrinter $printer;

  /**
   * The catalog the spec's fixtures speak.
   */
  protected DialectCatalog $catalog;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->parser = $this->container->get('daedalus_blueprint.dialect_parser');
    $this->printer = $this->container->get('daedalus_blueprint.dialect_printer');
    $this->catalog = static::catalog();
  }

  /**
   * The spec site vocabulary, a hand-built catalog.
   *
   * Mirrors a live site's schema: the text block's field_text_tag allows
   * h1..h6 (a text block may be the page's one h1), so this roster matches
   * what DialectCatalogBuilder emits from the site's real values. The
   * materials (tokens, a swatch, the axis and style variants, a blueprint)
   * ride a hand-built roster (materials()), the same shape the providers
   * serve live.
   */
  protected static function catalog(): DialectCatalog {
    return new DialectCatalog(
      containerLayoutId: 'daedalus_stylish_container',
      // 0.6: axis order per variant scope; the block kind's axis (emphasis,
      // every block form) is undeclared here and lands after the declared
      // ones by the roster's order.
      axes: ['kind:container' => ['width' => [], 'layout' => []], 'block_type:text' => ['tone' => []]],
      blockTypes: [
        'text' => [
          'attributes' => [
            'tag' => ['field' => 'field_text_tag', 'values' => ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'span', 'p']],
            'url' => ['field' => 'field_text_url', 'kind' => 'link'],
          ],
          'body' => ['field' => 'field_text_text', 'kind' => 'plain'],
        ],
        'image' => [
          'attributes' => [
            'alt' => ['field' => 'field_image', 'key' => 'alt_text'],
            'generate' => ['field' => 'field_image', 'key' => '_generate'],
            'file' => ['field' => 'field_image', 'key' => '_file'],
            'media' => ['field' => 'field_image', 'kind' => 'ref'],
            'url' => ['field' => 'field_image_url', 'kind' => 'link'],
            'aspect-ratio' => [
              'field' => 'field_image',
              'key' => 'aspectRatio',
              'values' => ['16:9', '4:3', '1:1', '9:16'],
            ],
          ],
          'void' => TRUE,
        ],
        'formatted_text' => [
          'attributes' => [
            'format' => [
              'field' => 'field_formatted_text_text',
              'key' => 'format',
              'values' => ['daedalus_edit_formatted_text'],
            ],
          ],
          'body' => ['field' => 'field_formatted_text_text', 'kind' => 'rich'],
        ],
        'teaser' => [
          'attributes' => [
            'content' => ['field' => 'field_teaser_content', 'kind' => 'ref'],
          ],
        ],
      ],
      conditions: [
        'narrow' => '@container (max-width: 767px)',
        'medium' => '@container (min-width: 768px) and (max-width: 1023px)',
        'dark' => '@media (prefers-color-scheme: dark)',
        'reduced-motion' => '@media (prefers-reduced-motion: reduce)',
        'hover' => ':hover',
        'focus' => ':focus',
        'focus-within' => ':focus-within',
        'focus-visible' => ':focus-visible',
        'active' => ':active',
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
      ],
      // The structural facts: spelled like states on the addressed
      // element, taught and corrected apart; a negation is a fact of the
      // same shape, identified by its :not() wrapper.
      facts: ['first-child', 'last-child', 'only-child', 'empty', 'odd', 'even', 'not-first-child', 'not-last-child', 'not-only-child', 'not-empty'],
      // The selector rosters per owner: the text block's declared part
      // and, on every owner, the tag tier's vocabulary (the styling
      // layer's base section, the one declaration of a prose tag's
      // styleability); the types a container's children may be named by
      // are the spine's (the block types above plus the two kinds).
      selectors: static::selectorRosters(['pb-text' => ['.text-block-link' => 'Link'], 'pb-image' => [], 'pb-formatted-text' => [], 'pb-teaser' => []]),
      // v0.5: the spine's instance namespace and its identity tags (the
      // rich-text identity classes and what the minter mints).
      instances: [
        '.s-*' => [
          'label' => 'Text element',
          'tags' => [
            'p' => 'Paragraph',
            'h2' => 'Heading 2',
            'h3' => 'Heading 3',
            'h4' => 'Heading 4',
            'blockquote' => 'Block quote',
            'ul' => 'Bulleted list',
            'ol' => 'Numbered list',
            'li' => 'List item',
          ],
        ],
      ],
      roster: static::materials(),
    );
  }

  /**
   * The spec site's materials, as the providers would serve them.
   *
   * Tokens on the spec site skin's scales; one swatch ("lime green", the
   * label a model spells as a var() name); the width and layout axis
   * variants (width root-only, the depth fact on each record); the style
   * variants (button on the text block, band on the container, lead a
   * base:h2 treatment no roster places: the spine judges its scope); the
   * 0.6 block axes (tone on the text block type, emphasis on the block
   * kind: attributes on the block's own tag, every block form for the
   * kind's); two block-scope blueprints (hero and pull_quote: a placement is
   * always a container, so the provenance grammar has two names to stamp
   * and refuses either on a block), plus a page band (full_bleed, its root
   * wearing the root-only container_full) so the walk's placement refusal
   * has a band to refuse.
   */
  protected static function materials(): MaterialsRoster {
    $records = [];
    $tokens = [
      'color' => ['bg', 'surface', 'elevated', 'text', 'muted', 'border', 'accent', 'accent-hover', 'error', 'warning'],
      'font' => ['body', 'heading', 'mono'],
      'text' => ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl'],
      'weight' => ['regular', 'medium', 'semibold', 'bold'],
      'leading' => ['tight', 'snug', 'normal', 'relaxed'],
      'tracking' => ['tight', 'normal', 'wide'],
      'space' => ['1', '2', '3', '4', '6', '8', '12', '16', '24', 'gutter'],
      'radius' => ['sm', 'md', 'lg', 'full'],
      'transition' => ['fast', 'normal'],
      'layout' => ['max-width'],
    ];
    foreach ($tokens as $scale => $names) {
      foreach ($names as $name) {
        $records[] = new MaterialRecord(kind: MaterialKind::Token, id: "$scale/$name", label: $name, value: '0', group: $scale, spellings: [MaterialRecord::SLOT_STYLE => "var(--$scale-$name)"], origin: MaterialRecord::ORIGIN_SHIPPED);
      }
    }
    $records[] = new MaterialRecord(kind: MaterialKind::Swatch, id: 'lime_green', label: 'Lime green', value: '#4dff8c', group: 'color', spellings: [MaterialRecord::SLOT_STYLE => 'var(--swatch-lime_green)']);
    foreach (['full', 'site', '2xl', 'xl', 'lg', 'md', 'sm'] as $width) {
      $records[] = new MaterialRecord(kind: MaterialKind::Variant, id: "container_$width", label: ucfirst($width), group: 'width', spellings: [MaterialRecord::attrSlot('kind:container', 'width') => $width], scope: Address::fromString('kind:container'), applies: [MaterialRecord::APPLIES_DEPTH => MaterialRecord::DEPTH_ROOT], origin: MaterialRecord::ORIGIN_SHIPPED);
    }
    $records[] = new MaterialRecord(kind: MaterialKind::Variant, id: 'columns', label: 'Columns', group: 'container_layout', spellings: [MaterialRecord::attrSlot('kind:container', 'layout') => 'columns'], scope: Address::fromString('kind:container'), origin: MaterialRecord::ORIGIN_SHIPPED);
    $records[] = new MaterialRecord(kind: MaterialKind::Variant, id: 'button', label: 'Button', description: 'Dress the link as a solid button.', group: 'style', spellings: [MaterialRecord::SLOT_CLASS => 'button'], scope: Address::fromString('block_type:text'), origin: MaterialRecord::ORIGIN_SHIPPED);
    $records[] = new MaterialRecord(kind: MaterialKind::Variant, id: 'band', label: 'Band', group: 'style', spellings: [MaterialRecord::SLOT_CLASS => 'band'], scope: Address::fromString('kind:container'));
    $records[] = new MaterialRecord(kind: MaterialKind::Variant, id: 'lead', label: 'Lead', group: 'style', spellings: [MaterialRecord::SLOT_CLASS => 'lead'], scope: Address::fromString('base:h2'));
    foreach (['loud', 'quiet'] as $tone) {
      $records[] = new MaterialRecord(kind: MaterialKind::Variant, id: "text_$tone", label: ucfirst($tone), description: "Set the text $tone.", group: 'tone', spellings: [MaterialRecord::attrSlot('block_type:text', 'tone') => $tone], scope: Address::fromString('block_type:text'));
    }
    $records[] = new MaterialRecord(kind: MaterialKind::Variant, id: 'block_emphatic', label: 'Emphatic', group: 'emphasis', spellings: [MaterialRecord::attrSlot('kind:block', 'emphasis') => 'emphatic'], scope: Address::fromString('kind:block'));
    $records[] = new MaterialRecord(kind: MaterialKind::Blueprint, id: 'hero', label: 'Hero', description: 'A single-column hero band.', spellings: [MaterialRecord::SLOT_BLUEPRINT => 'hero'], blueprintScope: MaterialRecord::SCOPE_BLOCK, origin: MaterialRecord::ORIGIN_SHIPPED, updatable: FALSE);
    $records[] = new MaterialRecord(kind: MaterialKind::Blueprint, id: 'pull_quote', label: 'Pull quote', description: 'One quoted line, set large.', spellings: [MaterialRecord::SLOT_BLUEPRINT => 'pull_quote'], blueprintScope: MaterialRecord::SCOPE_BLOCK);
    $records[] = new MaterialRecord(kind: MaterialKind::Blueprint, id: 'full_bleed', label: 'Full bleed', description: 'An edge-to-edge band.', spellings: [
      MaterialRecord::SLOT_BLUEPRINT => 'full_bleed',
    ], applies: [
      MaterialRecord::APPLIES_WEARS => ['band', 'container_full'],
    ], blueprintScope: MaterialRecord::SCOPE_BLOCK);
    return new MaterialsRoster($records, new CacheableMetadata());
  }

  /**
   * The accept-side document corpus.
   */
  protected static function acceptDocuments(): array {
    return [
      'ideal document (every grammar tier, pb- spelling)' => static::IDEAL,
      'a file declaration beside the style block (write-only, never printed)' => <<<HTML
<pb-container id="band" width="site">
  <pb-image id="mark" file="logo.svg" alt="The mark" />
</pb-container>
<pb-file name="logo.svg" type="image/svg+xml">
  <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><circle cx="4" cy="4" r="3"/></svg>
</pb-file>
<style>
  #band { padding-block: var(--space-16); }
</style>
HTML,
      'a band blueprint at the document\'s top level' => '<pb-blueprint name="full_bleed" />',
      'published @media wrappers are legal (dark, reduced-motion)' => <<<HTML
<pb-container id="band" width="site">
  <pb-text id="t" tag="h2">Hello &amp; welcome & more</pb-text>
</pb-container>
<style>
  @media (prefers-color-scheme: dark) { #band { background: var(--color-surface); } }
  @media (prefers-reduced-motion: reduce) { #t { transition: none; } }
</style>
HTML,
      'nested container, media reference, class variant, compounds' => <<<HTML
<pb-container id="outer" width="site" layout="columns">
  <pb-container id="left" style="flex-grow: 2">
    <pb-text id="cta" url="/signup" class="button">Get started</pb-text>
    <pb-formatted-text><ul><li>Rich <em>body</em></li></ul></pb-formatted-text>
  </pb-container>
  <pb-container id="right">
    <pb-image id="pic" media="Sunrise" alt="Sunrise" aspect-ratio="4:3" />
    <pb-teaser content="About us" />
  </pb-container>
</pb-container>
<style>
  @container (max-width: 767px) { #cta:hover { color: var(--color-accent-hover); } }
  #outer > * { align-self: center; }
</style>
HTML,
      'generated boxes: the element\'s own, under a state, and a child type\'s' => <<<HTML
<pb-container id="band" width="site">
  <pb-text id="t" tag="h2">Hi</pb-text>
</pb-container>
<style>
  #t::after { content: ""; }
  #t:hover::before { content: ""; background-color: var(--color-accent); }
  #band > pb-text::after { content: none; }
</style>
HTML,
      'v0.3: class by scope on a container and a base treatment, media token in CSS' => <<<HTML
<pb-container id="band" width="site" class="band" style="background-image: url(media:0f7b0c2e-8c7a-4a9e-9d0a-2f1f8e3c4b5d)">
  <pb-text id="lede" tag="h2" class="lead">Hello</pb-text>
</pb-container>
<style>
  #band:hover { background-image: url(media:0f7b0c2e-8c7a-4a9e-9d0a-2f1f8e3c4b5d); }
</style>
HTML,
      'materials: a swatch var() is a published token like any skin token' => <<<HTML
<pb-container id="band" width="site" style="background: var(--swatch-lime_green); color: var(--color-text)">
  <pb-text id="t" tag="h2" style="border: 1px solid var(--swatch-lime_green)">Hi</pb-text>
</pb-container>
HTML,
      'placement provenance rides the root as blueprint="" (a top-level and a nested container)' => <<<HTML
<pb-container id="band" blueprint="hero" width="site">
  <pb-container id="q" blueprint="pull_quote">
    <pb-text id="t" tag="p">Said well.</pb-text>
  </pb-container>
</pb-container>
HTML,
      '0.6: block axes are attributes on the block\'s tag (a type axis, the block kind\'s axis on every block form)' => <<<HTML
<pb-container id="band" width="site">
  <pb-text id="cta" class="button" tone="loud" emphasis="emphatic" tag="h2">Hi</pb-text>
  <pb-block plugin="system_branding_block" emphasis="emphatic" />
  <pb-block type="quote" emphasis="emphatic"><pb-field name="field_quote_text">Said well.</pb-field></pb-block>
</pb-container>
HTML,
      'v0.5: rich-text instances (authored identity, tag tier incl. li, one list\'s items)' => <<<HTML
<pb-container id="c" width="site">
  <pb-formatted-text id="copy"><p class="s-a1">Lead copy</p><p>Plain copy</p><ul class="s-b2"><li>One</li><li>Two</li></ul></pb-formatted-text>
</pb-container>
<style>
  #copy .s-a1 { color: var(--color-accent); }
  #copy p { font-size: var(--text-lg); }
  #copy li { padding-inline: var(--space-2); }
  #copy .s-b2 > * { padding-block: var(--space-1); }
</style>
HTML,
      'child selectors: the children, a type among them, a chain into a type\'s part, a tag inside a type' => <<<HTML
<pb-container id="band" width="site">
  <pb-text id="a" tag="p">One</pb-text>
  <pb-text id="b" tag="p">Two</pb-text>
  <pb-image id="pic" media="Sunrise" alt="Sunrise" aspect-ratio="4:3" />
</pb-container>
<style>
  #band > * { margin: 0; }
  #band > pb-text { color: var(--color-accent); }
  #band > pb-text:hover { color: var(--color-accent-hover); }
  #band > pb-text .text-block-link { padding: var(--space-2); }
  #band pb-image { border-radius: var(--space-2); }
  #band p { font-size: var(--text-lg); }
</style>
HTML,
      'relations: sibling combinators, :has(), :not(), a negated fact and a state after the qualifiers' => <<<HTML
<pb-container id="band" width="site">
  <pb-text id="a" tag="p">One</pb-text>
  <pb-text id="b" tag="p">Two</pb-text>
  <pb-image id="pic" media="Sunrise" alt="Sunrise" aspect-ratio="4:3" />
</pb-container>
<style>
  #band > p + p { margin-top: var(--space-2); }
  #band > pb-text ~ pb-image { margin-top: var(--space-1); }
  #band > pb-text:has(a) { color: var(--color-accent); }
  #band > pb-text:has(a):hover { color: var(--color-accent-hover); }
  #band > pb-text:has(+ pb-image) { margin-bottom: 0; }
  #band > pb-text:not(:has(a)) { color: var(--color-surface); }
  #band > pb-text .text-block-link ~ .text-block-link { padding: var(--space-1); }
  #band > *:not(pb-image):not(:last-child) { margin-bottom: var(--space-2); }
</style>
HTML,
      'position facts: a pseudo-class on the addressed element, alone, beside a state, on the root' => <<<HTML
<pb-container id="band" width="site">
  <pb-text id="a" tag="p">One</pb-text>
  <pb-text id="b" tag="p">Two</pb-text>
</pb-container>
<style>
  #band > pb-text:first-child { margin-top: 0; }
  #band > *:nth-child(odd) { background-color: var(--color-surface); }
  #band > pb-text:hover:last-child { color: var(--color-accent); }
  #a:empty { display: none; }
  @container (max-width: 767px) { #b:only-child { padding: var(--space-1); } }
</style>
HTML,
      'nesting is sugar: a nested rule, a state, a wrapper, all flattened' => <<<HTML
<pb-container id="band" width="site">
  <pb-text id="a" tag="p">One</pb-text>
</pb-container>
<style>
  #band {
    margin: 0;
    > pb-text { color: var(--color-accent); &:hover { color: var(--color-accent-hover); } }
    :hover { padding: var(--space-2); }
    @container (max-width: 767px) { margin: var(--space-1); }
  }
</style>
HTML,
    ];
  }

  /**
   * The accept-side fragment corpus.
   */
  protected static function acceptFragments(): array {
    return [
      'a plugin block with no fields' => '<pb-block plugin="system_branding_block"/>',
      'a field block naming the page\'s field' => '<pb-block field="body"/>',
      'a blueprint reference' => '<pb-blueprint name="hero"/>',
      'generic form for an undeclared block type' => '<pb-block type="quote"><pb-field name="field_quote_text">Said well.</pb-field></pb-block>',
    ];
  }

  /**
   * The adversarial document corpus: [document, expected codes 'A|B'].
   */
  protected static function rejectDocuments(): array {
    return [
      '<div> is not a dialect element' => ['<div class="hero"><pb-text id="t">Hi</pb-text></div>', 'SUGAR_TAG'],
      'a file declaration without a name' => [
        '<pb-container id="c"></pb-container><pb-file type="image/svg+xml"><svg xmlns="http://www.w3.org/2000/svg"/></pb-file>',
        'FILE_DECLARATION',
      ],
      'a file declaration without a type' => [
        '<pb-container id="c"></pb-container><pb-file name="logo.svg"><svg xmlns="http://www.w3.org/2000/svg"/></pb-file>',
        'FILE_DECLARATION',
      ],
      'a file declaration with an empty body' => [
        '<pb-container id="c"></pb-container><pb-file name="logo.svg" type="image/svg+xml"></pb-file>',
        'FILE_DECLARATION',
      ],
      'a file declaration naming a path' => [
        '<pb-container id="c"></pb-container><pb-file name="icons/logo.svg" type="image/svg+xml"><svg xmlns="http://www.w3.org/2000/svg"/></pb-file>',
        'FILE_DECLARATION',
      ],
      'a file declaration wearing an attribute it does not take' => [
        '<pb-container id="c"></pb-container><pb-file name="logo.svg" type="image/svg+xml" alt="x"><svg xmlns="http://www.w3.org/2000/svg"/></pb-file>',
        'ILLEGAL_ATTR',
      ],
      'a file declaration inside a container (a declaration is the document\'s)' => [
        '<pb-container id="c"><pb-file name="logo.svg" type="image/svg+xml"><svg xmlns="http://www.w3.org/2000/svg"/></pb-file></pb-container>',
        'ILLEGAL_CONTAINMENT',
      ],
      '<section> sugar' => ['<section><pb-text id="t">Hi</pb-text></section>', 'SUGAR_TAG'],
      '<h2> sugar at element position' => ['<pb-container id="c"><h2>Hello</h2></pb-container>', 'SUGAR_TAG'],
      '<img src> raw image is sugar naming <pb-image>' => [
        '<pb-container id="c"><img src="https://example.com/x.jpg"></pb-container>',
        'SUGAR_TAG',
      ],
      '<image> sugar through the HTML5 rename' => [
        '<pb-container id="c"><image src="/x.jpg" alt="x" /></pb-container>',
        'SUGAR_TAG',
      ],
      'src is not writable on <pb-image>' => [
        '<pb-container id="c"><pb-image src="/x.jpg" alt="x" /></pb-container>',
        'ILLEGAL_SRC',
      ],
      'unprefixed tags name the pb- form' => [
        '<container id="c"><text id="t">Hi</text></container>',
        'UNPREFIXED_TAG',
      ],
      '@media misuse: width condition as media query' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>@media (max-width: 767px) { #t { color: red; } }</style>",
        'UNKNOWN_WRAPPER',
      ],
      'unpublished @container breakpoint' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>@container (max-width: 500px) { #t { color: red; } }</style>",
        'UNKNOWN_WRAPPER',
      ],
      'Tailwind class on text' => [
        '<pb-container id="c"><pb-text id="t" class="text-center">Hi</pb-text></pb-container>',
        'UNKNOWN_CLASS',
      ],
      '!important' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#t { color: var(--color-text) !important; }</style>",
        'IMPORTANT',
      ],
      ':root custom property definition' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>:root { --color-brand: red; }</style>",
        'BAD_SELECTOR',
      ],
      'bare class selector' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>.button { padding: var(--space-2); }</style>",
        'BAD_SELECTOR',
      ],
      'element selector descent into an undeclared tag (a chain of tags is grammar; div is not a declared tag)' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#c div span { color: red; }</style>",
        'STYLE_TAG_NOT_DECLARED',
      ],
      'a state on #id while addressing a descendant' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#c:hover p { color: red; }</style>",
        'BAD_SELECTOR',
      ],
      'a leading sibling combinator (the owner\'s siblings are outside its subtree; between compounds + is grammar)' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#c + p { color: red; }</style>",
        'BAD_SELECTOR',
      ],
      'a qualifier on the id itself (a qualifier narrows a compound after #id)' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#c:has(pb-text) { color: red; }</style>",
        'BAD_SELECTOR',
      ],
      'a state before a qualifier (states and facts come last)' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#c > pb-text:hover:has(a) { color: red; }</style>",
        'BAD_SELECTOR',
      ],
      'children on a block' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#t > * { color: red; }</style>",
        'BAD_SELECTOR',
      ],
      'an element type no block type declares' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#c > pb-ghost { color: red; }</style>",
        'UNKNOWN_TYPE',
      ],
      'selector list' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#c, #t { color: red; }</style>",
        'SELECTOR_LIST',
      ],
      'url() in a value' => ['<pb-container id="c" style="background: url(/x.png)"></pb-container>', 'URL_FUNCTION'],
      'external image url() (only media:, generate: and file: urls exist)' => [
        '<pb-container id="c" style="background-image: url(\'https://example.com/x.jpg\')"></pb-container>',
        'URL_FUNCTION',
      ],
      'generate: nested inside another function is not a layer' => [
        '<pb-container id="c" style="background-image: cross-fade(url(generate:a sunrise), black)"></pb-container>',
        'BAD_DECLARATION',
      ],
      'generate: with an empty prompt' => [
        '<pb-container id="c" style="background-image: url(generate:)"></pb-container>',
        'BAD_DECLARATION',
      ],
      'file: nested inside another function is not a layer' => [
        '<pb-container id="c" style="background-image: cross-fade(url(file:hero.svg), black)"></pb-container>',
        'BAD_DECLARATION',
      ],
      'file: with an empty name' => [
        '<pb-container id="c" style="background-image: url(file:)"></pb-container>',
        'BAD_DECLARATION',
      ],
      'a class no published style variant spells' => [
        '<pb-container id="c" class="hero-band"></pb-container>',
        'UNKNOWN_CLASS',
      ],
      'hallucinated token (the measured error class)' => [
        '<pb-container id="c" style="background: var(--color-bg-dark)"></pb-container>',
        'UNKNOWN_TOKEN',
      ],
      'materials: a swatch spelled by its label instead of its machine name' => [
        '<pb-container id="c" style="background: var(--swatch-limegreen)"></pb-container>',
        'UNKNOWN_TOKEN',
      ],
      'materials: a blueprint name nothing publishes' => [
        '<pb-container id="c"><pb-blueprint name="hero-band" /></pb-container>',
        'UNKNOWN_BLUEPRINT',
      ],
      'provenance naming no published blueprint' => [
        '<pb-container id="c" blueprint="hero-band"></pb-container>',
        'UNKNOWN_BLUEPRINT',
      ],
      'a blueprint claimed on an inline block' => [
        '<pb-container id="c"><pb-text id="t" blueprint="hero">Hi</pb-text></pb-container>',
        'BLUEPRINT_MISMATCH',
      ],
      'a blueprint claimed on a plugin block' => [
        '<pb-container id="c"><pb-block id="b" plugin="system_branding_block" blueprint="pull_quote" /></pb-container>',
        'BLUEPRINT_MISMATCH',
      ],
      'clamp() is an unsupported value' => [
        "<pb-container id=\"c\"><pb-text id=\"t\" tag=\"h2\">Big</pb-text></pb-container>\n<style>#t { font-size: clamp(2.25rem, 5vw, 3.75rem); }</style>",
        'UNSUPPORTED_VALUE',
      ],
      'calc() is an unsupported value' => [
        '<pb-container id="c" style="padding-inline: calc(var(--space-4) * 2)"></pb-container>',
        'UNSUPPORTED_VALUE',
      ],
      'min()/max() ride the same rule' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#t { width: min(100%, 40rem); }</style>",
        'UNSUPPORTED_VALUE',
      ],
      'undeclared part class' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#c .card-inner { padding: var(--space-2); }</style>",
        'UNKNOWN_PART',
      ],
      'selector against an id not in the document' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#ghost { color: red; }</style>",
        'UNKNOWN_ID_SELECTOR',
      ],
      'unknown attribute' => [
        '<pb-container id="c"><pb-text id="t" size="large">Hi</pb-text></pb-container>',
        'ILLEGAL_ATTR',
      ],
      'server-derived attribute authored' => [
        '<pb-container id="c"><pb-text id="t" info="Heading">Hi</pb-text></pb-container>',
        'SERVER_DERIVED_ATTR',
      ],
      'data-* is print-only' => ['<pb-container id="c" data-alias="hero"></pb-container>', 'READONLY_ATTR'],
      'bad axis value' => ['<pb-container id="c" width="huge"></pb-container>', 'BAD_ATTR_VALUE'],
      '0.6: bad block axis value' => [
        '<pb-container id="c"><pb-text id="t" tone="huge">Hi</pb-text></pb-container>',
        'BAD_ATTR_VALUE',
      ],
      '0.6: a block type\'s axis on another tag' => [
        '<pb-container id="c"><pb-image alt="x" tone="loud" /></pb-container>',
        'ILLEGAL_ATTR',
      ],
      '0.6: a block axis on the container' => ['<pb-container id="c" tone="loud"></pb-container>', 'ILLEGAL_ATTR'],
      'width on a nested container' => [
        '<pb-container id="c"><pb-container id="inner" width="full"></pb-container></pb-container>',
        'AXIS_NOT_TOP_LEVEL',
      ],
      'a band blueprint inside a container' => [
        '<pb-container id="c"><pb-blueprint name="full_bleed" /></pb-container>',
        'BLUEPRINT_NOT_TOP_LEVEL',
      ],
      'duplicate id' => [
        '<pb-container id="c"><pb-text id="x">A</pb-text><pb-text id="x">B</pb-text></pb-container>',
        'DUPLICATE_ID',
      ],
      'illegal id' => ['<pb-container id="2fast"></pb-container>', 'BAD_ID'],
      'bare text in a container' => ['<pb-container id="c">Loose copy here</pb-container>', 'STRAY_TEXT'],
      'unclosed container swallows siblings' => [
        '<pb-container id="a"><pb-text id="t">Hi</pb-text>',
        'UNCLOSED_ELEMENT',
      ],
      'v0.4: double quotes inside style="" close the attribute early' => [
        '<pb-container id="hero" style="background-image: linear-gradient(rgb(0 0 0 / 0.6), rgb(0 0 0 / 0.6)), url("generate:faint dark blueprint schematics on deep navy")"></pb-container>',
        'UNTERMINATED_ATTR',
      ],
      'unknown state pseudo-class' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#t:visited { color: red; }</style>",
        'UNKNOWN_STATE',
      ],
      'pseudo-elements other than the two generated boxes' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#t::marker { color: red; }</style>",
        'BAD_SELECTOR',
      ],
      'a state after the generated box (the box is terminal)' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#t::after:hover { color: red; }</style>",
        'BAD_SELECTOR',
      ],
      'v0.5: instance class the body does not carry' => [
        "<pb-container id=\"c\"><pb-formatted-text id=\"copy\"><p class=\"s-a1\">Lead</p></pb-formatted-text></pb-container>\n<style>#copy .s-zz { color: red; }</style>",
        'UNKNOWN_INSTANCE',
      ],
      'v0.5: instance class on an element without a rich body' => [
        "<pb-container id=\"c\"><pb-text id=\"t\">Hi</pb-text></pb-container>\n<style>#t .s-a1 { color: red; }</style>",
        'UNKNOWN_INSTANCE',
      ],
      'v0.5: undeclared tag is not an addressable text tag' => [
        "<pb-container id=\"c\"><pb-formatted-text id=\"copy\"><p class=\"s-a1\">Lead</p></pb-formatted-text></pb-container>\n<style>#copy div { color: red; }</style>",
        'STYLE_TAG_NOT_DECLARED',
      ],
      'v0.5: one authored identity on two mintable elements' => [
        '<pb-container id="c"><pb-formatted-text id="copy"><p class="s-a1">Lead</p><p class="s-a1">Copy</p></pb-formatted-text></pb-container>',
        'IDENTITY_COLLISION',
      ],
    ];
  }

  /**
   * The adversarial fragment corpus.
   */
  protected static function rejectFragments(): array {
    return [
      '<style> inside an op payload' => [
        '<pb-text id="t">Hi</pb-text><style>#t { color: red; }</style>',
        'STYLE_IN_FRAGMENT',
      ],
      'a block naming a field and a plugin at once' => [
        '<pb-block field="body" plugin="system_branding_block"/>',
        'ILLEGAL_ATTR',
      ],
      'a field block spelled by its per-bundle plugin id' => [
        '<pb-block plugin="field_block:node:page:body"/>',
        'ILLEGAL_ATTR',
      ],
      'a field attribute naming nothing' => [
        '<pb-block field=""/>',
        'ILLEGAL_ATTR',
      ],
      '<pb-file> inside an op payload (the file op is the batch\'s declaration)' => [
        '<pb-file name="logo.svg" type="image/svg+xml"><svg xmlns="http://www.w3.org/2000/svg"/></pb-file><pb-image file="logo.svg" alt="x" />',
        'FILE_IN_FRAGMENT',
      ],
      'link as a child element instead of url=' => [
        '<pb-text id="t">Hi <a href="/x">there</a></pb-text>',
        'ILLEGAL_CONTAINMENT|SUGAR_TAG',
      ],
      'unprefixed tag in an op payload' => ['<text id="t">Hi</text>', 'UNPREFIXED_TAG'],
      'provenance on a blueprint reference (a reference is not a placement)' => [
        '<pb-blueprint name="hero" blueprint="hero" />',
        'ILLEGAL_ATTR',
      ],
    ];
  }

  /**
   * Every accept-side corpus entry parses clean, and the print round-trips.
   *
   * For documents: y = print(parse(x)) must itself parse clean and print
   * byte-identically (print(parse(y)) === y), the spec section 9 contract
   * for canonical documents applied to the corpus via one canonicalizing
   * pass.
   */
  public function testCorpusAccepts(): void {
    $failures = [];
    foreach (static::acceptDocuments() as $name => $document) {
      $result = $this->parser->parseDocument($document, $this->catalog);
      if (!$result->isValid()) {
        $failures[] = sprintf('ACCEPT %s was rejected: %s', $name, implode('; ', array_map('strval', $result->errors)));
        continue;
      }
      $canonical = $this->printer->print($result->tree, $this->catalog)->text;
      $reparse = $this->parser->parseDocument($canonical, $this->catalog);
      if (!$reparse->isValid()) {
        $failures[] = sprintf('ROUNDTRIP %s: canonical print did not reparse: %s', $name, implode('; ', array_map('strval', $reparse->errors)));
        continue;
      }
      if ($this->printer->print($reparse->tree, $this->catalog)->text !== $canonical) {
        $failures[] = sprintf('ROUNDTRIP %s: print(parse(y)) !== y for the canonical print', $name);
      }
    }
    foreach (static::acceptFragments() as $name => $fragment) {
      $result = $this->parser->parseFragment($fragment, $this->catalog);
      if (!$result->isValid()) {
        $failures[] = sprintf('ACCEPT fragment %s was rejected: %s', $name, implode('; ', array_map('strval', $result->errors)));
        continue;
      }
      $canonical = $this->printer->printFragment($result->tree, $this->catalog)->text;
      $reparse = $this->parser->parseFragment($canonical, $this->catalog);
      if (!$reparse->isValid()) {
        $failures[] = sprintf('ROUNDTRIP fragment %s: canonical print did not reparse: %s', $name, implode('; ', array_map('strval', $reparse->errors)));
        continue;
      }
      if ($this->printer->printFragment($reparse->tree, $this->catalog)->text !== $canonical) {
        $failures[] = sprintf('ROUNDTRIP fragment %s: print(parse(y)) !== y', $name);
      }
    }
    $this->assertSame([], $failures, implode("\n", $failures));
  }

  /**
   * Every adversarial corpus entry is rejected with the expected code.
   */
  public function testCorpusRejects(): void {
    $failures = [];
    foreach (static::rejectDocuments() as $name => [$document, $codes]) {
      $result = $this->parser->parseDocument($document, $this->catalog);
      $got = array_unique(array_map(static fn ($e) => $e->code, $result->errors));
      if (array_intersect(explode('|', $codes), $got) === []) {
        $failures[] = sprintf('REJECT %s: wanted %s, got %s', $name, $codes, $got === [] ? 'VALID' : implode(', ', $got));
      }
    }
    foreach (static::rejectFragments() as $name => [$fragment, $codes]) {
      $result = $this->parser->parseFragment($fragment, $this->catalog);
      $got = array_unique(array_map(static fn ($e) => $e->code, $result->errors));
      if (array_intersect(explode('|', $codes), $got) === []) {
        $failures[] = sprintf('REJECT fragment %s: wanted %s, got %s', $name, $codes, $got === [] ? 'VALID' : implode(', ', $got));
      }
    }
    $this->assertSame([], $failures, implode("\n", $failures));
  }

  /**
   * A <pb-file> declaration parses to the result's files, never the tree.
   *
   * The body is RAWTEXT (an SVG's own tags are opaque to the dialect),
   * trimmed at both ends; name and type ride as written; the line is the
   * declaration's; the tree holds the elements only and the print never
   * carries the declaration (after commit a declared file is media). A
   * fragment never carries files.
   */
  public function testFileDeclarationParsesToFiles(): void {
    $document = "<pb-container id=\"band\"></pb-container>\n<pb-file name=\"logo.svg\" type=\"image/svg+xml\">\n  <svg xmlns=\"http://www.w3.org/2000/svg\"><g><circle r=\"3\"/></g></svg>\n</pb-file>\n<pb-file name=\"data.csv\" type=\"text/csv\">a,b\n1,2</pb-file>";
    $result = $this->parser->parseDocument($document, $this->catalog);
    $this->assertTrue($result->isValid(), implode('; ', array_map('strval', $result->errors)));
    $this->assertSame([
      [
        'name' => 'logo.svg',
        'type' => 'image/svg+xml',
        'content' => '<svg xmlns="http://www.w3.org/2000/svg"><g><circle r="3"/></g></svg>',
        'line' => 2,
      ],
      ['name' => 'data.csv', 'type' => 'text/csv', 'content' => "a,b\n1,2", 'line' => 5],
    ], $result->files);
    $this->assertCount(1, $result->tree, 'The declaration is not an element.');
    $this->assertSame('container', $result->tree[0]['kind']);
    $this->assertStringNotContainsString('pb-file', $this->printer->print($result->tree, $this->catalog)->text);

    $fragment = $this->parser->parseFragment('<pb-file name="logo.svg" type="image/svg+xml"><svg/></pb-file>', $this->catalog);
    $this->assertFalse($fragment->isValid());
    $this->assertSame('FILE_IN_FRAGMENT', $fragment->errors[0]->code);
    $this->assertStringContainsString('file op', $fragment->errors[0]->message);
    $this->assertSame([], $fragment->files);

    // The grammar errors name the declaration and its line.
    $bad = $this->parser->parseDocument("<pb-container id=\"c\"></pb-container>\n<pb-file type=\"image/svg+xml\"></pb-file>", $this->catalog);
    $codes = array_map(static fn ($e) => $e->code, $bad->errors);
    $this->assertSame(['FILE_DECLARATION', 'FILE_DECLARATION'], $codes, 'The missing name and the empty body are two facts in one round.');
    $this->assertSame(2, $bad->errors[0]->line);
    $this->assertSame([], $bad->files, 'A declaration whose grammar failed is not carried.');
  }

  /**
   * A band blueprint is refused below the top level, its wearing named.
   *
   * The roster's one predicate (MaterialsRoster::applicable()) decides:
   * full_bleed's root wears container_full, root-only, so the reference
   * is refused inside a container in AXIS_NOT_TOP_LEVEL's words with the
   * variant and axis in context (the same facts the op spine's
   * VARIANT_ROOT_ONLY carries), while hero, wearing nothing root-only,
   * places nested and the band places at the top level.
   */
  public function testBandBlueprintRefusedBelowTopLevel(): void {
    $result = $this->parser->parseFragment('<pb-container id="c"><pb-blueprint name="full_bleed" /></pb-container>', $this->catalog);
    $this->assertCount(1, $result->errors);
    $error = $result->errors[0];
    $this->assertSame('BLUEPRINT_NOT_TOP_LEVEL', $error->code);
    $this->assertSame('"full_bleed" is a page band (its root wears container_full "Full" (width="full")): it applies to top-level containers only, never nested inside another container', $error->message);
    $this->assertSame(['blueprint' => 'full_bleed', 'variant' => 'container_full', 'axis' => 'width'], $error->context);
    $this->assertSame('Place it at the top level of the document, outside every container.', $error->hint);

    $this->assertSame([], $this->parser->parseFragment('<pb-container id="c"><pb-blueprint name="hero" /></pb-container>', $this->catalog)->errors);
    // A fragment lands nested unless the op says top level (the rule a
    // width= container already follows), so the band is refused by
    // default and accepted where the payload lands at the top level.
    $this->assertSame(['BLUEPRINT_NOT_TOP_LEVEL'], array_map(static fn ($e) => $e->code, $this->parser->parseFragment('<pb-blueprint name="full_bleed" />', $this->catalog)->errors));
    $this->assertSame([], $this->parser->parseFragment('<pb-blueprint name="full_bleed" />', $this->catalog, top_level: TRUE)->errors);
  }

  /**
   * The canonical print, byte-exact, for a live page's tree shape.
   *
   * The tree fixture mirrors what NodeTreeBuilder builds from a live page
   * (uuids, info + form-only stub residue, label, a width variant, styles
   * on the node) and pins every canonical-print
   * rule at once: residue never prints (info, stubs, label), the width
   * variant prints as an axis attribute, default-condition styles print
   * as style="", the rich body prints verbatim with its format, and
   * uuids print as ids. print(parse(x)) == x closes the loop.
   */
  public function testCanonicalPrintOfNodeOneShape(): void {
    $tree = [
      [
        'kind' => 'container',
        'layout_id' => 'daedalus_stylish_container',
        'uuid' => 'c0d89921-04a5-48d1-8720-d7508da565f6',
        'children' => [
          [
            'kind' => 'block',
            'uuid' => '66f39f31-8735-431e-9848-0db7e34e81ff',
            'block_type' => 'text',
            'label' => 'Front page title',
            'block_fields' => [
              'info' => 'Front page title',
              'field_text_tag' => 'h2',
              'field_text_text' => 'Welcome!',
              'field_text_url' => ['_form_only' => TRUE, '_label' => 'URL'],
            ],
            'styles' => [
              '' => [
                'default' => ['font-size' => 'var(--text-5xl)', 'margin-bottom' => '2rem'],
                'narrow+hover' => ['color' => 'var(--color-accent)'],
              ],
            ],
          ],
          [
            'kind' => 'block',
            'uuid' => 'e12f04b1-289f-4a33-a7f9-d506411a67f7',
            'block_type' => 'formatted_text',
            'label' => 'Front page body',
            'block_fields' => [
              'info' => 'Front page body',
              'field_formatted_text_text' => [
                'value' => '<h2>Daedalus is the future of page building.</h2><p>Come for the code &amp; stay.</p>',
                'format' => 'daedalus_edit_formatted_text',
              ],
            ],
          ],
        ],
        'styles' => [
          '' => [
            'variants' => [['variant' => 'container_lg', 'fragment' => '']],
            'default' => ['padding-top' => '4rem', 'padding-bottom' => '4rem'],
          ],
        ],
      ],
    ];

    $expected = <<<HTML
<pb-container id="c0d89921-04a5-48d1-8720-d7508da565f6" width="lg" style="padding-top: 4rem; padding-bottom: 4rem">
  <pb-text id="66f39f31-8735-431e-9848-0db7e34e81ff" tag="h2" style="font-size: var(--text-5xl); margin-bottom: 2rem">Welcome!</pb-text>
  <pb-formatted-text id="e12f04b1-289f-4a33-a7f9-d506411a67f7" format="daedalus_edit_formatted_text"><h2>Daedalus is the future of page building.</h2><p>Come for the code &amp; stay.</p></pb-formatted-text>
</pb-container>
<style>
  @container (max-width: 767px) {
    #66f39f31-8735-431e-9848-0db7e34e81ff:hover { color: var(--color-accent); }
  }
</style>

HTML;

    $print = $this->printer->print($tree, $this->catalog)->text;
    $this->assertSame($expected, $print);

    $reparse = $this->parser->parseDocument($print, $this->catalog);
    $this->assertSame([], array_map('strval', $reparse->errors));
    $this->assertSame($print, $this->printer->print($reparse->tree, $this->catalog)->text, 'print(parse(x)) == x');

    // The parsed tree carries the same styles and the same authored field
    // values; the residue (info, stubs, label) is gone by design.
    $container = $reparse->tree[0];
    $this->assertSame($tree[0]['styles'], $container['styles']);
    $this->assertSame($tree[0]['children'][0]['styles'], $container['children'][0]['styles']);
    $this->assertSame('h2', $container['children'][0]['block_fields']['field_text_tag']);
    $this->assertSame('Welcome!', $container['children'][0]['block_fields']['field_text_text']);
    $this->assertSame($tree[0]['children'][1]['block_fields']['field_formatted_text_text'], $container['children'][1]['block_fields']['field_formatted_text_text']);
    $this->assertArrayNotHasKey('info', $container['children'][0]['block_fields']);
    $this->assertArrayNotHasKey('field_text_url', $container['children'][0]['block_fields']);
    $this->assertArrayNotHasKey('label', $container['children'][0]);
  }

  /**
   * A composition variant's distributed fragments print nothing.
   *
   * Applying a composition variant to a container binds its slot entries
   * onto the resolved children as fragment references (daedalus_stylish's
   * bind-once distribution). Those references are derived state: the
   * parent's root wear is the authored gesture and re-wearing it there
   * re-distributes, so a child's fragment has no spelling of its own and
   * the print shows only the parent's class. A loud exception on a
   * distributed fragment would fail every page_create that places a
   * blueprint carrying a composition variant (a theme's card blueprint is the
   * live shape).
   */
  public function testDistributedFragmentsPrintNothing(): void {
    $tree = [
      [
        'kind' => 'container',
        'layout_id' => 'daedalus_stylish_container',
        'uuid' => 'c0d89921-04a5-48d1-8720-d7508da565f6',
        'styles' => ['' => ['variants' => [['variant' => 'band', 'fragment' => '']]]],
        'children' => [
          [
            'kind' => 'block',
            'uuid' => '66f39f31-8735-431e-9848-0db7e34e81ff',
            'block_type' => 'text',
            'block_fields' => ['field_text_tag' => 'h3', 'field_text_text' => 'Card Title'],
            'styles' => ['' => ['variants' => [['variant' => 'band', 'fragment' => 'block(text,1)']]]],
          ],
          [
            'kind' => 'block',
            'uuid' => 'e12f04b1-289f-4a33-a7f9-d506411a67f7',
            'block_type' => 'text',
            'block_fields' => ['field_text_text' => 'Read more'],
            'styles' => [
              '' => [
                'variants' => [
                  ['variant' => 'button', 'fragment' => ''],
                  ['variant' => 'band', 'fragment' => 'block(text,2)'],
                ],
              ],
            ],
          ],
        ],
      ],
    ];

    $expected = <<<HTML
<pb-container id="c0d89921-04a5-48d1-8720-d7508da565f6" class="band">
  <pb-text id="66f39f31-8735-431e-9848-0db7e34e81ff" tag="h3">Card Title</pb-text>
  <pb-text id="e12f04b1-289f-4a33-a7f9-d506411a67f7" class="button">Read more</pb-text>
</pb-container>

HTML;

    $print = $this->printer->print($tree, $this->catalog)->text;
    $this->assertSame($expected, $print);

    // The print reparses to the parent's wear (the gesture that
    // re-distributes) and the child's own root wear; the fragments are
    // daedalus_stylish's to mint again at apply time.
    $reparse = $this->parser->parseDocument($print, $this->catalog);
    $this->assertSame([], array_map('strval', $reparse->errors));
    $container = $reparse->tree[0];
    $this->assertSame([['variant' => 'band', 'fragment' => '']], $container['styles']['']['variants']);
    $this->assertArrayNotHasKey('styles', $container['children'][0]);
    $this->assertSame([['variant' => 'button', 'fragment' => '']], $container['children'][1]['styles']['']['variants']);
  }

  /**
   * A wear a composition distributed onto a child prints nothing.
   *
   * Applying a composition variant also places the slot's nested ''
   * references onto the resolved child as map references stamped "via"
   * the distributing variant. Such a wear
   * is derived exactly like a fragment reference: the parent's root wear
   * is the authored gesture and re-wearing it re-distributes, origin
   * included, so the child's layout attribute has no print. Printing it
   * would let page_create's tree-order apply re-place it as the child's
   * own authored wear, which a later switch of the composition could not
   * reap (the card wrapper keeping "columns" from Card Left to Stacked). The
   * same wear worn directly, no origin, is the editor's and still prints.
   */
  public function testDerivedWearsPrintNothing(): void {
    $tree = [
      [
        'kind' => 'container',
        'layout_id' => 'daedalus_stylish_container',
        'uuid' => 'c0d89921-04a5-48d1-8720-d7508da565f6',
        'styles' => ['' => ['variants' => [['variant' => 'band', 'fragment' => '']]]],
        'children' => [
          [
            'kind' => 'container',
            'layout_id' => 'daedalus_stylish_container',
            'uuid' => '66f39f31-8735-431e-9848-0db7e34e81ff',
            'styles' => [
              '' => [
                'variants' => [
                  ['variant' => 'columns', 'fragment' => '', 'via' => 'band'],
                  ['variant' => 'band', 'fragment' => 'container(1)'],
                ],
              ],
            ],
            'children' => [],
          ],
          [
            'kind' => 'container',
            'layout_id' => 'daedalus_stylish_container',
            'uuid' => 'e12f04b1-289f-4a33-a7f9-d506411a67f7',
            'styles' => ['' => ['variants' => [['variant' => 'columns', 'fragment' => '']]]],
            'children' => [],
          ],
        ],
      ],
    ];

    $expected = <<<HTML
<pb-container id="c0d89921-04a5-48d1-8720-d7508da565f6" class="band">
  <pb-container id="66f39f31-8735-431e-9848-0db7e34e81ff">
  </pb-container>
  <pb-container id="e12f04b1-289f-4a33-a7f9-d506411a67f7" layout="columns">
  </pb-container>
</pb-container>

HTML;

    $print = $this->printer->print($tree, $this->catalog)->text;
    $this->assertSame($expected, $print);

    // The print reparses to the parent's wear (the gesture that
    // re-distributes the nested row, origin and all) and the second
    // child's own direct wear; the derived one is daedalus_stylish's to stamp
    // again at apply time.
    $reparse = $this->parser->parseDocument($print, $this->catalog);
    $this->assertSame([], array_map('strval', $reparse->errors));
    $container = $reparse->tree[0];
    $this->assertSame([['variant' => 'band', 'fragment' => '']], $container['styles']['']['variants']);
    $this->assertArrayNotHasKey('styles', $container['children'][0]);
    $this->assertSame([['variant' => 'columns', 'fragment' => '']], $container['children'][1]['styles']['']['variants']);
  }

  /**
   * Link attributes print in the authorable grammar, never storage uris.
   *
   * An authored url="<nolink>" printing back as url="route:<nolink>" is
   * storage residue the writer never typed. What is
   * read must be what is written: "route:" and "internal:" uris print as
   * their user-entered form ("<nolink>", "/about", "<front>"), and the
   * forms the write grammar takes verbatim ("entity:node/42", external
   * urls) pass through unchanged. The reparse yields the bare strings.
   *
   * 0.4 sharpens the same rule to the byte level: attribute values are
   * escaped to the HTML5 minimum only (& and "), so url="<nolink>" and
   * url="<front>" print RAW, exactly as the write grammar takes them
   * (the old ENT_COMPAT print's &lt;nolink&gt; was a spelling no writer
   * may use).
   */
  public function testLinkAttributesPrintAuthorable(): void {
    $links = [
      'route:<nolink>' => '<nolink>',
      'internal:/about' => '/about',
      'internal:/' => '<front>',
      'internal:/#top' => '<front>#top',
      'entity:node/42' => 'entity:node/42',
      'https://example.com/x?y=1' => 'https://example.com/x?y=1',
    ];
    $children = [];
    $i = 0;
    foreach ($links as $stored => $authorable) {
      $children[] = [
        'kind' => 'block',
        'uuid' => sprintf('%08d-0000-4000-8000-000000000000', ++$i),
        'block_type' => 'text',
        'block_fields' => [
          'field_text_text' => $authorable,
          'field_text_url' => ['uri' => $stored, '_displayable' => 'ignored'],
        ],
      ];
    }
    $tree = [
      [
        'kind' => 'container',
        'layout_id' => 'daedalus_stylish_container',
        'uuid' => 'c0d89921-04a5-48d1-8720-d7508da565f6',
        'children' => $children,
      ],
    ];

    $print = $this->printer->print($tree, $this->catalog)->text;
    foreach ($links as $stored => $authorable) {
      $this->assertStringContainsString(sprintf(' url="%s"', $authorable), $print);
      $this->assertStringNotContainsString($stored === $authorable ? 'never-printed' : $stored, $print);
    }

    $reparse = $this->parser->parseDocument($print, $this->catalog);
    $this->assertSame([], array_map('strval', $reparse->errors));
    $this->assertSame(array_values($links), array_map(
      static fn (array $node): string => $node['block_fields']['field_text_url'],
      $reparse->tree[0]['children'],
    ), 'The reparse carries the authorable strings the write grammar accepts.');
    $this->assertSame($print, $this->printer->print($reparse->tree, $this->catalog)->text, 'print(parse(x)) == x');
  }

  /**
   * A section on a non-container layout prints its regions as facts.
   *
   * The print is total over the document: a section whose layout is not
   * the container prints data-layout and, with more than one region,
   * data-regions, its regions transparent (every child prints at the
   * depth a container's child would, naming its region in data-region,
   * regions in the layout's order, an empty region printing nothing). A
   * single-region layout prints data-layout alone. The result notes the
   * section once, and the annotations are refused written back.
   */
  public function testRegionSectionsPrintLayoutFacts(): void {
    $text = static fn (string $uuid, string $body): array => [
      'kind' => 'block',
      'uuid' => $uuid,
      'block_type' => 'text',
      'block_fields' => ['field_text_tag' => 'h2', 'field_text_text' => $body],
    ];
    $tree = [
      [
        'kind' => 'container',
        'uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
        'layout_id' => 'layout_threecol_section',
        'children' => [
          [
            'kind' => 'region',
            'region' => 'first',
            'children' => [
              $text('bbbbbbbb-0000-4000-8000-000000000002', 'Left'),
              [
                'kind' => 'container',
                'uuid' => 'cccccccc-0000-4000-8000-000000000003',
                'layout_id' => 'daedalus_stylish_container',
                'children' => [$text('dddddddd-0000-4000-8000-000000000004', 'Nested')],
              ],
            ],
          ],
          ['kind' => 'region', 'region' => 'second', 'children' => [['kind' => 'blueprint', 'name' => 'hero']]],
          ['kind' => 'region', 'region' => 'third', 'children' => []],
        ],
      ],
    ];
    $expected = <<<HTML
<pb-container id="aaaaaaaa-0000-4000-8000-000000000001" data-layout="layout_threecol_section" data-regions="first second third">
  <pb-text id="bbbbbbbb-0000-4000-8000-000000000002" data-region="first" tag="h2">Left</pb-text>
  <pb-container id="cccccccc-0000-4000-8000-000000000003" data-region="first">
    <pb-text id="dddddddd-0000-4000-8000-000000000004" tag="h2">Nested</pb-text>
  </pb-container>
  <pb-blueprint data-region="second" name="hero" />
</pb-container>

HTML;
    $print = $this->printer->print($tree, $this->catalog);
    $this->assertSame($expected, $print->text);
    $this->assertFalse($print->isAuthored());
    $this->assertCount(1, $print->notes);
    $this->assertSame(DialectPrintNoteKind::Layout, $print->notes[0]->kind);
    $this->assertSame('aaaaaaaa-0000-4000-8000-000000000001', $print->notes[0]->elementId);
    $this->assertSame('#aaaaaaaa-0000-4000-8000-000000000001 is a section on the "layout_threecol_section" layout (regions first, second, third), which the page language does not spell', $print->notes[0]->detail);
    $this->assertReadOnlyWrittenBack($print->text);

    // A single-region layout (core's layout_onecol seed): the layout
    // prints, no region does, and the one note says so.
    $single = [
      [
        'kind' => 'container',
        'uuid' => 'aaaaaaaa-0000-4000-8000-000000000001',
        'layout_id' => 'layout_onecol',
        'children' => [
          [
            'kind' => 'region',
            'region' => 'content',
            'children' => [$text('bbbbbbbb-0000-4000-8000-000000000002', 'Only')],
          ],
        ],
      ],
    ];
    $print = $this->printer->print($single, $this->catalog);
    $this->assertSame("<pb-container id=\"aaaaaaaa-0000-4000-8000-000000000001\" data-layout=\"layout_onecol\">\n  <pb-text id=\"bbbbbbbb-0000-4000-8000-000000000002\" tag=\"h2\">Only</pb-text>\n</pb-container>\n", $print->text);
    $this->assertSame(['#aaaaaaaa-0000-4000-8000-000000000001 is a section on the "layout_onecol" layout, which the page language does not spell'], array_map(static fn ($note) => $note->detail, $print->notes));
    $this->assertReadOnlyWrittenBack($print->text);

    // A container-layout section prints no layout fact and no note.
    $this->assertTrue($this->printer->print([$tree[0]['children'][0]['children'][1]], $this->catalog)->isAuthored());
  }

  /**
   * A field the grammar cannot spell is named in data-unprinted, not lost.
   *
   * Three grains, one register: a structured value under a scalar
   * attribute contract, a field with no contract at all on a declared
   * block, and a structured value on an undeclared block's generic form.
   * The element prints with the rest of its fields; the field names ride
   * data-unprinted (catalog order, then the block's own); one note per
   * field names the element and the reason; the annotation is refused
   * written back.
   */
  public function testUnprintedFieldsAreNamedNotPrinted(): void {
    $tree = [
      [
        'kind' => 'container',
        'uuid' => 'c0d89921-04a5-48d1-8720-d7508da565f6',
        'layout_id' => 'daedalus_stylish_container',
        'children' => [
          [
            'kind' => 'block',
            'uuid' => '66f39f31-8735-431e-9848-0db7e34e81ff',
            'block_type' => 'text',
            'block_fields' => [
              'info' => 'Title',
              'field_text_tag' => ['value' => 'h2', 'weight' => 0],
              'field_text_text' => 'Welcome!',
              'field_extra' => 'kept by set, lost by replace',
              'field_stub' => ['_form_only' => TRUE],
            ],
          ],
          [
            'kind' => 'block',
            'uuid' => 'e12f04b1-289f-4a33-a7f9-d506411a67f7',
            'block_type' => 'card',
            'block_fields' => [
              'field_card_title' => 'Plans',
              'field_card_items' => [['value' => 'a'], ['value' => 'b']],
            ],
          ],
        ],
      ],
    ];
    $expected = <<<HTML
<pb-container id="c0d89921-04a5-48d1-8720-d7508da565f6">
  <pb-text id="66f39f31-8735-431e-9848-0db7e34e81ff" data-unprinted="field_text_tag field_extra">Welcome!</pb-text>
  <pb-block id="e12f04b1-289f-4a33-a7f9-d506411a67f7" data-unprinted="field_card_items" type="card">
    <pb-field name="field_card_title">Plans</pb-field>
  </pb-block>
</pb-container>

HTML;
    $print = $this->printer->print($tree, $this->catalog);
    $this->assertSame($expected, $print->text);
    $title = '66f39f31-8735-431e-9848-0db7e34e81ff';
    $card = 'e12f04b1-289f-4a33-a7f9-d506411a67f7';
    $this->assertSame([
      [
        DialectPrintNoteKind::UnprintedField,
        $title,
        "#$title holds field field_text_tag as a structured value, which the tag attribute does not spell",
      ],
      [
        DialectPrintNoteKind::UnprintedField,
        $title,
        "#$title holds field field_extra, which has no attribute or body contract in the catalog",
      ],
      [
        DialectPrintNoteKind::UnprintedField,
        $card,
        "#$card holds field field_card_items as a structured value, which the generic <pb-field> form does not spell",
      ],
    ], $this->noteTuples($print->notes));
    $this->assertReadOnlyWrittenBack($print->text);
  }

  /**
   * A link with a title or options prints its bare uri, marked partial.
   *
   * The uri is the one spelling the url attribute has, so it prints (the
   * read teaches the write) and the attribute's name rides data-partial:
   * a set on it replaces the whole link. A link with no uri at all is an
   * unprinted field. Both annotations are refused written back.
   */
  public function testPartialLinkPrintsBareUri(): void {
    $tree = [
      [
        'kind' => 'container',
        'uuid' => 'c0d89921-04a5-48d1-8720-d7508da565f6',
        'layout_id' => 'daedalus_stylish_container',
        'children' => [
          [
            'kind' => 'block',
            'uuid' => '66f39f31-8735-431e-9848-0db7e34e81ff',
            'block_type' => 'text',
            'block_fields' => [
              'field_text_text' => 'About',
              'field_text_url' => [
                'uri' => 'internal:/about',
                'title' => 'About us',
                'options' => ['attributes' => ['target' => '_blank']],
                '_displayable' => 'ignored',
              ],
            ],
          ],
          [
            'kind' => 'block',
            'uuid' => 'e12f04b1-289f-4a33-a7f9-d506411a67f7',
            'block_type' => 'text',
            'block_fields' => [
              'field_text_text' => 'Nowhere',
              'field_text_url' => ['title' => 'A title with no uri'],
            ],
          ],
        ],
      ],
    ];
    $expected = <<<HTML
<pb-container id="c0d89921-04a5-48d1-8720-d7508da565f6">
  <pb-text id="66f39f31-8735-431e-9848-0db7e34e81ff" data-partial="url" url="/about">About</pb-text>
  <pb-text id="e12f04b1-289f-4a33-a7f9-d506411a67f7" data-unprinted="field_text_url">Nowhere</pb-text>
</pb-container>

HTML;
    $print = $this->printer->print($tree, $this->catalog);
    $this->assertSame($expected, $print->text);
    $about = '66f39f31-8735-431e-9848-0db7e34e81ff';
    $nowhere = 'e12f04b1-289f-4a33-a7f9-d506411a67f7';
    $this->assertSame([
      [
        DialectPrintNoteKind::PartialField,
        $about,
        "#$about prints url as the bare uri; the stored link also carries title, options, which the page language does not spell",
      ],
      [
        DialectPrintNoteKind::UnprintedField,
        $nowhere,
        "#$nowhere holds field field_text_url as a link with no uri (title), which the url attribute does not spell",
      ],
    ], $this->noteTuples($print->notes));
    $this->assertReadOnlyWrittenBack($print->text);
  }

  /**
   * A fragment over a tree carrying rules prints without them and says so.
   *
   * A fragment has no <style> block, so the conditions and parts its
   * elements carry are left out and the result carries one note for the
   * fragment (no element). A definition tree without ids has nothing a
   * rule could address, so its gap is the element's own note instead;
   * either way the print is not wholly authored.
   */
  public function testFragmentWithRulesPrintsWithoutThem(): void {
    $container = static fn (array $block): array => [
      [
        'kind' => 'container',
        'layout_id' => 'daedalus_stylish_container',
        'children' => [$block],
      ],
    ];
    $styled = [
      'kind' => 'block',
      'uuid' => '66f39f31-8735-431e-9848-0db7e34e81ff',
      'block_type' => 'text',
      'block_fields' => ['field_text_text' => 'Hi'],
      'styles' => ['' => ['default' => ['color' => 'red'], 'hover' => ['color' => 'blue']]],
    ];
    $print = $this->printer->printFragment($container($styled), $this->catalog);
    $this->assertSame("<pb-container>\n  <pb-text id=\"66f39f31-8735-431e-9848-0db7e34e81ff\" style=\"color: red\">Hi</pb-text>\n</pb-container>\n", $print->text);
    $this->assertFalse($print->isAuthored());
    $this->assertSame([
      [
        DialectPrintNoteKind::UnprintedStyle,
        NULL,
        'the fragment leaves out the conditions and parts its elements carry; a fragment spells only style="", conditions and parts ride the style op',
      ],
    ], $this->noteTuples($print->notes));

    unset($styled['uuid']);
    $print = $this->printer->printFragment($container($styled), $this->catalog);
    $this->assertSame("<pb-container>\n  <pb-text style=\"color: red\">Hi</pb-text>\n</pb-container>\n", $print->text);
    $this->assertSame([
      [
        DialectPrintNoteKind::UnprintedStyle,
        NULL,
        '<pb-text> carries conditions or parts, which have no print on an element without an id',
      ],
    ], $this->noteTuples($print->notes));

    // Only style="" on an id-less element: a wholly authored fragment.
    $styled['styles'] = ['' => ['default' => ['color' => 'red']]];
    $this->assertTrue($this->printer->printFragment($container($styled), $this->catalog)->isAuthored());
  }

  /**
   * A print's notes as [kind, element id, detail] tuples, for assertions.
   *
   * @param \Drupal\daedalus_blueprint\Dialect\DialectPrintNote[] $notes
   *   The notes.
   *
   * @return array
   *   One tuple per note, in order.
   */
  protected function noteTuples(array $notes): array {
    return array_map(static fn ($note) => [$note->kind, $note->elementId, $note->detail], $notes);
  }

  /**
   * Asserts a print's read-only annotations are refused written back.
   *
   * The data-* register is the parser's existing READONLY_ATTR rule: a
   * document carrying one is refused with that code (other errors may
   * ride along; the annotation alone closes the write).
   */
  protected function assertReadOnlyWrittenBack(string $print): void {
    $result = $this->parser->parseDocument($print, $this->catalog);
    $codes = array_unique(array_map(static fn ($e) => $e->code, $result->errors));
    $this->assertContains('READONLY_ATTR', $codes, implode(', ', $codes));
    // With the annotations stripped the document is the authored grammar
    // again: what the model may write back is exactly the rest.
    $stripped = preg_replace('/ data-[a-z]+="[^"]*"/', '', $print);
    $result = $this->parser->parseDocument($stripped, $this->catalog);
    $this->assertSame([], array_map('strval', $result->errors));
  }

  /**
   * Structural errors carry the source line of the offending construct.
   */
  public function testErrorsCarryLineNumbers(): void {
    $document = <<<HTML
<pb-container id="a">
  <pb-text id="t">Hi
</pb-container>
<style>
  #t { color: var(--color-text); }
  @media (max-width: 500px) {
    #t { color: red; }
  }
</style>
HTML;
    $result = $this->parser->parseDocument($document, $this->catalog);
    $by_code = [];
    foreach ($result->errors as $error) {
      $by_code[$error->code][] = $error->line;
    }
    // The pb-text opened on line 2 is implicitly closed by the container's
    // end tag; the error points at where it opened.
    $this->assertSame([2], $by_code['UNCLOSED_ELEMENT'] ?? []);
    // The unpublished wrapper opens on line 6.
    $this->assertSame([6], $by_code['UNKNOWN_WRAPPER'] ?? []);
  }

  /**
   * The accept-side op-batch corpus.
   *
   * Save_material rides the batch untouched: it is another handler's op
   * and the envelope validator does not judge it (at the spine it
   * resolves through the registry).
   */
  protected static function acceptBatches(): array {
    return [
      'every DOM op in one envelope, plus a registered gesture' => [
        [
          'op' => 'insert',
          'html' => '<pb-container id="hero"><pb-text id="t" tag="h2">Hi</pb-text></pb-container>',
          'at' => ['ref' => 'root', 'position' => 'beforeend'],
        ],
        [
          'op' => 'set',
          'ref' => 'hero-title',
          'attrs' => ['tag' => 'h2', 'style' => 'color: var(--color-muted)'],
        ],
        [
          'op' => 'replace',
          'id' => 'hero',
          'html' => '<pb-container id="hero2"><pb-blueprint name="hero"/></pb-container>',
        ],
        [
          'op' => 'move',
          'ref' => 'pic',
          'at' => ['ref' => 'hero2', 'position' => 'afterbegin'],
        ],
        [
          'op' => 'style',
          'css' => '#cta:hover { color: var(--color-accent); }',
          'remove' => [['selector' => '#cta', 'properties' => ['letter-spacing']]],
        ],
        ['op' => 'remove', 'ref' => 'old-band'],
        ['op' => 'save_material', 'ref' => 'hero2', 'name' => 'hero'],
      ],
      'a file declaration ahead of the ops that place it' => [
        [
          'op' => 'file',
          'name' => 'logo.svg',
          'type' => 'image/svg+xml',
          'content' => '<svg xmlns="http://www.w3.org/2000/svg"/>',
        ],
        [
          'op' => 'insert',
          'html' => '<pb-image id="mark" file="logo.svg" alt="The mark" />',
          'at' => ['ref' => 'hero', 'position' => 'beforeend'],
        ],
        ['op' => 'style', 'css' => "#hero { background-image: url('file:logo.svg'); }"],
      ],
      'bare op object is a one-element batch' => [
        'op' => 'set',
        'ref' => 'hero-title',
        'attrs' => ['tag' => 'h3'],
      ],
      '{ops: [...]} envelope' => [
        'ops' => [
          ['op' => 'remove', 'ref' => 'old-band'],
        ],
      ],
      'v0.3: set detaches worn variants, alone or beside attrs' => [
        ['op' => 'set', 'ref' => 'cta', 'detach' => ['button']],
        ['op' => 'set', 'ref' => 'hero', 'detach' => ['width', 'band'], 'attrs' => ['layout' => 'columns']],
      ],
      'v0.3: set on root patches the page\'s own fields' => [
        ['op' => 'set', 'ref' => 'root', 'attrs' => ['title' => 'Renamed page']],
      ],
      'v0.3: the material gestures ride page_edit with aliases' => [
        [
          'op' => 'save_material',
          'kind' => 'blueprint',
          'element' => 'hero',
          'label' => 'Hero',
          'description' => 'The hero band',
        ],
        ['op' => 'update_material', 'kind' => 'blueprint', 'id' => 'hero', 'element' => 'hero'],
        ['op' => 'remove_material', 'kind' => 'swatch', 'id' => 'lime_green'],
      ],
    ];
  }

  /**
   * The reject-side op-batch corpus: [batch, expected codes 'A|B'].
   *
   * Envelope rejects plus the fluency spills (descendant selectors into
   * rich text, compound #id.class): the two measured error classes, each
   * rejected with a corrective naming the dialect gesture. An unknown op
   * (add_section) is a tool-surface restriction (page_edit's op set), not
   * the spine's: at the spine unknown ops resolve through the registry,
   * so only the position error is asserted here.
   */
  protected static function rejectBatches(): array {
    return [
      'a file op without its bytes' => [
        [
          ['op' => 'file', 'name' => 'logo.svg', 'type' => 'image/svg+xml'],
        ],
        'BAD_OP',
      ],
      'a file op whose name is not a string' => [
        [
          ['op' => 'file', 'name' => 42, 'type' => 'image/svg+xml', 'content' => '<svg/>'],
        ],
        'BAD_OP',
      ],
      'bad position (an unknown op beside it is the tool surface\'s to refuse)' => [
        [
          ['op' => 'insert', 'html' => '<pb-text id="t">Hi</pb-text>', 'at' => ['ref' => 'c', 'position' => 'inside']],
        ],
        'BAD_OP',
      ],
      'sugar rides an op payload' => [
        [
          ['op' => 'insert', 'html' => '<div>Hi</div>', 'at' => ['ref' => 'c', 'position' => 'beforeend']],
        ],
        'SUGAR_TAG',
      ],
      'clamp() rides a set op style' => [
        [
          ['op' => 'set', 'ref' => 'hero-title', 'attrs' => ['style' => 'font-size: clamp(1rem, 2vw, 3rem)']],
        ],
        'UNSUPPORTED_VALUE',
      ],
      'calc() rides a style op' => [
        [
          ['op' => 'style', 'css' => '#hero { padding-top: calc(2rem + 5px); }'],
        ],
        'UNSUPPORTED_VALUE',
      ],
      'fluency spill: multi-element descent into rich text (a chain of tags is grammar; span is no declared tag)' => [
        [
          ['op' => 'style', 'css' => '#hero h2 span { font-size: var(--text-3xl); }'],
        ],
        'STYLE_TAG_NOT_DECLARED',
      ],
      'fluency spill: compound #id.class selector' => [
        [
          ['op' => 'style', 'css' => '#cta.button { padding: var(--space-2); }'],
        ],
        'BAD_SELECTOR',
      ],
      'set without a ref' => [
        [
          ['op' => 'set', 'attrs' => ['tag' => 'h2']],
        ],
        'BAD_OP',
      ],
      'insert without html' => [
        [
          ['op' => 'insert', 'at' => ['ref' => 'c', 'position' => 'beforeend']],
        ],
        'BAD_OP',
      ],
      'style with neither css nor remove' => [
        [
          ['op' => 'style'],
        ],
        'BAD_OP',
      ],
      'hallucinated token in a style op' => [
        [
          ['op' => 'style', 'css' => '#hero { background: var(--color-bg-dark); }'],
        ],
        'UNKNOWN_TOKEN',
      ],
    ];
  }

  /**
   * Every accept-side batch validates clean at the envelope level.
   */
  public function testOpBatchCorpusAccepts(): void {
    $handler = $this->container->get('daedalus_blueprint.dom_operation_handler');
    $failures = [];
    foreach (static::acceptBatches() as $name => $batch) {
      $errors = $handler->validateBatch($batch, $this->catalog);
      if ($errors !== []) {
        $failures[] = sprintf('ACCEPT batch %s was rejected: %s', $name, implode('; ', array_map('strval', $errors)));
      }
    }
    $this->assertSame([], $failures, implode("\n", $failures));
  }

  /**
   * Every reject-side batch fails with the expected code and a corrective.
   */
  public function testOpBatchCorpusRejects(): void {
    $handler = $this->container->get('daedalus_blueprint.dom_operation_handler');
    $failures = [];
    foreach (static::rejectBatches() as $name => [$batch, $codes]) {
      $errors = $handler->validateBatch($batch, $this->catalog);
      $got = array_unique(array_map(static fn ($e) => $e->code, $errors));
      if (array_intersect(explode('|', $codes), $got) === []) {
        $failures[] = sprintf('REJECT batch %s: wanted %s, got %s', $name, $codes, $got === [] ? 'VALID' : implode(', ', $got));
      }
    }
    $this->assertSame([], $failures, implode("\n", $failures));

    // The fluency-spill correctives name the dialect gesture, not just the
    // grammar (the corrective channel is what measurably teaches). A chain
    // of tags is grammar now (a paragraph's links), so the spill is the
    // undeclared tag in it, and its corrective names the declared tags.
    $spill = $handler->validateBatch([
      ['op' => 'style', 'css' => '#hero h2 span { font-size: var(--text-3xl); }'],
    ], $this->catalog);
    $this->assertStringContainsString('span is not an addressable text tag', $spill[0]->message);
    $tag_tier = $handler->validateBatch([
      ['op' => 'style', 'css' => '#hero h2 { font-size: var(--text-3xl); }'],
    ], $this->catalog);
    $this->assertSame([], $tag_tier, 'A descent into ONE declared tag is the canonical tag-tier gesture.');
    $compound = $handler->validateBatch([
      ['op' => 'style', 'css' => '#cta.button { padding: var(--space-2); }'],
    ], $this->catalog);
    $this->assertStringContainsString('class=""', $compound[0]->message);
  }

  /**
   * The parser distinguishes printed uuids from author aliases.
   */
  public function testIdentityShape(): void {
    $result = $this->parser->parseFragment('<pb-text id="66f39f31-8735-431e-9848-0db7e34e81ff">A</pb-text><pb-text id="hero-title">B</pb-text>', $this->catalog);
    $this->assertTrue($result->isValid());
    $this->assertSame('66f39f31-8735-431e-9848-0db7e34e81ff', $result->tree[0]['uuid'] ?? NULL);
    $this->assertArrayNotHasKey('id', $result->tree[0]);
    $this->assertSame('hero-title', $result->tree[1]['id'] ?? NULL);
    $this->assertArrayNotHasKey('uuid', $result->tree[1]);
  }

  /**
   * V0.4: media tokens print single-quoted in style="" and in <style>.
   *
   * The print teaches the write (a printer emitting url(&quot;media:...)
   * inside style="" teaches the model the one form that cannot be written
   * there), so every style print site canonicalizes tokens to the
   * single-quoted spelling, whatever quoting the stored value carries:
   * values stored under the older quoting never migrate, they
   * re-canonicalize on print and on their next write.
   */
  public function testMediaTokensPrintSingleQuoted(): void {
    $uuid = '0f7b0c2e-8c7a-4a9e-9d0a-2f1f8e3c4b5d';
    // The retired double-quoted stored spelling, constructed (the literal
    // is banned from the vocabulary).
    $legacy = str_replace("'", '"', "url('media:$uuid')");
    $tree = [
      [
        'kind' => 'container',
        'layout_id' => 'daedalus_stylish_container',
        'uuid' => 'c0d89921-04a5-48d1-8720-d7508da565f6',
        'children' => [],
        'styles' => [
          '' => [
            'default' => ['background-image' => 'linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5)), ' . $legacy],
            'hover' => ['background-image' => $legacy],
          ],
        ],
      ],
    ];

    $expected = <<<HTML
<pb-container id="c0d89921-04a5-48d1-8720-d7508da565f6" style="background-image: linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5)), url('media:$uuid')">
</pb-container>
<style>
  #c0d89921-04a5-48d1-8720-d7508da565f6:hover { background-image: url('media:$uuid'); }
</style>

HTML;
    $print = $this->printer->print($tree, $this->catalog)->text;
    $this->assertSame($expected, $print);

    $reparse = $this->parser->parseDocument($print, $this->catalog);
    $this->assertSame([], array_map('strval', $reparse->errors));
    $this->assertSame($print, $this->printer->print($reparse->tree, $this->catalog)->text, 'print(parse(x)) == x');
    $this->assertSame("url('media:$uuid')", $reparse->tree[0]['styles']['']['hover']['background-image'], 'The parse stores the canonical single-quoted token.');
  }

  /**
   * V0.4: the early-closed-attribute cascade collapses to ONE error.
   *
   * A hero with url("generate:...") in raw double quotes inside style=""
   * closes the attribute at the first embedded quote and the rest of the
   * value parses as junk attributes (37 ILLEGAL_ATTR lines for one cause,
   * none naming it). The walk diagnoses the shape (an
   * attribute value left with an unbalanced "(", illegal names after it)
   * and reports exactly one UNTERMINATED_ATTR naming the attribute and
   * the single-quote fix; the junk and the truncated value's own
   * declaration errors are swallowed.
   */
  public function testEarlyClosedStyleAttributeCollapsesToOneError(): void {
    $document = '<pb-container id="hero" style="background-image: linear-gradient(rgb(0 0 0 / 0.6), rgb(0 0 0 / 0.6)), url("generate:faint dark blueprint schematics on deep navy")"></pb-container>';
    $result = $this->parser->parseDocument($document, $this->catalog);
    $codes = array_map(static fn ($e) => $e->code, $result->errors);
    $this->assertSame(['UNTERMINATED_ATTR'], $codes, implode('; ', array_map('strval', $result->errors)));
    $this->assertStringContainsString('style=""', $result->errors[0]->message);
    $this->assertStringContainsString("url('media:<uuid>')", $result->errors[0]->message);
    $this->assertStringContainsString('single quotes', $result->errors[0]->message);
  }

  /**
   * V0.3: url('generate:<prompt>') is the CSS spelling of the handled value.
   *
   * Every CSS entry point (style="", the style op, <style> blocks) goes
   * through parseDeclarations, so the translation lands the {_generate}
   * value the spine's value handlers resolve, and a media token passes
   * through untouched for the spine to validate.
   */
  public function testGenerateUrlTranslatesToTheHandledValue(): void {
    $css = new DialectCssParser($this->catalog);
    $errors = [];
    $properties = $css->parseDeclarations("background-image: url('generate: a sunrise over the bay '); color: var(--color-text); background-color: var(--color-bg)", NULL, 'test', $errors);
    $this->assertSame([], $errors);
    $this->assertSame(['_generate' => 'a sunrise over the bay'], $properties['background-image']);
    $this->assertSame('var(--color-text)', $properties['color']);

    $errors = [];
    $properties = $css->parseDeclarations("background-image: url('media:0f7b0c2e-8c7a-4a9e-9d0a-2f1f8e3c4b5d')", NULL, 'test', $errors);
    $this->assertSame([], $errors);
    $this->assertSame("url('media:0f7b0c2e-8c7a-4a9e-9d0a-2f1f8e3c4b5d')", $properties['background-image'], 'The media token is the spine\'s to validate; it rides verbatim.');

    // A media token composes with a gradient as one plain CSS value. A
    // pre-0.4 double-quoted token (constructed; the literal is banned)
    // still reads, and the parse re-canonicalizes it to the
    // single-quoted spelling (0.4).
    $errors = [];
    $legacy = str_replace("'", '"', "url('media:0f7b0c2e-8c7a-4a9e-9d0a-2f1f8e3c4b5d')");
    $properties = $css->parseDeclarations('background-image: linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5)), ' . $legacy, NULL, 'test', $errors);
    $this->assertSame([], $errors);
    $this->assertSame("linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5)), url('media:0f7b0c2e-8c7a-4a9e-9d0a-2f1f8e3c4b5d')", $properties['background-image']);

    // The double-quoted generate spelling reads too (either quote parses;
    // only the single-quoted form is taught or printed).
    $errors = [];
    $properties = $css->parseDeclarations(str_replace("'", '"', "background-image: url('generate:a sunrise')"), NULL, 'test', $errors);
    $this->assertSame([], $errors);
    $this->assertSame(['_generate' => 'a sunrise'], $properties['background-image']);
  }

  /**
   * A gradient composes over a generation request in ONE write.
   *
   * A value carrying a generation request splits on top-level commas
   * (paren-aware) into the layer list StyleValueResolver takes on
   * media-capable properties: literal CSS fragments (media tokens
   * canonicalized per layer) and {_generate} handled objects, joined back
   * with commas once the media is minted. The darkened generated hero,
   * the pattern the model reached for three times in the fourth live
   * run, is one declaration.
   */
  public function testCompositeGenerateTranslatesToTheLayerList(): void {
    $css = new DialectCssParser($this->catalog);
    $errors = [];
    $properties = $css->parseDeclarations("background-image: linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5)), url('generate:a sunrise over the bay')", NULL, 'test', $errors);
    $this->assertSame([], array_map('strval', $errors));
    $this->assertSame([
      'linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5))',
      ['_generate' => 'a sunrise over the bay'],
    ], $properties['background-image']);

    // Literal layers canonicalize their media tokens like any value; the
    // legacy double-quoted spelling is constructed, never spelled.
    $errors = [];
    $legacy = str_replace("'", '"', "url('media:0f7b0c2e-8c7a-4a9e-9d0a-2f1f8e3c4b5d')");
    $properties = $css->parseDeclarations("background-image: $legacy, url('generate:a matching texture')", NULL, 'test', $errors);
    $this->assertSame([], array_map('strval', $errors));
    $this->assertSame([
      "url('media:0f7b0c2e-8c7a-4a9e-9d0a-2f1f8e3c4b5d')",
      ['_generate' => 'a matching texture'],
    ], $properties['background-image']);

    // A gradient over a generate url is an accepted document: the whole
    // document path lands the layer list on the node's styles.
    $document = '<pb-container id="c" style="background-image: linear-gradient(black, black), url(generate:a sunrise)"></pb-container>';
    $result = $this->parser->parseDocument($document, $this->catalog);
    $this->assertSame([], array_map('strval', $result->errors));
    $this->assertSame(
      ['linear-gradient(black, black)', ['_generate' => 'a sunrise']],
      $result->tree[0]['styles']['']['default']['background-image'],
    );
  }

  /**
   * The file url is the CSS spelling of the {_file} handled value.
   *
   * The file handle rides the same translation as the generation request
   * through parseDeclarations, so every CSS entry point lands the {_file}
   * value daedalus_ai's style handler resolves: alone as the bare handled
   * value, as one layer of a media-capable value in the layer list, quote
   * and prefix-case agnostic with the name kept exact, refused with a
   * corrective when the name is empty or the call is nested inside
   * another function, and taught in the url() corrective.
   */
  public function testFileUrlTranslatesToTheHandledValue(): void {
    $css = new DialectCssParser($this->catalog);
    $errors = [];
    $properties = $css->parseDeclarations("background-image: url('file: Hero.svg '); color: var(--color-text)", NULL, 'test', $errors);
    $this->assertSame([], array_map('strval', $errors));
    $this->assertSame(['_file' => 'Hero.svg'], $properties['background-image'], 'The name is trimmed and kept case-exact: file aliases are exact-match.');
    $this->assertSame('var(--color-text)', $properties['color']);

    $errors = [];
    $properties = $css->parseDeclarations(str_replace("'", '"', "background-image: url('FILE:hero.svg')"), NULL, 'test', $errors);
    $this->assertSame([], array_map('strval', $errors));
    $this->assertSame(['_file' => 'hero.svg'], $properties['background-image'], 'Either quote and any prefix case read.');

    $errors = [];
    $properties = $css->parseDeclarations("background-image: linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5)), url('file:hero.svg')", NULL, 'test', $errors);
    $this->assertSame([], array_map('strval', $errors));
    $this->assertSame([
      'linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5))',
      ['_file' => 'hero.svg'],
    ], $properties['background-image'], 'A gradient over a file handle is the layer list, like a gradient over a generation request.');

    $errors = [];
    $properties = $css->parseDeclarations("background-image: url('file:texture.png'), url('generate:a matching sky')", NULL, 'test', $errors);
    $this->assertSame([], array_map('strval', $errors));
    $this->assertSame([['_file' => 'texture.png'], ['_generate' => 'a matching sky']], $properties['background-image'], 'The two handled kinds compose as layers of one value.');

    $errors = [];
    $css->parseDeclarations("background-image: url('file:')", NULL, 'test', $errors);
    $this->assertCount(1, $errors);
    $this->assertSame('BAD_DECLARATION', $errors[0]->code);
    $this->assertStringContainsString("url('file:') in background-image needs a name", $errors[0]->message);

    $errors = [];
    $css->parseDeclarations("background-image: cross-fade(url('file:hero.svg'), black)", NULL, 'test', $errors);
    $this->assertCount(1, $errors);
    $this->assertSame('BAD_DECLARATION', $errors[0]->code);
    $this->assertStringContainsString("url('file:...') in background-image must be its own comma layer", $errors[0]->message);

    $errors = [];
    $css->parseDeclarations("background-image: url('/x.png')", NULL, 'test', $errors);
    $this->assertCount(1, $errors);
    $this->assertSame('URL_FUNCTION', $errors[0]->code);
    $this->assertStringContainsString("url('file:<name>') (a file this conversation holds, by its name)", $errors[0]->message, 'The url() corrective teaches the third scheme.');

    // The whole document path lands the file handle on the node's styles.
    $document = '<pb-container id="c" style="background-image: url(file:hero.svg)"></pb-container>';
    $result = $this->parser->parseDocument($document, $this->catalog);
    $this->assertSame([], array_map('strval', $result->errors));
    $this->assertSame(['_file' => 'hero.svg'], $result->tree[0]['styles']['']['default']['background-image']);
  }

  /**
   * V0.5: the instance selector grammar, its mappings and correctives.
   *
   * The dialect lane's selector cases: `#id .s-x` (one identified
   * rich-text element, existence-checked against the element's actual
   * content), `#id p` (the element-scoped tag tier over the declared
   * vocabulary — li included since universal minting put items in it,
   * so `#id li` is plain bare-tag grammar), and `#id .s-x > *` (part +
   * children composed, ONE list's items, beside the li tier).
   * Correctives are teaching surfaces: the existence miss names the
   * element's real instances with excerpts, the undeclared tag names
   * the declared vocabulary, and the descendant rejection teaches the
   * tag-tier gesture instead of training the model away from it.
   */
  public function testInstanceSelectorGrammar(): void {
    $css = new DialectCssParser($this->catalog);
    $known = [
      'copy' => [
        'container' => FALSE,
        'block_type' => 'formatted_text',
        'body' => '<p class="s-a1">Lead copy here</p><p>Plain paragraph</p><ul class="s-b2"><li>One</li><li>Two</li></ul>',
      ],
      'plain' => ['container' => FALSE, 'block_type' => 'text'],
    ];

    // The three v0.5 spellings map onto the storage grammar's locals.
    $errors = [];
    $this->assertSame(['id' => 'copy', 'local' => '::.s-a1', 'states' => []], $css->parseSelector('#copy .s-a1', NULL, $known, $errors));
    $this->assertSame(['id' => 'copy', 'local' => '::p', 'states' => []], $css->parseSelector('#copy p', NULL, $known, $errors));
    $this->assertSame(['id' => 'copy', 'local' => '::p', 'states' => ['hover']], $css->parseSelector('#copy p:hover', NULL, $known, $errors));
    $this->assertSame(['id' => 'copy', 'local' => '::.s-b2 > *', 'states' => []], $css->parseSelector('#copy .s-b2 > *', NULL, $known, $errors));
    // Li is a declared tag (universal minting), so #id li is plain
    // bare-tag grammar: ALL of the element's list items, any depth,
    // present and future. #id .s-x > * stays the ONE-list gesture.
    $this->assertSame(['id' => 'copy', 'local' => '::li', 'states' => []], $css->parseSelector('#copy li', NULL, $known, $errors));
    $this->assertSame([], array_map('strval', $errors));

    // The existence miss names the element's real instances with excerpts.
    $errors = [];
    $this->assertNull($css->parseSelector('#copy .s-zz', NULL, $known, $errors));
    $this->assertSame('UNKNOWN_INSTANCE', $errors[0]->code);
    $this->assertStringContainsString('.s-a1 <p> "Lead copy here"', $errors[0]->message);
    $this->assertStringContainsString('.s-b2 <ul>', $errors[0]->message);

    // An element without a rich body has no instances to address.
    $errors = [];
    $this->assertNull($css->parseSelector('#plain .s-a1', NULL, $known, $errors));
    $this->assertSame('UNKNOWN_INSTANCE', $errors[0]->code);
    $this->assertStringContainsString('no rich text body', $errors[0]->message);

    // An undeclared tag gets the declared vocabulary and the one-list
    // recipe.
    $errors = [];
    $this->assertNull($css->parseSelector('#copy div', NULL, $known, $errors));
    $this->assertSame('STYLE_TAG_NOT_DECLARED', $errors[0]->code);
    $this->assertStringContainsString('h1, h2, h3, h4, h5, h6, p, a, ul, ol, li, blockquote, pre, code, img', $errors[0]->message);
    $this->assertStringContainsString('ONE list\'s items are #id .s-x > *', $errors[0]->message);

    // A chain of tags is grammar (a paragraph's links, say); an
    // undeclared tag in it is judged by the tag tier's vocabulary.
    $errors = [];
    $this->assertNull($css->parseSelector('#copy div span', NULL, $known, $errors));
    $this->assertSame('STYLE_TAG_NOT_DECLARED', $errors[0]->code);
    $this->assertSame(['id' => 'copy', 'local' => '::p a', 'states' => ['hover']], $css->parseSelector('#copy p a:hover', NULL, $known, $errors));
    // A sibling combinator between compounds is grammar: the p right
    // after a p inside the body, the p resolving where its predecessor
    // did (the tag tier of the same owner).
    $errors = [];
    $this->assertSame(['id' => 'copy', 'local' => '::p + p', 'states' => []], $css->parseSelector('#copy p + p', NULL, $known, $errors));
    $this->assertSame([], array_map('strval', $errors));
    // What the grammar refuses outright names the grammar: a pseudo-class
    // off the addressed element, an attribute, a chained instance class.
    foreach (['#copy p:hover a', '#copy [x]', '#copy .s-a1 .s-b2'] as $selector) {
      $errors = [];
      $this->assertNull($css->parseSelector($selector, NULL, $known, $errors), $selector);
      $this->assertSame('BAD_SELECTOR', $errors[0]->code, $selector);
      $this->assertStringContainsString('is not a relative selector the grammar accepts', $errors[0]->message, $selector);
    }
    // A state on #id while addressing a descendant is refused with the
    // spelling that means it (the state belongs on the addressed element).
    $errors = [];
    $this->assertNull($css->parseSelector('#copy:hover p', NULL, $known, $errors));
    $this->assertSame('BAD_SELECTOR', $errors[0]->code);
    $this->assertStringContainsString('#copy p:hover', $errors[0]->message);
    // Children, types and chains on a container; a block has no children
    // to address; an undeclared type names the declared ones.
    $known['band'] = ['container' => TRUE, 'block_type' => NULL, 'plugin_id' => NULL, 'body' => NULL];
    $errors = [];
    $this->assertSame(['id' => 'band', 'local' => '::> *', 'states' => []], $css->parseSelector('#band > *', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text', 'states' => ['hover']], $css->parseSelector('#band > pb-text:hover', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text .text-block-link', 'states' => []], $css->parseSelector('#band>pb-text .text-block-link', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::pb-container > *', 'states' => []], $css->parseSelector('#band pb-container > *', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-block', 'states' => []], $css->parseSelector('#band > pb-block', NULL, $known, $errors));
    // Position facts ride the pseudo-class slot like states, resolved by
    // their printed wrapper (":nth-child(odd)" is the fact named odd);
    // a state and a fact compose on the addressed element.
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text', 'states' => ['first-child']], $css->parseSelector('#band > pb-text:first-child', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> *', 'states' => ['odd']], $css->parseSelector('#band > *:nth-child(odd)', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text', 'states' => ['hover', 'last-child']], $css->parseSelector('#band > pb-text:hover:last-child', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '', 'states' => ['empty']], $css->parseSelector('#band:empty', NULL, $known, $errors));
    $this->assertSame([], array_map('strval', $errors));
    // A parameterized structural pseudo-class is no published fact: the
    // corrective names the states and the facts apart.
    $errors = [];
    $this->assertNull($css->parseSelector('#band > *:nth-child(3n+1)', NULL, $known, $errors));
    $this->assertSame('UNKNOWN_STATE', $errors[0]->code);
    $this->assertSame(['first-child', 'last-child', 'only-child', 'empty', 'odd', 'even', 'not-first-child', 'not-last-child', 'not-only-child', 'not-empty'], $errors[0]->context['published_facts']);
    $this->assertStringContainsString('Legal states: :hover, :focus, :focus-within, :focus-visible, :active.', $errors[0]->hint);
    $this->assertStringContainsString('Position facts: :first-child, :last-child, :only-child, :empty, :nth-child(odd), :nth-child(even), :not(:first-child), :not(:last-child), :not(:only-child), :not(:empty).', $errors[0]->hint);
    // The catalog resolves a negation's printed wrapper to its fact the
    // way it resolves the base fact's (the parser's reading of the
    // nested pseudo-class is the dialect task's).
    $this->assertSame('not-last-child', static::catalog()->pseudoClassCondition(':not(:last-child)'));
    $this->assertSame('last-child', static::catalog()->pseudoClassCondition(':last-child'));
    $errors = [];
    $this->assertNull($css->parseSelector('#plain > pb-text', NULL, $known, $errors));
    $this->assertSame('BAD_SELECTOR', $errors[0]->code);
    $this->assertStringContainsString('containers only', $errors[0]->message);
    $errors = [];
    $this->assertNull($css->parseSelector('#band > pb-ghost', NULL, $known, $errors));
    $this->assertSame('UNKNOWN_TYPE', $errors[0]->code);
    $this->assertStringContainsString('pb-text', $errors[0]->hint);
    // A part after a type is owned by that type, not by the container.
    $errors = [];
    $this->assertNull($css->parseSelector('#band > pb-image .text-block-link', NULL, $known, $errors));
    $this->assertSame('UNKNOWN_PART', $errors[0]->code);

    // The relations. The peel takes the trailing states and facts off
    // the last compound and stops at its qualifiers: a state after a
    // :has(), a negated fact after a :not() compound, a :not() whose
    // argument is a compound with an implied subject; a sibling compound
    // resolves in its predecessor's context, a :has() argument from the
    // compound it narrows.
    $errors = [];
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text:has(a)', 'states' => ['hover']], $css->parseSelector('#band > pb-text:has(a):hover', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> *:not(pb-image)', 'states' => ['not-last-child']], $css->parseSelector('#band > *:not(pb-image):not(:last-child)', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text:not(:has(a))', 'states' => []], $css->parseSelector('#band > pb-text:not(:has(a))', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> p + p', 'states' => []], $css->parseSelector('#band > p + p', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text + pb-image', 'states' => ['first-child']], $css->parseSelector('#band > pb-text + pb-image:first-child', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text:has(+ pb-image)', 'states' => []], $css->parseSelector('#band > pb-text:has(+ pb-image)', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-container:has(> pb-image) > *', 'states' => []], $css->parseSelector('#band > pb-container:has(> pb-image) > *', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text:has(.text-block-link)', 'states' => []], $css->parseSelector('#band > pb-text:has(.text-block-link)', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text .text-block-link ~ .text-block-link', 'states' => []], $css->parseSelector('#band > pb-text .text-block-link ~ .text-block-link', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text:has(a):not(pb-image)', 'states' => []], $css->parseSelector('#band > pb-text:not(pb-image):has(a)', NULL, $known, $errors), 'canonical order; the negated type is judged at the same position');
    $this->assertSame(['id' => 'band', 'local' => '', 'states' => ['not-last-child']], $css->parseSelector('#band:not(:last-child)', NULL, $known, $errors));
    $this->assertSame([], array_map('strval', $errors));
    // The generated boxes. The pseudo-element peels first (so "after"
    // never reads as a state), the element's own box is the anchor's
    // local, a chain's box rides its last compound, and the states go
    // before the box as CSS spells them; a part's box is the part's.
    $errors = [];
    $this->assertSame(['id' => 'band', 'local' => '::&::after', 'states' => []], $css->parseSelector('#band::after', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::&::before', 'states' => ['hover']], $css->parseSelector('#band:hover::before', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text::after', 'states' => []], $css->parseSelector('#band > pb-text::after', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text::before', 'states' => ['hover', 'first-child']], $css->parseSelector('#band > pb-text:hover:first-child::before', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text:has(a)::after', 'states' => ['hover']], $css->parseSelector('#band > pb-text:has(a):hover::after', NULL, $known, $errors));
    $this->assertSame(['id' => 'band', 'local' => '::> pb-text .text-block-link::before', 'states' => []], $css->parseSelector('#band > pb-text .text-block-link::before', NULL, $known, $errors));
    $this->assertSame(['id' => 'copy', 'local' => '::.s-a1::after', 'states' => []], $css->parseSelector('#copy .s-a1::after', NULL, $known, $errors));
    $this->assertSame(['id' => 'plain', 'local' => '::&::before', 'states' => ['hover']], $css->parseSelector('#plain:hover::before', NULL, $known, $errors), 'A block has no children to address, but every element has its own boxes.');
    $this->assertSame([], array_map('strval', $errors));
    // Only the two boxes exist; the corrective names them.
    $errors = [];
    $this->assertNull($css->parseSelector('#band::marker', NULL, $known, $errors));
    $this->assertSame('BAD_SELECTOR', $errors[0]->code);
    $this->assertStringContainsString('::marker is not an addressable pseudo-element; the generated boxes are ::before and ::after (#band::before, #band::after)', $errors[0]->message);
    $this->assertSame(['before', 'after'], $errors[0]->context['pseudo_elements']);
    // The box is terminal and one per selector: a state after it, a
    // compound after it, and a second box are refused with the order.
    foreach (['#band::after:hover', '#band::before .text-block-link', '#band::before::after', '#band > pb-text::after > *'] as $selector) {
      $errors = [];
      $this->assertNull($css->parseSelector($selector, NULL, $known, $errors), $selector);
      $this->assertSame('BAD_SELECTOR', $errors[0]->code, $selector);
      $this->assertStringContainsString('a pseudo-element is terminal and one per selector; the states and position facts go before it (#band:hover::', $errors[0]->message, $selector);
    }
    // A "::" behind a space is every descendant's box in CSS: refused
    // with the glued spelling.
    $errors = [];
    $this->assertNull($css->parseSelector('#band ::after', NULL, $known, $errors));
    $this->assertSame('BAD_SELECTOR', $errors[0]->code);
    $this->assertStringContainsString('a pseudo-element is glued to the compound whose box it is (#band::after', $errors[0]->message);
    // Inside a qualifier the pseudo-element is the relative grammar's
    // refusal; the compound before a box is still judged.
    $errors = [];
    $this->assertNull($css->parseSelector('#band > pb-text:has(a::before)', NULL, $known, $errors));
    $this->assertSame('BAD_SELECTOR', $errors[0]->code);
    $this->assertStringContainsString('is not a relative selector the grammar accepts', $errors[0]->message);
    $errors = [];
    $this->assertNull($css->parseSelector('#band > pb-ghost::after', NULL, $known, $errors));
    $this->assertSame('UNKNOWN_TYPE', $errors[0]->code);
    // A leading sibling combinator addresses the owner's siblings, outside
    // its subtree: refused with the spelling from the parent.
    foreach (['#band + p', '#band ~ p', '#band+p'] as $selector) {
      $errors = [];
      $this->assertNull($css->parseSelector($selector, NULL, $known, $errors), $selector);
      $this->assertSame('BAD_SELECTOR', $errors[0]->code, $selector);
      $this->assertStringContainsString('#band\'s siblings are outside it', $errors[0]->message, $selector);
      $this->assertStringContainsString('#parent > pb-text + pb-text', $errors[0]->message, $selector);
    }
    // A qualifier on #id itself is a condition with a parameter, which no
    // condition is: refused naming the compound it narrows instead.
    foreach (['#band:has(a)', '#band:not(pb-image)', '#band:has(> pb-image)', '#band:not(.text-block-link)', '#band:has(a) > pb-text'] as $selector) {
      $errors = [];
      $this->assertNull($css->parseSelector($selector, NULL, $known, $errors), $selector);
      $this->assertSame('BAD_SELECTOR', $errors[0]->code, $selector);
      $this->assertStringContainsString('never #band itself', $errors[0]->message, $selector);
    }
    // States and facts come last: a state before a qualifier is outside
    // the grammar.
    $errors = [];
    $this->assertNull($css->parseSelector('#band > pb-text:hover:has(a)', NULL, $known, $errors));
    $this->assertSame('BAD_SELECTOR', $errors[0]->code);
    $this->assertStringContainsString('after them, the published states', $errors[0]->message);
    // A :not() of an unpublished pseudo-class is no fact.
    $errors = [];
    $this->assertNull($css->parseSelector('#band > *:not(:hover)', NULL, $known, $errors));
    $this->assertSame('UNKNOWN_STATE', $errors[0]->code);
    // The vocabulary inside an argument: a :has() part resolves against
    // the type it narrows, a :not() class where the narrowed compound
    // stands (the container's parts, never the type's), a type inside
    // :has() on a block type has no children to name.
    $errors = [];
    $this->assertNull($css->parseSelector('#band > pb-image:has(.text-block-link)', NULL, $known, $errors));
    $this->assertSame('UNKNOWN_PART', $errors[0]->code);
    $this->assertStringContainsString('not a part of <pb-image>', $errors[0]->message);
    $errors = [];
    $this->assertNull($css->parseSelector('#band > pb-text:not(.text-block-link)', NULL, $known, $errors));
    $this->assertSame('UNKNOWN_PART', $errors[0]->code);
    $this->assertStringContainsString('not a part of <pb-container>', $errors[0]->message);
    $errors = [];
    $this->assertNull($css->parseSelector('#band > pb-text:has(> pb-image)', NULL, $known, $errors));
    $this->assertSame('BAD_SELECTOR', $errors[0]->code);
    $this->assertStringContainsString('pb-text is a block, so no type follows it', $errors[0]->message);
    $errors = [];
    $this->assertNull($css->parseSelector('#band > pb-container:has(pb-ghost)', NULL, $known, $errors));
    $this->assertSame('UNKNOWN_TYPE', $errors[0]->code);
    $errors = [];
    $this->assertNull($css->parseSelector('#band > *:not(pb-ghost)', NULL, $known, $errors));
    $this->assertSame('UNKNOWN_TYPE', $errors[0]->code);

    // With no declared namespaces the pre-v0.5 correctives hold: the
    // vocabulary grows by data, never by promise.
    $bare = new DialectCssParser(static::bareCatalog());
    $errors = [];
    $this->assertNull($bare->parseSelector('#copy p', NULL, ['copy' => $known['copy']], $errors));
    $this->assertSame('BAD_SELECTOR', $errors[0]->code);
    $this->assertStringContainsString('descendant elements are not addressable', $errors[0]->message);
    $errors = [];
    $this->assertNull($bare->parseSelector('#copy div span', NULL, ['copy' => $known['copy']], $errors));
    $this->assertStringContainsString('descendant elements are not addressable', $errors[0]->message);
  }

  /**
   * A relation rule prints back as the line that was written.
   *
   * The done-when of the dialect's relations: the key carries the
   * qualifiers, the condition carries the state or the negated fact, and
   * the printer spells the two back in the parser's order (qualifiers on
   * the compound, states last).
   */
  public function testRelationsPrintBack(): void {
    $document = <<<HTML
<pb-container id="band" width="site">
  <pb-text id="a" tag="p">One</pb-text>
  <pb-image id="pic" media="Sunrise" alt="Sunrise" aspect-ratio="4:3" />
</pb-container>
<style>
  #band > pb-text:has(a):hover { color: red; }
  #band > *:not(pb-image):not(:last-child) { margin-bottom: 0; }
  #band > pb-text:not(:has(a)) { color: blue; }
  #band > p + p { margin-top: 0; }
  #band::after { content: ""; }
  #band:hover::before { content: ""; background-color: var(--color-accent); }
  #band > pb-text:first-child::after { content: none; }
</style>
HTML;
    $result = $this->parser->parseDocument($document, $this->catalog);
    $this->assertTrue($result->isValid(), implode('; ', array_map('strval', $result->errors)));
    $styles = $result->tree[0]['styles'];
    $this->assertSame(['color' => 'red'], $styles['::> pb-text:has(a)']['hover']);
    $this->assertSame(['margin-bottom' => '0'], $styles['::> *:not(pb-image)']['not-last-child']);
    $this->assertSame(['color' => 'blue'], $styles['::> pb-text:not(:has(a))']['default']);
    $this->assertSame(['margin-top' => '0'], $styles['::> p + p']['default']);
    // A generated box is a rule at the box's key: the anchor's for the
    // element's own, the chain's with the pseudo glued for a child's; the
    // state is the entry's condition, never selector text.
    $this->assertSame(['content' => '""'], $styles['::&::after']['default']);
    $this->assertSame(['content' => '""', 'background-color' => 'var(--color-accent)'], $styles['::&::before']['hover']);
    $this->assertSame(['content' => 'none'], $styles['::> pb-text::after']['first-child']);
    $print = $this->printer->print($result->tree, $this->catalog)->text;
    $this->assertStringContainsString('#band > pb-text:has(a):hover { color: red; }', $print);
    $this->assertStringContainsString('#band > *:not(pb-image):not(:last-child) { margin-bottom: 0; }', $print);
    $this->assertStringContainsString('#band > pb-text:not(:has(a)) { color: blue; }', $print);
    $this->assertStringContainsString('#band > p + p { margin-top: 0; }', $print);
    $this->assertStringContainsString('#band::after { content: ""; }', $print);
    $this->assertStringContainsString('#band:hover::before { content: ""; background-color: var(--color-accent); }', $print);
    $this->assertStringContainsString('#band > pb-text:first-child::after { content: none; }', $print);
  }

  /**
   * A rule wears variants as declarations, the attribute's twin.
   *
   * A `::` local's references print inside the rule's plain block before
   * its properties: an axis variant as `{axis}: {value}` (in reference
   * order), the style classes as one `class: a b`, through the catalog
   * lookups the element's attributes use; a wear-only rule prints its
   * declarations alone; the rule's other conditions print as they did.
   * The parser reads them back as the entry's reference list (axis wear
   * first, then class wear), so print(parse(x)) == x. Refused at parse:
   * wear on the element itself (the attributes' and the set op's
   * spelling), wear under a state or a wrapper, an unpublished class, and
   * a top-level-only axis (a rule's matches are never top-level); an axis
   * name whose value spells none of the axis's variants is CSS and lands
   * as a property.
   */
  public function testRuleWearPrintsAndParsesAsDeclarations(): void {
    $tree = [
      [
        'kind' => 'container',
        'layout_id' => 'daedalus_stylish_container',
        'uuid' => 'c0d89921-04a5-48d1-8720-d7508da565f6',
        'children' => [
          [
            'kind' => 'block',
            'uuid' => '66f39f31-8735-431e-9848-0db7e34e81ff',
            'block_type' => 'text',
            'block_fields' => ['field_text_tag' => 'p', 'field_text_text' => 'Hi'],
          ],
        ],
        'styles' => [
          '::> pb-text' => [
            'variants' => [['variant' => 'button', 'fragment' => ''], ['variant' => 'text_loud', 'fragment' => '']],
            'default' => ['color' => 'red'],
            'hover' => ['color' => 'blue'],
          ],
          '::h2' => ['variants' => [['variant' => 'lead', 'fragment' => '']]],
        ],
      ],
    ];
    $expected = <<<HTML
<pb-container id="c0d89921-04a5-48d1-8720-d7508da565f6">
  <pb-text id="66f39f31-8735-431e-9848-0db7e34e81ff" tag="p">Hi</pb-text>
</pb-container>
<style>
  #c0d89921-04a5-48d1-8720-d7508da565f6 > pb-text { tone: loud; class: button; color: red; }
  #c0d89921-04a5-48d1-8720-d7508da565f6 > pb-text:hover { color: blue; }
  #c0d89921-04a5-48d1-8720-d7508da565f6 h2 { class: lead; }
</style>

HTML;
    $print = $this->printer->print($tree, $this->catalog)->text;
    $this->assertSame($expected, $print);

    $reparse = $this->parser->parseDocument($print, $this->catalog);
    $this->assertSame([], array_map('strval', $reparse->errors));
    $styles = $reparse->tree[0]['styles'];
    $this->assertSame([['variant' => 'text_loud', 'fragment' => ''], ['variant' => 'button', 'fragment' => '']], $styles['::> pb-text']['variants'], 'Axis wear reads back first, then class wear.');
    $this->assertSame(['color' => 'red'], $styles['::> pb-text']['default']);
    $this->assertSame(['color' => 'blue'], $styles['::> pb-text']['hover']);
    $this->assertSame([['variant' => 'lead', 'fragment' => '']], $styles['::h2']['variants']);
    $this->assertSame($print, $this->printer->print($reparse->tree, $this->catalog)->text, 'print(parse(x)) == x');

    $codes = function (string $css): array {
      $document = "<pb-container id=\"band\"><pb-text id=\"t\" tag=\"p\">Hi</pb-text></pb-container>\n<style>$css</style>";
      $result = $this->parser->parseDocument($document, $this->catalog);
      return array_map(static fn ($error): string => $error->code, $result->errors);
    };
    $this->assertSame(['WEAR_ON_ELEMENT'], $codes('#t { class: button; }'), 'The element wears on its own tag, never in a rule.');
    $this->assertSame(['WEAR_ON_ELEMENT'], $codes('#band { width: full; }'), 'An axis on the element itself is its attribute.');
    $this->assertSame(['WEAR_UNDER_CONDITION'], $codes('#band > pb-text:hover { class: button; }'), 'A wear has no state.');
    $this->assertSame(['WEAR_UNDER_CONDITION'], $codes('@container (max-width: 767px) { #band > pb-text { tone: loud; } }'), 'A wear has no wrapper.');
    $this->assertSame(['AXIS_NOT_TOP_LEVEL'], $codes('#band > pb-container { width: full; }'), 'A top-level-only axis never wears at a rule.');
    $this->assertSame(['UNKNOWN_CLASS'], $codes('#band > pb-text { class: nope; }'), 'The class corrective is the element\'s.');

    // An axis name whose value is none of the axis's values is CSS.
    $result = $this->parser->parseDocument("<pb-container id=\"band\"></pb-container>\n<style>#band > pb-container { width: 100%; }</style>", $this->catalog);
    $this->assertSame([], array_map('strval', $result->errors));
    $this->assertSame(['width' => '100%'], $result->tree[0]['styles']['::> pb-container']['default']);
    $this->assertArrayNotHasKey('variants', $result->tree[0]['styles']['::> pb-container']);
  }

  /**
   * Nesting is sugar: nested rules flatten to the rules they mean.
   *
   * A nested prelude composes with the parent selector by CSS nesting's
   * relative rule (`&` the parent, a bare prelude relative to it, a
   * leading `:` the parent's own state), a nested `@wrapper` adds its
   * condition, and the declarations outside the nested blocks stay the
   * parent's; the assignments come out exactly as the flat document would
   * produce them, and the print is always flat.
   */
  public function testNestingFlattens(): void {
    $css = new DialectCssParser($this->catalog);
    $known = ['band' => ['container' => TRUE, 'block_type' => NULL, 'plugin_id' => NULL, 'body' => NULL]];
    $errors = [];
    $nested = $css->parseStyleBlock(<<<'CSS'
#band {
  margin: 0;
  > pb-text { color: red; &:hover { color: blue; } }
  :hover { padding: 1rem; }
  & > * { gap: 1rem; }
  @container (max-width: 767px) { margin: 1rem; p { color: green; } }
}
CSS, 1, $known, $errors);
    $this->assertSame([], array_map('strval', $errors));
    $flat = $css->parseStyleBlock(<<<'CSS'
#band { margin: 0; }
#band > pb-text { color: red; }
#band > pb-text:hover { color: blue; }
#band:hover { padding: 1rem; }
#band > * { gap: 1rem; }
@container (max-width: 767px) { #band { margin: 1rem; } #band p { color: green; } }
CSS, 1, $known, $errors);
    $this->assertSame($flat, $nested);
    $this->assertSame(['band', '::> pb-text', 'hover', ['color' => 'blue']], $nested[2]);
    $this->assertSame(['band', '::p', 'narrow', ['color' => 'green']], $nested[6]);
  }

  /**
   * The spec catalog with no instance namespaces declared.
   */
  /**
   * The selector rosters the fixture's styling layer would contribute.
   *
   * Every owner (the container, each block type given, any other block)
   * carries the base section's tags; the parts are the given owners'.
   *
   * @param array<string, array<string, string>> $parts
   *   Block type owner (dialect tag) => its parts (selector => label), in
   *   the catalog's block type order.
   *
   * @return array<string, array{parts: array<string, string>, tags: string[]}>
   *   The lane, in the subscriber's order.
   */
  protected static function selectorRosters(array $parts): array {
    $tags = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a', 'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'img'];
    $rosters = [];
    foreach (['pb-container', ...array_keys($parts), 'pb-block'] as $owner) {
      $rosters[$owner] = ['parts' => $parts[$owner] ?? [], 'tags' => $tags];
    }
    return $rosters;
  }

  protected static function bareCatalog(): DialectCatalog {
    return new DialectCatalog(
      containerLayoutId: 'daedalus_stylish_container',
      axes: [],
      blockTypes: [],
      conditions: ['hover' => ':hover'],
    );
  }

  /**
   * Material misses are structured and corrected against the right kind.
   *
   * Every material a document names resolves through the catalog's value
   * grammar over the roster, so a miss answers like a spine error: the
   * message names the miss, the context carries the reference and the
   * roster's ranked candidates, and the hint is the roster's one
   * did-you-mean. Kind-aware: the swatch typo is corrected toward the
   * swatch (the pre-roster parser offered skin tokens only), a class typo
   * toward the style variants, an axis value toward that axis, a blueprint
   * name toward the blueprints.
   */
  public function testMaterialMissesAreStructuredAndKindAware(): void {
    $result = $this->parser->parseDocument('<pb-container id="c" style="background: var(--swatch-limegreen)"></pb-container>', $this->catalog);
    $this->assertSame(['UNKNOWN_TOKEN'], array_map(static fn ($e) => $e->code, $result->errors));
    $error = $result->errors[0];
    $this->assertStringContainsString('var(--swatch-limegreen)', $error->message);
    $this->assertSame('var(--swatch-limegreen)', $error->context['reference']);
    $this->assertSame([['kind' => 'swatch', 'id' => 'lime_green', 'spelling' => 'var(--swatch-lime_green)']], $error->context['candidates']);
    $this->assertSame('Did you mean this swatch: lime_green "Lime green" (var(--swatch-lime_green))?', $error->hint);

    $result = $this->parser->parseDocument('<pb-container id="c" style="background: var(--color-bg-dark)"></pb-container>', $this->catalog);
    $this->assertSame('color/bg', $result->errors[0]->context['candidates'][0]['id'], 'A skin token typo still corrects toward the nearest token.');

    $result = $this->parser->parseDocument('<pb-container id="c" class="buton"></pb-container>', $this->catalog);
    $this->assertSame('UNKNOWN_CLASS', $result->errors[0]->code);
    $this->assertSame(['band'], $result->errors[0]->context['declared'], 'The element\'s own roster rides the context.');
    $this->assertSame('button', $result->errors[0]->context['candidates'][0]['id']);
    $this->assertStringStartsWith('Did you mean this variant: button "Button" (class="button")', $result->errors[0]->hint);
    $this->assertStringContainsString('<pb-container> declares: band', $result->errors[0]->hint);

    $result = $this->parser->parseDocument('<pb-container id="c" width="ful"></pb-container>', $this->catalog);
    $this->assertSame('BAD_ATTR_VALUE', $result->errors[0]->code);
    $this->assertSame(['full', 'site', '2xl', 'xl', 'lg', 'md', 'sm'], $result->errors[0]->context['allowed']);
    $this->assertSame('container_full', $result->errors[0]->context['candidates'][0]['id']);

    $result = $this->parser->parseFragment('<pb-blueprint name="hero-band" />', $this->catalog);
    $this->assertSame(['UNKNOWN_BLUEPRINT'], array_map(static fn ($e) => $e->code, $result->errors));
    $this->assertSame(['hero', 'pull_quote', 'full_bleed'], $result->errors[0]->context['available']);
    $this->assertSame('Did you mean this blueprint: hero "Hero" (<pb-blueprint name="hero">): A single-column hero band.?', $result->errors[0]->hint);

    // A miss with nothing close falls back to the roster teaching.
    $result = $this->parser->parseDocument('<pb-container id="c" style="color: var(--zzz)"></pb-container>', $this->catalog);
    $this->assertSame([], $result->errors[0]->context['candidates']);
    $this->assertStringContainsString('MATERIALS section', $result->errors[0]->hint);

    // Structured facts ride the op door unchanged (the two doors' one shape).
    $errors = $this->container->get('daedalus_blueprint.dom_operation_handler')->validateBatch([
      ['op' => 'set', 'ref' => 'c', 'attrs' => ['style' => 'background: var(--swatch-limegreen)']],
    ], $this->catalog);
    $this->assertSame('lime_green', $errors[0]->context['candidates'][0]['id']);
  }

  /**
   * The spec catalog with a closed stylable roster (a strict site).
   *
   * One roster per kind at every placement depth: containers style the
   * whole padding family, block-axis margins, gap and color; blocks
   * style spacing and typography. The rosters are the styling layer's
   * vocabulary rosters, hand-built here the way a live site builds them
   * from its declarations (VocabularyRosterBuilder).
   */
  protected static function grantedCatalog(): DialectCatalog {
    return new DialectCatalog(
      containerLayoutId: 'daedalus_stylish_container',
      axes: [],
      blockTypes: [
        'text' => [
          'attributes' => ['tag' => ['field' => 'field_text_tag', 'values' => ['h2', 'p']]],
          'body' => ['field' => 'field_text_text', 'kind' => 'plain'],
        ],
      ],
      conditions: ['hover' => ':hover', 'narrow' => '@container (max-width: 767px)'],
      selectors: static::selectorRosters(['pb-text' => ['.text-block-link' => 'Link']]),
      stylable: [
        'container' => new VocabularyRoster(Address::kind('container'), 'spec', [
          'spacing' => [
            'padding',
            'padding-top',
            'padding-bottom',
            'padding-left',
            'padding-right',
            'padding-inline',
            'margin',
            'margin-top',
            'margin-bottom',
            'gap',
          ],
          'color' => ['background-color', 'color'],
        ]),
        'block' => new VocabularyRoster(Address::kind('block'), 'spec', [
          'spacing' => ['padding', 'padding-top', 'margin'],
          'typography' => ['font-size', 'font-weight'],
          'color' => ['color'],
        ]),
      ],
    );
  }

  /**
   * The parser judges grammar only; the grant is the validator's.
   *
   * A property outside an element's vocabulary roster is refused by the
   * semantic validator when the parsed styles land as update_style ops
   * (STYLE_PROPERTY_NOT_DECLARED with the roster's own corrective), never
   * at parse, so the catalog's rosters exist for the prompt and the
   * validator, and a document with ungranted properties parses clean here
   * at every depth and on every door. A declaration the grammar DID
   * refuse stays out of the parsed map (the tree is best-effort under
   * errors and the semantic pass over it must not judge a value twice),
   * while the grammatical ones ride.
   */
  public function testGrantIsNotJudgedAtParse(): void {
    $catalog = static::grantedCatalog();
    $document = <<<'HTML'
<pb-container id="band" style="margin-inline: auto; padding: 4rem 0">
  <pb-container id="card" style="padding: 1rem; margin-inline: auto">
    <pb-text id="t" tag="h2" style="font-size: 2rem; text-decoration: underline">Hi</pb-text>
  </pb-container>
</pb-container>
<style>
  #band:hover { margin-inline: 0; }
  @container (max-width: 767px) { #card { padding: 1rem; } }
  #t .text-block-link { margin-inline: 0; }
</style>
HTML;
    $result = $this->parser->parseDocument($document, $catalog);
    $this->assertSame([], $result->errors, print_r(array_map('strval', $result->errors), TRUE));
    $this->assertSame(['margin-inline' => 'auto', 'padding' => '4rem 0'], $result->tree[0]['styles']['']['default']);
    $this->assertSame(['margin-inline' => '0'], $result->tree[0]['styles']['']['hover']);

    $refused = '<pb-container id="c" style="margin-inline: auto"></pb-container>';
    foreach ([FALSE, TRUE] as $top_level) {
      $this->assertSame([], $this->parser->parseFragment($refused, $catalog, $top_level)->errors);
    }

    // A grammar miss leaves the map without that declaration and the
    // grammatical neighbor in it.
    $errors = [];
    $css = new DialectCssParser($catalog);
    $properties = $css->parseDeclarations('padding: calc(1rem + 2px); margin: 1rem', NULL, 'test', $errors);
    $this->assertSame(['UNSUPPORTED_VALUE'], array_map(static fn ($e) => $e->code, $errors));
    $this->assertSame(['margin' => '1rem'], $properties);

    // The rosters the prompt and the validator read are the catalog's.
    $this->assertSame(['container', 'block'], array_keys($catalog->vocabularies()));
    $this->assertContains('padding', $catalog->vocabularies()['block']->granted());
    $this->assertSame([], $this->catalog->vocabularies());
    $this->assertNull($this->catalog->vocabularyProse(), 'The spec catalog carries no roster and no prose.');
  }

  /**
   * A math function's corrective names the width-band lane that exists.
   *
   * Clamp()/calc() stay refused (the unsupported-value rule above), and
   * the corrective says what to write instead, because a model reaches
   * for clamp() as its only idea of a headline that fits a phone and a
   * bare refusal leaves it no reachable answer. The lane is one value per
   * width band: the wide value in
   * style="" and the narrow value in a rule under the narrowest published
   * band, in the page document's <style> block or the style op. The
   * formatter is the catalog's (one corrective for every door that reads
   * it); a catalog without width bands names the generic per-condition
   * form; the context carries the bands so a tool can print them.
   */
  public function testUnsupportedValueHintNamesTheWidthBand(): void {
    $result = $this->parser->parseDocument('<pb-container id="c"><pb-text id="t" tag="h2" style="font-size: clamp(2.25rem, 5vw, 3.75rem)">Big</pb-text></pb-container>', $this->catalog);
    $this->assertCount(1, $result->errors, print_r(array_map('strval', $result->errors), TRUE));
    $error = $result->errors[0];
    $this->assertSame('UNSUPPORTED_VALUE', $error->code);
    $this->assertSame('clamp() in font-size: use a token or a single length; clamp()/calc() are not supported', $error->message);
    $this->assertSame([
      'property' => 'font-size',
      'function' => 'clamp',
      'width_bands' => [
        'narrow' => '@container (max-width: 767px)',
        'medium' => '@container (min-width: 768px) and (max-width: 1023px)',
      ],
    ], $error->context);
    $this->assertSame('Responsive values are one value per width band, not math: write the wide value in style="" (the default) and the narrow value in a narrow rule, @container (max-width: 767px) { #id { font-size: ... } }, in the page document\'s <style> block or the style op.', $error->hint);
    $this->assertSame($this->catalog->unsupportedValueHint('font-size'), $error->hint, 'The parser reads the catalog\'s one formatter.');

    // The same corrective on the op door (a style op's calc()).
    $ops = $this->container->get('daedalus_blueprint.dom_operation_handler')->validateBatch([
      ['op' => 'style', 'css' => '#hero { padding-top: calc(2rem + 5px); }'],
    ], $this->catalog);
    $this->assertSame(['UNSUPPORTED_VALUE'], array_map(static fn ($e) => $e->code, $ops));
    $this->assertSame($this->catalog->unsupportedValueHint('padding-top'), $ops[0]->hint);

    // No width bands published: the generic per-condition form, and the
    // context carries an empty band map.
    $bare = $this->parser->parseDocument('<pb-container id="c" style="padding-top: calc(1rem + 2px)"></pb-container>', static::bareCatalog());
    $this->assertCount(1, $bare->errors);
    $this->assertSame('UNSUPPORTED_VALUE', $bare->errors[0]->code);
    $this->assertSame([], $bare->errors[0]->context['width_bands']);
    $this->assertSame('Write one value per condition, not math: the base value in style="" and an adapted value in a rule under a published wrapper (the page document\'s <style> block, or the style op).', $bare->errors[0]->hint);
  }

  /**
   * Placement provenance prints on the root and parses as identity.
   *
   * The serializer surfaces the stamp place_blueprint wrote as the
   * node's read-only `blueprint` key; the print carries it as blueprint="id"
   * right after the identity (containers and blocks alike, any block
   * form) so the model sees what a band IS, and the parse takes it back
   * as an identity fact on the node (the expansion stamps the root create
   * op with it, never re-instantiates). A stamp naming no rostered
   * blueprint is residue: it never prints, and written by hand it is
   * refused with the roster's blueprints; a stamp on a block is refused as a
   * mismatch (a blueprint places a container, never a block).
   */
  public function testProvenancePrintsAndParsesAsIdentity(): void {
    $tree = [
      [
        'kind' => 'container',
        'layout_id' => 'daedalus_stylish_container',
        'uuid' => 'c0d89921-04a5-48d1-8720-d7508da565f6',
        'blueprint' => 'hero',
        'children' => [
          [
            'kind' => 'container',
            'layout_id' => 'daedalus_stylish_container',
            'uuid' => 'd4e5f6a7-3c4d-4e5f-8a9b-2c3d4e5f6a7b',
            'blueprint' => 'pull_quote',
            'children' => [
              [
                'kind' => 'block',
                'uuid' => '66f39f31-8735-431e-9848-0db7e34e81ff',
                'block_type' => 'text',
                'block_fields' => ['field_text_tag' => 'p', 'field_text_text' => 'Said well.'],
                'styles' => ['' => ['variants' => [['variant' => 'button', 'fragment' => '']]]],
              ],
            ],
          ],
          [
            'kind' => 'block',
            'uuid' => 'e12f04b1-289f-4a33-a7f9-d506411a67f7',
            'block_type' => 'quote',
            'block_fields' => ['field_quote_text' => 'Generic form.'],
          ],
          [
            'kind' => 'block',
            'uuid' => 'a7c1d6e2-1b2c-4d3e-8f90-0a1b2c3d4e5f',
            'plugin_id' => 'system_branding_block',
          ],
          [
            'kind' => 'block',
            'uuid' => 'b8d2e7f3-2c3d-4e5f-9a01-1b2c3d4e5f60',
            'field' => 'body',
            'label' => 'Body',
            'configuration' => ['formatter' => ['type' => 'text_default', 'label' => 'hidden']],
          ],
          [
            'kind' => 'block',
            'uuid' => 'f0e1d2c3-b4a5-4968-8776-655443322110',
            'block_type' => 'text',
            'blueprint' => 'retired',
            'block_fields' => ['field_text_text' => 'Dead stamp.'],
          ],
        ],
        'styles' => ['' => ['variants' => [['variant' => 'container_site', 'fragment' => '']]]],
      ],
    ];

    // A field block prints the field it names, never a per-bundle plugin
    // id; its formatter configuration has no dialect spelling and does not
    // print (the parse of the print carries the field alone).
    $expected = <<<HTML
<pb-container id="c0d89921-04a5-48d1-8720-d7508da565f6" blueprint="hero" width="site">
  <pb-container id="d4e5f6a7-3c4d-4e5f-8a9b-2c3d4e5f6a7b" blueprint="pull_quote">
    <pb-text id="66f39f31-8735-431e-9848-0db7e34e81ff" class="button" tag="p">Said well.</pb-text>
  </pb-container>
  <pb-block id="e12f04b1-289f-4a33-a7f9-d506411a67f7" type="quote">
    <pb-field name="field_quote_text">Generic form.</pb-field>
  </pb-block>
  <pb-block id="a7c1d6e2-1b2c-4d3e-8f90-0a1b2c3d4e5f" plugin="system_branding_block" />
  <pb-block id="b8d2e7f3-2c3d-4e5f-9a01-1b2c3d4e5f60" field="body" />
  <pb-text id="f0e1d2c3-b4a5-4968-8776-655443322110">Dead stamp.</pb-text>
</pb-container>

HTML;
    $print = $this->printer->print($tree, $this->catalog)->text;
    $this->assertSame($expected, $print);

    $reparse = $this->parser->parseDocument($print, $this->catalog);
    $this->assertSame([], array_map('strval', $reparse->errors));
    $this->assertSame($print, $this->printer->print($reparse->tree, $this->catalog)->text, 'print(parse(x)) == x');
    $container = $reparse->tree[0];
    $this->assertSame('hero', $container['blueprint']);
    $this->assertSame('pull_quote', $container['children'][0]['blueprint'], 'A nested placement carries its own stamp.');
    $this->assertSame([NULL, NULL, NULL, NULL], array_map(static fn (array $node): ?string => $node['blueprint'] ?? NULL, array_slice($container['children'], 1)), 'Blocks carry no stamp: a blueprint places a container.');
    $this->assertArrayNotHasKey('blueprint', $container['children'][4], 'A dead stamp never printed, so the reparse carries none.');
    $this->assertSame([['variant' => 'button', 'fragment' => '']], $container['children'][0]['children'][0]['styles']['']['variants'], 'Provenance on the placement sits beside the wear of what it holds.');

    // The rejects are structured like every material miss.
    $result = $this->parser->parseDocument('<pb-container id="c" blueprint="hero-band"></pb-container>', $this->catalog);
    $this->assertSame(['UNKNOWN_BLUEPRINT'], array_map(static fn ($e) => $e->code, $result->errors));
    $this->assertSame('blueprint', $result->errors[0]->context['attribute']);
    $this->assertSame(['hero', 'pull_quote', 'full_bleed'], $result->errors[0]->context['available']);
    $this->assertSame('hero', $result->errors[0]->context['candidates'][0]['id']);
    $this->assertStringStartsWith('Did you mean this blueprint: hero "Hero"', $result->errors[0]->hint, 'A near miss corrects toward the blueprint, like every material miss.');
    $result = $this->parser->parseDocument('<pb-container id="c" blueprint="zzz"></pb-container>', $this->catalog);
    $this->assertSame([], $result->errors[0]->context['candidates']);
    $this->assertStringContainsString('placement provenance', $result->errors[0]->hint, 'Nothing close: the hint teaches what the attribute is and lists the blueprints.');
    $this->assertStringContainsString('pull_quote "Pull quote"', $result->errors[0]->hint);

    $result = $this->parser->parseDocument('<pb-container id="c"><pb-text id="t" blueprint="hero">Hi</pb-text></pb-container>', $this->catalog);
    $this->assertSame(['BLUEPRINT_MISMATCH'], array_map(static fn ($e) => $e->code, $result->errors));
    $this->assertSame(['attribute' => 'blueprint', 'blueprint' => 'hero', 'element' => 'pb-text'], $result->errors[0]->context);
    $this->assertSame('blueprint="hero" on <pb-text>: a blueprint places a container, never a block', $result->errors[0]->message);
    $this->assertStringContainsString('<pb-blueprint name="hero" />', $result->errors[0]->hint);
    $this->assertSame(1, $result->errors[0]->line);

    // The fragment door speaks the same grammar, and the reference element
    // refuses the attribute with its own legal list.
    $result = $this->parser->parseFragment('<pb-container id="q" blueprint="pull_quote"><pb-text id="t">Said well.</pb-text></pb-container>', $this->catalog);
    $this->assertTrue($result->isValid(), implode(' | ', array_map('strval', $result->errors)));
    $this->assertSame('pull_quote', $result->tree[0]['blueprint']);
    $this->assertSame(['BLUEPRINT_MISMATCH'], array_map(static fn ($e) => $e->code, $this->parser->parseFragment('<pb-text id="q" blueprint="pull_quote">Said well.</pb-text>', $this->catalog)->errors), 'The fragment door refuses a stamp on a block too.');
    $result = $this->parser->parseFragment('<pb-blueprint name="hero" blueprint="hero" />', $this->catalog);
    $this->assertSame(['ILLEGAL_ATTR'], array_map(static fn ($e) => $e->code, $result->errors));
    $this->assertSame(['id', 'name'], $result->errors[0]->context['legal']);
  }

  /**
   * A container's name prints and parses on the section's label slot.
   *
   * The element-naming spine's one renamable word reaches the AI door: a
   * section carrying a label prints name="..." after its provenance and
   * before its axes, the parser lands it back on the node's label key
   * (the serializer's, so a stored blueprint tree and a parsed tree share
   * one shape) and the round trip is byte-stable, escaping included. The
   * attribute is the container's alone: a block's name is derived from
   * its content, so name on a block tag is ILLEGAL_ATTR with the block's
   * own legal roster, and an empty name is no name.
   */
  public function testContainerNamePrintsAndParses(): void {
    $tree = [
      [
        'kind' => 'container',
        'layout_id' => 'daedalus_stylish_container',
        'uuid' => 'c0d89921-04a5-48d1-8720-d7508da565f6',
        'label' => 'Welcome & "hello"',
        'blueprint' => 'hero',
        'children' => [
          [
            'kind' => 'container',
            'layout_id' => 'daedalus_stylish_container',
            'uuid' => 'd1e2f3a4-b5c6-4d7e-8f90-a1b2c3d4e5f6',
            'label' => 'Pricing',
            'children' => [],
          ],
          [
            'kind' => 'container',
            'layout_id' => 'daedalus_stylish_container',
            'uuid' => 'e2f3a4b5-c6d7-4e8f-9a01-b2c3d4e5f6a7',
            'children' => [],
          ],
          [
            'kind' => 'block',
            'uuid' => '66f39f31-8735-431e-9848-0db7e34e81ff',
            'block_type' => 'text',
            'label' => 'Front page title',
            'block_fields' => ['field_text_tag' => 'h2', 'field_text_text' => 'Hi'],
          ],
        ],
        'styles' => ['' => ['variants' => [['variant' => 'container_lg', 'fragment' => '']]]],
      ],
    ];
    $expected = <<<HTML
<pb-container id="c0d89921-04a5-48d1-8720-d7508da565f6" blueprint="hero" name="Welcome &amp; &quot;hello&quot;" width="lg">
  <pb-container id="d1e2f3a4-b5c6-4d7e-8f90-a1b2c3d4e5f6" name="Pricing">
  </pb-container>
  <pb-container id="e2f3a4b5-c6d7-4e8f-9a01-b2c3d4e5f6a7">
  </pb-container>
  <pb-text id="66f39f31-8735-431e-9848-0db7e34e81ff" tag="h2">Hi</pb-text>
</pb-container>

HTML;
    $print = $this->printer->print($tree, $this->catalog)->text;
    $this->assertSame($expected, $print);

    $reparse = $this->parser->parseDocument($print, $this->catalog);
    $this->assertSame([], array_map('strval', $reparse->errors));
    $this->assertSame($print, $this->printer->print($reparse->tree, $this->catalog)->text, 'print(parse(x)) == x');
    $container = $reparse->tree[0];
    $this->assertSame('Welcome & "hello"', $container['label'], 'The name lands on the serializer\'s label key, unescaped.');
    $this->assertSame('Pricing', $container['children'][0]['label'], 'Nested containers name the same way.');
    $this->assertArrayNotHasKey('label', $container['children'][1], 'An unnamed container carries no label.');
    $this->assertArrayNotHasKey('label', $container['children'][2], 'A block\'s label is residue: never printed, never parsed.');

    // The fragment door speaks the same grammar; an empty name is no name.
    $result = $this->parser->parseFragment('<pb-container id="band" name="Pricing"></pb-container><pb-container id="bare" name=""></pb-container>', $this->catalog);
    $this->assertTrue($result->isValid(), implode('; ', array_map('strval', $result->errors)));
    $this->assertSame('Pricing', $result->tree[0]['label']);
    $this->assertArrayNotHasKey('label', $result->tree[1]);

    // Container-only: a block's name is derived, so its tags refuse the
    // attribute with their own legal rosters, and the container's roster
    // names it.
    $result = $this->parser->parseDocument('<pb-container id="c"><pb-text id="t" name="Title">Hi</pb-text></pb-container>', $this->catalog);
    $this->assertSame(['ILLEGAL_ATTR'], array_map(static fn ($e) => $e->code, $result->errors));
    $this->assertSame('name is not an attribute of <pb-text>', $result->errors[0]->message);
    $this->assertNotContains('name', $result->errors[0]->context['legal']);
    $result = $this->parser->parseDocument('<pb-container id="c"><pb-block id="b" plugin="system_branding_block" name="Brand" /></pb-container>', $this->catalog);
    $this->assertSame(['ILLEGAL_ATTR'], array_map(static fn ($e) => $e->code, $result->errors));
    $this->assertSame('name is not an attribute of <pb-block>', $result->errors[0]->message);
    $result = $this->parser->parseDocument('<pb-container id="c" title="Hero"></pb-container>', $this->catalog);
    $this->assertSame(['ILLEGAL_ATTR'], array_map(static fn ($e) => $e->code, $result->errors));
    $this->assertSame(['blueprint', 'class', 'id', 'layout', 'name', 'style', 'width'], $result->errors[0]->context['legal']);
  }

  /**
   * The catalog records the dialect version (a public contract).
   *
   * Durable artifacts never freeze dialect text (blueprints store the lean
   * node tree), so the catalog is the one surface naming the grammar's
   * version; this pins that the contract exists and tracks the spec.
   */
  public function testCatalogRecordsDialectVersion(): void {
    $this->assertSame(DialectCatalog::DIALECT_VERSION, $this->catalog->version());
    $this->assertSame('0.8', $this->catalog->version(), 'The recorded version tracks the grammar (0.6: placement provenance as blueprint="" on the root, and variant axes as attributes on every element\'s tag, the block forms included; 0.7: the slot section <pb-slot name="id">, a top-level container filling a slot of the document around it; 0.8: the linked placement <pb-linked blueprint="id"/>, a blueprint shown by reference as one block).');
  }

  /**
   * Spec 0.6: block axes print and parse as attributes on the block's own tag.
   *
   * The container rule on every tagged scope: a block type's declared axis
   * rides its <pb-{type}> (and its generic form), the block kind's rides
   * every block form (<pb-block plugin>, <pb-block type>), in the
   * element's axis order (the kind's axes first, then the type's), before
   * class. The parse takes the attribute back as the variant reference,
   * refuses a value the axis does not offer with the axis's allowed
   * values and the roster's did-you-mean, and refuses the attribute on a
   * tag whose axis view lacks it.
   */
  public function testBlockAxesPrintAndParseOnTheirTag(): void {
    $tree = [
      [
        'kind' => 'container',
        'layout_id' => 'daedalus_stylish_container',
        'uuid' => 'c0d89921-04a5-48d1-8720-d7508da565f6',
        'children' => [
          [
            'kind' => 'block',
            'uuid' => '66f39f31-8735-431e-9848-0db7e34e81ff',
            'block_type' => 'text',
            'block_fields' => ['field_text_tag' => 'h2', 'field_text_text' => 'Hi'],
            'styles' => [
              '' => [
                'variants' => [
                  ['variant' => 'text_loud', 'fragment' => ''],
                  ['variant' => 'block_emphatic', 'fragment' => ''],
                  ['variant' => 'button', 'fragment' => ''],
                ],
                'default' => ['color' => 'red'],
              ],
            ],
          ],
          [
            'kind' => 'block',
            'uuid' => '0b4a2f0e-7d2c-4f9a-9c1e-2a6d8e5b3c7f',
            'plugin_id' => 'system_branding_block',
            'styles' => ['' => ['variants' => [['variant' => 'block_emphatic', 'fragment' => '']]]],
          ],
          [
            'kind' => 'block',
            'uuid' => '7f1e9c3a-5b2d-4e8f-a0c6-1d3b5f7a9e2c',
            'block_type' => 'quote',
            'block_fields' => ['field_quote_text' => 'Said well.'],
            'styles' => ['' => ['variants' => [['variant' => 'block_emphatic', 'fragment' => '']]]],
          ],
        ],
      ],
    ];
    $expected = <<<HTML
<pb-container id="c0d89921-04a5-48d1-8720-d7508da565f6">
  <pb-text id="66f39f31-8735-431e-9848-0db7e34e81ff" emphasis="emphatic" tone="loud" class="button" tag="h2" style="color: red">Hi</pb-text>
  <pb-block id="0b4a2f0e-7d2c-4f9a-9c1e-2a6d8e5b3c7f" plugin="system_branding_block" emphasis="emphatic" />
  <pb-block id="7f1e9c3a-5b2d-4e8f-a0c6-1d3b5f7a9e2c" type="quote" emphasis="emphatic">
    <pb-field name="field_quote_text">Said well.</pb-field>
  </pb-block>
</pb-container>

HTML;
    $print = $this->printer->print($tree, $this->catalog)->text;
    $this->assertSame($expected, $print);

    // The reparse carries the same references in the same order (axes in
    // the element's axis order, then class wear), byte-stable.
    $reparse = $this->parser->parseDocument($print, $this->catalog);
    $this->assertSame([], array_map('strval', $reparse->errors));
    $this->assertSame($print, $this->printer->print($reparse->tree, $this->catalog)->text, 'print(parse(x)) == x');
    $children = $reparse->tree[0]['children'];
    $this->assertSame([
      ['variant' => 'block_emphatic', 'fragment' => ''],
      ['variant' => 'text_loud', 'fragment' => ''],
      ['variant' => 'button', 'fragment' => ''],
    ], $children[0]['styles']['']['variants']);
    $this->assertSame([['variant' => 'block_emphatic', 'fragment' => '']], $children[1]['styles']['']['variants']);
    $this->assertSame([['variant' => 'block_emphatic', 'fragment' => '']], $children[2]['styles']['']['variants']);

    // The element's axis view: the kind's axes first, then the type's;
    // the container's view carries neither block axis.
    $this->assertSame(['emphasis', 'tone'], array_keys($this->catalog->axesFor(FALSE, 'text', NULL)));
    $this->assertSame(['emphasis'], array_keys($this->catalog->axesFor(FALSE, NULL, 'system_branding_block:derivative')));
    $this->assertSame(['emphasis'], array_keys($this->catalog->axesFor(FALSE, 'quote', NULL)));
    $this->assertSame(['width', 'layout'], array_keys($this->catalog->axesFor(TRUE, NULL, NULL)));

    // A miss corrects against the axis, on the block's tag.
    $result = $this->parser->parseDocument('<pb-container id="c"><pb-text id="t" tone="lowd">Hi</pb-text></pb-container>', $this->catalog);
    $this->assertSame(['BAD_ATTR_VALUE'], array_map(static fn ($e) => $e->code, $result->errors));
    $this->assertStringContainsString('tone="lowd" on <pb-text>', $result->errors[0]->message);
    $this->assertSame(['loud', 'quiet'], $result->errors[0]->context['allowed']);
    $this->assertSame('text_loud', $result->errors[0]->context['candidates'][0]['id']);
    $this->assertSame('Did you mean this variant: text_loud "Loud" (tone="loud"): Set the text loud.?', $result->errors[0]->hint);

    // The attribute is legal only where the axis view carries it.
    $result = $this->parser->parseDocument('<pb-container id="c"><pb-image alt="x" tone="loud" /></pb-container>', $this->catalog);
    $this->assertSame('ILLEGAL_ATTR', $result->errors[0]->code);
    $this->assertSame([
      'alt',
      'aspect-ratio',
      'blueprint',
      'class',
      'emphasis',
      'file',
      'generate',
      'id',
      'media',
      'style',
      'url',
    ], $result->errors[0]->context['legal']);
  }

  /**
   * Spec 0.6: an axis attribute that would mean two things fails the catalog.
   *
   * A block axis spelled as a field attribute of a tag it rides, or as a
   * reserved name (the global attributes, plugin/type/name, the set op's
   * body slot, data-*), is refused at construction with the scope, the
   * variants and the collision named: no print, parse or teaching may
   * quietly pick one meaning.
   */
  public function testAxisAttributeCollisionsFailTheCatalogBuild(): void {
    $collisions = [
      'a type axis on one of its field attributes' => [
        new MaterialRecord(kind: MaterialKind::Variant, id: 'text_h2', label: 'H2', group: 'tag', spellings: [MaterialRecord::attrSlot('block_type:text', 'tag') => 'h2'], scope: Address::fromString('block_type:text')),
        'collides with the tag attribute of <pb-text> (field field_text_tag)',
      ],
      'the block kind\'s axis on a field attribute of any declared type' => [
        new MaterialRecord(kind: MaterialKind::Variant, id: 'block_linked', label: 'Linked', group: 'url', spellings: [MaterialRecord::attrSlot('kind:block', 'url') => 'linked'], scope: Address::fromString('kind:block')),
        'collides with the url attribute of <pb-text> (field field_text_url)',
      ],
      'a reserved name' => [
        new MaterialRecord(kind: MaterialKind::Variant, id: 'block_classy', label: 'Classy', group: 'class', spellings: [MaterialRecord::attrSlot('kind:block', 'class') => 'classy'], scope: Address::fromString('kind:block')),
        'is a reserved attribute name',
      ],
      'the generic form\'s identity attribute' => [
        new MaterialRecord(kind: MaterialKind::Variant, id: 'text_typed', label: 'Typed', group: 'type', spellings: [MaterialRecord::attrSlot('block_type:text', 'type') => 'typed'], scope: Address::fromString('block_type:text')),
        'is a reserved attribute name',
      ],
      'a data-* spelling' => [
        new MaterialRecord(kind: MaterialKind::Variant, id: 'container_x', label: 'X', group: 'data-x', spellings: [MaterialRecord::attrSlot('kind:container', 'data-x') => 'x'], scope: Address::fromString('kind:container')),
        'is a reserved attribute name',
      ],
    ];
    $base = static::catalog();
    foreach ($collisions as $case => [$record, $message]) {
      try {
        new DialectCatalog(
          containerLayoutId: 'daedalus_stylish_container',
          axes: [],
          blockTypes: $base->blockTypes(),
          conditions: [],
          roster: new MaterialsRoster([$record], new CacheableMetadata()),
        );
        $this->fail("$case: the catalog built.");
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString($message, $e->getMessage(), $case);
        $this->assertStringContainsString($record->id, $e->getMessage(), $case);
      }
    }
  }

  /**
   * An axis carries its description and owned properties into the view.
   *
   * The declaration facts no variant record carries ride the catalog's
   * axes contribution beside the print order: what the axis means and
   * which properties its values write. The element's axis view stamps
   * both on the entry (NULL and [] for an undescribed axis, declared or
   * roster-only), so the brief, the schema and the warning that answers
   * an owned write all read one fact. Birth defaults and the skin's
   * dressed tags ride the same event: a contributing fixture declares
   * them and the catalog answers per placement scope and site-wide.
   */
  public function testAxisFactsBirthDefaultsAndDressedTagsRideTheCatalog(): void {
    $event = new DialectCatalogBuildEvent([], 'daedalus_stylish_container');
    $event->addAxes('kind:container', [
      'width' => [
        'description' => 'The band\'s content column: site for the shared column, full for a deliberately edge-to-edge band.',
        'owns' => ['padding-left', 'padding-right', 'max-width'],
      ],
      'layout' => [],
    ]);
    $event->addAxes('block_type:text', ['tone' => ['description' => 'How loud the text speaks.']]);
    $event->addBirthDefaults('layout:daedalus_stylish_container', ['width' => 'container_site']);
    $event->addBirthDefaults('block_type:text', ['tone' => 'text_quiet']);
    $event->addDressedTags(['h1', 'h2', 'p']);
    $event->addDressedTags(['p', 'a']);

    $catalog = new DialectCatalog(
      containerLayoutId: 'daedalus_stylish_container',
      axes: $event->getAxes(),
      blockTypes: static::catalog()->blockTypes(),
      conditions: [],
      roster: static::materials(),
      birthDefaults: $event->getBirthDefaults(),
      dressedTags: $event->getDressedTags(),
    );

    $container = $catalog->axesFor(TRUE, NULL, NULL);
    $this->assertSame(['width', 'layout'], array_keys($container));
    $this->assertStringStartsWith('The band\'s content column', $container['width']['description']);
    $this->assertSame(['padding-left', 'padding-right', 'max-width'], $container['width']['owns']);
    $this->assertSame(['full', 'site', '2xl', 'xl', 'lg', 'md', 'sm'], array_keys($container['width']['values']), 'The values still come from the roster.');
    $this->assertNull($container['layout']['description']);
    $this->assertSame([], $container['layout']['owns']);

    // A block's view merges the kind's roster-only axis (undescribed) with
    // the type's described one.
    $text = $catalog->axesFor(FALSE, 'text', NULL);
    $this->assertSame(['emphasis', 'tone'], array_keys($text));
    $this->assertNull($text['emphasis']['description']);
    $this->assertSame('How loud the text speaks.', $text['tone']['description']);
    $this->assertSame([], $text['tone']['owns']);

    // Birth defaults answer per placement scope, [] elsewhere; a part
    // address answers for its root.
    $this->assertSame(['width' => 'container_site'], $catalog->birthDefaults(Address::layout('daedalus_stylish_container')));
    $this->assertSame(['tone' => 'text_quiet'], $catalog->birthDefaults(Address::blockType('text')));
    $this->assertSame(['tone' => 'text_quiet'], $catalog->birthDefaults(Address::fromString('block_type:text::.text-block-link')));
    $this->assertSame([], $catalog->birthDefaults(Address::blockType('image')));
    $this->assertSame(['h1', 'h2', 'p', 'a'], $catalog->dressedTags());

    // A catalog with no contributions answers nothing.
    $this->assertSame([], $this->catalog->birthDefaults(Address::layout('daedalus_stylish_container')));
    $this->assertSame([], $this->catalog->dressedTags());
    $this->assertNull($this->catalog->axesFor(TRUE, NULL, NULL)['width']['description']);

    // The event refuses malformed facts loudly.
    foreach ([
      ['kind:container', ['width' => ['owns' => 'padding-left']]],
      ['kind:container', ['width' => ['description' => 7]]],
      ['kind:container', ['' => []]],
      ['kind:container::.part', ['width' => []]],
    ] as [$scope, $axes]) {
      try {
        (new DialectCatalogBuildEvent([], ''))->addAxes($scope, $axes);
        $this->fail(sprintf('Axes %s on %s were accepted.', json_encode($axes), $scope));
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
    try {
      (new DialectCatalogBuildEvent([], ''))->addBirthDefaults('block_type:text', ['tone' => '']);
      $this->fail('An empty variant id was accepted as a birth default.');
    }
    catch (\InvalidArgumentException) {
      $this->addToAssertionCount(1);
    }
  }

  /**
   * The site's group vocabulary contributed on the event rides the catalog.
   *
   * The groups are declared in a family the spine cannot read, so the
   * layer that parses it adds them on the build event, validated, and
   * the built catalog answers them in declaration order (the offered
   * view too); a second contribution of an id keeps the first. The
   * reserved bucket for the things in no group (DialectCatalog::UNGROUPED)
   * is never a declared id, and a malformed group is refused loudly.
   */
  public function testGroupsRideTheCatalog(): void {
    $event = new DialectCatalogBuildEvent([], 'daedalus_stylish_container');
    $event->addGroups([
      'heroes' => ['label' => ' Heroes ', 'description' => 'Full-width openers. '],
      'bands' => ['label' => 'Bands'],
    ]);
    $event->addGroups(['heroes' => ['label' => 'Second heroes'], 'cards' => ['label' => 'Cards', 'description' => NULL]]);
    $expected = [
      'heroes' => ['label' => 'Heroes', 'description' => 'Full-width openers.'],
      'bands' => ['label' => 'Bands', 'description' => ''],
      'cards' => ['label' => 'Cards', 'description' => ''],
    ];
    $this->assertSame($expected, $event->getGroups());

    $catalog = new DialectCatalog(
      containerLayoutId: 'daedalus_stylish_container',
      axes: $event->getAxes(),
      blockTypes: static::catalog()->blockTypes(),
      conditions: [],
      roster: static::materials(),
      groups: $event->getGroups(),
    );
    $this->assertSame($expected, $catalog->groups(), 'The built catalog answers the contributed groups in declaration order.');
    $this->assertSame($expected, $catalog->offered()->groups(), 'The offered view carries them through.');
    $this->assertSame([], $this->catalog->groups(), 'A catalog with no contribution answers none.');

    foreach ([
      [DialectCatalog::UNGROUPED => ['label' => 'No group']],
      ['Heroes' => ['label' => 'Heroes']],
      ['heroes' => ['label' => '']],
      ['heroes' => ['label' => 7]],
      ['heroes' => 'Heroes'],
      ['heroes' => ['label' => 'Heroes', 'description' => 7]],
    ] as $groups) {
      try {
        (new DialectCatalogBuildEvent([], ''))->addGroups($groups);
        $this->fail(sprintf('Groups %s were accepted.', json_encode($groups)));
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

}
