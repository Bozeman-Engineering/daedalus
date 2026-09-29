<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ai\Kernel;

use Drupal\daedalus_ai\Attachments;
use Drupal\daedalus_ai\FileDeclarationHandler;
use Drupal\daedalus_blueprint\Dialect\DialectAliasMap;
use Drupal\daedalus_blueprint\Dialect\FileDeclarationException;
use Drupal\daedalus_blueprint\Dialect\FileDeclarationHandlerInterface;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the seam that saves bytes the model declares in a batch.
 *
 * FileDeclarationHandler is daedalus_ai's implementation of the dialect's
 * file declaration seam: the declared bytes go through daedalus_ui's file
 * lane (so the SVG sanitizer and the validators run, the type is checked
 * against the name, the kinds are the textual ones a model authors) as a
 * temporary file in the attachment directory, and the attachment store
 * holds the file for the conversation under the DECLARED name on the
 * alias overlay only: a fresh map over tempstore does not see it until
 * the batch settles (persist()), and the same name declared again names
 * the new file whatever the lane saved it as. A refusal is a
 * FileDeclarationException carrying the lane's reasons and holds nothing.
 *
 * Built like FileLaneTest (the real lane from the container) with a real
 * store over the container's alias map; the daedalus_ai module stays
 * uninstalled.
 */
#[CoversClass(FileDeclarationHandler::class)]
#[Group('daedalus_ai')]
#[RunTestsInSeparateProcesses]
final class FileDeclarationHandlerTest extends KernelTestBase {

  use UserCreationTrait;

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
   * The conversation the declarations are held for.
   */
  private const THREAD = 'thread-declare';

  /**
   * A dirty SVG the sanitizer must rewrite.
   */
  private const DIRTY_SVG = <<<SVG
    <svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">
      <script>alert('xss')</script>
      <circle cx="5" cy="5" r="4" fill="currentColor"/>
    </svg>
    SVG;

  /**
   * The seam under test.
   */
  private FileDeclarationHandler $handler;

  /**
   * The store the declarations are held in.
   */
  private Attachments $attachments;

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
    $this->setUpCurrentUser(['name' => 'author']);
    $this->attachments = new Attachments(
      $this->container->get('file.usage'),
      $this->container->get('daedalus_blueprint.dialect_alias_map'),
      $this->container->get('entity_type.manager'),
      $this->container->get('current_user'),
      $this->container->get('keyvalue.expirable'),
      $this->container->get('database'),
      $this->container->get('datetime.time'),
    );
    $this->handler = new FileDeclarationHandler($this->container->get('daedalus_ui.file_lane'), $this->attachments);
  }

  /**
   * Declared bytes are sanitized, held under the declared name, unsettled.
   */
  public function testDeclaresThroughTheLaneOntoTheOverlay(): void {
    $this->assertInstanceOf(FileDeclarationHandlerInterface::class, $this->handler);
    $this->handler->declare(self::THREAD, 'logo.svg', 'image/svg+xml', self::DIRTY_SVG);

    $file = $this->attachments->resolve(self::THREAD, 'logo.svg');
    $this->assertNotNull($file, 'The overlay answers the declared name at once (later ops in the batch resolve it).');
    $this->assertSame('logo.svg', $file->getFilename());
    $this->assertSame('image/svg+xml', $file->getMimeType());
    $this->assertStringStartsWith(Attachments::DIRECTORY . '/', $file->getFileUri());
    $bytes = $this->attachments->bytes($file);
    $this->assertStringNotContainsString('<script', $bytes, 'The lane ran the sanitizer.');
    $this->assertStringNotContainsString('onload', $bytes);
    $this->assertStringContainsString('<circle', $bytes);
    $this->assertSame(['daedalus_ai' => ['thread' => [self::THREAD => '1']]], $this->container->get('file.usage')->listUsage($file), 'The store holds the file for the thread.');

    // Unsettled: the next request sees nothing until the batch persists.
    $fresh = new DialectAliasMap($this->container->get('tempstore.shared'));
    $this->assertNull($fresh->resolveFile(self::THREAD, 'logo.svg'), 'The seam never persists; the batch does.');
    $this->container->get('daedalus_blueprint.dialect_alias_map')->persist();
    $this->assertSame($file->uuid(), (new DialectAliasMap($this->container->get('tempstore.shared')))->resolveFile(self::THREAD, 'logo.svg'));

    // The same name declared again: the lane renames on disk, the name
    // moves to the new file.
    $this->handler->declare(self::THREAD, 'logo.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="2" height="2"/></svg>');
    $again = $this->attachments->resolve(self::THREAD, 'logo.svg');
    $this->assertNotSame($file->id(), $again->id());
    $this->assertSame('logo_0.svg', $again->getFilename());
    $this->assertStringContainsString('<rect', $this->attachments->bytes($again));

    // A textual kind beside the SVG.
    $this->handler->declare(self::THREAD, 'rows.csv', 'text/csv', "a,b\n1,2");
    $csv = $this->attachments->resolve(self::THREAD, 'rows.csv');
    $this->assertSame('text/csv', $csv->getMimeType());
    $this->assertSame("a,b\n1,2", $this->attachments->bytes($csv));
  }

  /**
   * Refusals carry the lane's reasons and hold nothing.
   */
  public function testRefusalsHoldNothing(): void {
    $cases = [
      'a binary kind is not authorable' => [
        'shot.png',
        'image/png',
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='),
        'html css csv md txt json svg',
      ],
      'a pdf is not authorable' => ['doc.pdf', 'application/pdf', '%PDF-1.4', 'html css csv md txt json svg'],
      'a type disagreeing with the name' => [
        'logo.svg',
        'text/plain',
        '<svg xmlns="http://www.w3.org/2000/svg"/>',
        'text/plain',
      ],
    ];
    foreach ($cases as $case => [$name, $type, $content, $needle]) {
      try {
        $this->handler->declare(self::THREAD, $name, $type, $content);
        $this->fail("$case: not refused.");
      }
      catch (FileDeclarationException $e) {
        $this->assertNotEmpty($e->messages, $case);
        $this->assertStringContainsString($needle, $e->getMessage(), $case);
      }
      $this->assertNull($this->attachments->resolve(self::THREAD, $name), $case);
    }
    $this->assertSame([], File::loadMultiple(), 'A refusal leaves no file entity behind.');
    $this->assertSame(0, (int) $this->container->get('database')->select('file_usage')->countQuery()->execute()->fetchField());
  }

}
