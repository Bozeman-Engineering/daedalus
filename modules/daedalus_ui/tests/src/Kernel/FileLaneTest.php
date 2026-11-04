<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

// cspell:ignore résumé
use Drupal\Core\File\FileExists;
use Drupal\daedalus_ui\File\FileLane;
use Drupal\daedalus_ui\File\FileLaneException;
use Drupal\field\Entity\FieldConfig;
use Drupal\file\Entity\File;
use Drupal\file\Upload\FileUploadHandler;
use Drupal\file\Upload\UploadedFileInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The file lane: one road from supplied bytes to a managed file.
 *
 * The bytes entry is driven end to end (spool, validators, the SVG
 * sanitizer on the FileValidationEvent chain, the move, the entity save,
 * permanence, the canonical name). The request entry shares every step
 * after the wrap, but core's FormUploadedFile moves through PHP's
 * move_uploaded_file(), which refuses anything that is not a real PHP
 * upload, so inside Kernel it runs over a handler whose one override is that
 * OS move (requestLane()); daedalus_ai's AttachRouteTest (Functional) and
 * daedalus_layout's MediaFileDragBehaviorTest (FJS) are its proofs through a
 * real multipart upload.
 */
#[CoversClass(FileLane::class)]
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
final class FileLaneTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'image',
    'media',
    'block',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'filter',
    'daedalus_blueprint_api',
    'daedalus_ui',
  ];

  /**
   * Where the tests save.
   */
  private const DIRECTORY = 'public://lane-test';

  /**
   * A dirty SVG the sanitizer must rewrite.
   */
  private const DIRTY_SVG = <<<SVG
    <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)">
      <script>alert('xss')</script>
      <use xlink:href="https://evil.example/leak.svg#x"/>
      <circle cx="5" cy="5" r="4" fill="currentColor"/>
    </svg>
    SVG;

  /**
   * The lane under test.
   */
  private FileLane $lane;

  /**
   * This test's own temporary directory, the lane's spool.
   *
   * The refusal test compares the spool before and after; the OS temp dir
   * is shared with every other process (drupal.org's runner runs classes
   * concurrently), so the lane spools to a directory nothing else writes.
   */
  private string $tempDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installConfig(['field', 'system', 'image', 'file', 'media']);
    // An image type (png gif jpg jpeg webp) and a file type whose source field
    // accepts any extension: the lane must take the first's extensions and
    // never inherit the second's "anything".
    $this->createMediaType('image', ['id' => 'image']);
    $any = $this->createMediaType('file', ['id' => 'document']);
    $source_field = FieldConfig::load('media.document.' . $any->getSource()->getSourceFieldDefinition($any)->getName());
    $source_field->setSetting('file_extensions', '')->save();

    $this->tempDirectory = sys_get_temp_dir() . '/daedalus-file-lane-' . $this->databasePrefix;
    mkdir($this->tempDirectory, 0777, TRUE);
    $this->setSetting('file_temp_path', $this->tempDirectory);

    $this->lane = $this->container->get('daedalus_ui.file_lane');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    array_map('unlink', glob($this->tempDirectory . '/*') ?: []);
    rmdir($this->tempDirectory);
    parent::tearDown();
  }

  /**
   * The accepted set: media source extensions, textual kinds, font kinds.
   */
  public function testAcceptedExtensions(): void {
    $accepted = $this->lane->acceptedExtensions();
    sort($accepted);
    $expected = array_merge(FileLane::TEXTUAL_EXTENSIONS, FileLane::FONT_EXTENSIONS, [
      'png',
      'gif',
      'jpg',
      'jpeg',
      'webp',
    ]);
    sort($expected);
    $this->assertSame($expected, $accepted);

    $this->assertNull($this->lane->mediaTypeExtensions($this->container->get('entity_type.manager')->getStorage('media_type')->load('document')));
  }

  /**
   * SVG bytes come out sanitized, saved temporary in the asked directory.
   */
  public function testSvgBytesAreSanitized(): void {
    $file = $this->lane->fromBytes('logo.svg', 'image/svg+xml', self::DIRTY_SVG, self::DIRECTORY);

    $this->assertTrue($file->isTemporary());
    $this->assertSame('image/svg+xml', $file->getMimeType());
    $this->assertSame('logo.svg', $file->getFilename());
    $this->assertStringStartsWith(self::DIRECTORY . '/', $file->getFileUri());
    $clean = file_get_contents($file->getFileUri());
    $this->assertStringNotContainsString('<script', $clean);
    $this->assertStringNotContainsString('onload', $clean);
    $this->assertStringNotContainsString('evil.example', $clean);
    $this->assertStringContainsString('<circle', $clean);
    $this->assertSame(strlen($clean), (int) $file->getSize());
    $this->assertNotNull(File::load($file->id()));

    // A second file of the same name is renamed, never replaced.
    $second = $this->lane->fromBytes('logo.svg', 'image/svg+xml', self::DIRTY_SVG, self::DIRECTORY);
    $this->assertSame('logo_0.svg', $second->getFilename());
    $this->assertNotSame($file->getFileUri(), $second->getFileUri());
  }

  /**
   * A disallowed extension raises and leaves nothing behind.
   */
  public function testDisallowedExtensionRaises(): void {
    $spooled = glob($this->container->get('file_system')->realpath('temporary://') . '/file*');
    try {
      $this->lane->fromBytes('shell.php', 'application/x-httpd-php', '<?php echo 1;', self::DIRECTORY);
      $this->fail('A .php file must be refused.');
    }
    catch (FileLaneException $e) {
      $this->assertStringContainsString('extension', $e->getMessage());
    }
    $this->assertSame([], File::loadMultiple());
    $this->assertSame($spooled, glob($this->container->get('file_system')->realpath('temporary://') . '/file*'));

    // The "any extension" media type does not open the lane: a docx is
    // neither a source extension nor a textual kind.
    $this->expectException(FileLaneException::class);
    $this->lane->fromBytes('memo.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'PK', self::DIRECTORY);
  }

  /**
   * A narrowing intersects the accepted set and can never widen it.
   */
  public function testNarrowingNeverWidens(): void {
    $image = $this->container->get('entity_type.manager')->getStorage('media_type')->load('image');
    $narrowed = $this->lane->mediaTypeExtensions($image);
    $this->assertSame(['png', 'gif', 'jpg', 'jpeg', 'webp'], $narrowed);

    $png = file_get_contents($this->root . '/core/misc/druplicon.png');
    $file = $this->lane->fromBytes('photo.png', 'image/png', $png, self::DIRECTORY, FALSE, $narrowed);
    $this->assertSame('image/png', $file->getMimeType());

    try {
      $this->lane->fromBytes('notes.txt', 'text/plain', 'hello', self::DIRECTORY, FALSE, $narrowed);
      $this->fail('A textual kind outside the narrowing must be refused.');
    }
    catch (FileLaneException $e) {
      $this->assertStringContainsString('extension', $e->getMessage());
    }

    // A narrowing outside the accepted set leaves nothing, and nothing is
    // a refusal, not core's "anything".
    $this->expectException(FileLaneException::class);
    $this->lane->fromBytes('shell.php', 'application/x-httpd-php', '<?php', self::DIRECTORY, FALSE, ['php']);
  }

  /**
   * The declared type must agree with the name; the permanent flag persists.
   */
  public function testDeclaredTypeAndPermanence(): void {
    $file = $this->lane->fromBytes('styles.css', 'text/css', 'a { color: red }', self::DIRECTORY, TRUE);
    $this->assertTrue($file->isPermanent());
    $this->assertTrue(File::load($file->id())->isPermanent());
    $this->assertSame('text/css', $file->getMimeType());

    try {
      $this->lane->fromBytes('styles.css', 'image/svg+xml', 'a { color: red }', self::DIRECTORY);
      $this->fail('A declared type disagreeing with the name must be refused.');
    }
    catch (FileLaneException $e) {
      $this->assertStringContainsString('image/svg+xml', $e->getMessage());
      $this->assertStringContainsString('text/css', $e->getMessage());
    }
    // The refused file is gone, entity and bytes.
    $this->assertCount(1, File::loadMultiple());
    $this->assertFileDoesNotExist(self::DIRECTORY . '/styles_0.css');
  }

  /**
   * The size ceiling refuses oversize bytes.
   */
  public function testSizeCeiling(): void {
    $this->expectException(FileLaneException::class);
    $this->expectExceptionMessageMatches('/(exceed|larger|size)/i');
    $this->lane->fromBytes('big.txt', 'text/plain', str_repeat('x', FileLane::SIZE_LIMIT + 1), self::DIRECTORY);
  }

  /**
   * Every file is saved under the lane's canonical name, on both roads.
   *
   * The macOS screenshot name carries a NARROW NO-BREAK SPACE (U+202F)
   * before "PM", the character the live site's model normalized to a plain
   * space so its handle stopped resolving; the lane turns every whitespace
   * into a dash. Transliteration, the empty-stem fallback, core's security
   * munge still running after the lane, and the collision rename on the
   * clean name are each pinned, then the request road through a real
   * Symfony UploadedFile proves the decorator cleans too.
   */
  public function testFilenamesAreCanonical(): void {
    $png = file_get_contents($this->root . '/core/misc/druplicon.png');
    $screenshot = "Screenshot 2026-10-27 at 4.17.44\u{202F}PM.png";

    // The spine itself, before any save.
    $this->assertSame('Screenshot-2026-10-27-at-4.17.44-PM.png', $this->lane->canonicalName($screenshot));
    $this->assertSame('resume-final.pdf', $this->lane->canonicalName("résumé  final.PDF"));
    $this->assertSame('file.png', $this->lane->canonicalName('???.png'));
    $this->assertSame('a-b.txt', $this->lane->canonicalName("a\u{00A0}b.txt"));

    $file = $this->lane->fromBytes($screenshot, 'image/png', $png, self::DIRECTORY);
    $this->assertSame('Screenshot-2026-10-27-at-4.17.44-PM.png', $file->getFilename());
    $this->assertSame(self::DIRECTORY . '/Screenshot-2026-10-27-at-4.17.44-PM.png', $file->getFileUri());
    $this->assertSame('image/png', $file->getMimeType());

    $this->assertSame('resume-final.pdf', $this->lane->fromBytes("résumé  final.PDF", 'application/pdf', '%PDF-1.4', self::DIRECTORY)->getFilename());
    $this->assertSame('file.png', $this->lane->fromBytes('???.png', 'image/png', $png, self::DIRECTORY)->getFilename());

    // Core's security subscriber still runs on the clean name.
    $this->assertSame('shell.php_.txt', $this->lane->fromBytes('shell.php.txt', 'text/plain', 'hello', self::DIRECTORY)->getFilename());

    // A second screenshot collides after cleaning and is renamed as always.
    $second = $this->lane->fromBytes($screenshot, 'image/png', $png, self::DIRECTORY);
    $this->assertSame('Screenshot-2026-10-27-at-4.17.44-PM_0.png', $second->getFilename());
    $this->assertNotSame($file->getFileUri(), $second->getFileUri());

    // The request road: a real Symfony UploadedFile, whose client name the
    // lane's wrapper answers in canonical form.
    $source = $this->tempDirectory . '/upload.png';
    file_put_contents($source, $png);
    $upload = new UploadedFile($source, "Screenshot 2026-10-28 at 9.03.10\u{202F}AM.png", 'image/png', NULL, TRUE);
    $saved = $this->requestLane()->fromRequest($upload, self::DIRECTORY);
    $this->assertSame('Screenshot-2026-10-28-at-9.03.10-AM.png', $saved->getFilename());
    $this->assertSame(self::DIRECTORY . '/Screenshot-2026-10-28-at-9.03.10-AM.png', $saved->getFileUri());
    $this->assertSame('image/png', $saved->getMimeType());
    $this->assertFileDoesNotExist($source);
    $this->assertFileExists($saved->getFileUri());
    $this->assertNotNull(File::load($saved->id()));
  }

  /**
   * The lane service over core's handler with only the OS move swapped.
   *
   * PHP's move_uploaded_file() refuses any file PHP did not receive as an
   * upload, so a request's upload cannot land inside Kernel through the real
   * handler; this handler moves with FileSystem::move() instead and every
   * other step (the wrap, validation, the sanitize-name event, the collision
   * rename, the entity save) is core's. The handler is set beneath
   * file.upload_handler and the lane re-created from its own service
   * definition, so the wiring under test is daedalus_ui.services.yml's.
   */
  private function requestLane(): FileLane {
    $handler = new class(
      $this->container->get('file_system'),
      $this->container->get('entity_type.manager'),
      $this->container->get('stream_wrapper_manager'),
      $this->container->get('event_dispatcher'),
      $this->container->get('file.mime_type.guesser'),
      $this->container->get('current_user'),
      $this->container->get('request_stack'),
      $this->container->get('file.repository'),
      $this->container->get('file.validator'),
      $this->container->get('lock'),
      $this->container->get('validation.basic_recursive_validator_factory'),
    ) extends FileUploadHandler {

      /**
       * {@inheritdoc}
       */
      protected function moveUploadedFile(UploadedFileInterface $uploadedFile, string $uri): bool {
        return (bool) $this->fileSystem->move($uploadedFile->getRealPath(), $uri, FileExists::Error);
      }

    };
    $this->container->set('file.upload_handler', $handler);
    // Drop the cached lane so the next get() builds it over the swapped
    // handler from the service definition.
    $this->container->set('daedalus_ui.file_lane', NULL);
    return $this->container->get('daedalus_ui.file_lane');
  }

}
