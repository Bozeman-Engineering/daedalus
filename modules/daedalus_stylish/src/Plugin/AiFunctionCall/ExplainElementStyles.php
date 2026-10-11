<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\AiFunctionCall;

use Drupal\ai\Attribute\FunctionCall;
use Drupal\ai\Base\FunctionCallBase;
use Drupal\ai\Service\FunctionCalling\ExecutableFunctionCallInterface;
use Drupal\ai\Service\FunctionCalling\FunctionCallInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\BundleListBuilder;
use Drupal\daedalus_blueprint\Dispatch\BlueprintBackendResolver;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\daedalus_stylish\StyleStack;
use Drupal\daedalus_ui\ElementModel\BlueprintElementAnnotator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Explains one element's effective styles as its layer stack.
 *
 * The AI's read surface for the layered entry model, the same projection
 * the inspector and the code view render (StyleStack): every layer that
 * styles a key in delivery order, bottom to top, in ONE numbered list,
 * the defaults floor (the declaration's own defaults, then the active
 * skin's for the element's scope) beneath the ancestor rules reaching
 * the element (a container's `> pb-text` color, the columns variant's `> *`
 * fill: the selector matcher over the blueprint tree decides which
 * ancestor entries land here), each rule a group of layers (the variants
 * the rule wears, delivered here beneath the rule's own values and named
 * as variants worn by the rule, never by this element) beneath the worn
 * variants beneath the element's own overrides, with each declaration
 * marked winning or lost to the layer above (StyleStack::linesAt()). The
 * model's write tools
 * (update_style, apply_variant, detach_variant) describe the stack in
 * prose; this tool shows the actual one, so "why does this element look
 * like that" and "what would my override shadow" are one read instead of
 * a mental merge of the map, the variant library, the skin and the
 * delivery order. The skin's base:{tag} layer is the READ SUBJECT's: the
 * one element the key reads, its tag from the blueprint tree's markup
 * annotation (StyleStack::subjectTag()); a rule key ("{key}::> pb-text")
 * explains the rule itself, its entry over the variants it wears at
 * every match, floorless, since its matches have no one tag.
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
  description: 'Explains an element\'s effective styles as its layer stack, one numbered list bottom to top: the defaults floor for the element\'s scope (its declaration\'s own defaults, then the active skin\'s), then every ancestor rule reaching the element (a container\'s "> pb-text" or "p" entry landing on this element or inside it), then each worn variant by label with its description in delivery order (a wearing a composition distributed onto the element says which composition it is worn via), then the element\'s own overrides, every property marked winning or lost to a higher layer; the skin\'s base:{tag} layer is the element\'s own tag\'s (a rule key explains the rule itself: its entry over the variants it wears, no tag floor). Read it before overriding a value a variant, an ancestor or the skin supplies, or when a style change does not show; staged changes included.',
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
      description: new TranslatableMarkup("The element's selectable key (container:{uuid}, block:{component_uuid}, field:{entity_uuid}:{field_name}, ...), optionally followed by '::' and a relative selector (::.class-name a declared part, ::p its paragraphs, ::> * its children, ::> pb-text the text blocks among them). A rule key explains the rule itself, not one element: its entry over the variants it wears at every match."),
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
   * The blueprint backend resolver: the entity's serialized sections.
   */
  protected BlueprintBackendResolver $backendResolver;

  /**
   * The element annotator: the `_element` facts and the lean tree.
   */
  protected BlueprintElementAnnotator $annotator;

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
    $instance->backendResolver = $container->get('daedalus_blueprint.backend_resolver');
    $instance->annotator = $container->get('daedalus_ui.element_annotator');
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
    $host = $this->storageHandler->load($entity_type, $entity_id);
    if ($host === NULL) {
      $this->setOutput("Entity $entity_type:$entity_id not found.");
      return;
    }
    if ($this->bundleListBuilder->isHidden($entity_type, $host->bundle())) {
      $this->setOutput('Unknown or hidden bundle: ' . $entity_type . '/' . $host->bundle() . '.');
      return;
    }
    if (!$host->entity()->access('view')) {
      $this->setOutput("Access denied for 'view' on $entity_type:$entity_id.");
      return;
    }
    if (!$host->hasStyleStore()) {
      $this->setOutput(sprintf('Styling is not enabled for %s %s entities.', $host->bundle(), $entity_type));
      return;
    }
    if ($this->validator->parseKey($key) === NULL) {
      $this->setOutput("\"$key\" is not a valid style map key. " . StyleMapValidator::KEY_GRAMMAR_HINT);
      return;
    }

    $map = $host->styleMap();

    // The host resolves the key's scope (a block's bundle, a container's
    // layout) for the floor, the way validation resolves it; its annotated
    // tree answers which ancestor rules reach the key.
    $this->setOutput($this->explain($map, $key, ['host' => $host], $this->tree($host)));
  }

  /**
   * The host's lean node tree, annotated: the selector matcher's input.
   *
   * The same tree the edit-mode client boots with (BlueprintMirror): the
   * backend's serialized blueprint through daedalus_ui's annotator,
   * whose `layout` carries each node's kind, address facts and resolved
   * markup (the root tag a tag compound matches, the tags the prose
   * carries). [] for a host no backend serializes.
   */
  protected function tree(HostInterface $host): array {
    $entity = $host->entity();
    $backend = $this->backendResolver->resolveFor($entity);
    if ($backend === NULL) {
      return [];
    }
    $blueprint = $this->annotator->annotate($backend->serialize($entity), $host, 'full');
    return is_array($blueprint['layout'] ?? NULL) ? $blueprint['layout'] : [];
  }

  /**
   * Formats the key's stack (and its parts') for the model.
   */
  protected function explain(array $map, string $key, array $context, array $tree): string {
    $stack = $this->styleStack->stack($map, $key, $context, NULL, $tree);
    $suffixes = $this->styleStack->partSuffixes($map, $key);

    $worn = in_array(StyleStack::TIER_VARIANT, array_column($stack['layers'], 'tier'), TRUE);
    $bare = !$worn && $this->ownLayer($stack)['styles'] === [];
    if ($bare && $suffixes === []) {
      $lines = [
        "$key carries no styles of its own and wears no variants: only the defaults floor and the ancestor rules below, plus what CSS inherits from its ancestors (e.g. a parent's flex layout), shape it.",
        '',
      ];
      array_push($lines, ...$this->stackLines($stack));
      return implode("\n", $lines);
    }

    $lines = [
      "Style stack for $key (scope {$stack['scope']}), bottom layer first; for any property the TOPMOST layer that sets it wins. The element's own properties are the top layer (overrides): editing writes there, and a lower layer's value shows through only where no higher layer sets the property.",
      "The lowest layers are the defaults floor: the element's declaration's own defaults (the box facts its block type, block plugin, or layout ships; code, not editable), then the active skin's defaults for the element's scope over them. A floor value loses to every layer that sets its property and shows through only where none does. Above the floor sit the ancestor rules reaching this element (an ancestor's selector entry such as \"> pb-text\" or \"p\", edited at that ancestor's key) and the variants those rules wear (delivered here beneath the rule's own values; the rule wears them, this element does not), which every worn variant and own value of this element beats.",
      '',
    ];
    array_push($lines, ...$this->stackLines($stack));
    foreach ($suffixes as $suffix) {
      $part_key = $key . $suffix;
      $lines[] = '';
      $lines[] = $this->suffixHeading($suffix) . " ($part_key):";
      array_push($lines, ...$this->stackLines($this->styleStack->stack($map, $part_key, $context, NULL, $tree)));
    }
    return implode("\n", $lines);
  }

  /**
   * A '::selector' suffix's heading: a part, a generated box, or a rule.
   *
   * A declared part ("::.class-name", one class compound reached by
   * descent) is a piece of the element itself; a generated box
   * ("::&::before" the element's own, "::.quote::after" a part's, "::>
   * pb-text::before" each matched text block's) is a pseudo-element
   * that exists only while the stack delivers `content` there (a box is
   * born with content: "" and disappears when that line is deleted);
   * every other selector (a tag tier, the children, a type, a chain) is
   * a rule the element owns, styling what it reaches (and, wearing a
   * variant, delivering the variant there).
   */
  protected function suffixHeading(string $suffix): string {
    $selector = substr($suffix, strlen(RelativeSelector::SUFFIX));
    if (RelativeSelector::parse($selector)?->pseudo !== NULL) {
      return "Pseudo $selector (a generated box; it exists only while a layer below delivers content, the line it is born with)";
    }
    return ($this->isPart($selector) ? 'Part ' : 'Rule ') . $selector;
  }

  /**
   * Whether a relative selector names a declared part of the element.
   *
   * One unqualified class compound reached by descent, the shape the read
   * subject rule gives a tag of its own (StyleStack::subjectTag()); every
   * other selector is a rule whose matches have no one tag.
   */
  protected function isPart(string $selector): bool {
    $parsed = RelativeSelector::parse($selector);
    return $parsed !== NULL && $parsed->count() === 1
      && $parsed->first()['combinator'] === RelativeSelector::DESCENDANT
      && $parsed->first()['qualifiers'] === []
      && $parsed->first()['kind'] === RelativeSelector::CLASS_PART;
  }

  /**
   * One stack's numbered layer lines, bottom to top, and the tag caveat.
   *
   * Every layer prints in the one idiom (a heading, a condition block per
   * stored condition, a lost mark naming the winner), the floor's layers
   * first so the model knows which declaration and skin blocks answered
   * for the captured scope. When no floor layer exists the list says so
   * before the worn layers. The last line places the skin's base:{tag}
   * tier (baseTierLine()): the layer it is among the ones above, or why
   * none sits beneath.
   */
  protected function stackLines(array $stack): array {
    $layers = $stack['layers'];
    $marks = $this->marks($stack);
    $lines = [];
    if (!$this->hasFloor($stack)) {
      $lines[] = $stack['skin']
        ? "   (no declaration and no active-skin default is declared for {$stack['scope']} at this key)"
        : "   (no declaration default is declared for {$stack['scope']} at this key, and no skin is active)";
    }
    foreach ($layers as $index => $layer) {
      $lines[] = sprintf('%d. %s', $index + 1, $this->layerHeading($layer));
      // The material's own words (its record's description): what the
      // layer is for, so an override is judged against the intent it
      // shadows, not just the values.
      if (is_string($layer['description']) && trim($layer['description']) !== '') {
        $lines[] = '   ' . trim($layer['description']);
      }
      if (!$layer['resolved']) {
        $lines[] = '   (stale: the referenced variant no longer exists; the reference delivers nothing and stays until detached)';
        continue;
      }
      if ($layer['styles'] === []) {
        // An own ancestor layer rides its rule's group even with no
        // values of its own (the map holds no entry at the position, or
        // an empty one): the position is listed for the variant the rule
        // delivers here, the layer beneath it.
        $lines[] = match ($layer['tier']) {
          StyleStack::TIER_ELEMENT => '   (no own properties: nothing shadows the layers below)',
          StyleStack::TIER_ANCESTOR => '   (no values of its own at this position: the rule is listed for the variant it delivers here)',
          default => "   (supplies nothing at {$stack['key']}; its styles land elsewhere, e.g. on its parts or rules, or on other slots)",
        };
        continue;
      }
      foreach ($layer['styles'] as $condition => $properties) {
        $lines[] = "   $condition:";
        foreach ($properties as $property => $value) {
          $line = "     $property: $value;";
          $winner = $marks[$index][$condition][$property];
          if ($winner !== NULL) {
            $line .= '   << lost to ' . $this->layerWinnerName($layers, $winner);
          }
          $lines[] = $line;
        }
      }
    }
    $lines[] = '   ' . $this->baseTierLine($stack);
    return $lines;
  }

  /**
   * The closing line placing the skin's base:{tag} tier.
   *
   * The tier is keyed by the read subject's tag from the blueprint tree
   * (StyleStack::subjectTag()), so when the subject's tag is one the skin
   * carries a block for, the tier is a layer among the ones above and the
   * line names it by number; otherwise the line says why none sits
   * beneath: no skin, no base blocks, a subject whose tag the skin carries
   * no block for (or that the tree names no tag for), or a rule, whose
   * matches have no one tag.
   */
  protected function baseTierLine(array $stack): string {
    if (!$stack['skin']) {
      return 'No skin is active, so no skin tier and no base:{tag} tier sit beneath these.';
    }
    if ($stack['baseTags'] === []) {
      return 'The active skin declares no base:{tag} defaults, so no tag tier sits beneath these.';
    }
    $tags = implode(', ', $stack['baseTags']);
    foreach ($stack['layers'] as $index => $layer) {
      if (str_starts_with((string) $layer['id'], 'skin:base:')) {
        return sprintf("The active skin's base:{tag} defaults for %s resolve by the element's tag from the blueprint tree: %s is layer %d above.", $tags, $layer['scope'], $index + 1);
      }
    }
    if ($stack['relative'] !== '' && !$this->isPart($stack['relative'])) {
      return sprintf("The active skin's base:{tag} defaults for %s resolve by one element's tag; a rule's matches have no one tag, so none sits beneath these.", $tags);
    }
    return sprintf("The active skin's base:{tag} defaults for %s resolve by the element's tag from the blueprint tree; this element's tag is none of them (or the tree names none), so none sits beneath these.", $tags);
  }

  /**
   * Each declaration's winner, per layer, condition and property.
   *
   * The stack's own read (StyleStack::linesAt(), one exact condition at a
   * time) indexed for printing in layer order: NULL where the declaration
   * wins, else the index of the layer it lost to.
   *
   * @return array<int, array<string, array<string, int|null>>>
   *   layer index => condition => property => winning layer index or NULL.
   */
  protected function marks(array $stack): array {
    $conditions = [];
    foreach ($stack['layers'] as $layer) {
      foreach (array_keys($layer['styles']) as $condition) {
        $conditions[$condition] = TRUE;
      }
    }
    $marks = [];
    foreach (array_keys($conditions) as $condition) {
      foreach ($this->styleStack->linesAt($stack, (string) $condition) as $line) {
        $marks[$line['layer']][$condition][$line['property']] = $line['winner'];
      }
    }
    return $marks;
  }

  /**
   * Whether any floor tier delivers at the key.
   */
  protected function hasFloor(array $stack): bool {
    foreach ($stack['layers'] as $layer) {
      if ($layer['tier'] === StyleStack::TIER_DECLARED || $layer['tier'] === StyleStack::TIER_SKIN) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * The element's own layer, always the stack's top.
   */
  protected function ownLayer(array $stack): array {
    return $stack['layers'][array_key_last($stack['layers'])];
  }

  /**
   * The name a lost declaration gives its winning layer.
   */
  protected function layerWinnerName(array $layers, int $index): string {
    $winner = $layers[$index];
    $name = match ($winner['tier']) {
      StyleStack::TIER_ELEMENT => "this element's own value",
      StyleStack::TIER_VARIANT => sprintf('"%s"', $winner['label'] ?? $winner['variant']),
      StyleStack::TIER_ANCESTOR => $this->styleStack->ancestorVariant($winner['id']) === NULL
        ? $this->ancestorRuleName($winner)
        : sprintf('"%s" through %s', $winner['label'] ?? $winner['variant'], $this->ancestorCarrierName($winner)),
      default => $this->tierName($winner),
    };
    return sprintf('%s (layer %d)', $name, $index + 1);
  }

  /**
   * A floor layer's name: whose defaults, for which scope.
   */
  protected function tierName(array $layer): string {
    return $layer['tier'] === StyleStack::TIER_SKIN
      ? "the active skin's defaults for {$layer['scope']}"
      : "the declared defaults of {$layer['scope']} (code, not editable)";
  }

  /**
   * An ancestor layer's rule in words: the rule "{selector}" on {owner}.
   *
   * The selector is the position the layer id names
   * (StyleStack::ancestorRule(), the own and the worn spelling alike),
   * the owner ancestorOwnerName()'s.
   */
  protected function ancestorRuleName(array $layer): string {
    $selector = $this->styleStack->ancestorRule($layer['id'])['selector'] ?? '';
    return sprintf('the rule "%s" on %s', $selector, $this->ancestorOwnerName($layer));
  }

  /**
   * An ancestor layer's owner in words, always with its key.
   *
   * The owner is named by the layer's `owner`: the owner node's
   * `_element.name` minted by daedalus_ui's annotator and riding the
   * tree (the element-naming spine; "Section" for an unrenamed page
   * band, "Container" nested, the custom label when renamed), the same
   * word every other surface reads. Own and worn layers carry it alike.
   * A layer whose owner the tree names no word for reads "the parent
   * element". The key is the one the rule is edited at.
   */
  protected function ancestorOwnerName(array $layer): string {
    return sprintf('%s (%s)', $layer['owner'] ?? 'the parent element', $layer['scope']);
  }

  /**
   * A worn ancestor layer's carrier in words: who wears the variant.
   *
   * The carrier is the layer's own `carrier` address, never the id: the
   * rule whose entry wears the variant ("the rule "> pb-text" on
   * Section (key)"), or the owner itself when its root wears a variant
   * whose "::> pb-text" entry reaches this element ("Section (key) at
   * its root"). A rule's wear delivers at the rule's position or,
   * for the variant's own part entries, at a position continued under
   * it (`pb-text .link`); the position is named when it differs from
   * the carrier's, so the reader sees which of the rule's continued
   * positions the value arrived at.
   */
  protected function ancestorCarrierName(array $layer): string {
    $position = $this->styleStack->ancestorRule($layer['id'])['selector'] ?? '';
    $carrier = $layer['carrier'];
    if ($carrier['selector'] === NULL) {
      return sprintf('%s at its root (its "%s" entry lands here)', $this->ancestorOwnerName($layer), $position);
    }
    $name = sprintf('the rule "%s" on %s', $carrier['selector'], $this->ancestorOwnerName($layer));
    return $carrier['selector'] === $position ? $name : sprintf('%s at "%s"', $name, $position);
  }

  /**
   * A layer's one-line heading.
   */
  protected function layerHeading(array $layer): string {
    if ($layer['tier'] === StyleStack::TIER_ELEMENT) {
      return "This element's own styles (overrides, the top layer)";
    }
    if ($layer['tier'] === StyleStack::TIER_ANCESTOR) {
      // A rule's group: the variants the rule wears beneath the rule's
      // own entry, each worn layer naming the variant and its carrier
      // (this element wears none of them; the carrier's key is where a
      // detach or a wear change happens).
      return $this->styleStack->ancestorVariant($layer['id']) === NULL
        ? sprintf('Inherited from %s: an ancestor\'s selector entry reaching this element, edited at that key', $this->ancestorRuleName($layer))
        : sprintf('Variant %s worn by %s, reaching this element beneath the rule\'s own values (edited at that key)', $this->materialName($layer['variant'], $layer['label']), $this->ancestorCarrierName($layer));
    }
    if ($layer['tier'] !== StyleStack::TIER_VARIANT) {
      return ucfirst($this->tierName($layer));
    }
    $name = $this->materialName($layer['variant'], $layer['label']);
    // A root wearing a composition distributed names its origin: the
    // editor did not apply it here, and switching or removing that
    // composition on its root takes it away again (the wear row's "via"
    // sub-line, in words). A distributed fragment names its slot instead.
    $wearing = match (TRUE) {
      $layer['fragment'] !== '' && $layer['fragment'] !== NULL => sprintf('distributed fragment "%s" of a composition', $layer['fragment']),
      $layer['via'] !== NULL => sprintf('worn via %s', $this->materialName($layer['via'], $layer['viaLabel'])),
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
