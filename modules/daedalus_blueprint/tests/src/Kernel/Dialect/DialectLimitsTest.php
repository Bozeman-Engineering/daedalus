<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Dialect;

use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DialectParser;
use Drupal\daedalus_blueprint\Dialect\DialectPrinter;
use Drupal\daedalus_blueprint\Dialect\DialectTreeBuilder;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The page language's print keeps values inside their tags; a parse is bounded.
 *
 * The print: a stored value prints inside the element that carries it and
 * nowhere else, whatever it spells. A plain body is escaped text; an
 * opaque body (rich text, a generic field, the style block) prints
 * verbatim except its own end tag; a comment's text stays in its comment.
 * Read back by the parser, each print is the one element it was printed
 * from.
 *
 * The parse: markup over the size ceiling is not read, and markup nested
 * past the depth ceiling is not kept. Either answers one error naming
 * the limit and an empty tree.
 */
#[CoversClass(DialectPrinter::class)]
#[CoversClass(DialectParser::class)]
#[CoversClass(DialectTreeBuilder::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class DialectLimitsTest extends BlueprintKernelTestBase {

  protected const SECTION = 'c0d89921-04a5-48d1-8720-d7508da565f6';

  protected const BLOCK = '66f39f31-8735-431e-9848-0db7e34e81ff';

  /**
   * The dialect parser under test.
   */
  protected DialectParser $parser;

  /**
   * The dialect printer under test.
   */
  protected DialectPrinter $printer;

  /**
   * A small site vocabulary: a plain block type, two rich ones, a condition.
   */
  protected DialectCatalog $catalog;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->parser = $this->container->get('daedalus_blueprint.dialect_parser');
    $this->printer = $this->container->get('daedalus_blueprint.dialect_printer');
    $this->catalog = new DialectCatalog(
      containerLayoutId: 'daedalus_stylish_container',
      axes: [],
      blockTypes: [
        'text' => [
          'attributes' => [],
          'body' => ['field' => 'field_text_text', 'kind' => 'plain'],
        ],
        'formatted_text' => [
          'attributes' => [],
          'body' => ['field' => 'field_formatted_text_text', 'kind' => 'rich'],
        ],
        // A block type with one long formatted field is rich under its
        // own tag, as the catalog builder declares core's basic block.
        'basic' => [
          'attributes' => [],
          'body' => ['field' => 'body', 'kind' => 'rich'],
        ],
      ],
      conditions: [
        'narrow' => ['wrapper' => '@container (max-width: 767px)', 'axis' => 'width'],
      ],
    );
  }

  /**
   * One section holding one block, as the serializer hands it.
   */
  protected function tree(array $block): array {
    return [
      [
        'kind' => 'container',
        'uuid' => self::SECTION,
        'layout_id' => 'daedalus_stylish_container',
        'children' => [['kind' => 'block', 'uuid' => self::BLOCK] + $block],
      ],
    ];
  }

  /**
   * Asserts a print reads back as the one section and one block it was.
   *
   * @return array
   *   The block node the parser read.
   */
  protected function assertOneBlock(string $text): array {
    $parse = $this->parser->parseDocument($text, $this->catalog);
    $this->assertSame([], array_map('strval', $parse->errors), 'The print parses clean.');
    $this->assertCount(1, $parse->tree, 'One section.');
    $this->assertSame(self::SECTION, $parse->tree[0]['uuid']);
    $this->assertCount(1, $parse->tree[0]['children'], 'One block in it.');
    $this->assertSame(self::BLOCK, $parse->tree[0]['children'][0]['uuid']);
    return $parse->tree[0]['children'][0];
  }

  /**
   * A rich body spelling its own end tag stays one body.
   */
  public function testRichBodyStaysInsideItsTag(): void {
    $stored = '<p>Copy</p></d-formatted-text></d-container><d-container id="second"><d-formatted-text></D-Formatted-Text ><p>more</p>';
    $print = $this->printer->print($this->tree([
      'block_type' => 'formatted_text',
      'block_fields' => ['field_formatted_text_text' => ['value' => $stored]],
    ]), $this->catalog);

    $this->assertSame(1, substr_count(strtolower($print->text), '</d-formatted-text'), 'The one end tag in the print is the element\'s own.');
    $this->assertStringContainsString('<p>Copy</p>&lt;/d-formatted-text>', $print->text, 'Everything but the end tag prints as stored.');
    $this->assertStringContainsString('&lt;/D-Formatted-Text ><p>more</p>', $print->text, 'Any letter case of the end tag is covered.');

    $block = $this->assertOneBlock($print->text);
    $body = $block['block_fields']['field_formatted_text_text'];
    $this->assertStringContainsString('<d-container id="second">', is_array($body) ? $body['value'] : $body, 'What the value spelled is body text to the parser.');

    $plain = '<h2>Title</h2><p>Come for the code &amp; stay.</p>';
    $print = $this->printer->print($this->tree([
      'block_type' => 'formatted_text',
      'block_fields' => ['field_formatted_text_text' => ['value' => $plain]],
    ]), $this->catalog);
    $this->assertStringContainsString('>' . $plain . '</d-formatted-text>', $print->text, 'A body that spells no end tag prints byte for byte.');
  }

  /**
   * Every rich block type's body is text to the parser, under its own tag.
   */
  public function testEveryRichBodyIsReadAsText(): void {
    $this->assertSame(['d-formatted-text', 'd-basic'], $this->catalog->richTags(), 'The catalog names the tag of each block type it declares rich.');

    $stored = '<p>Copy</p></d-container><d-container id="second"><d-text>forged</d-text>';
    $print = $this->printer->print($this->tree([
      'block_type' => 'basic',
      'block_fields' => ['body' => ['value' => $stored]],
    ]), $this->catalog);
    $this->assertStringContainsString('>' . $stored . '</d-basic>', $print->text, 'A body that spells no end tag of its own prints byte for byte.');
    $block = $this->assertOneBlock($print->text);
    $this->assertSame('basic', $block['block_type']);
    $this->assertSame($stored, $block['block_fields']['body'], 'The body reads back as the one value that was stored.');

    $harmless = '<h2>Title</h2><p>Come for the <a href="/code">code</a> &amp; stay.</p><ul><li>One</li><li>Two</li></ul>';
    $print = $this->printer->print($this->tree([
      'block_type' => 'basic',
      'block_fields' => ['body' => ['value' => $harmless]],
    ]), $this->catalog);
    $block = $this->assertOneBlock($print->text);
    $this->assertSame($harmless, $block['block_fields']['body'], 'A body of ordinary markup reads back whole, its tags and entities as stored.');

    $closing = '<p>Copy</p></d-basic></d-container><d-container id="second"><d-basic></D-Basic ><p>more</p>';
    $print = $this->printer->print($this->tree([
      'block_type' => 'basic',
      'block_fields' => ['body' => ['value' => $closing]],
    ]), $this->catalog);
    $this->assertSame(1, substr_count(strtolower($print->text), '</d-basic'), 'The one end tag in the print is the element\'s own.');
    $block = $this->assertOneBlock($print->text);
    $this->assertStringContainsString('<d-container id="second">', $block['block_fields']['body'], 'What the value spelled is body text to the parser.');

    // The tags are the catalog's: a tag this catalog does not declare
    // rich is read as elements.
    $other = new DialectCatalog(
      containerLayoutId: 'daedalus_stylish_container',
      axes: [],
      blockTypes: ['text' => ['attributes' => [], 'body' => ['field' => 'field_text_text', 'kind' => 'plain']]],
      conditions: [],
    );
    $this->assertSame([], $other->richTags());
    $parse = $this->parser->parseDocument('<d-container><d-text>Hi <b>there</b></d-text></d-container>', $other);
    $this->assertContains('ILLEGAL_CONTAINMENT', array_column($parse->errors, 'code'), 'A plain body is still read as elements.');
  }

  /**
   * A generic field value spelling its own end tag stays one field.
   */
  public function testGenericFieldStaysInsideItsTag(): void {
    $stored = 'Said well.</d-field></d-block></d-container><d-container id="second"><d-block type="quote"><d-field name="field_quote_text">';
    $print = $this->printer->print($this->tree([
      'block_type' => 'quote',
      'block_fields' => ['field_quote_text' => $stored],
    ]), $this->catalog);

    $this->assertSame(1, substr_count($print->text, '</d-field'), 'The one end tag in the print is the field\'s own.');
    $block = $this->assertOneBlock($print->text);
    $this->assertSame(['field_quote_text'], array_keys($block['block_fields']), 'One field, the one that was stored.');
    $this->assertStringContainsString('<d-container id="second">', $block['block_fields']['field_quote_text']);
  }

  /**
   * A plain body is text, and reads back as the text that was stored.
   */
  public function testPlainBodyIsText(): void {
    $stored = 'Welcome</d-text></d-container><d-container id="second"><d-text>in';
    $print = $this->printer->print($this->tree([
      'block_type' => 'text',
      'block_fields' => ['field_text_text' => $stored],
    ]), $this->catalog);

    $this->assertSame(1, substr_count($print->text, '</d-text>'));
    $this->assertStringNotContainsString('<d-container id="second">', $print->text);
    $block = $this->assertOneBlock($print->text);
    $this->assertSame($stored, $block['block_fields']['field_text_text'], 'The escape is undone by the parse: the round trip keeps the value.');
  }

  /**
   * An attribute value stays inside its quotes.
   */
  public function testAttributeValueStaysInsideItsQuotes(): void {
    $tree = $this->tree(['block_type' => 'text', 'block_fields' => ['field_text_text' => 'Hi']]);
    $tree[0]['label'] = 'Welcome"></d-container><d-container id="second" name="';
    $print = $this->printer->print($tree, $this->catalog);

    $parse = $this->parser->parseDocument($print->text, $this->catalog);
    $this->assertCount(1, $parse->tree, 'One section.');
    $this->assertSame($tree[0]['label'], $parse->tree[0]['label'], 'The name reads back whole.');
  }

  /**
   * Nothing a style entry holds ends the style block or a comment in it.
   */
  public function testStyleBlockStaysInsideItsTag(): void {
    $tree = $this->tree([
      'block_type' => 'text',
      'block_fields' => ['field_text_text' => 'Hi'],
      'styles' => [
        '' => [
          'narrow' => ['content' => '"</style><d-container id=second>"'],
          'no such */ </STYLE><d-container id=second> condition' => ['color' => 'red'],
        ],
      ],
      '_element' => ['type' => 'Text', 'name' => "Hi */ } </style>\n#x { color: red; } /*"],
    ]);

    $print = $this->printer->print($tree, $this->catalog);
    $this->assertSame(1, substr_count(strtolower($print->text), '</style'), 'The one end tag in the print is the block\'s own.');
    $this->assertStringEndsWith("</style>\n", $print->text);
    $this->assertStringContainsString('content: "<\/style><d-container id=second>"', $print->text, 'The value prints with the slash escaped, the same text to CSS.');
    $parse = $this->parser->parseDocument($print->text, $this->catalog);
    $this->assertCount(1, $parse->tree, 'The parser reads one section.');
    $this->assertCount(1, $parse->tree[0]['children']);

    $css = $this->printer->printStyles($tree, $this->catalog)->text;
    $this->assertStringNotContainsString('</style', strtolower($css), 'The CSS print spells no end tag either.');
    foreach (explode("\n", trim($css)) as $line) {
      if (str_starts_with(ltrim($line), '/*')) {
        $this->assertSame(1, substr_count($line, '*/'), "A comment closes once, at its end: $line");
        $this->assertStringEndsWith('*/', $line);
      }
    }
    $this->assertStringContainsString('  /* Text: Hi * / } <\/style> #x { color: red; } /* */', $css, 'The element\'s words are one comment on one line.');
  }

  /**
   * Markup over the size ceiling is refused unread, by both doors.
   */
  public function testMarkupOverTheSizeCeilingIsRefused(): void {
    $section = '<d-container id="a"><d-text>Hi</d-text></d-container>';
    $at = $section . str_repeat(' ', DialectParser::MAX_BYTES - strlen($section));
    $this->assertSame(DialectParser::MAX_BYTES, strlen($at));
    $parse = $this->parser->parseDocument($at, $this->catalog);
    $this->assertSame([], array_map('strval', $parse->errors), 'Markup at the ceiling is read.');
    $this->assertCount(1, $parse->tree);

    foreach (['document', 'fragment'] as $door) {
      $over = $at . ' ';
      $parse = $door === 'document'
        ? $this->parser->parseDocument($over, $this->catalog)
        : $this->parser->parseFragment($over, $this->catalog, TRUE);
      $this->assertFalse($parse->isValid());
      $this->assertSame([], $parse->tree, "Nothing of an oversized $door is read.");
      $this->assertCount(1, $parse->errors, 'One error, the limit.');
      $error = $parse->errors[0];
      $this->assertSame('MARKUP_TOO_LARGE', $error->code);
      $this->assertSame(['bytes' => DialectParser::MAX_BYTES + 1, 'max_bytes' => DialectParser::MAX_BYTES], $error->context);
      $this->assertStringContainsString((string) DialectParser::MAX_BYTES, $error->message, 'The message names the ceiling.');
      $this->assertNotSame('', $error->hint, 'The error says what to do instead.');
      $this->assertSame('MARKUP_TOO_LARGE', $error->toBlueprintError('html')->getCode(), 'The op door answers it in the spine\'s shape.');
    }
  }

  /**
   * Markup nested past the depth ceiling is refused whole, by both doors.
   */
  public function testNestingPastTheDepthCeilingIsRefused(): void {
    $nest = static fn (int $depth): string => str_repeat("<d-container>\n", $depth) . str_repeat("</d-container>\n", $depth);

    $parse = $this->parser->parseDocument($nest(DialectParser::MAX_DEPTH), $this->catalog);
    $this->assertNotContains('NESTING_TOO_DEEP', array_column($parse->errors, 'code'), 'Nesting at the ceiling is read.');
    $depth = 0;
    for ($node = $parse->tree[0] ?? NULL; $node !== NULL; $node = $node['children'][0] ?? NULL) {
      $depth++;
    }
    $this->assertSame(DialectParser::MAX_DEPTH, $depth, 'Every level at the ceiling is in the tree.');

    $over = '<d-container id="kept"><d-text>Hi</d-text></d-container>' . "\n" . $nest(DialectParser::MAX_DEPTH + 1);
    foreach (['document', 'fragment'] as $door) {
      $parse = $door === 'document'
        ? $this->parser->parseDocument($over, $this->catalog)
        : $this->parser->parseFragment($over, $this->catalog, TRUE);
      $this->assertSame([], $parse->tree, "Nothing of an over-nested $door is kept, the part before the limit included.");
      $this->assertCount(1, $parse->errors, 'One error, the limit: no unclosed or mismatched noise beside it.');
      $error = $parse->errors[0];
      $this->assertSame('NESTING_TOO_DEEP', $error->code);
      $this->assertSame(DialectParser::MAX_DEPTH + 2, $error->line, 'The line is the first element past the ceiling.');
      $this->assertSame(['max_depth' => DialectParser::MAX_DEPTH], $error->context);
      $this->assertStringContainsString((string) DialectParser::MAX_DEPTH, $error->message);
      $this->assertNotSame('', $error->hint);
    }

    // An element left open nests what follows it: far past the ceiling it
    // is the same one error, however long the run.
    $parse = $this->parser->parseDocument(str_repeat('<d-container>', 5000), $this->catalog);
    $this->assertSame(['NESTING_TOO_DEEP'], array_column($parse->errors, 'code'));
    $this->assertSame([], $parse->tree);
  }

}
