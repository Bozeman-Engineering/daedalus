<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_ai\StyleValue\FileStyleValueHandler;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\daedalus_stylish\StyleValueResolver;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the `_file` handled value on the style grammar's input tier.
 *
 * FileStyleValueHandler is the style grammar's face of the file handle:
 * a media-capable style property value may arrive as {"_file": "name"}
 * (the dialect's url('file:<name>') lands as exactly that) and resolves
 * at write time to the canonical stored token url('media:<uuid>') of the
 * media minted from the held file. The invariants under test:
 *
 * - CHECK is side-effect-free and answers every refusal before COMMIT:
 *   shape errors (empty name, bad alt_text, unsupported keys), an unknown
 *   name, a name from another conversation, a write without a
 *   conversation, another user's file (UNKNOWN_FILE), a file no media
 *   type accepts (UNSUPPORTED_FILE) and a bundle the user may not create
 *   (ACCESS_DENIED_ENTITY); nothing is minted.
 * - The stand-in is plain CSS ("none"), never a media token.
 * - COMMIT mints media from the held file (bundle by MIME, the source
 *   file IS the held file, named by the file, alt_text on the source) and
 *   resolves to the token for its uuid; nothing is deferred.
 * - Through the REAL StyleValueResolver, with the conversation threaded
 *   by the lanes, a layers list joins a literal and the resolved token.
 *
 * Built like GenerateStyleValueHandlerTest (daedalus_ai uninstalled,
 * daedalus_stylish enabled for the seam), with a real attachment store
 * over the container's alias map.
 */
#[CoversClass(FileStyleValueHandler::class)]
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
class FileStyleValueHandlerTest extends DaedalusAiKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_stylish', 'svg_image_field'];

  /**
   * The conversation the fixture holds files for.
   */
  private const THREAD = 'thread-style';

  /**
   * The style-value handler under test.
   */
  protected FileStyleValueHandler $styleHandler;

  /**
   * The store the names resolve through.
   */
  protected Attachments $attachments;

  /**
   * A selectable style key to write under.
   */
  protected string $styleKey = 'container:2ccc0362-5579-85a0-f3a6-624b8a6de818';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->createMediaType('svg', ['id' => 'vector_image', 'label' => 'Vector image']);
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
    $this->styleHandler = new FileStyleValueHandler(
      $this->attachments,
      $this->mediaMinter,
      $this->container->get('daedalus_blueprint.access_checker'),
    );
  }

  /**
   * CHECK phase: every refusal, and no side effects ever.
   */
  public function testCheckPhaseIsSideEffectFreeAndValidates(): void {
    $this->assertSame('_file', $this->styleHandler->getMarkerKey());
    $this->assertStringContainsString('{"_file": "name"', $this->styleHandler->getGuidance());
    $this->hold('hero.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>');
    $this->hold('notes.txt', 'text/plain', 'plain words');
    $context = $this->styleContext($this->styleKey, self::THREAD);
    $media_before = $this->mediaCount();

    // A sound value checks clean and stands in as plain CSS.
    $value = ['_file' => 'hero.svg', 'alt_text' => 'The hero mark'];
    $this->assertSame([], $this->styleHandler->check($value, $context));
    $this->assertSame('none', $this->styleHandler->getStandIn($value, $context));

    // Shape errors.
    $errors = $this->styleHandler->check(['_file' => '  '], $context);
    $this->assertCount(1, $errors);
    $this->assertSame('_file', $errors[0]->getPath());
    $this->assertSame('INVALID_STYLE_VALUE', $errors[0]->getCode());

    $errors = $this->styleHandler->check(['_file' => 'hero.svg', 'alt_text' => 42], $context);
    $this->assertCount(1, $errors);
    $this->assertSame('alt_text', $errors[0]->getPath());

    $errors = $this->styleHandler->check(['_file' => 'hero.svg', 'aspect_ratio' => '16:9'], $context);
    $this->assertCount(1, $errors);
    $this->assertSame('', $errors[0]->getPath());
    $this->assertStringContainsString('aspect_ratio', $errors[0]->getMessage());

    // The name must resolve for this conversation and user.
    foreach ([
      'unknown name' => [['_file' => 'missing.svg'], $context],
      'another conversation' => [['_file' => 'hero.svg'], $this->styleContext($this->styleKey, 'thread-other')],
      'no conversation' => [['_file' => 'hero.svg'], $this->styleContext($this->styleKey, '')],
    ] as $case => [$checked, $at]) {
      $errors = $this->styleHandler->check($checked, $at);
      $this->assertCount(1, $errors, $case);
      $this->assertSame('UNKNOWN_FILE', $errors[0]->getCode(), $case);
      $this->assertSame('_file', $errors[0]->getPath(), $case);
    }

    // A file no media type accepts.
    $errors = $this->styleHandler->check(['_file' => 'notes.txt'], $context);
    $this->assertCount(1, $errors);
    $this->assertSame('UNSUPPORTED_FILE', $errors[0]->getCode());
    $this->assertStringContainsString('"notes.txt"', $errors[0]->getMessage());

    // Another user's file never resolves.
    $other = $this->createUser(['view media', 'create vector_image media'], 'other');
    $this->hold('theirs.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>', $other);
    $this->assertSame('UNKNOWN_FILE', $this->styleHandler->check(['_file' => 'theirs.svg'], $context)[0]->getCode());

    // A bundle the user may not create: the raster-only editor holds an SVG.
    $raster_only = $this->setUpCurrentUser(
      ['name' => 'raster only'],
      ['view media', 'access content', 'create image media'],
    );
    $this->hold('mine.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"/>', $raster_only);
    $errors = $this->styleHandler->check(['_file' => 'mine.svg'], $context);
    $this->assertCount(1, $errors);
    $this->assertSame('ACCESS_DENIED_ENTITY', $errors[0]->getCode());
    $this->assertStringContainsString('vector_image', $errors[0]->getMessage());

    // Side-effect-free throughout.
    $this->assertSame($media_before, $this->mediaCount());
  }

  /**
   * COMMIT: media from the held file, the canonical token, final at once.
   */
  public function testResolveMintsTheHeldFile(): void {
    $svg = $this->hold('hero.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');
    $context = $this->styleContext($this->styleKey, self::THREAD);
    $warnings = [];
    $css = $this->styleHandler->resolve(['_file' => 'hero.svg', 'alt_text' => 'The hero mark'], $context, $warnings);

    $this->assertMatchesRegularExpression("/^url\\('media:[0-9a-f-]{36}'\\)$/", $css);
    $media = $this->container->get('entity.repository')->loadEntityByUuid('media', substr($css, 11, 36));
    $this->assertNotNull($media, 'The resolved token references saved media.');
    $this->assertSame('vector_image', $media->bundle());
    $this->assertSame('hero.svg', $media->getName());
    $source = $media->getSource()->getSourceFieldDefinition($media->bundle->entity)->getName();
    $this->assertSame((int) $svg->id(), (int) $media->get($source)->target_id, 'The media\'s source file IS the held file.');
    $this->assertSame('The hero mark', $media->get($source)->alt);
    $this->assertTrue(File::load($svg->id())->isPermanent());
    $this->assertSame([], $warnings);
    $this->assertFalse($this->collector->hasPending(), 'Nothing is deferred.');

    // Through the REAL resolver, with the conversation the lanes thread, a
    // layers list joins the literal and the resolved token.
    $resolver = new StyleValueResolver(
      [$this->styleHandler],
      $this->container->get('plugin.manager.daedalus_stylish_property_group'),
    );
    $map = [
      $this->styleKey => [
        'default' => [
          'background-image' => [
            'linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5))',
            ['_file' => 'hero.svg'],
          ],
        ],
      ],
    ];
    $resolution = $resolver->check($map, NULL, self::THREAD);
    $this->assertSame([], $resolution->getErrors());
    $this->assertSame(
      'linear-gradient(rgb(0 0 0 / 0.5), rgb(0 0 0 / 0.5)), none',
      $resolution->getMap()[$this->styleKey]['default']['background-image'],
      'The stand-in map is plain CSS for the validator.',
    );
    $committed = $resolution->commit()[$this->styleKey]['default']['background-image'];
    $this->assertMatchesRegularExpression(
      "/^linear-gradient\\(rgb\\(0 0 0 \\/ 0\\.5\\), rgb\\(0 0 0 \\/ 0\\.5\\)\\), url\\('media:[0-9a-f-]{36}'\\)$/",
      $committed,
    );

    // The same map without a conversation is refused at CHECK: the seam's
    // context is where the lanes hand the conversation over.
    $unthreaded = $resolver->check($map);
    $this->assertCount(1, $unthreaded->getErrors());
    $this->assertSame('UNKNOWN_FILE', $unthreaded->getErrors()[0]->getCode());
    $this->assertSame($this->styleKey . '.default.background-image.1._file', $unthreaded->getErrors()[0]->getPath());
  }

  /**
   * Builds the seam's context array for a value in a style map.
   */
  protected function styleContext(string $key, string $conversation): array {
    return [
      'key' => $key,
      'condition' => 'default',
      'property' => 'background-image',
      'path' => "$key.default.background-image",
      'layer' => NULL,
      'entity' => NULL,
      'conversation' => $conversation,
    ];
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
