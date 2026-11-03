<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Tool;

/**
 * The prose the AI tools' shared parameters state from ONE source.
 *
 * The entity_type, entity_id and langcode parameters name the same thing
 * on every tool card that takes them (the page tools, the JSON door's
 * tools, daedalus_stylish's explain tool), so each is one sentence here,
 * referenced from the tools' FunctionCall attributes as a class-constant
 * expression (the Edit.php pattern: an attribute argument must be a
 * literal or a constant expression), never retyped per card (the
 * MaterialsProse precedent; seven cards once carried seven spellings of
 * the entity type parameter, each with its own example list). A card
 * whose parameter means more than the shared fact (page_read's template
 * and blueprint spellings) appends its own clause after the constant.
 * Lives in daedalus_blueprint because every tool-bearing module depends
 * on it, and daedalus_stylish depends on daedalus_blueprint and not on
 * daedalus_blueprint_ai.
 */
final class ParameterProse {

  /**
   * The entity_type parameter.
   */
  public const ENTITY_TYPE = 'The entity type ID (e.g. node).';

  /**
   * The entity_id parameter: numeric for content, a machine name for config.
   */
  public const ENTITY_ID = 'The entity ID: numeric for a content entity, the machine name for a config entity.';

  /**
   * The langcode parameter: the translation to answer.
   */
  public const LANGCODE = 'The language code of the translation to answer; empty for the default language.';

}
