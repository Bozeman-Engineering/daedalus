<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests writer-minted identity is validated at every entry point.
 *
 * A caller-supplied uuid becomes the new element's identity verbatim and is
 * referenced literally by sibling operations, style map keys, and selectable
 * keys. Two ways that goes wrong, both refused here:
 *
 * - Malformed: the strict RFC 4122 hex grammar is what every addressing
 *   surface requires, so a human-readable pseudo-uuid like
 *   "a1b2c3d4-hero-sect-ion1-111111111111" places an element that renders
 *   fine but that no styling or selection surface can address. INVALID_UUID.
 * - Already in use: a writer whose context is full of uuids it just read
 *   can mint one that already identifies an element. Downstream key
 *   semantics turn that into silent damage (same-section component uuids
 *   replace each other) or silent ambiguity (two live elements, one
 *   identity). UUID_IN_USE, never a server-side regeneration: sibling
 *   operations reference the minted value literally.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(BlueprintOperations::class)]
#[Group('daedalus_blueprint')]
class BlueprintOperationsMintedUuidTest extends BlueprintKernelTestBase {

  /**
   * A well-formed-looking but non-hex pseudo uuid (the observed AI failure).
   */
  protected const BAD_UUID = 'a1b2c3d4-hero-sect-ion1-111111111111';

  /**
   * The blueprint operations service.
   */
  protected BlueprintOperations $operations;

  /**
   * The daedalus_layout UUID of the root section components are placed into.
   */
  protected string $rootSectionUuid;

  /**
   * The daedalus_layout UUID of a second root section, for cross-section collisions.
   */
  protected string $siblingSectionUuid;

  /**
   * The UUID of a block placement already living in the root section.
   */
  protected string $existingComponentUuid;

  /**
   * The host entity holding the root section.
   */
  protected object $entity;

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
      'file',
      'daedalus_ui',
      'daedalus_tempstore',
      'daedalus_blueprint_api',
      'daedalus_layout',
    ]);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');
    if (!\Drupal::database()->schema()->tableExists('inline_block_usage')) {
      $this->installSchema('layout_builder', ['inline_block_usage']);
    }

    $this->enableLayoutBuilder();

    $this->createBlockContentType('basic', 'Basic');

    $uuid_generator = new PhpUuid();
    $this->rootSectionUuid = $uuid_generator->generate();
    $this->siblingSectionUuid = $uuid_generator->generate();
    $this->existingComponentUuid = $uuid_generator->generate();

    $section = $this->createSection('layout_onecol', [
      [
        'uuid' => $this->existingComponentUuid,
        'configuration' => ['id' => 'system_powered_by_block', 'label' => 'Existing'],
      ],
    ]);
    $section->setThirdPartySetting('daedalus_layout', 'uuid', $this->rootSectionUuid);
    $sibling = $this->createSection('layout_onecol');
    $sibling->setThirdPartySetting('daedalus_layout', 'uuid', $this->siblingSectionUuid);
    $this->entity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Host',
      'layout_builder__layout' => [$section, $sibling],
    ]);
    $this->entity->save();

    $this->operations = $this->container->get('daedalus_blueprint.operations');
  }

  /**
   * Tests a write anchored on a display default lands over a blank placeholder.
   *
   * A fresh override whose field holds only layout_builder_blank (core's
   * copy of the display's marker) reads as empty, so the first anchored
   * write materializes the display's real default section, uuid and all,
   * exactly as it does over an empty field, and the placeholder is gone.
   */
  public function testAnchoredWriteMaterializesDefaultOverBlankPlaceholder(): void {
    $default_uuid = (new PhpUuid())->generate();
    $default = $this->createSection('layout_onecol');
    $default->setThirdPartySetting('daedalus_layout', 'uuid', $default_uuid);
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $display->removeAllSections()->appendSection($default)->save();

    $fresh = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'Fresh',
      'layout_builder__layout' => [new Section('layout_builder_blank')],
    ]);
    $fresh->save();

    $result = $this->operations->addElement($fresh, ['anchor' => $default_uuid, 'placement' => 'inside'], [
      'block_type' => 'basic',
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $sections = $fresh->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $this->assertSame($default_uuid, $sections[0]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertCount(1, $sections[0]->getComponents());
  }

  /**
   * Asserts a result failed with exactly one INVALID_UUID at a path.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResult $result
   *   The operation result.
   * @param string $path
   *   The expected error path.
   */
  protected function assertInvalidUuidAt(object $result, string $path): void {
    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_UUID', $errors[0]->getCode());
    $this->assertSame($path, $errors[0]->getPath());
    $this->assertStringContainsString(self::BAD_UUID, $errors[0]->getMessage());
    $this->assertNotSame('', $errors[0]->getHint());
  }

  /**
   * Asserts a result failed with exactly one UUID_IN_USE at a path.
   *
   * @param \Drupal\daedalus_blueprint\Result\BlueprintResult $result
   *   The operation result.
   * @param string $path
   *   The expected error path.
   * @param string $uuid
   *   The colliding UUID, which the corrective must name.
   */
  protected function assertUuidInUseAt(object $result, string $path, string $uuid): void {
    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('UUID_IN_USE', $errors[0]->getCode());
    $this->assertSame($path, $errors[0]->getPath());
    $this->assertStringContainsString($uuid, $errors[0]->getMessage());
    // The corrective has to tell the writer what to do instead: re-mint,
    // rather than retry the same value or expect a server-side rename.
    $this->assertStringContainsStringIgnoringCase('fresh', $errors[0]->getHint());
  }

  /**
   * Returns the UUIDs of the components in the first root section.
   *
   * @return string[]
   *   Component UUIDs in section order.
   */
  protected function rootComponentUuids(): array {
    $sections = $this->entity->get('layout_builder__layout')->getSections();
    return array_values(array_map(
      fn($component) => $component->getUuid(),
      $sections[0]->getComponents(),
    ));
  }

  /**
   * Tests add rejects a malformed component uuid before placing anything.
   */
  public function testAddElementRejectsMalformedUuid(): void {
    $result = $this->operations->addElement($this->entity, ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
      'block_type' => 'basic',
      'uuid' => self::BAD_UUID,
    ]);

    $this->assertInvalidUuidAt($result, 'data.uuid');
    $this->assertSame([$this->existingComponentUuid], $this->rootComponentUuids());
  }

  /**
   * Tests add_section rejects a malformed section uuid.
   */
  public function testAddSectionRejectsMalformedUuid(): void {
    $result = $this->operations->addSection(
      $this->entity,
      ['anchor' => $this->entity->uuid(), 'placement' => 'inside'],
      self::BAD_UUID,
    );

    $this->assertInvalidUuidAt($result, 'uuid');
    $this->assertCount(2, $this->entity->get('layout_builder__layout')->getSections());
  }

  /**
   * Tests duplicate rejects a malformed clone uuid before locating anything.
   */
  public function testDuplicateRejectsMalformedCloneUuid(): void {
    $result = $this->operations->duplicateElement(
      $this->entity,
      (new PhpUuid())->generate(),
      self::BAD_UUID,
    );

    $this->assertInvalidUuidAt($result, 'uuid');
  }

  /**
   * Tests a nested section node rejects malformed section and child uuids.
   */
  public function testNestedSectionNodeRejectsMalformedUuids(): void {
    $result = $this->operations->restructureLayout($this->entity, [
      [
        'layout_id' => 'layout_onecol',
        'uuid' => $this->rootSectionUuid,
        'regions' => [
          'content' => [
            [
              'layout_id' => 'layout_twocol_section',
              'uuid' => self::BAD_UUID,
              'regions' => [
                'first' => [
                  ['uuid' => self::BAD_UUID, 'block_type' => 'basic', 'block_fields' => []],
                ],
              ],
            ],
          ],
        ],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(2, $errors);
    foreach ($errors as $error) {
      $this->assertSame('INVALID_UUID', $error->getCode());
    }
    $paths = array_map(fn($error) => $error->getPath(), $errors);
    $this->assertContains('layout_builder__layout[0].regions.content[0].uuid', $paths);
    $this->assertContains('layout_builder__layout[0].regions.content[0].regions.first[0].uuid', $paths);
  }

  /**
   * Tests the batch path surfaces the rejection with the operation index.
   */
  public function testBatchTagsInvalidUuidWithOperationIndex(): void {
    $result = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'add_element',
        'block_type' => 'basic',
        'uuid' => (new PhpUuid())->generate(),
        'location' => ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'block_type' => 'basic',
        'uuid' => self::BAD_UUID,
        'location' => ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('INVALID_UUID', $errors[0]->getCode());
    $this->assertSame(1, $errors[0]->getOperationIndex());
    // The batch rolled back: the first operation's valid add went too.
    $this->assertSame([$this->existingComponentUuid], $this->rootComponentUuids());
  }

  /**
   * Tests a minted uuid colliding with a sibling in the same section.
   *
   * The worst of the collision classes and the reason the check exists:
   * Section::setComponent() keys by uuid, so without the gate this add
   * silently REPLACES the existing placement and reports success.
   */
  public function testAddElementRejectsUuidOfSameSectionSibling(): void {
    $result = $this->operations->addElement($this->entity, ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
      'block_type' => 'basic',
      'uuid' => $this->existingComponentUuid,
    ]);

    $this->assertUuidInUseAt($result, 'data.uuid', $this->existingComponentUuid);
    // The element that was there is still the only one there.
    $this->assertSame([$this->existingComponentUuid], $this->rootComponentUuids());
  }

  /**
   * Tests a minted uuid colliding with a block in a different section.
   *
   * Nothing is overwritten here — the damage is two live elements sharing
   * one identity, which locate, style, and undo resolve to whichever comes
   * first. Refused for the same reason.
   */
  public function testAddElementRejectsUuidOfCrossSectionBlock(): void {
    $result = $this->operations->addElement($this->entity, [
      'anchor' => $this->siblingSectionUuid,
      'placement' => 'inside',
    ], [
      'block_type' => 'basic',
      'uuid' => $this->existingComponentUuid,
    ]);

    $this->assertUuidInUseAt($result, 'data.uuid', $this->existingComponentUuid);
    $sections = $this->entity->get('layout_builder__layout')->getSections();
    $this->assertCount(0, $sections[1]->getComponents());
  }

  /**
   * Tests a minted block uuid colliding with a section's identity.
   *
   * Containers bridge the two namespaces (a container's component uuid is
   * its section uuid), so block identity and section identity have to be
   * unique against each other, not just within their own kind.
   */
  public function testAddElementRejectsUuidOfExistingSection(): void {
    $result = $this->operations->addElement($this->entity, ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
      'block_type' => 'basic',
      'uuid' => $this->siblingSectionUuid,
    ]);

    $this->assertUuidInUseAt($result, 'data.uuid', $this->siblingSectionUuid);
    $this->assertSame([$this->existingComponentUuid], $this->rootComponentUuids());
  }

  /**
   * Tests add_section rejects a uuid already identifying a section.
   */
  public function testAddSectionRejectsUuidOfExistingSection(): void {
    $result = $this->operations->addSection(
      $this->entity,
      ['anchor' => $this->rootSectionUuid, 'placement' => 'after'],
      $this->siblingSectionUuid,
    );

    $this->assertUuidInUseAt($result, 'uuid', $this->siblingSectionUuid);
    $this->assertCount(2, $this->entity->get('layout_builder__layout')->getSections());
  }

  /**
   * Tests duplicate rejects a clone uuid equal to the source's.
   *
   * The plausible model slip when asked to duplicate: reusing the uuid it
   * was just handed as the target. It would replace the source in place.
   */
  public function testDuplicateRejectsCloneUuidOfSource(): void {
    $result = $this->operations->duplicateElement(
      $this->entity,
      $this->existingComponentUuid,
      $this->existingComponentUuid,
    );

    $this->assertUuidInUseAt($result, 'uuid', $this->existingComponentUuid);
    $this->assertSame([$this->existingComponentUuid], $this->rootComponentUuids());
  }

  /**
   * Tests a uuid repeated across two operations of one batch collides.
   *
   * Operations apply sequentially to the live entity, so the gate sees what
   * its predecessors inserted — no separate within-batch bookkeeping. This
   * is the "model repeats the same random uuid twice" case.
   */
  public function testBatchRejectsUuidRepeatedAcrossOperations(): void {
    $minted = (new PhpUuid())->generate();
    $result = $this->operations->batchOperations($this->entity, [
      [
        'op' => 'add_element',
        'block_type' => 'basic',
        'uuid' => $minted,
        'location' => ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'block_type' => 'basic',
        'uuid' => $minted,
        'location' => ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'],
      ],
    ]);

    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('UUID_IN_USE', $errors[0]->getCode());
    $this->assertSame(1, $errors[0]->getOperationIndex());
    // Rolled back: the first operation's valid add went with it.
    $this->assertSame([$this->existingComponentUuid], $this->rootComponentUuids());
  }

  /**
   * Tests a fresh minted uuid still places normally.
   *
   * The gate refuses collisions, not minting: the optimistic seam the batch
   * grammar rests on has to keep working.
   */
  public function testFreshMintedUuidStillPlaces(): void {
    $minted = (new PhpUuid())->generate();
    $result = $this->operations->addElement($this->entity, ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
      'block_type' => 'basic',
      'uuid' => $minted,
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertContains($minted, $this->rootComponentUuids());
  }

}
