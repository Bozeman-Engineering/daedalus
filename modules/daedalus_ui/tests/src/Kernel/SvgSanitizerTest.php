<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\file\Entity\File;
use Drupal\file\Validation\FileValidatorInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the upload-time SVG sanitizer.
 *
 * The subscriber listens to FileValidationEvent, which every upload lane
 * reaches through the file.validator service, so the tests drive the real
 * validator rather than calling the subscriber directly: that also proves
 * the service wiring and event subscription.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
final class SvgSanitizerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'file',
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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('file');
  }

  /**
   * Writes content to a temp file and wraps it in an unsaved file entity.
   */
  private function createSvgFile(string $content, string $filename = 'test.svg'): File {
    $uri = 'temporary://' . $filename;
    file_put_contents($uri, $content);
    $file = File::create([
      'uri' => $uri,
      'filename' => $filename,
    ]);
    $file->setMimeType('image/svg+xml');
    $file->setSize(strlen($content));
    return $file;
  }

  /**
   * Malicious markup is stripped in place and the size updated.
   */
  public function testMaliciousSvgIsSanitizedInPlace(): void {
    $dirty = <<<SVG
      <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)">
        <script>alert('xss')</script>
        <use xlink:hReF="https://evil.example/leak.svg#x"/>
        <circle cx="5" cy="5" r="4" fill="currentColor"/>
      </svg>
      SVG;
    $file = $this->createSvgFile($dirty);

    $violations = $this->container->get(FileValidatorInterface::class)->validate($file, []);

    $this->assertCount(0, $violations);
    $clean = file_get_contents($file->getFileUri());
    $this->assertStringNotContainsString('<script', $clean);
    $this->assertStringNotContainsString('onload', $clean);
    $this->assertStringNotContainsStringIgnoringCase('evil.example', $clean);
    $this->assertStringContainsString('<circle', $clean);
    $this->assertSame(strlen($clean), $file->getSize());
  }

  /**
   * A mixed-case extension does not slip past the SVG detection.
   */
  public function testMixedCaseExtensionIsSanitized(): void {
    $file = $this->createSvgFile(
      '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
      'test.SVG',
    );
    // Detection must not lean on the mime type alone.
    $file->setMimeType('application/octet-stream');

    $violations = $this->container->get(FileValidatorInterface::class)->validate($file, []);

    $this->assertCount(0, $violations);
    $this->assertStringNotContainsString('<script', file_get_contents($file->getFileUri()));
  }

  /**
   * Unparseable SVG content is rejected with a violation.
   */
  public function testUnparseableSvgIsRejected(): void {
    $file = $this->createSvgFile('this is <svg not xml');

    $violations = $this->container->get(FileValidatorInterface::class)->validate($file, []);

    $this->assertCount(1, $violations);
    $this->assertStringContainsString('rejected for security reasons', (string) $violations->get(0)->getMessage());
  }

  /**
   * Non-SVG files pass through byte-identical.
   */
  public function testNonSvgFileIsUntouched(): void {
    $content = '<script>not an svg, not our business</script>';
    $uri = 'temporary://test.txt';
    file_put_contents($uri, $content);
    $file = File::create([
      'uri' => $uri,
      'filename' => 'test.txt',
    ]);
    $file->setMimeType('text/plain');
    $file->setSize(strlen($content));

    $violations = $this->container->get(FileValidatorInterface::class)->validate($file, []);

    $this->assertCount(0, $violations);
    $this->assertSame($content, file_get_contents($uri));
  }

}
