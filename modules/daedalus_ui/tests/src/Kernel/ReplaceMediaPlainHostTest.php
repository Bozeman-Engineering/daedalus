<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\daedalus_blueprint_api\Ajax\BlueprintApplyResponseCommand;
use Drupal\daedalus_ui\Event\LayoutBuilderReplaceMedia;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\entity_test\EntityTestHelper;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * A media drop on a host without a document is an update_element on the host.
 *
 * A plain host (an entity of a bundle whose display is not overridable)
 * renders its template and owns only its fields. The MediaUpload
 * controller dispatches LayoutBuilderReplaceMedia for every drop, and the
 * subscriber commits one update_element on the entity whose field the
 * drop landed on: the host's root for its own field, whether the client
 * named no block or the template's field block presenting that field;
 * the template's own media block is refused at the op door as foreign,
 * and nothing is staged.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class ReplaceMediaPlainHostTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'entity_test',
    'file',
    'image',
    'media',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_layout',
  ];

  /**
   * The non-overridable bundle.
   */
  protected const PLAIN_BUNDLE = 'plain';

  /**
   * The template's identities: its section, its photo block, its field block.
   */
  protected const SECTION_UUID = '5e000000-0000-4000-8000-000000000001';
  protected const BLOCK_UUID = '5e000000-0000-4000-8000-000000000002';
  protected const FIELD_UUID = '5e000000-0000-4000-8000-000000000003';

  /**
   * The image media type id.
   */
  protected string $mediaBundle;

  /**
   * The media the template's block and the host's field reference at first.
   */
  protected Media $original;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installSchema('layout_builder', ['inline_block_usage']);
    $this->installConfig(['field', 'system', 'filter', 'block_content', 'image', 'file', 'media']);
    $this->mediaBundle = $this->createMediaType('image')->id();
    $this->original = $this->createImageMedia('original');

    // The photo block type and the host's own photo field, both plain
    // media references.
    BlockContentType::create(['id' => 'photo', 'label' => 'Photo', 'revision' => FALSE])->save();
    $this->createMediaReference('block_content', 'photo');
    EntityTestHelper::createBundle(self::PLAIN_BUNDLE, 'Plain', 'entity_test');
    $this->createMediaReference('entity_test', self::PLAIN_BUNDLE);

    // The template: Layout Builder on, overrides off; one section holding
    // the photo block and the field block presenting the host's photo.
    $section = new Section('layout_onecol');
    $section->setThirdPartySetting('daedalus_layout', 'uuid', self::SECTION_UUID);
    $section->appendComponent(new SectionComponent(self::BLOCK_UUID, 'content', [
      'id' => 'inline_block:photo',
      'label' => 'Photo block',
      'label_display' => '0',
      'block_serialized' => serialize(BlockContent::create([
        'type' => 'photo',
        'info' => 'Photo block',
        'reusable' => FALSE,
        'field_photo' => $this->original->id(),
      ])),
    ]));
    $section->appendComponent(new SectionComponent(self::FIELD_UUID, 'content', [
      'id' => 'field_block:entity_test:' . self::PLAIN_BUNDLE . ':field_photo',
      'label' => 'Photo',
      'label_display' => '0',
      'context_mapping' => ['entity' => 'layout_builder.entity', 'view_mode' => 'view_mode'],
      'formatter' => [
        'label' => 'hidden',
        'type' => 'entity_reference_entity_view',
        'settings' => ['view_mode' => 'default'],
        'third_party_settings' => [],
      ],
    ]));
    LayoutBuilderEntityViewDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => self::PLAIN_BUNDLE,
      'mode' => 'default',
      'status' => TRUE,
    ])->enableLayoutBuilder()->appendSection($section)->save();

    // The actor: update access on the host (the host access policy), the
    // edit-mode keys on the render, media access for the reference.
    $this->setUpCurrentUser([], [
      'administer entity_test content',
      'configure any layout',
      'use daedalus ui edit mode',
      'view media',
      'administer media',
      'access content',
    ]);
  }

  /**
   * Adds a single-value media reference field named field_photo to a bundle.
   */
  protected function createMediaReference(string $entity_type, string $bundle): void {
    if (FieldStorageConfig::loadByName($entity_type, 'field_photo') === NULL) {
      FieldStorageConfig::create([
        'field_name' => 'field_photo',
        'entity_type' => $entity_type,
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'media'],
      ])->save();
    }
    FieldConfig::create([
      'field_name' => 'field_photo',
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => 'Photo',
      'settings' => [
        'handler' => 'default:media',
        'handler_settings' => ['target_bundles' => [$this->mediaBundle => $this->mediaBundle]],
      ],
    ])->save();
    if ($entity_type === 'block_content') {
      $this->container->get('entity_display.repository')
        ->getViewDisplay('block_content', $bundle)
        ->setComponent('field_photo', [
          'type' => 'entity_reference_entity_view',
          'settings' => ['view_mode' => 'default'],
        ])
        ->save();
    }
  }

  /**
   * Creates image media whose source file is a copy of druplicon.png.
   */
  protected function createImageMedia(string $name): Media {
    $type = $this->container->get('entity_type.manager')->getStorage('media_type')->load($this->mediaBundle);
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();
    $uri = 'public://' . $name . '.png';
    $this->container->get('file_system')->copy($this->root . '/core/misc/druplicon.png', $uri);
    $file = File::create(['uri' => $uri, 'filename' => basename($uri)]);
    $file->save();
    $media = Media::create([
      'bundle' => $this->mediaBundle,
      'name' => $name,
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

  /**
   * The saved plain host, its own photo referencing the original media.
   */
  protected function createHost(): EntityTest {
    $entity = EntityTest::create([
      'type' => self::PLAIN_BUNDLE,
      'name' => 'Plain host',
      'field_photo' => $this->original->id(),
    ]);
    $entity->save();
    return EntityTest::load($entity->id());
  }

  /**
   * The working copy, as the storage handler and the controller serve it.
   */
  protected function working(EntityTest $entity): EntityTest {
    /** @var \Drupal\entity_test\Entity\EntityTest */
    return $this->container->get('daedalus_blueprint.storage_handler')->load('entity_test', $entity->id())->entity();
  }

  /**
   * The host's own photo, read past the entity memory cache.
   */
  protected function storedPhotoId(EntityTest $entity): int {
    $stored = $this->container->get('entity_type.manager')->getStorage('entity_test')->loadUnchanged($entity->id());
    return (int) $stored->get('field_photo')->target_id;
  }

  /**
   * Dispatches the event as the controller does, under the client's request.
   */
  protected function dispatch(EntityTest $entity, Media $media, ?string $block_uuid): LayoutBuilderReplaceMedia {
    $query = 'navigationMode=edit' . ($block_uuid === NULL ? '' : '&blockUuid=' . $block_uuid);
    $request = Request::create('/daedalus-ui/replace-media/entity_test/' . $entity->id() . '/default/field_photo/' . $this->mediaBundle . '?' . $query, 'POST');
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'daedalus_ui.replace_media');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route('/daedalus-ui/replace-media/{entity_type}/{entity}/{view_mode}/{media_reference}/{media_bundle}'));
    $request->attributes->set('entity_type', 'entity_test');
    $request->attributes->set('entity', $entity);
    $request->attributes->set('_raw_variables', new InputBag(['entity_type' => 'entity_test', 'entity' => $entity->id()]));

    $event = new LayoutBuilderReplaceMedia($media, $this->working($entity), 'default', 'field_photo');
    $request_stack = $this->container->get('request_stack');
    $request_stack->push($request);
    try {
      $this->container->get('event_dispatcher')->dispatch($event, LayoutBuilderReplaceMedia::class);
    }
    finally {
      $request_stack->pop();
      $this->container->get('daedalus_ui.ui')->clearDerivedHost();
    }
    return $event;
  }

  /**
   * The one apply-response command's payload.
   */
  protected function appliedPayload(AjaxResponse $response): array {
    $commands = $response->getCommands();
    $this->assertCount(1, $commands, 'The drop answers exactly one command.');
    $this->assertSame(BlueprintApplyResponseCommand::COMMAND, $commands[0]['command']);
    return $commands[0]['payload'];
  }

  /**
   * Asserts the drop was staged as one op on the host's own field.
   */
  protected function assertStagedOnTheRoot(EntityTest $entity, Media $replacement, LayoutBuilderReplaceMedia $event): void {
    $payload = $this->appliedPayload($event->getResponse());
    $this->assertTrue($payload['success'], print_r($payload['errors'] ?? [], TRUE));
    $targets = [['uuid' => $entity->uuid(), 'field_name' => 'field_photo']];
    $this->assertSame($targets, $payload['targets'], 'The op targets the host\'s own field.');
    $this->assertSame('tempstore', $payload['persisted']);
    $this->assertSame((int) $replacement->id(), (int) $this->working($entity)->get('field_photo')->target_id, 'The working copy references the new media.');
    $this->assertSame((int) $this->original->id(), $this->storedPhotoId($entity), 'The replacement is staged, never saved.');
    $entries = $payload['history']['entries'];
    $latest = end($entries);
    $this->assertSame('Replaced Photo', $latest['label']);
    $this->assertSame($targets, $latest['targets']);
  }

  /**
   * No block context: the host's own field, one op on the root.
   */
  public function testHostFieldDropIsAnOpOnTheRoot(): void {
    $entity = $this->createHost();
    $replacement = $this->createImageMedia('replacement');
    $this->assertStagedOnTheRoot($entity, $replacement, $this->dispatch($entity, $replacement, NULL));
  }

  /**
   * The template's field block presenting the host's field: the root's op too.
   */
  public function testFieldBlockDropIsAnOpOnTheRoot(): void {
    $entity = $this->createHost();
    $replacement = $this->createImageMedia('replacement');
    $this->assertStagedOnTheRoot($entity, $replacement, $this->dispatch($entity, $replacement, self::FIELD_UUID));
  }

  /**
   * The template's own media block is refused as foreign; nothing is staged.
   */
  public function testTemplateBlockDropIsRefused(): void {
    $entity = $this->createHost();
    $replacement = $this->createImageMedia('replacement');
    $payload = $this->appliedPayload($this->dispatch($entity, $replacement, self::BLOCK_UUID)->getResponse());
    $this->assertFalse($payload['success']);
    $this->assertSame('FOREIGN_DOCUMENT', $payload['errors'][0]['code'], print_r($payload['errors'], TRUE));
    $this->assertArrayNotHasKey('persisted', $payload);
    $this->assertArrayNotHasKey('history', $payload);
    $this->assertSame((int) $this->original->id(), (int) $this->working($entity)->get('field_photo')->target_id, 'A refused op stages nothing on the host.');
    $host = $this->container->get('daedalus_blueprint.storage_handler')->load('entity_test', $entity->id());
    $this->assertSame([], $this->container->get('daedalus_tempstore.history_manager')->metadata($host)['entries'], 'No history entry was produced.');
  }

}
