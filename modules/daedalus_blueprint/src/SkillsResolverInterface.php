<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint;

/**
 * Resolves skill definitions from YAML discovery across modules.
 */
interface SkillsResolverInterface {

  /**
   * Gets the skill definitions that exist for the acting account.
   *
   * @return array
   *   Skill definitions keyed by skill ID. Each definition contains:
   *   - label: string
   *   - description: string
   *   - type: string (skill type plugin ID)
   *   - instructions: string
   *   - permission: (optional) string. A permission the acting account
   *     must hold for the skill to exist for the request; a skill gated
   *     this way is neither listed nor invokable without it.
   *   - design_system: string|null. The design system the skill is a
   *     member of (resolved at discovery: the file's key, else the
   *     shipping module's packaged system), or NULL for a global skill.
   *     A member of an inactive design system does not exist for the
   *     request either: neither listed nor invokable.
   *   - reframe: bool. Whether invoking the skill is a reframing of the
   *     work (the doctrine the model re-reads before a big build): the
   *     context spine closes the working transcript's segment on such an
   *     invocation once the segment is large enough, so the doctrine lands
   *     near the request instead of under the conversation's bulk. Always
   *     present on a resolved definition; FALSE when the file omits it.
   */
  public function getSkills(): array;

  /**
   * Gets a single skill definition by ID.
   *
   * @param string $id
   *   The skill ID.
   *
   * @return array|null
   *   The skill definition, or NULL if not found.
   */
  public function getSkill(string $id): ?array;

}
