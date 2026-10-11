<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel\Capability;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\Core\Session\AccountInterface;
use Drupal\daedalus_blueprint\Capability\Policy\RolePolicy;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The role policy: structure and inline block content follow core's grammar.
 *
 * On a content host each of core's three Layout Builder override
 * permissions opens the document's structure (arrange, insert, configure)
 * to an actor who may update the host; with none of them structure is
 * refused while the host's own fields stay open. An inline block's fields
 * are content behind core's `create and edit custom blocks`, whatever the
 * structure grant. A template is open only to an actor who may update the
 * display and holds `configure any layout`.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(RolePolicy::class)]
#[Group('daedalus_blueprint')]
class RolePolicyTest extends BlueprintKernelTestBase {

  /**
   * The blueprint operations service.
   */
  protected object $operations;

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
      'daedalus_ui',
      'daedalus_layout',
      // The display's update access is field_ui's `administer {type}
      // display`.
      'field_ui',
    ]);
    $this->enableLayoutBuilder('entity_test_rev', 'entity_test_rev');
    $this->createBlockContentType('basic', 'Basic');
    $this->createBlockField('field_text', 'string', 'basic');
    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * A content host owning one section that holds one inline block.
   *
   * @return array{0: \Drupal\daedalus_blueprint\Host\HostInterface, 1: string, 2: string}
   *   The host, the section's uuid and the inline block's uuid.
   */
  protected function contentHost(): array {
    $uuids = new PhpUuid();
    $section_uuid = $uuids->generate();
    $block_uuid = $uuids->generate();
    $block = $this->entityTypeManager->getStorage('block_content')->create([
      'type' => 'basic',
      'reusable' => FALSE,
      'info' => 'Inline',
      'field_text' => 'Original',
    ]);
    $section = $this->createSection('layout_onecol', [
      ['uuid' => $block_uuid, 'configuration' => $this->createInlineBlockConfiguration('basic', $block, 'Inline')],
    ]);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $section_uuid);
    $entity = $this->createRevEntity(['name' => 'Page', 'layout_builder__layout' => [$section]]);
    $host = $this->host($entity);
    $this->assertTrue($host->hasDocument());
    return [$host, $section_uuid, $block_uuid];
  }

  /**
   * Runs one op as a batch on a host for an actor.
   */
  protected function apply(HostInterface $host, array $op, AccountInterface $actor): BlueprintResult {
    return $this->operations->batchOperations($host, [$op], ['actor' => $actor]);
  }

  /**
   * Asserts a batch was refused for a missing permission.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResult $result
   *   The batch result.
   * @param string $path
   *   The operation parameter the refusal is reported at.
   * @param string[] $permissions
   *   The permissions the refusal must name, any one of which opens it.
   */
  protected function assertPermissionRefused(BlueprintResult $result, string $path, array $permissions): void {
    $this->assertFalse($result->isSuccess(), 'The batch was refused.');
    $error = $result->getErrors()[0];
    $this->assertSame(['ACCESS_DENIED_PERMISSION', $path], [$error->getCode(), $error->getPath()]);
    $this->assertSame($permissions, $error->getContext()['permissions']);
    foreach ($permissions as $permission) {
      $this->assertStringContainsString("'$permission'", $error->getMessage());
    }
  }

  /**
   * Asserts a batch landed.
   */
  protected function assertAllowed(BlueprintResult $result): void {
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
  }

  /**
   * Each of core's three override permissions opens structure; none of them is a content grant.
   */
  public function testCorePermissionsOpenStructure(): void {
    $structure = [
      'configure any layout',
      'configure all entity_test_rev entity_test_rev layout overrides',
      'configure editable entity_test_rev entity_test_rev layout overrides',
    ];
    foreach ($structure as $permission) {
      [$host, $section_uuid, $block_uuid] = $this->contentHost();
      $actor = $this->createUser(['administer entity_test content', $permission]);
      $this->assertAllowed($this->apply($host, [
        'op' => 'update_element',
        'element' => $block_uuid,
        'properties' => ['label' => 'Renamed'],
      ], $actor));
      $this->assertAllowed($this->apply($host, [
        'op' => 'move_element',
        'element' => $block_uuid,
        'location' => ['anchor' => $section_uuid, 'placement' => 'inside'],
      ], $actor));
      $this->assertAllowed($this->apply($host, [
        'op' => 'add_section',
        'location' => ['anchor' => $section_uuid, 'placement' => 'after'],
      ], $actor));
      $this->assertAllowed($this->apply($host, [
        'op' => 'remove_element',
        'element' => $block_uuid,
      ], $actor));

      // The structure grant says nothing about an inline block's content.
      [$host, , $block_uuid] = $this->contentHost();
      $this->assertPermissionRefused($this->apply($host, [
        'op' => 'update_element',
        'element' => $block_uuid,
        'properties' => ['field_text' => 'Changed'],
      ], $actor), 'properties', ['create and edit custom blocks']);
    }
  }

  /**
   * Without an override permission structure is refused and the host's fields stay open.
   */
  public function testContentPermissionsLeaveStructureClosed(): void {
    [$host, $section_uuid, $block_uuid] = $this->contentHost();
    $actor = $this->createUser(['administer entity_test content']);
    $structure = [
      'configure any layout',
      'configure all entity_test_rev entity_test_rev layout overrides',
      'configure editable entity_test_rev entity_test_rev layout overrides',
    ];

    $this->assertPermissionRefused($this->apply($host, [
      'op' => 'update_element',
      'element' => $block_uuid,
      'properties' => ['label' => 'Renamed'],
    ], $actor), 'properties', $structure);
    $this->assertPermissionRefused($this->apply($host, [
      'op' => 'move_element',
      'element' => $block_uuid,
      'location' => ['anchor' => $section_uuid, 'placement' => 'inside'],
    ], $actor), 'element', $structure);
    $this->assertPermissionRefused($this->apply($host, [
      'op' => 'add_section',
      'location' => ['anchor' => $section_uuid, 'placement' => 'after'],
    ], $actor), 'location.anchor', $structure);
    $this->assertPermissionRefused($this->apply($host, [
      'op' => 'remove_element',
      'element' => $block_uuid,
    ], $actor), 'element', $structure);

    // The host's own fields are content, which field access answers.
    $this->assertAllowed($this->apply($host, [
      'op' => 'update_element',
      'element' => $host->uuid(),
      'properties' => ['name' => 'Renamed'],
    ], $actor));
    $this->assertSame('Renamed', $host->fields()->label());
  }

  /**
   * An inline block's fields follow core's inline block permission alone.
   */
  public function testInlineBlockFieldsFollowCustomBlocksPermission(): void {
    [$host, , $block_uuid] = $this->contentHost();
    $op = [
      'op' => 'update_element',
      'element' => $block_uuid,
      'properties' => ['field_text' => 'Changed'],
    ];

    $this->assertPermissionRefused(
      $this->apply($host, $op, $this->createUser(['administer entity_test content'])),
      'properties',
      ['create and edit custom blocks'],
    );

    $blocks = $this->createUser(['administer entity_test content', 'create and edit custom blocks']);
    $this->assertAllowed($this->apply($host, $op, $blocks));

    // The content grant is no structure grant: the block's label is configure.
    $this->assertPermissionRefused($this->apply($host, [
      'op' => 'update_element',
      'element' => $block_uuid,
      'properties' => ['label' => 'Renamed'],
    ], $blocks), 'properties', [
      'configure any layout',
      'configure all entity_test_rev entity_test_rev layout overrides',
      'configure editable entity_test_rev entity_test_rev layout overrides',
    ]);
  }

  /**
   * A template opens to display update access plus `configure any layout`.
   *
   * The display's update access is field_ui's `administer {type}
   * display`, which exists for entity_test (the one test entity type with
   * a Field UI base route).
   */
  public function testTemplateFollowsDisplayAccess(): void {
    $this->enableLayoutBuilder('entity_test', 'entity_test');
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $host = $this->container->get('daedalus_blueprint.host_resolver')->fromEntity($display);
    $op = [
      'op' => 'add_section',
      'location' => ['anchor' => $host->uuid(), 'placement' => 'inside'],
    ];

    $result = $this->apply($host, $op, $this->createUser(['configure any layout']));
    $this->assertFalse($result->isSuccess());
    $this->assertSame('ACCESS_DENIED_ENTITY', $result->getErrors()[0]->getCode(), 'Without update access on the display the template is closed.');

    $this->assertPermissionRefused(
      $this->apply($host, $op, $this->createUser(['administer entity_test display'])),
      'location.anchor',
      ['configure any layout'],
    );

    $both = $this->createUser(['administer entity_test display', 'configure any layout']);
    $this->assertAllowed($this->apply($host, $op, $both));
  }

}
