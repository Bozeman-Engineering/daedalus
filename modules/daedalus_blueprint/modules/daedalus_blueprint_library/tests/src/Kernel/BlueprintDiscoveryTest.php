<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_library\Kernel;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint_library\BlueprintDiscovery;
use Drupal\daedalus_blueprint_library\BlueprintDiscoveryInterface;
use Drupal\daedalus_blueprint_library\Exception\BlueprintDefinitionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests blueprint discovery: per-file YAML, envelope validation, caching.
 *
 * Blueprint files are the file-is-the-palette-entry lane: discovery reads
 * one blueprint per MODULE.daedalus_blueprint.blueprints.ID.yml, enforces
 * the envelope grammar loudly at discovery time (so a broken shipped file
 * fails at cache rebuild, not first placement: a missing key, a wrong
 * scope or subject, a layout root that is not a container), rejects
 * cross-module ID collisions (blueprint IDs are site-wide vocabulary), and
 * caches the parsed set behind the daedalus_blueprint_library tag. The
 * layout VALUES are deliberately not validated here; the spine validates
 * them when a placement instantiates a copy.
 */
#[CoversClass(BlueprintDiscovery::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class BlueprintDiscoveryTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['daedalus_blueprint_library_test'];

  /**
   * The discovery service, resolved late (enableModules rebuilds containers).
   */
  protected function discovery(): BlueprintDiscovery {
    return \Drupal::service('daedalus_blueprint_library.discovery');
  }

  /**
   * Tests per-file discovery, point lookup, and result caching.
   */
  public function testDiscoveryGetBlueprintAndCaching(): void {
    // The fixture ships two blueprints, one file each; the filename suffix
    // is the ID and the provider is injected alongside the authored keys.
    $blueprints = $this->discovery()->getBlueprints();
    $this->assertSame(['call_to_action', 'hero'], array_keys($blueprints));

    $cta = $blueprints['call_to_action'];
    $this->assertSame('Call to Action', $cta['label']);
    $this->assertSame('Two-column card: heading with link beside an image.', $cta['description']);
    $this->assertSame('daedalus_blueprint_library_test', $cta['provider']);
    $this->assertSame(BlueprintDiscovery::SOURCE_MODULE, $cta['source']);
    $this->assertSame('block', $cta['scope']);
    $this->assertSame('entity_test:entity_test', $cta['subject']);
    $this->assertSame([], $cta['styles'], 'An absent styles key is served as the empty node-less map.');
    $this->assertSame([], $cta['groups'], 'An absent groups key is served as no group, the one entry shape.');
    $this->assertFalse($cta['favorite'], 'An absent favorite key is served as not a favorite, the one entry shape.');
    $this->assertSame('section', $cta['type'], 'An absent type key is served as the section type, the one entry shape.');
    // No authored icon: served as NULL, the uniform "no icon" spelling.
    $this->assertNull($cta['icon']);
    // The layout arrives as authored: a list of one container root with
    // element-relative style keys, no UUIDs, pinned fields intact.
    $this->assertCount(1, $cta['layout']);
    $section = $cta['layout'][0];
    $this->assertSame('container', $section['kind']);
    $this->assertSame('layout_twocol_section', $section['layout_id']);
    $this->assertSame('Call to Action', $section['label']);
    $this->assertSame('flex', $section['styles']['']['default']['display']);
    $this->assertSame('h2', $section['children'][0]['children'][0]['block_fields']['field_text_tag']);
    $this->assertSame('image', $section['children'][1]['children'][0]['block_type']);

    // Point lookup returns one definition by ID and NULL for an unknown ID.
    $hero = $this->discovery()->getBlueprint('hero');
    $this->assertIsArray($hero);
    $this->assertSame('Hero', $hero['label']);
    // The authored module-relative icon path is served resolved into the
    // icon value grammar's path spelling: root-relative.
    $this->assertSame('/' . \Drupal::service('extension.list.module')->getPath('daedalus_blueprint_library_test') . '/assets/hero.svg', $hero['icon']);
    // The groups ride the entry as authored (filter categories; the
    // surface that filters resolves them against the site's vocabulary).
    $this->assertSame(['heroes', 'openers'], $hero['groups']);
    $this->assertTrue($hero['favorite'], 'The file\'s favorite: true rides the entry.');
    $this->assertNull($this->discovery()->getBlueprint('does_not_exist'));

    // The parsed set is served from cache: a poisoned cache entry is returned
    // verbatim, proving no re-scan happens per call.
    $poisoned = [
      'poisoned' => [
        'label' => 'Poisoned',
        'description' => 'x',
        'layout' => [['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []]],
        'provider' => 'test',
      ],
    ];
    \Drupal::cache()->set('daedalus_blueprint_library:blueprints', $poisoned, CacheBackendInterface::CACHE_PERMANENT, [BlueprintDiscovery::CACHE_TAG]);
    $this->assertSame($poisoned, $this->discovery()->getBlueprints());

    // Invalidating the vocabulary tag forces a re-scan of the files.
    \Drupal::service('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);
    $this->assertSame(['call_to_action', 'hero'], array_keys($this->discovery()->getBlueprints()));
  }

  /**
   * A file's bare variant ids are served in the tree's reference shape.
   *
   * The instantiate fixtures author `variants: [card]`, the sugar the
   * instantiator also reads; the served layout carries {variant: card},
   * the one grammar every reader of a definition walks (the printer, the
   * roster's wearings).
   */
  public function testVariantReferencesNormalized(): void {
    $this->enableModules(['daedalus_blueprint_library_instantiate_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);
    $cta = $this->discovery()->getBlueprint('cta');
    $this->assertSame([['variant' => 'card']], $cta['layout'][0]['styles']['']['variants']);
    $this->assertSame([['variant' => 'panel']], $cta['layout'][0]['children'][1]['children'][1]['styles']['']['variants'], 'Nested nodes normalize too.');
    $this->assertSame('aliceblue', $cta['layout'][0]['styles']['']['default']['background-color'], 'Nothing else changes.');
  }

  /**
   * A grammar-violating shipped file fails discovery loudly.
   */
  public function testBrokenBlueprintFileFailsLoudly(): void {
    $this->enableModules(['daedalus_blueprint_library_broken_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);

    try {
      $this->discovery()->getBlueprints();
      $this->fail('A blueprint file missing the layout key must fail discovery.');
    }
    catch (BlueprintDefinitionException $e) {
      $this->assertSame('daedalus_blueprint_library_broken_test', $e->getProvider());
      $this->assertSame('broken', $e->getBlueprintId());
      $this->assertSame('INVALID_BLUEPRINT', $e->getErrors()[0]->getCode());
      $this->assertStringContainsString('layout', $e->getMessage());
    }
  }

  /**
   * A shipped file named for a reserved id fails discovery loudly.
   *
   * `blank` and `template` are the starts that are not blueprints, named
   * in the same slot as a page blueprint's id, so a file whose id is one
   * of them is refused before its envelope is read, whatever it holds
   * (the fixture is otherwise valid); the content lane refuses the same
   * ids at presave (BlueprintEntityTest).
   */
  public function testReservedIdFailsLoudly(): void {
    $this->enableModules(['daedalus_blueprint_library_reserved_id_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);

    try {
      $this->discovery()->getBlueprints();
      $this->fail('A blueprint file named for a reserved id must fail discovery.');
    }
    catch (BlueprintDefinitionException $e) {
      $this->assertSame('daedalus_blueprint_library_reserved_id_test', $e->getProvider());
      $this->assertSame('blank', $e->getBlueprintId());
      $error = $e->getErrors()[0];
      $this->assertSame('INVALID_BLUEPRINT', $error->getCode());
      $this->assertSame('blank', $error->getPath());
      $this->assertSame('The blueprint ID "blank" is reserved.', $error->getMessage());
      $this->assertSame(['reserved' => ['blank', 'template']], $error->getContext());
      $this->assertStringContainsString('"blank" and "template" name the starts that are not blueprints', $error->getHint());
    }

    // The other reserved id, through the shape check the files run.
    $check = new \ReflectionMethod(BlueprintDiscovery::class, 'checkDefinitionShape');
    $directory = \Drupal::service('extension.list.module')->getPath('daedalus_blueprint_library_test');
    $valid = [
      'label' => 'Template',
      'description' => 'Valid otherwise.',
      'scope' => 'page',
      'subject' => 'entity_test:entity_test',
      'layout' => [['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []]],
    ];
    try {
      $check->invoke($this->discovery(), 'daedalus_blueprint_library_test', 'template', $valid, $directory);
      $this->fail('The id "template" must be refused.');
    }
    catch (BlueprintDefinitionException $e) {
      $this->assertSame('template', $e->getBlueprintId());
      $this->assertSame('INVALID_BLUEPRINT', $e->getErrors()[0]->getCode());
      $this->assertSame('The blueprint ID "template" is reserved.', $e->getErrors()[0]->getMessage());
    }
  }

  /**
   * A layout root that is not a container is an envelope error.
   *
   * Roots are always containers (a placement lands a container; a block
   * is captured with the container it sits in), so a shipped file whose
   * root is a block node is a developer error caught at discovery, never
   * a blueprint that quietly places as a block.
   */
  public function testBlockRootFailsLoudly(): void {
    $this->enableModules(['daedalus_blueprint_library_block_root_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);

    try {
      $this->discovery()->getBlueprints();
      $this->fail('A blueprint file whose layout root is a block must fail discovery.');
    }
    catch (BlueprintDefinitionException $e) {
      $this->assertSame('daedalus_blueprint_library_block_root_test', $e->getProvider());
      $this->assertSame('bare_block', $e->getBlueprintId());
      $error = $e->getErrors()[0];
      $this->assertSame('INVALID_BLUEPRINT', $error->getCode());
      $this->assertSame('bare_block.layout.0', $error->getPath());
      $this->assertStringContainsString('is not a container node', $e->getMessage());
      $this->assertStringContainsString('Roots are always containers', $error->getHint());
    }
  }

  /**
   * Every envelope rule refuses with the key it names.
   *
   * The on-disk fixtures cover one violation each; the rest of the grammar
   * (scope, subject, the layout list, the one-root rule of block scope,
   * the node-less styles map, unknown keys) is driven through the shape
   * check directly, so each rule is pinned without a fixture module apiece.
   */
  public function testEnvelopeRules(): void {
    $container = ['kind' => 'container', 'layout_id' => 'layout_onecol', 'children' => []];
    $valid = [
      'label' => 'Valid',
      'description' => 'A valid envelope.',
      'scope' => 'block',
      'subject' => 'entity_test:entity_test',
      'layout' => [$container],
    ];
    $check = new \ReflectionMethod(BlueprintDiscovery::class, 'checkDefinitionShape');
    $directory = \Drupal::service('extension.list.module')->getPath('daedalus_blueprint_library_test');
    $check->invoke($this->discovery(), 'daedalus_blueprint_library_test', 'valid', $valid, $directory);
    $check->invoke($this->discovery(), 'daedalus_blueprint_library_test', 'valid', ['scope' => 'page', 'layout' => [$container, $container], 'styles' => ['entity:' => ['default' => ['color' => 'red']]]] + $valid, $directory);
    $check->invoke($this->discovery(), 'daedalus_blueprint_library_test', 'valid', ['groups' => ['heroes', 'openers']] + $valid, $directory);
    $favorite = ['favorite' => TRUE] + $valid;
    $check->invoke($this->discovery(), 'daedalus_blueprint_library_test', 'valid', $favorite, $directory);

    foreach ([
      'invalid scope' => ['scope' => 'section'],
      'invalid subject' => ['subject' => 'page'],
      'is not a list of root nodes' => ['layout' => $container],
      'is not a container node' => ['layout' => [$container, ['kind' => 'block', 'block_type' => 'text']]],
      'has scope block and 2 layout roots' => ['layout' => [$container, $container]],
      'styles is not a map' => ['styles' => [['default' => []]]],
      'carries the node key "container:abc"' => ['styles' => ['container:abc' => ['default' => ['color' => 'red']]]],
      'is not a layered map' => ['styles' => ['entity:' => []]],
      'Unknown key(s) "element"' => ['element' => $container],
      'missing the "subject" key' => ['subject' => NULL],
      // groups: a non-empty list of distinct machine names, or absent (the
      // one spelling of "no group").
      'invalid groups list' => ['groups' => 'heroes'],
      'has an invalid groups list' => ['groups' => []],
      'blueprint file has an invalid groups list' => ['groups' => ['Heroes!']],
      '"bad" blueprint file has an invalid groups list' => ['groups' => ['heroes', 'heroes']],
      // favorite: true, or absent (the one spelling of "not a favorite",
      // as ai: true is refused for the opt-out's default).
      'has an invalid favorite key' => ['favorite' => FALSE],
      'blueprint file has an invalid favorite key' => ['favorite' => 'yes'],
    ] as $message => $override) {
      $definition = array_filter($override + $valid, static fn ($value): bool => $value !== NULL);
      try {
        $check->invoke($this->discovery(), 'daedalus_blueprint_library_test', 'bad', $definition, $directory);
        $this->fail("The envelope must refuse: $message");
      }
      catch (BlueprintDefinitionException $e) {
        $this->assertSame('INVALID_BLUEPRINT', $e->getErrors()[0]->getCode(), $message);
        $this->assertStringContainsString($message, $e->getMessage());
      }
    }
  }

  /**
   * The AI opt-out: served resolved under `offered`, one disk spelling.
   *
   * A file without the key is offered (the default every material has),
   * a content blueprint serves its own flag, and a file spelling the
   * default (`ai: true`) is refused at discovery like every other grammar
   * violation, so the default never grows a second spelling on disk.
   */
  public function testAiOptOut(): void {
    $this->assertTrue($this->discovery()->getBlueprint('hero')['offered'], 'An absent ai key is the default: offered.');

    $this->createBlueprint(['label' => 'Palette only', 'description' => 'Kept for people.', 'ai_offered' => 0]);
    $served = $this->discovery()->getBlueprint('palette_only');
    $this->assertFalse($served['offered']);
    $this->assertSame(BlueprintDiscoveryInterface::SOURCE_CONTENT, $served['source']);
    $this->assertArrayNotHasKey('ai', $served, 'The disk key never reaches a served definition; offered is the one spelling.');
    $this->assertFalse($served['favorite'], 'A content blueprint serves its own flag: off by default.');

    // The site favorite rides the same way: a content blueprint's field
    // serves resolved, one spelling across both lanes (the file lane's
    // hero fixture says favorite: true).
    $this->createBlueprint(['label' => 'Starred', 'description' => 'A favorite.', 'favorite' => TRUE]);
    $this->assertTrue($this->discovery()->getBlueprint('starred')['favorite']);

    $this->enableModules(['daedalus_blueprint_library_ai_shape_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);
    try {
      $this->discovery()->getBlueprints();
      $this->fail('A blueprint file spelling ai: true must fail discovery.');
    }
    catch (BlueprintDefinitionException $e) {
      $this->assertSame('daedalus_blueprint_library_ai_shape_test', $e->getProvider());
      $this->assertSame('loud_band', $e->getBlueprintId());
      $this->assertSame('INVALID_BLUEPRINT', $e->getErrors()[0]->getCode());
      $this->assertStringContainsString('invalid ai key', $e->getMessage());
    }
  }

  /**
   * A grammar-violating icon path (traversal) fails discovery loudly.
   *
   * The YAML lane's icon is a module-relative path; absolute paths,
   * schemes, and traversal are developer errors in the shipping module,
   * refused at discovery so they surface at cache rebuild, never as a
   * broken palette image.
   */
  public function testInvalidIconShapeFailsLoudly(): void {
    $this->enableModules(['daedalus_blueprint_library_icon_shape_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);

    try {
      $this->discovery()->getBlueprints();
      $this->fail('A blueprint file with a traversal icon path must fail discovery.');
    }
    catch (BlueprintDefinitionException $e) {
      $this->assertSame('daedalus_blueprint_library_icon_shape_test', $e->getProvider());
      $this->assertSame('bad_icon_shape', $e->getBlueprintId());
      $this->assertSame('INVALID_BLUEPRINT', $e->getErrors()[0]->getCode());
      $this->assertStringContainsString('icon', $e->getMessage());
    }
  }

  /**
   * An icon file the module does not ship fails discovery loudly.
   *
   * The deliberate asymmetry of the two icon lanes: a dangling MEDIA icon
   * degrades at render (site content comes and goes), but a dangling FILE
   * icon is a developer error and discovery existence-checks it.
   */
  public function testMissingIconFileFailsLoudly(): void {
    $this->enableModules(['daedalus_blueprint_library_icon_file_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);

    try {
      $this->discovery()->getBlueprints();
      $this->fail('A blueprint file naming a nonexistent icon file must fail discovery.');
    }
    catch (BlueprintDefinitionException $e) {
      $this->assertSame('daedalus_blueprint_library_icon_file_test', $e->getProvider());
      $this->assertSame('dangling_icon_file', $e->getBlueprintId());
      $this->assertSame('INVALID_BLUEPRINT', $e->getErrors()[0]->getCode());
      $this->assertStringContainsString('does not exist', $e->getMessage());
    }
  }

  /**
   * Membership follows the one disk-lane rule; content blueprints carry theirs.
   *
   * A file blueprint's design_system is the shipping module's packaged
   * system unless the file says otherwise (an explicit id names one, `~`
   * opts out); a module that packages nothing ships global blueprints. The
   * rule answers from disk: a package's members belong to its system in
   * the window before its install config lands (a recipe installs the
   * module first and imports its config after), and the landing changes
   * no answer. The served definition carries the RESOLVED membership for
   * both sources, beside the offered flag, the type and the type's
   * frame-only rule (resolved from the type entities once per rebuild),
   * and the parsed set carries the design-system list tag so a
   * site-built system's deletion refuses a cached member on the next
   * read.
   *
   * The file definition's key list asserted here and the served content
   * entry's in BlueprintDiscoveryMergeTest are the discovery entry's two
   * exhaustive pins. Any key the payload gains is owed to both, and a
   * task that adds one names both classes in its verify.
   */
  public function testDesignSystemMembership(): void {
    // The package's files before its system lands: the absent-key and the
    // explicit-key members already belong to aurora, the opted-out file
    // and the packageless module's files are global, and the cache entry
    // carries the design-system list tag.
    $this->enableModules(['daedalus_blueprint_design_system_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);
    $this->assertNull(DesignSystem::load('aurora'), 'The window: the package is installed, its system has not landed.');
    $blueprints = $this->discovery()->getBlueprints();
    $this->assertEqualsCanonicalizing(['aurora_band', 'aurora_named_band', 'call_to_action', 'hero', 'plain_band'], array_keys($blueprints));
    $this->assertSame('aurora', $blueprints['aurora_band']['design_system']);
    $this->assertSame('aurora', $blueprints['aurora_named_band']['design_system']);
    $this->assertNull($blueprints['plain_band']['design_system']);
    $this->assertNull($blueprints['hero']['design_system']);
    $this->assertContains('config:design_system_list', \Drupal::cache()->get('daedalus_blueprint_library:blueprints')->tags);

    // The system landing changes no answer.
    $this->installConfig(['daedalus_blueprint', 'daedalus_blueprint_design_system_test']);
    $this->assertInstanceOf(DesignSystem::class, DesignSystem::load('aurora'));
    $blueprints = $this->discovery()->getBlueprints();
    $this->assertSame('aurora', $blueprints['aurora_band']['design_system']);
    $this->assertSame('aurora', $blueprints['aurora_named_band']['design_system']);
    $this->assertNull($blueprints['plain_band']['design_system']);
    $this->assertNull($blueprints['hero']['design_system']);
    $this->assertSame([
      'label',
      'description',
      'icon',
      'design_system',
      'offered',
      'scope',
      'subject',
      'bundles',
      'groups',
      'favorite',
      'type',
      'frame_only',
      'layout',
      'styles',
      'provider',
      'source',
    ], array_keys($blueprints['hero']), 'Every file definition carries the resolved membership, offered, type and frame_only keys and the whole envelope.');
    $this->assertSame([], $blueprints['hero']['bundles'], 'A file carries no allow-list.');

    // A content blueprint serves its own field; a dangling id cannot be saved.
    $this->createBlueprint([
      'label' => 'Aurora quote',
      'machine_name' => 'aurora_quote',
      'description' => 'A quote.',
      'design_system' => 'aurora',
    ]);
    $this->assertSame('aurora', $this->discovery()->getBlueprint('aurora_quote')['design_system']);
    try {
      $this->createBlueprint(['label' => 'Stray', 'description' => 'A quote.', 'design_system' => 'nowhere']);
      $this->fail('A content blueprint naming no design system must not save.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('"nowhere" is not a design system', $e->getMessage());
    }
  }

  /**
   * A file naming a design system the site does not have fails loudly.
   */
  public function testUnknownDesignSystemFailsLoudly(): void {
    $this->enableModules(['daedalus_blueprint_library_design_system_unknown_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);

    try {
      $this->discovery()->getBlueprints();
      $this->fail('A blueprint file naming an unknown design system must fail discovery.');
    }
    catch (BlueprintDefinitionException $e) {
      $this->assertSame('daedalus_blueprint_library_design_system_unknown_test', $e->getProvider());
      $this->assertSame('stray_band', $e->getBlueprintId());
      $error = $e->getErrors()[0];
      $this->assertSame('BLUEPRINT_DESIGN_SYSTEM_UNKNOWN', $error->getCode());
      $this->assertSame([], $error->getContext()['design_systems']);
      $this->assertStringContainsString('"nowhere"', $e->getMessage());
    }
  }

  /**
   * Two enabled modules shipping the same blueprint ID is a loud conflict.
   */
  public function testBlueprintIdCollisionFailsLoudly(): void {
    $this->enableModules(['daedalus_blueprint_library_collision_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([BlueprintDiscovery::CACHE_TAG]);

    try {
      $this->discovery()->getBlueprints();
      $this->fail('Two modules shipping the same blueprint ID must fail discovery.');
    }
    catch (BlueprintDefinitionException $e) {
      $this->assertSame('call_to_action', $e->getBlueprintId());
      $error = $e->getErrors()[0];
      $this->assertSame('BLUEPRINT_CONFLICT', $error->getCode());
      $providers = $error->getContext()['providers'];
      sort($providers);
      $this->assertSame(['daedalus_blueprint_library_collision_test', 'daedalus_blueprint_library_test'], $providers);
    }
  }

}
