<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\Core\Config\Schema\SchemaIncompleteException;
use Drupal\daedalus_ui\Form\BundleEditFormAlter;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\taxonomy\Entity\Vocabulary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The page builder's bundle settings validate beyond node and block types.
 *
 * One schema type (`daedalus_ui.bundle.options`: `enabled`, `initial_mode`,
 * `presentation`) is named for each bundle config type of core's
 * page-capable content entity types, so a bundle of a third entity type
 * (a vocabulary here) validates its `daedalus_ui` third-party settings
 * under the kernel's strict schema checker, the form's probe answers yes
 * for it and no for a bundle config type nothing names, and the retired
 * per-mode `status` map is refused everywhere.
 */
#[CoversClass(BundleEditFormAlter::class)]
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class BundleSettingsSchemaTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The module's hard dependencies (daedalus_blueprint + daedalus_blueprint_api)
   * are invisible to kernel environments and must be listed explicitly or
   * the container fails to compile.
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'taxonomy',
    'entity_test',
    'field',
    'text',
    'filter',
    'link',
    'options',
    'file',
    'image',
    'media',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
  }

  /**
   * A vocabulary saves the settings under strict schema; the probe agrees.
   */
  public function testThirdEntityTypeBundleValidates(): void {
    $entity_type_manager = $this->container->get('entity_type.manager');
    /** @var \Drupal\daedalus_ui\Form\BundleEditFormAlter $form_alter */
    $form_alter = $this->container->get('daedalus_ui.form_alter.bundle_edit');
    foreach (['taxonomy_vocabulary', 'node_type', 'block_content_type', 'media_type'] as $bundle_entity_type) {
      $this->assertTrue($form_alter->hasSettingsSchema($entity_type_manager->getDefinition($bundle_entity_type)), "The settings schema is named for $bundle_entity_type.");
    }
    // A bundle config type nothing names: the fallback resolution would
    // answer its own mapping, and the probe must not read that as a yes.
    $this->assertFalse($form_alter->hasSettingsSchema($entity_type_manager->getDefinition('entity_test_bundle')));

    // The kernel's strict schema checker refuses the save of any key the
    // schema does not describe, so a clean save is the proof.
    Vocabulary::create(['vid' => 'landing', 'name' => 'Landing'])
      ->setThirdPartySetting('daedalus_ui', 'enabled', TRUE)
      ->setThirdPartySetting('daedalus_ui', 'initial_mode', 'edit')
      ->save();

    $vocabulary = Vocabulary::load('landing');
    $this->assertTrue($vocabulary->getThirdPartySetting('daedalus_ui', 'enabled'));
    $this->assertSame('edit', $vocabulary->getThirdPartySetting('daedalus_ui', 'initial_mode'));

    // The stored value is typed as the schema says, through the type's own
    // name (the probe's exact match) and the shared mapping's keys.
    $settings = $this->container->get('config.typed')->get('taxonomy.vocabulary.landing')->get('third_party_settings.daedalus_ui');
    $this->assertSame('taxonomy.vocabulary.*.third_party.daedalus_ui', $settings->getDataDefinition()->getDataType());
    $this->assertSame('boolean', $settings->get('enabled')->getDataDefinition()->getDataType());
    $this->assertSame(['enabled', 'initial_mode', 'presentation'], array_keys($settings->getDataDefinition()['mapping']));
  }

  /**
   * The retired per-mode status map is refused, on a node type too.
   */
  public function testRetiredStatusMapIsRefused(): void {
    $this->expectException(SchemaIncompleteException::class);
    $this->expectExceptionMessage('third_party_settings.daedalus_ui.status');
    NodeType::create(['type' => 'legacy', 'name' => 'Legacy'])
      ->setThirdPartySetting('daedalus_ui', 'status', ['edit' => TRUE])
      ->save();
  }

}
