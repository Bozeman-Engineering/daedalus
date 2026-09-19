<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\BundleListBuilder;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\StyleMapItem;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\daedalus_stylish\StyleStack;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Explains one element's effective styles as its layer stack.
 *
 * The AI's read surface for the layered entry model, the same projection
 * the code view renders (StyleStack): every layer worn at a key in
 * delivery order, bottom to top, with each declaration marked winning or
 * lost to the layer above. The model's write tools (update_style,
 * apply_variant, detach_variant) describe the stack in prose; this tool
 * shows the actual one, so "why does this element look like that" and
 * "what would my override shadow" are one read instead of a mental merge
 * of the map, the variant library, and the delivery order. Beneath the
 * stack it prints the defaults floor the server can enumerate (the
 * declaration's own defaults and the active skin's for the element's
 * scope, StyleStack::floor()), the same floor lines the code view prints
 * for the selected element, and names the skin's base:{tag} tier as the
 * one half that resolves by rendered tag in the browser.
 *
 * Reads through daedalus_blueprint's storage handler, so the staged
 * (tempstore) copy the editor is looking at is what gets explained,
 * matching every other blueprint tool. Extends the ai module's base
 * directly (not DaedalusBlueprintToolBase, which lives in the optional
 * daedalus_blueprint_ai sub-module) so discovery never reflects a class
 * from a module the site may not have enabled; the guards it needs from
 * that base (visibility, workspace, access) are replicated here against
 * the base daedalus_blueprint services daedalus_stylish already depends on.
 */
#[FunctionCall(
  id: 'daedalus_stylish:explain_element_styles',
  function_name: 'explain_element_styles',
  name: 'Explain Element Styles',
  description: 'Explains an element\'s effective styles as its layer stack: each worn variant by label with its description in delivery order (a wearing a composition distributed onto the element says which composition it is worn via), then the element\'s own overrides, every property marked winning or lost to a higher layer; beneath the stack, the defaults floor for the element\'s scope (its declaration\'s own defaults, then the active skin\'s), each value marked lost where a layer above sets it, and the skin\'s base:{tag} tier named as the one part that resolves by rendered tag in the browser. Read it before overriding a value a variant or the skin supplies, or when a style change does not show; staged changes included.',
  group: 'information_tools',
  context_definitions: [
    'entity_type' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity Type'),
      required: TRUE,
      description: new TranslatableMarkup('The host entity type (e.g. node).'),
    ),
    'entity_id' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Entity ID'),
      required: TRUE,
      description: new TranslatableMarkup('The host entity ID.'),
    ),
    'key' => new ContextDefinition(
      data_type: 'string',
      label: new TranslatableMarkup('Style map key'),
      required: TRUE,
      description: new TranslatableMarkup("The element's selectable key (container:{uuid}, block:{component_uuid}, field:{entity_uuid}:{field_name}, ...), optionally followed by ::.class-name for a declared sub-element."),
    ),
  ],
)]
final class ExplainElementStyles extends FunctionCallBase implements ExecutableFunctionCallInterface {

  /**
   * The entity storage handler: the staged-copy read every tool shares.
   */
  protected EntityStorageHandler $storageHandler;

  /**
   * The bundle list builder: the AI-visibility authority.
   */
  protected BundleListBuilder $bundleListBuilder;

  /**
   * The style map validator (key grammar).
   */
  protected StyleMapValidator $validator;

  /**
   * The stack projection.
   */
  protected StyleStack $styleStack;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): FunctionCallInterface|static {
    $instance = new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('ai.context_definition_normalizer'),
      $container->get('plugin.manager.ai_data_type_converter'),
    );
    $instance->storageHandler = $container->get('daedalus_blueprint.storage_handler');
    $instance->bundleListBuilder = $container->get('daedalus_blueprint.bundle_list_builder');
    $instance->validator = $container->get('daedalus_stylish.style_map_validator');
    $instance->styleStack = $container->get('daedalus_stylish.style_stack');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $entity_type = (string) $this->getContextValue('entity_type');
    $entity_id = (string) $this->getContextValue('entity_id');
    $key = (string) $this->getContextValue('key');

    if ($this->bundleListBuilder->isHidden($entity_type)) {
      $this->setOutput("Unknown or hidden entity type: $entity_type.");
      return;
    }
    if ($this->storageHandler->isWorkspaceRequired()) {
      $this->setOutput('An active workspace is required. Switch to one with tool__workspace_switch, or create one with tool__workspace_create_and_switch; ask the user which.');
      return;
    }
    $entity = $this->storageHandler->load($entity_type, $entity_id);
    if (!$entity) {
      $this->setOutput("Entity $entity_type:$entity_id not found.");
      return;
    }
    if ($this->bundleListBuilder->isHidden($entity_type, $entity->bundle())) {
      $this->setOutput('Unknown or hidden bundle: ' . $entity_type . '/' . $entity->bundle() . '.');
      return;
    }
    if (!$entity->access('view')) {
      $this->setOutput("Access denied for 'view' on $entity_type:$entity_id.");
      return;
    }
    if (!$entity->hasField(DaedalusStylishFieldManager::FIELD_NAME)) {
      $this->setOutput(sprintf('Styling is not enabled for %s %s entities.', $entity->bundle(), $entity_type));
      return;
    }
    if ($this->validator->parseKey($key) === NULL) {
      $this->setOutput("\"$key\" is not a valid style map key. Keys are selectable keys: entity:{uuid}, field:{uuid}:{field_name}, container:{uuid}, block:{component_uuid}, or block:{component_uuid}:{field_name}, optionally followed by ::.class-name for a sub-element.");
      return;
    }

    $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    $map = $item instanceof StyleMapItem ? $item->getStyleMap() : [];

    // The host entity resolves the key's scope (a block's bundle, a
    // container's layout) for the floor, the way validation resolves it.
    $this->setOutput($this->explain($map, $key, ['entity' => $entity]));
  }

  /**
   * Formats the key's stack and floor (and its parts') for the model.
   */
  protected function explain(array $map, string $key, array $context): string {
    $layers = $this->styleStack->layers($map, $key);
    $suffixes = $this->styleStack->partSuffixes($map, $key);
    $floor = $this->styleStack->floor($map, $key, $context);

    $worn = count($layers) > 1;
    $bare = !$worn && !array_filter(array_column($layers, 'styles'));
    if ($bare && $suffixes === []) {
      $lines = [
        "$key carries no styles of its own and wears no variants: only the defaults floor below and styling inherited from ancestors (e.g. a parent's flex layout) shape it.",
        '',
      ];
      array_push($lines, ...$this->floorLines($floor, $layers, $key));
      return implode("\n", $lines);
    }

    $lines = [
      "Style stack for $key, bottom layer first; for any property the TOPMOST layer that sets it wins. The element's own properties are the top layer (overrides): editing writes there, and a lower layer's value shows through only where no higher layer sets the property.",
      "Beneath the stack sits the defaults floor, listed after it: the element's declaration's own defaults (the box facts its block type, block plugin, or layout ships; code, not editable), then the active skin's defaults for the element's scope over them. A floor value loses to every layer that sets its property and shows through only where none does.",
      '',
    ];
    array_push($lines, ...$this->stackLines($layers, $key));
    $lines[] = '';
    array_push($lines, ...$this->floorLines($floor, $layers, $key));
    foreach ($suffixes as $suffix) {
      $part_key = $key . $suffix;
      $part_layers = $this->styleStack->layers($map, $part_key);
      $lines[] = '';
      $lines[] = "Sub-element $part_key:";
      array_push($lines, ...$this->stackLines($part_layers, $part_key));
      $lines[] = '';
      array_push($lines, ...$this->floorLines($this->styleStack->floor($map, $part_key, $context), $part_layers, $part_key));
    }
    return implode("\n", $lines);
  }

  /**
   * One key's defaults floor, tier by tier, and the tag-tier caveat.
   *
   * Printed beneath the numbered stack in the stack's own idiom (a
   * condition block per tier, a lost mark naming the winner), with the
   * captured scope in the heading so the model knows which declaration
   * and skin blocks answered. The last line states the base:{tag} half
   * honestly: the tags the skin carries, resolved by rendered tag in the
   * browser (the code view prints that tier for the selected element).
   */
  protected function floorLines(array $floor, array $layers, string $key): array {
    $lines = ["Defaults floor for $key (scope {$floor['scope']}), beneath every layer above:"];
    if ($floor['tiers'] === []) {
      $lines[] = $floor['skin']
        ? "   (no declaration and no active-skin default is declared for {$floor['scope']} at this key)"
        : "   (no declaration default is declared for {$floor['scope']} at this key, and no skin is active)";
    }
    foreach ($floor['tiers'] as $tier) {
      $lines[] = '   ' . ucfirst($this->tierName($tier)) . ':';
      foreach ($tier['styles'] as $condition => $properties) {
        $lines[] = "     $condition:";
        foreach ($properties as $property => $declaration) {
          $line = "       $property: {$declaration['value']};";
          if ($declaration['lost']) {
            $winner = $declaration['winner'];
            $line .= '   << lost to ' . (isset($winner['layer'])
              ? $this->layerWinnerName($layers, $winner['layer'])
              : $this->tierName($floor['tiers'][$winner['tier']]) . ' (floor)');
          }
          $lines[] = $line;
        }
      }
    }
    $lines[] = '   ' . match (TRUE) {
      !$floor['skin'] => 'No skin is active, so no skin tier and no base:{tag} tier sit beneath these.',
      $floor['base_tags'] === [] => 'The active skin declares no base:{tag} defaults, so no tag-resolved tier sits beneath these.',
      default => sprintf('Beneath these, the active skin\'s base:{tag} defaults for %s apply by the element\'s RENDERED tag, a browser fact this read cannot resolve; the code view prints that tier for the selected element.', implode(', ', $floor['base_tags'])),
    };
    return $lines;
  }

  /**
   * A floor tier's name: whose defaults, for which scope.
   */
  protected function tierName(array $tier): string {
    return $tier['tier'] === 'skin'
      ? "the active skin's defaults for {$tier['scope']}"
      : "the declared defaults of {$tier['scope']} (code, not editable)";
  }

  /**
   * One stack's numbered layer lines, bottom to top.
   */
  protected function stackLines(array $layers, string $key): array {
    $lines = [];
    foreach ($layers as $index => $layer) {
      $lines[] = sprintf('%d. %s', $index + 1, $this->layerHeading($layer));
      // The material's own words (its record's description): what the
      // layer is for, so an override is judged against the intent it
      // shadows, not just the values.
      if (is_string($layer['description'] ?? NULL) && trim($layer['description']) !== '') {
        $lines[] = '   ' . trim($layer['description']);
      }
      if (!$layer['resolved']) {
        $lines[] = '   (stale: the referenced variant no longer exists; the reference delivers nothing and stays until detached)';
        continue;
      }
      if ($layer['styles'] === []) {
        $lines[] = $layer['variant'] === NULL
          ? '   (no own properties: nothing shadows the layers below)'
          : "   (supplies nothing at $key; its styles land elsewhere, e.g. on sub-elements or other slots)";
        continue;
      }
      foreach ($layer['styles'] as $condition => $properties) {
        $lines[] = "   $condition:";
        foreach ($properties as $property => $declaration) {
          $line = "     $property: {$declaration['value']};";
          if ($declaration['lost']) {
            $line .= '   << lost to ' . $this->layerWinnerName($layers, $declaration['winner']);
          }
          $lines[] = $line;
        }
      }
    }
    return $lines;
  }

  /**
   * The name a lost declaration gives its winning stack layer.
   */
  protected function layerWinnerName(array $layers, int $index): string {
    $winner = $layers[$index];
    return $winner['variant'] === NULL
      ? sprintf('this element\'s own value (layer %d)', $index + 1)
      : sprintf('"%s" (layer %d)', $winner['label'] ?? $winner['variant'], $index + 1);
  }

  /**
   * A layer's one-line heading.
   */
  protected function layerHeading(array $layer): string {
    if ($layer['variant'] === NULL) {
      return "This element's own styles (overrides, the top layer)";
    }
    $name = $this->materialName($layer['variant'], $layer['label']);
    // A root wearing a composition distributed names its origin: the
    // editor did not apply it here, and switching or removing that
    // composition on its root takes it away again (the wear row's "via"
    // sub-line, in words). A distributed fragment names its slot instead.
    $wearing = match (TRUE) {
      $layer['fragment'] !== '' && $layer['fragment'] !== NULL => sprintf('distributed fragment "%s" of a composition', $layer['fragment']),
      $layer['via'] !== NULL => sprintf('worn via %s', $this->materialName($layer['via'], $layer['via_label'])),
      default => 'worn at the variant root',
    };
    $axis = $layer['axis'] !== NULL ? sprintf('%s axis, ', $layer['axis']) : '';
    return sprintf('Variant %s, %s%s', $name, $axis, $wearing);
  }

  /**
   * A material's name for the reader: "Label" (machine_name), or the name.
   *
   * The quoted label with the machine name in parentheses when the label
   * adds something; the bare machine name in quotes when it does not (no
   * label, or the label spells the name).
   */
  protected function materialName(string $machine_name, ?string $label): string {
    return $label !== NULL && $label !== $machine_name
      ? sprintf('"%s" (%s)', $label, $machine_name)
      : sprintf('"%s"', $machine_name);
  }

}
