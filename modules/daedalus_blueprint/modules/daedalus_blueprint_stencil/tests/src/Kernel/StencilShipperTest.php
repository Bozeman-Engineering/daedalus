<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Test\TestDatabase;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\daedalus_blueprint_stencil\Exception\StencilShipException;
use Drupal\daedalus_blueprint_stencil\StencilDiscovery;
use Drupal\daedalus_blueprint_stencil\StencilDiscoveryInterface;
use Drupal\daedalus_blueprint_stencil\StencilOperationHandler;
use Drupal\daedalus_blueprint_stencil\StencilShipper;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\VariantImporter;
use Drupal\daedalus_stylish\VariantShipper;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the stencil ship lane: a placement to a module, with its variants.
 *
 * The lock worth having is the round trip: a ship writes the stencil file
 * and the per-id variants file into the target module, adopts the owned
 * variants in place (same uuid, provider stamped, ownership lane flipped to
 * the file), graduates the content stencil, and afterwards discovery serves
 * the file, the placement keeps its provenance stamp, and a variants sync
 * reports UNCHANGED. The icon rides along: a graduating stencil's media
 * file is copied into the module and named by the file's icon key, which
 * a re-ship keeps. The target module lives under the test's own site
 * directory (sites/simpletest/ID), never a real module directory, and is
 * removed on teardown (the VariantShipperTest pattern).
 */
#[CoversClass(StencilShipper::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilShipperTest extends StencilKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * The module the tests ship into.
   */
  protected const TARGET = 'stencil_ship_target';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_stylish'];

  /**
   * The target module's directory, absolute.
   */
  protected string $targetPath;

  /**
   * Whether the media stack (file and media schemas, config) is installed.
   */
  protected bool $mediaInstalled = FALSE;

  /**
   * {@inheritdoc}
   *
   * The target module is written into the test's REAL site directory before
   * the kernel boots (extension discovery caches each scanned directory per
   * process) and the real path is kept as a scan root after boot through
   * the test_parent_site setting; both file discoveries glob, which no
   * stream wrapper serves.
   */
  protected function setUpFilesystem(): void {
    parent::setUpFilesystem();
    $site = (new TestDatabase($this->databasePrefix))->getTestSitePath();
    $this->targetPath = $this->root . '/' . $site . '/modules/' . self::TARGET;
    mkdir($this->targetPath, 0777, TRUE);
    file_put_contents($this->targetPath . '/' . self::TARGET . '.info.yml', Yaml::encode([
      'name' => 'Stencil ship target',
      'type' => 'module',
      'core_version_requirement' => '*',
      'hidden' => TRUE,
      'dependencies' => ['daedalus_stylish:daedalus_stylish', 'daedalus_blueprint:daedalus_blueprint_stencil'],
    ]));
    $this->setSetting('test_parent_site', $site);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('daedalus_variant');
    // Variant references resolve through the materials roster, whose
    // daedalus_stylish provider reads all four storages on every build.
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->installConfig(['daedalus_stylish']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    $this->enableModules([self::TARGET]);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (isset($this->targetPath) && is_dir($site = dirname($this->targetPath, 2))) {
      foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($site, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
      }
      rmdir($site);
    }
    parent::tearDown();
  }

  /**
   * The shipper service, resolved late (installs rebuild the container).
   */
  protected function shipper(): StencilShipper {
    return \Drupal::service('daedalus_blueprint_stencil.shipper');
  }

  /**
   * The discovery service, resolved late.
   */
  protected function discovery(): StencilDiscoveryInterface {
    return \Drupal::service('daedalus_blueprint_stencil.discovery');
  }

  /**
   * Creates a container-scoped variant.
   */
  protected function createVariant(string $machine_name, array $values = []): DaedalusStylishVariant {
    $variant = DaedalusStylishVariant::create($values + [
      'label' => ucfirst(str_replace('_', ' ', $machine_name)),
      'machine_name' => $machine_name,
      'scope' => 'kind:container',
      'styles' => ['' => ['default' => ['padding' => '2rem']]],
    ]);
    $variant->save();
    return $variant;
  }

  /**
   * Loads a variant by machine name, uncached.
   */
  protected function loadVariant(string $machine_name): ?DaedalusStylishVariant {
    $storage = \Drupal::entityTypeManager()->getStorage('daedalus_variant');
    $storage->resetCache();
    $variants = $storage->loadByProperties(['machine_name' => $machine_name]);
    $variant = reset($variants) ?: NULL;
    assert($variant === NULL || $variant instanceof DaedalusStylishVariant);
    return $variant;
  }

  /**
   * Creates a content stencil whose element wears the given variants.
   */
  protected function createStencil(string $label, string $machine_name, array $variants = []): Stencil {
    $element = ['kind' => 'container', 'layout_id' => 'layout_onecol'];
    if ($variants !== []) {
      $element['styles'] = ['' => ['variants' => $variants]];
    }
    $stencil = Stencil::create([
      'label' => $label,
      'machine_name' => $machine_name,
      'description' => "The $label stencil.",
    ]);
    $stencil->setElement($element);
    $stencil->save();
    return $stencil;
  }

  /**
   * Places a stamped section on a fresh host, saved.
   *
   * @return array{entity: \Drupal\entity_test\Entity\EntityTest, placed: string}
   *   The host and the placed root's uuid.
   */
  protected function placeStamped(?string $stencil): array {
    ['entity' => $entity] = $this->createHost();
    $placed = \Drupal::service('uuid')->generate();
    $op = [
      'op' => 'add_section',
      'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
      'uuid' => $placed,
    ];
    if ($stencil !== NULL) {
      $op['stencil'] = $stencil;
    }
    $this->assertBatchSuccess($this->operations()->batchOperations($entity, [$op]));
    $entity->save();
    return ['entity' => $entity, 'placed' => $placed];
  }

  /**
   * The stencil file the ship writes.
   */
  protected function stencilFile(string $id): string {
    return $this->targetPath . '/' . StencilDiscovery::fileName(self::TARGET, $id);
  }

  /**
   * The per-id variants file the ship writes.
   */
  protected function variantsFile(string $id): string {
    return $this->targetPath . '/' . self::TARGET . '.daedalus_stylish.variants.' . $id . '.yml';
  }

  /**
   * Installs the media stack and creates a media type of the given source.
   *
   * The IconResolverTest fixture pattern; on demand because only the icon
   * case needs media entities.
   */
  protected function createIconMediaType(string $source): string {
    if (!$this->mediaInstalled) {
      $this->installEntitySchema('file');
      $this->installSchema('file', ['file_usage']);
      $this->installEntitySchema('media');
      $this->installConfig(['field', 'system', 'image', 'file', 'media']);
      $this->mediaInstalled = TRUE;
    }
    return $this->createMediaType($source)->id();
  }

  /**
   * Creates image media whose source file is a copy of druplicon.png.
   */
  protected function createImageMedia(string $name, string $uri): Media {
    $bundle = $this->createIconMediaType('image');
    $type = $this->container->get('entity_type.manager')->getStorage('media_type')->load($bundle);
    $source_field = $type->getSource()->getSourceFieldDefinition($type)->getName();
    $this->container->get('file_system')->copy($this->root . '/core/misc/druplicon.png', $uri);
    $file = File::create(['uri' => $uri, 'filename' => basename($uri)]);
    $file->save();
    $media = Media::create([
      'bundle' => $bundle,
      'name' => $name,
      $source_field => ['target_id' => $file->id()],
    ]);
    $media->save();
    return $media;
  }

  /**
   * Identity: the provenance stamp, then --id, else a refusal.
   */
  public function testIdentity(): void {
    // An unstamped placement (built by hand) has nothing to match against.
    ['entity' => $entity, 'placed' => $placed] = $this->placeStamped(NULL);
    try {
      $this->shipper()->plan($entity, $placed, self::TARGET);
      $this->fail('A placement without provenance must not ship without --id.');
    }
    catch (StencilShipException $e) {
      $this->assertSame('STENCIL_SHIP_IDENTITY_MISSING', $e->getErrors()[0]->getCode());
    }
    $this->assertFileDoesNotExist($this->stencilFile('card'));

    // --id names the stencil; a malformed id refuses.
    $plan = $this->shipper()->plan($entity, $placed, self::TARGET, ['id' => 'card']);
    $this->assertSame('card', $plan->id);
    try {
      $this->shipper()->plan($entity, $placed, self::TARGET, ['id' => 'Card Thing']);
      $this->fail('A malformed id must refuse.');
    }
    catch (StencilShipException $e) {
      $this->assertSame('STENCIL_SHIP_INVALID_ID', $e->getErrors()[0]->getCode());
    }

    // The stamp is the identity; --id overrides it.
    ['entity' => $entity, 'placed' => $placed] = $this->placeStamped('card');
    $this->assertSame('card', $this->shipper()->plan($entity, $placed, self::TARGET)->id);
    $this->assertSame('other', $this->shipper()->plan($entity, $placed, self::TARGET, ['id' => 'other'])->id);

    // Unknown element and unknown module refuse before anything else.
    try {
      $this->shipper()->plan($entity, '11111111-2222-3333-4444-555555555555', self::TARGET);
      $this->fail('An unknown element must refuse.');
    }
    catch (StencilShipException $e) {
      $this->assertSame('STENCIL_SHIP_ELEMENT_NOT_FOUND', $e->getErrors()[0]->getCode());
    }
    try {
      $this->shipper()->plan($entity, $placed, 'no_such_module');
      $this->fail('An unknown module must refuse.');
    }
    catch (StencilShipException $e) {
      $this->assertSame('STENCIL_SHIP_MODULE_UNKNOWN', $e->getErrors()[0]->getCode());
    }
  }

  /**
   * Existence: none creates, a target file updates, elsewhere refuses.
   *
   * And a content stencil with the id graduates.
   */
  public function testExistenceCases(): void {
    ['entity' => $entity, 'placed' => $placed] = $this->placeStamped('card');

    // Nothing anywhere: create. The plan is the dry run: nothing written.
    $plan = $this->shipper()->plan($entity, $placed, self::TARGET);
    $this->assertSame(StencilShipper::CREATED, $plan->stencil);
    $this->assertFalse($plan->graduates());
    $this->assertSame([], $plan->variants);
    $this->assertFileDoesNotExist($this->stencilFile('card'));

    // The ship writes the file; the same ship again is UNCHANGED, a new
    // label is an UPDATE, and the file's own label survives a ship that
    // names none (editing the face in place is safe).
    $plan = $this->shipper()->ship($entity, $placed, self::TARGET, ['label' => 'Card', 'description' => 'A card.']);
    $this->assertSame(StencilShipper::CREATED, $plan->stencil);
    $this->assertFileExists($this->stencilFile('card'));
    $written = Yaml::decode(file_get_contents($this->stencilFile('card')));
    $this->assertSame(['label', 'description', 'design_system', 'element'], array_keys($written));
    $this->assertSame('Card', $written['label']);
    $this->assertSame('A card.', $written['description']);
    $this->assertNull($written['design_system'], 'A placement with no stencil behind it, into a module packaging no system: global, written explicitly.');
    $this->assertSame('container', $written['element']['kind']);
    $this->assertStringStartsWith('# Machine-written by daedalus_blueprint_stencil', file_get_contents($this->stencilFile('card')));
    $this->assertSame(StencilShipper::UNCHANGED, $this->shipper()->plan($entity, $placed, self::TARGET)->stencil);
    $this->assertSame(StencilShipper::UNCHANGED, $this->shipper()->ship($entity, $placed, self::TARGET)->stencil);
    $this->assertSame(StencilShipper::UPDATED, $this->shipper()->plan($entity, $placed, self::TARGET, ['label' => 'Card v2'])->stencil);
    $this->shipper()->ship($entity, $placed, self::TARGET, ['label' => 'Card v2']);
    $this->assertSame('Card v2', Yaml::decode(file_get_contents($this->stencilFile('card')))['label']);
    $this->assertSame('A card.', Yaml::decode(file_get_contents($this->stencilFile('card')))['description']);

    // Discovery serves the target's file right away.
    $served = $this->discovery()->getStencil('card');
    $this->assertSame(StencilDiscoveryInterface::SOURCE_MODULE, $served['source']);
    $this->assertSame(self::TARGET, $served['provider']);
    $this->assertSame('Card v2', $served['label']);

    // A file in another module: refused, nothing written.
    $this->enableModules(['daedalus_blueprint_stencil_test']);
    Cache::invalidateTags([StencilDiscovery::CACHE_TAG]);
    try {
      $this->shipper()->ship($entity, $placed, self::TARGET, ['id' => 'hero']);
      $this->fail('An id another module ships must refuse.');
    }
    catch (StencilShipException $e) {
      $error = $e->getErrors()[0];
      $this->assertSame('STENCIL_SHIP_SHIPPED_ELSEWHERE', $error->getCode());
      $this->assertSame('daedalus_blueprint_stencil_test', $error->getContext()['provider']);
    }
    $this->assertFileDoesNotExist($this->stencilFile('hero'));

    // A content stencil with the id: graduation, whose face is the default.
    $stencil = $this->createStencil('Band', 'band');
    ['entity' => $entity, 'placed' => $placed] = $this->placeStamped('band');
    $plan = $this->shipper()->plan($entity, $placed, self::TARGET);
    $this->assertSame(StencilShipper::CREATED, $plan->stencil);
    $this->assertTrue($plan->graduates());
    $this->assertSame((int) $stencil->id(), (int) $plan->graduating->id());
    $this->assertSame('Band', $plan->definition['label']);
    $this->assertSame('The Band stencil.', $plan->definition['description']);
    // The dry run graduated nothing.
    $this->assertInstanceOf(Stencil::class, Stencil::load($stencil->id()));

    // An empty explicit face refuses.
    try {
      $this->shipper()->plan($entity, $placed, self::TARGET, ['description' => ' ']);
      $this->fail('An empty description must refuse.');
    }
    catch (StencilShipException $e) {
      $this->assertSame('MISSING_PARAMETER', $e->getErrors()[0]->getCode());
    }
  }

  /**
   * Graduation: the content stencil becomes the file, variants adopt in place.
   *
   * The site is consistent afterwards. The roster: card_left is owned but
   * unworn (ownership is curation, it ships), card_right is owned and worn,
   * free_look is unowned and worn (ships unowned, said out loud),
   * elsewhere_look is another module's shipped variant the subtree wears
   * (skipped, with the info.yml warning), and rival_look belongs to another
   * stencil (skipped).
   */
  public function testGraduation(): void {
    $left = $this->createVariant('card_left', ['description' => 'The card on the left.']);
    $right = $this->createVariant('card_right');
    $this->createVariant('free_look');
    $elsewhere = $this->createVariant('elsewhere_look');
    $elsewhere->setShippedSource('other_module', $elsewhere->getStyles());
    $elsewhere->save();
    $this->createVariant('rival_look');
    $stencil = $this->createStencil('Card', 'card', ['card_right']);
    $manager = $this->container->get('daedalus_blueprint_stencil.manager');
    $manager->adoptVariants($stencil, ['card_left', 'card_right']);
    $rival = $this->createStencil('Rival', 'rival');
    $manager->adoptVariants($rival, ['rival_look']);

    // The placement: a stamped root wearing card_right, nested sections
    // wearing the other looks (axes replace, so one look per element).
    ['entity' => $entity] = $this->createHost();
    $uuid = \Drupal::service('uuid');
    $placed = $uuid->generate();
    $nested = [$uuid->generate(), $uuid->generate(), $uuid->generate()];
    $result = $this->operations()->batchOperations($entity, [
      [
        'op' => 'add_section',
        'location' => ['anchor' => $entity->uuid(), 'placement' => 'inside'],
        'uuid' => $placed,
        'stencil' => 'card',
      ],
      ['op' => 'add_section', 'location' => ['anchor' => $placed, 'placement' => 'inside'], 'uuid' => $nested[0]],
      ['op' => 'add_section', 'location' => ['anchor' => $placed, 'placement' => 'inside'], 'uuid' => $nested[1]],
      ['op' => 'add_section', 'location' => ['anchor' => $placed, 'placement' => 'inside'], 'uuid' => $nested[2]],
      ['op' => 'apply_variant', 'key' => "container:$placed", 'variant' => 'card_right'],
      ['op' => 'apply_variant', 'key' => "container:{$nested[0]}", 'variant' => 'free_look'],
      ['op' => 'apply_variant', 'key' => "container:{$nested[1]}", 'variant' => 'elsewhere_look'],
    ]);
    $this->assertBatchSuccess($result);
    // The rival's look cannot be applied here (the apply gate), so it is
    // worn through the map directly: the roster reads wearings, not gates.
    $map = json_decode((string) $entity->get('daedalus_stylish_styles')->value, TRUE);
    $map["container:{$nested[2]}"] = ['variants' => [['variant' => 'rival_look', 'fragment' => '']]];
    $entity->set('daedalus_stylish_styles', json_encode($map));
    $entity->save();

    $plan = $this->shipper()->plan($entity, $placed, self::TARGET);
    $this->assertSame('card', $plan->id);
    $this->assertSame(StencilShipper::CREATED, $plan->stencil);
    $this->assertTrue($plan->graduates());
    $this->assertSame(['card_left', 'card_right', 'free_look'], array_keys($plan->variants));
    $this->assertSame('card', $plan->variants['card_left']['stencil']);
    $this->assertSame('card', $plan->variants['card_right']['stencil']);
    $this->assertArrayNotHasKey('stencil', $plan->variants['free_look']);
    $this->assertSame(['label', 'scope', 'description', 'design_system', 'stencil', 'styles'], array_keys($plan->variants['card_left']));
    $this->assertSame([
      'card_left' => VariantShipper::CREATED,
      'card_right' => VariantShipper::CREATED,
      'free_look' => VariantShipper::CREATED,
    ], $plan->variantOutcomes);
    $this->assertSame(['rival_look', 'elsewhere_look'], array_keys($plan->skipped));
    $this->assertStringContainsString('"rival" stencil', $plan->skipped['rival_look']);
    $this->assertStringContainsString('"other_module" module', $plan->skipped['elsewhere_look']);
    $this->assertCount(2, $plan->warnings);
    $this->assertStringContainsString('"other_module" module, which ' . self::TARGET . '.info.yml does not list', $plan->warnings[0]);
    $this->assertStringContainsString('"free_look" variant ships unowned', $plan->warnings[1]);
    // The dry run touched nothing.
    $this->assertFileDoesNotExist($this->stencilFile('card'));
    $this->assertFileDoesNotExist($this->variantsFile('card'));
    $this->assertInstanceOf(Stencil::class, Stencil::load($stencil->id()));
    $this->assertNull($this->loadVariant('card_left')->getProvider());

    $shipped = $this->shipper()->ship($entity, $placed, self::TARGET);
    $this->assertEquals($plan->variantOutcomes, $shipped->variantOutcomes);
    $this->assertEquals($plan->skipped, $shipped->skipped);

    // Disk: the two-file package, the owned entries carrying their owner.
    $this->assertFileExists($this->stencilFile('card'));
    $definition = Yaml::decode(file_get_contents($this->stencilFile('card')));
    $this->assertSame('Card', $definition['label']);
    $this->assertSame('The Card stencil.', $definition['description']);
    $this->assertSame(['card_right'], $definition['element']['styles']['']['variants']);
    $variants = Yaml::decode(file_get_contents($this->variantsFile('card')));
    $this->assertSame(['card_left', 'card_right', 'free_look'], array_keys($variants));
    $this->assertSame('card', $variants['card_left']['stencil']);
    $this->assertSame('The card on the left.', $variants['card_left']['description']);
    $this->assertSame('card', $variants['card_right']['stencil']);
    $this->assertArrayNotHasKey('stencil', $variants['free_look']);

    // Entities: adopted in place (same uuid and id), provider stamped, the
    // ownership lane flipped from the content reference to the file.
    foreach (['card_left' => $left, 'card_right' => $right] as $name => $original) {
      $adopted = $this->loadVariant($name);
      $this->assertSame($original->uuid(), $adopted->uuid());
      $this->assertSame($original->id(), $adopted->id());
      $this->assertSame(self::TARGET, $adopted->getProvider());
      $this->assertFalse($adopted->isModified());
      $this->assertTrue($adopted->isPublished());
      $this->assertTrue($adopted->get('stencil')->isEmpty());
      $this->assertSame('card', $adopted->get('stencil_file')->value);
      $this->assertSame('card', $manager->variantOwnershipPayload($adopted)['stencil']);
    }
    $free = $this->loadVariant('free_look');
    $this->assertSame(self::TARGET, $free->getProvider());
    $this->assertTrue($free->get('stencil')->isEmpty());
    $this->assertTrue($free->get('stencil_file')->isEmpty());
    $this->assertSame('other_module', $this->loadVariant('elsewhere_look')->getProvider());
    $this->assertNull($this->loadVariant('rival_look')->getProvider());
    $this->assertSame((int) $rival->id(), (int) $this->loadVariant('rival_look')->get('stencil')->target_id);

    // The content stencil graduated: gone, and discovery serves the file
    // under the same id without a collision.
    $this->assertNull(Stencil::load($stencil->id()));
    $served = $this->discovery()->getStencil('card');
    $this->assertSame(StencilDiscoveryInterface::SOURCE_MODULE, $served['source']);
    $this->assertSame(self::TARGET, $served['provider']);
    $this->assertSame($definition['element'], $served['element']);

    // The placement keeps its provenance stamp: the placed root still says
    // it came from "card", now the file stencil.
    $reloaded = EntityTest::load($entity->id());
    $this->assertSame('card', $reloaded->get('layout_builder__layout')->getSection(1)->getThirdPartySetting(StencilOperationHandler::PROVENANCE_KEY, 'stencil'));

    // The site is consistent: a sync of the target reports UNCHANGED for
    // every shipped name and keeps the file-lane ownership.
    $importer = $this->container->get('daedalus_stylish.variant_importer');
    assert($importer instanceof VariantImporter);
    $this->assertSame([
      self::TARGET => [
        'card_left' => VariantImporter::UNCHANGED,
        'card_right' => VariantImporter::UNCHANGED,
        'free_look' => VariantImporter::UNCHANGED,
      ],
    ], $importer->sync([self::TARGET])->outcomes());
    $this->assertSame('card', $this->loadVariant('card_left')->get('stencil_file')->value);
    $this->assertTrue($this->loadVariant('card_left')->get('stencil')->isEmpty());

    // Shipping the same placement again: the file stencil is the source
    // now (its file-lane roster), nothing graduates, everything UNCHANGED.
    $again = $this->shipper()->ship($entity, $placed, self::TARGET);
    $this->assertSame(StencilShipper::UNCHANGED, $again->stencil);
    $this->assertFalse($again->graduates());
    $this->assertSame([
      'card_left' => VariantShipper::UNCHANGED,
      'card_right' => VariantShipper::UNCHANGED,
      'free_look' => VariantShipper::UNCHANGED,
    ], $again->variantOutcomes);
  }

  /**
   * The membership round-trips: written by the ship, served by discovery.
   *
   * A graduating stencil's design system is written into the file (the
   * owned variants carry theirs through daedalus_stylish's shipper), discovery
   * serves it, a re-ship is UNCHANGED, and a later ship from a placement
   * with no stencil behind it keeps the file's key the way it keeps the
   * label and description.
   */
  public function testDesignSystemRoundTrip(): void {
    $this->enableModules(['daedalus_blueprint_design_system_test']);
    $this->installConfig(['daedalus_blueprint', 'daedalus_blueprint_design_system_test']);
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate('aurora');
    $manager = $this->container->get('daedalus_blueprint_stencil.manager');
    $this->createVariant('glow_look');
    $stencil = $this->createStencil('Glow', 'glow', ['glow_look']);
    $stencil->setDesignSystem('aurora')->save();
    $manager->adoptVariants($stencil, ['glow_look']);
    $this->assertSame('aurora', $this->loadVariant('glow_look')->getDesignSystem());

    ['entity' => $entity, 'placed' => $placed] = $this->placeStamped('glow');
    $this->assertBatchSuccess($this->operations()->batchOperations($entity, [
      ['op' => 'apply_variant', 'key' => "container:$placed", 'variant' => 'glow_look'],
    ]));
    $entity->save();

    $plan = $this->shipper()->plan($entity, $placed, self::TARGET);
    $this->assertSame('aurora', $plan->definition['design_system']);
    $this->assertSame(['label', 'description', 'design_system', 'element'], array_keys($plan->definition));
    $this->assertSame('aurora', $plan->variants['glow_look']['design_system']);

    $this->shipper()->ship($entity, $placed, self::TARGET);
    $written = Yaml::decode(file_get_contents($this->stencilFile('glow')));
    $this->assertSame('aurora', $written['design_system']);
    $this->assertSame('aurora', Yaml::decode(file_get_contents($this->variantsFile('glow')))['glow_look']['design_system']);
    $served = $this->discovery()->getStencil('glow');
    $this->assertSame(StencilDiscoveryInterface::SOURCE_MODULE, $served['source']);
    $this->assertSame('aurora', $served['design_system']);
    $this->assertSame('aurora', $this->container->get('daedalus_blueprint.materials_roster_builder')->build()->get(MaterialKind::Stencil, 'glow')->designSystem);
    $this->assertSame('aurora', $this->loadVariant('glow_look')->getDesignSystem());

    // Nothing graduates now: the file's own key is kept, and the ship is
    // UNCHANGED under a different active system too (membership is the
    // file's fact, not the site's).
    $this->assertSame(StencilShipper::UNCHANGED, $this->shipper()->plan($entity, $placed, self::TARGET)->stencil);
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate('borealis');
    $this->assertSame(StencilShipper::UNCHANGED, $this->shipper()->plan($entity, $placed, self::TARGET)->stencil);
    $this->assertSame('aurora', Yaml::decode(file_get_contents($this->stencilFile('glow')))['design_system']);
  }

  /**
   * The AI opt-out: ai: false on graduation, kept on a re-ship, no key else.
   *
   * A withheld graduating stencil ships `ai: false` (the key's one
   * spelling), discovery serves it resolved as offered FALSE and the
   * roster's offered view omits it while the full roster keeps it; a
   * re-ship from the placement keeps the key like the label; an offered
   * stencil ships no key, the default's one spelling on disk.
   */
  public function testAiOptOut(): void {
    $this->createStencil('Card', 'card')->setOffered(FALSE)->save();
    ['entity' => $entity, 'placed' => $placed] = $this->placeStamped('card');

    // A withheld graduating stencil ships ai: false between icon and
    // design_system; the plan is the file.
    $plan = $this->shipper()->plan($entity, $placed, self::TARGET);
    $this->assertTrue($plan->graduates());
    $this->assertSame(['label', 'description', 'ai', 'design_system', 'element'], array_keys($plan->definition));
    $this->assertFalse($plan->definition['ai']);
    $this->assertSame(StencilShipper::CREATED, $this->shipper()->ship($entity, $placed, self::TARGET)->stencil);
    $written = Yaml::decode(file_get_contents($this->stencilFile('card')));
    $this->assertSame(['label', 'description', 'ai', 'design_system', 'element'], array_keys($written));
    $this->assertFalse($written['ai']);

    // Discovery serves the flag resolved; the roster keeps two views of
    // the one site: legality reads the full set, the AI the offered one.
    $served = $this->discovery()->getStencil('card');
    $this->assertSame(StencilDiscoveryInterface::SOURCE_MODULE, $served['source']);
    $this->assertFalse($served['offered']);
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertFalse($roster->stencils()['card']->offered);
    $this->assertArrayNotHasKey('card', $roster->offered()->stencils());

    // A re-ship from the placement keeps the key like the label, through
    // UNCHANGED and UPDATED alike.
    $this->assertSame(StencilShipper::UNCHANGED, $this->shipper()->ship($entity, $placed, self::TARGET)->stencil);
    $updated = $this->shipper()->ship($entity, $placed, self::TARGET, ['label' => 'Card v2']);
    $this->assertSame(StencilShipper::UPDATED, $updated->stencil);
    $written = Yaml::decode(file_get_contents($this->stencilFile('card')));
    $this->assertSame('Card v2', $written['label']);
    $this->assertFalse($written['ai']);

    // An offered stencil ships no key: the default has one spelling on
    // disk, its absence (the existing key lists pin it).
    $this->createStencil('Band', 'band');
    ['entity' => $entity, 'placed' => $placed] = $this->placeStamped('band');
    $plan = $this->shipper()->ship($entity, $placed, self::TARGET);
    $this->assertArrayNotHasKey('ai', $plan->definition);
    $this->assertArrayNotHasKey('ai', Yaml::decode(file_get_contents($this->stencilFile('band'))));
    $this->assertTrue($this->discovery()->getStencil('band')['offered']);
  }

  /**
   * The icon: copied on graduation, kept on a re-ship, none otherwise.
   *
   * A graduating stencil's media file lands at assets/stencils/ID.<ext> and
   * the file's icon key names it (discovery resolves it after the ship,
   * the media entity stays); a re-ship from the placement keeps the key
   * like the label and description; a stencil without an icon ships none;
   * a media that resolves to no file ships none with a warning; the dry
   * run copies nothing.
   */
  public function testIcon(): void {
    $media = $this->createImageMedia('Card icon', 'public://card-icon.png');
    $stencil = $this->createStencil('Card', 'card');
    $stencil->setIcon($media)->save();
    ['entity' => $entity, 'placed' => $placed] = $this->placeStamped('card');
    $icon = $this->targetPath . '/' . StencilShipper::ICON_DIRECTORY . '/card.png';

    // The plan names the copy; the dry run copies nothing.
    $plan = $this->shipper()->plan($entity, $placed, self::TARGET);
    $this->assertTrue($plan->graduates());
    $this->assertSame(StencilShipper::ICON_COPIED, $plan->icon);
    $this->assertSame(StencilShipper::ICON_DIRECTORY . '/card.png', $plan->definition['icon']);
    $this->assertSame(['label', 'description', 'icon', 'design_system', 'element'], array_keys($plan->definition));
    $this->assertSame('public://card-icon.png', $plan->iconSource->getFileUri());
    $this->assertSame([], $plan->warnings);
    $this->assertFileDoesNotExist($icon);
    $this->assertDirectoryDoesNotExist(dirname($icon));

    // The ship copies the file and writes the key naming it.
    $shipped = $this->shipper()->ship($entity, $placed, self::TARGET);
    $this->assertSame(StencilShipper::ICON_COPIED, $shipped->icon);
    $this->assertFileExists($icon);
    $this->assertFileEquals($this->root . '/core/misc/druplicon.png', $icon);
    $written = Yaml::decode(file_get_contents($this->stencilFile('card')));
    $this->assertSame(['label', 'description', 'icon', 'design_system', 'element'], array_keys($written));
    $this->assertSame(StencilShipper::ICON_DIRECTORY . '/card.png', $written['icon']);

    // Discovery serves the file's icon resolved to the module's path; the
    // media entity is site content and stays.
    $served = $this->discovery()->getStencil('card');
    $this->assertSame(StencilDiscoveryInterface::SOURCE_MODULE, $served['source']);
    $this->assertSame('/' . $this->container->get('extension.list.module')->getPath(self::TARGET) . '/' . StencilShipper::ICON_DIRECTORY . '/card.png', $served['icon']);
    $this->assertNull(Stencil::load($stencil->id()));
    $this->assertInstanceOf(Media::class, Media::load($media->id()));

    // A re-ship from the placement has no icon source: the key is kept,
    // like the label and description, through UNCHANGED and UPDATED alike.
    $again = $this->shipper()->ship($entity, $placed, self::TARGET);
    $this->assertSame(StencilShipper::ICON_KEPT, $again->icon);
    $this->assertNull($again->iconSource);
    $this->assertSame(StencilShipper::UNCHANGED, $again->stencil);
    $this->assertSame(StencilShipper::ICON_DIRECTORY . '/card.png', $again->definition['icon']);
    $updated = $this->shipper()->ship($entity, $placed, self::TARGET, ['label' => 'Card v2']);
    $this->assertSame(StencilShipper::ICON_KEPT, $updated->icon);
    $this->assertSame(StencilShipper::UPDATED, $updated->stencil);
    $written = Yaml::decode(file_get_contents($this->stencilFile('card')));
    $this->assertSame('Card v2', $written['label']);
    $this->assertSame(StencilShipper::ICON_DIRECTORY . '/card.png', $written['icon']);
    $this->assertFileEquals($this->root . '/core/misc/druplicon.png', $icon);

    // A stencil without an icon ships none: no key, no file, no warning.
    $this->createStencil('Band', 'band');
    ['entity' => $entity, 'placed' => $placed] = $this->placeStamped('band');
    $plan = $this->shipper()->ship($entity, $placed, self::TARGET);
    $this->assertSame(StencilShipper::ICON_NONE, $plan->icon);
    $this->assertNull($plan->iconSource);
    $this->assertArrayNotHasKey('icon', $plan->definition);
    $this->assertSame([], $plan->warnings);
    $this->assertArrayNotHasKey('icon', Yaml::decode(file_get_contents($this->stencilFile('band'))));
    $this->assertNull($this->discovery()->getStencil('band')['icon']);

    // A media that resolves to no file ships none, and says so.
    $empty = Media::create(['bundle' => $this->createIconMediaType('file'), 'name' => 'Empty document']);
    $empty->save();
    $this->createStencil('Promo', 'promo')->setIcon($empty)->save();
    ['entity' => $entity, 'placed' => $placed] = $this->placeStamped('promo');
    $plan = $this->shipper()->ship($entity, $placed, self::TARGET);
    $this->assertSame(StencilShipper::ICON_NONE, $plan->icon);
    $this->assertArrayNotHasKey('icon', $plan->definition);
    $this->assertCount(1, $plan->warnings);
    $this->assertStringContainsString('icon media "Empty document" (' . $empty->id() . ') references no file', $plan->warnings[0]);
    $this->assertArrayNotHasKey('icon', Yaml::decode(file_get_contents($this->stencilFile('promo'))));
    $this->assertSame([$icon], glob($this->targetPath . '/' . StencilShipper::ICON_DIRECTORY . '/*'));
    $this->assertInstanceOf(Media::class, Media::load($empty->id()));
  }

}
