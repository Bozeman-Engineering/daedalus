<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Dialect;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\Dialect\CssFragmentDoor;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The parse door: a CSS fragment under one key, read into flat entries.
 *
 * The door wraps the text under its key, re-roots copied `#id` rules,
 * parses through the style op's parser over the live catalog and the
 * staged tree, and answers entries, rule wear and correctives whose lines
 * are the sent text's. It writes nothing.
 */
#[CoversClass(CssFragmentDoor::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class CssFragmentDoorTest extends BlueprintKernelTestBase {

  /**
   * A uuid no element on the page carries (a copied block's id).
   */
  protected const OTHER = '99999999-9999-4999-8999-999999999999';

  /**
   * The door under test.
   */
  protected CssFragmentDoor $door;

  /**
   * The host entity.
   */
  protected object $entity;

  /**
   * The root section's uuid (a container in the tree).
   */
  protected string $section;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->enableModules([
      'layout_discovery',
      'block',
      'block_content',
      'layout_builder',
      'navigation',
      'twig_events',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'file',
      'daedalus_ui',
      // Section identity (the uuid the tree addresses containers by) is
      // daedalus_layout's third-party setting.
      'daedalus_layout',
      'daedalus_stylish',
    ]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    // The catalog reads the materials roster, whose daedalus_stylish
    // provider reads all three storages.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_skin_material_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    $this->enableLayoutBuilder();

    $this->section = (new PhpUuid())->generate();
    $section = $this->createSection('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->section);
    $this->entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Styled host',
      'layout_builder__layout' => [$section],
    ]);
    $this->entity->save();

    // A published container style variant, the class a rule may wear.
    $variant = DaedalusStylishVariant::create([
      'label' => 'Section',
      'machine_name' => 'section',
      'scope' => 'kind:container',
      'axis' => 'style',
      'status' => TRUE,
    ]);
    $variant->setStyles(['' => ['default' => ['padding-top' => '4rem']]]);
    $variant->save();

    $this->door = $this->container->get('daedalus_blueprint.css_fragment_door');
  }

  /**
   * Nesting, states and wrappers under an element key, and nothing written.
   *
   * The element's own declarations land on its default condition, `&:`
   * and `@wrapper` blocks pick conditions (a wrapper around a state reads
   * as the canonical compound), and relative preludes land as the
   * element's rule keys, in source order.
   */
  public function testNestingStatesAndWrappers(): void {
    $key = "container:{$this->section}";
    $parsed = $this->door->parse($this->host($this->entity), $key, "color: red;\n&:hover { color: blue; }\n@container (max-width: 767px) {\n  &:hover { color: green; }\n}\n> d-container { gap: 1rem; }\n> * { margin: 0; }");
    $this->assertSame([], $parsed['errors']);
    $this->assertSame([], $parsed['wear']);
    $this->assertSame([
      ['key' => $key, 'condition' => 'default', 'properties' => ['color' => 'red']],
      ['key' => $key, 'condition' => 'hover', 'properties' => ['color' => 'blue']],
      ['key' => $key, 'condition' => 'hover+narrow', 'properties' => ['color' => 'green']],
      ['key' => "$key::> d-container", 'condition' => 'default', 'properties' => ['gap' => '1rem']],
      ['key' => "$key::> *", 'condition' => 'default', 'properties' => ['margin' => '0']],
    ], $parsed['entries']);

    // The WHEN opened: an at-rule prelude equal to a published wrapper
    // (case and spacing folded) is its reference; any other query the
    // condition grammar reads is a LITERAL clause in its canonical text,
    // composed with the states and wrappers around it; an unpublished
    // posable pseudo-class on the anchor peels as a pseudo-class literal.
    // The door parses only: whether this site admits a literal is the
    // validator's verdict at the write.
    $parsed = $this->door->parse($this->host($this->entity), $key, "@CONTAINER (max-width:767px) { color: red; }\n@media (hover: hover) { &:hover { color: blue; } }\n@container (min-width: 500px) { > * { margin: 0; } }\n@supports (display: grid) { gap: 1rem; }\n&:checked { color: green; }");
    $this->assertSame([], $parsed['errors']);
    $this->assertSame([
      ['key' => $key, 'condition' => 'narrow', 'properties' => ['color' => 'red']],
      ['key' => $key, 'condition' => 'hover+@media (hover: hover)', 'properties' => ['color' => 'blue']],
      ['key' => "$key::> *", 'condition' => '@container (min-width: 500px)', 'properties' => ['margin' => '0']],
      ['key' => $key, 'condition' => '@supports (display: grid)', 'properties' => ['gap' => '1rem']],
      ['key' => $key, 'condition' => ':checked', 'properties' => ['color' => 'green']],
    ], $parsed['entries']);

    // What the grammar cannot read is refused on its line with the
    // digester's corrective naming the published wrappers.
    $parsed = $this->door->parse($this->host($this->entity), $key, "color: red;\n@media screen { color: blue; }");
    $this->assertSame(['UNKNOWN_WRAPPER'], array_column($parsed['errors'], 'code'));
    $this->assertSame(2, $parsed['errors'][0]['context']['line']);
    $this->assertStringContainsString('@container (max-width: 767px)  (narrow)', $parsed['errors'][0]['hint']);
    $this->assertSame([['key' => $key, 'condition' => 'default', 'properties' => ['color' => 'red']]], $parsed['entries']);

    $storage = $this->entityTypeManager->getStorage('entity_test');
    $storage->resetCache([$this->entity->id()]);
    $this->assertSame([], $this->container->get('daedalus_blueprint.node_tree_builder')->styleMap($this->host($storage->load($this->entity->id()))), 'The door writes nothing.');
  }

  /**
   * A rule key: the text is the rule's block, its wear peeled off.
   *
   * Under `#id { selector { … } }` the plain declarations are the rule's
   * default entry, `&:hover` its state, and `class: section` the variant the
   * rule wears (the parser's variants tuple), answered apart from the
   * entries because the client emits it as apply_variant.
   */
  public function testRuleKeyAndItsWear(): void {
    $key = "container:{$this->section}::> d-container";
    $parsed = $this->door->parse($this->host($this->entity), $key, "class: section;\ngap: 2rem;\n&:hover { gap: 3rem; }");
    $this->assertSame([], $parsed['errors']);
    $this->assertSame([['key' => $key, 'variant' => 'section']], $parsed['wear']);
    $this->assertSame([
      ['key' => $key, 'condition' => 'default', 'properties' => ['gap' => '2rem']],
      ['key' => $key, 'condition' => 'hover', 'properties' => ['gap' => '3rem']],
    ], $parsed['entries']);
  }

  /**
   * Wear in a nested rule under an element key; wear on the element refused.
   */
  public function testRuleWearUnderAnElementKey(): void {
    $key = "container:{$this->section}";
    $parsed = $this->door->parse($this->host($this->entity), $key, "> d-container { class: section; gap: 1rem; }");
    $this->assertSame([], $parsed['errors']);
    $this->assertSame([['key' => "$key::> d-container", 'variant' => 'section']], $parsed['wear']);
    $this->assertSame([
      ['key' => "$key::> d-container", 'condition' => 'default', 'properties' => ['gap' => '1rem']],
    ], $parsed['entries']);

    // The element wears on its own tag, never in its block: the parser's
    // rule, answered as a corrective and the rest of the text still read.
    $parsed = $this->door->parse($this->host($this->entity), $key, "class: section;\ncolor: red;");
    $this->assertSame(['WEAR_ON_ELEMENT'], array_column($parsed['errors'], 'code'));
    $this->assertSame([], $parsed['wear']);
    $this->assertSame([['key' => $key, 'condition' => 'default', 'properties' => ['color' => 'red']]], $parsed['entries']);
  }

  /**
   * A copied block re-roots onto the key it is pasted into.
   *
   * "Copy CSS" prints `#<uuid>` rules with the element's words as a
   * comment and the conditioned rules under wrappers; pasted onto another
   * element every rule-level `#id` becomes `&`, so the block lands on the
   * pasted-into key instead of failing on a foreign id.
   */
  public function testRerootsCopiedBlocks(): void {
    $key = "container:{$this->section}";
    $other = self::OTHER;
    $parsed = $this->door->parse($this->host($this->entity), $key, "/* Section: Other */\n#$other { padding: 0; }\n#$other:hover { padding: 1rem; }\n@container (max-width: 767px) {\n  #$other > * { margin: 0; }\n}");
    $this->assertSame([], $parsed['errors']);
    $this->assertSame([
      ['key' => $key, 'condition' => 'default', 'properties' => ['padding' => '0']],
      ['key' => $key, 'condition' => 'hover', 'properties' => ['padding' => '1rem']],
      ['key' => "$key::> *", 'condition' => 'narrow', 'properties' => ['margin' => '0']],
    ], $parsed['entries']);
  }

  /**
   * A corrective carries the line of the sent text, and the rest still reads.
   *
   * The digester's refusal (a pseudo-element of another name) arrives as
   * the parser's BAD_SELECTOR with the digester's hint; an unpublished
   * pseudo-class (`&:sparkle`) is no refusal here any more: it stays a
   * qualifier of the element's own key, and whether the site admits the
   * word is the validator's verdict on the write (STYLE_SELECTOR_UNKNOWN).
   */
  public function testCorrectiveCarriesItsLine(): void {
    $key = "container:{$this->section}";
    // Any pseudo-element the browser spells parses (`&::sparkle` is an
    // entry at the box key; the validator refuses the WRITE as
    // STYLE_SELECTOR_UNKNOWN, the door runs no validator); a functional
    // one is the digester's refusal, carrying its line.
    $parsed = $this->door->parse($this->host($this->entity), $key, "color: red;\n\n&::sparkle { color: blue; }");
    $this->assertSame([], $parsed['errors']);
    $this->assertSame([
      ['key' => $key, 'condition' => 'default', 'properties' => ['color' => 'red']],
      ['key' => $key . '::&::sparkle', 'condition' => 'default', 'properties' => ['color' => 'blue']],
    ], $parsed['entries']);
    $parsed = $this->door->parse($this->host($this->entity), $key, "color: red;\n\n&::part(label) { color: blue; }");
    $this->assertCount(1, $parsed['errors']);
    $error = $parsed['errors'][0];
    $this->assertSame('css', $error['path']);
    $this->assertSame('BAD_SELECTOR', $error['code']);
    $this->assertSame(3, $error['context']['line']);
    $this->assertStringEndsWith('(line 3)', $error['message']);
    $this->assertStringStartsWith('Style a pseudo-element by its bare name', $error['hint']);
    $this->assertSame([['key' => $key, 'condition' => 'default', 'properties' => ['color' => 'red']]], $parsed['entries']);

    // The door parses only: an unpublished pseudo-class on the anchor
    // peels as a pseudo-class literal clause (`:sparkle`, a word the
    // validator refuses at the write with the nearest states), one on a
    // compound before the last stays its qualifier, a list folds into
    // one `:is()` rule (the digester's fold, one entry).
    $parsed = $this->door->parse($this->host($this->entity), $key, "&:sparkle { color: blue; }\nli:hover > ul { display: block; }\nh1, h2 { margin: 0; }");
    $this->assertSame([], $parsed['errors']);
    $this->assertSame([
      ['key' => $key, 'condition' => ':sparkle', 'properties' => ['color' => 'blue']],
      ['key' => "$key::li:hover > ul", 'condition' => 'default', 'properties' => ['display' => 'block']],
      ['key' => "$key::*:is(h1, h2)", 'condition' => 'default', 'properties' => ['margin' => '0']],
    ], $parsed['entries']);
  }

  /**
   * A key naming no element, the wrong kind, or no key at all is refused.
   */
  public function testRefusesStrangersAndMalformedKeys(): void {
    $parsed = $this->door->parse($this->host($this->entity), 'container:' . self::OTHER, 'color: red;');
    $this->assertSame(['ELEMENT_NOT_FOUND'], array_column($parsed['errors'], 'code'));
    $this->assertSame([], $parsed['entries']);

    $parsed = $this->door->parse($this->host($this->entity), "block:{$this->section}", 'color: red;');
    $this->assertSame(['BAD_KEY'], array_column($parsed['errors'], 'code'));
    $this->assertStringContainsString('is a container', $parsed['errors'][0]['message']);

    $parsed = $this->door->parse($this->host($this->entity), "container:{$this->section}::>>", 'color: red;');
    $this->assertSame(['BAD_KEY'], array_column($parsed['errors'], 'code'));

    $parsed = $this->door->parse($this->host($this->entity), 'entity:' . $this->entity->uuid(), 'color: red;');
    $this->assertSame(['BAD_KEY'], array_column($parsed['errors'], 'code'));
  }

}
