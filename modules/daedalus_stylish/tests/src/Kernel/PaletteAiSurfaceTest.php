<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\Entity\DaedalusStylishSkin;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the palette AI surface: the skin's named values on the JSON door.
 *
 * Material rosters are never permission-gated: the active skin's tokens
 * exist for every account (entity access on the skin's write lane, not
 * visibility, is the permission model). The palette is tokens on the
 * color and gradient scales, and they ride the materials fragment's
 * `tokens` group (core's MaterialsSchema) by scale, keyed by the var()
 * name a style value references, values as stored, each with its
 * description when its author wrote one; the group is present exactly
 * while the worn skin offers a token (grows-by-data: empty means absent,
 * not an empty map). This pins that group's shape, the offered-only
 * rule, the absence-when-empty rule and the alter context's invalidation
 * tags.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class PaletteAiSurfaceTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'daedalus_blueprint',
    'filter',
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
    // The materials hook queries the variant and skin storages on the
    // same alter.
    $this->installEntitySchema('daedalus_variant');
    $this->installEntitySchema('daedalus_skin');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions: the importers mirror references into the usage tables
    // and switch to an administrative account.
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_skin_material_usage',
    ]);
    $this->setUpCurrentUser([], [], TRUE);
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
      ],
      'styles' => ['type' => 'daedalus_stylish_styles', 'style_guide' => ['guidance' => 'x']],
    ];
    $context = ['entity_type' => 'entity_test', 'bundle' => 'entity_test', 'view_mode' => 'full', 'cache_tags' => []];
    $this->container->get('module_handler')->alter('daedalus_blueprint_schema', $schema, $context);
    return [$schema, $context];
  }

  /**
   * The palette rides the tokens group, ungated, by scale.
   */
  public function testPaletteTokens(): void {
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora']);
    $skin->setPackageTokens([
      'color' => [
        'brand-red' => '#e94560',
        'ink' => ['value' => '#123456', 'offered' => FALSE],
      ],
      'gradient' => [
        'hero-fade' => [
          'value' => [
            'default' => 'linear-gradient(135deg, #1a1a2e, var(--color-brand-red))',
            'dark' => 'linear-gradient(135deg, #0a0a14, var(--color-brand-red))',
          ],
          'description' => 'The hero ground; never behind body copy.',
        ],
      ],
    ])->save();
    $this->activateSkin($skin);

    [$schema, $context] = $this->alteredSchema();
    $materials = $schema['materials'] ?? NULL;
    $this->assertIsArray($materials, 'The materials fragment landed on the schema.');

    // The reference grammar and the fit rules ride the one description;
    // no per-kind labels table: a value's own shape says what it fits.
    $this->assertStringContainsString('"tokens" are the design tokens the active skin mints', $materials['guidance']);
    $this->assertStringContainsString('colors and gradients among them', $materials['guidance']);
    $this->assertStringNotContainsString('permission-gated', $materials['guidance']);
    $this->assertStringContainsString('Colors fit color-valued properties', $materials['guidance']);
    $this->assertArrayNotHasKey('kinds', $materials);

    // Offered tokens only, by scale, keyed by the derived custom property
    // (the one spelling rule), values as stored, the description riding
    // only when written.
    $tokens = $materials['tokens'];
    $this->assertSame(['value' => '#e94560'], $tokens['color']['--color-brand-red']);
    $this->assertArrayNotHasKey('--color-ink', $tokens['color']);
    $this->assertSame(
      [
        'default' => 'linear-gradient(135deg, #1a1a2e, var(--color-brand-red))',
        'dark' => 'linear-gradient(135deg, #0a0a14, var(--color-brand-red))',
      ],
      $tokens['gradient']['--gradient-hero-fade']['value'],
    );
    $this->assertSame('The hero ground; never behind body copy.', $tokens['gradient']['--gradient-hero-fade']['description']);

    // The skin invalidation domain rides the alter context.
    $this->assertContains('daedalus_skin_list', $context['cache_tags']);

    // The materials are the schema's own key; the field's style guide is
    // untouched.
    $this->assertSame(['guidance'], array_keys($schema['styles']['style_guide']));

    // Non-styling fields are untouched.
    $this->assertArrayNotHasKey('style_guide', $schema['fields']['name']);
  }

  /**
   * An empty palette means NO tokens group: empty is absent, grows-by-data.
   *
   * The fragment itself still lands (its guidance names the group and
   * teaches the reference grammar), but the tokens group joins the
   * fragment only with the first offered token of the worn skin: an empty
   * map under the key would be vocabulary with nothing to say.
   */
  public function testEmptyPalette(): void {
    [$schema] = $this->alteredSchema();
    $materials = $schema['materials'] ?? NULL;
    $this->assertIsArray($materials, 'The fragment exists with no skin: minting is part of the grammar.');
    $this->assertArrayNotHasKey('tokens', $materials);

    // A token withheld from the AI is not vocabulary either: the group
    // appears only with the first OFFERED token.
    $skin = DaedalusStylishSkin::create(['label' => 'Aurora', 'machine_name' => 'aurora']);
    $skin->setPackageTokens(['color' => ['ink' => ['value' => '#123456', 'offered' => FALSE]]])->save();
    $this->activateSkin($skin);
    [$schema] = $this->alteredSchema();
    $this->assertArrayNotHasKey('tokens', $schema['materials']);
  }

}
