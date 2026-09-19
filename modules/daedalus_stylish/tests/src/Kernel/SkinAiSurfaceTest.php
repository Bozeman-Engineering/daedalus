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
 * Tests the skin/token AI surface: unconditional vocabulary, gated writes.
 *
 * Visibility of a material is never a gate: the minted tokens ride every
 * style_guide unconditionally as the materials fragment's named-value
 * groups (without the roster, var() references would be unlearnable
 * except by bouncing off validation). Changing the design system IS
 * gated, by the same access skin CRUD enforces: the daedalus_skin
 * bundle-list exposure (which the daedalus_blueprint_ai tools enforce, so
 * listed means operable) and the skins skill follow the acting account's
 * 'administer skins' permission. The materials guidance is roster-facts
 * only and account-invariant; the skin authoring mechanics and their
 * permission posture ride the skins skill, and a skin is a design system
 * member whose activation is the system's default_skin (the skill's
 * catalog and the skin context both say so).
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class SkinAiSurfaceTest extends KernelTestBase {

  use DesignSystemActivationTrait;
  use UserCreationTrait;

  /**
   * Any theme name works: an unknown theme simply has no theme layers.
   */
  protected const THEME = 'stark';

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
    'daedalus_stylish_control_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('daedalus_skin');
    // The roster's daedalus_stylish provider queries the swatch and variant
    // storages unconditionally on the same alter.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_variant');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', ['daedalus_variant_usage', 'daedalus_swatch_usage', 'daedalus_animation_usage']);
    $this->container->get('state')->set('daedalus_stylish_control_test.site', [
      'token_scales' => [
        'space' => ['label' => 'Space', 'shape' => 'dimension'],
        'color' => ['label' => 'Color', 'shape' => 'color'],
      ],
    ]);
    $this->container->get('daedalus_stylish.style_guide')->resetCache();

    // Burn uid 1: the superuser access policy would blur the permission
    // assertions below.
    $this->createUser();

    $skin = DaedalusStylishSkin::create(['label' => 'Aurora']);
    $skin->setTokens([
      'space' => ['gutter' => '1.5rem'],
      'color' => ['accent' => ['default' => '#1a1a2e', 'dark' => '#e8e8f0']],
    ])->save();
    $this->activateSkin($skin);
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
   * The token groups are unconditional; only the authoring lines flip.
   */
  public function testTokenGroupsUnconditional(): void {
    // An account with no skin access still gets the whole vocabulary.
    $this->setCurrentUser($this->createUser());
    [$schema, $context] = $this->alteredSchema();
    $materials = $schema['materials'] ?? NULL;
    $this->assertIsArray($materials, 'The materials fragment lands without any skin permission.');

    // The named values: the active skin's minted tokens, grouped by scale
    // id, each entry keyed by the RESOLVED variable (the application
    // handle), values as stored. Scale metadata (shape, label, prefix
    // template) is mint-time vocabulary and lives in the skins skill; the
    // active-skin pointer is the skin lane's business too.
    $this->assertSame('1.5rem', $materials['tokens']['space']['--space-gutter']);
    $this->assertSame(['default' => '#1a1a2e', 'dark' => '#e8e8f0'], $materials['tokens']['color']['--color-accent']);
    $this->assertArrayNotHasKey('scales', $materials);
    $this->assertContains('daedalus_skin_list', $context['cache_tags']);
    $this->assertContains('config:daedalus_stylish.settings', $context['cache_tags']);
    // The materials are the schema's own key (core renders them from the
    // roster); the field's style guide is untouched.
    $this->assertSame(['guidance'], array_keys($schema['fields']['daedalus_stylish_styles']['style_guide']));
    // Non-styling fields are untouched.
    $this->assertArrayNotHasKey('style_guide', $schema['fields']['name']);

    // Roster facts only, account-invariant: the guidance carries no
    // authoring routing (mechanics ride the styling and skins skills),
    // and an account with skin access reads the same paragraph.
    $this->assertStringNotContainsString('skins skill', $materials['guidance']);
    $this->assertStringNotContainsString('administrator action', $materials['guidance']);
    $this->setCurrentUser($this->createUser(['administer skins']));
    [$schema] = $this->alteredSchema();
    $this->assertSame($materials['guidance'], $schema['materials']['guidance']);
  }

  /**
   * Entity and skill visibility flip with the administer skins permission.
   */
  public function testWriteSurfaceFlipsWithPermission(): void {
    $builder = $this->container->get('daedalus_blueprint.bundle_list_builder');
    $resolver = $this->container->get('daedalus_blueprint.skills_resolver');

    // Without the permission the write surface does not exist: isHidden()
    // is what the AI tools enforce, so hidden means unreachable.
    $this->setCurrentUser($this->createUser());
    $this->assertArrayNotHasKey('daedalus_skin', $builder->getVisibleBundles());
    $this->assertTrue($builder->isHidden('daedalus_skin'));
    // Variants stay reachable: the variant mint gesture depends on them,
    // and only skins are permission-gated.
    $this->assertFalse($builder->isHidden('daedalus_variant'));
    $this->assertNull($resolver->getSkill('skins'));
    // The styling skill is gone (the design system brief carries the
    // token vocabulary for every account); no skill of that id exists.
    $this->assertNull($resolver->getSkill('styling'));

    // With the permission the whole write surface exists.
    $this->setCurrentUser($this->createUser(['administer skins']));
    $visible = $builder->getVisibleBundles();
    $this->assertArrayHasKey('daedalus_skin', $visible);
    $this->assertFalse($builder->isHidden('daedalus_skin'));
    $this->assertStringContainsString('skins skill', $visible['daedalus_skin']['bundles']['daedalus_skin']['description']);

    // The skins skill exists for this account and composes live state
    // before its static recipes.
    $definition = $resolver->getSkill('skins');
    $this->assertIsArray($definition);
    $this->assertSame('skins', $definition['type']);
    $this->assertSame('administer skins', $definition['permission']);
    $type = $this->container->get('daedalus_blueprint.skill_type_manager')->getType('skins');
    $this->assertNotNull($type, 'The skins skill type is registered.');
    $definition['id'] = 'skins';
    // The fixture skin joins the fixture system so the catalog's member
    // flag has something to say.
    $this->container->get('daedalus_stylish.skin_manager')->loadActiveSkin()->set('design_system', 'aurora')->save();
    $composed = $type->compose($definition, []);
    $this->assertStringContainsString('space (dimension, --space-{name})', $composed);
    $this->assertStringContainsString('machine_name aurora, "Aurora"', $composed);
    $this->assertStringContainsString('ACTIVE', $composed);
    // The catalog teaches membership and what ACTIVE means: a skin is a
    // member of the system its design_system field names, and the active
    // skin is the active design system's default_skin; the recipes say
    // activation is a design-system act, never a skin write.
    $this->assertStringContainsString('ACTIVE marks the active design system\'s default_skin', $composed);
    $this->assertMatchesRegularExpression('/machine_name aurora, "Aurora": ACTIVE, member of aurora, /', $composed);
    $this->assertStringContainsString('Activation is a design-system act', $composed);
    $this->assertStringNotContainsString('styling skill', $composed);
    $this->assertStringContainsString("## Designing a skin's identity", $composed);
    $this->assertStringContainsString('characterful display face', $composed);
    $this->assertStringContainsString('## Recipes (generic entity ops on daedalus_skin)', $composed);
    // The palette boundary: tokens vs swatches routing, the reserved
    // namespace, and the skin-switch persistence contrast.
    $this->assertStringContainsString('## Tokens vs the palette', $composed);
    $this->assertStringContainsString('var(--swatch-{machine_name})', $composed);
    $this->assertStringContainsString('--swatch- namespace is reserved', $composed);
    $this->assertStringContainsString('persist across a skin switch', $composed);
    // The unminted-reference rule is generated law, absent here because
    // this site (guarded) does not check it.
    $this->assertStringNotContainsString('unminted references are rejected', $composed);

    // On a closed-world site the catalog gains the reference rule as
    // plain fact, and the composition still never names a mode or the
    // setting (the mode is opaque to the AI).
    $this->enforce('strict');
    $strict_composed = $type->compose($definition, []);
    $this->assertStringContainsString('must name a minted token or a PUBLISHED swatch; unminted references are rejected', $strict_composed);
    $this->assertDoesNotMatchRegularExpression('/\b(strict|guarded|enforcement)\b/i', $strict_composed);
    $this->enforce('guarded');

    // Entity-level guidance is knowledge, not access: it is discovered
    // unconditionally, and only accounts that can reach the daedalus_skin
    // schema (the tools enforce visibility) ever read it.
    $skin_context = $this->container->get('daedalus_blueprint.context_resolver')->getContext('daedalus_skin', 'daedalus_skin');
    $this->assertStringContainsString('skins skill', $skin_context['guidance']);
    // The context teaches the same two facts the catalog does: membership
    // and the active skin as the active system's default_skin.
    $this->assertStringContainsString('member of a design system', $skin_context['guidance']);
    $this->assertStringContainsString("active design system's default_skin", $skin_context['guidance']);
    $this->assertStringContainsString('Activation is a design-system act', $skin_context['guidance']);
  }

}
