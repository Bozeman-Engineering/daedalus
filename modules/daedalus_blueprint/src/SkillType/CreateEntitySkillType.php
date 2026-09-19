<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\SkillType;

use Drupal\daedalus_blueprint\BundleListBuilder;
use Drupal\daedalus_blueprint\SkillsResolverInterface;

/**
 * Skill type for the create-an-entity workflow.
 *
 * Returns the AI-visible bundle catalog plus a blocking checklist that runs
 * before the LLM creates a new entity. The checklist is kind-blind at the
 * door (identify a bundle) and then forks by kind: a page (any bundle with a
 * layout) routes through schema read, prerequisite skills, a plan and its
 * approval into page_create as ONE page-language document; every other
 * entity routes through schema read into eb_create_entity as a blueprint.
 * Used solely by the create_entity skill: the target entity is chosen inside
 * the workflow rather than fixed by the page the user is on.
 *
 * Other modules extend the checklist by declaring a step on their own skill
 * definition rather than by patching this class: a skill definition may carry
 * a `blocking_step_of` map of workflow id to checklist step text, and every
 * visible skill declaring a `create_entity` step is rendered into the page
 * branch between the schema read and the plan's approval. This keeps
 * daedalus_blueprint page-builder-agnostic: the design doctrine skill (daedalus_stylish)
 * inserts itself here without this module naming it, and what a plan holds
 * (a direction, a signature, what each band wears) is that skill's to say.
 */
class CreateEntitySkillType implements SkillTypeInterface {

  public function __construct(
    protected BundleListBuilder $bundleListBuilder,
    protected SkillsResolverInterface $skillsResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function compose(array $skill_definition, array $parameters): string {
    $parts = [];

    $parts[] = $this->bundleListBuilder->formatAsMarkdown();

    $page_steps = [
      'Call eb_read_schema(entity_type, bundle) to get the structural schema and design guidance for that bundle.',
    ];
    foreach ($this->skillsResolver->getSkills() as $definition) {
      $step = $definition['blocking_step_of']['create_entity'] ?? NULL;
      if (is_string($step) && $step !== '') {
        $page_steps[] = $step;
      }
    }
    $page_steps[] = 'Present your plan to the user and wait for approval.';
    $page_steps[] = 'Call page_create with the entity fields and the whole page as ONE page-language document.';

    $other_steps = [
      'Call eb_read_schema(entity_type, bundle) to get the structural schema and guidance for that bundle.',
      'Call eb_create_entity with the blueprint.',
    ];

    $parts[] = "## Next steps\n\n"
      . "BLOCKING REQUIREMENT: Before calling any creation tool, you must:\n"
      . "1. Identify the correct entity_type and bundle from the list above.\n\n"
      . "Then the workflow forks by kind.\n\n"
      . "If the bundle is a page (any bundle with a layout):\n"
      . $this->numberSteps($page_steps)
      . "\n\nIf it is any other entity:\n"
      . $this->numberSteps($other_steps);

    if (!empty($skill_definition['instructions'])) {
      $parts[] = "## General guidance\n\n" . $skill_definition['instructions'];
    }

    return implode("\n\n", $parts);
  }

  /**
   * Numbers branch steps, continuing after the shared identify-bundle step 1.
   *
   * @param string[] $steps
   *   The branch's steps in order.
   *
   * @return string
   *   The steps numbered from 2, one per line.
   */
  protected function numberSteps(array $steps): string {
    $checklist = [];
    foreach ($steps as $i => $step) {
      $checklist[] = ($i + 2) . '. ' . $step;
    }
    return implode("\n", $checklist);
  }

}
