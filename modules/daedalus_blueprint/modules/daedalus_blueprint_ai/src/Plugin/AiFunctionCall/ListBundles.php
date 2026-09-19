<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall;

use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\daedalus_blueprint\BundleListBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists available entity types and their bundles.
 */
#[FunctionCall(
  id: 'daedalus_blueprint:list_bundles',
  function_name: 'eb_list_bundles',
  name: 'List Bundles',
  description: 'Lists available entity types and their bundles (content types, block types, etc.) with descriptions. Use this to discover what entity types and bundles exist before reading schemas or creating entities.',
  group: 'information_tools',
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      required: FALSE,
      description: new TranslatableMarkup('Filter to a specific entity type (e.g. node, block_content). Omit to list all fieldable entity types.'),
    ),
  ],
)]
class ListBundles extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The bundle list builder.
   */
  protected BundleListBuilder $bundleListBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->bundleListBuilder = $container->get('daedalus_blueprint.bundle_list_builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $entity_type_filter = $this->getContextValue('entity_type');

    if ($entity_type_filter) {
      $bundle_list = $this->bundleListBuilder->getVisibleBundles();
      if (!isset($bundle_list[$entity_type_filter])) {
        $this->stringOutput = "Unknown or hidden entity type: $entity_type_filter";
        return;
      }
      $type_data = $bundle_list[$entity_type_filter];
      $lines = ["## {$type_data['label']} ({$entity_type_filter})"];
      foreach ($type_data['bundles'] as $bundle_id => $bundle_data) {
        $line = "- **{$bundle_id}**: {$bundle_data['label']}";
        if (!empty($bundle_data['description'])) {
          $line .= " — {$bundle_data['description']}";
        }
        $lines[] = $line;
      }
      $this->stringOutput = implode("\n", $lines);
      return;
    }

    $this->stringOutput = $this->bundleListBuilder->formatAsMarkdown();
  }

}
