<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_ai\FieldHandler\FileReferenceHandler;
use Drupal\daedalus_blueprint\FieldHandler\MediaFromFileInterface;
use Drupal\daedalus_blueprint\FieldHandler\MediaGenerationInterface;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\media\Entity\Media;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the file handle on media reference fields.
 *
 * FileReferenceHandler is the field grammar's face of "place a file the
 * conversation holds": a media reference item carrying {_file: name}
 * resolves the name through the attachment store for the write's
 * conversation ($options['conversation'], the op spine's carrier) and the
 * current user, mints media from the file through the shared minter
 * (bundle by MIME: raster to image, SVG to vector_image) and sets the
 * reference at once. The invariants under test:
 *
 * - A held SVG lands as vector_image media whose source file IS the held
 *   file (now permanent through the media's usage), named by the file and
 *   described by alt_text; a held raster lands as image media; alt_text
 *   defaults to the filename; the LLM's [{...}] wrapping is unwrapped; a
 *   name can be placed again (the conversation keeps holding the file).
 * - Refusals name the model's word: an unknown name, a name from another
 *   conversation, another user's file, a write without a conversation
 *   (UNKNOWN_FILE), an empty name (INVALID_FILE_REFERENCE), a file no
 *   media type accepts (UNSUPPORTED_FILE), a bundle the user may not
 *   create (ACCESS_DENIED_FIELD); nothing is minted on a refusal.
 * - Everything without `_file` falls through to the generation handler
 *   and, below it, the plain reference: one handler chain, one order.
 * - The handler states the file vocabulary (MediaFromFileInterface)
 *   beside the generation vocabulary it inherits, and the field handler
 *   manager publishes both for the doors.
 *
 * Built like ImageGenerationHandlerTest (the daedalus_ai module stays
 * uninstalled; the handler is wired from its real collaborators), with a
 * real attachment store over the container's alias map.
 */
#[CoversClass(FileReferenceHandler::class)]
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class FileReferenceHandlerTest extends DaedalusAiKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['svg_image_field'];

  /**
   * The conversation the fixture holds files for.
   */
  private const THREAD = 'thread-place';

  /**
   * The handler under test.
   */
  protected FileReferenceHandler $fileHandler;

  /**
   * The store the names resolve through.
   */
  protected Attachments $attachments;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The vector bundle beside the base fixture's image bundle: the two
    // bundles the minter reads off a file's MIME type.
    $this->createMediaType('svg', ['id' => 'vector_image', 'label' => 'Vector image']);

    // Placing creates media as the current user; the base user is
    // view-only, so re-log as an editor who may create both bundles.
    $this->currentUser = $this->setUpCurrentUser(
      ['name' => 'placing editor'],
      ['view media', 'access content', 'create image media', 'create vector_image media'],
    );

    $this->attachments = new Attachments(
      $this->container->get('file.usage'),
      $this->container->get('daedalus_blueprint.dialect_alias_map'),
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get('keyvalue.expirable'),
      $this->container->get('database'),
      $this->container->get('datetime.time'),
    );
    $this->fileHandler = new FileReferenceHandler(
      $this->container->get('entity_type.manager'),
      $this->container->get('logger.channel.default'),
      $this->minter,
      $this->collector,
      $this->dimensionManager,
      $this->container->get('daedalus_blueprint.access_checker'),
      $this->attachments,
      $this->mediaMinter,
    );
  }

  /**
   * A held file becomes media and the reference is final at once.
   */
  public function testPlacesHeldFiles(): void {
    $definition = $this->mediaFieldDefinition();
    $svg = $this->hold('hero.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');
    $png = $this->hold('shot.png', 'image/png', $this->pngBytes());
    $this->assertTrue(File::load($svg->id())->isPermanent(), 'The store holds the file on usage, which makes it permanent.');

    $result = $this->fileHandler->deserialize(
      ['_file' => 'hero.svg', 'alt_text' => 'The hero mark'],
      $definition,
      ['conversation' => self::THREAD],
    );
    $this->assertFalse($result->hasErrors(), print_r($result->getErrors(), TRUE));
    $this->assertSame([], $result->getWarnings());
    $value = $result->getValue();
    $this->assertIsArray($value);
    $media = Media::load((int) $value['target_id']);
    $this->assertNotNull($media, 'The reference points at saved media at once.');
    $this->assertSame('vector_image', $media->bundle(), 'An SVG lands in the vector bundle by its MIME type.');
    $this->assertSame('hero.svg', $media->getName(), 'The media is named by the file.');
    $source = $media->getSource()->getSourceFieldDefinition($media->bundle->entity)->getName();
    $this->assertSame((int) $svg->id(), (int) $media->get($source)->target_id, 'The media\'s source file IS the held file.');
    $this->assertSame('The hero mark', $media->get($source)->alt);
    $this->assertTrue(File::load($svg->id())->isPermanent());
    // Nothing is deferred: no generation job, no pending target.
    $this->assertNull($this->jobStore->get($media->uuid()));
    $this->assertFalse($this->collector->hasPending());

    // A raster lands in the image bundle; alt_text defaults to the filename;
    // the LLM's [{...}] wrapping is unwrapped.
    $result = $this->fileHandler->deserialize([['_file' => 'shot.png']], $definition, ['conversation' => self::THREAD]);
    $this->assertFalse($result->hasErrors(), print_r($result->getErrors(), TRUE));
    $raster = Media::load((int) $result->getValue()['target_id']);
    $this->assertSame('image', $raster->bundle());
    $this->assertSame((int) $png->id(), (int) $raster->get('field_media_image')->target_id);
    $this->assertSame('shot.png', $raster->get('field_media_image')->alt);

    // The conversation keeps holding the file: placing the name again mints
    // another media item over the same file.
    $again = $this->fileHandler->deserialize(['_file' => 'hero.svg'], $definition, ['conversation' => self::THREAD]);
    $this->assertFalse($again->hasErrors());
    $this->assertNotSame((int) $media->id(), (int) $again->getValue()['target_id']);
    $this->assertNotNull($this->attachments->resolve(self::THREAD, 'hero.svg'), 'The name still resolves after placing.');
  }

  /**
   * Refusals name the model's word and mint nothing.
   */
  public function testRefusals(): void {
    $definition = $this->mediaFieldDefinition();
    $this->hold('hero.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    $this->hold('notes.txt', 'text/plain', 'plain words');
    $media_before = $this->mediaCount();

    $cases = [
      'unknown name' => [['_file' => 'missing.svg'], ['conversation' => self::THREAD], 'UNKNOWN_FILE'],
      'another conversation' => [['_file' => 'hero.svg'], ['conversation' => 'thread-other'], 'UNKNOWN_FILE'],
      'no conversation' => [['_file' => 'hero.svg'], [], 'UNKNOWN_FILE'],
      'empty name' => [['_file' => '  '], ['conversation' => self::THREAD], 'INVALID_FILE_REFERENCE'],
      'non-string name' => [['_file' => 42], ['conversation' => self::THREAD], 'INVALID_FILE_REFERENCE'],
      'no media type accepts it' => [
        ['_file' => 'notes.txt'],
        ['conversation' => self::THREAD],
        'UNSUPPORTED_FILE',
      ],
    ];
    foreach ($cases as $case => [$value, $options, $code]) {
      $result = $this->fileHandler->deserialize($value, $definition, $options);
      $this->assertTrue($result->hasErrors(), $case);
      $errors = $result->getErrors();
      $this->assertCount(1, $errors, $case);
      $this->assertSame($code, $errors[0]->getCode(), $case);
      $this->assertSame('fields.field_media_image', $errors[0]->getPath(), $case);
      $this->assertNull($result->getValue(), $case);
    }
    $this->assertStringContainsString('"missing.svg"', $this->fileHandler->deserialize(['_file' => 'missing.svg'], $definition, ['conversation' => self::THREAD])->getErrors()[0]->getMessage());

    // Another user's file never resolves, even under the same name in the
    // same conversation.
    $other = $this->createUser(['view media', 'access content', 'create vector_image media'], 'other');
    $this->hold('theirs.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>', $other);
    $result = $this->fileHandler->deserialize(['_file' => 'theirs.svg'], $definition, ['conversation' => self::THREAD]);
    $this->assertSame('UNKNOWN_FILE', $result->getErrors()[0]->getCode());

    // A bundle the user may not create: the raster-only editor holds an SVG.
    $raster_only = $this->setUpCurrentUser(
      ['name' => 'raster only'],
      ['view media', 'access content', 'create image media'],
    );
    $this->hold('mine.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>', $raster_only);
    $result = $this->fileHandler->deserialize(['_file' => 'mine.svg'], $definition, ['conversation' => self::THREAD]);
    $this->assertSame('ACCESS_DENIED_FIELD', $result->getErrors()[0]->getCode());
    $this->assertStringContainsString('vector_image', $result->getErrors()[0]->getMessage());

    $this->assertSame($media_before, $this->mediaCount(), 'No refusal minted anything.');
  }

  /**
   * Without `_file` the chain below answers: generation, then reference.
   */
  public function testFallsThroughToGenerationAndReference(): void {
    $definition = $this->mediaFieldDefinition();
    $this->assertTrue($this->fileHandler->applies($definition));
    $this->assertFalse($this->fileHandler->applies(EntityTest::create()->getFieldDefinition('name')));

    $generated = $this->fileHandler->deserialize(['_generate' => 'a calm lake', 'alt_text' => 'Lake'], $definition, ['conversation' => self::THREAD]);
    $this->assertFalse($generated->hasErrors());
    $minted = Media::load((int) $generated->getValue()['target_id']);
    $this->assertNotNull($this->jobStore->get($minted->uuid()), 'A generation request still queues its job through the inherited path.');
    $this->assertTrue($this->collector->hasPending());

    $plain = $this->fileHandler->deserialize(['target_id' => $this->existingMediaId], $definition);
    $this->assertFalse($plain->hasErrors());
    $this->assertSame(['target_id' => $this->existingMediaId], $plain->getValue());
  }

  /**
   * The handler states the file vocabulary beside the generation one.
   */
  public function testPublishesTheFileVocabulary(): void {
    $this->assertInstanceOf(MediaFromFileInterface::class, $this->fileHandler);
    $this->assertInstanceOf(MediaGenerationInterface::class, $this->fileHandler);
    $this->assertStringContainsString('{_file: "name", alt_text:', $this->fileHandler->getGuidance());
    $this->assertStringContainsString('{_generate:', $this->fileHandler->getGuidance());
    $this->assertStringNotContainsString('_file', $this->fileHandler->getFileGuidance(), 'The file guidance is door-neutral: it names no key.');

    // The manager publishes both vocabularies for the doors (the schema
    // builder carries them into block_fields; the catalog builder reads
    // the interfaces to publish file= and generate=).
    $manager = $this->container->get('daedalus_blueprint.field_handler_manager');
    $manager->addHandler($this->fileHandler, 'entity_reference', 20);
    $info = $manager->inspectFieldDefinition($this->mediaFieldDefinition());
    $this->assertSame($this->fileHandler->getFileGuidance(), $info['file']['guidance']);
    $this->assertSame($this->fileHandler->getGenerationGuidance(), $info['generation']['guidance']);
    $this->assertSame($this->fileHandler, $manager->getHandler($this->mediaFieldDefinition()), 'Above the generation handler in the chain.');
  }

  /**
   * Saves a temporary file the way the lane leaves one and holds it.
   */
  private function hold(string $name, string $mime, string $bytes, ?UserInterface $owner = NULL): FileInterface {
    $uri = 'public://' . $name;
    file_put_contents($uri, $bytes);
    $file = File::create([
      'uri' => $uri,
      'filename' => $name,
      'filemime' => $mime,
      'uid' => ($owner ?? $this->currentUser)->id(),
      'status' => 0,
    ]);
    $file->save();
    $this->attachments->attach($file, self::THREAD);
    return $file;
  }

  /**
   * A 1x1 PNG.
   */
  private function pngBytes(): string {
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
  }

  /**
   * Counts the saved media entities.
   */
  private function mediaCount(): int {
    return (int) $this->container->get('entity_type.manager')
      ->getStorage('media')
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

}
