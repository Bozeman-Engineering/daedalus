<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishAnimation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the animation AI surface: the materials fragment's animations group.
 *
 * Animations are the motion material: a named keyframes block the AI
 * wears by the property-scoped ident animation-name writes (motion-{name}),
 * never a var(). Like every material they are never permission-gated for
 * reading (entity access on the write lane is the permission model): the
 * published animations ride the materials fragment's animations group
 * (core's MaterialsSchema), keyed by machine name, each with its label,
 * its animation-name spelling, its steps as stored (so a writer sees what
 * an animation does before wearing it) and its description; the group and
 * its guidance sentence are present exactly while published animations
 * exist (grows-by-data: empty means absent). This pins that group's
 * shape, the published-only rule, the absence-when-empty rule, the
 * alter context's invalidation tag, the mint gesture line the cached
 * prompt composes from, and the ungated bundle-list entry that routes
 * the AI to the material gestures. (The motion trigger mechanics are the
 * cached roster lines', VocabularyProseTest; the prompt's ANIMATIONS
 * block is pinned by daedalus_blueprint_ai's DialectCatalogPromptMaterialsTest.)
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class AnimationAiSurfaceTest extends KernelTestBase {

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
    'daedalus_ui',
    'daedalus_stylish',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_animation');
    // The materials provider reads all four storages on the same alter.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
  }

  /**
   * Runs the daedalus_blueprint_schema alter over a style-guide-bearing schema.
   *
   * @return array
   *   [the altered schema, the alter context].
   */
  protected function alteredSchema(): array {
    $schema = [
      'fields' => [
        'name' => ['type' => 'string'],
        'daedalus_stylish_styles' => ['type' => 'daedalus_stylish_styles', 'style_guide' => ['guidance' => 'x']],
      ],
    ];
    $context = ['entity_type' => 'entity_test', 'bundle' => 'entity_test', 'view_mode' => 'full', 'cache_tags' => []];
    $this->container->get('module_handler')->alter('daedalus_blueprint_schema', $schema, $context);
    return [$schema, $context];
  }

  /**
   * The animations group lands ungated with the published vocabulary.
   */
  public function testAnimationsGroup(): void {
    $fade = DaedalusStylishAnimation::create(['label' => 'Fade in']);
    $fade->setDescription('Fade the element in from transparent; the quiet entrance for any band.')
      ->setSteps(['from' => ['opacity' => '0'], 'to' => ['opacity' => '1']])
      ->save();
    $pulse = DaedalusStylishAnimation::create(['label' => 'Pulse']);
    $pulse->setSteps([
      '0%' => ['transform' => 'scale(1)'],
      '50%' => ['transform' => 'scale(1.05)', 'animation-timing-function' => 'ease-in-out'],
      '100%' => ['transform' => 'scale(1)'],
    ])->save();
    $draft = DaedalusStylishAnimation::create(['label' => 'Unreleased']);
    $draft->setSteps(['0%' => ['opacity' => '0.5']])->setUnpublished()->save();

    [$schema, $context] = $this->alteredSchema();
    $materials = $schema['materials'] ?? NULL;
    $this->assertIsArray($materials, 'The materials fragment landed on the schema.');

    // The guidance teaches the animation vocabulary while the site has
    // one: the ident spelling (never a var()) and the steps' shape.
    $this->assertStringContainsString('"animations" are the published keyframes animations, keyed by machine name', $materials['guidance']);
    $this->assertStringContainsString('never a var()', $materials['guidance']);
    $this->assertStringContainsString('"steps" are the keyframes as stored, offset => declarations', $materials['guidance']);
    $this->assertStringNotContainsString('permission-gated', $materials['guidance']);

    // Published animations only, keyed by machine name; the spelling is
    // the whole reference as animation-name writes it, the steps are the
    // canonical stored map (from/to became percentages, sorted), the
    // description rides only when the animation has one.
    $animations = $materials['animations'];
    $this->assertSame(['fade_in', 'pulse'], array_keys($animations));
    $this->assertSame([
      'label' => 'Fade in',
      'spelling' => 'animation-name: motion-fade_in',
      'steps' => ['0%' => ['opacity' => '0'], '100%' => ['opacity' => '1']],
      'description' => 'Fade the element in from transparent; the quiet entrance for any band.',
    ], $animations['fade_in']);
    $this->assertSame([
      'label' => 'Pulse',
      'spelling' => 'animation-name: motion-pulse',
      'steps' => [
        '0%' => ['transform' => 'scale(1)'],
        '50%' => ['transform' => 'scale(1.05)', 'animation-timing-function' => 'ease-in-out'],
        '100%' => ['transform' => 'scale(1)'],
      ],
    ], $animations['pulse']);
    $this->assertArrayNotHasKey('unreleased', $animations);

    // The published-animation invalidation domain rides the alter context.
    $this->assertContains('daedalus_animation_list', $context['cache_tags']);

    // The materials are the schema's own key; the field's style guide is
    // untouched, and non-styling fields are untouched.
    $this->assertSame(['guidance'], array_keys($schema['fields']['daedalus_stylish_styles']['style_guide']));
    $this->assertArrayNotHasKey('style_guide', $schema['fields']['name']);
  }

  /**
   * No published animation means NO animations group: empty is absent.
   *
   * The fragment itself still lands (minting is part of the grammar and
   * its guidance says an absent kind has none), but the animations group
   * and its guidance sentence join only with the first PUBLISHED
   * animation: an empty map under the key would be vocabulary with
   * nothing to say, and a draft is not vocabulary either.
   */
  public function testEmptyRoster(): void {
    [$schema] = $this->alteredSchema();
    $materials = $schema['materials'] ?? NULL;
    $this->assertIsArray($materials, 'The fragment exists with no animations.');
    $this->assertArrayNotHasKey('animations', $materials);
    $this->assertStringNotContainsString('"animations" are', $materials['guidance']);
    $this->assertStringContainsString('a kind absent here has no materials on this site', $materials['guidance']);

    $draft = DaedalusStylishAnimation::create(['label' => 'Unreleased']);
    $draft->setSteps(['0%' => ['opacity' => '0.5']])->setUnpublished()->save();
    [$schema] = $this->alteredSchema();
    $this->assertArrayNotHasKey('animations', $schema['materials']);
    $this->assertStringNotContainsString('"animations" are', $schema['materials']['guidance']);
  }

  /**
   * The mint gesture line the cached prompt composes teaches steps, then wear.
   *
   * The cached page-language section's MINT line is assembled from each
   * provider's gesture lines (DialectCatalogPrompt::materialsLines()):
   * daedalus_stylish's animation clause names the steps map's shape and the
   * follow-through (wear it as animation-name), so the AI's first
   * contact with the mint already carries the recipe order.
   */
  public function testMintGestureLine(): void {
    $lines = $this->container->get('daedalus_blueprint.materials_roster_builder')->gestureLines();
    $this->assertSame('steps {"0%": {...}, "100%": {...}}, description; then wear it as animation-name', $lines['animation']);
  }

  /**
   * The daedalus_animation is AI-visible ungated, with the routing description.
   */
  public function testBundleListEntry(): void {
    $builder = $this->container->get('daedalus_blueprint.bundle_list_builder');
    $visible = $builder->getVisibleBundles();
    $this->assertArrayHasKey('daedalus_animation', $visible);
    $this->assertFalse($builder->isHidden('daedalus_animation'));
    $description = $visible['daedalus_animation']['bundles']['daedalus_animation']['description'];
    $this->assertStringContainsString('animation-name: motion-{machine_name}', $description);
    $this->assertStringContainsString('@keyframes', $description);
    // The routing is to the material gestures the brief's MINT line
    // teaches, never to a skill (the styling skill is gone) or an entity
    // write.
    $this->assertStringContainsString('save_material', $description);
    $this->assertStringContainsString('MINT line', $description);
    $this->assertStringNotContainsString('skill', $description);
  }

}
