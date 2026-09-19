<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Materials;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\ValueGrammar;
use Drupal\daedalus_blueprint\Materials\ValueKind;
use Drupal\daedalus_blueprint\Materials\ValueReference;
use Drupal\daedalus_blueprint_materials_test\TestMaterialsProvider;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\daedalus\Traits\NodeSuitesTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the value grammar: what a string in a slot IS, how a material spells.
 *
 * Pins the resolution contract every former sniff site meets at: a whole
 * style value that is one var() or one media/generate url() resolves to
 * its kind, anything else is a literal whose embedded references are
 * listed; class tokens, axis attribute values, stencil names and
 * animation names resolve through the roster's spelling index or come
 * back Unknown (never
 * literal); spell() is the provider's spelling or a loud refusal; and
 * the JS twin answers the same battery identically.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class ValueGrammarTest extends KernelTestBase {

  use NodeSuitesTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'daedalus_blueprint',
    'daedalus_blueprint_materials_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    TestMaterialsProvider::$records = NULL;
  }

  /**
   * The grammar service.
   */
  protected function grammar(): ValueGrammar {
    return $this->container->get('daedalus_blueprint.value_grammar');
  }

  /**
   * The roster over the test provider's reference records.
   */
  protected function roster(): MaterialsRoster {
    return $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
  }

  /**
   * The JS twin lives in daedalus_ui; node runs from its root.
   */
  protected static function nodeModuleRoot(): string {
    return DRUPAL_ROOT . '/' . \Drupal::service('extension.list.module')->getPath('daedalus_ui');
  }

  /**
   * A style value is one reference, one inline value, or a literal.
   */
  public function testResolveStyle(): void {
    $grammar = $this->grammar();
    $roster = $this->roster();

    $swatch = $grammar->resolve('var(--swatch-lime_green)', MaterialRecord::SLOT_STYLE, $roster);
    $this->assertSame(ValueKind::Swatch, $swatch->kind);
    $this->assertSame('lime_green', $swatch->id);
    $this->assertSame($roster->get(MaterialKind::Swatch, 'lime_green'), $swatch->record, 'A resolved material carries its record.');
    $this->assertTrue($swatch->isMaterial());
    $this->assertSame(['kind' => 'swatch', 'id' => 'lime_green', 'spelling' => 'var(--swatch-lime_green)'], $swatch->toArray());

    $token = $grammar->resolve(' var( --color-accent ) ', MaterialRecord::SLOT_STYLE, $roster);
    $this->assertSame(ValueKind::Token, $token->kind);
    $this->assertSame('color/accent', $token->id);
    $this->assertSame('var(--color-accent)', $token->spelling, 'Whitespace inside var() is tolerated; the spelling is canonical.');

    $unknown = $grammar->resolve('var(--swatch-limegreen)', MaterialRecord::SLOT_STYLE, $roster);
    $this->assertSame(ValueKind::Unknown, $unknown->kind);
    $this->assertSame('--swatch-limegreen', $unknown->id, 'Unknown keeps the name as written, for the corrective.');
    $this->assertSame('var(--swatch-limegreen)', $unknown->spelling);
    $this->assertNull($unknown->record);
    $this->assertFalse($unknown->isMaterial());

    $media = $grammar->resolve('url("media:ABC-123")', MaterialRecord::SLOT_STYLE, $roster);
    $this->assertSame(['kind' => 'media', 'id' => 'abc-123', 'spelling' => "url('media:abc-123')"], $media->toArray(), 'Reading is quote-agnostic; the spelling is the single-quoted lowercase canon.');
    $this->assertSame(ValueKind::Media, $grammar->resolve("URL( 'Media:x' )", MaterialRecord::SLOT_STYLE, $roster)->kind);

    $generate = $grammar->resolve("url('generate: a calm lake ')", MaterialRecord::SLOT_STYLE, $roster);
    $this->assertSame(ValueKind::Generate, $generate->kind);
    $this->assertSame('a calm lake', $generate->id);
    $this->assertSame("url('generate:a calm lake')", $generate->spelling);
    $this->assertSame('', $grammar->resolve("url('generate:')", MaterialRecord::SLOT_STYLE, $roster)->id, 'An empty prompt is still a generate request; acceptability is the consumer\'s rule.');

    foreach ([
      'red',
      '1px solid var(--swatch-lime_green)',
      'var(--color-accent, red)',
      'url(https://x/y.png)',
      "linear-gradient(red, blue), url('media:m1')",
    ] as $value) {
      $literal = $grammar->resolve($value, MaterialRecord::SLOT_STYLE, $roster);
      $this->assertSame(ValueKind::Literal, $literal->kind, $value);
      $this->assertNull($literal->id);
      $this->assertSame($value, $literal->spelling);
    }
    $this->assertEquals(ValueReference::literal(''), $grammar->resolve('  ', MaterialRecord::SLOT_STYLE, $roster), 'An empty value is the empty literal.');
    $this->assertTrue(ValueKind::Literal->isInline());
    $this->assertFalse(ValueKind::Unknown->isInline());
    $this->assertSame(MaterialKind::Stencil, ValueKind::Stencil->material());
    $this->assertNull(ValueKind::Media->material());
  }

  /**
   * Class, attribute and stencil slots hold references only.
   */
  public function testResolveReferenceSlots(): void {
    $grammar = $this->grammar();
    $roster = $this->roster();

    $this->assertSame('button', $grammar->resolve('button', MaterialRecord::SLOT_CLASS, $roster)->id);
    $this->assertSame(ValueKind::Variant, $grammar->resolve(' button ', MaterialRecord::SLOT_CLASS, $roster)->kind);
    $this->assertSame(ValueKind::Unknown, $grammar->resolve('hero', MaterialRecord::SLOT_CLASS, $roster)->kind, 'Slots are separate vocabularies: the stencil "hero" is no class.');
    $this->assertSame(ValueKind::Stencil, $grammar->resolve('hero', MaterialRecord::SLOT_STENCIL, $roster)->kind);
    $this->assertSame('container_full', $grammar->resolve('full', MaterialRecord::attrSlot('kind:container', 'width'), $roster)->id);
    $this->assertSame('block_full', $grammar->resolve('full', MaterialRecord::attrSlot('kind:block', 'width'), $roster)->id, 'An attribute value resolves in its scope: the same spelling on another scope is that scope\'s variant.');
    $unknown = $grammar->resolve('full', MaterialRecord::attrSlot('kind:container', 'height'), $roster);
    $this->assertSame(ValueKind::Unknown, $unknown->kind);
    $this->assertSame(['kind' => 'unknown', 'id' => 'full', 'spelling' => 'full'], $unknown->toArray());
    $this->assertSame(ValueKind::Literal, $grammar->resolve('', MaterialRecord::SLOT_CLASS, $roster)->kind);
    $this->assertSame(ValueKind::Unknown, $grammar->resolve('var(--swatch-lime_green)', MaterialRecord::SLOT_CLASS, $roster)->kind, 'A var() is no class: the style slot\'s syntax does not leak.');

    // The animation slot resolves a keyframes name by its prefixed ident;
    // the style slot never resolves a bare ident (property-scoped: only a
    // consumer that knows the property is animation-* asks here).
    $this->assertSame('fade_in', $grammar->resolve(' motion-fade_in ', MaterialRecord::SLOT_ANIMATION, $roster)->id);
    $this->assertSame(ValueKind::Animation, $grammar->resolve('motion-fade_in', MaterialRecord::SLOT_ANIMATION, $roster)->kind);
    $this->assertSame(MaterialKind::Animation, ValueKind::Animation->material());
    $this->assertSame(ValueKind::Unknown, $grammar->resolve('spin', MaterialRecord::SLOT_ANIMATION, $roster)->kind, 'A plain ident names no material; whether it is a theme keyframes is the consumer\'s rule.');
    $this->assertSame(ValueKind::Literal, $grammar->resolve('motion-fade_in', MaterialRecord::SLOT_STYLE, $roster)->kind);
  }

  /**
   * References() lists what must resolve, in source order.
   */
  public function testReferences(): void {
    $grammar = $this->grammar();
    $roster = $this->roster();
    $kinds = static fn (array $refs): array => array_map(static fn (ValueReference $ref): array => [
      $ref->kind->value,
      $ref->id,
    ], $refs);

    $compound = "1px solid var(--swatch-lime_green), linear-gradient(var(--color-accent, red), url(x.png)), url('media:M1'), url('generate:sky')";
    $this->assertSame([
      ['swatch', 'lime_green'],
      ['token', 'color/accent'],
      ['media', 'm1'],
      ['generate', 'sky'],
    ], $kinds($grammar->references($compound, MaterialRecord::SLOT_STYLE, $roster)), 'var() with a fallback, media and generate are listed; a file url() is not.');
    $this->assertSame([['unknown', '--nope']], $kinds($grammar->references('var(--nope)', MaterialRecord::SLOT_STYLE, $roster)));
    $this->assertSame([], $grammar->references('red', MaterialRecord::SLOT_STYLE, $roster), 'A literal carries no reference.');

    $this->assertSame([['variant', 'button'], ['unknown', 'nope'], ['variant', 'hero_glow']], $kinds($grammar->references("button  nope\thero_glow", MaterialRecord::SLOT_CLASS, $roster)));
    $this->assertSame([], $grammar->references('  ', MaterialRecord::SLOT_CLASS, $roster));
    $this->assertSame([['variant', 'container_full']], $kinds($grammar->references(' full ', MaterialRecord::attrSlot('kind:container', 'width'), $roster)));
    $this->assertSame([['variant', 'block_full']], $kinds($grammar->references(' full ', MaterialRecord::attrSlot('kind:block', 'width'), $roster)));
    $this->assertSame([], $grammar->references('', MaterialRecord::attrSlot('kind:container', 'width'), $roster));
    $this->assertSame([['stencil', 'hero']], $kinds($grammar->references('hero', MaterialRecord::SLOT_STENCIL, $roster)));
    $this->assertSame([['animation', 'fade_in']], $kinds($grammar->references(' motion-fade_in ', MaterialRecord::SLOT_ANIMATION, $roster)));
    $this->assertSame([], $grammar->references('motion-fade_in', MaterialRecord::SLOT_STYLE, $roster), 'A bare ident in a style value is a literal, no reference.');
  }

  /**
   * Canonical() respells every reference and nothing else.
   *
   * The one rewrite behind parse and print: media and generate url()s
   * take the single-quoted canon whatever quoting they carried, a var()
   * head loses its inner whitespace and keeps its fallback, a file url()
   * and every literal byte stay as written, and an unknown reference is
   * left for the consumer to refuse.
   */
  public function testCanonical(): void {
    $grammar = $this->grammar();
    $roster = $this->roster();
    $legacy = str_replace("'", '"', "url('media:ABC')");
    $this->assertSame("linear-gradient(red, blue), url('media:abc')", $grammar->canonical("linear-gradient(red, blue), $legacy", MaterialRecord::SLOT_STYLE, $roster));
    $this->assertSame('var(--color-accent, red) url(x.png)', $grammar->canonical('var( --color-accent, red) url(x.png)', MaterialRecord::SLOT_STYLE, $roster));
    $this->assertSame("url('generate:a dog')", $grammar->canonical('url(generate: a dog )', MaterialRecord::SLOT_STYLE, $roster));
    $this->assertSame('var(--nope)', $grammar->canonical('var( --nope )', MaterialRecord::SLOT_STYLE, $roster), 'An unknown reference keeps its spelling; refusing it is the consumer\'s rule.');
    $this->assertSame('button nope', $grammar->canonical('  button   nope ', MaterialRecord::SLOT_CLASS, $roster));
    $this->assertSame('hero', $grammar->canonical(' hero ', MaterialRecord::SLOT_STENCIL, $roster));
    $this->assertSame('motion-fade_in', $grammar->canonical(' motion-fade_in ', MaterialRecord::SLOT_ANIMATION, $roster));
    $this->assertSame('', $grammar->canonical('', MaterialRecord::SLOT_STYLE, $roster));
  }

  /**
   * Spell() is the provider's spelling, or a loud refusal.
   */
  public function testSpell(): void {
    $grammar = $this->grammar();
    $roster = $this->roster();
    $this->assertSame('var(--swatch-lime_green)', $grammar->spell($roster->get(MaterialKind::Swatch, 'lime_green'), MaterialRecord::SLOT_STYLE));
    $this->assertSame('full', $grammar->spell($roster->get(MaterialKind::Variant, 'container_full'), MaterialRecord::attrSlot('kind:container', 'width')));
    $this->assertSame('full', $grammar->spell($roster->get(MaterialKind::Variant, 'block_full'), MaterialRecord::attrSlot('kind:block', 'width')));
    $this->assertSame('hero', $grammar->spell($roster->get(MaterialKind::Stencil, 'hero'), MaterialRecord::SLOT_STENCIL));
    $this->assertSame('motion-fade_in', $grammar->spell($roster->get(MaterialKind::Animation, 'fade_in'), MaterialRecord::SLOT_ANIMATION));
    $this->assertSame("url('media:abc')", ValueGrammar::spellMedia(' ABC '));
    $this->assertSame("url('generate:a dog')", ValueGrammar::spellGenerate('  a dog  '));

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Swatch "lime_green" has no spelling in the class slot; it spells in: style.');
    $grammar->spell($roster->get(MaterialKind::Swatch, 'lime_green'), MaterialRecord::SLOT_CLASS);
  }

  /**
   * A slot the grammar does not know is refused, not guessed.
   */
  public function testUnknownSlot(): void {
    $this->assertTrue(ValueGrammar::isSlot('attr:kind:container:width'));
    $this->assertTrue(ValueGrammar::isSlot(MaterialRecord::SLOT_ANIMATION));
    $this->assertFalse(ValueGrammar::isSlot('attr:width'), 'The site-wide attribute slot does not exist.');
    $this->assertFalse(ValueGrammar::isSlot('attr:'));
    $this->assertFalse(ValueGrammar::isSlot('field'));
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Unknown value slot "field"');
    $this->grammar()->resolve('x', 'field', $this->roster());
  }

  /**
   * A reference's shape is guarded: materials carry their record, only.
   */
  public function testReferenceGuards(): void {
    $record = $this->roster()->get(MaterialKind::Swatch, 'lime_green');
    $this->assertSame($record, (new ValueReference(ValueKind::Swatch, 'lime_green', 'var(--swatch-lime_green)', $record))->record);
    $guards = [
      'material without record' => static fn () => new ValueReference(ValueKind::Swatch, 'lime_green', 'x'),
      'material with a record of another kind' => static fn () => new ValueReference(ValueKind::Token, 'lime_green', 'x', $record),
      'inline with a record' => static fn () => new ValueReference(ValueKind::Media, 'u', 'x', $record),
      'literal with an id' => static fn () => new ValueReference(ValueKind::Literal, 'x', 'x'),
      'unknown without an id' => static fn () => new ValueReference(ValueKind::Unknown, NULL, 'x'),
    ];
    foreach ($guards as $label => $build) {
      try {
        $build();
        $this->fail("$label was accepted.");
      }
      catch (\InvalidArgumentException) {
        $this->addToAssertionCount(1);
      }
    }
  }

  /**
   * The PHP grammar and its client twin answer the same battery identically.
   *
   * The round-trip lock (the ElementResolver/element-resolver.js pattern):
   * the roster's records go to the twin in the delivery shape
   * (MaterialRecord::toArray(), what the page-load payload carries), and
   * every resolve, references, spell, applicable and scopesPair case is
   * answered by both sides; the answers must be deeply equal, or a
   * picker's live reading of a value differs from what the server
   * validates.
   */
  public function testClientTwinEquivalence(): void {
    $grammar = $this->grammar();
    $roster = $this->roster();

    $style_values = [
      'var(--swatch-lime_green)', ' var( --color-accent ) ', 'var(--swatch-limegreen)', 'var(--color-accent, red)',
      'url("media:ABC-123")', "URL( 'Media:x' )", "url('generate: a calm lake ')", "url('generate:')", 'url(https://x/y.png)',
      'red', '', '1px solid var(--swatch-lime_green)',
      "linear-gradient(var(--color-accent, red), url(x.png)), url('media:M1'), url('generate:sky')",
      'var(--nope) var(--swatch-lime_green)', 'button', 'hero', 'motion-fade_in',
    ];
    $slot_values = [
      [MaterialRecord::SLOT_CLASS, 'button'], [MaterialRecord::SLOT_CLASS, ' button '], [
        MaterialRecord::SLOT_CLASS,
        'hero',
      ],
      [MaterialRecord::SLOT_CLASS, "button  nope\thero_glow"], [MaterialRecord::SLOT_CLASS, ''],
      [MaterialRecord::SLOT_CLASS, 'var(--swatch-lime_green)'],
      [MaterialRecord::attrSlot('kind:container', 'width'), 'full'], [
        MaterialRecord::attrSlot('kind:container', 'width'),
        ' full ',
      ], [
        MaterialRecord::attrSlot('kind:container', 'height'),
        'full',
      ],
      [MaterialRecord::attrSlot('kind:block', 'width'), 'full'], [
        MaterialRecord::attrSlot('block_type:text', 'width'),
        'full',
      ],
      [MaterialRecord::attrSlot('kind:container', 'width'), ''],
      [MaterialRecord::SLOT_STENCIL, 'hero'], [MaterialRecord::SLOT_STENCIL, 'button'], [
        MaterialRecord::SLOT_STENCIL,
        '',
      ],
      [MaterialRecord::SLOT_ANIMATION, 'motion-fade_in'], [MaterialRecord::SLOT_ANIMATION, ' motion-fade_in '], [
        MaterialRecord::SLOT_ANIMATION,
        'spin',
      ],
      [MaterialRecord::SLOT_ANIMATION, 'var(--swatch-lime_green)'], [MaterialRecord::SLOT_ANIMATION, ''],
    ];
    $value_cases = array_merge(
      array_map(static fn (string $value): array => ['value' => $value, 'slot' => MaterialRecord::SLOT_STYLE], $style_values),
      array_map(static fn (array $pair): array => ['value' => $pair[1], 'slot' => $pair[0]], $slot_values),
    );
    $spell_cases = [];
    foreach ($roster->all() as $record) {
      foreach (array_keys($record->spellings) as $slot) {
        $spell_cases[] = ['kind' => $record->kind->value, 'id' => $record->id, 'slot' => $slot];
      }
    }
    $applicable_cases = [];
    foreach (['button', 'container_full', 'block_full', 'hero_glow', 'link_quiet'] as $variant) {
      foreach (['block_type:text', 'block_type:image', 'kind:block', 'kind:container', 'layout:layout_onecol'] as $scope) {
        foreach ([TRUE, FALSE] as $top_level) {
          foreach ([NULL, 'hero', 'call_to_action'] as $within) {
            $applicable_cases[] = [
              'kind' => 'variant',
              'id' => $variant,
              'scope' => $scope,
              'topLevel' => $top_level,
              'withinStencil' => $within,
            ];
          }
        }
      }
    }
    $applicable_cases[] = [
      'kind' => 'swatch',
      'id' => 'lime_green',
      'scope' => 'block_type:image',
      'topLevel' => FALSE,
      'withinStencil' => NULL,
    ];
    $applicable_cases[] = [
      'kind' => 'stencil',
      'id' => 'hero',
      'scope' => 'kind:container',
      'topLevel' => FALSE,
      'withinStencil' => NULL,
    ];
    $applicable_cases[] = [
      'kind' => 'animation',
      'id' => 'fade_in',
      'scope' => 'block_type:text',
      'topLevel' => FALSE,
      'withinStencil' => NULL,
    ];
    $pair_cases = array_map(static fn (array $row): array => ['variant' => $row[0], 'element' => $row[1]], array_values(MaterialsRosterTest::scopePairs()));

    $php = [
      'resolve' => array_map(static fn (array $c): array => $grammar->resolve($c['value'], $c['slot'], $roster)->toArray(), $value_cases),
      'references' => array_map(static fn (array $c): array => array_map(static fn (ValueReference $ref): array => $ref->toArray(), $grammar->references($c['value'], $c['slot'], $roster)), $value_cases),
      'canonical' => array_map(static fn (array $c): string => $grammar->canonical($c['value'], $c['slot'], $roster), $value_cases),
      'spell' => array_map(static fn (array $c): string => $grammar->spell($roster->get(MaterialKind::from($c['kind']), $c['id']), $c['slot']), $spell_cases),
      'applicable' => array_map(static fn (array $c): bool => $roster->applicable($roster->get(MaterialKind::from($c['kind']), $c['id']), Address::fromString($c['scope']), $c['topLevel'], $c['withinStencil']), $applicable_cases),
      'scopesPair' => array_map(static fn (array $c): bool => MaterialsRoster::scopesPair($c['variant'], $c['element']), $pair_cases),
    ];
    $input = json_encode([
      'records' => array_map(static fn (MaterialRecord $record): array => $record->toArray(), $roster->all()),
      'resolve' => $value_cases,
      'references' => $value_cases,
      'canonical' => $value_cases,
      'spell' => $spell_cases,
      'applicable' => $applicable_cases,
      'scopesPair' => $pair_cases,
    ], JSON_THROW_ON_ERROR);
    $js = json_decode($this->runNodeScript('tests/js/materials-cases.mjs', $input), TRUE, 512, JSON_THROW_ON_ERROR);

    foreach ($php as $battery => $expected) {
      $this->assertSameSize($expected, $js[$battery] ?? [], "The $battery battery.");
      foreach ($expected as $index => $answer) {
        $this->assertSame(json_decode(json_encode($answer), TRUE), $js[$battery][$index], sprintf('%s case %d diverged between the PHP grammar and the client twin.', $battery, $index));
      }
    }
    // Guard against a silently-degenerate battery: every kind answered.
    $kinds = array_unique(array_column($php['resolve'], 'kind'));
    sort($kinds);
    $this->assertSame(['animation', 'generate', 'literal', 'media', 'stencil', 'swatch', 'token', 'unknown', 'variant'], $kinds);
    $this->assertContains(TRUE, $php['applicable']);
    $this->assertContains(FALSE, $php['applicable']);
  }

}
