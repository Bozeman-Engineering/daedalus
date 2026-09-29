<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\daedalus_blueprint_api\Ajax\BlueprintApplyResponseCommand;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_layout\EventSubscriber\DaedalusUiReplaceMedia;
use Drupal\daedalus_ui\Event\LayoutBuilderReplaceMedia;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\file\Entity\File;
use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\SectionComponent;
use Drupal\media\Entity\Media;
use Drupal\Tests\daedalus\Kernel\NestedStorageKernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Route;

/**
 * The media drop on a placed block is one update_element op on the spine.
 *
 * The MediaUpload controller (daedalus_ui) uploads the dropped file, creates
 * the media entity and dispatches LayoutBuilderReplaceMedia with the block
 * component on the request query; this covers the subscriber that answers
 * it: the op runs on the root's working copy through the reference
 * handler, the write is staged (never saved), the history entry is labeled
 * and targeted at the block field, and the response is the standard
 * mutation-response payload as one daedalusBlueprintApplyResponse command.
 * A failed op (an unknown component) answers the same shape with the
 * spine's errors and stages nothing.
 *
 * @group daedalus_layout
 */
#[CoversClass(DaedalusUiReplaceMedia::class)]
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class ReplaceMediaOperationTest extends NestedStorageKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'file',
    'image',
    'media',
  ];

  /**
   * The component UUID of the placed photo block.
   */
  protected const BLOCK_UUID = '4d1f1d3e-0f1b-4c2a-9c3d-2b8e6a7f5c10';

  /**
   * The image media type id.
   */
  protected string $mediaBundle;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'image', 'file', 'media']);
    $this->mediaBundle = $this->createMediaType('image')->id();

    // The photo block: a plain media reference block, rendered through the
    // default entity_reference_entity_view chain (the drop target's shape).
    $this->createBlockContentType('photo', 'Photo');
    FieldStorageConfig::create([
      'field_name' => 'field_photo',
      'entity_type' => 'block_content',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'media'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_photo',
      'entity_type' => 'block_content',
      'bundle' => 'photo',
      'label' => 'Photo',
      'settings' => [
        'handler' => 'default:media',
        'handler_settings' => ['target_bundles' => [$this->mediaBundle => $this->mediaBundle]],
      ],
    ])->save();
    $this->container->get('entity_display.repository')
      ->getViewDisplay('block_content', 'photo')
      ->setComponent('field_photo', [
        'type' => 'entity_reference_entity_view',
        'settings' => ['view_mode' => 'default'],
      ])
      ->save();

    // The spine checks field edit access per field and the reference
    // handler resolves the target; the block keys the re-render swaps by
    // are stamped for edit-mode users.
    $this->setUpCurrentUser([], [
      'configure any layout',
      'use daedalus ui edit mode',
      'view media',
      'administer media',
      'access content',
    ]);
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
   * The saved host entity holding one inline photo block referencing $media.
   */
  protected function createHost(Media $media): EntityTest {
    $block = BlockContent::create([
      'type' => 'photo',
      'reusable' => FALSE,
      'info' => 'Photo block',
      'field_photo' => $media->id(),
    ]);
    $component = new SectionComponent(self::BLOCK_UUID, 'content', [
      'id' => 'inline_block:photo',
      'provider' => 'layout_builder',
      'label' => 'Photo block',
      'label_display' => FALSE,
      'view_mode' => 'full',
      'block_serialized' => serialize($block),
    ]);
    // A labeled host: entity_test's access handler reads the label for the
    // 'edit' check the edit-mode render gate runs.
    $entity = EntityTest::create([
      'name' => 'Photo host',
      OverridesSectionStorage::FIELD_NAME => [$this->createSection('layout_onecol', [$component])],
    ]);
    $entity->save();
    return EntityTest::load($entity->id());
  }

  /**
   * The working copy, as the storage handler and the controller serve it.
   */
  protected function working(EntityTest $entity): EntityTest {
    /** @var \Drupal\entity_test\Entity\EntityTest */
    return $this->container->get('daedalus_blueprint.storage_handler')->load('entity_test', $entity->id());
  }

  /**
   * The photo block's media target id as the given host object carries it.
   */
  protected function referencedMediaId(EntityTest $entity): ?int {
    $component = $entity->get(OverridesSectionStorage::FIELD_NAME)->get(0)->section->getComponent(self::BLOCK_UUID);
    // The saved row carries the block by revision id (the presave walker
    // saved it), the staged copy by serialized block; the adapter reads both.
    $block = $this->container->get('daedalus_blueprint.daedalus_layout_adapter')->extractBlockContent($component);
    $id = $block->get('field_photo')->target_id;
    return $id === NULL ? NULL : (int) $id;
  }

  /**
   * Dispatches the event under a request shaped like the client's upload.
   *
   * The client (file-drag.js) posts to the replace-media route with the mode
   * and the block context on the query; the route carries the host entity. The
   * request stays on the stack for the whole round because the subscriber
   * reads the query and the response render reads the mode and the route.
   */
  protected function dispatch(EntityTest $entity, Media $media, ?string $block_uuid = self::BLOCK_UUID): LayoutBuilderReplaceMedia {
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
      $this->container->get('daedalus_ui.ui')->clearDerivedEntity();
    }
    return $event;
  }

  /**
   * The one apply-response command's payload, asserting it is the only command.
   */
  protected function appliedPayload(AjaxResponse $response): array {
    $commands = $response->getCommands();
    $this->assertCount(1, $commands, 'The drop answers exactly one command.');
    $this->assertSame(BlueprintApplyResponseCommand::COMMAND, $commands[0]['command']);
    return $commands[0]['payload'];
  }

  /**
   * The drop stages one update_element op and answers the mutation payload.
   */
  public function testDropCommitsAsAnOp(): void {
    $original = $this->createImageMedia('original');
    $replacement = $this->createImageMedia('replacement');
    $entity = $this->createHost($original);

    $event = $this->dispatch($entity, $replacement);
    $payload = $this->appliedPayload($event->getResponse());
    $this->assertTrue($payload['success'], print_r($payload['errors'], TRUE));

    // The write: the working copy's block references the new media, the
    // stored row still the original (staging never saves).
    $this->assertSame((int) $replacement->id(), $this->referencedMediaId($this->working($entity)));
    // The stored block revision: read past the entity memory cache, which
    // serves the one instance the op just mutated (the saved row carries
    // the block by revision id, the staged copy by serialized block).
    $this->container->get('entity_type.manager')->getStorage('block_content')->resetCache();
    $stored = $this->container->get('entity_type.manager')->getStorage('entity_test')->loadUnchanged($entity->id());
    $this->assertSame((int) $original->id(), $this->referencedMediaId($stored), 'The replacement is staged in tempstore, not saved.');
    $this->assertSame('tempstore', $payload['persisted']);

    // The contract: the payload describes the host, targets the block field
    // (undo swaps the block, not the page) and carries the mirror.
    $targets = [['uuid' => self::BLOCK_UUID, 'field_name' => 'field_photo']];
    $this->assertSame($entity->uuid(), $payload['entity_uuid']);
    $this->assertSame($targets, $payload['targets']);
    $this->assertArrayHasKey('blueprint', $payload);
    $this->assertNull($payload['langcode'], 'No langcode on the query means the default translation.');

    // The re-render is keyed by the block (its field key when the render
    // stamps one, else the nearest stamped ancestor), never the whole page.
    $keys = array_keys($payload['markup']);
    $this->assertCount(1, $keys, print_r($keys, TRUE));
    $this->assertContains($keys[0], [
      SelectableKey::blockField(self::BLOCK_UUID, 'field_photo'),
      SelectableKey::block(self::BLOCK_UUID),
    ]);
    $this->assertStringContainsString('replacement', $payload['markup'][$keys[0]], 'The swapped subtree renders the new media.');

    // History rides the payload: the entry is labeled by the field and
    // targeted at it.
    $entries = $payload['history']['entries'];
    $latest = end($entries);
    $this->assertSame('Replaced Photo', $latest['label']);
    $this->assertSame($targets, $latest['targets']);
    $this->assertFalse($latest['baseline']);
  }

  /**
   * An unknown component answers the spine's failure shape and stages nothing.
   */
  public function testUnknownComponentFails(): void {
    $original = $this->createImageMedia('original');
    $replacement = $this->createImageMedia('replacement');
    $entity = $this->createHost($original);

    $event = $this->dispatch($entity, $replacement, 'not-a-component');
    $payload = $this->appliedPayload($event->getResponse());
    $this->assertFalse($payload['success']);
    $this->assertNotEmpty($payload['errors']);
    $this->assertArrayNotHasKey('persisted', $payload);
    $this->assertArrayNotHasKey('history', $payload);
    $this->assertSame((int) $original->id(), $this->referencedMediaId($this->working($entity)), 'A failed op stages nothing.');
    $this->assertSame([], $this->container->get('daedalus_tempstore.history_manager')->metadata($this->working($entity))['entries'], 'No history entry was produced.');
  }

  /**
   * A Layout Builder drop without its block context is a bad request.
   */
  public function testMissingBlockContextIsRefused(): void {
    $entity = $this->createHost($this->createImageMedia('original'));
    $this->expectException(BadRequestHttpException::class);
    $this->dispatch($entity, $this->createImageMedia('replacement'), NULL);
  }

}
