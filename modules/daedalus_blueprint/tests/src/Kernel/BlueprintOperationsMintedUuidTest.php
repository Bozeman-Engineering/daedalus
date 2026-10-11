<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\Component\Uuid\Php as PhpUuid;
use Drupal\daedalus_blueprint\BlueprintOperations;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
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
   * Tests a write anchored on a seeded section lands.
   *
   * A never-started page (its override field holds no section at all) is
   * seeded on its first read: the host's storage door copies the display's
   * template with fresh identity (derived from the page, never the
   * template's uuid). The uuid a reader sees is the seeded one, so an
   * anchor copied from the read lands, and the template's own uuid names
   * nothing on the page.
   */
  public function testAnchoredWriteLandsOnTheSeededTemplate(): void {
    $default_uuid = (new PhpUuid())->generate();
    $default = $this->createSection('layout_onecol');
    $default->setThirdPartySetting('daedalus_layout', 'uuid', $default_uuid);
    $display = LayoutBuilderEntityViewDisplay::load('entity_test.entity_test.default');
    $display->removeAllSections()->appendSection($default)->save();

    $fresh = $this->entityTypeManager->getStorage('entity_test')->create(['name' => 'Fresh']);
    $fresh->save();

    $host = $this->host($fresh);
    $seeded_uuid = $host->sections()[0]->getThirdPartySetting('daedalus_layout', 'uuid');
    $this->assertNotSame($default_uuid, $seeded_uuid, 'The seed is a copy with its own identity.');
    $this->assertSame(DocumentInstantiator::derivedUuid($fresh->uuid(), $default_uuid), $seeded_uuid);

    $result = $this->operations->addElement($host, ['anchor' => $seeded_uuid, 'placement' => 'inside'], [
      'block_type' => 'basic',
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray()['errors'] ?? [], TRUE));
    $sections = $fresh->get('layout_builder__layout')->getSections();
    $this->assertCount(1, $sections);
    $this->assertSame($seeded_uuid, $sections[0]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $this->assertCount(1, $sections[0]->getComponents());

    // The template's own uuid is nowhere on the page.
    $result = $this->operations->addElement($host, ['anchor' => $default_uuid, 'placement' => 'inside'], [
      'block_type' => 'basic',
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('ANCHOR_NOT_FOUND', $result->getErrors()[0]->getCode());
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
    $result = $this->operations->addElement($this->host($this->entity), ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
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
      $this->host($this->entity),
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
      $this->host($this->entity),
      (new PhpUuid())->generate(),
      self::BAD_UUID,
    );

    $this->assertInvalidUuidAt($result, 'uuid');
  }

  /**
   * Tests a created layout tree rejects malformed container and block uuids.
   *
   * The tree grammar refuses them before anything places, on the tree's
   * coordinates (a container whose uuid is refused expands no children).
   */
  public function testLayoutTreeRejectsMalformedUuids(): void {
    $result = $this->operations->createEntity([
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
      'fields' => ['name' => 'Malformed'],
      'layout' => [
        [
          'kind' => 'container',
          'layout_id' => 'layout_onecol',
          'children' => [
            [
              'kind' => 'region',
              'region' => 'content',
              'children' => [
                [
                  'kind' => 'container',
                  'layout_id' => 'layout_twocol_section',
                  'uuid' => self::BAD_UUID,
                  'children' => [],
                ],
                ['kind' => 'block', 'uuid' => self::BAD_UUID, 'block_type' => 'basic', 'block_fields' => []],
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
      $this->assertSame('INVALID_NODE', $error->getCode());
    }
    $paths = array_map(fn($error) => $error->getPath(), $errors);
    $this->assertContains('layout[0].children[0].children[0].uuid', $paths);
    $this->assertContains('layout[0].children[0].children[1].uuid', $paths);
  }

  /**
   * Tests the batch path surfaces the rejection with the operation index.
   */
  public function testBatchTagsInvalidUuidWithOperationIndex(): void {
    $result = $this->operations->batchOperations($this->host($this->entity), [
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
    $result = $this->operations->addElement($this->host($this->entity), ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
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
    $result = $this->operations->addElement($this->host($this->entity), [
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
    $result = $this->operations->addElement($this->host($this->entity), ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
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
      $this->host($this->entity),
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
      $this->host($this->entity),
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
    $result = $this->operations->batchOperations($this->host($this->entity), [
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
    $result = $this->operations->addElement($this->host($this->entity), ['anchor' => $this->rootSectionUuid, 'placement' => 'inside'], [
      'block_type' => 'basic',
      'uuid' => $minted,
    ]);

    $this->assertTrue($result->isSuccess());
    $this->assertContains($minted, $this->rootComponentUuids());
  }

  /**
   * An op on a plain host naming an element of the display's document.
   *
   * A bundle with Layout Builder on and overrides off has no document of
   * its own: its entities render the display's sections, whose uuids the
   * serializer publishes. An op anchored on one of those is a real gesture
   * at the wrong door, so the op door's capability gate (the ownership
   * policy, asked before dispatch) answers FOREIGN_DOCUMENT naming the
   * owner, never ELEMENT_NOT_FOUND. The corrective is the host's (it
   * renders what it does not own), and the admin actor, who may open the
   * template, gets it as the portal; a uuid nowhere on the page stays
   * ANCHOR_NOT_FOUND, the operation's own answer.
   */
  public function testOpOnForeignDocumentNamesTheOwner(): void {
    $template_uuid = (new PhpUuid())->generate();
    $template = $this->createSection('layout_onecol');
    $template->setThirdPartySetting('daedalus_layout', 'uuid', $template_uuid);
    $display = LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test_rev',
      'bundle' => 'entity_test_rev',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display->enableLayoutBuilder()->appendSection($template)->save();

    $plain = $this->entityTypeManager->getStorage('entity_test_rev')->create(['name' => 'Plain']);
    $plain->save();
    $host = $this->host($plain);
    $this->assertTrue($host->hasDocument(), 'The locked bundle carries the override field.');
    // The host owns nothing yet (ownSections) but reads what it renders:
    // the display's document, a foreign one whose owner the resolver names.
    $this->assertSame([], $host->ownSections());
    $this->assertSame($template_uuid, $host->sections()[0]->getThirdPartySetting('daedalus_layout', 'uuid'));
    $owner = $this->container->get('daedalus_blueprint.host_resolver')->foreignOwner($host);
    $this->assertSame('entity_test_rev.entity_test_rev.default', $owner->id());
    $this->assertNull($this->container->get('daedalus_blueprint.host_resolver')->foreignOwner($this->host($this->entity)), 'A host with a document renders nothing foreign.');

    $result = $this->operations->batchOperations($host, [
      [
        'op' => 'add_element',
        'block_type' => 'basic',
        'location' => ['anchor' => $template_uuid, 'placement' => 'inside'],
      ],
    ]);
    $this->assertFalse($result->isSuccess());
    $errors = $result->getErrors();
    $this->assertCount(1, $errors);
    $this->assertSame('FOREIGN_DOCUMENT', $errors[0]->getCode());
    $this->assertSame('location.anchor', $errors[0]->getPath());
    $this->assertSame(['entity_type' => 'entity_view_display', 'entity_id' => 'entity_test_rev.entity_test_rev.default'], $errors[0]->getContext()['owner']);
    $this->assertSame('entity_test_rev.entity_test_rev.default', $errors[0]->getContext()['portal']['entity_id']);
    $this->assertStringContainsString('entity_view_display entity_test_rev.entity_test_rev.default', $errors[0]->getMessage());

    $result = $this->operations->batchOperations($host, [['op' => 'remove_element', 'element' => $template_uuid]]);
    $this->assertSame('FOREIGN_DOCUMENT', $result->getErrors()[0]->getCode());
    $this->assertSame('element', $result->getErrors()[0]->getPath());

    $result = $this->operations->batchOperations($host, [
      [
        'op' => 'add_element',
        'block_type' => 'basic',
        'location' => ['anchor' => (new PhpUuid())->generate(), 'placement' => 'inside'],
      ],
    ]);
    $this->assertSame('ANCHOR_NOT_FOUND', $result->getErrors()[0]->getCode());
  }

}
