<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Dialect;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\DialectCssParser;
use Drupal\daedalus_blueprint\Dialect\DialectPrinter;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The per-element CSS print: printStyles() and its parse round trip.
 *
 * The print behind "Copy CSS" spells every entry as a flat rule exactly
 * as the document print's <style> block does, the element's own default
 * entry included (a `#id { }` rule where the document puts a style=""),
 * heads each element with its words and its own wear as comments, and
 * parses back through parseStyleBlock() to the entries the nodes hold.
 */
#[CoversClass(DialectPrinter::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class DialectPrinterStylesTest extends BlueprintKernelTestBase {

  protected const SECTION = '11111111-1111-4111-8111-111111111111';
  protected const TEXT = '22222222-2222-4222-8222-222222222222';
  protected const QUIET = '33333333-3333-4333-8333-333333333333';

  /**
   * The printer under test.
   */
  protected DialectPrinter $printer;

  /**
   * The catalog the fixture speaks.
   */
  protected DialectCatalog $catalog;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->printer = $this->container->get('daedalus_blueprint.dialect_printer');
    $this->catalog = new DialectCatalog(
      containerLayoutId: 'layout_onecol',
      axes: ['block_type:testimonial' => ['emphasis' => []]],
      blockTypes: [],
      // Table order is the canonical compound order: narrow+hover.
      conditions: [
        'narrow' => '@container (max-width: 767px)',
        'dark' => '@media (prefers-color-scheme: dark)',
        'hover' => ':hover',
      ],
      roster: new MaterialsRoster([
        new MaterialRecord(kind: MaterialKind::Variant, id: 'band', label: 'Band', group: 'style', spellings: [MaterialRecord::SLOT_CLASS => 'band'], scope: Address::fromString('kind:container')),
        new MaterialRecord(kind: MaterialKind::Variant, id: 'testimonial_emphatic', label: 'Emphatic', group: 'emphasis', spellings: [MaterialRecord::attrSlot('block_type:testimonial', 'emphasis') => 'emphatic'], scope: Address::fromString('block_type:testimonial')),
      ], new CacheableMetadata()),
    );
  }

  /**
   * An annotated subtree: a styled, worn section holding a styled block.
   *
   * The section carries a default entry, a compound condition, its own
   * wear (band), a child-selector rule wearing band with a hover
   * condition, and a children rule; the text block one default entry;
   * the last block nothing.
   */
  protected function subtree(): array {
    return [
      [
        'kind' => 'container',
        'uuid' => self::SECTION,
        'layout_id' => 'layout_onecol',
        'label' => 'Welcome',
        '_element' => ['type' => 'Section', 'name' => 'Welcome'],
        'styles' => [
          '' => [
            'variants' => [['variant' => 'band', 'fragment' => '']],
            'default' => ['color' => 'red'],
            'narrow+hover' => ['color' => 'green'],
          ],
          '::> pb-container' => [
            'variants' => [['variant' => 'band', 'fragment' => '']],
            'default' => ['gap' => '1rem'],
            'hover' => ['gap' => '2rem'],
          ],
          '::> *' => ['default' => ['margin' => '0']],
        ],
        'children' => [
          [
            'kind' => 'block',
            'uuid' => self::TEXT,
            'block_type' => 'text',
            '_element' => ['type' => 'Text', 'name' => 'Welcome!'],
            'styles' => ['' => ['default' => ['font-weight' => 'bold']]],
          ],
          [
            'kind' => 'block',
            'uuid' => self::QUIET,
            'block_type' => 'text',
            '_element' => ['type' => 'Text', 'name' => 'Text'],
          ],
        ],
      ],
    ];
  }

  /**
   * The print, byte-exact: words, wear, the own default as a rule, rules.
   */
  public function testPrintStyles(): void {
    $section = self::SECTION;
    $text = self::TEXT;
    $expected = <<<CSS
      /* Section: Welcome */
      /* wears class="band" */
      #$section { color: red; }
      @container (max-width: 767px) {
        #$section:hover { color: green; }
      }
      #$section > pb-container { class: band; gap: 1rem; }
      #$section > pb-container:hover { gap: 2rem; }
      #$section > * { margin: 0; }
      /* Text: Welcome! */
      #$text { font-weight: bold; }

    CSS;
    $this->assertSame($expected, $this->printer->printStyles($this->subtree(), $this->catalog));
  }

  /**
   * The print parses back to the entries the nodes hold.
   *
   * The comments blank out, the own default rule lands on the element's
   * '' local, the rule's wear comes back as its variants tuple, and the
   * compound condition reads back in its canonical spelling.
   */
  public function testPrintParsesBackToTheSameTuples(): void {
    $nodes = $this->subtree();
    $print = $this->printer->printStyles($nodes, $this->catalog);
    $errors = [];
    $tuples = (new DialectCssParser($this->catalog))->parseStyleBlock($print, 1, DialectCssParser::knownIds($nodes, $this->catalog), $errors);
    $this->assertSame([], array_map('strval', $errors));
    $this->assertSame([
      [self::SECTION, '', 'default', ['color' => 'red']],
      [self::SECTION, '', 'narrow+hover', ['color' => 'green']],
      [self::SECTION, '::> pb-container', 'variants', [['variant' => 'band', 'fragment' => '']]],
      [self::SECTION, '::> pb-container', 'default', ['gap' => '1rem']],
      [self::SECTION, '::> pb-container', 'hover', ['gap' => '2rem']],
      [self::SECTION, '::> *', 'default', ['margin' => '0']],
      [self::TEXT, '', 'default', ['font-weight' => 'bold']],
    ], $tuples);
  }

  /**
   * A bare tree prints no words; wear alone still prints its line.
   *
   * A tree built from the entity (no `_element`) heads nothing, an
   * element with neither styles nor wear prints nothing at all, a worn
   * but unstyled element prints its wear note, and region nodes are
   * transparent.
   */
  public function testBareTreesAndEmptyElements(): void {
    $text = self::TEXT;
    $bold = [
      'kind' => 'block',
      'uuid' => self::TEXT,
      'block_type' => 'text',
      'styles' => ['' => ['default' => ['font-weight' => 'bold']]],
    ];
    $this->assertSame("  #$text { font-weight: bold; }\n", $this->printer->printStyles([$bold], $this->catalog));
    $this->assertSame('', $this->printer->printStyles([
      [
        'kind' => 'block',
        'uuid' => self::TEXT,
        'block_type' => 'text',
        '_element' => ['type' => 'Text', 'name' => 'Text'],
      ],
    ], $this->catalog));
    $this->assertSame("  /* Section */\n  /* wears class=\"band\" */\n", $this->printer->printStyles([
      [
        'kind' => 'container',
        'uuid' => self::SECTION,
        'layout_id' => 'layout_onecol',
        '_element' => ['type' => 'Section', 'name' => 'Section'],
        'styles' => ['' => ['variants' => [['variant' => 'band', 'fragment' => '']]]],
        'children' => [
          [
            'kind' => 'region',
            'region' => 'content',
            'children' => [
              ['kind' => 'block', 'uuid' => self::QUIET, 'block_type' => 'text'],
            ],
          ],
        ],
      ],
    ], $this->catalog));
    $this->assertSame("  #$text { font-weight: bold; }\n", $this->printer->printStyles([
      ['kind' => 'region', 'region' => 'content', 'children' => [$bold]],
    ], $this->catalog));
  }

}
