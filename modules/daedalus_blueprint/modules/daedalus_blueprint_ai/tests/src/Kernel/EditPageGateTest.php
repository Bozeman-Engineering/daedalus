<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\Edit;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Eb_edit leaves the page: pages are refused toward page_edit.
 *
 * On a site where the page language speaks for pages (the catalog
 * declares a container layout), any entity carrying the layout field is
 * page_edit's whole surface — structure, content, styling, variants,
 * stencil gestures — so eb_edit refuses it with one corrective naming
 * page_edit, before any operation runs. Entities the page language does
 * NOT speak for (no layout field on the bundle) keep the JSON door: the
 * same site still edits them through eb_edit. The predicate is the
 * entity's layout field plus the site's container layout, never the
 * presence of sections: an empty page is still a page.
 */
#[CoversClass(Edit::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class EditPageGateTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. 'key' is an ai dependency;
   * daedalus_blueprint_test ships the eb_test_container layout the real
   * catalog builder derives containerLayoutId from.
   */
  protected static $modules = [
    'key',
    'ai',
    'daedalus_blueprint_ai',
    'daedalus_blueprint_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The page-language site shape: layout builder overridable on
    // entity_test.entity_test (the bundle grows layout_builder__layout)
    // and a discoverable container layout (eb_test_container).
    $this->enableLayoutBuilder();
  }

  /**
   * Runs eb_edit and returns its readable output.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $entity_id
   *   The entity ID.
   *
   * @return string
   *   The tool's readable output.
   */
  protected function runEdit(string $entity_type, string $entity_id): string {
    $tool = $this->container->get('plugin.manager.ai.function_calls')
      ->getFunctionCallFromFunctionName('eb_edit');
    $this->assertNotNull($tool, 'The eb_edit tool is available.');
    $tool->setContextValue('entity_type', $entity_type);
    $tool->setContextValue('entity_id', $entity_id);
    $tool->setContextValue('save', TRUE);
    $tool->setContextValue('operations', [
      ['op' => 'update_element', 'properties' => ['name' => 'Renamed']],
    ]);
    $tool->execute();
    return $tool->getReadableOutput();
  }

  /**
   * A page entity is refused with one corrective naming page_edit.
   *
   * Even a legal root-field op is refused: the gate is on the entity, not
   * the op list, because a page has ONE grammar and set with ref "root"
   * spells the same gesture inside it.
   */
  public function testPageEntityIsRefusedTowardPageEdit(): void {
    $storage = $this->entityTypeManager->getStorage('entity_test');
    $page = $storage->create(['name' => 'Host']);
    $page->save();

    $output = $this->runEdit('entity_test', (string) $page->id());

    $this->assertStringContainsString('is a page', $output);
    $this->assertStringContainsString('page_edit', $output);
    $this->assertStringNotContainsString('success: true', $output);
    // Nothing ran: the rename did not land.
    $storage->resetCache();
    $this->assertSame('Host', $storage->load($page->id())->get('name')->value);
  }

  /**
   * A non-page entity on the same site still edits through eb_edit.
   */
  public function testNonPageEntityStillEdits(): void {
    $storage = $this->entityTypeManager->getStorage('entity_test_rev');
    $entity = $storage->create(['name' => 'Host']);
    $entity->save();

    $output = $this->runEdit('entity_test_rev', (string) $entity->id());

    $this->assertStringContainsString('success: true', $output);
    $this->assertStringNotContainsString('page_edit', $output);
    $storage->resetCache();
    $this->assertSame('Renamed', $storage->load($entity->id())->get('name')->value);
  }

}
