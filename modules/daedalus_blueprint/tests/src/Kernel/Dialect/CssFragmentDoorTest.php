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
    // provider reads all four storages.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
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
      'label' => 'Band',
      'machine_name' => 'band',
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
    $parsed = $this->door->parse($this->entity, $key, "color: red;\n&:hover { color: blue; }\n@container (max-width: 767px) {\n  &:hover { color: green; }\n}\n> pb-container { gap: 1rem; }\n> * { margin: 0; }");
    $this->assertSame([], $parsed['errors']);
    $this->assertSame([], $parsed['wear']);
    $this->assertSame([
      ['key' => $key, 'condition' => 'default', 'properties' => ['color' => 'red']],
      ['key' => $key, 'condition' => 'hover', 'properties' => ['color' => 'blue']],
      ['key' => $key, 'condition' => 'hover+narrow', 'properties' => ['color' => 'green']],
      ['key' => "$key::> pb-container", 'condition' => 'default', 'properties' => ['gap' => '1rem']],
      ['key' => "$key::> *", 'condition' => 'default', 'properties' => ['margin' => '0']],
    ], $parsed['entries']);

    $storage = $this->entityTypeManager->getStorage('entity_test');
    $storage->resetCache([$this->entity->id()]);
    $this->assertSame([], $this->container->get('daedalus_blueprint.node_tree_builder')->styleMap($storage->load($this->entity->id())), 'The door writes nothing.');
  }

  /**
   * A rule key: the text is the rule's block, its wear peeled off.
   *
   * Under `#id { selector { … } }` the plain declarations are the rule's
   * default entry, `&:hover` its state, and `class: band` the variant the
   * rule wears (the parser's variants tuple), answered apart from the
   * entries because the client emits it as apply_variant.
   */
  public function testRuleKeyAndItsWear(): void {
    $key = "container:{$this->section}::> pb-container";
    $parsed = $this->door->parse($this->entity, $key, "class: band;\ngap: 2rem;\n&:hover { gap: 3rem; }");
    $this->assertSame([], $parsed['errors']);
    $this->assertSame([['key' => $key, 'variant' => 'band']], $parsed['wear']);
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
    $parsed = $this->door->parse($this->entity, $key, "> pb-container { class: band; gap: 1rem; }");
    $this->assertSame([], $parsed['errors']);
    $this->assertSame([['key' => "$key::> pb-container", 'variant' => 'band']], $parsed['wear']);
    $this->assertSame([
      ['key' => "$key::> pb-container", 'condition' => 'default', 'properties' => ['gap' => '1rem']],
    ], $parsed['entries']);

    // The element wears on its own tag, never in its block: the parser's
    // rule, answered as a corrective and the rest of the text still read.
    $parsed = $this->door->parse($this->entity, $key, "class: band;\ncolor: red;");
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
    $parsed = $this->door->parse($this->entity, $key, "/* Section: Other */\n#$other { padding: 0; }\n#$other:hover { padding: 1rem; }\n@container (max-width: 767px) {\n  #$other > * { margin: 0; }\n}");
    $this->assertSame([], $parsed['errors']);
    $this->assertSame([
      ['key' => $key, 'condition' => 'default', 'properties' => ['padding' => '0']],
      ['key' => $key, 'condition' => 'hover', 'properties' => ['padding' => '1rem']],
      ['key' => "$key::> *", 'condition' => 'narrow', 'properties' => ['margin' => '0']],
    ], $parsed['entries']);
  }

  /**
   * A corrective carries the line of the sent text, and the rest still reads.
   */
  public function testUnknownStateCorrectiveCarriesItsLine(): void {
    $key = "container:{$this->section}";
    $parsed = $this->door->parse($this->entity, $key, "color: red;\n\n&:sparkle { color: blue; }");
    $this->assertCount(1, $parsed['errors']);
    $error = $parsed['errors'][0];
    $this->assertSame('css', $error['path']);
    $this->assertSame('UNKNOWN_STATE', $error['code']);
    $this->assertSame(3, $error['context']['line']);
    $this->assertStringEndsWith('(line 3)', $error['message']);
    $this->assertStringStartsWith('Legal states:', $error['hint']);
    $this->assertSame([['key' => $key, 'condition' => 'default', 'properties' => ['color' => 'red']]], $parsed['entries']);
  }

  /**
   * A key naming no element, the wrong kind, or no key at all is refused.
   */
  public function testRefusesStrangersAndMalformedKeys(): void {
    $parsed = $this->door->parse($this->entity, 'container:' . self::OTHER, 'color: red;');
    $this->assertSame(['ELEMENT_NOT_FOUND'], array_column($parsed['errors'], 'code'));
    $this->assertSame([], $parsed['entries']);

    $parsed = $this->door->parse($this->entity, "block:{$this->section}", 'color: red;');
    $this->assertSame(['BAD_KEY'], array_column($parsed['errors'], 'code'));
    $this->assertStringContainsString('is a container', $parsed['errors'][0]['message']);

    $parsed = $this->door->parse($this->entity, "container:{$this->section}::>>", 'color: red;');
    $this->assertSame(['BAD_KEY'], array_column($parsed['errors'], 'code'));

    $parsed = $this->door->parse($this->entity, 'entity:' . $this->entity->uuid(), 'color: red;');
    $this->assertSame(['BAD_KEY'], array_column($parsed['errors'], 'code'));
  }

}
