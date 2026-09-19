<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\SkillType;

/**
 * Composes a skill response from its definition and invocation parameters.
 *
 * Each skill type turns a skill definition — plus any arguments the LLM passed
 * when invoking it — into the instruction text returned to the LLM, pulling in
 * whatever dynamic context (bundle catalog, the current entity's
 * bundle-specific guidance, etc.) is appropriate to that type.
 */
interface SkillTypeInterface {

  /**
   * Composes the skill response text.
   *
   * @param array $skill_definition
   *   The skill definition (id, label, description, type, instructions).
   * @param array $parameters
   *   Invocation parameters from the LLM's tool call (e.g. an 'args' string).
   *   Skill types interpret these as they see fit; some ignore them.
   *
   * @return string
   *   The composed instruction text returned as the tool result.
   */
  public function compose(array $skill_definition, array $parameters): string;

}
