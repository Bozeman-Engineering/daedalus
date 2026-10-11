<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\daedalus_stylish\Entity\DaedalusStylishVariant;
use Drupal\daedalus_stylish\VariantConformance;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests variant conformance: the offered variants judged under the law.
 *
 * A published variant's legality is a fact about (variant, current law):
 * a variant born under guarded law with a value strict law refuses is
 * withheld from the roster the moment the site turns strict, and offered
 * again when it turns back, with no write in between. The verdict judges
 * the global variants and the active system's members alike (an inactive
 * system's member is not offered either way, so it is never judged), is
 * derived inside the roster build without recursing into it, flips with
 * an enforcement edit on the active design system (the mode is the
 * system's property) in the same request, and is re-judged when a
 * variant is saved (the reset that rides the repository's). The
 * recursion guard is per flow: a judgement parked in a placeholder fiber
 * (core yields one from inside the variant load) is interleaving when
 * another fiber or the main flow asks, not recursion, and the validator's
 * universe door is the parked flow's alone.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class VariantConformanceTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * The suite's shipped block variants, global materials on every site.
   *
   * The activation in setUp syncs every enabled module, so they join the
   * fixture's own variants on the roster; nothing in this fixture binds
   * their motion literals, so they conform under either law.
   */
  protected const SUITE_BLOCK_VARIANTS = ['block_fade_in', 'block_lift', 'block_rise', 'block_scale_in'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'daedalus_blueprint',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'daedalus_stylish',
    'daedalus_stylish_control_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_skin');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_variant');
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
    $this->installConfig(['daedalus_blueprint', 'daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->setUpCurrentUser([], [], TRUE);
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
      ],
    ]);
    // The container kind grants spacing only: a typography literal on it
    // is legal under guarded law (recognition and grammar) and refused
    // under strict law (the closed world), with no write in between.
    $this->container->get('state')->set('daedalus_stylish_control_test.kinds', [
      'container' => [
        'variant_axes' => ['container_layout'],
        'property_groups' => ['spacing' => []],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();

    // The active system (aurora) with a member skin, and a second system
    // (nocturne) that stays inactive.
    foreach (['aurora', 'nocturne'] as $id) {
      DesignSystem::create(['id' => $id, 'label' => ucfirst($id), 'default_skin' => $id])->save();
      $skin = DaedalusStylishSkin::create(['label' => ucfirst($id), 'machine_name' => $id, 'design_system' => $id]);
      $skin->setPackageTokens(['space' => ['4' => '1rem']])->save();
    }
    $this->activateSkin('aurora');

    // Two conforming variants and three raw-valued ones: a global, the
    // active system's member and the inactive system's member.
    $this->createVariant('tidy', ['padding' => '1rem']);
    $this->createVariant('aurora_tidy', ['padding' => 'var(--space-4)'], 'aurora');
    $this->createVariant('raw', ['font-size' => '2rem']);
    $this->createVariant('aurora_raw', ['font-size' => '2rem'], 'aurora');
    $this->createVariant('nocturne_raw', ['font-size' => '2rem'], 'nocturne');
  }

  /**
   * Creates a published container variant wearing the given root styles.
   */
  protected function createVariant(string $name, array $styles, ?string $design_system = NULL): DaedalusStylishVariant {
    $variant = DaedalusStylishVariant::create([
      'label' => ucwords(str_replace('_', ' ', $name)),
      'machine_name' => $name,
      'scope' => 'kind:container',
      'design_system' => $design_system,
    ]);
    $variant->setStyles(['' => ['default' => $styles]])->save();
    return $variant;
  }

  /**
   * The roster as the next request would build it.
   *
   * The builder memoizes per request and is reset by the material entity
   * hooks and the design-system switch, not by an enforcement save (no
   * subscriber wiring: the persistent verdict rides the config tag, and
   * the request that saves the setting reads the roster no further). The
   * reset here is that request boundary.
   */
  protected function roster(): MaterialsRoster {
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');
    $builder->reset();
    return $builder->build();
  }

  /**
   * The offered variant names, sorted.
   *
   * @return string[]
   *   The names.
   */
  protected function offered(): array {
    $names = array_keys($this->roster()->variants());
    sort($names);
    return $names;
  }

  /**
   * The withheld names, sorted.
   *
   * @return string[]
   *   The names.
   */
  protected function withheld(): array {
    $names = array_keys($this->container->get('daedalus_stylish.variant_conformance')->withheld());
    sort($names);
    return $names;
  }

  /**
   * Makes the judge's next variant load a real one.
   *
   * The repository memoizes the published set per request and the storage
   * serves loaded entities from its static cache, where no load hook
   * fires; both are cleared so the control module's load hook sees the
   * judge's load.
   */
  protected function coldVariantLoads(): void {
    $this->container->get('daedalus_stylish.variant_repository')->reset();
    $this->container->get('entity_type.manager')->getStorage('daedalus_variant')->resetCache();
  }

  /**
   * Strict law withholds the raw-valued variants; guarded law offers them.
   *
   * The enforcement save flips the verdict within the request that made
   * it, and the global variant and the active system's member judge
   * alike; the inactive system's member is neither judged nor offered.
   */
  public function testLawChangeFlipsTheVerdict(): void {
    // Guarded (the default): every offered variant conforms.
    $this->assertSame([], $this->withheld());
    $this->assertSame(['aurora_raw', 'aurora_tidy', ...self::SUITE_BLOCK_VARIANTS, 'raw', 'tidy'], $this->offered());
    $this->assertTrue($this->roster()->has(MaterialKind::Variant, 'raw'));

    // Strict: the closed world refuses the typography literal on both the
    // global and the member, naming the property; the conforming pair
    // stays, the inactive member is not on either list.
    $this->enforce('strict');
    $this->assertSame(['aurora_raw', 'raw'], $this->withheld());
    $verdict = $this->container->get('daedalus_stylish.variant_conformance')->withheld();
    foreach (['raw', 'aurora_raw'] as $name) {
      $this->assertCount(1, $verdict[$name]);
      $this->assertSame('STYLE_PROPERTY_NOT_DECLARED', $verdict[$name][0]->getCode());
      $this->assertSame('default.font-size', $verdict[$name][0]->getPath());
    }
    $this->assertSame(['aurora_tidy', ...self::SUITE_BLOCK_VARIANTS, 'tidy'], $this->offered());
    $this->assertFalse($this->roster()->has(MaterialKind::Variant, 'raw'));
    $this->assertFalse($this->roster()->has(MaterialKind::Variant, 'nocturne_raw'));

    // Back to guarded: offered again, with no write in between.
    $this->enforce('guarded');
    $this->assertSame([], $this->withheld());
    $this->assertTrue($this->roster()->has(MaterialKind::Variant, 'raw'));

    // Open law is the same answer: nothing binds.
    $this->enforce('open');
    $this->assertSame([], $this->withheld());
  }

  /**
   * Judging inside a roster build returns the judged set, with no recursion.
   *
   * The build asks the provider, the provider asks the judge, the judge
   * validates the provider's unjudged records against themselves under
   * the validator's universe door; the roster that comes back is already
   * the judged set and the verdict is memoized and persisted for the
   * request's other readers.
   */
  public function testJudgingInsideTheBuild(): void {
    $this->enforce('strict');
    $cache = $this->container->get('cache.default');
    $this->assertFalse($cache->get(VariantConformance::CACHE_ID . '::strict:aurora'));

    // The first read is the build itself.
    $roster = $this->roster();
    $this->assertSame(['aurora_tidy', ...self::SUITE_BLOCK_VARIANTS, 'tidy'], array_keys($roster->variants()));
    $this->assertSame(['aurora_raw', 'raw'], $this->withheld());
    $this->assertNotFalse($cache->get(VariantConformance::CACHE_ID . '::strict:aurora'));

    // A member of the active system judges in the active universe: its
    // token reference resolves, so the verdict never needed buildFor().
    $this->assertTrue($roster->has(MaterialKind::Variant, 'aurora_tidy'));

    // The verdict's persistent entry rides enforcement's source, the
    // design system entity (an enforcement edit is an entity save, so the
    // list tag): a fresh request after an invalidation judges again
    // rather than reading the stale entry.
    $this->container->get('cache_tags.invalidator')->invalidateTags(['design_system_list']);
    $this->assertFalse($cache->get(VariantConformance::CACHE_ID . '::strict:aurora'));
  }

  /**
   * Saving a variant re-judges it: the reset rides the repository's.
   *
   * No manual reset: the variant entity hooks reset the repository, the
   * verdict and the roster together, and the entity save invalidates the
   * list tag the persistent verdict carries.
   */
  public function testSavingVariantRejudges(): void {
    $this->enforce('strict');
    $this->assertSame(['aurora_raw', 'raw'], $this->withheld());
    // One request boundary after the law change (roster()); every build
    // below reads the builder directly, so only the hooks' resets stand
    // between a save and the roster.
    $this->assertFalse($this->roster()->has(MaterialKind::Variant, 'raw'));
    $builder = $this->container->get('daedalus_blueprint.materials_roster_builder');

    // The raw variant is repaired: conforming now, offered now.
    $raw = $this->container->get('daedalus_stylish.variant_repository')->resolve('raw');
    $raw->setStyles(['' => ['default' => ['padding' => '1rem']]])->save();
    $this->assertSame(['aurora_raw'], $this->withheld());
    $this->assertTrue($builder->build()->has(MaterialKind::Variant, 'raw'));

    // A conforming member picks up a refused value: withheld now.
    $tidy = $this->container->get('daedalus_stylish.variant_repository')->resolve('aurora_tidy');
    $tidy->setStyles(['' => ['default' => ['padding' => 'var(--space-4)', 'font-size' => '2rem']]])->save();
    $this->assertSame(['aurora_raw', 'aurora_tidy'], $this->withheld());
    $this->assertFalse($builder->build()->has(MaterialKind::Variant, 'aurora_tidy'));

    // Unpublishing leaves the judge's set: neither withheld nor offered.
    $tidy->set('status', 0)->save();
    $this->assertSame(['aurora_raw'], $this->withheld());
    $this->assertFalse($builder->build()->has(MaterialKind::Variant, 'aurora_tidy'));
  }

  /**
   * Drives a fiber to its end through every park, returning its result.
   *
   * A judgement parks wherever core yields the fiber it runs in (the
   * theme registry on a cold kernel, the control module's load hook, the
   * alias manager on a page), so a leg resumes until the fiber is done
   * rather than counting parks.
   */
  protected function finish(\Fiber $fiber): mixed {
    while (!$fiber->isTerminated()) {
      $fiber->resume();
    }
    return $fiber->getReturn();
  }

  /**
   * A judgement parked in a fiber is waited for by a second fiber, not refused.
   *
   * The page that found this: two placeholders (the header and footer
   * blocks) each stamp their variant classes, each build asks the judge,
   * and the first judgement is parked mid-run by a suspend core makes
   * from inside its variant load (the alias manager yields any fiber it
   * runs in; the control module's load hook stands in for it here). The
   * second fiber's ask is not the judging flow re-entering: it yields,
   * the first finishes, and both hold the one verdict.
   */
  public function testParkedJudgementIsWaitedForByAnotherFiber(): void {
    $this->enforce('strict');
    $judge = $this->container->get('daedalus_stylish.variant_conformance');
    $this->coldVariantLoads();
    $this->container->get('state')->set('daedalus_stylish_control_test.park_variant_load', TRUE);

    $first = new \Fiber(static fn (): array => $judge->withheld());
    $first->start();
    $this->assertTrue($first->isSuspended(), 'the first judgement parked mid-run');

    $second = new \Fiber(static fn (): array => $judge->withheld());
    $second->start();
    $this->assertTrue($second->isSuspended(), 'the second fiber yields for the parked judgement instead of refusing');

    $verdict = $this->finish($first);
    $this->assertSame(['aurora_raw', 'raw'], array_keys($verdict));
    $this->assertNotFalse($this->container->get('cache.default')->get(VariantConformance::CACHE_ID . '::strict:aurora'));
    $this->assertSame($verdict, $this->finish($second), 'the waiting fiber took the parked judgement\'s verdict');
  }

  /**
   * The main flow judges for itself beside a parked fiber; both agree.
   *
   * The main flow cannot yield, so it judges again: the same verdict,
   * written twice, and the parked fiber's judgement still completes.
   */
  public function testMainFlowJudgesBesideAParkedFiber(): void {
    $this->enforce('strict');
    $judge = $this->container->get('daedalus_stylish.variant_conformance');
    $this->coldVariantLoads();
    $this->container->get('state')->set('daedalus_stylish_control_test.park_variant_load', TRUE);

    $parked = new \Fiber(static fn (): array => $judge->withheld());
    $parked->start();
    $this->assertTrue($parked->isSuspended());

    $this->assertSame(['aurora_raw', 'raw'], array_keys($judge->withheld()), 'the main flow got a verdict of its own');
    $this->assertSame(['aurora_raw', 'raw'], array_keys($this->finish($parked)));
  }

  /**
   * The judging flow re-entering the judge is still refused.
   *
   * The guard's reason to exist: a validation step that reads the
   * roster instead of the judge's universe recurses into the roster being
   * built from the verdict, and that is a trailhead named loudly.
   */
  public function testRecursionFromTheJudgingFlowIsRefused(): void {
    $this->enforce('strict');
    $judge = $this->container->get('daedalus_stylish.variant_conformance');
    $this->coldVariantLoads();
    $this->container->get('state')->set('daedalus_stylish_control_test.rejudge_in_variant_load', TRUE);

    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Variant conformance was asked for while it was being judged');
    $judge->withheld();
  }

  /**
   * A universe handed to the validator belongs to the flow that holds it.
   *
   * A validation parked inside withUniverse() must not lend its universe
   * to whatever runs meanwhile: the main flow and another fiber read the
   * site's roster, and the parked flow still validates in the universe
   * it was handed once it resumes.
   */
  public function testUniverseDoorIsPerFlow(): void {
    $validator = $this->container->get('daedalus_stylish.style_map_validator');
    $this->assertNotNull($validator->loadVariant('tidy'));

    $empty = new MaterialsRoster([], new CacheableMetadata());
    $parked = new \Fiber(static fn () => $validator->withUniverse($empty, static function () use ($validator) {
      \Fiber::suspend();
      return $validator->loadVariant('tidy');
    }));
    $parked->start();
    $this->assertTrue($parked->isSuspended());

    $this->assertNotNull($validator->loadVariant('tidy'), 'the main flow reads the site roster while a fiber holds a universe');
    $other = new \Fiber(static fn () => $validator->loadVariant('tidy'));
    $other->start();
    $this->assertTrue($other->isTerminated());
    $this->assertNotNull($other->getReturn(), 'another fiber reads the site roster too');

    $parked->resume();
    $this->assertTrue($parked->isTerminated());
    $this->assertNull($parked->getReturn(), 'the parked flow still validates in the universe it was handed');
  }

}
