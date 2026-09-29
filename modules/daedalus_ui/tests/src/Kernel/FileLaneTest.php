<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\daedalus_ui\File\FileLane;
use Drupal\daedalus_ui\File\FileLaneException;
use Drupal\field\Entity\FieldConfig;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The file lane: one road from supplied bytes to a managed file.
 *
 * The bytes entry is driven end to end (spool, validators, the SVG
 * sanitizer on the FileValidationEvent chain, the move, the entity save,
 * permanence). The request entry shares every step after the wrap, but
 * core's FormUploadedFile moves through PHP's move_uploaded_file(), which
 * refuses anything that is not a real PHP upload, so it cannot run inside a
 * Kernel test: daedalus_layout's MediaFileDragBehaviorTest (FJS) is its
 * proof through the upload controller.
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

    $this->lane = $this->container->get('daedalus_ui.file_lane');
  }

  /**
   * The accepted set is the media source extensions plus the textual kinds.
   */
  public function testAcceptedExtensions(): void {
    $accepted = $this->lane->acceptedExtensions();
    sort($accepted);
    $expected = array_merge(FileLane::TEXTUAL_EXTENSIONS, ['png', 'gif', 'jpg', 'jpeg', 'webp']);
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

}
