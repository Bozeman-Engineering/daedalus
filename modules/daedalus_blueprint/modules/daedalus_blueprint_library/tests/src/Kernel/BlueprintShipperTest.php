<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Test\TestDatabase;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint_library\BlueprintDiscovery;
use Drupal\daedalus_blueprint_library\BlueprintDiscoveryInterface;
use Drupal\daedalus_blueprint_library\BlueprintLayout;
use Drupal\daedalus_blueprint_library\BlueprintShipper;
use Drupal\daedalus_blueprint_library\Drush\Commands\BlueprintShipCommand;
use Drupal\daedalus_blueprint_library\Entity\Blueprint;
use Drupal\daedalus_blueprint_library\Exception\BlueprintShipException;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\VariantImporter;
use Drupal\daedalus_stylish\VariantShipper;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the blueprint ship lane: a saved blueprint to a module, with its variants.
 *
 * The lock worth having is the round trip: a ship writes the blueprint
 * file (discovery's envelope, built from the blueprint host's tree and
 * node-less styles) and the per-id variants file into the target module,
 * adopts the owned variants in place (same uuid, provider stamped,
 * ownership lane flipped to the file), graduates the content blueprint,
 * and afterwards discovery serves the file as an entry equal to the one
 * it served for the entity, a variants sync reports UNCHANGED, and the
 * dry run printed exactly the file the ship wrote. The icon rides along:
 * a blueprint's media file is copied into the module and named by the
 * file's icon key. The target module lives under the test's own site
 * directory (sites/simpletest/ID), never a real module directory, and is
 * removed on teardown (the VariantShipperTest pattern).
 */
#[CoversClass(BlueprintShipper::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintShipperTest extends BlueprintKernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * The module the tests ship into.
   */
  protected const TARGET = 'blueprint_ship_target';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_stylish'];

  /**
   * The target module's directory, absolute.
   */
  protected string $targetPath;

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
      'name' => 'Blueprint ship target',
      'type' => 'module',
      'core_version_requirement' => '*',
      'hidden' => TRUE,
      'dependencies' => ['daedalus_stylish:daedalus_stylish', 'daedalus_blueprint:daedalus_blueprint_library'],
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
    $this->installEntitySchema('daedalus_font');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_stylish']);
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
  protected function shipper(): BlueprintShipper {
    return \Drupal::service('daedalus_blueprint_library.shipper');
  }

  /**
   * The discovery service, resolved late.
   */
  protected function discovery(): BlueprintDiscoveryInterface {
    return \Drupal::service('daedalus_blueprint_library.discovery');
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
   * A onecol container node, optionally wearing variants, holding children.
   */
  protected function container(array $variants = [], array $children = []): array {
    $node = ['kind' => 'container', 'layout_id' => 'layout_onecol'];
    if ($variants !== []) {
      $node['styles'] = ['' => ['variants' => $variants]];
    }
    if ($children !== []) {
      $node['children'] = [['kind' => 'region', 'region' => 'content', 'children' => $children]];
    }
    return $node;
  }

  /**
   * Creates a saved blueprint whose one root wears the given variants.
   */
  protected function createWearing(string $label, string $machine_name, array $variants = [], array $values = []): Blueprint {
    return $this->createBlueprintFromTree($values + [
      'label' => $label,
      'machine_name' => $machine_name,
      'description' => "The $label blueprint.",
    ], [$this->container($variants)]);
  }

  /**
   * The blueprint file the ship writes.
   */
  protected function blueprintFile(string $id): string {
    return $this->targetPath . '/' . BlueprintDiscovery::fileName(self::TARGET, $id);
  }

  /**
   * The per-id variants file the ship writes.
   */
  protected function variantsFile(string $id): string {
    return $this->targetPath . '/' . self::TARGET . '.daedalus_stylish.variants.' . $id . '.yml';
  }

  /**
   * Creates image media whose source file is a copy of druplicon.png.
   */
  protected function createImageMedia(string $name, string $uri): Media {
    $this->installConfig(['system']);
    $bundle = $this->createMediaType('image')->id();
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
   * A tree with every text block's field_ref dropped: the shipped reading.
   */
  protected function withoutFieldRef(array $nodes): array {
    return array_map(function (array $node): array {
      unset($node['block_fields']['field_ref']);
      if (isset($node['children'])) {
        $node['children'] = $this->withoutFieldRef($node['children']);
      }
      return $node;
    }, $nodes);
  }

  /**
   * A fresh tester over the command, from the current container.
   */
  protected function tester(): CommandTester {
    return new CommandTester(BlueprintShipCommand::create(\Drupal::getContainer()));
  }

  /**
   * Refusals: the module, the id, a file under the id, an empty document, the face.
   */
  public function testRefusals(): void {
    $blueprint = $this->createWearing('Card', 'card');
    try {
      $this->shipper()->plan($blueprint, 'no_such_module');
      $this->fail('An unknown module must refuse.');
    }
    catch (BlueprintShipException $e) {
      $this->assertSame('BLUEPRINT_SHIP_MODULE_UNKNOWN', $e->getErrors()[0]->getCode());
    }

    // A machine name that is not a file id.
    try {
      $this->shipper()->plan($this->createWearing('Bad card', 'Bad card'), self::TARGET);
      $this->fail('A malformed id must refuse.');
    }
    catch (BlueprintShipException $e) {
      $this->assertSame('BLUEPRINT_SHIP_INVALID_ID', $e->getErrors()[0]->getCode());
    }

    // A file already under the id in the target module (a state discovery
    // refuses at its next rebuild): nothing is overwritten.
    file_put_contents($this->blueprintFile('card'), "label: Stale\n");
    try {
      $this->shipper()->plan($blueprint, self::TARGET);
      $this->fail('A file under the id must refuse.');
    }
    catch (BlueprintShipException $e) {
      $this->assertSame('BLUEPRINT_SHIP_FILE_EXISTS', $e->getErrors()[0]->getCode());
    }
    unlink($this->blueprintFile('card'));

    // An empty document: a blueprint file holds at least one root.
    try {
      $this->shipper()->plan($this->createBlueprint(['label' => 'Empty', 'machine_name' => 'empty', 'layout_builder__layout' => []]), self::TARGET);
      $this->fail('An empty document must refuse.');
    }
    catch (BlueprintShipException $e) {
      $this->assertSame('BLUEPRINT_SHIP_EMPTY', $e->getErrors()[0]->getCode());
    }

    // An empty explicit face refuses; the dry run graduated nothing.
    try {
      $this->shipper()->plan($blueprint, self::TARGET, ['description' => ' ']);
      $this->fail('An empty description must refuse.');
    }
    catch (BlueprintShipException $e) {
      $this->assertSame('MISSING_PARAMETER', $e->getErrors()[0]->getCode());
    }
    $this->assertInstanceOf(Blueprint::class, Blueprint::load($blueprint->id()));
    $this->assertFileDoesNotExist($this->blueprintFile('card'));
  }

  /**
   * The envelope: the dry run prints the file, discovery reads it back equal.
   *
   * A blueprint captured from the reference section (a labeled two-column
   * container, a text block pinning a heading and an entity reference, a
   * basic block, a nested container) with a node-less style on its root
   * ships as discovery's envelope: the host's tree without identity, the
   * reference stripped, the root key bare; the command's dry run prints
   * that file byte for byte and writes nothing; the ship writes it, and
   * the entry discovery then serves for the file equals the entry it
   * served for the entity (identity and the site-local reference aside).
   */
  public function testEnvelopeRoundTrip(): void {
    $this->createReferenceFields();
    ['entity' => $entity, 'section' => $section] = $this->buildReferenceSection();
    $entity->save();
    $result = $this->container->get('daedalus_blueprint_library.manager')->saveAsBlueprint($this->host($entity), $section, 'Call to Action', 'Two-column card.');
    $this->assertSame([], $result->warnings);
    $blueprint = $this->loadBlueprint($result->blueprint->getMachineName());
    $map = $this->host($blueprint)->styleMap();
    $map['entity:' . $blueprint->uuid()] = ['default' => ['margin' => '1rem']];
    $blueprint->set('daedalus_stylish_styles', json_encode($map));
    $blueprint->setGroups(['heroes', 'bands']);
    $blueprint->setFavorite(TRUE);
    $blueprint->save();
    $blueprint = $this->loadBlueprint('call_to_action');
    $before = $this->discovery()->getBlueprint('call_to_action');
    $this->assertSame(BlueprintDiscoveryInterface::SOURCE_CONTENT, $before['source']);

    // The plan is the envelope in discovery's key order; the layout is
    // the saved document without identity and without the reference.
    $plan = $this->shipper()->plan($blueprint, self::TARGET);
    $envelope_keys = [
      'label',
      'description',
      'design_system',
      'groups',
      'favorite',
      'scope',
      'subject',
      'layout',
      'styles',
    ];
    $this->assertSame($envelope_keys, array_keys($plan->definition));
    $this->assertSame('Call to Action', $plan->definition['label']);
    $this->assertSame('Two-column card.', $plan->definition['description']);
    $this->assertNull($plan->definition['design_system']);
    $this->assertSame(['heroes', 'bands'], $plan->definition['groups'], 'The entity\'s groups ship as the envelope\'s groups key.');
    $this->assertTrue($plan->definition['favorite'], 'A site favorite ships favorite: true, the key\'s one spelling.');
    $this->assertSame('block', $plan->definition['scope']);
    $this->assertSame('entity_test:entity_test', $plan->definition['subject']);
    $this->assertSame($this->withoutFieldRef($this->definitionOf($blueprint)), $plan->definition['layout']);
    $text = $plan->definition['layout'][0]['children'][0]['children'][0];
    $this->assertSame('Pinned heading', $text['block_fields']['field_heading']);
    $this->assertArrayNotHasKey('field_ref', $text['block_fields'], 'References are stripped on the way to disk.');
    $this->assertSame(['entity:' => ['default' => ['margin' => '1rem']]], $plan->definition['styles'], 'The root key is bare: a file has no host.');
    $this->assertSame([], $plan->variants);
    $this->assertSame([], $plan->warnings);
    $this->assertFileDoesNotExist($this->blueprintFile('call_to_action'));

    // The command's dry run prints the report and the file as it would be
    // written, and writes nothing.
    $tester = $this->tester();
    $this->assertSame(Command::SUCCESS, $tester->execute(['blueprint' => 'call_to_action', 'module' => self::TARGET, '--dry-run' => TRUE]));
    $display = $tester->getDisplay();
    $this->assertStringContainsString(self::TARGET . ': blueprint call_to_action created', $display);
    $this->assertStringContainsString('content blueprint "Call to Action" (' . $blueprint->id() . ') graduates', $display);
    $this->assertStringContainsString('Dry run: nothing was written.', $display);
    $start = strpos($display, '# Machine-written by daedalus_blueprint_library (BlueprintShipper)');
    $this->assertNotFalse($start);
    // The file ends where the closing comment's line begins.
    $end = strrpos(substr($display, 0, strpos($display, 'Dry run')), "\n");
    $printed = substr($display, $start, $end - $start);
    $this->assertSame($plan->definition, Yaml::decode($printed), 'The dry run prints the envelope the ship writes.');
    $this->assertSame(rtrim($this->shipper()->fileContents($plan)), rtrim($printed));
    $this->assertFileDoesNotExist($this->blueprintFile('call_to_action'));
    $this->assertInstanceOf(Blueprint::class, Blueprint::load($blueprint->id()));

    // The ship writes that file; discovery serves it as the entry it served
    // for the entity, minus identity and the reference.
    $this->shipper()->ship($blueprint, self::TARGET);
    $this->assertSame($this->shipper()->fileContents($plan), file_get_contents($this->blueprintFile('call_to_action')));
    $this->assertNull(Blueprint::load($blueprint->id()), 'The content blueprint graduated.');
    $served = $this->discovery()->getBlueprint('call_to_action');
    $this->assertSame(BlueprintDiscoveryInterface::SOURCE_MODULE, $served['source']);
    $this->assertSame(self::TARGET, $served['provider']);
    foreach (['label', 'description', 'icon', 'design_system', 'offered', 'scope', 'subject', 'groups', 'favorite'] as $key) {
      $this->assertSame($before[$key], $served[$key], "The $key is the entity's.");
    }
    $this->assertSame(['heroes', 'bands'], $served['groups'], 'The groups round-trip entity to file to entry.');
    $this->assertTrue($served['favorite'], 'The favorite round-trips entity to file to entry.');
    $this->assertSame($this->withoutFieldRef(BlueprintLayout::withoutUuids($before['layout'])), $served['layout']);
    $this->assertSame(['entity:' => ['default' => ['margin' => '1rem']]], $served['styles']);
    $this->assertSame(array_values(array_diff(array_keys($before), ['origin', 'entity_id'])), array_keys($served), 'One entry shape across both lanes; only the content lane carries an origin and a page id.');
  }

  /**
   * Graduation: the blueprint becomes the file, variants adopt in place.
   *
   * The site is consistent afterwards. The roster: card_left is owned but
   * unworn (ownership is curation, it ships), card_right is owned and worn
   * by the root, free_look is unowned and worn (ships unowned, said out
   * loud), elsewhere_look is another module's shipped variant the document
   * wears (skipped, with the info.yml warning), and rival_look belongs to
   * another blueprint (skipped).
   */
  public function testGraduation(): void {
    $left = $this->createVariant('card_left', ['description' => 'The card on the left.']);
    $right = $this->createVariant('card_right');
    $this->createVariant('free_look');
    $elsewhere = $this->createVariant('elsewhere_look');
    $elsewhere->setShippedSource('other_module', $elsewhere->getStyles());
    $elsewhere->save();
    $this->createVariant('rival_look');
    $manager = $this->container->get('daedalus_blueprint_library.manager');
    $rival = $this->createWearing('Rival', 'rival');
    $manager->adoptVariants($rival, ['rival_look']);

    // The document: a root wearing card_right, nested containers wearing
    // the other looks (axes replace, so one look per element). The rival's
    // look cannot be applied here (the apply gate), so it is worn through
    // the map directly: the roster reads wearings, not gates.
    $blueprint = $this->createBlueprintFromTree(['label' => 'Card', 'machine_name' => 'card', 'description' => 'The Card blueprint.'], [
      $this->container(['card_right'], [
        $this->container(['free_look']),
        $this->container(['elsewhere_look']),
        $this->container(),
      ]),
    ]);
    $manager->adoptVariants($blueprint, ['card_left', 'card_right']);
    $blueprint = $this->loadBlueprint('card');
    $third = $this->builtLayout($blueprint)[0]['children'][0]['children'][2]['uuid'];
    $map = $this->host($blueprint)->styleMap();
    $map["container:$third"] = ['variants' => [['variant' => 'rival_look', 'fragment' => '']]];
    $blueprint->set('daedalus_stylish_styles', json_encode($map));
    $blueprint->save();
    $blueprint = $this->loadBlueprint('card');

    $plan = $this->shipper()->plan($blueprint, self::TARGET);
    $this->assertSame('card', $plan->id);
    $this->assertSame((int) $blueprint->id(), (int) $plan->blueprint->id());
    $this->assertSame('Card', $plan->definition['label']);
    $this->assertSame('The Card blueprint.', $plan->definition['description']);
    $this->assertSame(['label', 'description', 'design_system', 'scope', 'subject', 'layout'], array_keys($plan->definition));
    $this->assertSame([['variant' => 'card_right', 'fragment' => '']], $plan->definition['layout'][0]['styles']['']['variants']);
    $this->assertSame(['card_left', 'card_right', 'free_look'], array_keys($plan->variants));
    $this->assertSame('card', $plan->variants['card_left']['blueprint']);
    $this->assertSame('card', $plan->variants['card_right']['blueprint']);
    $this->assertArrayNotHasKey('blueprint', $plan->variants['free_look']);
    $this->assertSame(['label', 'scope', 'description', 'design_system', 'blueprint', 'styles'], array_keys($plan->variants['card_left']));
    $this->assertSame([
      'card_left' => VariantShipper::CREATED,
      'card_right' => VariantShipper::CREATED,
      'free_look' => VariantShipper::CREATED,
    ], $plan->variantOutcomes);
    // Skipped in the order found: the rival's during the walk, the other
    // module's when the variant lane reports it shipped elsewhere.
    $this->assertSame(['rival_look', 'elsewhere_look'], array_keys($plan->skipped));
    $this->assertStringContainsString('"rival" blueprint', $plan->skipped['rival_look']);
    $this->assertStringContainsString('"other_module" module', $plan->skipped['elsewhere_look']);
    $this->assertCount(2, $plan->warnings);
    $this->assertStringContainsString('"other_module" module, which ' . self::TARGET . '.info.yml does not list', $plan->warnings[0]);
    $this->assertStringContainsString('"free_look" variant ships unowned', $plan->warnings[1]);
    // The dry run touched nothing.
    $this->assertFileDoesNotExist($this->blueprintFile('card'));
    $this->assertFileDoesNotExist($this->variantsFile('card'));
    $this->assertInstanceOf(Blueprint::class, Blueprint::load($blueprint->id()));
    $this->assertNull($this->loadVariant('card_left')->getProvider());

    $shipped = $this->shipper()->ship($blueprint, self::TARGET);
    $this->assertEquals($plan->variantOutcomes, $shipped->variantOutcomes);
    $this->assertEquals($plan->skipped, $shipped->skipped);

    // Disk: the two-file package, the owned entries carrying their owner.
    $this->assertFileExists($this->blueprintFile('card'));
    $definition = Yaml::decode(file_get_contents($this->blueprintFile('card')));
    $this->assertSame($plan->definition, $definition);
    $variants = Yaml::decode(file_get_contents($this->variantsFile('card')));
    $this->assertSame(['card_left', 'card_right', 'free_look'], array_keys($variants));
    $this->assertSame('card', $variants['card_left']['blueprint']);
    $this->assertSame('The card on the left.', $variants['card_left']['description']);
    $this->assertSame('card', $variants['card_right']['blueprint']);
    $this->assertArrayNotHasKey('blueprint', $variants['free_look']);

    // Entities: adopted in place (same uuid and id), provider stamped, the
    // ownership lane flipped from the content reference to the file.
    foreach (['card_left' => $left, 'card_right' => $right] as $name => $original) {
      $adopted = $this->loadVariant($name);
      $this->assertSame($original->uuid(), $adopted->uuid());
      $this->assertSame($original->id(), $adopted->id());
      $this->assertSame(self::TARGET, $adopted->getProvider());
      $this->assertFalse($adopted->isModified());
      $this->assertTrue($adopted->isPublished());
      $this->assertTrue($adopted->get('blueprint')->isEmpty());
      $this->assertSame('card', $adopted->get('blueprint_file')->value);
      $this->assertSame('card', $manager->variantOwnershipPayload($adopted)['blueprint']);
    }
    $free = $this->loadVariant('free_look');
    $this->assertSame(self::TARGET, $free->getProvider());
    $this->assertTrue($free->get('blueprint')->isEmpty());
    $this->assertTrue($free->get('blueprint_file')->isEmpty());
    $this->assertSame('other_module', $this->loadVariant('elsewhere_look')->getProvider());
    $this->assertNull($this->loadVariant('rival_look')->getProvider());
    $this->assertSame((int) $rival->id(), (int) $this->loadVariant('rival_look')->get('blueprint')->target_id);

    // The content blueprint graduated: gone, and discovery serves the file
    // under the same id without a collision, the rival untouched.
    $this->assertNull(Blueprint::load($blueprint->id()));
    $served = $this->discovery()->getBlueprint('card');
    $this->assertSame(BlueprintDiscoveryInterface::SOURCE_MODULE, $served['source']);
    $this->assertSame(self::TARGET, $served['provider']);
    $this->assertSame($definition['layout'], $served['layout']);
    $this->assertSame(BlueprintDiscoveryInterface::SOURCE_CONTENT, $this->discovery()->getBlueprint('rival')['source']);

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
    $this->assertSame('card', $this->loadVariant('card_left')->get('blueprint_file')->value);
    $this->assertTrue($this->loadVariant('card_left')->get('blueprint')->isEmpty());

    // The id is the file's now: a new content blueprint cannot take it.
    try {
      $this->createWearing('Card again', 'card');
      $this->fail('A content blueprint under a shipped id must refuse.');
    }
    catch (\Drupal\Core\Entity\EntityStorageException $e) {
      $this->assertStringContainsString('already taken', $e->getPrevious()->getMessage());
    }
  }

  /**
   * The membership round-trips: written by the ship, served by discovery.
   *
   * A member blueprint's design system is written into the file (its owned
   * variants carry theirs through daedalus_stylish's shipper), discovery
   * and the roster serve it, under a different active system too
   * (membership is the file's fact, not the site's).
   */
  public function testDesignSystemRoundTrip(): void {
    $this->enableModules(['daedalus_blueprint_design_system_test']);
    $this->installConfig(['daedalus_blueprint', 'daedalus_blueprint_design_system_test']);
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate('aurora');
    $manager = $this->container->get('daedalus_blueprint_library.manager');
    $this->createVariant('glow_look');
    $blueprint = $this->createWearing('Glow', 'glow', ['glow_look'], ['design_system' => 'aurora']);
    $manager->adoptVariants($blueprint, ['glow_look']);
    $this->assertSame('aurora', $this->loadVariant('glow_look')->getDesignSystem());
    $blueprint = $this->loadBlueprint('glow');

    $plan = $this->shipper()->plan($blueprint, self::TARGET);
    $this->assertSame('aurora', $plan->definition['design_system']);
    $this->assertSame(['label', 'description', 'design_system', 'scope', 'subject', 'layout'], array_keys($plan->definition));
    $this->assertSame('aurora', $plan->variants['glow_look']['design_system']);

    $this->shipper()->ship($blueprint, self::TARGET);
    $written = Yaml::decode(file_get_contents($this->blueprintFile('glow')));
    $this->assertSame('aurora', $written['design_system']);
    $this->assertSame('aurora', Yaml::decode(file_get_contents($this->variantsFile('glow')))['glow_look']['design_system']);
    $served = $this->discovery()->getBlueprint('glow');
    $this->assertSame(BlueprintDiscoveryInterface::SOURCE_MODULE, $served['source']);
    $this->assertSame('aurora', $served['design_system']);
    $this->assertSame('aurora', $this->container->get('daedalus_blueprint.materials_roster_builder')->build()->get(MaterialKind::Blueprint, 'glow')->designSystem);
    $this->assertSame('aurora', $this->loadVariant('glow_look')->getDesignSystem());

    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $this->container->get(ActiveDesignSystem::SERVICE_ID)->activate('borealis');
    $this->assertSame('aurora', $this->discovery()->getBlueprint('glow')['design_system']);
  }

  /**
   * The AI opt-out: ai: false when withheld, no key when offered.
   *
   * A withheld blueprint ships `ai: false` (the key's one spelling),
   * discovery serves it resolved as offered FALSE and the roster's offered
   * view omits it while the full roster keeps it; an offered blueprint
   * ships no key, the default's one spelling on disk.
   */
  public function testAiOptOut(): void {
    $blueprint = $this->createWearing('Card', 'card', [], ['ai_offered' => FALSE]);
    $this->assertFalse($blueprint->isOffered());

    $plan = $this->shipper()->plan($blueprint, self::TARGET);
    $this->assertSame(['label', 'description', 'ai', 'design_system', 'scope', 'subject', 'layout'], array_keys($plan->definition));
    $this->assertFalse($plan->definition['ai']);
    $this->shipper()->ship($blueprint, self::TARGET);
    $written = Yaml::decode(file_get_contents($this->blueprintFile('card')));
    $this->assertSame(['label', 'description', 'ai', 'design_system', 'scope', 'subject', 'layout'], array_keys($written));
    $this->assertFalse($written['ai']);

    // Discovery serves the flag resolved; the roster keeps two views of
    // the one site: legality reads the full set, the AI the offered one.
    $served = $this->discovery()->getBlueprint('card');
    $this->assertSame(BlueprintDiscoveryInterface::SOURCE_MODULE, $served['source']);
    $this->assertFalse($served['offered']);
    $roster = $this->container->get('daedalus_blueprint.materials_roster_builder')->build();
    $this->assertFalse($roster->blueprints()['card']->offered);
    $this->assertArrayNotHasKey('card', $roster->offered()->blueprints());

    // An offered blueprint ships no key: the default has one spelling on
    // disk, its absence (the key lists pin it).
    $plan = $this->shipper()->ship($this->createWearing('Band', 'band'), self::TARGET);
    $this->assertArrayNotHasKey('ai', $plan->definition);
    $this->assertArrayNotHasKey('ai', Yaml::decode(file_get_contents($this->blueprintFile('band'))));
    $this->assertTrue($this->discovery()->getBlueprint('band')['offered']);
    // Likewise the favorite: not one ships no key, and the entry says so.
    $this->assertArrayNotHasKey('favorite', $plan->definition);
    $this->assertArrayNotHasKey('favorite', Yaml::decode(file_get_contents($this->blueprintFile('band'))));
    $this->assertFalse($this->discovery()->getBlueprint('band')['favorite']);
  }

  /**
   * The icon: copied on graduation, none otherwise.
   *
   * A blueprint's media file lands at assets/blueprints/ID.<ext> and the
   * file's icon key names it (discovery resolves it after the ship, the
   * media entity stays); a blueprint without an icon ships none; a media
   * that resolves to no file ships none with a warning; the dry run copies
   * nothing.
   */
  public function testIcon(): void {
    $media = $this->createImageMedia('Card icon', 'public://card-icon.png');
    $blueprint = $this->createWearing('Card', 'card');
    $blueprint->setIcon($media)->save();
    $blueprint = $this->loadBlueprint('card');
    $icon = $this->targetPath . '/' . BlueprintShipper::ICON_DIRECTORY . '/card.png';

    // The plan names the copy; the dry run copies nothing.
    $plan = $this->shipper()->plan($blueprint, self::TARGET);
    $this->assertSame(BlueprintShipper::ICON_COPIED, $plan->icon);
    $this->assertSame(BlueprintShipper::ICON_DIRECTORY . '/card.png', $plan->definition['icon']);
    $this->assertSame(['label', 'description', 'icon', 'design_system', 'scope', 'subject', 'layout'], array_keys($plan->definition));
    $this->assertSame('public://card-icon.png', $plan->iconSource->getFileUri());
    $this->assertSame([], $plan->warnings);
    $this->assertFileDoesNotExist($icon);
    $this->assertDirectoryDoesNotExist(dirname($icon));

    // The ship copies the file and writes the key naming it.
    $shipped = $this->shipper()->ship($blueprint, self::TARGET);
    $this->assertSame(BlueprintShipper::ICON_COPIED, $shipped->icon);
    $this->assertFileExists($icon);
    $this->assertFileEquals($this->root . '/core/misc/druplicon.png', $icon);
    $written = Yaml::decode(file_get_contents($this->blueprintFile('card')));
    $this->assertSame(BlueprintShipper::ICON_DIRECTORY . '/card.png', $written['icon']);

    // Discovery serves the file's icon resolved to the module's path; the
    // media entity is site content and stays.
    $served = $this->discovery()->getBlueprint('card');
    $this->assertSame(BlueprintDiscoveryInterface::SOURCE_MODULE, $served['source']);
    $this->assertSame('/' . $this->container->get('extension.list.module')->getPath(self::TARGET) . '/' . BlueprintShipper::ICON_DIRECTORY . '/card.png', $served['icon']);
    $this->assertNull(Blueprint::load($blueprint->id()));
    $this->assertInstanceOf(Media::class, Media::load($media->id()));

    // A blueprint without an icon ships none: no key, no file, no warning.
    $plan = $this->shipper()->ship($this->createWearing('Band', 'band'), self::TARGET);
    $this->assertSame(BlueprintShipper::ICON_NONE, $plan->icon);
    $this->assertNull($plan->iconSource);
    $this->assertArrayNotHasKey('icon', $plan->definition);
    $this->assertSame([], $plan->warnings);
    $this->assertArrayNotHasKey('icon', Yaml::decode(file_get_contents($this->blueprintFile('band'))));
    $this->assertNull($this->discovery()->getBlueprint('band')['icon']);

    // A media that resolves to no file ships none, and says so.
    $empty = Media::create(['bundle' => $this->createMediaType('file')->id(), 'name' => 'Empty document']);
    $empty->save();
    $promo = $this->createWearing('Promo', 'promo');
    $promo->setIcon($empty)->save();
    $plan = $this->shipper()->ship($this->loadBlueprint('promo'), self::TARGET);
    $this->assertSame(BlueprintShipper::ICON_NONE, $plan->icon);
    $this->assertArrayNotHasKey('icon', $plan->definition);
    $this->assertCount(1, $plan->warnings);
    $this->assertStringContainsString('icon media "Empty document" (' . $empty->id() . ') references no file', $plan->warnings[0]);
    $this->assertArrayNotHasKey('icon', Yaml::decode(file_get_contents($this->blueprintFile('promo'))));
    $this->assertSame([$icon], glob($this->targetPath . '/' . BlueprintShipper::ICON_DIRECTORY . '/*'));
    $this->assertInstanceOf(Media::class, Media::load($empty->id()));
  }

  /**
   * The command: the run ships behind the confirmation, refusals exit non-zero.
   *
   * A file blueprint is refused as everywhere (read-only on the site, the
   * manager's refusal), an unknown reference is a not-found, and a
   * successful run prints the plan's lines and the success line.
   */
  public function testCommand(): void {
    $this->createWearing('Card', 'card');
    $tester = $this->tester();
    $this->assertSame(Command::SUCCESS, $tester->execute(['blueprint' => 'card', 'module' => self::TARGET, '--label' => 'Card v2'], ['interactive' => FALSE]));
    $display = $tester->getDisplay();
    $this->assertStringContainsString(self::TARGET . ': blueprint card created', $display);
    $this->assertStringContainsString(self::TARGET . ': icon card none', $display);
    $this->assertStringContainsString('Shipped the "card" blueprint to', $display);
    $this->assertSame('Card v2', Yaml::decode(file_get_contents($this->blueprintFile('card')))['label']);
    $this->assertSame([], Blueprint::loadMultiple(), 'The content blueprint graduated.');

    // The shipped file is read-only: the manager's refusal, not a ship.
    $tester = $this->tester();
    $this->assertSame(Command::FAILURE, $tester->execute(['blueprint' => 'card', 'module' => self::TARGET], ['interactive' => FALSE]));
    $this->assertStringContainsString('[BLUEPRINT_READ_ONLY]', $tester->getDisplay());

    $tester = $this->tester();
    $this->assertSame(Command::FAILURE, $tester->execute(['blueprint' => 'nope', 'module' => self::TARGET], ['interactive' => FALSE]));
    $this->assertStringContainsString('[BLUEPRINT_NOT_FOUND]', $tester->getDisplay());
  }

}
