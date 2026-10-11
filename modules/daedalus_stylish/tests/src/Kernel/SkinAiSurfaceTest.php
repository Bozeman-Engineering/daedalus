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
 * only and account-invariant; the skin authoring mechanics (the token
 * gestures with kind token, update_default, the create door) and their
 * permission posture ride the skins skill, and a skin is a design system
 * member the site wears by choice or as the system's default_skin (the
 * skill's catalog and the skin context both say so).
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
   * The gutter token's description: the reasoning the schema carries whole.
   */
  protected const GUTTER_DESCRIPTION = 'The band gutter. Reach for it between a band and its neighbours, never inside a card.';

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
    // The roster's daedalus_stylish provider queries the swatch and variant
    // storages unconditionally on the same alter.
    $this->installEntitySchema('daedalus_swatch');
    $this->installEntitySchema('daedalus_animation');
    $this->installEntitySchema('daedalus_font');
    $this->installEntitySchema('daedalus_variant');
    $this->installConfig(['daedalus_stylish']);
    // Activating a design system syncs every enabled module's shipped
    // definitions (ShippedSync::onDesignSystemActivated()): the importers
    // mirror motion-* and --swatch-* references into the usage tables and
    // switch to an administrative account, so the fixture carries both.
    $this->installSchema('daedalus_stylish', [
      'daedalus_variant_usage',
      'daedalus_swatch_usage',
      'daedalus_animation_usage',
      'daedalus_token_usage',
      'daedalus_font_usage',
    ]);
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
    $skin->setPackageTokens([
      'space' => ['gutter' => ['value' => '1.5rem', 'description' => self::GUTTER_DESCRIPTION]],
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
      ],
      'styles' => ['type' => 'daedalus_stylish_styles', 'style_guide' => ['guidance' => 'x']],
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
    // handle), the value as stored beside the token's whole description
    // when its author wrote one (the schema is the surface that carries
    // every material in full; the brief prints the first sentence).
    // Scale metadata (shape, label, prefix template) is mint-time
    // vocabulary and lives in the skins skill; the worn-skin pointer is
    // the skin lane's business too.
    $this->assertSame(['value' => '1.5rem', 'description' => self::GUTTER_DESCRIPTION], $materials['tokens']['space']['--space-gutter']);
    $this->assertSame(['value' => ['default' => '#1a1a2e', 'dark' => '#e8e8f0']], $materials['tokens']['color']['--color-accent']);
    $this->assertArrayNotHasKey('scales', $materials);
    $this->assertContains('daedalus_skin_list', $context['cache_tags']);
    $this->assertContains('config:daedalus_stylish.settings', $context['cache_tags']);
    // The materials are the schema's own key (core renders them from the
    // roster); the field's style guide is untouched.
    $this->assertSame(['guidance'], array_keys($schema['styles']['style_guide']));
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
    // member of the system its design_system field names, and the worn
    // skin is the site's choice for the active design system, else its
    // default_skin; the operations say wearing a skin is a person's
    // gesture, never an op.
    $this->assertStringContainsString('ACTIVE marks the skin the site wears: its choice for the active design system while that names a published, conforming member, else the system\'s default_skin', $composed);
    $this->assertMatchesRegularExpression('/machine_name aurora, "Aurora": ACTIVE, member of aurora, /', $composed);
    $this->assertStringContainsString('Wearing a skin is an administer gesture with no operation', $composed);
    $this->assertStringNotContainsString('styling skill', $composed);
    $this->assertStringContainsString("## Designing a skin's identity", $composed);
    $this->assertStringContainsString('characterful display face', $composed);
    // The skill speaks the operations: tokens are materials of the worn
    // skin (kind token on the material gesture family), defaults go
    // through update_default one scope at a time, a skin is created
    // through the entity door with token records; the read-merge-write
    // recipes over whole fields are gone with the whole-field write.
    $this->assertStringContainsString('## Operations (page_edit ops; they write the worn skin, not the page)', $composed);
    $this->assertStringContainsString('save_material {kind: token, group: <scale>, label:', $composed);
    $this->assertStringContainsString('update_material {kind: token, id: "space/4", value?,', $composed);
    $this->assertStringContainsString('remove_material {kind: token, id}', $composed);
    $this->assertStringContainsString('update_default {scope, key?,', $composed);
    $this->assertStringContainsString('{scale: {name: {value, description, offered}}}', $composed);
    $this->assertStringContainsString('two LAYERS on one entity', $composed);
    $this->assertStringContainsString('eb_create_entity daedalus_skin with label, tokens', $composed);
    $retired_teaching = [
      'read-merge-write',
      'FULL map',
      'Activation is a design-system act',
      'Recipes (generic entity ops',
      '{scale: {name: value}}',
    ];
    foreach ($retired_teaching as $retired) {
      $this->assertStringNotContainsString($retired, $composed, "The retired teaching \"$retired\" left the skill.");
    }
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
    // The context teaches the same facts the catalog does (membership,
    // the worn skin as the site's choice else the system's default_skin,
    // the two layers) and routes to the operations the skill teaches.
    $this->assertStringContainsString('member of a design system', $skin_context['guidance']);
    $this->assertStringContainsString("else the system's default_skin", $skin_context['guidance']);
    $this->assertStringContainsString('{scale: {name: {value, description, offered}}}', $skin_context['guidance']);
    $this->assertStringContainsString('save_material, update_material and remove_material with kind token, defaults through update_default, never a whole-field write', $skin_context['guidance']);
    $this->assertStringContainsString('no operation switches it', $skin_context['guidance']);
    foreach (['read-merge-write', 'Activation is a design-system act', '{scale: {name: value}}'] as $retired) {
      $this->assertStringNotContainsString($retired, $skin_context['guidance'], "The retired teaching \"$retired\" left the context.");
    }
  }

}
