<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Unit\Migrate\PlusSuite;

use Drupal\Core\Config\Checkpoint\Checkpoint;
use Drupal\daedalus\Migrate\PlusSuite\Mapping;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the rename mapping on the strings a migrating site stores.
 *
 * The survey strings are the trial site's (plus-suite-to-daedalus-migration,
 * 2026-09-19): config names, a base field's column, a custom module's import,
 * a stored asset path and role permissions. The table under test is the one
 * the package ships, migrate/plus_suite/mapping.tsv, so this class is also the proof
 * that the PHP reader and the code-rename script agree on its grammar.
 *
 * @group daedalus
 */
#[Group('daedalus')]
final class MappingTest extends UnitTestCase {

  /**
   * The mapping under test, read from the table the package ships.
   */
  private Mapping $mapping;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->mapping = Mapping::fromFile();
  }

  /**
   * The survey strings and what a migrated site must carry instead.
   */
  public static function surveyProvider(): array {
    return [
      'module' => ['lb_plus', 'daedalus_layout'],
      'config name' => ['lb_plus.settings', 'daedalus_layout.settings'],
      'navigation_plus config name' => ['navigation_plus.settings', 'daedalus_ui.settings'],
      'base field column' => ['navigation_plus_settings', 'daedalus_ui_settings'],
      'storage schema key' => [
        'user.field_schema_data.navigation_plus_settings',
        'user.field_schema_data.daedalus_ui_settings',
      ],
      'custom module import' => [
        'Drupal\edit_plus\Event\BlockPropertiesEvent',
        'Drupal\daedalus_edit\Event\BlockPropertiesEvent',
      ],
      'info.yml dependency' => ['edit_plus:edit_plus', 'daedalus_edit:daedalus_edit'],
      'asset path' => [
        '/modules/contrib/lb_plus/assets/heading.svg',
        '/modules/contrib/daedalus/modules/daedalus_layout/assets/heading.svg',
      ],
      'image block asset path' => [
        '/modules/contrib/edit_plus/modules/edit_plus_image_block/assets/image.svg',
        '/modules/contrib/daedalus/modules/daedalus_edit/modules/daedalus_edit_image_block/assets/image.svg',
      ],
      'lb_plus permission' => ['administer layout builder + configuration', 'administer daedalus layout configuration'],
      'lb_plus promote permission' => ['promote layout builder + blocks', 'promote daedalus layout blocks'],
      'navigation_plus 2.3 permission unchanged' => ['use toolbar plus edit mode', 'use toolbar plus edit mode'],
      'navigation_plus 2.3 configure permission unchanged' => [
        'configure toolbar plus modes',
        'configure toolbar plus modes',
      ],
      'already renamed' => ['daedalus_layout', 'daedalus_layout'],
      'config hash with np- inside untouched' => [
        'hvVxL1G-ZCxaq32IZws0YsfuhvaDiQE_np-0g35KjUk',
        'hvVxL1G-ZCxaq32IZws0YsfuhvaDiQE_np-0g35KjUk',
      ],
      'config hash with npX inside untouched' => [
        '_core: {default_config_hash: 4Z2AmsHc5Dmg-npXqDfz9bkBg0Yd7uAmPoXtEXdCtWk}',
        '_core: {default_config_hash: 4Z2AmsHc5Dmg-npXqDfz9bkBg0Yd7uAmPoXtEXdCtWk}',
      ],
      'a 43-character machine name is not a hash' => [
        'entity_blueprint_stencil_owned_variant_test',
        'daedalus_blueprint_stencil_owned_variant_test',
      ],
      'a css prefix after a hyphen' => ['data-np-tooltip', 'data-daedalus-ui-tooltip'],
      'already renamed config' => ['daedalus_ui.settings', 'daedalus_ui.settings'],
      'sub-module' => ['lb_plus_section_library', 'daedalus_layout_section_library'],
      'block sub-module' => ['edit_plus_layout_block', 'daedalus_edit_layout_block'],
      'core plugin id untouched' => ['inline_block:heading', 'inline_block:heading'],
      'separate project untouched' => ['field_sample_value', 'field_sample_value'],
      'js root' => ['Drupal.NavigationPlus', 'Drupal.Daedalus'],
      'checkpoint name' => ['config.checkpoint.lb_plus.settings', 'config.checkpoint.daedalus_layout.settings'],
    ];
  }

  /**
   * Each survey string translates as the site needs, and only once.
   */
  #[DataProvider('surveyProvider')]
  public function testTranslate(string $old, string $new): void {
    $this->assertSame($new, $this->mapping->translate($old));
    $this->assertSame($new, $this->mapping->translate($new), 'Translation is idempotent.');
  }

  /**
   * Arrays translate by value, keys included, leaving other scalars alone.
   */
  public function testTranslateDeepArrays(): void {
    $extension = [
      'module' => ['lb_plus' => 0, 'navigation_plus' => 0, 'node' => 0],
      'theme' => ['claro' => 0],
      'profile' => 'standard',
    ];
    $this->assertSame([
      'module' => ['daedalus_layout' => 0, 'daedalus_ui' => 0, 'node' => 0],
      'theme' => ['claro' => 0],
      'profile' => 'standard',
    ], $this->mapping->translateDeep($extension));

    $display = [
      'dependencies' => ['module' => ['layout_builder', 'lb_plus']],
      'third_party_settings' => [
        'lb_plus' => [
          'default_section' => 'layout_onecol',
          'block_config' => ['icon' => ['inline_block:heading' => '/modules/contrib/lb_plus/assets/heading.svg']],
          'enabled' => TRUE,
          'weight' => 3,
          'nothing' => NULL,
        ],
      ],
    ];
    $translated = $this->mapping->translateDeep($display);
    $this->assertSame(['layout_builder', 'daedalus_layout'], $translated['dependencies']['module']);
    $this->assertSame([
      'default_section' => 'layout_onecol',
      'block_config' => ['icon' => ['inline_block:heading' => '/modules/contrib/daedalus/modules/daedalus_layout/assets/heading.svg']],
      'enabled' => TRUE,
      'weight' => 3,
      'nothing' => NULL,
    ], $translated['third_party_settings']['daedalus_layout']);
    $this->assertArrayNotHasKey('lb_plus', $translated['third_party_settings']);
  }

  /**
   * A stored Section round-trips with its lb_plus TPS under daedalus_layout.
   */
  public function testTranslateDeepSection(): void {
    $component = new SectionComponent('8f6b3b1e-2c3d-4e5f-8a9b-0c1d2e3f4a5b', 'content', [
      'id' => 'inline_block:heading',
      'label' => 'Hello',
      'title_size' => 'h2',
    ], ['lb_plus' => ['promoted' => TRUE]]);
    $section = new Section('layout_onecol', ['label' => 'Intro'], [$component], ['lb_plus' => ['uuid' => 'a1b2c3d4-0000-4000-8000-000000000001']]);

    $translated = $this->mapping->translateDeep($section);

    $this->assertInstanceOf(Section::class, $translated);
    $this->assertNotSame($section, $translated);
    $this->assertSame(['uuid' => 'a1b2c3d4-0000-4000-8000-000000000001'], $translated->getThirdPartySettings('daedalus_layout'));
    $this->assertSame([], $translated->getThirdPartySettings('lb_plus'));
    $this->assertSame(['daedalus_layout'], $translated->getThirdPartyProviders());
    $this->assertSame('layout_onecol', $translated->getLayoutId());
    // getLayoutSettings() instantiates the layout plugin; read the stored
    // value the walk touched instead.
    $this->assertSame(['label' => 'Intro'], (new \ReflectionProperty(Section::class, 'layoutSettings'))->getValue($translated));
    $translated_component = $translated->getComponent('8f6b3b1e-2c3d-4e5f-8a9b-0c1d2e3f4a5b');
    $this->assertSame('8f6b3b1e-2c3d-4e5f-8a9b-0c1d2e3f4a5b', $translated_component->getUuid());
    $this->assertSame('content', $translated_component->getRegion());
    $this->assertSame(['id' => 'inline_block:heading', 'label' => 'Hello', 'title_size' => 'h2'], $translated_component->get('configuration'));
    $this->assertSame(['promoted' => TRUE], $translated_component->get('daedalus_layout'));
    $this->assertNull($translated_component->get('lb_plus'));

    $this->assertSame(['uuid' => 'a1b2c3d4-0000-4000-8000-000000000001'], $section->getThirdPartySettings('lb_plus'), 'The original is untouched.');
    $this->assertEquals($translated, $this->mapping->translateDeep($translated), 'A second walk is a no-op.');
    $serialized = unserialize(serialize($translated), ['allowed_classes' => [Section::class, SectionComponent::class]]);
    $this->assertEquals($translated, $serialized, 'The clone re-serializes cleanly.');
  }

  /**
   * An object graph with shared and circular references is walked once each.
   *
   * A stored BaseFieldDefinition and its item definition point at each
   * other; the walk must end where serialization does, on one clone per
   * object.
   */
  public function testTranslateDeepGraph(): void {
    $shared = new \stdClass();
    $shared->module = 'lb_plus';
    $a = new \stdClass();
    $b = new \stdClass();
    $a->other = $b;
    $a->shared = $shared;
    $b->other = $a;
    $b->shared = $shared;

    $translated = $this->mapping->translateDeep($a);

    $this->assertNotSame($a, $translated);
    $this->assertSame('daedalus_layout', $translated->shared->module);
    $this->assertSame($translated, $translated->other->other, 'The cycle closes on the clone.');
    $this->assertSame($translated->shared, $translated->other->shared, 'A shared object is cloned once.');
    $this->assertSame('lb_plus', $shared->module, 'The original graph is untouched.');
    $this->assertSame($b, $a->other);
  }

  /**
   * An object with readonly properties is rebuilt, not cloned and modified.
   *
   * A site's state carries config.checkpoints, an array of core's readonly
   * Checkpoint value objects whose label can name the old suite; the walk
   * initializes a fresh instance's properties rather than failing on the
   * clone's.
   */
  public function testTranslateDeepReadonly(): void {
    $checkpoint = new Checkpoint('cba4a0a3', "Backup before the 'Plus Suite Theme' recipe.", 1775266091, NULL);

    $translated = $this->mapping->translateDeep(['cba4a0a3' => $checkpoint]);

    $this->assertInstanceOf(Checkpoint::class, $translated['cba4a0a3']);
    $this->assertNotSame($checkpoint, $translated['cba4a0a3']);
    $this->assertSame('cba4a0a3', $translated['cba4a0a3']->id);
    $this->assertSame("Backup before the 'Daedalus Theme' recipe.", $translated['cba4a0a3']->label);
    $this->assertSame(1775266091, $translated['cba4a0a3']->timestamp);
    $this->assertNull($translated['cba4a0a3']->parent);
    $this->assertSame("Backup before the 'Plus Suite Theme' recipe.", $checkpoint->label, 'The original is untouched.');
  }

  /**
   * A serialized string inside a value is walked, its lengths kept right.
   *
   * An inline block component's block_serialized is serialized PHP inside
   * the stored section; a text substitution would break the string lengths
   * inside it. One the walk leaves alone keeps its bytes, and one holding a
   * class not on disk stops the walk naming the class.
   */
  public function testTranslateDeepSerializedString(): void {
    $block = new \stdClass();
    $block->info = 'lb_plus heading';
    $block->settings = ['module' => 'navigation_plus'];
    $untouched = serialize(['label' => 'Plain']);
    $component = ['id' => 'inline_block:heading', 'block_serialized' => serialize($block), 'other' => $untouched];

    $translated = $this->mapping->translateDeep($component);

    $inner = unserialize($translated['block_serialized'], ['allowed_classes' => [\stdClass::class]]);
    $this->assertSame('daedalus_layout heading', $inner->info);
    $this->assertSame(['module' => 'daedalus_ui'], $inner->settings);
    $this->assertSame($untouched, $translated['other']);
    $this->assertSame('lb_plus heading', $block->info, 'The original is untouched.');

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('holds an object of the class Drupal\\edit_plus\\Gone, which is not on disk');
    $this->mapping->translateDeep(['block_serialized' => 'O:21:"Drupal\\edit_plus\\Gone":0:{}']);
  }

  /**
   * The floor rows name the oldest schema version served per old module.
   */
  public function testFloor(): void {
    $this->assertSame([
      'lb_plus' => 10003,
      'navigation_plus' => 10400,
      'edit_plus' => 10001,
      'tempstore_plus' => 11001,
    ], $this->mapping->floor());
  }

  /**
   * The reader enforces the table's grammar rather than guessing at a row.
   */
  public function testGrammar(): void {
    $rows = $this->mapping->rows();
    $this->assertNotEmpty($rows);
    $this->assertSame(['^migrate/plus_suite/', '^tests/src/(Unit|Kernel)/Migrate/PlusSuite/'], array_column($this->mapping->rows('skip'), 'old'));
    $this->assertSame('daedalus_layout', $this->mapping->rows('id')[1]['new']);

    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage("Unknown kind 'rename'");
    Mapping::fromFile($this->table("rename\tlb_plus\tdaedalus_layout\n"));
  }

  /**
   * The literal hits in a text: what the audit lists per custom-code file.
   *
   * Longest first at a position (the namespace before the module), distinct,
   * protect rows masked (a separate project is not a hit), regex rows left
   * out (a data-np-* dataset key is not a literal the table renames).
   */
  public function testHits(): void {
    $source = "use Drupal\\edit_plus\\Event\\BlockPropertiesEvent;\n"
      . "// Rides edit_plus's event; field_sample_value and twig_events stay.\n"
      . "dependencies:\n  - edit_plus:edit_plus\n  - navigation_plus:navigation_plus\n"
      . "mount.dataset.npTreeMounted; Drupal.NavigationPlus.foo; daedalus_layout;\n";
    $this->assertSame(['Drupal\edit_plus', 'edit_plus', 'navigation_plus', 'Drupal.NavigationPlus'], $this->mapping->hits($source));
    $this->assertSame([], $this->mapping->hits('daedalus_layout and daedalus_ui.settings, twig_events'));
  }

  /**
   * A row without a `new` cell is a bad line, as it is for the script.
   */
  public function testShortRow(): void {
    $this->expectException(\UnexpectedValueException::class);
    $this->expectExceptionMessage('Bad table line 2');
    Mapping::fromFile($this->table("# comment\nid\tlb_plus\n"));
  }

  /**
   * Writes a throwaway table for the grammar tests.
   */
  private function table(string $contents): string {
    $path = tempnam(sys_get_temp_dir(), 'mapping');
    file_put_contents($path, $contents);
    return $path;
  }

}
