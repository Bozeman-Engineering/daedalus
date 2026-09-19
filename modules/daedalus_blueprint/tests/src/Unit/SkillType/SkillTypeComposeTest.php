<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Unit\SkillType;

use Drupal\daedalus_blueprint\BundleListBuilder;
use Drupal\daedalus_blueprint\SkillsResolverInterface;
use Drupal\daedalus_blueprint\SkillType\CreateEntitySkillType;
use Drupal\daedalus_blueprint\SkillType\DefaultSkillType;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the string composition of the create_entity and default skill types.
 *
 * A skill type turns a skill definition into the instruction text the LLM
 * receives from the eb_skill tool. These are pure string builders with no
 * container, so they are unit-tested here.
 *
 * CreateEntitySkillType is what the create_entity skill returns. Its behavioral
 * value lives entirely in the assembled string: (1) the AI-visible bundle
 * catalog (so the LLM discovers which entity types/bundles exist), and (2) the
 * BLOCKING REQUIREMENT checklist: a shared identify-bundle step, then a fork by
 * kind. The page branch (any bundle with a layout) runs read schema, any skill
 * that declared itself a blocking step via `blocking_step_of`, the plan's
 * approval, and ends at page_create with ONE page-language document; the
 * non-page branch runs read schema and ends at eb_create_entity with a
 * blueprint. If a refactor dropped the formatAsMarkdown() call or changed the
 * array glue so the checklist vanished, the AI would start creating entities
 * without discovering bundles or pausing for approval, and no other test would
 * catch it. We assert the catalog, the fork, and both branch endpoints are
 * present (the fork is what makes the skill kind-blind: one discovery door
 * routing pages to page_create and everything else to eb_create_entity), that a
 * skill declaring a create_entity blocking step is rendered into the page
 * branch between the schema read and the plan's approval (the extension seam
 * the design doctrine skill in daedalus_stylish rides; daedalus_blueprint itself must
 * never name that skill, and it never states what a plan holds: the doctrine is
 * the declaring skill's, so no design vocabulary lives in this checklist), and
 * that the optional "## General guidance" section is appended only when the
 * definition carries instructions (it must be absent when instructions are
 * empty, so an empty skill never emits a dangling header).
 *
 * DefaultSkillType is the verbatim passthrough used by skills that need no
 * dynamic composition; we pin that it returns the instructions unchanged and an
 * empty string (never NULL) when the key is missing, since the eb_skill tool
 * assigns the return straight into a string output.
 *
 * The BundleListBuilder is mocked to a sentinel string: this test is about the
 * skill type's composition, not the builder (which has its own kernel
 * coverage), so the only thing that matters is that the catalog text flows
 * through verbatim.
 */
#[CoversClass(CreateEntitySkillType::class)]
#[CoversClass(DefaultSkillType::class)]
#[Group('daedalus_blueprint')]
class SkillTypeComposeTest extends UnitTestCase {

  /**
   * Builds the skill type with a sentinel catalog and a skill roster.
   *
   * @param string $catalog
   *   What the mocked BundleListBuilder returns.
   * @param array $skills
   *   What the mocked SkillsResolver returns from getSkills().
   */
  protected function createSkillType(string $catalog, array $skills = []): CreateEntitySkillType {
    $bundleListBuilder = $this->createMock(BundleListBuilder::class);
    $bundleListBuilder->method('formatAsMarkdown')->willReturn($catalog);

    $skillsResolver = $this->createMock(SkillsResolverInterface::class);
    $skillsResolver->method('getSkills')->willReturn($skills);

    return new CreateEntitySkillType($bundleListBuilder, $skillsResolver);
  }

  /**
   * Tests create_entity composition with instructions present.
   */
  public function testCreateEntityComposeIncludesCatalogChecklistAndGuidance(): void {
    $catalog = '## SENTINEL Catalog (sentinel)\n- foo: Foo bundle';
    $skillType = $this->createSkillType($catalog);

    $definition = [
      'id' => 'create_entity',
      'instructions' => 'GENERAL-GUIDANCE-SENTINEL principles to follow.',
    ];
    $output = $skillType->compose($definition, []);

    // The bundle catalog from the (mocked) builder is included verbatim, so the
    // LLM can discover entity types/bundles before creating anything.
    $this->assertStringContainsString($catalog, $output);

    // The blocking orchestration checklist must be present in full: it is the
    // guardrail that stops the AI creating an entity before it has read the
    // schema and obtained user approval of its plan. The checklist itself
    // carries no design doctrine (no concept menu, no page shape): that is
    // the declaring skill's, so a static step here would be a second copy.
    $this->assertStringContainsString('BLOCKING REQUIREMENT', $output);
    $this->assertStringContainsString('1. Identify the correct entity_type and bundle', $output);
    $this->assertStringContainsString('2. Call eb_read_schema(entity_type, bundle)', $output);
    $this->assertStringContainsString('3. Present your plan', $output);
    $this->assertStringContainsString('wait for approval', $output);
    $this->assertStringNotContainsString('concept', $output);

    // The checklist forks by kind after bundle identification: this is what
    // makes the skill kind-blind (one discovery door for both create paths).
    // The page branch must end at page_create as ONE page-language document;
    // the non-page branch must end at eb_create_entity with a blueprint. The
    // page branch's endpoint comes after the approval step, so a page is
    // never created before the user has signed off on the plan.
    $this->assertStringContainsString('the workflow forks by kind', $output);
    $this->assertStringContainsString('If the bundle is a page (any bundle with a layout):', $output);
    $this->assertStringContainsString('4. Call page_create with the entity fields and the whole page as ONE page-language document.', $output);
    $this->assertStringContainsString('If it is any other entity:', $output);
    $this->assertStringContainsString('3. Call eb_create_entity with the blueprint.', $output);
    $this->assertGreaterThan(
      strpos($output, 'wait for approval'),
      strpos($output, 'Call page_create'),
    );

    // Definition instructions are appended under a "## General guidance"
    // heading when present.
    $this->assertStringContainsString('## General guidance', $output);
    $this->assertStringContainsString('GENERAL-GUIDANCE-SENTINEL principles to follow.', $output);
  }

  /**
   * Tests declared blocking steps land in the checklist, in order.
   *
   * A skill definition may declare `blocking_step_of: {create_entity: ...}`;
   * every visible skill declaring one is rendered into the page branch
   * between the schema read and the plan's approval, so a doctrine skill
   * can make itself a blocking step without daedalus_blueprint naming it.
   * Steps declared for other workflows, or with empty/non-string text, must
   * not appear, and a declared step must not leak into the non-page branch
   * (whose numbering stays untouched by declared steps).
   */
  public function testCreateEntityComposeRendersDeclaredBlockingSteps(): void {
    $skillType = $this->createSkillType('## SENTINEL Catalog', [
      // A skill declaring no step (the skins skill's shape) renders nothing.
      'skins' => ['label' => 'Skins'],
      'design' => [
        'label' => 'Design',
        'blocking_step_of' => [
          'create_entity' => 'DESIGN-STEP-SENTINEL Invoke the design skill.',
        ],
      ],
      'other_workflow' => [
        'blocking_step_of' => ['add_section' => 'WRONG-WORKFLOW-SENTINEL'],
      ],
      'empty_step' => [
        'blocking_step_of' => ['create_entity' => ''],
      ],
    ]);

    $output = $skillType->compose(['id' => 'create_entity'], []);

    // The declared step is numbered into the page branch after the schema
    // read and before the plan's approval, which renumbers that branch's
    // tail steps.
    $this->assertStringContainsString('2. Call eb_read_schema(entity_type, bundle)', $output);
    $this->assertStringContainsString('3. DESIGN-STEP-SENTINEL Invoke the design skill.', $output);
    $this->assertStringContainsString('4. Present your plan', $output);
    $this->assertStringContainsString('5. Call page_create with the entity fields and the whole page as ONE page-language document.', $output);

    // The declared step lands in the page branch only: it renders before the
    // non-page branch header, and the non-page branch keeps its unshifted
    // numbering (schema read then eb_create_entity).
    $this->assertGreaterThan(
      strpos($output, 'DESIGN-STEP-SENTINEL'),
      strpos($output, 'If it is any other entity:'),
    );
    $this->assertStringContainsString('3. Call eb_create_entity with the blueprint.', $output);

    $this->assertStringNotContainsString('WRONG-WORKFLOW-SENTINEL', $output);
    // A skill declaring no step is never named: the checklist names
    // exactly the skills that declared themselves blocking.
    $this->assertStringNotContainsString('Skins', $output);
  }

  /**
   * Tests create_entity omits the general guidance section when empty.
   *
   * An empty/absent instructions value must not produce a dangling
   * "## General guidance" header with nothing under it.
   */
  public function testCreateEntityComposeOmitsGuidanceWhenEmpty(): void {
    $catalog = '## SENTINEL Catalog (sentinel)';
    $skillType = $this->createSkillType($catalog);

    // No 'instructions' key at all.
    $outputNoKey = $skillType->compose(['id' => 'create_entity'], []);
    $this->assertStringContainsString($catalog, $outputNoKey);
    $this->assertStringContainsString('BLOCKING REQUIREMENT', $outputNoKey);
    $this->assertStringNotContainsString('## General guidance', $outputNoKey);

    // An explicitly empty instructions string behaves the same.
    $outputEmpty = $skillType->compose(['id' => 'create_entity', 'instructions' => ''], []);
    $this->assertStringNotContainsString('## General guidance', $outputEmpty);
  }

  /**
   * Tests the default skill type returns instructions verbatim.
   */
  public function testDefaultComposeReturnsInstructionsVerbatim(): void {
    $skillType = new DefaultSkillType();

    $instructions = "Do the thing.\n\nThen do the other thing.";
    $this->assertSame(
      $instructions,
      $skillType->compose(['instructions' => $instructions], []),
    );
  }

  /**
   * Tests the default skill type returns '' (not NULL) when no instructions.
   *
   * The eb_skill tool assigns compose() straight into a string property, so a
   * NULL here would be a type error at the boundary; the contract is an empty
   * string for a definition with no instructions.
   */
  public function testDefaultComposeReturnsEmptyStringWhenMissing(): void {
    $skillType = new DefaultSkillType();

    $this->assertSame('', $skillType->compose([], []));
  }

}
