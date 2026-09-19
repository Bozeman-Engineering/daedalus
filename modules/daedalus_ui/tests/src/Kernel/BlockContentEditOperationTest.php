<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\block\Entity\Block;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the "Edit layout" operation on the block layout listing.
 *
 * A block config placement whose plugin wraps a Layout Builder-enabled
 * block_content (the bundle display has overrides enabled, so the entity
 * carries the layout field) is the entry point to that entity's standalone
 * editing surface. The operation must appear exactly there: not on
 * placements of non-LB bundles, not on non-block_content plugins, and not
 * for users who cannot update the content entity. The URL must come from
 * the editing-surface derivation (the daedalus_ui.block_content.edit
 * route), not the entity's canonical route.
 */
#[RunTestsInSeparateProcesses]
class BlockContentEditOperationTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * Navigation_plus's hard dependencies (daedalus_blueprint +
   * daedalus_blueprint_api) are invisible to kernel environments and must be
   * listed explicitly or the container fails to compile.
   */
  protected static $modules = [
    'system',
    'user',
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
   * A placement wrapping an LB-enabled block_content.
   */
  protected Block $footerPlacement;

  /**
   * A placement wrapping a block_content of a bundle without Layout Builder.
   */
  protected Block $plainPlacement;

  /**
   * The LB-enabled block_content the footer placement wraps.
   */
  protected BlockContent $footer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('block_content');
    $this->container->get('theme_installer')->install(['stark']);

    // An LB-enabled bundle: enabling overrides on the display creates the
    // layout_builder__layout field, the fact the operation gates on.
    BlockContentType::create([
      'id' => 'layout_block',
      'label' => 'Layout block',
    ])->save();
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'block_content',
      'bundle' => 'layout_block',
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->setOverridable()->save();

    // A bundle without Layout Builder.
    BlockContentType::create([
      'id' => 'basic',
      'label' => 'Basic',
    ])->save();

    // The block_content deriver only exposes reusable entities, so the
    // placements' plugin ids resolve.
    $this->footer = BlockContent::create([
      'type' => 'layout_block',
      'info' => 'Footer',
      'reusable' => TRUE,
    ]);
    $this->footer->save();
    $plain = BlockContent::create([
      'type' => 'basic',
      'info' => 'Plain',
      'reusable' => TRUE,
    ]);
    $plain->save();
    $this->container->get('plugin.manager.block')->clearCachedDefinitions();

    $this->footerPlacement = Block::create([
      'id' => 'stark_footer',
      'theme' => 'stark',
      'plugin' => 'block_content:' . $this->footer->uuid(),
      'region' => 'content',
    ]);
    $this->footerPlacement->save();
    $this->plainPlacement = Block::create([
      'id' => 'stark_plain',
      'theme' => 'stark',
      'plugin' => 'block_content:' . $plain->uuid(),
      'region' => 'content',
    ]);
    $this->plainPlacement->save();
  }

  /**
   * Gets the listing operations for a block config entity.
   */
  protected function getOperations(Block $block): array {
    return $this->container->get('entity_type.manager')
      ->getListBuilder('block')
      ->getOperations($block);
  }

  /**
   * The operation appears exactly on LB-enabled block_content placements.
   */
  public function testEditLayoutOperation(): void {
    $this->setUpCurrentUser([], ['administer blocks', 'administer block content']);

    $operations = $this->getOperations($this->footerPlacement);
    $this->assertArrayHasKey('edit-layout', $operations);
    $this->assertSame(
      '/block-content/' . $this->footer->id(),
      $operations['edit-layout']['url']->toString(),
      'The operation links to the standalone editing surface, not the admin canonical route.'
    );

    // A block_content without the layout field gets no operation.
    $this->assertArrayNotHasKey('edit-layout', $this->getOperations($this->plainPlacement));

    // A non-block_content plugin gets no operation.
    $system_block = Block::create([
      'id' => 'stark_branding',
      'theme' => 'stark',
      'plugin' => 'system_powered_by_block',
      'region' => 'content',
    ]);
    $system_block->save();
    $this->assertArrayNotHasKey('edit-layout', $this->getOperations($system_block));
  }

  /**
   * No operation without update access on the content entity.
   */
  public function testEditLayoutOperationRequiresUpdateAccess(): void {
    $this->setUpCurrentUser([], ['administer blocks']);
    $this->assertArrayNotHasKey('edit-layout', $this->getOperations($this->footerPlacement));
  }

}
