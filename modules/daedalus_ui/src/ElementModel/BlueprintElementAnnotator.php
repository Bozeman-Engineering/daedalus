<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\NodeTree\NodeTreeBuilder;
use Drupal\layout_builder\Section;
use Drupal\daedalus_ui\Presentation\Presentation;

/**
 * Annotates a DELIVERED blueprint with the element model's decisions.
 *
 * The blueprint is the stored-data model; the element model is page-builder
 * vocabulary, so it is attached only at daedalus_ui's delivery seams,
 * the drupalSettings boot payload (EntityViewAlter::attachBlueprintMirror)
 * and the mutation response (BlueprintElementAnnotations subscriber):
 * never persisted, never visible to headless/AI consumers of base
 * daedalus_blueprint, whose blueprint data stays annotation-free.
 *
 * ONE annotation, `_element`, rides every owning node, the root blueprint
 * included, mirroring the declaration grammar so the client reads the same
 * vocabulary developers author:
 * - presentation: Presentation::toArray() ({mode, label?, edit_target?}).
 *   Components get the bundle tier of the one model; the root gets the
 *   derived entity rule (its label field presents AS the entity row, so the
 *   root's edit_target names it — ElementModel::rootPresentation).
 * - composed: TRUE when the block type declares `composed: true`; an
 *   absent key means the sandwich. Compose is a block-TYPE fact, so plugin
 *   components (system/field blocks) never carry it.
 * - fields: {name: {element: false} | {presentation: 'unit'} |
 *   {markup: {instances?, tags?}}}, only non-default facts, so an absent entry
 *   means "exists, presents open". `element: false` is the EXISTENCE axis
 *   (owner declarations plus the registered feeders: display-hidden inference
 *   for inline blocks, preserved-fields for roots on LB displays): nothing
 *   answers to the key in ANY view, so the projection folds the row
 *   unconditionally. Field presentation cannot ride the field nodes themselves
 *   because scalar field values (a plain string) carry no keys. Inline entities
 *   (paragraphs) are not annotated; they present open.
 * - markup: the INSTANCE tier of the page-elements model, the element
 *   resolver's concrete tree for this node's data ({tag, attributes,
 *   classes, hoisted, parts, slots}, see ElementResolver), the canonical
 *   answer to "what does THIS element render as". Stamped on the root
 *   (entity kind), on every section node (the layout's effective
 *   declaration at the section's nesting depth; sections gain their first
 *   `_element` for it), and on every inline-block component whose
 *   block_content entity the root's section data resolves (the effective
 *   block-type declaration against that entity). A component whose entity
 *   cannot be resolved (a dangling revision) carries NO markup: the
 *   annotation only claims what the data can back, and client presence
 *   reads degrade to the declared sketch. Client consumers (part
 *   presence, drag's container drop surface, daedalus_stylish's layout-context
 *   facts) read this annotation instead of scraping the DOM; the canvas
 *   is a projection of it, verified, not sourced, by observation.
 * - markup.instances: minted rich-text instance elements, content-owned
 *   elements wearing an identity class from a declared instance namespace
 *   (the spine's top-level `instances:` declarations,
 *   PageBuilderDeclarations::getInstances()), enumerated from the stored
 *   HTML of the owner's rich-text field values (any field whose storage
 *   exposes a filter_format `format` property, the same rule the minting
 *   walk uses). Each instance is {class, tag, label, text?, children},
 *   first carrier in document order owning its class (the minter's
 *   ownership rule), nested carriers nesting. Instances ride the COMPOSED
 *   block's own markup (its render is one element; the fields have no
 *   elements of their own) and, for open renders, the owning node's
 *   per-field `fields.NAME.markup.instances`, mirroring where the
 *   carriers' innermost selectable element lands in the DOM. Labels are
 *   stamped here from the namespace's declared tag vocabulary (the one
 *   truth), so no client carries a tag-vocabulary copy.
 * - markup.tags: the distinct bare tag names the same rich-text values
 *   contain (every element under the parsed body, minted or not, first
 *   occurrence order), riding wherever `instances` rides and stamped by
 *   the same rule (present only when non-empty). Carriers answer identity
 *   and labels; this answers PRESENCE for tags that never mint (a link,
 *   inline code, emphasis), so a client gating base's tag vocabulary on
 *   the annotation can offer `a` on a block whose prose links without any
 *   carrier wearing `a`. Plain-text values (no elements) stamp nothing.
 *
 * Only inline blocks are fieldable bundles the model can decide for; plugin
 * components stay unannotated — they present open, exactly as attribution
 * stamps nothing on them, and their bespoke templates keep DOM authority
 * (presence reads answer "unknown" and surfaces degrade to declarations).
 */
final class BlueprintElementAnnotator {

  /**
   * Instance-excerpt cap, in characters.
   *
   * The client's content-label convention truncates at 80; the stamped
   * text only needs to cover that with headroom, not duplicate whole
   * documents into the payload.
   */
  private const TEXT_CAP = 120;

  /**
   * The component entities the current annotate() call resolved.
   *
   * Component uuid => block_content entity, built once per call from the
   * root entity's section data (the staged state the blueprint was
   * serialized from).
   *
   * @var array<string, \Drupal\Core\Entity\FieldableEntityInterface>
   */
  private array $componentEntities = [];

  /**
   * The declared instance namespaces for the current call, keyed by prefix.
   *
   * From the spine's `instances:` declarations ('.s-*' => prefix 's-'):
   * the namespace is declared once at the spine because carrier ownership
   * varies by render shape (composed vs open) while the vocabulary must
   * not. Each entry is the namespace's declaration; its `tags` map is the
   * label source for enumerated rows.
   *
   * @var array<string, array>
   */
  private array $instanceNamespaces = [];

  public function __construct(
    private readonly ElementModel $elementModel,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly PageBuilderDeclarations $declarations,
    private readonly ElementResolver $resolver,
    private readonly DaedalusLayoutAdapter $daedalusLayoutAdapter,
    private readonly NodeTreeBuilder $nodeTreeBuilder,
  ) {}

  /**
   * Annotates a delivered blueprint in place and returns it.
   *
   * @param array $blueprint
   *   The serialized blueprint (full mode) of the root entity.
   * @param \Drupal\Core\Entity\EntityInterface $root
   *   The root entity the blueprint was serialized from — supplies the
   *   entity type and bundle for the root field definitions, and the
   *   section data the component entities resolve from.
   * @param string $view_mode
   *   The view mode the root renders with — the display the root existence
   *   feeders (preserved fields) speak for.
   *
   * @return array
   *   The annotated blueprint.
   */
  public function annotate(array $blueprint, EntityInterface $root, string $view_mode): array {
    $this->componentEntities = $root instanceof FieldableEntityInterface
      ? $this->componentEntitiesOf($root)
      : [];
    $this->instanceNamespaces = $this->instanceNamespacesOf();
    if ($root instanceof FieldableEntityInterface) {
      $annotation = [
        'presentation' => $this->elementModel
          ->rootPresentation($root->getEntityTypeId(), $root->bundle(), $view_mode)
          ->toArray(),
        'markup' => $this->resolver->resolve(
          $this->declarations->getKind('entity'),
          $root,
        ),
      ];
      $fields = $this->fieldFacts(
        array_keys($blueprint['fields'] ?? []),
        ElementModel::KIND_ENTITY,
        $root->getEntityTypeId(),
        $root->bundle(),
        $view_mode,
        $root,
      );
      if (!empty($fields)) {
        $annotation['fields'] = $fields;
      }
      $blueprint['_element'] = $annotation;
    }
    if (!empty($blueprint['layout_builder__layout'])) {
      $blueprint['layout_builder__layout'] = $this->annotateSections($blueprint['layout_builder__layout'], ElementResolver::DEPTH_ROOT);
    }
    // The spine's lean node tree rides every delivered blueprint as `_tree`
    // (an annotation like `_element`: delivery-only, never stored, ignored
    // on echo). It is built over the ANNOTATED section nodes — the tree
    // transform carries unknown keys — so tree nodes wear the same
    // `_element` facts, and the mirror consumers that read the tree
    // (projection, markup index, componentForUuid) get one shape with
    // styles on the node and no region/delta residue. Riding the blueprint
    // rather than the envelope means the mutation-response seam refreshes
    // it wholesale for free.
    if ($root instanceof FieldableEntityInterface) {
      $blueprint['_tree'] = $this->nodeTreeBuilder->fromSections(
        $blueprint['layout_builder__layout'] ?? [],
        $this->nodeTreeBuilder->styleMap($root),
      );
    }
    return $blueprint;
  }

  /**
   * Annotates a serialized section list (recursively through nested sections).
   *
   * @param array $sections
   *   Serialized section nodes ({uuid?, layout_id, regions} — or, for
   *   container sections, {uuid?, layout_id, children}: one ordered
   *   component list in place of the regions map).
   * @param string $depth
   *   The nesting depth of the LIST (ElementResolver::DEPTH_ROOT for the
   *   root layout's sections, DEPTH_NESTED for sections reached through a
   *   component list), the depth the layout declarations resolve at.
   *
   * @return array
   *   The annotated section nodes.
   */
  private function annotateSections(array $sections, string $depth): array {
    foreach ($sections as &$section) {
      // The section's markup: the layout's effective declaration (the
      // universal container kind layered under any layout-specific file)
      // resolved at this depth. Sections carry no entity of their own, so
      // the resolution is declaration + depth + the emptiness fact, which
      // only the section node can answer (no components in any region and
      // no children): the resolver's one {empty: true} answer for
      // containers, every consumer reading it from this annotation.
      $section['_element']['markup'] = $this->resolver->resolve(
        $this->declarations->getLayout((string) ($section['layout_id'] ?? '')),
        NULL,
        $depth,
        $this->sectionIsEmpty($section),
      );
      // Iterate variables, never `?? []` expressions: a by-reference
      // foreach over an expression writes into a temporary and the
      // annotations silently vanish.
      if (!empty($section['regions']) && is_array($section['regions'])) {
        foreach ($section['regions'] as &$components) {
          $this->annotateComponentList($components);
        }
        unset($components);
      }
      // A container section's ordered children list is the same component
      // list under the serializer's container shape (its sole region is a
      // storage vestige, so there is no regions level to walk).
      if (!empty($section['children']) && is_array($section['children'])) {
        $this->annotateComponentList($section['children']);
      }
    }
    return $sections;
  }

  /**
   * Whether a serialized section node carries no components at all.
   *
   * The {empty: true} fact for containers, answered from the node itself
   * (sections have no entity to derive it from): every region list empty
   * and no children entries. Bare-UUID relocation strings count — they
   * stand in for real components.
   */
  private function sectionIsEmpty(array $section): bool {
    if (!empty($section['children']) && is_array($section['children'])) {
      return FALSE;
    }
    foreach (is_array($section['regions'] ?? NULL) ? $section['regions'] : [] as $components) {
      if (!empty($components) && is_array($components)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Annotates one component list (a region's entries or a children list).
   *
   * @param array $components
   *   The component list, modified by reference.
   */
  private function annotateComponentList(array &$components): void {
    foreach ($components as &$component) {
      // An entry carrying layout_id is a nested section node: it presents
      // AS a section (no block facade), so no component annotation beyond
      // the section markup; recurse into its own component lists like any
      // section, at nested depth, which is what being reached through a
      // component list means.
      if (is_array($component) && isset($component['layout_id'])) {
        $component = current($this->annotateSections([$component], ElementResolver::DEPTH_NESTED));
        continue;
      }
      $this->annotateComponent($component);
    }
    unset($component);
  }

  /**
   * Annotates one component node.
   */
  private function annotateComponent(array|string &$component): void {
    // Bare-UUID relocation strings carry no node to annotate.
    if (!is_array($component)) {
      return;
    }
    // The serializer emits `block_type` (the bundle) for inline blocks and
    // `plugin_id` for everything else.
    $block_type = $component['block_type'] ?? NULL;
    if ($block_type === NULL) {
      return;
    }
    // label_display is the raw stored value; an absent key means 'visible'
    // (core's block plugin default) — the same fallback the serializer and
    // LayoutBuilderBlockAttributes use.
    $label_visible = ($component['label_display'] ?? 'visible') === 'visible';
    $annotation = [
      'presentation' => $this->elementModel
        ->bundlePresentation('block_content', $block_type, $label_visible)
        ->toArray(),
    ];
    $composed = $this->elementModel->isComposedInlineBlock($block_type);
    if ($composed) {
      $annotation['composed'] = TRUE;
    }
    $entity = $this->componentEntities[$component['uuid'] ?? ''] ?? NULL;
    if ($entity !== NULL) {
      $markup = $this->resolver->resolve($this->declarations->getBlockType($block_type), $entity);
      if ($composed) {
        // A composed render is ONE element: the fields have no elements of
        // their own, so their rich-text facts (instance carriers, present
        // tags) mount on the block's own markup, where their innermost
        // selectable element lands.
        $markup = array_merge($markup, $this->entityMarkup($entity));
      }
      $annotation['markup'] = $markup;
    }
    $fields = $this->fieldFacts(
      array_keys($component['block_fields'] ?? []),
      ElementModel::KIND_BLOCK,
      'block_content',
      $block_type,
      // Inline blocks render through their bundle's own display.
      'full',
      // Open renders own their instance carriers per field; a composed
      // block's carriers already rode its own markup above.
      $composed ? NULL : $entity,
    );
    if (!empty($fields)) {
      $annotation['fields'] = $fields;
    }
    $component['_element'] = $annotation;
  }

  /**
   * The non-default per-field facts for one owning node's field map.
   *
   * Intersected with the names actually present on the node so the payload
   * only carries facts a row could exist for.
   *
   * @param string[] $names
   *   The field names present on the node ('fields' / 'block_fields' keys).
   * @param string $kind
   *   The existence kind (ElementModel::KIND_ENTITY / KIND_BLOCK).
   * @param string $entity_type_id
   *   The entity type the fields belong to.
   * @param string $bundle
   *   The bundle.
   * @param string $view_mode
   *   The view mode the node renders with.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $entity
   *   The owning entity whose rich-text values carry instance elements, or
   *   NULL when instances ride elsewhere (a composed block's own markup)
   *   or no entity resolves.
   *
   * @return array<string, array>
   *   {name: {element: false} | {presentation: mode} |
   *   {markup: {instances?, tags?}}}, non-default only.
   */
  private function fieldFacts(array $names, string $kind, string $entity_type_id, string $bundle, string $view_mode, ?FieldableEntityInterface $entity = NULL): array {
    if (empty($names)) {
      return [];
    }
    $non_existent = $this->elementModel->nonExistentFields($kind, $entity_type_id, $bundle, $view_mode);
    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    $facts = [];
    foreach ($names as $name) {
      if (in_array($name, $non_existent, TRUE)) {
        // Existence outranks presentation: a key nothing answers to has no
        // row to present.
        $facts[$name] = ['element' => FALSE];
        continue;
      }
      $definition = $definitions[$name] ?? NULL;
      if ($definition === NULL) {
        continue;
      }
      $mode = $this->elementModel->fieldPresentation($definition);
      if ($mode !== Presentation::OPEN) {
        $facts[$name] = ['presentation' => $mode];
      }
      if ($entity !== NULL) {
        $markup = $this->fieldMarkup($entity, $name);
        if (!empty($markup)) {
          $facts[$name]['markup'] = $markup;
        }
      }
    }
    return $facts;
  }

  /**
   * The component entities the root's section data resolves.
   *
   * The blueprint's component nodes carry serialized values, not entities;
   * markup resolution needs the entities, so they come from the same
   * staged section data the blueprint was serialized from, matched back
   * to the serialized nodes by component uuid.
   *
   * @return array<string, \Drupal\Core\Entity\FieldableEntityInterface>
   *   Component uuid => block_content entity.
   */
  private function componentEntitiesOf(FieldableEntityInterface $root): array {
    if (!$root->hasField('layout_builder__layout')) {
      return [];
    }
    $entities = [];
    foreach ($root->get('layout_builder__layout') as $item) {
      if ($item->section instanceof Section) {
        $this->collectComponentEntities($item->section, $entities);
      }
    }
    return $entities;
  }

  /**
   * Collects one section's inline-block entities, nested sections included.
   */
  private function collectComponentEntities(Section $section, array &$entities): void {
    foreach ($section->getComponents() as $component) {
      if ($this->daedalusLayoutAdapter->isSectionComponent($component)) {
        $embedded = $this->daedalusLayoutAdapter->embeddedSection($component);
        if ($embedded !== NULL) {
          $this->collectComponentEntities($embedded, $entities);
        }
        continue;
      }
      if ($this->daedalusLayoutAdapter->isInlineBlock($component)) {
        $entity = $this->daedalusLayoutAdapter->extractBlockContent($component);
        if ($entity !== NULL) {
          $entities[$component->getUuid()] = $entity;
        }
      }
    }
  }

  /**
   * The declared instance namespaces, keyed by class prefix ('s-').
   *
   * The spine's `instances:` declarations re-keyed for the enumeration
   * walk: the pattern selector's dot and star strip to the bare class
   * prefix identityOf() matches against.
   *
   * @return array<string, array>
   *   The instance declarations keyed by their pattern.
   */
  private function instanceNamespacesOf(): array {
    $namespaces = [];
    foreach ($this->declarations->getInstances() as $pattern => $declaration) {
      $pattern = (string) $pattern;
      if (is_array($declaration) && preg_match(PageBuilderDeclarations::PART_PATTERN, $pattern)) {
        $namespaces[substr($pattern, 1, -1)] = $declaration;
      }
    }
    return $namespaces;
  }

  /**
   * The rich-text markup facts across one entity's rich-text fields.
   *
   * @return array{instances?: array[], tags?: string[]}
   *   Instance rows in document order across the entity's rich-text
   *   fields (definition order between fields) and the distinct tag names
   *   on the same walk, each key present only when non-empty.
   */
  private function entityMarkup(FieldableEntityInterface $entity): array {
    $instances = [];
    $tags = [];
    foreach (array_keys($entity->getFieldDefinitions()) as $name) {
      $facts = $this->fieldMarkup($entity, $name);
      $instances = array_merge($instances, $facts['instances'] ?? []);
      $tags = array_merge($tags, $facts['tags'] ?? []);
    }
    return self::markupFacts($instances, $tags);
  }

  /**
   * The rich-text markup facts one field's stored values carry.
   *
   * @return array{instances?: array[], tags?: string[]}
   *   Instance rows ({class, tag, label, text?, children}) in document
   *   order across the field's deltas (only when a pattern-part namespace
   *   is declared) and the distinct tag names of every element in those
   *   values, each key present only when non-empty; [] for non-rich-text
   *   fields and empty values.
   */
  private function fieldMarkup(FieldableEntityInterface $entity, string $name): array {
    if (!$entity->hasField($name)) {
      return [];
    }
    $definition = $entity->get($name)->getFieldDefinition();
    $properties = $definition->getFieldStorageDefinition()->getPropertyDefinitions();
    if (!isset($properties['format']) || $properties['format']->getDataType() !== 'filter_format') {
      return [];
    }
    $instances = [];
    $tags = [];
    $seen = [];
    foreach ($entity->get($name) as $item) {
      $value = $item->value;
      if (!is_string($value) || trim($value) === '') {
        continue;
      }
      $body = Html::load($value)->getElementsByTagName('body')->item(0);
      if ($body === NULL) {
        continue;
      }
      if (!empty($this->instanceNamespaces)) {
        $instances = array_merge($instances, $this->instanceRows($body, $seen));
      }
      foreach ($body->getElementsByTagName('*') as $element) {
        $tags[] = strtolower($element->tagName);
      }
    }
    return self::markupFacts($instances, $tags);
  }

  /**
   * Packs rich-text facts the annotation's way: non-empty keys only.
   *
   * @param array[] $instances
   *   Instance rows, document order.
   * @param string[] $tags
   *   Tag names in document order, repeats allowed (deduplicated here,
   *   first occurrence kept, so the fact reads in document order).
   *
   * @return array{instances?: array[], tags?: string[]}
   *   The facts, with each key present only when non-empty.
   */
  private static function markupFacts(array $instances, array $tags): array {
    $facts = [];
    if (!empty($instances)) {
      $facts['instances'] = $instances;
    }
    if (!empty($tags)) {
      $facts['tags'] = array_values(array_unique($tags));
    }
    return $facts;
  }

  /**
   * Collects the instance rows under one parsed scope, ownership-guarded.
   *
   * Depth-first over element children: an element carrying an identity
   * class from a declared pattern namespace becomes a row (recursing for
   * nested carriers); anything else is a transparent wrapper whose subtree
   * is searched in place. Duplicate identities (malformed content the next
   * presave re-mints) keep the first carrier, mirroring the minter's
   * ownership walk.
   *
   * @param \DOMNode $scope
   *   The parsed scope.
   * @param string[] $seen
   *   The identity classes already claimed (mutated in place).
   *
   * @return array[]
   *   The instance rows.
   */
  private function instanceRows(\DOMNode $scope, array &$seen): array {
    $rows = [];
    foreach ($scope->childNodes as $child) {
      if (!$child instanceof \DOMElement) {
        continue;
      }
      $identity = $this->identityOf($child);
      if ($identity !== NULL && !in_array($identity[0], $seen, TRUE)) {
        [$class, $prefix] = $identity;
        $seen[] = $class;
        $tag = strtolower($child->tagName);
        // The label is the namespace's declared tag vocabulary (the one
        // truth); a carrier outside it labels as its bare tag.
        $tags = $this->instanceNamespaces[$prefix]['tags'] ?? [];
        $label = $tags[$tag] ?? $tag;
        $row = [
          'class' => $class,
          'tag' => $tag,
          'label' => is_string($label) ? $label : $tag,
        ];
        $text = trim(preg_replace('/\s+/', ' ', $child->textContent ?? '') ?? '');
        if ($text !== '') {
          $row['text'] = mb_substr($text, 0, self::TEXT_CAP);
        }
        $row['children'] = $this->instanceRows($child, $seen);
        $rows[] = $row;
      }
      else {
        $rows = array_merge($rows, $this->instanceRows($child, $seen));
      }
    }
    return $rows;
  }

  /**
   * An element's identity class from the declared namespaces, or NULL.
   *
   * The suffix charset is the minted shape (lowercase alphanumerics, plus
   * the hyphen/underscore a part selector legally carries): stamping only
   * classes the instance-part grammar can address keeps every enumerated
   * row a styleable target.
   *
   * @return array{0: string, 1: string}|null
   *   [identity class, matched namespace prefix], or NULL when the
   *   element carries no declared identity.
   */
  private function identityOf(\DOMElement $element): ?array {
    foreach (preg_split('/\s+/', $element->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY) as $class) {
      foreach (array_keys($this->instanceNamespaces) as $prefix) {
        if (str_starts_with($class, $prefix) && preg_match('/^[a-z0-9_-]+$/', substr($class, strlen($prefix)))) {
          return [$class, $prefix];
        }
      }
    }
    return NULL;
  }

}
