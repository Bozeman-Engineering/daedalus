<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit_page\Kernel;

use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the page content type installs.
 *
 * Config is imported with strict schema checking, so this exercises the
 * shipped node type, layout field, and view display end to end. The AI
 * design guidance rides module-only daedalus_blueprint context discovery
 * (the reason this is a module rather than recipe config), so its
 * discovery is asserted here too.
 */
#[Group('daedalus_edit_page')]
#[RunTestsInSeparateProcesses]
class InstallTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'filter',
    'text',
    'editor',
    'menu_ui',
    'block',
    'layout_discovery',
    'layout_builder',
    'daedalus_blueprint',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_layout',
    'field_sample_value',
    'daedalus_edit',
    'daedalus_stylish',
    'daedalus_stylish_layouts',
    'daedalus_edit_page',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['daedalus_edit_page']);
  }

  /**
   * The node type, layout field, and view display install.
   */
  public function testPageConfigInstalls(): void {
    $node_type = NodeType::load('page');
    $this->assertNotNull($node_type);
    // The page builder is on for the bundle: one boolean, and the mode it
    // opens new pages in.
    $daedalus_ui = $node_type->getThirdPartySettings('daedalus_ui');
    $this->assertTrue($daedalus_ui['enabled']);
    $this->assertSame('edit', $daedalus_ui['initial_mode']);
    $this->assertSame(['enabled', 'initial_mode'], array_keys($daedalus_ui));
    $this->assertSame(['footer', 'main'], $node_type->getThirdPartySetting('menu_ui', 'available_menus'));
    // Previewing happens in place through the page builder; core's preview
    // button is disabled so it cannot detour out of it.
    $this->assertSame(DRUPAL_DISABLED, $node_type->get('preview_mode'));
    $this->assertFalse($node_type->displaySubmitted());

    $this->assertNotNull(FieldStorageConfig::load('node.layout_builder__layout'));
    $this->assertNotNull(FieldConfig::load('node.page.layout_builder__layout'));

    $display = EntityViewDisplay::load('node.page.default');
    $this->assertNotNull($display);
    $this->assertTrue($display->getThirdPartySetting('layout_builder', 'enabled'));
    // Nothing is curated per display: a block type's favorite is its own
    // setting (the three shipped block types carry it), so the display
    // holds no daedalus_layout settings at all.
    $this->assertSame([], $display->getThirdPartySettings('daedalus_layout'));
    $this->assertNotContains('daedalus_layout', $display->getDependencies()['module'] ?? []);
  }

  /**
   * The AI design guidance is discovered through the context resolver.
   */
  public function testAiContextDiscovery(): void {
    $resolver = $this->container->get('daedalus_blueprint.context_resolver');

    $guidance = $resolver->getEntityGuidance('node', 'page');
    $this->assertNotNull($guidance);
    $this->assertStringContainsString('general-purpose standalone page', $guidance);

    $this->assertNotEmpty($resolver->getLayoutContext('node', 'page', 'daedalus_stylish_container')['guidance']);
  }

}
