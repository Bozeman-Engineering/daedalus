<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\BundleListBuilder;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests entity persistence routing and AI-visible bundle filtering.
 *
 * Two untested public surfaces share this install:
 *
 * EntityStorageHandler is the single persistence router every blueprint write
 * and every eb_* AI tool goes through. Its routing decides whether an edit is
 * committed to the database or staged in tempstore, and after every successful
 * persist it fires hook_daedalus_blueprint_entity_persisted with the entity, a
 * saved-vs-tempstored flag, and the affected component UUIDs. That hook is a
 * documented contract consumers subscribe to (change-detection timestamps, AI
 * side effects), so its payload is pinned here. This class deliberately runs
 * with neither daedalus_tempstore nor layout_builder enabled, which is the exact
 * configuration where the service must fall through to a direct database save:
 * it proves new=TRUE, force_save=TRUE, and a plain edit (new=FALSE, no
 * tempstore backend) all save directly and all fire the hook with saved=TRUE
 * and the passed UUIDs. It also pins isWorkspaceRequired()=FALSE when the
 * workspaces module is absent (the workspace gate is wired but cannot trip
 * here).
 *
 * BundleListBuilder builds the AI-visible entity-type/bundle catalog that
 * drives eb_list_bundles, the create_entity skill, and schema discovery. Its
 * visibility filtering is the admin control that hides bundles from the AI: a
 * hidden bundle must never reach the LLM. The test proves hidden_bundles drops
 * the named bundle (and drops the whole entity type when that was its last
 * visible bundle), hidden_entity_types removes a type outright, and
 * formatAsMarkdown emits the documented "## Label (id)" / "- bundle: label"
 * shape with the description suffix appended only when the bundle actually has
 * a description.
 *
 * Both live in fat methods over one setUp because reinstalling Drupal per
 * method is the slow part of a kernel run. The fixture module records
 * persist-hook invocations into state so the hook payload is observable without
 * holding the entity across the hook boundary.
 */
#[CoversClass(EntityStorageHandler::class)]
#[CoversClass(BundleListBuilder::class)]
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class EntityStorageAndBundleListTest extends BlueprintKernelTestBase {

  /**
   * Relaxed schema checking for a sibling module's settings.
   *
   * The daedalus_blueprint_ai.settings config that drives BundleListBuilder's
   * visibility filtering is owned by the daedalus_blueprint_ai sub-module, which
   * is not installed here (the base module's BundleListBuilder reads the config
   * defensively whether or not that module is present). Relax schema checking
   * so the test can write that config directly; this does not weaken any
   * behavioral assertion, it only skips schema validation of a sibling module's
   * settings object.
   *
   * @var bool
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * Enables the fixture so persist-hook invocations are recorded in state.
   */
  protected function setUp(): void {
    parent::setUp();
    $this->enableModules(['daedalus_blueprint_test']);
    \Drupal::state()->set('daedalus_blueprint_test.persisted', []);
  }

  /**
   * Tests direct-save routing, the persist payload, and the workspace gate.
   */
  public function testPersistRoutesToDatabaseAndFiresHook(): void {
    /** @var \Drupal\daedalus_blueprint\EntityStorageHandler $handler */
    $handler = $this->container->get('daedalus_blueprint.storage_handler');

    // No workspaces module installed: the gate is wired but never trips.
    $this->assertFalse($handler->isWorkspaceRequired());

    // new=TRUE: a freshly created entity always saves directly, regardless of
    // tempstore availability, and fires the hook with saved=TRUE.
    $newEntity = $this->entityTypeManager->getStorage('entity_test')->create([
      'name' => 'New entity',
    ]);
    $handler->persist($newEntity, is_new: TRUE, element_uuids: ['uuid-new']);
    $this->assertNotNull($newEntity->id(), 'New entity received an ID, so it was saved to the database.');

    // force_save=TRUE on an existing entity bypasses any tempstore and commits.
    $existing = $this->createTestEntity(['field_subtitle' => 'before']);
    $existing->set('field_subtitle', 'after');
    $handler->persist($existing, force_save: TRUE, element_uuids: ['uuid-a', 'uuid-b']);
    $reloaded = $this->entityTypeManager
      ->getStorage('entity_test')
      ->loadUnchanged($existing->id());
    $this->assertSame('after', $reloaded->get('field_subtitle')->value);

    // new=FALSE with no tempstore backend available also saves directly: the
    // storage handler has neither daedalus_tempstore nor layout_builder here, so it
    // falls through to a database save and reports saved=TRUE.
    $editOnly = $this->createTestEntity(['field_subtitle' => 'one']);
    $editOnly->set('field_subtitle', 'two');
    $handler->persist($editOnly, element_uuids: []);
    $reloadedEdit = $this->entityTypeManager
      ->getStorage('entity_test')
      ->loadUnchanged($editOnly->id());
    $this->assertSame('two', $reloadedEdit->get('field_subtitle')->value);

    // The persist hook fired once per call, each time with saved=TRUE (every
    // path here is a direct save) and the element UUIDs handed to persist().
    $log = \Drupal::state()->get('daedalus_blueprint_test.persisted', []);
    $this->assertCount(3, $log);

    $this->assertSame('entity_test', $log[0]['entity_type']);
    $this->assertTrue($log[0]['saved']);
    $this->assertSame(['uuid-new'], $log[0]['element_uuids']);

    $this->assertTrue($log[1]['saved']);
    $this->assertSame(['uuid-a', 'uuid-b'], $log[1]['element_uuids']);

    $this->assertTrue($log[2]['saved']);
    $this->assertSame([], $log[2]['element_uuids']);
  }

  /**
   * Tests bundle visibility filtering and the markdown shape.
   */
  public function testBundleListVisibilityAndMarkdown(): void {
    // Add a second entity_test_rev field so it remains fieldable/listed, and a
    // block_content type carrying a description so the markdown description
    // suffix path is exercised. block_content + block are enabled without
    // layout_builder, keeping this independent of the LB storage routing.
    $this->enableModules(['block', 'block_content']);
    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);
    $this->entityTypeManager = $this->container->get('entity_type.manager');

    $this->entityTypeManager->getStorage('block_content_type')->create([
      'id' => 'hero',
      'label' => 'Hero',
      'description' => 'A hero banner block.',
      'revision' => TRUE,
    ])->save();
    $this->entityTypeManager->getStorage('block_content_type')->create([
      'id' => 'plain',
      'label' => 'Plain',
      'revision' => TRUE,
    ])->save();

    /** @var \Drupal\daedalus_blueprint\BundleListBuilder $builder */
    $builder = $this->container->get('daedalus_blueprint.bundle_list_builder');

    // Baseline: with no hidden config, block_content and its two bundles are
    // visible, and entity_test_rev is present.
    $visible = $builder->getVisibleBundles();
    $this->assertArrayHasKey('block_content', $visible);
    $this->assertArrayHasKey('hero', $visible['block_content']['bundles']);
    $this->assertArrayHasKey('plain', $visible['block_content']['bundles']);
    $this->assertArrayHasKey('entity_test_rev', $visible);

    // Hide the only entity_test_rev bundle: filtering must drop the whole
    // entity type, since hiding its last bundle leaves nothing to advertise.
    // Also hide one of block_content's two bundles: the type stays, that bundle
    // is gone. Hide an entire type outright via hidden_entity_types.
    \Drupal::configFactory()
      ->getEditable('daedalus_blueprint_ai.settings')
      ->set('hidden_entity_types', ['user'])
      ->set('hidden_bundles', [
        'entity_test_rev' => ['entity_test_rev'],
        'block_content' => ['plain'],
      ])
      ->save();

    $filtered = $builder->getVisibleBundles();
    $this->assertArrayNotHasKey('user', $filtered, 'hidden_entity_types removed the type outright.');
    $this->assertArrayNotHasKey('entity_test_rev', $filtered, 'Hiding the last bundle dropped the whole type.');
    $this->assertArrayHasKey('block_content', $filtered, 'block_content survives: it still has a visible bundle.');
    $this->assertArrayNotHasKey('plain', $filtered['block_content']['bundles']);
    $this->assertArrayHasKey('hero', $filtered['block_content']['bundles']);

    // isHidden() is the enforcement seam the AI tools consult: hidden types
    // and bundles are refused by every tool, not merely unlisted. Its answer
    // must track the filtered list exactly.
    $this->assertTrue($builder->isHidden('user'), 'A config-hidden type is hidden.');
    $this->assertTrue($builder->isHidden('entity_test_rev'), 'A type whose last bundle is hidden is hidden outright.');
    $this->assertFalse($builder->isHidden('block_content'), 'A type with a surviving bundle is not type-hidden.');
    $this->assertTrue($builder->isHidden('block_content', 'plain'), 'The hidden bundle is bundle-hidden.');
    $this->assertFalse($builder->isHidden('block_content', 'hero'), 'The surviving bundle is not hidden.');
    // Targets outside the catalog are never "hidden": unknown bundles and
    // non-fieldable types get the tools' own suitability guards instead.
    $this->assertFalse($builder->isHidden('block_content', 'nonexistent'));
    $this->assertFalse($builder->isHidden('entity_view_display'));
    $this->assertFalse($builder->isHidden('no_such_type'));

    // The markdown reflects the filtered list with the documented shape.
    $markdown = $builder->formatAsMarkdown();
    $this->assertStringContainsString('## Content block (block_content)', $markdown);
    // The hero bundle line carries its description after a non-em-dash hyphen.
    $this->assertStringContainsString('- hero: Hero — A hero banner block.', $markdown);
    // The hidden bundle never reaches the markdown handed to the LLM.
    $this->assertStringNotContainsString('plain: Plain', $markdown);
    // The hidden entity type is absent.
    $this->assertStringNotContainsString('(user)', $markdown);

    // A bundle without a description has no suffix: assert entity_test's line
    // is exactly "- entity_test: <label>" with nothing appended.
    $this->assertMatchesRegularExpression(
      '/^- entity_test: [^\n]*[^—\n]$/m',
      $markdown,
    );
  }

}
