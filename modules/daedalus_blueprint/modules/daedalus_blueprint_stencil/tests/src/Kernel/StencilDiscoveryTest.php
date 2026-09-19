<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_stencil\Kernel;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint_stencil\Entity\Stencil;
use Drupal\daedalus_blueprint_stencil\Exception\StencilDefinitionException;
use Drupal\daedalus_blueprint_stencil\StencilDiscovery;
use Drupal\daedalus_blueprint_stencil\StencilDiscoveryInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests stencil discovery: per-file YAML, grammar validation, caching.
 *
 * Stencils are the file-is-the-palette-entry lane: discovery reads one
 * stencil per MODULE.daedalus_blueprint.stencils.STENCIL_ID.yml, enforces the
 * envelope grammar loudly at discovery time (so a broken shipped file fails
 * at cache rebuild, not first placement), rejects cross-module ID collisions
 * (stencil IDs are site-wide vocabulary), and caches the parsed set behind
 * the daedalus_blueprint_stencils tag. The element VALUES are deliberately
 * not validated here; the spine validates them when the instantiate
 * operation stamps a copy.
 */
#[CoversClass(StencilDiscovery::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class StencilDiscoveryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    // The stencil entity's icon field references media, so installing the
    // stencil schema needs the media entity type (and its field types).
    'field',
    'file',
    'image',
    'media',
    'daedalus_blueprint',
    'daedalus_blueprint_stencil',
    'daedalus_blueprint_stencil_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Discovery merges stencil content entities over the file set, so it
    // queries stencil storage on every rebuild even when no content exists.
    $this->installEntitySchema('user');
    $this->installEntitySchema('stencil');
  }

  /**
   * The discovery service, resolved late (enableModules rebuilds containers).
   */
  protected function discovery(): StencilDiscovery {
    return \Drupal::service('daedalus_blueprint_stencil.discovery');
  }

  /**
   * Tests per-file discovery, point lookup, and result caching.
   */
  public function testDiscoveryGetStencilAndCaching(): void {
    // The fixture ships two stencils, one file each; the filename suffix is
    // the ID and the provider is injected alongside the authored keys.
    $stencils = $this->discovery()->getStencils();
    $this->assertSame(['call_to_action', 'hero'], array_keys($stencils));

    $cta = $stencils['call_to_action'];
    $this->assertSame('Call to Action', $cta['label']);
    $this->assertSame('Two-column card: heading with link beside an image.', $cta['description']);
    $this->assertSame('daedalus_blueprint_stencil_test', $cta['provider']);
    $this->assertSame(StencilDiscovery::SOURCE_MODULE, $cta['source']);
    // No authored icon: served as NULL, the uniform "no icon" spelling.
    $this->assertNull($cta['icon']);
    // The element subtree arrives exactly as authored: a section-rooted
    // node with element-relative style keys, no UUIDs, pinned fields intact.
    $section = $cta['element'];
    $this->assertSame('layout_twocol_section', $section['layout_id']);
    $this->assertSame('Call to Action', $section['label']);
    $this->assertSame('flex', $section['styles']['']['default']['display']);
    $this->assertSame('h2', $section['children'][0]['children'][0]['block_fields']['field_text_tag']);
    $this->assertSame('image', $section['children'][1]['children'][0]['block_type']);

    // Point lookup returns one definition by ID and NULL for an unknown ID.
    $hero = $this->discovery()->getStencil('hero');
    $this->assertIsArray($hero);
    $this->assertSame('Hero', $hero['label']);
    // The authored module-relative icon path is served resolved into the
    // icon value grammar's path spelling: root-relative.
    $this->assertSame('/' . \Drupal::service('extension.list.module')->getPath('daedalus_blueprint_stencil_test') . '/assets/hero.svg', $hero['icon']);
    $this->assertNull($this->discovery()->getStencil('does_not_exist'));

    // The parsed set is served from cache: a poisoned cache entry is returned
    // verbatim, proving no re-scan happens per call.
    $poisoned = [
      'poisoned' => [
        'label' => 'Poisoned',
        'description' => 'x',
        'element' => ['kind' => 'block', 'block_type' => 'text'],
        'provider' => 'test',
      ],
    ];
    \Drupal::cache()->set('daedalus_blueprint_stencil:stencils', $poisoned, CacheBackendInterface::CACHE_PERMANENT, [StencilDiscovery::CACHE_TAG]);
    $this->assertSame($poisoned, $this->discovery()->getStencils());

    // Invalidating the stencils cache tag forces a re-scan of the files.
    \Drupal::service('cache_tags.invalidator')->invalidateTags([StencilDiscovery::CACHE_TAG]);
    $this->assertSame(['call_to_action', 'hero'], array_keys($this->discovery()->getStencils()));
  }

  /**
   * A grammar-violating shipped file fails discovery loudly.
   */
  public function testBrokenStencilFileFailsLoudly(): void {
    $this->enableModules(['daedalus_blueprint_stencil_broken_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([StencilDiscovery::CACHE_TAG]);

    try {
      $this->discovery()->getStencils();
      $this->fail('A stencil file missing the element key must fail discovery.');
    }
    catch (StencilDefinitionException $e) {
      $this->assertSame('daedalus_blueprint_stencil_broken_test', $e->getProvider());
      $this->assertSame('broken', $e->getStencilId());
      $this->assertSame('INVALID_STENCIL', $e->getErrors()[0]->getCode());
      $this->assertStringContainsString('element', $e->getMessage());
    }
  }

  /**
   * The AI opt-out: served resolved under `offered`, one disk spelling.
   *
   * A file without the key is offered (the default every material has),
   * a content stencil serves its own flag, and a file spelling the
   * default (`ai: true`) is refused at discovery like every other grammar
   * violation, so the default never grows a second spelling on disk.
   */
  public function testAiOptOut(): void {
    $this->assertTrue($this->discovery()->getStencil('hero')['offered'], 'An absent ai key is the default: offered.');

    $withheld = Stencil::create(['label' => 'Palette only', 'description' => 'Kept for people.']);
    $withheld->setElement(['kind' => 'block', 'block_type' => 'text'])->setOffered(FALSE)->save();
    $served = $this->discovery()->getStencil('palette_only');
    $this->assertFalse($served['offered']);
    $this->assertSame(StencilDiscoveryInterface::SOURCE_CONTENT, $served['source']);
    $this->assertArrayNotHasKey('ai', $served, 'The disk key never reaches a served definition; offered is the one spelling.');

    $this->enableModules(['daedalus_blueprint_stencil_ai_shape_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([StencilDiscovery::CACHE_TAG]);
    try {
      $this->discovery()->getStencils();
      $this->fail('A stencil file spelling ai: true must fail discovery.');
    }
    catch (StencilDefinitionException $e) {
      $this->assertSame('daedalus_blueprint_stencil_ai_shape_test', $e->getProvider());
      $this->assertSame('loud_band', $e->getStencilId());
      $this->assertSame('INVALID_STENCIL', $e->getErrors()[0]->getCode());
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
    $this->enableModules(['daedalus_blueprint_stencil_icon_shape_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([StencilDiscovery::CACHE_TAG]);

    try {
      $this->discovery()->getStencils();
      $this->fail('A stencil file with a traversal icon path must fail discovery.');
    }
    catch (StencilDefinitionException $e) {
      $this->assertSame('daedalus_blueprint_stencil_icon_shape_test', $e->getProvider());
      $this->assertSame('bad_icon_shape', $e->getStencilId());
      $this->assertSame('INVALID_STENCIL', $e->getErrors()[0]->getCode());
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
    $this->enableModules(['daedalus_blueprint_stencil_icon_file_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([StencilDiscovery::CACHE_TAG]);

    try {
      $this->discovery()->getStencils();
      $this->fail('A stencil file naming a nonexistent icon file must fail discovery.');
    }
    catch (StencilDefinitionException $e) {
      $this->assertSame('daedalus_blueprint_stencil_icon_file_test', $e->getProvider());
      $this->assertSame('dangling_icon_file', $e->getStencilId());
      $this->assertSame('INVALID_STENCIL', $e->getErrors()[0]->getCode());
      $this->assertStringContainsString('does not exist', $e->getMessage());
    }
  }

  /**
   * Membership follows the one disk-lane rule; content stencils carry theirs.
   *
   * A file stencil's design_system is the shipping module's packaged
   * system unless the file says otherwise (an explicit id names one, `~`
   * opts out); a module that packages nothing ships global stencils. The
   * rule answers from disk: a package's members belong to its system in
   * the window before its install config lands (a recipe installs the
   * module first and imports its config after), and the landing changes
   * no answer. The served definition carries the RESOLVED membership for
   * both sources, and the parsed set carries the design-system list tag
   * so a site-built system's deletion refuses a cached member on the
   * next read.
   */
  public function testDesignSystemMembership(): void {
    // The package's files before its system lands: the absent-key and the
    // explicit-key members already belong to aurora, the opted-out file
    // and the packageless module's files are global, and the cache entry
    // carries the design-system list tag.
    $this->enableModules(['daedalus_blueprint_design_system_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([StencilDiscovery::CACHE_TAG]);
    $this->assertNull(DesignSystem::load('aurora'), 'The window: the package is installed, its system has not landed.');
    $stencils = $this->discovery()->getStencils();
    $this->assertEqualsCanonicalizing(['aurora_band', 'aurora_named_band', 'call_to_action', 'hero', 'plain_band'], array_keys($stencils));
    $this->assertSame('aurora', $stencils['aurora_band']['design_system']);
    $this->assertSame('aurora', $stencils['aurora_named_band']['design_system']);
    $this->assertNull($stencils['plain_band']['design_system']);
    $this->assertNull($stencils['hero']['design_system']);
    $this->assertContains('config:design_system_list', \Drupal::cache()->get('daedalus_blueprint_stencil:stencils')->tags);

    // The system landing changes no answer.
    $this->installConfig(['daedalus_blueprint', 'daedalus_blueprint_design_system_test']);
    $this->assertInstanceOf(DesignSystem::class, DesignSystem::load('aurora'));
    $stencils = $this->discovery()->getStencils();
    $this->assertSame('aurora', $stencils['aurora_band']['design_system']);
    $this->assertSame('aurora', $stencils['aurora_named_band']['design_system']);
    $this->assertNull($stencils['plain_band']['design_system']);
    $this->assertNull($stencils['hero']['design_system']);
    $this->assertEqualsCanonicalizing([
      'label',
      'description',
      'icon',
      'design_system',
      'offered',
      'element',
      'provider',
      'source',
    ], array_keys($stencils['hero']), 'Every file definition carries the resolved membership and offered keys.');

    // A content stencil serves its own field; a dangling id cannot be saved.
    $member = Stencil::create([
      'label' => 'Aurora quote',
      'machine_name' => 'aurora_quote',
      'description' => 'A quote.',
      'design_system' => 'aurora',
      'status' => 1,
    ]);
    $member->setElement(['kind' => 'block', 'block_type' => 'text']);
    $member->save();
    $this->assertSame('aurora', $this->discovery()->getStencil('aurora_quote')['design_system']);
    $stray = Stencil::create(['label' => 'Stray', 'description' => 'A quote.', 'design_system' => 'nowhere']);
    $stray->setElement(['kind' => 'block', 'block_type' => 'text']);
    try {
      $stray->save();
      $this->fail('A content stencil naming no design system must not save.');
    }
    catch (EntityStorageException $e) {
      $this->assertStringContainsString('"nowhere" is not a design system', $e->getMessage());
    }
  }

  /**
   * A file naming a design system the site does not have fails loudly.
   */
  public function testUnknownDesignSystemFailsLoudly(): void {
    $this->enableModules(['daedalus_blueprint_stencil_design_system_unknown_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([StencilDiscovery::CACHE_TAG]);

    try {
      $this->discovery()->getStencils();
      $this->fail('A stencil file naming an unknown design system must fail discovery.');
    }
    catch (StencilDefinitionException $e) {
      $this->assertSame('daedalus_blueprint_stencil_design_system_unknown_test', $e->getProvider());
      $this->assertSame('stray_band', $e->getStencilId());
      $error = $e->getErrors()[0];
      $this->assertSame('STENCIL_DESIGN_SYSTEM_UNKNOWN', $error->getCode());
      $this->assertSame([], $error->getContext()['design_systems']);
      $this->assertStringContainsString('"nowhere"', $e->getMessage());
    }
  }

  /**
   * Two enabled modules shipping the same stencil ID is a loud conflict.
   */
  public function testStencilIdCollisionFailsLoudly(): void {
    $this->enableModules(['daedalus_blueprint_stencil_collision_test']);
    \Drupal::service('cache_tags.invalidator')->invalidateTags([StencilDiscovery::CACHE_TAG]);

    try {
      $this->discovery()->getStencils();
      $this->fail('Two modules shipping the same stencil ID must fail discovery.');
    }
    catch (StencilDefinitionException $e) {
      $this->assertSame('call_to_action', $e->getStencilId());
      $error = $e->getErrors()[0];
      $this->assertSame('STENCIL_CONFLICT', $error->getCode());
      $providers = $error->getContext()['providers'];
      sort($providers);
      $this->assertSame(['daedalus_blueprint_stencil_collision_test', 'daedalus_blueprint_stencil_test'], $providers);
    }
  }

}
