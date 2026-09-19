<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\daedalus_blueprint\SkillsResolverInterface;
use Drupal\daedalus_blueprint\SkillType\SkillTypeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Invokes a skill to get domain-specific behavioral guidance.
 */
#[FunctionCall(
  id: 'daedalus_blueprint:skill',
  function_name: 'eb_skill',
  name: 'Skill',
  description: "Loads a skill's behavioral guidance by id before you act on a task it covers. Valid skill ids come from the available-skills <system-reminder>; never guess one.",
  group: 'information_tools',
  context_definitions: [
    'skill' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Skill'),
      required: TRUE,
      description: new TranslatableMarkup('The skill ID to invoke (e.g. create_entity).'),
    ),
    'args' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Arguments'),
      required: FALSE,
      description: new TranslatableMarkup('Optional arguments for the skill.'),
    ),
  ],
)]
class Skill extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The skills resolver.
   */
  protected SkillsResolverInterface $skillsResolver;

  /**
   * The skill type plugin manager.
   */
  protected SkillTypeManager $skillTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->skillsResolver = $container->get('daedalus_blueprint.skills_resolver');
    $instance->skillTypeManager = $container->get('daedalus_blueprint.skill_type_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $skill_id = $this->getContextValue('skill');

    $definition = $this->skillsResolver->getSkill($skill_id);
    if ($definition === NULL) {
      $this->stringOutput = "Unknown skill: {$skill_id}";
      return;
    }

    $type_id = $definition['type'] ?? 'default';
    $type = $this->skillTypeManager->getType($type_id);
    if ($type === NULL) {
      $this->stringOutput = "Unknown skill type: {$type_id}";
      return;
    }

    $parameters = [];
    $args = $this->getContextValue('args');
    if ($args) {
      $parameters['args'] = $args;
    }

    $definition['id'] = $skill_id;
    $this->stringOutput = $type->compose($definition, $parameters);
  }

}
