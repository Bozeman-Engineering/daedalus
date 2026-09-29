<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\OperationType\GenericType\ImageFile;
use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_ai\Plugin\AiFunctionCall\ViewFile;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\daedalus_blueprint_ai\ConversationContext;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the view_file tool: a name back to its bytes, by kind, for its owner.
 *
 * What must not regress:
 * - The kind split is FileKind's, shared with the ATTACHMENTS block: text
 *   comes back as text, a raster image through the ai_agents image
 *   contract (getPluginInstance()/getResult()/getContextValues()['_images'],
 *   walked literally as the wrapper walks it), a PDF as a refusal (the
 *   decision: PDFs are seen only in the turn they arrive), anything else
 *   as a name.
 * - A name resolves only through the attachment store for the current
 *   conversation and the current user: an unknown name, another user's
 *   file and a run without a conversation all answer with words, never
 *   with bytes.
 * - `media:<uuid>` opens the source file of a media item the user may
 *   view, and nothing else.
 *
 * The tool is built exactly as its create() factory would, over a real
 * store (its container collaborators, as AttachmentsTest builds it) and a
 * conversation context stamped by hand; daedalus_ai itself is not enabled
 * (its AI-stack chain is unrelated).
 */
#[CoversClass(ViewFile::class)]
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
final class ViewFileToolTest extends KernelTestBase {

  use UserCreationTrait;
  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'key',
    'ai',
    'file',
    'field',
    'image',
    'media',
  ];

  /**
   * The conversation id the fixture stamps.
   */
  private const THREAD = 'thread-view';

  /**
   * A 1x1 transparent PNG: genuine image bytes.
   */
  private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

  /**
   * The store the names resolve through.
   */
  private Attachments $attachments;

  /**
   * The conversation the tool reads.
   */
  private ConversationContext $conversation;

  /**
   * The user holding the files.
   */
  private UserInterface $owner;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'file', 'image', 'media']);

    $this->owner = $this->setUpCurrentUser(['name' => 'owner'], ['view media']);
    $this->createMediaType('image', ['id' => 'image', 'label' => 'Image']);

    $this->attachments = new Attachments(
      $this->container->get('file.usage'),
      new DialectAliasMap($this->container->get('tempstore.shared')),
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get('keyvalue.expirable'),
      $this->container->get('database'),
      $this->container->get('datetime.time'),
    );
    $this->container->set('daedalus_ai.attachments', $this->attachments);
    $this->conversation = new ConversationContext();
    $this->conversation->set(self::THREAD);
    $this->container->set('daedalus_blueprint_ai.conversation_context', $this->conversation);
  }

  /**
   * Each kind comes back the way the ATTACHMENTS block promised.
   */
  public function testKindsByName(): void {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>';
    $this->attachments->attach($this->saveFile('hero.svg', 'image/svg+xml', $svg), self::THREAD);
    $this->attachments->attach($this->saveFile('shot.png', 'image/png', base64_decode(self::PNG)), self::THREAD);
    $this->attachments->attach($this->saveFile('brief.pdf', 'application/pdf', '%PDF-1.4'), self::THREAD);
    $this->attachments->attach($this->saveFile('bundle.zip', 'application/zip', 'PK'), self::THREAD);

    // Text: the bytes, fenced under the file's name, no image.
    $tool = $this->tool('hero.svg');
    $tool->execute();
    $output = $tool->getReadableOutput();
    $this->assertStringStartsWith('hero.svg (image/svg+xml, ', $output);
    $this->assertStringContainsString("<file name=\"hero.svg\">\n$svg\n</file>", $output);
    $this->assertNull($tool->getResult());

    // Image: the ai_agents duck-typed chain, walked literally.
    $tool = $this->tool('shot.png');
    $tool->execute();
    $this->assertStringContainsString('shot.png (image/png, ', $tool->getReadableOutput());
    $this->assertStringContainsString('arrives as an image on your next turn', $tool->getReadableOutput());
    $this->assertTrue(method_exists($tool, 'getPluginInstance'));
    $instance = $tool->getPluginInstance();
    $this->assertTrue(method_exists($instance, 'getResult'));
    $images = $instance->getResult()->getContextValues()['_images'];
    $this->assertCount(1, $images);
    $this->assertInstanceOf(ImageFile::class, $images[0]);
    $this->assertSame('image/png', $images[0]->getMimeType());
    $this->assertSame('shot.png', $images[0]->getFilename());
    $this->assertSame(base64_decode(self::PNG), $images[0]->getBinary());

    // PDF: the recorded decision, in words.
    $tool = $this->tool('brief.pdf');
    $tool->execute();
    $this->assertStringContainsString('PDFs cannot be re-opened', $tool->getReadableOutput());
    $this->assertStringContainsString('attach it again', $tool->getReadableOutput());
    $this->assertNull($tool->getResult());

    // Other: named, never delivered.
    $tool = $this->tool('bundle.zip');
    $tool->execute();
    $this->assertStringContainsString('bundle.zip (application/zip, ', $tool->getReadableOutput());
    $this->assertStringContainsString('not a kind you can read', $tool->getReadableOutput());
    $this->assertNull($tool->getResult());
  }

  /**
   * Words, never bytes, for what the model was not handed.
   */
  public function testRefusals(): void {
    $this->attachments->attach($this->saveFile('mine.svg', 'image/svg+xml', '<svg/>'), self::THREAD);

    $tool = $this->tool('missing.svg');
    $tool->execute();
    $this->assertStringContainsString('No file named "missing.svg" in this conversation', $tool->getReadableOutput());
    $this->assertNull($tool->getResult());

    // Another user's file in the same thread is not this user's to open.
    $other = $this->createUser([], 'other');
    $this->container->get('current_user')->setAccount($other);
    $this->attachments->attach($this->saveFile('theirs.png', 'image/png', base64_decode(self::PNG), $other), self::THREAD);
    $this->container->get('current_user')->setAccount($this->owner);
    $tool = $this->tool('theirs.png');
    $tool->execute();
    $this->assertStringContainsString('No file named "theirs.png"', $tool->getReadableOutput());
    $this->assertNull($tool->getResult());

    $tool = $this->tool('  ');
    $tool->execute();
    $this->assertStringContainsString('Name the file to open', $tool->getReadableOutput());

    // Without a conversation there is no store to read from.
    $this->conversation->set('');
    $tool = $this->tool('mine.svg');
    $tool->execute();
    $this->assertStringContainsString('no conversation', $tool->getReadableOutput());
    $this->assertNull($tool->getResult());
  }

  /**
   * The media road: a viewable media item's source file, and only that.
   */
  public function testMediaSourceFile(): void {
    $type = $this->container->get('entity_type.manager')->getStorage('media_type')->load('image');
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();
    $file = $this->saveFile('generated.png', 'image/png', base64_decode(self::PNG));
    $media = Media::create([
      'bundle' => 'image',
      'name' => 'Generated',
      $source_field => ['target_id' => $file->id(), 'alt' => 'A generated image'],
    ]);
    $media->save();
    $empty = Media::create(['bundle' => 'image', 'name' => 'Empty']);
    $empty->save();

    // No conversation is needed on the media road.
    $this->conversation->set('');
    $tool = $this->tool('media:' . $media->uuid());
    $tool->execute();
    $this->assertStringContainsString('media:' . $media->uuid() . ' (image/png, ', $tool->getReadableOutput());
    $images = $tool->getPluginInstance()->getResult()->getContextValues()['_images'];
    $this->assertCount(1, $images);
    $this->assertSame('generated.png', $images[0]->getFilename());
    $this->assertSame(base64_decode(self::PNG), $images[0]->getBinary());

    $tool = $this->tool('media:' . $empty->uuid());
    $tool->execute();
    $this->assertStringContainsString('has no file of its own', $tool->getReadableOutput());
    $this->assertNull($tool->getResult());

    foreach (['media:', 'media:not-a-uuid'] as $name) {
      $tool = $this->tool($name);
      $tool->execute();
      $this->assertStringContainsString('No media item', $tool->getReadableOutput());
      $this->assertNull($tool->getResult());
    }

    // A user who may not view media is told there is no such item.
    $this->container->get('current_user')->setAccount($this->createUser([], 'viewer'));
    $tool = $this->tool('media:' . $media->uuid());
    $tool->execute();
    $this->assertStringContainsString('No media item', $tool->getReadableOutput());
    $this->assertStringContainsString('you can view', $tool->getReadableOutput());
    $this->assertNull($tool->getResult());
  }

  /**
   * Builds the tool exactly as the plugin manager would.
   */
  private function tool(string $name): ViewFile {
    $attribute = (new \ReflectionClass(ViewFile::class))
      ->getAttributes(FunctionCall::class)[0]
      ->newInstance();
    $attribute->setClass(ViewFile::class);
    $definition = $attribute->get();
    $tool = ViewFile::create($this->container, [], $definition['id'], $definition);
    $tool->setContextValue('name', $name);
    return $tool;
  }

  /**
   * Saves a managed file with the given bytes, MIME type and owner.
   */
  private function saveFile(string $name, string $mime, string $content, ?UserInterface $owner = NULL): FileInterface {
    $directory = 'public://view-file';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $uri = "$directory/$name";
    file_put_contents($uri, $content);
    $file = File::create([
      'uri' => $uri,
      'filename' => $name,
      'filemime' => $mime,
      'uid' => ($owner ?? $this->owner)->id(),
      'status' => 0,
    ]);
    $file->save();
    return $file;
  }

}
