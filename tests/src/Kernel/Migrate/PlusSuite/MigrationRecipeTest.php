<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Kernel\Migrate\PlusSuite;

use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Config\DatabaseStorage;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the migration recipe over a translated site.
 *
 * The site after the rename phase and `drush updb`, in miniature: the
 * foundation and the renamed engine modules installed, and the image block
 * type the old suite shipped (the type, its media reference field storage
 * and field, in the old shape without the url field daedalus's image block
 * adds). The recipe installs the styling family and the three block modules,
 * imports their config without touching what the site already carries, adds
 * the url field, activates olivero, and applies a second time changing
 * nothing.
 *
 * @group daedalus
 */
#[Group('daedalus')]
#[RunTestsInSeparateProcesses]
final class MigrationRecipeTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * The modules the recipe installs.
   */
  private const INSTALLED = [
    'daedalus_stylish',
    'daedalus_stylish_layouts',
    'daedalus_stylish_layers',
    'daedalus_stylish_olivero_skin',
    'daedalus_edit_text_block',
    'daedalus_edit_image_block',
    'daedalus_edit_formatted_text_block',
  ];

  /**
   * The old suite's image block objects, as the site carries them.
   */
  private const OLD_IMAGE_OBJECTS = [
    'block_content.type.image',
    'field.storage.block_content.field_image',
    'field.field.block_content.image.field_image',
  ];

  /**
   * The raw active configuration storage; see storage().
   */
  private DatabaseStorage $storage;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'file',
    'image',
    'media',
    'navigation',
    'editor',
    'path',
    'twig_events',
    'field_sample_value',
    'daedalus',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_tempstore',
    'daedalus_ui',
    'daedalus_edit',
    'daedalus_layout',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('block_content');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    // The settings object the rename's foundation install created.
    $this->installConfig(['daedalus_blueprint']);
    // The shipped-definition importers switch to an administrative account.
    $this->setUpCurrentUser([], [], TRUE);

    // The old suite's image block: a media reference field on a type with no
    // third-party settings, and no url field.
    BlockContentType::create([
      'id' => 'image',
      'label' => 'Image',
      'description' => 'A media library image field.',
    ])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_image',
      'entity_type' => 'block_content',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_image',
      'entity_type' => 'block_content',
      'bundle' => 'image',
      'label' => 'Image',
      'settings' => [
        'handler' => 'default:media',
        'handler_settings' => [
          'target_bundles' => ['image' => 'image'],
          'sort' => ['field' => '_none', 'direction' => 'ASC'],
          'auto_create' => FALSE,
          'auto_create_bundle' => '',
        ],
      ],
    ])->save();
  }

  /**
   * The recipe converges a translated site and applies again cleanly.
   */
  public function testApply(): void {
    $old_image = $this->storage()->readMultiple(self::OLD_IMAGE_OBJECTS);
    $this->assertCount(3, $old_image);
    foreach (self::INSTALLED as $module) {
      $this->assertFalse($this->container->get('module_handler')->moduleExists($module), "$module is not installed before the recipe");
    }
    $this->assertNull($this->config('daedalus_blueprint.settings')->get('active_design_system'));

    $this->apply();

    foreach (self::INSTALLED as $module) {
      $this->assertTrue($this->container->get('module_handler')->moduleExists($module), "$module is installed by the recipe");
    }
    $this->assertSame($old_image, $this->storage()->readMultiple(self::OLD_IMAGE_OBJECTS), 'the old suite\'s image objects are untouched');

    $url_storage = FieldStorageConfig::loadByName('block_content', 'field_image_url');
    $this->assertNotNull($url_storage, 'the url field storage is added');
    $this->assertSame('link', $url_storage->getType());
    $this->assertTrue($this->container->get('database')->schema()->tableExists('block_content__field_image_url'));
    $this->assertNotNull(FieldConfig::loadByName('block_content', 'image', 'field_image_url'), 'the url field is added to the image type');
    $form_display = $this->storage()->read('core.entity_form_display.block_content.image.default');
    $this->assertIsArray($form_display, 'the image form display the site lacked is imported');
    $this->assertArrayHasKey('field_image_url', $form_display['content']);

    $this->assertNotNull(BlockContentType::load('text'));
    $this->assertNotNull(BlockContentType::load('formatted_text'));
    $this->assertNotNull(FieldStorageConfig::loadByName('block_content', 'field_text_text'));
    $this->assertTrue($this->storage()->exists('filter.format.daedalus_edit_formatted_text'));

    $this->assertTrue($this->storage()->exists('daedalus_stylish.settings'));
    $this->assertTrue($this->storage()->exists('daedalus_stylish_layers.settings'));
    $this->assertTrue($this->storage()->exists('daedalus_blueprint.design_system.olivero'));
    $this->assertSame('olivero', $this->config('daedalus_blueprint.settings')->get('active_design_system'));
    $skins = $this->container->get('entity_type.manager')->getStorage('daedalus_skin')->loadByProperties(['machine_name' => 'olivero']);
    $this->assertCount(1, $skins, 'the activation sweep landed the shipped olivero skin');

    // A second apply: no module, no config object and no value changes.
    $modules = array_keys($this->container->get('module_handler')->getModuleList());
    $config = $this->storage()->readMultiple($this->storage()->listAll());
    $this->apply();
    $this->assertSame($modules, array_keys($this->container->get('module_handler')->getModuleList()));
    $this->assertSame($config, $this->storage()->readMultiple($this->storage()->listAll()));
  }

  /**
   * The active configuration, read from its table.
   *
   * Not the container's cached storage: the installs read settings objects
   * before the import creates them, and the cache keeps those misses.
   */
  private function storage(): DatabaseStorage {
    return $this->storage ??= new DatabaseStorage($this->container->get('database'), 'config');
  }

  /**
   * Applies the package's migration recipe, as migrate.sh does through drush.
   */
  private function apply(): void {
    RecipeRunner::processRecipe(Recipe::createFromDirectory(dirname(__DIR__, 5) . '/migrate/plus_suite'));
  }

}
