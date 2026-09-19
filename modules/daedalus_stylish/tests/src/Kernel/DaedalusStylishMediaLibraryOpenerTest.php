<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\media\Entity\Media;
use Drupal\media\MediaInterface;
use Drupal\media_library\MediaLibraryState;
use Drupal\daedalus_stylish\MediaToken;
use Drupal\daedalus_stylish\DaedalusStylishMediaLibraryOpener;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the media library opener for media-valued styles.
 *
 * The opener's contract: buildUrl() constructs the media_library.ui URL the
 * inspector's pickers open (state hash included, the map host in the opener
 * parameters, allowed types gated to image- and vector-source media types —
 * validation's MEDIA_NOT_IMAGE gate mirrored at the offer), checkAccess()
 * gates the library on host-entity update access (the same gate the
 * update_style mutation the selection commits through enforces), and
 * getSelectionResponse() returns the one selection command carrying the
 * semantic token plus the server-resolved preview URL.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class DaedalusStylishMediaLibraryOpenerTest extends KernelTestBase {

  use MediaTypeCreationTrait;
  use UserCreationTrait;

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
    'views',
    // media_library's editor opener resolves filter_format storage during
    // route building; filter provides the entity type.
    'filter',
    'media_library',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'entity_test',
    'daedalus_stylish',
  ];

  /**
   * The opener under test.
   */
  protected DaedalusStylishMediaLibraryOpener $opener;

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
    $this->installConfig(['field', 'system', 'image', 'file', 'media', 'daedalus_stylish']);
    // The service provider registers the opener because media_library is
    // installed; getting it here is itself the registration assertion.
    $this->opener = $this->container->get(DaedalusStylishMediaLibraryOpener::SERVICE_ID);
  }

  /**
   * Creates image media around a copy of core's druplicon.
   */
  protected function createImageMedia(string $bundle, string $source_field, string $filename): MediaInterface {
    $this->container->get('file_system')->copy($this->root . '/core/misc/druplicon.png', "public://$filename");
    $file = File::create(['uri' => "public://$filename", 'filename' => $filename]);
    $file->save();
    $media = Media::create([
      'bundle' => $bundle,
      'name' => $filename,
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

  /**
   * A state addressing this opener with the given opener parameters.
   */
  protected function state(array $opener_parameters): MediaLibraryState {
    return MediaLibraryState::create(
      DaedalusStylishMediaLibraryOpener::SERVICE_ID,
      ['image'],
      'image',
      1,
      $opener_parameters,
    );
  }

  /**
   * Access follows host-entity update access; broken addressing is forbidden.
   */
  public function testCheckAccess(): void {
    // Uid 1 bypasses access; burn it so the real accounts are ordinary.
    $this->createUser();
    $no_access = $this->createUser();
    $editor = $this->createUser(['administer entity_test content']);

    $host = EntityTest::create(['name' => 'Host page']);
    $host->save();

    // Missing opener parameters forbid, never fall through.
    $access = $this->opener->checkAccess($this->state([]), $editor);
    $this->assertTrue($access->isForbidden());
    $this->assertSame('entity_type_id parameter is missing.', $access->getReason());

    // An unloadable host forbids.
    $access = $this->opener->checkAccess($this->state([
      'entity_type_id' => 'entity_test',
      'entity_id' => '999',
    ]), $editor);
    $this->assertTrue($access->isForbidden());

    // The gate is the HOST entity's update access — the same check the
    // blueprint mutation endpoint the selection commits through enforces.
    $parameters = [
      'entity_type_id' => 'entity_test',
      'entity_id' => (string) $host->id(),
    ];
    $this->assertFalse($this->opener->checkAccess($this->state($parameters), $no_access)->isAllowed());
    $this->assertTrue($this->opener->checkAccess($this->state($parameters), $editor)->isAllowed());
  }

  /**
   * The buildUrl offers image- and vector-source types only, tamper-proof.
   */
  public function testBuildUrl(): void {
    $host = EntityTest::create(['name' => 'Host page']);
    $host->save();

    // No image- or vector-source media types: nothing selectable could ever
    // validate, so no library is offered.
    $this->assertNull($this->opener->buildUrl($host));

    // The allowed types mirror validation's gate: the source FIELD TYPE
    // (image or svg_image_field), so the file-source type is excluded even
    // though media_library could list it, while the vector type is offered
    // (vectors resolve to inert background URLs like any raster).
    $image_type = $this->createMediaType('image');
    $vector_type = $this->createMediaType('svg');
    $this->createMediaType('file');
    $url = $this->opener->buildUrl($host);
    $this->assertNotNull($url);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    $this->assertSame(DaedalusStylishMediaLibraryOpener::SERVICE_ID, $query['media_library_opener_id']);
    $allowed = array_values($query['media_library_allowed_types']);
    sort($allowed);
    $expected = [$image_type->id(), $vector_type->id()];
    sort($expected);
    $this->assertSame($expected, $allowed);
    $this->assertContains($query['media_library_selected_type'], $expected);
    $this->assertSame('1', $query['media_library_remaining']);
    $this->assertSame([
      'entity_type_id' => 'entity_test',
      'entity_id' => (string) $host->id(),
    ], $query['media_library_opener_parameters']);

    // The state round-trips: fromRequest() re-validates the hash (throwing
    // on tampering), and the reconstructed state passes checkAccess for a
    // host editor — the whole open-the-modal handshake.
    $state = MediaLibraryState::fromRequest(Request::create($url));
    $this->createUser();
    $editor = $this->createUser(['administer entity_test content']);
    $this->assertTrue($this->opener->checkAccess($state, $editor)->isAllowed());

    // An unsaved host cannot be addressed by the opener parameters.
    $this->assertNull($this->opener->buildUrl(EntityTest::create(['name' => 'Unsaved'])));
  }

  /**
   * The selection returns as one command: token + uuid + preview URL.
   */
  public function testSelectionResponse(): void {
    $image_type = $this->createMediaType('image');
    $source_field = $image_type->getSource()->getSourceFieldDefinition($image_type)->getName();
    $media = $this->createImageMedia($image_type->id(), $source_field, 'hero.png');
    $state = $this->state([
      'entity_type_id' => 'entity_test',
      'entity_id' => '1',
    ]);

    $response = $this->opener->getSelectionResponse($state, [$media->id()]);
    $this->assertInstanceOf(AjaxResponse::class, $response);
    $commands = $response->getCommands();
    $this->assertCount(1, $commands);
    $this->assertSame('daedalusStylishMediaLibrarySelection', $commands[0]['command']);
    $this->assertSame(strtolower($media->uuid()), $commands[0]['uuid']);
    $this->assertSame(MediaToken::build($media->uuid()), $commands[0]['token']);
    // No site default: the original file's URL previews.
    $this->assertStringContainsString('hero.png', $commands[0]['url']);
    $this->assertStringNotContainsString('/styles/', $commands[0]['url']);

    // The preview URL rides the same resolution as delivery: the site
    // default image style applies (no key exists at selection time, so no
    // guide constraint can).
    $this->config('daedalus_stylish.settings')->set('media_image_style', 'thumbnail')->save();
    $commands = $this->opener->getSelectionResponse($state, [$media->id()])->getCommands();
    $this->assertStringContainsString('/styles/thumbnail/', $commands[0]['url']);

    // A selection that no longer loads (deleted underneath the open modal)
    // returns no selection command — the modal just closes.
    $this->assertCount(0, $this->opener->getSelectionResponse($state, [999])->getCommands());
  }

}
