<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\entity_test\Entity\EntityTest;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\MediaToken;
use Drupal\daedalus_stylish\VariantStylesheet;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests server delivery of media-valued styles.
 *
 * Validation of the token grammar is MediaStyleValueTest's; this covers the
 * resolver half: media tokens resolve to file URLs as a pre-print map
 * transform at every server print site (the entity's own map in
 * CssStyleBackend, variant styles in the compiled stylesheet and the
 * edit-mode inline resolution), the image style resolves guide constraint ->
 * site default -> original, media cache tags bubble on the host build, and
 * media changes recompile the static variant stylesheet.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class MediaValueDeliveryTest extends KernelTestBase {

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
    'entity_test',
    'daedalus_stylish',
    'daedalus_stylish_media_guide_test',
  ];

  /**
   * Image media the tokens under test reference.
   */
  protected MediaInterface $imageMedia;

  /**
   * The image media type's source field name.
   */
  protected string $sourceField;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('file');
    $this->installSchema('file', ['file_usage']);
    $this->installEntitySchema('media');
    $this->installEntitySchema('daedalus_variant');
    // Material references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['field', 'system', 'image', 'file', 'media', 'daedalus_stylish']);

    $image_type = $this->createMediaType('image');
    $this->sourceField = $image_type->getSource()->getSourceFieldDefinition($image_type)->getName();
    $this->imageMedia = $this->createImageMedia($image_type->id(), 'hero.png');
  }

  /**
   * Creates image media around a copy of core's druplicon.
   */
  protected function createImageMedia(string $bundle, string $filename, array $values = []): MediaInterface {
    $this->container->get('file_system')->copy($this->root . '/core/misc/druplicon.png', "public://$filename");
    $file = File::create(['uri' => "public://$filename", 'filename' => $filename]);
    $file->save();
    $media = Media::create($values + [
      'bundle' => $bundle,
      'name' => $filename,
      $this->sourceField => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Builds the entity's style element and returns [css, cache tags].
   */
  protected function buildCss(EntityTest $entity, array $map): array {
    $element = $this->container->get('daedalus_stylish.style_backend')->build($entity, $map);
    return [(string) ($element['#value'] ?? ''), $element['#cache']['tags'] ?? []];
  }

  /**
   * Map delivery resolves tokens to file URLs and bubbles media cache tags.
   */
  public function testMapDeliveryResolvesTokens(): void {
    $entity = EntityTest::create(['name' => 'bg']);
    $entity->save();
    [$css, $tags] = $this->buildCss($entity, [
      "entity:{$entity->uuid()}" => [
        'default' => [
          // The token composes with gradients and comma layers; everything
          // around it must survive untouched.
          'background-image' => 'linear-gradient(120deg, #1a1a2e, #16213e), ' . MediaToken::build($this->imageMedia->uuid()),
          'background-size' => 'cover',
        ],
      ],
    ]);

    $this->assertStringContainsString('linear-gradient(120deg, #1a1a2e, #16213e), url("', $css);
    $this->assertStringContainsString('hero.png', $css);
    $this->assertStringContainsString('background-size: cover;', $css);
    // The printers never see a token, and no token survives to the browser.
    $this->assertStringNotContainsString('media:', $css);
    // No guide constraint on kind:entity and no site default: the original
    // file serves, no derivative path.
    $this->assertStringNotContainsString('/styles/', $css);
    // The media's tags ride the host build, so a file replace or delete
    // invalidates the entity's render cache entry.
    $this->assertContains('media:' . $this->imageMedia->id(), $tags);
  }

  /**
   * The daedalus_stylish.settings media_image_style default picks the derivative.
   */
  public function testSiteDefaultImageStyle(): void {
    $this->config('daedalus_stylish.settings')->set('media_image_style', 'thumbnail')->save();
    $entity = EntityTest::create(['name' => 'bg']);
    $entity->save();
    [$css, $tags] = $this->buildCss($entity, [
      "entity:{$entity->uuid()}" => ['default' => ['background-image' => MediaToken::build($this->imageMedia->uuid())]],
    ]);

    $this->assertStringContainsString('/styles/thumbnail/', $css);
    // The derivative's config tag bubbles beside the media tags.
    $this->assertContains('config:image.style.thumbnail', $tags);
    $this->assertContains('media:' . $this->imageMedia->id(), $tags);

    // A default naming a nonexistent style degrades to the original file,
    // never to a dead declaration.
    $this->config('daedalus_stylish.settings')->set('media_image_style', 'no_such_style')->save();
    [$css] = $this->buildCss($entity, [
      "entity:{$entity->uuid()}" => ['default' => ['background-image' => MediaToken::build($this->imageMedia->uuid())]],
    ]);
    $this->assertStringContainsString('hero.png', $css);
    $this->assertStringNotContainsString('/styles/', $css);
  }

  /**
   * A guide image_style constraint beats the site default.
   *
   * The daedalus_stylish_media_guide_test constrains background-image to "large" on the
   * universal block kind — the declaration a block:{uuid} key resolves when
   * its placement cannot be located, exactly as validation resolves it.
   */
  public function testGuideConstraintImageStyle(): void {
    $this->config('daedalus_stylish.settings')->set('media_image_style', 'thumbnail')->save();
    $entity = EntityTest::create(['name' => 'bg']);
    $entity->save();
    [$css, $tags] = $this->buildCss($entity, [
      'block:66666666-6666-6666-6666-666666666666' => ['default' => ['background-image' => MediaToken::build($this->imageMedia->uuid())]],
      // The entity key has no constraint, so the site default still applies
      // there: per-key resolution, not per-map.
      "entity:{$entity->uuid()}" => ['default' => ['background-image' => MediaToken::build($this->imageMedia->uuid())]],
    ]);

    $this->assertStringContainsString('/styles/large/', $css);
    $this->assertStringContainsString('/styles/thumbnail/', $css);
    $this->assertContains('config:image.style.large', $tags);
  }

  /**
   * Vector media resolve to inert file URLs; image styles degrade politely.
   *
   * The vector lane through the resolver: an svg_image_field-source token
   * resolves to the SVG file's plain URL (the background-image position is
   * inert, nothing renders inline), and a site default image style, which
   * cannot process an SVG, degrades to the original file via the
   * supportsUri() guard instead of printing a dead derivative path.
   */
  public function testVectorMediaDelivery(): void {
    $vector_type = $this->createMediaType('svg');
    $vector_source = $vector_type->getSource()->getSourceFieldDefinition($vector_type)->getName();
    file_put_contents('public://logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');
    $file = File::create(['uri' => 'public://logo.svg', 'filename' => 'logo.svg']);
    $file->save();
    $vector = Media::create([
      'bundle' => $vector_type->id(),
      'name' => 'Logo',
      $vector_source => ['target_id' => $file->id()],
    ]);
    $vector->save();

    $this->config('daedalus_stylish.settings')->set('media_image_style', 'thumbnail')->save();
    $entity = EntityTest::create(['name' => 'bg']);
    $entity->save();
    [$css, $tags] = $this->buildCss($entity, [
      "entity:{$entity->uuid()}" => ['default' => ['background-image' => MediaToken::build($vector->uuid())]],
    ]);

    $this->assertStringContainsString('logo.svg', $css);
    // No derivative path and no inline markup: the original file's URL, in
    // an inert url() position.
    $this->assertStringNotContainsString('/styles/', $css);
    $this->assertStringNotContainsString('<svg', $css);
    $this->assertStringNotContainsString('media:', $css);
    $this->assertContains('media:' . $vector->id(), $tags);
  }

  /**
   * Delivery of a content token on a generated box falls out of the flag.
   *
   * The map transform is property-blind, so `content: url('media:…')` at a
   * box key resolves exactly as background-image does: through the site
   * default image style, through a guide `image_style` constraint on
   * `content` where the host declares the box (the media guide fixture's
   * ::before on the universal block kind, beating the site default; the
   * undeclared ::after is derived and keeps the default), vector media to
   * the plain file URL (an image style cannot process an SVG), and a
   * missing media to `none`, which on `content` means no box: the honest
   * degradation, the same word the birth seed's opposite uses. The
   * media's tags ride the host build like any token's.
   */
  public function testContentTokenDelivery(): void {
    $this->config('daedalus_stylish.settings')->set('media_image_style', 'thumbnail')->save();
    $entity = EntityTest::create(['name' => 'box']);
    $entity->save();
    $token = MediaToken::build($this->imageMedia->uuid());

    // The site default sizes a content image where nothing constrains it.
    [$css, $tags] = $this->buildCss($entity, [
      "entity:{$entity->uuid()}::&::before" => ['default' => ['content' => $token]],
    ]);
    $this->assertStringContainsString(':scope::before', $css);
    $this->assertMatchesRegularExpression('~content: url\("[^"]*/styles/thumbnail/[^"]*hero\.png[^"]*"\);~', $css);
    $this->assertStringNotContainsString('media:', $css);
    $this->assertContains('media:' . $this->imageMedia->id(), $tags);
    $this->assertContains('config:image.style.thumbnail', $tags);

    // A guide image_style constraint on content beats the site default,
    // per box: the declared ::before is constrained, the derived ::after
    // is not.
    [$css, $tags] = $this->buildCss($entity, [
      'block:66666666-6666-6666-6666-666666666666::&::before' => ['default' => ['content' => $token]],
      'block:66666666-6666-6666-6666-666666666666::&::after' => ['default' => ['content' => $token]],
    ]);
    $this->assertMatchesRegularExpression('~:scope::before \{\s*content: url\("[^"]*/styles/large/[^"]*"\);~', $css);
    $this->assertMatchesRegularExpression('~:scope::after \{\s*content: url\("[^"]*/styles/thumbnail/[^"]*"\);~', $css);
    $this->assertContains('config:image.style.large', $tags);

    // Vector media on content print the file URL, no derivative.
    $vector_type = $this->createMediaType('svg');
    $vector_source = $vector_type->getSource()->getSourceFieldDefinition($vector_type)->getName();
    file_put_contents('public://rosette.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle r="5"/></svg>');
    $file = File::create(['uri' => 'public://rosette.svg', 'filename' => 'rosette.svg']);
    $file->save();
    $vector = Media::create([
      'bundle' => $vector_type->id(),
      'name' => 'Rosette',
      $vector_source => ['target_id' => $file->id()],
    ]);
    $vector->save();
    [$css, $tags] = $this->buildCss($entity, [
      "entity:{$entity->uuid()}::&::after" => ['default' => ['content' => MediaToken::build($vector->uuid())]],
    ]);
    $this->assertMatchesRegularExpression('~content: url\("[^"]*rosette\.svg"\);~', $css);
    $this->assertStringNotContainsString('/styles/', $css);
    $this->assertStringNotContainsString('<svg', $css);
    $this->assertContains('media:' . $vector->id(), $tags);

    // A missing media prints no box.
    [$css] = $this->buildCss($entity, [
      "entity:{$entity->uuid()}::&::before" => ['default' => ['content' => MediaToken::build('123e4567-e89b-12d3-a456-426614174000')]],
    ]);
    $this->assertStringContainsString('content: none;', $css);
    $this->assertStringNotContainsString('media:', $css);
  }

  /**
   * A token whose media does not resolve degrades to "none" at the gate.
   */
  public function testMissingMediaDegradesToNone(): void {
    $entity = EntityTest::create(['name' => 'bg']);
    $entity->save();
    [$css] = $this->buildCss($entity, [
      "entity:{$entity->uuid()}" => ['default' => ['background-image' => MediaToken::build('123e4567-e89b-12d3-a456-426614174000')]],
    ]);

    $this->assertStringContainsString('background-image: none;', $css);
    $this->assertStringNotContainsString('media:', $css);
  }

  /**
   * The client mirror's uuid -> URL table resolves like server printing.
   *
   * The preview payload (drupalSettings.daedalusStylish.mediaUrls): one entry per
   * resolvable token uuid, lowercased, resolved through the same image-style
   * logic as the map transform (guide constraint beats site default, per
   * key), unresolvable uuids omitted so the client degrades them to "none".
   */
  public function testClientUrlTable(): void {
    $this->config('daedalus_stylish.settings')->set('media_image_style', 'thumbnail')->save();
    $entity = EntityTest::create(['name' => 'bg']);
    $entity->save();
    $missing = '123e4567-e89b-12d3-a456-426614174000';
    $uuid = $this->imageMedia->uuid();

    $tags = [];
    $table = $this->container->get('daedalus_stylish.media_value_resolver')->clientUrlTable([
      // The constrained block key resolves first, so its "large" derivative
      // wins the flat table's first-resolution slot for this uuid.
      'block:66666666-6666-6666-6666-666666666666' => ['default' => ['background-image' => MediaToken::build($uuid)]],
      "entity:{$entity->uuid()}" => [
        'default' => [
          // Uppercased payload still lands under the lowercased table key,
          // in the retired double-quoted spelling (constructed: pre-0.4
          // stored values must keep resolving with no migration).
          'background-image' => 'linear-gradient(red, blue), ' . str_replace("'", '"', sprintf("url('media:%s')", strtoupper($uuid))),
        ],
        'hover' => ['background-image' => MediaToken::build($missing)],
        // The variants list holds no CSS and never seeds the table.
        'variants' => [['variant' => 'hero_bg', 'fragment' => '']],
      ],
    ], $tags, $entity);

    $this->assertSame([strtolower($uuid)], array_keys($table));
    $this->assertStringContainsString('/styles/large/', $table[strtolower($uuid)]);
    $this->assertStringContainsString('hero.png', $table[strtolower($uuid)]);
    // The table's cache metadata matches server printing: the media's tags
    // and the derivative's config tag ride wherever the table attaches.
    $this->assertContains('media:' . $this->imageMedia->id(), $tags);
    $this->assertContains('config:image.style.large', $tags);
  }

  /**
   * The compiled variant stylesheet resolves tokens and tracks media uuids.
   */
  public function testVariantCompileResolvesTokens(): void {
    $uuid = $this->imageMedia->uuid();
    $variant = DaedalusStylishVariant::create([
      'label' => 'Hero background',
      'machine_name' => 'hero_bg',
      'scope' => 'kind:block',
      'status' => TRUE,
    ]);
    $variant->setStyles(['' => ['default' => ['background-image' => MediaToken::build($uuid)]]]);
    $variant->save();

    $state = $this->container->get('state');
    $uri = $state->get(VariantStylesheet::STATE_KEY);
    $this->assertIsString($uri);
    $css = file_get_contents($uri);
    $this->assertStringContainsString('hero.png', $css);
    $this->assertStringNotContainsString('media:', $css);
    // The raw token payloads are recorded for the media-change recompile
    // gate.
    $this->assertSame([strtolower($uuid)], $state->get(VariantStylesheet::MEDIA_STATE_KEY));
    $this->assertTrue($this->container->get('daedalus_stylish.variant_stylesheet')->referencesMedia($uuid));
  }

  /**
   * Media changes recompile the static stylesheet; unrelated media do not.
   *
   * Update, delete and restore of a referenced media, in that order; the
   * MEDIA_STATE_KEY list records raw payloads so a deleted uuid stays gated.
   */
  public function testMediaChangeRecompiles(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'Hero background',
      'machine_name' => 'hero_bg',
      'scope' => 'kind:block',
      'status' => TRUE,
    ]);
    $variant->setStyles(['' => ['default' => ['background-image' => MediaToken::build($this->imageMedia->uuid())]]]);
    $variant->save();
    $state = $this->container->get('state');
    $uri = $state->get(VariantStylesheet::STATE_KEY);
    $this->assertStringContainsString('hero.png', file_get_contents($uri));

    // An unreferenced media save leaves the file alone (the state-list gate).
    $this->createImageMedia($this->imageMedia->bundle(), 'unrelated.png');
    $this->assertSame($uri, $state->get(VariantStylesheet::STATE_KEY));

    // Replacing the referenced media's file changes the resolved URL, so the
    // update hook recompiles to a new content-addressed file.
    $this->container->get('file_system')->copy($this->root . '/core/misc/druplicon.png', 'public://hero-replaced.png');
    $replacement = File::create(['uri' => 'public://hero-replaced.png', 'filename' => 'hero-replaced.png']);
    $replacement->save();
    $this->imageMedia->set($this->sourceField, ['target_id' => $replacement->id()]);
    $this->imageMedia->save();
    $replaced_uri = $state->get(VariantStylesheet::STATE_KEY);
    $this->assertNotSame($uri, $replaced_uri);
    $this->assertStringContainsString('hero-replaced.png', file_get_contents($replaced_uri));

    // Deleting the media recompiles again: the token degrades to "none".
    $this->imageMedia->delete();
    $deleted_uri = $state->get(VariantStylesheet::STATE_KEY);
    $this->assertNotSame($replaced_uri, $deleted_uri);
    $this->assertStringContainsString('background-image: none;', file_get_contents($deleted_uri));

    // The deleted uuid stays on the recorded list, so restoring the media
    // under the same uuid (a content sync) recompiles: "none" swaps back for
    // the URL. A reference to media that never existed never gets this far;
    // the judge refuses it at variant save.
    $this->createImageMedia($this->imageMedia->bundle(), 'hero-restored.png', ['uuid' => $this->imageMedia->uuid()]);
    $restored_uri = $state->get(VariantStylesheet::STATE_KEY);
    $this->assertNotSame($deleted_uri, $restored_uri);
    $this->assertStringContainsString('hero-restored.png', file_get_contents($restored_uri));
  }

  /**
   * Edit-mode inline variant resolution resolves tokens and bubbles tags.
   */
  public function testEditModeInlineVariantCss(): void {
    $variant = DaedalusStylishVariant::create([
      'label' => 'Hero background',
      'machine_name' => 'hero_bg',
      'scope' => 'kind:entity',
      'status' => TRUE,
    ]);
    $variant->setStyles(['' => ['default' => ['background-image' => MediaToken::build($this->imageMedia->uuid())]]]);
    $variant->save();

    $entity = EntityTest::create(['name' => 'on-variant']);
    $entity->save();
    $this->container->get('request_stack')->getCurrentRequest()->cookies->set('navigationMode', 'edit');
    [$css, $tags] = $this->buildCss($entity, [
      "entity:{$entity->uuid()}" => ['variants' => [['variant' => 'hero_bg', 'fragment' => '']]],
    ]);

    $this->assertStringContainsString("@scope ([data-selectable=\"entity:{$entity->uuid()}\"])", $css);
    $this->assertStringContainsString('hero.png', $css);
    $this->assertStringNotContainsString('media:', $css);
    // The media's tags bubble beside the variant's own, so a media edit
    // invalidates the cached edit-mode render.
    $this->assertContains('media:' . $this->imageMedia->id(), $tags);
    $this->assertContains("daedalus_variant:{$variant->id()}", $tags);
  }

}
