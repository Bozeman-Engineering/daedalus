<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\daedalus_blueprint\Materials\DistroLock;
use Drupal\daedalus_blueprint\Materials\MaterialGestureOperationHandler;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Result\BlueprintResult;
use Drupal\daedalus_blueprint_library\BlueprintDiscoveryInterface;
use Drupal\daedalus_blueprint_library\BlueprintLayout;
use Drupal\daedalus_blueprint_library\BlueprintManager;
use Drupal\daedalus_blueprint_library\Exception\BlueprintGestureException;
use Drupal\daedalus_blueprint_library\Materials\BlueprintMaterialsProvider;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the blueprint gestures as spine ops.
 *
 * The ops are save_material and update_material with kind blueprint.
 *
 * Capture is a SPINE OP, so the UI verb and the AI's batch tools are thin
 * adapters over one grammar. The proof of parity runs here: a subtree is
 * COMPOSED and SAVED in the SAME batch; the capture reads the batch's live
 * entity, which is exactly what the composing operations just staged,
 * never the database copy. The capture is the one machinery: the host's
 * tree at the element, instantiated into the new blueprint's own host with
 * fresh identity and committed, so the saved blueprint is a page whose
 * document equals the captured subtree.
 */
#[CoversClass(BlueprintManager::class)]
#[CoversClass(MaterialGestureOperationHandler::class)]
#[CoversClass(BlueprintMaterialsProvider::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintManagerTest extends BlueprintKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_stylish'];

  /**
   * {@inheritdoc}
   */
  protected static array $permissions = ['create section blueprint', 'edit any section blueprint', 'delete any section blueprint'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The materials delta resolves against the roster, whose daedalus_stylish
    // provider reads all four storages on every build; the blueprint's
    // document carries its node styles in the map field the field manager
    // owns, and the source page's map is written raw.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_skin');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
    $this->createReferenceFields();
  }

  /**
   * The batch operations composing the reference section, uuids pinned.
   *
   * The same shape BlueprintKernelTestBase::buildReferenceSection() runs, as
   * raw operations so a test can append save_material to the SAME batch.
   *
   * @return array{operations: array[], section: string, text: string}
   *   The operations and the uuids they pin.
   */
  protected function compositionOperations(string $entity_uuid): array {
    $uuid = \Drupal::service('uuid');
    $section = $uuid->generate();
    $text = $uuid->generate();
    $inner = $uuid->generate();
    return [
      'operations' => [
        [
          'op' => 'add_section',
          'location' => ['anchor' => $entity_uuid, 'placement' => 'inside'],
          'uuid' => $section,
          'layout' => 'layout_twocol_section',
          'layout_settings' => ['column_widths' => '33-67'],
        ],
        [
          'op' => 'update_element',
          'element' => $section,
          'properties' => ['label' => 'Call to Action'],
        ],
        [
          'op' => 'add_element',
          'location' => ['anchor' => $section, 'placement' => 'inside', 'region' => 'first'],
          'block_type' => 'text',
          'uuid' => $text,
          'properties' => [
            'field_heading' => 'Pinned heading',
            'field_ref' => ['target_id' => (int) \Drupal::currentUser()->id()],
            'label' => 'My heading',
          ],
        ],
        [
          'op' => 'add_element',
          'location' => ['anchor' => $section, 'placement' => 'inside', 'region' => 'second'],
          'block_type' => 'basic',
        ],
        [
          'op' => 'add_section',
          'location' => ['anchor' => $section, 'placement' => 'inside', 'region' => 'second'],
          'uuid' => $inner,
        ],
        [
          'op' => 'add_element',
          'location' => ['anchor' => $inner, 'placement' => 'inside'],
          'block_type' => 'basic',
        ],
      ],
      'section' => $section,
      'text' => $text,
    ];
  }

  /**
   * Save-as-blueprint: compose AND save in ONE batch (the AI parity proof).
   */
  public function testSaveBlueprintInComposingBatch(): void {
    ['entity' => $entity] = $this->createHost();
    ['operations' => $operations, 'section' => $section, 'text' => $text] = $this->compositionOperations($entity->uuid());

    // A block field's style entry: expressible since the tree grammar
    // (the ':{field}' local), so the capture carries it onto the node.
    $entity->set('daedalus_stylish_styles', json_encode([
      "block:$text:field_heading" => ['default' => ['color' => 'tomato']],
    ]));

    $operations[] = [
      'op' => 'save_material',
      'kind' => 'blueprint',
      'element' => $section,
      'label' => 'My CTA',
      'description' => 'A composed call to action.',
    ];
    $result = $this->operations()->batchOperations($this->host($entity), $operations);
    $this->assertBatchSuccess($result);

    // The capture saw the batch's OWN compositions: nothing was ever saved
    // to the database copy of the page, and the blueprint's document IS
    // the captured subtree with fresh identity.
    $blueprint = $this->loadBlueprint('my_cta');
    $this->assertSame('My CTA', (string) $blueprint->label());
    $this->assertSame('A composed call to action.', $blueprint->getDescription());
    $this->assertTrue($blueprint->isPublished());
    $this->assertSame('block', $blueprint->getScope());
    $this->assertSame('entity_test', $blueprint->getSubjectType(), 'The subject is stamped from the source host\'s display.');
    $this->assertSame('entity_test', $blueprint->getSubjectBundle());
    $expected = $this->definitionAt($this->host($entity), $section);
    $this->assertSame(['default' => ['color' => 'tomato']], $expected['children'][0]['children'][0]['styles'][':field_heading'], 'The source tree carries the field entry on the block node.');
    $this->assertEquals([$expected], $this->definitionOf($blueprint));
    $layout = $this->builtLayout($blueprint);
    $this->assertNotSame($section, $layout[0]['uuid'], 'The blueprint minted its own identity.');
    $this->assertNotSame($text, $layout[0]['children'][0]['children'][0]['uuid']);

    // Nothing warned onto the batch's warning channel.
    $warnings = array_filter(
      $result->getWarnings(),
      static fn ($warning) => $warning->getCode() === MaterialGestureOperationHandler::CAPTURE_WARNING,
    );
    $this->assertCount(0, $warnings);

    // The save reached the client's channel: the blueprint's entity hook
    // recorded it on the request's materials change set, so the mutation
    // response carries its record as an upsert (the delivery contract).
    $delta = \Drupal::service('daedalus_blueprint.materials_change_set')->delta();
    $this->assertSame([], $delta['remove']);
    $this->assertSame([['blueprint', 'my_cta']], array_map(static fn (array $record): array => [
      $record['kind'],
      $record['id'],
    ], $delta['upsert']));
    $this->assertSame('My CTA', $delta['upsert'][0]['label']);
    $this->assertSame('block', $delta['upsert'][0]['blueprint_scope']);
    // A person minted it through the op lane: the origin base field stamps
    // user (the AI tools stamp ai, testOriginStampedByLane).
    $this->assertSame('user', $delta['upsert'][0]['origin']);
    $this->assertSame('user', $blueprint->getOrigin());

    // The save IS a palette entry: discovery serves it as a content
    // blueprint whose layout is the saved document.
    $definition = \Drupal::service(BlueprintDiscoveryInterface::class)->getBlueprint('my_cta');
    $this->assertNotNull($definition);
    $this->assertSame(BlueprintDiscoveryInterface::SOURCE_CONTENT, $definition['source']);
    $this->assertSame('entity_test:entity_test', $definition['subject']);
    $this->assertEquals([$expected], BlueprintLayout::withoutUuids($definition['layout']));
  }

  /**
   * A container holding a field block saves as a blueprint and places again.
   *
   * The field block names the field (never core's per-bundle derivative),
   * its label and its own configuration keys (the formatter) travel, and
   * placing the blueprint on another page lands the same field block with
   * the same configuration and fresh identity, the provenance stamp on
   * the root.
   */
  public function testFieldBlockCaptureAndPlacement(): void {
    ['entity' => $entity] = $this->createHost();
    $section = \Drupal::service('uuid')->generate();
    $field_block = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'add_section',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
        'uuid' => $section,
        'layout' => 'layout_onecol',
      ],
      [
        'op' => 'add_element',
        'location' => ['anchor' => $section, 'placement' => 'inside'],
        'plugin_id' => 'field_block:entity_test:entity_test:name',
        'uuid' => $field_block,
        'properties' => ['label' => 'The name', 'label_display' => 'visible', 'formatter' => ['type' => 'string', 'label' => 'inline']],
      ],
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $section,
        'label' => 'Name band',
        'description' => 'A band showing the name.',
      ],
    ]);
    $this->assertBatchSuccess($result);

    $blueprint = $this->loadBlueprint('name_band');
    $node = $this->definitionOf($blueprint)[0]['children'][0]['children'][0];
    $this->assertSame('block', $node['kind']);
    $this->assertSame('name', $node['field'], 'A field block names its field; the host a blueprint lands on supplies the derivative.');
    $this->assertArrayNotHasKey('plugin_id', $node);
    $this->assertSame('The name', $node['label']);
    $this->assertTrue($node['label_rendered']);
    $this->assertSame('inline', $node['configuration']['formatter']['label']);
    $this->assertArrayNotHasKey('block_type', $node);
    $this->assertArrayNotHasKey('uuid', $node);

    // Placed on another page: the same field block, fresh identity.
    ['entity' => $other] = $this->createHost();
    $root = \Drupal::service('uuid')->generate();
    $result = $this->operations()->batchOperations($this->host($other), [
      [
        'op' => 'place_blueprint',
        'blueprint' => 'name_band',
        'location' => ['anchor' => $other->uuid(), 'placement' => 'inside'],
        'uuid' => $root,
      ],
    ]);
    $this->assertBatchSuccess($result);
    $placed = NULL;
    foreach ($other->get('layout_builder__layout')->getSections() as $candidate) {
      if ($candidate->getThirdPartySetting('daedalus_layout', 'uuid') === $root) {
        $placed = $candidate;
      }
    }
    $this->assertNotNull($placed, 'The placement root carries the operation\'s uuid.');
    $this->assertSame('name_band', $placed->getThirdPartySetting('daedalus_blueprint_library', 'blueprint'));
    $components = array_values($placed->getComponents());
    $this->assertCount(1, $components);
    $this->assertNotSame($field_block, $components[0]->getUuid(), 'The placement mints its own identity.');
    $configuration = $components[0]->get('configuration');
    $this->assertSame('field_block:entity_test:entity_test:name', $configuration['id']);
    $this->assertSame('The name', $configuration['label']);
    $this->assertSame('visible', $configuration['label_display']);
    $this->assertSame('inline', $configuration['formatter']['label']);
    $this->assertSame('string', $configuration['formatter']['type']);
  }

  /**
   * The whole page captures as a page-scope blueprint.
   *
   * The host's own uuid is the element: every top-level container travels,
   * the scope is page, and the page's own style keys (its root entry) land
   * re-addressed to the blueprint's root.
   */
  public function testPageScopeCapture(): void {
    ['entity' => $entity, 'section' => $section, 'root_section' => $root_section] = $this->buildReferenceSection();
    $entity->set('daedalus_stylish_styles', json_encode([
      'entity:' . $entity->uuid() => ['default' => ['margin' => '1rem']],
    ]));

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $entity->uuid(),
        'label' => 'Whole page',
        'description' => 'The page as a starting point.',
      ],
    ]);
    $this->assertBatchSuccess($result);

    $blueprint = $this->loadBlueprint('whole_page');
    $this->assertSame('page', $blueprint->getScope());
    $definition = $this->definitionOf($blueprint);
    $this->assertCount(2, $definition, 'Both top-level containers travel.');
    $this->assertEquals([$this->definitionAt($this->host($entity), $root_section), $this->definitionAt($this->host($entity), $section)], $definition);
    $map = $this->host($blueprint)->styleMap();
    $this->assertSame(['default' => ['margin' => '1rem']], $map['entity:' . $blueprint->uuid()] ?? NULL, 'The page root\'s own entry is re-addressed to the blueprint.');
    $this->assertArrayNotHasKey('entity:' . $entity->uuid(), $map);
  }

  /**
   * The ops enforce the blueprint's create access (never validation-exempt).
   */
  public function testPermissionGate(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $this->setUpCurrentUser([], [], FALSE);
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'save_material', 'kind' => 'blueprint', 'element' => $section, 'label' => 'X', 'description' => 'Y'],
    ]);

    $this->assertFalse($result->isSuccess());
    $this->assertSame('PERMISSION_DENIED', $result->getErrors()[0]->getCode());
    $this->assertStringContainsString('create section blueprint', $result->getErrors()[0]->getMessage());
    $this->assertSame([], \Drupal::entityTypeManager()->getStorage('blueprint')->loadMultiple(), 'No blueprint was created.');
  }

  /**
   * A capture names its type; the type's create permission is the gate.
   *
   * `section` unless named, so an actor holding only the site chrome
   * grant saves a header by naming `site_chrome` and is refused the
   * default; an unknown type is refused before any permission is asked.
   */
  public function testCaptureType(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();
    $this->setUpCurrentUser([], ['create site_chrome blueprint'], FALSE);

    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'save_material', 'kind' => 'blueprint', 'element' => $section, 'label' => 'Header', 'description' => 'The site header.'],
    ]);
    $this->assertFalse($result->isSuccess(), 'The default type is section; the site chrome grant does not create one.');
    $this->assertSame('PERMISSION_DENIED', $result->getErrors()[0]->getCode());

    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'save_material', 'kind' => 'blueprint', 'element' => $section, 'label' => 'Header', 'description' => 'The site header.', 'type' => 'site_chrome'],
    ]);
    $this->assertBatchSuccess($result);
    $blueprints = \Drupal::entityTypeManager()->getStorage('blueprint')->loadByProperties(['machine_name' => 'header']);
    $this->assertSame('site_chrome', reset($blueprints)->getType());
    $this->assertSame('site_chrome', \Drupal::service(BlueprintDiscoveryInterface::class)->getBlueprint('header')['type'], 'Discovery serves the type.');
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertSame('site_chrome', $roster->get(MaterialKind::Blueprint, 'header')->group, 'The roster groups the blueprint by its type.');

    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'save_material', 'kind' => 'blueprint', 'element' => $section, 'label' => 'Stray', 'description' => 'No such type.', 'type' => 'banner'],
    ]);
    $this->assertFalse($result->isSuccess());
    $this->assertSame('INVALID_PARAMETER', $result->getErrors()[0]->getCode());
    $this->assertSame('type', $result->getErrors()[0]->getPath());
    $this->assertSame(['types' => ['section', 'site_chrome']], $result->getErrors()[0]->getContext());
  }

  /**
   * Malformed save gestures fail loudly with blueprint-shaped errors.
   */
  public function testSaveValidation(): void {
    ['entity' => $entity, 'section' => $section, 'text' => $text] = $this->buildReferenceSection();

    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      ['op' => 'save_material', 'kind' => 'blueprint', 'element' => $section, 'label' => '', 'description' => ''],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['label']);
    $this->assertSame(['MISSING_PARAMETER'], $codes['description']);

    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      ['op' => 'save_material', 'kind' => 'blueprint', 'label' => 'CTA', 'description' => 'A CTA.'],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['element']);

    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => '11111111-2222-3333-4444-555555555555',
        'label' => 'CTA',
        'description' => 'A CTA.',
      ],
    ]));
    $this->assertSame(['BLUEPRINT_CAPTURE_FAILED'], $codes['element']);

    // Roots are containers: a block is refused toward its container.
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'save_material', 'kind' => 'blueprint', 'element' => $text, 'label' => 'Heading', 'description' => 'One block.'],
    ]);
    $this->assertSame(['BLUEPRINT_ROOT_NOT_CONTAINER'], $this->errorCodes($result)['element']);
    $this->assertSame($section, $result->getErrors()[0]->getContext()['container']);
    $this->assertStringContainsString('Capture its container', $result->getErrors()[0]->getHint());

    // A malformed strip_values is a malformed gesture.
    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      ['op' => 'save_material', 'kind' => 'blueprint', 'element' => $section, 'label' => 'CTA', 'description' => 'A CTA.', 'strip_values' => 'yes'],
    ]));
    $this->assertSame(['INVALID_PARAMETER'], $codes['strip_values']);

    // The family's own envelope: a missing kind, a kind whose gesture is
    // another provider's, a missing id on update.
    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      ['op' => 'save_material', 'element' => $section, 'label' => 'CTA', 'description' => 'A CTA.'],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['kind']);
    // With daedalus_stylish enabled every kind has a gesture provider, so a
    // token save is dispatched to that provider and its own refusal comes
    // back: the token line owns its grammar (a token is minted on a scale,
    // "group"; the blueprint's element and description mean nothing to it)
    // and its grade (this fixture's account is an admin, so the permission
    // gate never fires here). Nothing sits at kind; the envelope only
    // routes.
    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      ['op' => 'save_material', 'kind' => 'token', 'element' => $section, 'label' => 'CTA', 'description' => 'A CTA.'],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['group'], 'The token provider refused the mint in its own grammar.');
    $this->assertArrayNotHasKey('kind', $codes, 'A kind with a gesture is never refused at kind.');
    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      ['op' => 'update_material', 'kind' => 'blueprint', 'element' => $section],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['id']);
    $this->assertSame([], \Drupal::entityTypeManager()->getStorage('blueprint')->loadMultiple(), 'No refusal left a blueprint behind.');
  }

  /**
   * The origin base field is the lane's stamp: user here, ai on the AI door.
   *
   * The AI tools pass MaterialGestureOperationHandler::ORIGIN_OPTION = ai on
   * every batch (DaedalusBlueprintToolBase::blueprintOptions()); every other
   * lane stamps user. The roster record carries it for the life of the
   * site, and the delta the response carries says so at once.
   */
  public function testOriginStampedByLane(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $section,
        'label' => 'By the AI',
        'description' => 'Minted on request.',
      ],
    ], [MaterialGestureOperationHandler::ORIGIN_OPTION => 'ai']);
    $this->assertBatchSuccess($result);
    $delta = \Drupal::service('daedalus_blueprint.materials_change_set')->flush();
    $this->assertSame('ai', $delta['upsert'][0]['origin']);
    $blueprint = $this->loadBlueprint('by_the_ai');
    $this->assertSame('ai', $blueprint->getOrigin());

    // A later update by a person never rewrites who minted it.
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'update_material', 'kind' => 'blueprint', 'id' => 'by_the_ai', 'label' => 'Renamed by a person'],
    ]);
    $this->assertBatchSuccess($result);
    $this->assertSame('ai', $this->loadBlueprint('by_the_ai')->getOrigin());

    // remove_material deletes it; the delta says remove.
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'remove_material', 'kind' => 'blueprint', 'id' => 'by_the_ai'],
    ]);
    $this->assertBatchSuccess($result);
    $this->assertSame(['upsert' => [], 'remove' => [['kind' => 'blueprint', 'id' => 'by_the_ai']]], \Drupal::service('daedalus_blueprint.materials_change_set')->flush());
    $this->assertNull(\Drupal::entityTypeManager()->getStorage('blueprint')->loadUnchanged($blueprint->id()));

    // A malformed origin option is a programming error, never a stamp.
    $this->expectException(\InvalidArgumentException::class);
    $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $section,
        'label' => 'Bad',
        'description' => 'Bad origin.',
      ],
    ], [MaterialGestureOperationHandler::ORIGIN_OPTION => 'robot']);
  }

  /**
   * Push: a new revision carrying the captured document, metadata untouched.
   */
  public function testUpdateBlueprint(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $storage = \Drupal::entityTypeManager()->getStorage('blueprint');
    assert($storage instanceof RevisionableStorageInterface);
    $blueprint = $this->createBlueprint([
      'label' => 'My CTA',
      'machine_name' => NULL,
      'description' => 'A composed call to action.',
      'uid' => \Drupal::currentUser()->id(),
    ]);
    $this->assertSame('my_cta', $blueprint->getMachineName());
    $first_revision = (int) $blueprint->getRevisionId();
    $this->assertSame('layout_onecol', $this->definitionOf($blueprint)[0]['layout_id'], 'The blueprint starts as one empty onecol container.');

    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'update_material', 'kind' => 'blueprint', 'id' => 'my_cta', 'element' => $section],
    ]);
    $this->assertBatchSuccess($result);

    $updated = $this->loadBlueprint('my_cta');
    $this->assertEquals([$this->definitionAt($this->host($entity), $section)], $this->definitionOf($updated), 'The pushed document replaces the old one entirely.');
    $this->assertGreaterThan($first_revision, (int) $updated->getRevisionId(), 'The push is REVISIONED: a bad push is one revert away.');
    $this->assertSame('My CTA', (string) $updated->label());
    $this->assertStringContainsString('entity_test', (string) $updated->getRevisionLogMessage());

    // The old document survives as the previous revision.
    $previous = $storage->loadRevision($first_revision);
    $this->assertSame('layout_onecol', $this->builtLayoutOf($previous)[0]['layout_id']);
    $this->assertSame([], $this->builtLayoutOf($previous)[0]['children'][0]['children'], 'The previous revision\'s one container is still empty.');

    // The uuid reference form resolves too.
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'update_material', 'kind' => 'blueprint', 'id' => $blueprint->uuid(), 'element' => $section],
    ]);
    $this->assertBatchSuccess($result);

    // The palette face rides the same verb: label, description and icon
    // land in ONE revision beside a push, metadata no longer a separate
    // lane (the details route is an adapter over the same provider).
    $before = (int) $this->loadBlueprint('my_cta')->getRevisionId();
    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'update_material',
        'kind' => 'blueprint',
        'id' => 'my_cta',
        'element' => $section,
        'label' => 'Our CTA',
        'description' => 'Renamed while pushing.',
      ],
    ]);
    $this->assertBatchSuccess($result);
    $renamed = $this->loadBlueprint('my_cta');
    $this->assertSame('Our CTA', (string) $renamed->label());
    $this->assertSame('Renamed while pushing.', $renamed->getDescription());
    $this->assertSame($before + 1, (int) $renamed->getRevisionId(), 'Push and face land in one revision.');

    // A whole page cannot push into a block blueprint: scopes differ.
    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'update_material', 'kind' => 'blueprint', 'id' => 'my_cta', 'element' => $entity->uuid()],
    ]);
    $this->assertSame(['BLUEPRINT_SCOPE_MISMATCH'], $this->errorCodes($result)['element']);

    // An update naming nothing to change refuses.
    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      ['op' => 'update_material', 'kind' => 'blueprint', 'id' => 'my_cta'],
    ]));
    $this->assertSame(['MISSING_PARAMETER'], $codes['']);
  }

  /**
   * Update targets resolve loudly: read-only files and unknown references.
   */
  public function testUpdateRefusals(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    // The hero blueprint ships with the fixture module: a FILE blueprint,
    // read-only in every UI. The refusal names the provider instead of
    // hiding behind a not-found.
    $this->enableModules(['daedalus_blueprint_library_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['daedalus_blueprint_library']);

    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      ['op' => 'update_material', 'kind' => 'blueprint', 'id' => 'hero', 'element' => $section],
    ]));
    $this->assertSame(['BLUEPRINT_READ_ONLY'], $codes['id']);

    // The hint follows the distro lock. While it holds (a consuming site)
    // the site forks; under the developer gate the refusal names the
    // file's update lanes, the file itself in the provider module and the
    // ship command for a new blueprint. The code and the refusal itself
    // are the same under both: a file blueprint never gains an entity to
    // update.
    $this->assertStringContainsString('NEW blueprint', $this->readOnlyHint('hero'));
    $this->assertStringNotContainsString('blueprint-ship', $this->readOnlyHint('hero'));
    $this->setSetting(DistroLock::SETTING, TRUE);
    $hint = $this->readOnlyHint('hero');
    $this->assertStringContainsString('drush daedalus-blueprint:blueprint-ship', $hint);
    $this->assertStringContainsString('daedalus_blueprint_library_test', $hint, 'The lane names the provider module.');
    $this->assertStringContainsString('by hand', $hint, 'A shipped file is edited where it lives.');
    $this->assertStringNotContainsString('Save the composed element', $hint);
    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      ['op' => 'update_material', 'kind' => 'blueprint', 'id' => 'hero', 'element' => $section],
    ]));
    $this->assertSame(['BLUEPRINT_READ_ONLY'], $codes['id'], 'The gate changes the hint, never the refusal.');
    $this->setSetting(DistroLock::SETTING, FALSE);
    $this->assertStringContainsString('NEW blueprint', $this->readOnlyHint('hero'));

    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      ['op' => 'update_material', 'kind' => 'blueprint', 'id' => 'nope', 'element' => $section],
    ]));
    $this->assertSame(['BLUEPRINT_NOT_FOUND'], $codes['id']);

    // Removal refuses the same targets the same way.
    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      ['op' => 'remove_material', 'kind' => 'blueprint', 'id' => 'hero'],
    ]));
    $this->assertSame(['BLUEPRINT_READ_ONLY'], $codes['id']);
  }

  /**
   * An unpublished blueprint is its author's draft: push keeps working.
   */
  public function testUpdateUnpublished(): void {
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $blueprint = $this->createBlueprint([
      'label' => 'Draft CTA',
      'machine_name' => NULL,
      'description' => 'Still staging.',
      'uid' => \Drupal::currentUser()->id(),
      'status' => FALSE,
    ]);

    $result = $this->operations()->batchOperations($this->host($entity), [
      ['op' => 'update_material', 'kind' => 'blueprint', 'id' => 'draft_cta', 'element' => $section],
    ]);
    $this->assertBatchSuccess($result);

    $updated = \Drupal::entityTypeManager()->getStorage('blueprint')->loadUnchanged($blueprint->id());
    $this->assertFalse($updated->isPublished());
    $this->assertEquals([$this->definitionAt($this->host($entity), $section)], $this->definitionOf($updated));
  }

  /**
   * The icon round-trip on the op spine: save with icon, catalog serves it.
   *
   * One vocabulary end to end: the gesture takes the icon value grammar's
   * media spelling, the entity stores the media REFERENCE, the discovery
   * catalog serves the spelling back, and the material record the response
   * delivers carries the icon RESOLVED (the client palette paints it and
   * cannot resolve a media reference itself).
   */
  public function testSaveBlueprintIconRoundTrip(): void {
    $media = $this->setUpIconMedia();
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    $result = $this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $section,
        'label' => 'My CTA',
        'description' => 'A composed call to action.',
        'icon' => 'media:' . $media->uuid(),
      ],
    ]);
    $this->assertBatchSuccess($result);

    // The entity stores the media reference itself.
    $blueprint = $this->loadBlueprint('my_cta');
    $this->assertSame($media->uuid(), $blueprint->getIcon()?->uuid());

    // The material record the response delivers to the client carries the
    // icon resolved through IconResolver: this fixture's media has no
    // source file, so it degrades to NULL (the default icon) and the
    // media's tag rides the roster so a later file lands on the record.
    $delta = \Drupal::service('daedalus_blueprint.materials_change_set')->delta();
    $this->assertNull($delta['upsert'][0]['icon']);
    $roster = \Drupal::service('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertContains('media:' . $media->id(), $roster->getCacheableMetadata()->getCacheTags());

    // The catalog serves the content lane in the grammar's media spelling;
    // resolution to a URL is the provider's job (IconResolver).
    $definition = \Drupal::service(BlueprintDiscoveryInterface::class)->getBlueprint('my_cta');
    $this->assertSame('media:' . $media->uuid(), $definition['icon']);
  }

  /**
   * The icon gestures refuse bad spellings loudly, on save and on update.
   *
   * The gesture lane accepts ONLY the media spelling (the path lane belongs
   * to module-shipped blueprint files) and unknown media refuses at write
   * time; update_material takes the icon too (one verb rewrites the whole
   * material, capture and face alike) and refuses it the same way.
   */
  public function testIconGestureRefusals(): void {
    $this->setUpIconMedia();
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();

    // The path spelling is the blueprint FILES' lane; a path here has
    // nowhere to live.
    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $section,
        'label' => 'CTA',
        'description' => 'A CTA.',
        'icon' => '/modules/x/assets/icon.svg',
      ],
    ]));
    $this->assertSame(['INVALID_PARAMETER'], $codes['icon']);

    // Unknown media refuses at write time; the gesture never creates media.
    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $section,
        'label' => 'CTA',
        'description' => 'A CTA.',
        'icon' => 'media:11111111-2222-3333-4444-555555555555',
      ],
    ]));
    $this->assertSame(['BLUEPRINT_ICON_UNKNOWN_MEDIA'], $codes['icon']);

    // A non-string icon is a malformed gesture, not a silent drop.
    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'save_material',
        'kind' => 'blueprint',
        'element' => $section,
        'label' => 'CTA',
        'description' => 'A CTA.',
        'icon' => 123,
      ],
    ]));
    $this->assertSame(['INVALID_PARAMETER'], $codes['icon']);

    // No refusal left a half-saved blueprint behind.
    $this->assertSame([], \Drupal::entityTypeManager()->getStorage('blueprint')->loadMultiple());

    // update_material refuses a bad icon the same way, storing nothing.
    $blueprint = $this->createBlueprint([
      'label' => 'My CTA',
      'machine_name' => NULL,
      'description' => 'A composed call to action.',
      'uid' => \Drupal::currentUser()->id(),
    ]);
    $revision = $blueprint->getRevisionId();

    $codes = $this->errorCodes($this->operations()->batchOperations($this->host($entity), [
      [
        'op' => 'update_material',
        'kind' => 'blueprint',
        'id' => 'my_cta',
        'element' => $section,
        'icon' => 'media:11111111-2222-3333-4444-555555555555',
      ],
    ]));
    $this->assertSame(['BLUEPRINT_ICON_UNKNOWN_MEDIA'], $codes['icon']);
    $unchanged = $this->loadBlueprint('my_cta');
    $this->assertSame($revision, $unchanged->getRevisionId(), 'The refused push changed nothing.');
    $this->assertSame('layout_onecol', $this->definitionOf($unchanged)[0]['layout_id']);
    $this->assertNull($unchanged->getIcon());
  }

  /**
   * Installs the media stack and returns a saved media item for icon tests.
   *
   * The icon reference needs no source file: the gestures store and refuse
   * on the media ENTITY; URL resolution is IconResolver's separately tested
   * job.
   */
  protected function setUpIconMedia(): Media {
    $this->installConfig(['field', 'system']);
    $type = $this->createMediaType('image');
    $media = Media::create(['bundle' => $type->id(), 'name' => 'Blueprint icon']);
    $media->save();
    return $media;
  }

  /**
   * The lean tree of any blueprint object's document (a revision too).
   */
  protected function builtLayoutOf($blueprint): array {
    return \Drupal::service('daedalus_blueprint.node_tree_builder')->build($this->host($blueprint));
  }

  /**
   * Collects error codes keyed by path from a failed batch result.
   */
  protected function errorCodes(BlueprintResult $result): array {
    $this->assertFalse($result->isSuccess(), 'Expected the batch to fail.');
    $codes = [];
    foreach ($result->getErrors() as $error) {
      $codes[$error->getPath()][] = $error->getCode();
    }
    return $codes;
  }

  /**
   * The hint a file blueprint's read-only refusal carries right now.
   */
  protected function readOnlyHint(string $id): string {
    try {
      $this->container->get('daedalus_blueprint_library.manager')->resolveUpdatable($id);
    }
    catch (BlueprintGestureException $exception) {
      $error = $exception->getErrors()[0];
      $this->assertSame('BLUEPRINT_READ_ONLY', $error->getCode());
      return $error->getHint();
    }
    $this->fail("The \"{$id}\" file blueprint must refuse to resolve as updatable.");
  }

}
