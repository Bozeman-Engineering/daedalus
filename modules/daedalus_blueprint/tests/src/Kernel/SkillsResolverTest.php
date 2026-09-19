<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\SkillType\CreateEntitySkillType;
use Drupal\daedalus_blueprint\SkillType\DefaultSkillType;
use Drupal\daedalus_blueprint\DesignSystem\ActiveDesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\SkillsResolver;
use Drupal\daedalus_blueprint\SkillType\SkillTypeManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the skills system: discovery, alter, caching, and type wiring.
 *
 * Skills are the on-demand-guidance contract. The conversation carries only a
 * catalog (id + description); the LLM pulls full instructions through the
 * eb_skill tool exactly when a skill matches the task. Several behaviors make
 * that work and none of them were tested before this class:
 *
 * - SkillsResolver discovers one skill per
 *   MODULE.daedalus_blueprint.skills.ID.yml file (filename suffix is the id),
 *   merges duplicates last-module-wins, runs
 *   hook_daedalus_blueprint_skills_alter, and caches the result behind the
 *   daedalus_blueprint_skills tag. If discovery or the alter merge regressed, the
 *   catalog the AI sees would be wrong; if the cache tag were wrong, runtime
 *   skill edits would never appear.
 * - SkillTypeManager collects tagged skill type services by type_id and
 *   composes a skill through the type its definition names. The retired
 *   current_entity type (bundle context YAML `skills:` deltas layered onto a
 *   skill) must stay gone: bundle routing now rides the bundle's `guidance`
 *   key, which the schema surface actually delivers.
 * - BlueprintContextResolver's layout/block point queries are the lookups the
 *   element resolver depends on; the schema tests only exercise the
 *   field_type path, so these are pinned here directly.
 *
 * Everything shares one setUp (one Drupal install) and the assertions are
 * packed into a few fat methods, because reinstalling per method is the slow
 * part of a kernel run. The class asserts against the base module's real skill
 * YAML (create_entity) rather than synthetic fixtures, so it also guards that
 * shipped definition. The fixture module supplies the state-driven skills_alter
 * and context_alter hooks plus the context YAML used here.
 */
#[CoversClass(SkillsResolver::class)]
#[CoversClass(SkillTypeManager::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class SkillsResolverTest extends BlueprintKernelTestBase {

  /**
   * Enables the fixture module providing the alter hooks and context YAML.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableModules(['daedalus_blueprint_test']);
    \Drupal::cache()->deleteAll();
  }

  /**
   * Tests YAML skill discovery, getSkill, alter, and cache invalidation.
   */
  public function testDiscoveryGetSkillAlterAndCaching(): void {
    /** @var \Drupal\daedalus_blueprint\SkillsResolver $resolver */
    $resolver = $this->container->get('daedalus_blueprint.skills_resolver');

    // The base module ships one skill, discovered by filename suffix, with
    // its label/description/type intact.
    $skills = $resolver->getSkills();
    $this->assertArrayHasKey('create_entity', $skills);
    $this->assertSame('create_entity', $skills['create_entity']['type']);
    $this->assertSame('Create Entity', $skills['create_entity']['label']);

    // getSkill returns one definition by id, and NULL for an unknown id. Note
    // the discovered definition carries no 'id' key; the eb_skill tool injects
    // it before composing, so the resolver intentionally leaves it out.
    $createEntity = $resolver->getSkill('create_entity');
    $this->assertIsArray($createEntity);
    $this->assertSame('Create Entity', $createEntity['label']);
    $this->assertArrayNotHasKey('id', $createEntity);
    $this->assertNull($resolver->getSkill('does_not_exist'));

    // The alter hook can add a brand new skill and override an existing one.
    \Drupal::state()->set('daedalus_blueprint_test.skills', [
      'fixture_skill' => [
        'label' => 'Fixture Skill',
        'description' => 'Added by the alter hook.',
        'type' => 'default',
        'instructions' => 'Fixture instructions.',
      ],
      'create_entity' => [
        'label' => 'Overridden Create',
        'description' => 'Overridden by alter.',
        'type' => 'create_entity',
        'instructions' => 'New instructions.',
      ],
    ]);

    // Without invalidating the cache the first result is still served, proving
    // getSkills() is cached and not recomputed every call.
    $cached = $resolver->getSkills();
    $this->assertArrayNotHasKey('fixture_skill', $cached);
    $this->assertSame('Create Entity', $cached['create_entity']['label']);

    // Invalidating the skills cache tag forces recomputation; now the alter
    // additions/overrides appear. This is the path a runtime skill change uses.
    \Drupal::service('cache_tags.invalidator')
      ->invalidateTags(['daedalus_blueprint_skills']);
    $altered = $resolver->getSkills();
    $this->assertArrayHasKey('fixture_skill', $altered);
    $this->assertSame('Fixture Skill', $altered['fixture_skill']['label']);
    $this->assertSame('Overridden Create', $altered['create_entity']['label']);
  }

  /**
   * Tests the per-skill permission gate: gated skills exist per account.
   *
   * A definition's `permission` key makes the skill account-shaped: every
   * consumer (the catalog listing, the eb_skill tool) reads through the
   * resolver, so an account without the permission neither sees nor can
   * invoke the skill. The gate applies after the cache read, so the cached
   * discovery stays account-independent and switching accounts needs no
   * invalidation.
   */
  public function testPermissionGate(): void {
    $resolver = $this->container->get('daedalus_blueprint.skills_resolver');

    // Add a gated skill through the alter; the base setUp's admin account
    // passes any permission check, so it is visible and warms the cache.
    \Drupal::state()->set('daedalus_blueprint_test.skills', [
      'gated_skill' => [
        'label' => 'Gated Skill',
        'description' => 'Exists only for accounts that may administer modules.',
        'type' => 'default',
        'instructions' => 'Gated instructions.',
        'permission' => 'administer modules',
      ],
    ]);
    \Drupal::service('cache_tags.invalidator')
      ->invalidateTags(['daedalus_blueprint_skills']);
    $this->assertArrayHasKey('gated_skill', $resolver->getSkills());

    // Without the permission the skill does not exist for the account:
    // not listed, not resolvable by id. Ungated skills are untouched.
    $this->setCurrentUser($this->createUser());
    $skills = $resolver->getSkills();
    $this->assertArrayNotHasKey('gated_skill', $skills);
    $this->assertArrayHasKey('create_entity', $skills);
    $this->assertNull($resolver->getSkill('gated_skill'));

    // An account holding the permission reads the same cache entry and
    // gets the skill.
    $this->setCurrentUser($this->createUser(['administer modules']));
    $this->assertIsArray($resolver->getSkill('gated_skill'));
  }

  /**
   * Tests design-system membership: a member skill exists only when active.
   *
   * A skill file follows the one disk-lane rule every member kind shares:
   * absent key, the shipping module's packaged system (aurora_voice); an
   * explicit id, that system (aurora_named_voice); `design_system: ~`,
   * global (plain_prose). The rule answers from disk: a package's members
   * belong to its system in the window before its install config lands (a
   * recipe installs the module first and imports its config after), and
   * the landing changes no answer. A member of an inactive system does
   * not exist for the request, beside the permission gate: neither listed
   * nor resolvable by id, so the catalog subscriber and the eb_skill tool
   * inherit the rule. The cached discovery carries the design-system list
   * tag so a site-built system's deletion refuses a cached member on the
   * next read.
   */
  public function testDesignSystemMembership(): void {
    // The package's files before its system lands: both members already
    // belong to aurora in the cached discovery (read past the gate, which
    // hides them while no system is active); the opted-out file and the
    // packageless module's files are global. Services resolve after the
    // enable (it rebuilds the container; a pre-rebuild config factory
    // would not see the new one's writes).
    $this->enableModules(['daedalus_blueprint_design_system_test']);
    $resolver = $this->container->get('daedalus_blueprint.skills_resolver');
    $active = $this->container->get(ActiveDesignSystem::SERVICE_ID);
    $this->assertNull(DesignSystem::load('aurora'), 'The window: the package is installed, its system has not landed.');
    $skills = $resolver->getSkills();
    $this->assertArrayNotHasKey('aurora_voice', $skills, 'No system is active: the member does not exist for the request.');
    $this->assertArrayNotHasKey('aurora_named_voice', $skills);
    $this->assertNull($skills['plain_prose']['design_system']);
    $this->assertNull($skills['create_entity']['design_system'], 'A module packaging no system ships global skills.');
    $cached = \Drupal::cache()->get('daedalus_blueprint:skills');
    $this->assertSame('aurora', $cached->data['aurora_voice']['design_system']);
    $this->assertSame('aurora', $cached->data['aurora_named_voice']['design_system']);
    $this->assertContains('config:design_system_list', $cached->tags);

    // The system landing changes no answer; the gate still hides the
    // members until aurora is the active system.
    $this->installConfig(['daedalus_blueprint', 'daedalus_blueprint_design_system_test']);
    $skills = $resolver->getSkills();
    $this->assertArrayNotHasKey('aurora_voice', $skills);
    $this->assertArrayNotHasKey('aurora_named_voice', $skills);
    $this->assertNull($skills['plain_prose']['design_system']);
    $this->assertNull($skills['create_entity']['design_system']);
    $this->assertNull($resolver->getSkill('aurora_voice'));
    $cached = \Drupal::cache()->get('daedalus_blueprint:skills');
    $this->assertSame('aurora', $cached->data['aurora_voice']['design_system']);
    $this->assertSame('aurora', $cached->data['aurora_named_voice']['design_system']);

    $active->activate('aurora');
    $skills = $resolver->getSkills();
    $this->assertSame('aurora', $skills['aurora_voice']['design_system']);
    $this->assertSame('aurora', $skills['aurora_named_voice']['design_system']);
    $this->assertSame('Aurora voice', $resolver->getSkill('aurora_voice')['label']);
    $this->assertSame('Aurora named voice', $resolver->getSkill('aurora_named_voice')['label']);
    $this->assertArrayHasKey('plain_prose', $skills);

    // Another system active: aurora's members are gone again, global stays.
    DesignSystem::create(['id' => 'borealis', 'label' => 'Borealis'])->save();
    $active->activate('borealis');
    $skills = $resolver->getSkills();
    $this->assertArrayNotHasKey('aurora_voice', $skills);
    $this->assertArrayNotHasKey('aurora_named_voice', $skills);
    $this->assertArrayHasKey('plain_prose', $skills);
    $this->assertNull($resolver->getSkill('aurora_voice'));
    $this->assertNull($resolver->getSkill('aurora_named_voice'));
  }

  /**
   * Tests SkillTypeManager wiring and the retired current_entity type.
   */
  public function testSkillTypeManagerWiring(): void {
    /** @var \Drupal\daedalus_blueprint\SkillType\SkillTypeManager $manager */
    $manager = $this->container->get('daedalus_blueprint.skill_type_manager');

    // The collector registered both shipped skill types by their type_id,
    // and returns NULL for an unregistered id.
    $this->assertInstanceOf(
      DefaultSkillType::class,
      $manager->getType('default'),
    );
    $this->assertInstanceOf(
      CreateEntitySkillType::class,
      $manager->getType('create_entity'),
    );
    $this->assertNull($manager->getType('no_such_type'));

    // The current_entity type (bundle context `skills:` deltas layered onto
    // a skill) is retired: bundle-specific routing rides the bundle's
    // `guidance` key instead. A regression re-registering it fails here.
    $this->assertNull($manager->getType('current_entity'));
  }

  /**
   * Tests BlueprintContextResolver layout and block-type point queries.
   *
   * These two lookups are the seam the element resolver reads through. The
   * fixture ships universal layout/block YAML plus a scoped layout override for
   * entity_test.entity_test; the resolver merges them (scoped wins for
   * layout_onecol) and returns the block-type guidance directly.
   */
  public function testLayoutAndBlockTypePointQueries(): void {
    $contextResolver = $this->container->get('daedalus_blueprint.context_resolver');

    // Layout point query: scoped YAML overrides the universal guidance, while
    // the universal defaults survive the merge.
    $layout = $contextResolver->getLayoutContext('entity_test', 'entity_test', 'layout_onecol');
    $this->assertSame('Scoped one-column guidance for entity_test.', $layout['guidance']);
    $this->assertSame('full-width', $layout['defaults']['layout_settings']['width']);

    // Block-type point query returns the universal block guidance verbatim.
    $block = $contextResolver->getBlockTypeContext('entity_test', 'entity_test', 'yaml_test_block');
    $this->assertSame('YAML-provided block type guidance.', $block['guidance']);

    // Unknown layout/block ids return an empty array, never a notice.
    $this->assertSame([], $contextResolver->getLayoutContext('entity_test', 'entity_test', 'no_layout'));
    $this->assertSame([], $contextResolver->getBlockTypeContext('entity_test', 'entity_test', 'no_block'));
  }

}
