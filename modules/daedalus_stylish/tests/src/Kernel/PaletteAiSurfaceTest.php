<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSwatch;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the palette AI surface: the swatch side of the materials values.
 *
 * Material rosters are never permission-gated: the published swatches
 * and the daedalus_swatch bundle description exist for every account
 * (entity access on the swatch write lane, not visibility, is the
 * permission model). Swatches ride the
 * materials fragment's swatches group (core's MaterialsSchema)
 * as the reserved `swatch` group — provenance-free beside the token
 * groups, keyed by the var() name a style value references, values as
 * stored — and the group is present exactly while published swatches
 * exist (grows-by-data: empty means absent, not an empty map). This pins
 * that group's shape, the published-only rule, the absence-when-empty
 * rule, the alter context's invalidation tags, and the ungated
 * bundle-list entry.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class PaletteAiSurfaceTest extends KernelTestBase {

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
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    // The materials hook queries the variant and skin storages on the
    // same alter.
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
   * The swatch group lands ungated with the published vocabulary.
   */
  public function testSwatchGroup(): void {
    $red = DaedalusStylishSwatch::create(['label' => 'Brand red', 'kind' => 'color']);
    $red->setSwatchValue('#e94560')->save();
    $fade = DaedalusStylishSwatch::create(['label' => 'Hero fade', 'kind' => 'gradient']);
    $fade->setSwatchValue([
      'default' => 'linear-gradient(135deg, #1a1a2e, var(--swatch-brand_red))',
      'dark' => 'linear-gradient(135deg, #0a0a14, var(--swatch-brand_red))',
    ])->save();
    $draft = DaedalusStylishSwatch::create(['label' => 'Unreleased', 'kind' => 'color']);
    $draft->setSwatchValue('#123456')->setUnpublished()->save();

    [$schema, $context] = $this->alteredSchema();
    $materials = $schema['materials'] ?? NULL;
    $this->assertIsArray($materials, 'The materials fragment landed on the schema.');

    // The reference grammar and the fit rules ride the one description
    // (the minting recipe and its permission posture are the styling
    // skill's palette section, not the schema's); the old per-kind
    // labels table is gone: a value's own shape says what it fits.
    $this->assertStringContainsString('swatch', $materials['guidance']);
    $this->assertStringContainsString('published palette swatches', $materials['guidance']);
    $this->assertStringNotContainsString('permission-gated', $materials['guidance']);
    $this->assertStringContainsString('Colors fit color-valued properties', $materials['guidance']);
    $this->assertArrayNotHasKey('kinds', $materials);

    // Published swatches only, keyed by the derived variable (the one
    // namespace rule), values as stored, with the label a person asks
    // for and the kind saying what the value fits.
    $swatches = $materials['swatches'];
    $this->assertSame(['label' => 'Brand red', 'kind' => 'color', 'value' => '#e94560'], $swatches['--swatch-brand_red']);
    $this->assertSame('gradient', $swatches['--swatch-hero_fade']['kind']);
    $this->assertSame(
      [
        'default' => 'linear-gradient(135deg, #1a1a2e, var(--swatch-brand_red))',
        'dark' => 'linear-gradient(135deg, #0a0a14, var(--swatch-brand_red))',
      ],
      $swatches['--swatch-hero_fade']['value'],
    );
    $this->assertArrayNotHasKey('--swatch-unreleased', $swatches);

    // The published-swatch invalidation domain rides the alter context.
    $this->assertContains('daedalus_swatch_list', $context['cache_tags']);

    // The materials are the schema's own key; the field's style guide is
    // untouched.
    $this->assertSame(['guidance'], array_keys($schema['fields']['daedalus_stylish_styles']['style_guide']));

    // Non-styling fields are untouched.
    $this->assertArrayNotHasKey('style_guide', $schema['fields']['name']);
  }

  /**
   * An empty palette means NO swatches group: empty is absent, grows-by-data.
   *
   * The fragment itself still lands (its guidance names the group and
   * teaches the reference grammar), but the swatches group joins the
   * fragment only with the first published swatch: an empty map under
   * the reserved key would be vocabulary with nothing to say.
   */
  public function testEmptyPalette(): void {
    [$schema] = $this->alteredSchema();
    $materials = $schema['materials'] ?? NULL;
    $this->assertIsArray($materials, 'The fragment exists with no swatches: minting is part of the grammar.');
    $this->assertArrayNotHasKey('swatches', $materials);

    // An unpublished swatch is not vocabulary either: the group appears
    // only with the first PUBLISHED swatch.
    $draft = DaedalusStylishSwatch::create(['label' => 'Unreleased', 'kind' => 'color']);
    $draft->setSwatchValue('#123456')->setUnpublished()->save();
    [$schema] = $this->alteredSchema();
    $this->assertArrayNotHasKey('swatches', $schema['materials']);
  }

  /**
   * The daedalus_swatch is AI-visible ungated, with the routing description.
   */
  public function testBundleListEntry(): void {
    $builder = $this->container->get('daedalus_blueprint.bundle_list_builder');
    $visible = $builder->getVisibleBundles();
    $this->assertArrayHasKey('daedalus_swatch', $visible);
    $this->assertFalse($builder->isHidden('daedalus_swatch'));
    $description = $visible['daedalus_swatch']['bundles']['daedalus_swatch']['description'];
    $this->assertStringContainsString('var(--swatch-{machine_name})', $description);
    // The routing is to the material gestures the brief's MINT line
    // teaches, never to a skill (the styling skill is gone) or an entity
    // write.
    $this->assertStringContainsString('save_material', $description);
    $this->assertStringContainsString('MINT line', $description);
    $this->assertStringNotContainsString('skill', $description);
  }

}
