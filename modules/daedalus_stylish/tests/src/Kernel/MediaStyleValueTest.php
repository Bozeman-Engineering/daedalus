<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\daedalus_stylish\MediaToken;
use Drupal\daedalus_stylish\PropertyGroupPluginManager;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests media-token validation in property groups (the media value spine).
 *
 * The token grammar itself is locked by MediaTokenTest; this covers the
 * validation half of the spine: media-flagged properties accept
 * url('media:<uuid>') when the uuid names existing image or vector media,
 * reject malformed references, missing media, and media whose source is
 * neither, and unflagged properties reject tokens outright. Guide
 * constraints keep their exact semantics around tokens.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class MediaStyleValueTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'daedalus_blueprint',
    'user',
    'field',
    'file',
    'image',
    'media',
    'svg_image_field',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * The property group plugin manager.
   */
  protected PropertyGroupPluginManager $manager;

  /**
   * Image media the tokens under test reference.
   */
  protected MediaInterface $imageMedia;

  /**
   * Media whose source is a plain file, not an image or vector.
   */
  protected MediaInterface $fileMedia;

  /**
   * Vector media (svg_image_field source) the tokens under test reference.
   */
  protected MediaInterface $vectorMedia;

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
    $this->manager = $this->container->get('plugin.manager.daedalus_stylish_property_group');

    $image_type = $this->createMediaType('image');
    $source_field = $image_type->getSource()->getSourceFieldDefinition($image_type)->getName();
    $this->container->get('file_system')->copy($this->root . '/core/misc/druplicon.png', 'public://hero.png');
    $file = File::create(['uri' => 'public://hero.png', 'filename' => 'hero.png']);
    $file->save();
    $this->imageMedia = Media::create([
      'bundle' => $image_type->id(),
      'name' => 'Hero',
      $source_field => ['target_id' => $file->id()],
    ]);
    $this->imageMedia->save();

    $file_type = $this->createMediaType('file');
    $this->fileMedia = Media::create(['bundle' => $file_type->id(), 'name' => 'Document']);
    $this->fileMedia->save();

    $vector_type = $this->createMediaType('svg');
    $vector_source = $vector_type->getSource()->getSourceFieldDefinition($vector_type)->getName();
    file_put_contents('public://logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
    $svg_file = File::create(['uri' => 'public://logo.svg', 'filename' => 'logo.svg']);
    $svg_file->save();
    $this->vectorMedia = Media::create([
      'bundle' => $vector_type->id(),
      'name' => 'Logo',
      $vector_source => ['target_id' => $svg_file->id()],
    ]);
    $this->vectorMedia->save();
  }

  /**
   * Valid tokens pass on the media-flagged property, in every composition.
   */
  public function testValidTokens(): void {
    $background = $this->manager->createInstance('background');
    $uuid = $this->imageMedia->uuid();
    $token = MediaToken::build($uuid);

    $this->assertValid($background, 'background-image', $token);
    // Quote variants are the same token.
    $this->assertValid($background, 'background-image', "url('media:$uuid')");
    $this->assertValid($background, 'background-image', "url(media:$uuid)");
    // Tokens compose with gradients and comma layers.
    $this->assertValid($background, 'background-image', "linear-gradient(120deg, #1a1a2e, #16213e), $token");
    // The token-free grammar is untouched.
    $this->assertValid($background, 'background-image', 'linear-gradient(120deg, #1a1a2e, #16213e)');
    $this->assertValid($background, 'background-image', 'none');
    // Vector media (svg_image_field source) pass the same gate as rasters.
    // The vector lane stays inert here: the token resolves to a plain file
    // URL, never inline markup.
    $this->assertValid($background, 'background-image', MediaToken::build($this->vectorMedia->uuid()));
  }

  /**
   * Failure modes: malformed, missing, non-image/vector, unflagged property.
   */
  public function testInvalidTokens(): void {
    $background = $this->manager->createInstance('background');
    $uuid = $this->imageMedia->uuid();

    // A payload that is not a uuid.
    $this->assertCode('INVALID_MEDIA_REFERENCE', $background, 'background-image', "url('media:not-a-uuid')");
    // Media-scheme text the token grammar cannot parse must never reach the
    // printers as a literal: bare scheme, unclosed url().
    $this->assertCode('INVALID_MEDIA_REFERENCE', $background, 'background-image', "media:$uuid");
    $this->assertCode('INVALID_MEDIA_REFERENCE', $background, 'background-image', "url('media:$uuid");
    // A uuid that names no media.
    $this->assertCode('MEDIA_NOT_FOUND', $background, 'background-image', MediaToken::build('123e4567-e89b-12d3-a456-426614174000'));
    // Media whose source is neither an image nor a vector.
    $this->assertCode('MEDIA_NOT_IMAGE', $background, 'background-image', MediaToken::build($this->fileMedia->uuid()));
    // Properties without the media flag reject tokens outright.
    $this->assertCode('INVALID_STYLE_VALUE', $background, 'background-color', MediaToken::build($uuid));
    // Value hygiene still runs first.
    $this->assertCode('INVALID_STYLE_VALUE', $background, 'background-image', MediaToken::build($uuid) . '; color: red');
  }

  /**
   * Constraint and pass-through semantics hold exactly around tokens.
   */
  public function testTokenConstraintSemantics(): void {
    $background = $this->manager->createInstance('background');
    $token = MediaToken::build($this->imageMedia->uuid());

    // An allowed list stays the complete value space — a valid token outside
    // it fails, a listed token passes verbatim.
    $this->assertCode('STYLE_VALUE_NOT_ALLOWED', $background, 'background-image', $token, ['allowed' => ['none']]);
    $this->assertValid($background, 'background-image', $token, ['allowed' => [$token]]);
    // Token validation runs before the var() pass-through: a broken
    // reference cannot tunnel through beside a var().
    $this->assertCode('MEDIA_NOT_FOUND', $background, 'background-image', 'var(--overlay), ' . MediaToken::build('123e4567-e89b-12d3-a456-426614174000'));
    $this->assertValid($background, 'background-image', "var(--overlay), $token");
  }

  /**
   * Asserts a value validates cleanly.
   */
  protected function assertValid(object $group, string $property, string $value, array $constraints = []): void {
    $errors = $group->validate($property, $value, $constraints);
    $this->assertSame([], array_map(static fn ($e) => $e->toArray(), $errors), "$property: $value should be valid");
  }

  /**
   * Asserts validation fails with the given code.
   */
  protected function assertCode(string $code, object $group, string $property, string $value, array $constraints = []): void {
    $errors = $group->validate($property, $value, $constraints);
    $this->assertNotEmpty($errors, "$property: $value should be invalid");
    $this->assertSame($code, $errors[0]->getCode(), "$property: $value should fail with $code");
    $this->assertSame($property, $errors[0]->getPath());
    $this->assertNotSame('', $errors[0]->getHint(), 'Errors carry a hint');
  }

}
