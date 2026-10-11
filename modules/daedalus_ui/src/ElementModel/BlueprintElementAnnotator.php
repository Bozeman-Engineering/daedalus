<?php

declare(strict_types=1);

namespace Drupal\daedalus_ui\ElementModel;

use Drupal\Component\Utility\Html;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\daedalus_blueprint\Capability\Showing;
use Drupal\daedalus_blueprint\Capability\VerdictStamper;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Host\InnerRender;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\Plugin\Block\LinkedBlueprintBlock;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_ui\DaedalusUi;
use Drupal\layout_builder\Section;
use Drupal\daedalus_ui\Presentation\Presentation;

/**
 * Annotates a DELIVERED blueprint with the element model's decisions.
 *
 * The blueprint is the stored-data model; the element model is page-builder
 * vocabulary, so it is attached only at daedalus_ui's delivery seams,
 * the drupalSettings boot payload (BlueprintMirror::attach(), whose
 * build's cacheability carries what the icon providers read), the
 * mutation response and the derived read modes (BlueprintElementAnnotations
 * subscriber) and the HTML projection (HtmlProjector): never persisted,
 * never visible to headless/AI consumers of base daedalus_blueprint, whose
 * blueprint data stays annotation-free.
 *
 * ONE annotation, `_element`, rides every owning node, the root blueprint
 * and every container and block node of the lean tree its `layout`
 * carries (the walk is the tree's: region nodes are structure and carry
 * nothing), mirroring the declaration grammar so the client reads the
 * same vocabulary developers author:
 * - name and type: the element-naming spine, two translated strings minted
 *   HERE and delivered as data, never derived by a client. Every surface
 *   that words an element (the page-elements tree, the canvas chips, the
 *   stylish subject line and code tree in the leader page AND the
 *   satellite, the explain tool in PHP, the AI's chips) reads these two
 *   fields, so one element can never be worded two ways. `type` answers
 *   what the element IS: a section at the root layout depth is a
 *   "Section", a nested one a "Container" (the tree's <section>/<div>
 *   tag is the same fact; the palette item stays "Container" because
 *   what you drag is the kind, it becomes a Section when it lands at page
 *   depth); an inline block its block type entity's label ("Text",
 *   "Image"); a field block the field's label; a slot door "Slot"; a
 *   linked placement "Linked blueprint"; any other plugin block (a menu,
 *   the site branding) its block plugin's admin label, the Add panel's
 *   word for it; the root its bundle label, "Blueprint" on a saved
 *   blueprint's canvas, "Site frame" on the frame's, "Template" on a
 *   template's.
 *   `name` answers what the element SAYS: a section's custom label
 *   (layout_settings.label, surfaced by the serializer as the node's
 *   `label`) else its type; a merged block's content excerpt (the edit
 *   target's first line, capped at NAME_CAP) when that value is a string,
 *   else the presentation's label, else the component's label when that
 *   label is a NAME (not `label_rendered`: a rendered label is content,
 *   the heading precedent, so it is never a name by itself), else its
 *   type; a field block the field's label; a slot door its derivative's
 *   admin label ("Header slot", the roster's word, the Add panel's word
 *   for it: a door is named by what it is, never by a configured label);
 *   a linked placement the blueprint's label; a plugin block its
 *   unrendered label else its type; the root its entity label. An
 *   unrenamed element's name is therefore its type word, never a
 *   humanized machine id. The satellite reads both off the tree's nodes.
 *   Every component is attributed on the canvas by its uuid
 *   (LayoutBuilderBlockAttributes) and rows in the tree, so every
 *   component carries both words: a plugin block's annotation is exactly
 *   these two and the icon below.
 * - icon: the third minted word, what the element LOOKS like in an
 *   outline: a root-relative URL, or NULL when nothing answers. Minted
 *   here like the two words and read as data by every outline (the tree,
 *   code mode in the leader and in the satellite), never derived by a
 *   client. daedalus_ui answers its own kinds from assets/outline (the
 *   root page, a template root, a field block, a plugin block, a slot
 *   door); a
 *   container and an inline block are daedalus_layout's concepts, so the
 *   annotator asks the tagged element icon providers
 *   (ElementIconProviderInterface, first non-null answer in priority
 *   order; daedalus_layout answers the Container's asset and the block
 *   type's icon setting through the icon value grammar). What a provider
 *   read rides the caller's cacheability when the caller has a render to
 *   carry it (the mirror's build); pseudo rows the client alone draws
 *   (shell parts, generated boxes, instance carriers) are the client's
 *   anatomy and pick their glyph there.
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
 *   for inline blocks, root-field nonexistence for roots on LB displays): nothing
 *   answers to the key in ANY view, so the projection folds the row
 *   unconditionally. Field presentation cannot ride the field nodes themselves
 *   because scalar field values (a plain string) carry no keys. Inline entities
 *   (paragraphs) are not annotated; they present open.
 * - markup: the INSTANCE tier of the page-elements model, the element
 *   resolver's concrete tree for this node's data ({tag, attributes,
 *   classes, hoisted, parts, slots}, see ElementResolver), the canonical
 *   answer to "what does THIS element render as". Stamped on the root
 *   (entity kind), on every container node (the layout's effective
 *   declaration at the container's nesting depth), and on every
 *   inline-block component whose block_content entity the root's section
 *   data resolves (the effective block-type declaration against that
 *   entity). A component whose entity
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
 * - showing: on every JOIN of the render stack this document holds, a
 *   slot door (daedalus_slot:{id}) or a linked placement, what it shows
 *   seen from this canvas by this actor: the spine's one answer
 *   (VerdictStamper::showing(), Showing::toArray(): `shows` route | fill
 *   | default | nothing, `document` {entity_type, id, label}, `blueprint`
 *   the shown blueprint's machine name under default, `portal` where the
 *   shown blueprint is edited only when the actor may update it,
 *   `claimable`), the portal's `url` resolved here from the host it names
 *   (DaedalusUi::getEditingUrl, as hostVerdict() resolves the root's
 *   portal: the spine names hosts, surfaces derive urls). The holder the
 *   spine is asked for is the host whose document holds the node: the
 *   canvas's own for a top-level section filling a slot (a locked page's
 *   fill), else the document the canvas renders (HostResolver::
 *   renderHost(): the template on a locked page, the host itself
 *   otherwise). Absent on every other node (a copy is the page's own, so
 *   a detached placement is no join). The Page elements tree's
 *   shown-document row, the Open blueprint verb and the AI's chips read
 *   this and nothing else: a client never derives a route or guesses at
 *   access.
 * - can and refused: the spine's verdict on the node, copied from the
 *   `_verdict` stamp the serializer put there (VerdictStamper, for the
 *   actor the read was made for): `can` maps the five capabilities
 *   (content, configure, style, arrange, insert) to booleans, `refused`
 *   lists one {code, capabilities} entry per refusal. The root carries
 *   them too. The client's one gate (selection/capable.js) reads `can`
 *   through the projection index, and every gesture (verbs, drags, drops,
 *   rename, paste, the inspector) asks that gate; nothing on the client
 *   interprets a cause such as ownership itself. The root's refusals,
 *   explained (message, hint, the owner and the portal with its editing
 *   url), ride the mirror envelope and every mutation response as
 *   `verdict` (hostVerdict()), the one source of the document notice.
 *
 * Only inline blocks are fieldable bundles the model can decide for; plugin
 * components stay unannotated — they present open, exactly as attribution
 * stamps nothing on them, and their bespoke templates keep DOM authority
 * (presence reads answer "unknown" and surfaces degrade to declarations).
 * The one plugin exception is a Layout Builder FIELD BLOCK, which renders a
 * root field: it carries `presentation` (MERGED into that field, the
 * model's fieldBlockPresentation) and `presented_field` (the field's
 * selectable key, the twin of the placement's data-presented-field stamp),
 * so the projection folds the placement row and the field row as one and
 * the leaf inside edits in place.
 */
final class BlueprintElementAnnotator {

  use StringTranslationTrait;

  /**
   * A merged element's content-excerpt name cap, in characters.
   *
   * A name, not a preview: a string_long can hold paragraphs, so the name
   * is the first line clamped to this many characters (one fewer plus an
   * ellipsis when it overruns).
   */
  private const NAME_CAP = 80;

  /**
   * Instance-excerpt cap, in characters.
   *
   * Covers the NAME_CAP convention with headroom, not whole documents
   * duplicated into the payload.
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
   * The fields entity of the current annotate() call's host, when it has one.
   *
   * A field block presents one of ITS fields; the component node carries
   * only the field name, so the host's fields entity supplies the uuid and
   * the definition.
   */
  private ?FieldableEntityInterface $root = NULL;

  /**
   * The subject of the current annotate() call's host.
   *
   * The entity a render of the document shows (the page itself, or a
   * template's sample): a field block the host does not edit (a template
   * canvas, a field the page lacks) is still named by the subject's field
   * label when the subject has the field.
   */
  private ?FieldableEntityInterface $subject = NULL;

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

  /**
   * The canvas of the current annotate() call: the host being delivered.
   *
   * The showing of a join is answered for the canvas it is seen from (the
   * route host), the host the blueprint was serialized from.
   */
  private ?HostInterface $canvas = NULL;

  /**
   * The host whose document the canvas renders (HostResolver::renderHost()).
   *
   * The holder of every node of the delivered tree that is not the
   * canvas's own fill: the template on a locked page, the canvas itself
   * on a page that owns its document, a template, a frame or a blueprint.
   */
  private ?HostInterface $renderHost = NULL;

  /**
   * The holder of the top-level section being walked.
   *
   * Seated per top-level container by annotateNodes(): the canvas for a
   * section filling a slot (a locked page's own), the render host for the
   * rest.
   */
  private ?HostInterface $holder = NULL;

  /**
   * What the current annotate() call's icon answers depend on.
   *
   * The caller's accumulator when it has a render to carry it (the mirror's
   * build), else a throwaway: a mutation response and the AI's explain
   * tool deliver uncached.
   */
  private CacheableMetadata $cacheability;

  public function __construct(
    private readonly ElementModel $elementModel,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly PageBuilderDeclarations $declarations,
    private readonly ElementResolver $resolver,
    private readonly DaedalusLayoutAdapter $daedalusLayoutAdapter,
    private readonly BlockManagerInterface $blockManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DaedalusUi $daedalusUi,
    private readonly ElementIconProviderInterface $iconProvider,
    private readonly ModuleExtensionList $moduleList,
    private readonly InnerRender $innerRender,
    private readonly VerdictStamper $verdictStamper,
    private readonly HostResolver $hostResolver,
  ) {
    $this->cacheability = new CacheableMetadata();
  }

  /**
   * Annotates a delivered blueprint in place and returns it.
   *
   * @param array $blueprint
   *   The serialized blueprint (full mode) of the host.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the blueprint was serialized from: supplies the entity type
   *   and bundle for the root field definitions and the section data the
   *   component entities resolve from.
   * @param string $view_mode
   *   The view mode the root renders with — the display the root existence
   *   feeders (daedalus_layout's Layout Builder root-existence rule) speak
   *   for.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   Accumulates what the icon answers read (a block type, a media icon's
   *   tags) when the caller delivers through a cacheable render (the
   *   mirror's build); NULL when the delivery is uncached (a mutation
   *   response, the AI's explain tool).
   *
   * @return array
   *   The annotated blueprint.
   */
  public function annotate(array $blueprint, HostInterface $host, string $view_mode, ?CacheableMetadata $cacheability = NULL): array {
    $this->prepare($host, $cacheability);
    $root = $this->root;
    if ($root !== NULL) {
      // The root's type is the word for what the document is to the
      // editor (DaedalusUi::hostKind(): the bundle label "Page", or
      // "Blueprint" for a saved blueprint's canvas, the same word a portal
      // to it carries); its name the entity label, which is what its edit
      // target (the label field) holds; its icon the page glyph.
      $type = $this->daedalusUi->hostKind($host);
      $label = $host->label();
      $annotation = [
        'name' => $label !== '' ? $label : $type,
        'type' => $type,
        'icon' => $this->glyph('page'),
        'presentation' => $this->elementModel
          ->rootPresentation($host->entityTypeId(), $host->bundle(), $view_mode)
          ->toArray(),
        'markup' => $this->resolver->resolve(
          $this->declarations->getKind('entity'),
          $root,
        ),
      ];
      $fields = $this->fieldFacts(
        array_keys($blueprint['fields'] ?? []),
        ElementModel::KIND_ENTITY,
        $host->entityTypeId(),
        $host->bundle(),
        $view_mode,
        $root,
      );
      if (!empty($fields)) {
        $annotation['fields'] = $fields;
      }
      $blueprint['_element'] = $annotation;
    }
    else {
      // A host with no fields (a template host, the site frame): the root
      // is the template or the frame, named by the host's label and typed
      // by its kind ("Template", "Site frame"); nothing on it edits (no
      // edit target, no field facts), and its markup is the entity kind's
      // declaration resolved with no entity data (the layout root part is
      // present unconditionally, so the canvas and the model agree).
      $blueprint['_element'] = [
        'name' => $host->label(),
        'type' => $this->daedalusUi->hostKind($host),
        'icon' => $this->glyph('template'),
        'presentation' => (new Presentation(Presentation::OPEN))->toArray(),
        'markup' => $this->resolver->resolve($this->declarations->getKind('entity')),
      ];
    }
    $blueprint['_element'] += self::verdictFacts($blueprint);
    // The blueprint's `layout` is the spine's lean node tree, the spine's
    // `_verdict` stamp already on its nodes; the one walk below puts
    // `_element` beside it (an annotation like the stamp: delivery-only,
    // never stored, ignored on echo), so the mirror consumers that read
    // the tree (projection, markup index, componentForUuid) get one shape
    // with the model's facts on it. Riding the blueprint rather than the
    // envelope means the mutation-response seam refreshes it wholesale for
    // free.
    if (isset($blueprint['layout']) && is_array($blueprint['layout'])) {
      $blueprint['layout'] = $this->annotateNodes($blueprint['layout'], ElementResolver::DEPTH_ROOT);
    }
    return $blueprint;
  }

  /**
   * Annotates a lean node tree of a host, in place, and returns it.
   *
   * The tree-only seam (the read endpoint's derived modes, which print
   * from a tree the spine built): every container and block node gains
   * `_element` exactly as a delivered blueprint's `layout` wears it; the
   * root is not a tree node and gets nothing here.
   *
   * @param array $tree
   *   The ordered top-level container nodes (NodeTreeBuilder's shape).
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host the tree was built from.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheability
   *   As annotate() takes it.
   *
   * @return array
   *   The annotated tree.
   */
  public function annotateTree(array $tree, HostInterface $host, ?CacheableMetadata $cacheability = NULL): array {
    $this->prepare($host, $cacheability);
    return $this->annotateNodes($tree, ElementResolver::DEPTH_ROOT);
  }

  /**
   * Seats the per-call state one annotation walk reads.
   */
  private function prepare(HostInterface $host, ?CacheableMetadata $cacheability): void {
    $this->root = $host->fields();
    $this->subject = $host->subject();
    $this->componentEntities = $this->componentEntitiesOf($host);
    $this->instanceNamespaces = $this->instanceNamespacesOf();
    $this->canvas = $host;
    $this->renderHost = $this->hostResolver->renderHost($host);
    $this->holder = $this->renderHost;
    $this->cacheability = $cacheability ?? new CacheableMetadata();
  }

  /**
   * The URL of one of daedalus_ui's own outline glyphs (assets/outline).
   *
   * @param string $name
   *   The glyph's file name without its extension: page, template,
   *   field-block, plugin-block or slot.
   */
  private function glyph(string $name): string {
    return '/' . $this->moduleList->getPath('daedalus_ui') . '/assets/outline/' . $name . '.svg';
  }

  /**
   * Annotates one sibling list of lean nodes.
   *
   * A container node is annotated at the list's depth and its children
   * (through its region nodes, which are structure, not elements) at
   * nested depth; a block node is annotated as a component.
   *
   * @param array $nodes
   *   Lean nodes (a tree, a container's children, a region's children).
   * @param string $depth
   *   The nesting depth of the LIST (ElementResolver::DEPTH_ROOT for the
   *   tree's top level, DEPTH_NESTED below a container), the depth the
   *   layout declarations resolve at.
   *
   * @return array
   *   The annotated nodes.
   */
  private function annotateNodes(array $nodes, string $depth): array {
    foreach ($nodes as $index => $node) {
      if (!is_array($node)) {
        continue;
      }
      $kind = $node['kind'] ?? NULL;
      if ($kind === 'container') {
        if ($depth === ElementResolver::DEPTH_ROOT) {
          // The holder of a top-level section: the delivered tree of a
          // locked page is its template's sections and then the page's
          // own, the sections filling the template's slots
          // (LayoutSerializer::tree() over HostInterface::sections()), and
          // only a top-level section carries the slot it fills. A fill is
          // the canvas's own document; every other top-level section
          // belongs to the document the canvas renders, which is the
          // canvas itself wherever nothing is locked.
          $this->holder = isset($node['slot']) ? $this->canvas : $this->renderHost;
        }
        $nodes[$index] = $this->annotateContainer($node, $depth);
      }
      elseif ($kind === 'region') {
        $node['children'] = $this->annotateNodes($node['children'] ?? [], $depth);
        $nodes[$index] = $node;
      }
      elseif ($kind === LinkedBlueprintBlock::KIND) {
        $nodes[$index] = $this->annotateLinked($node);
      }
      else {
        $nodes[$index] = $this->annotateBlock($node);
      }
    }
    return $nodes;
  }

  /**
   * Annotates one container node and the nodes it holds.
   *
   * @param array $node
   *   The container node.
   * @param string $depth
   *   The depth the container sits at.
   *
   * @return array
   *   The annotated node.
   */
  private function annotateContainer(array $node, string $depth): array {
    // The layout vocabulary: a container at the tree's top level is a
    // Section of the page, one below another container a grouping
    // Container. Depth is the one fact that decides it, the same fact
    // that picks the <section>/<div> root form below.
    $type = $depth === ElementResolver::DEPTH_ROOT
      ? (string) $this->t('Section')
      : (string) $this->t('Container');
    // The custom label (layout_settings.label, the serializer's top-level
    // `label`) is the container's name when set; an unrenamed container is
    // called by its type, never by its humanized layout id.
    $label = $node['label'] ?? NULL;
    $node['_element']['name'] = is_string($label) && trim($label) !== '' ? $label : $type;
    $node['_element']['type'] = $type;
    // A container is daedalus_layout's concept: its icon is the providers'
    // answer (the Container asset), NULL with none installed.
    $node['_element']['icon'] = $this->iconProvider->icon($node, ElementIconProviderInterface::KIND_CONTAINER, $this->cacheability);
    $node['_element'] += self::verdictFacts($node);
    // The container's markup: the layout's effective declaration (the
    // universal container kind layered under any layout-specific file)
    // resolved at this depth. Containers carry no entity of their own, so
    // the resolution is declaration + depth + the emptiness fact, which
    // only the node can answer (no element in any region and no children):
    // the resolver's one {empty: true} answer for containers, every
    // consumer reading it from this annotation.
    $node['_element']['markup'] = $this->resolver->resolve(
      $this->declarations->getLayout((string) ($node['layout_id'] ?? '')),
      NULL,
      $depth,
      self::containerIsEmpty($node),
    );
    $node['children'] = $this->annotateNodes($node['children'] ?? [], ElementResolver::DEPTH_NESTED);
    return $node;
  }

  /**
   * Annotates a linked node: a blueprint shown by reference.
   *
   * Its words are the blueprint's: the name is the blueprint's label (the
   * machine name when no published blueprint carries it any more), the
   * type the kind's word. It carries the verdict (the spine refuses
   * `content` on it as FOREIGN_DOCUMENT, with the portal to the blueprint
   * for an actor who may update it) and its `showing`: the blueprint by
   * reference, with the portal to its canvas for that actor (the Open
   * blueprint verb's one source). No markup facts: the blueprint's
   * elements are the blueprint host's, not this document's.
   *
   * @param array $node
   *   The linked node.
   *
   * @return array
   *   The annotated node.
   */
  private function annotateLinked(array $node): array {
    $name = (string) ($node[LinkedBlueprintBlock::BLUEPRINT_KEY] ?? '');
    $blueprint = $name === '' ? NULL : $this->innerRender->blueprintHost($name);
    $type = (string) $this->t('Linked blueprint');
    $node['_element'] = [
      'name' => $blueprint !== NULL ? $blueprint->label() : ($name === '' ? $type : $name),
      'type' => $type,
      'icon' => $this->glyph('plugin-block'),
    ] + self::verdictFacts($node) + $this->showingFacts($node);
    return $node;
  }

  /**
   * A join's showing as an `_element` fact: `showing`, or nothing.
   *
   * The spine's one answer for the node seen from the canvas by the actor
   * the read is made for (VerdictStamper::showing(), NULL for a node that
   * is no join), the portal's url resolved from the host it names the way
   * hostVerdict() resolves the root's. A node the serializer gave no uuid
   * is no join either.
   *
   * @return array
   *   {showing: Showing::toArray() with `portal.url`} or [].
   */
  private function showingFacts(array $node): array {
    $uuid = $node['uuid'] ?? NULL;
    if (!is_string($uuid) || $uuid === '' || $this->holder === NULL) {
      return [];
    }
    $showing = $this->verdictStamper->showing($this->canvas, $this->holder, $uuid);
    if (!$showing instanceof Showing) {
      return [];
    }
    $facts = $showing->toArray();
    if ($facts['portal'] !== NULL) {
      $facts['portal']['url'] = $this->portalUrl($facts['portal']['entity_type'], $facts['portal']['entity_id']);
    }
    return ['showing' => $facts];
  }

  /**
   * Whether a container node holds no element at all.
   *
   * The {empty: true} fact for containers, answered from the node itself
   * (containers have no entity to derive it from): no child, or only
   * region nodes with no children of their own.
   */
  private static function containerIsEmpty(array $node): bool {
    foreach ($node['children'] ?? [] as $child) {
      if (!is_array($child)) {
        continue;
      }
      if (($child['kind'] ?? NULL) !== 'region' || !self::containerIsEmpty($child)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Annotates one block node.
   *
   * @param array $component
   *   The block node.
   *
   * @return array
   *   The annotated node.
   */
  private function annotateBlock(array $component): array {
    // The serializer emits `block_type` (the bundle) for inline blocks and
    // `plugin_id` for everything else.
    $block_type = $component['block_type'] ?? NULL;
    if ($block_type === NULL) {
      return $this->annotatePluginBlock($component);
    }
    // Whether the label renders as page content is the tree's
    // `label_rendered` (NodeTreeBuilder::labelRendered, the one rule the
    // canvas attribution reads too), absent when it does not.
    $label_visible = !empty($component['label_rendered']);
    $presentation = $this->elementModel->bundlePresentation('block_content', $block_type, $label_visible);
    $type = $this->daedalusUi->bundleLabel('block_content', $block_type);
    $annotation = [
      'name' => $this->componentName($component, $presentation, $label_visible, $type),
      'type' => $type,
      // An inline block's icon is its block type's, a daedalus_layout
      // setting, so the providers answer it; NULL with none installed.
      'icon' => $this->iconProvider->icon($component, ElementIconProviderInterface::KIND_BLOCK, $this->cacheability),
      'presentation' => $presentation->toArray(),
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
    $component['_element'] = $annotation + self::verdictFacts($component);
    return $component;
  }

  /**
   * Annotates a plugin block node (anything that is not an inline block).
   *
   * A field node (the tree names the FIELD a field block renders, never a
   * per-bundle plugin id) of a field the root has presents MERGED into
   * that root field (ElementModel::fieldBlockPresentation) and names it by
   * its selectable key. A field node the host does not edit (a template
   * canvas, whose root is NULL; a field the page lacks) presents open,
   * typed by the subject's field label when the subject has the field,
   * else by the field name. A slot door (daedalus_slot:{id}) is a join of
   * the render stack: its type is the kind's word, "Slot", its name the
   * derivative's admin label ("Header slot", SlotDeriver: the roster's
   * word, never a configured label, since a door is what it is), its
   * glyph the door, and it carries its `showing`. Any other plugin block
   * (a menu, the site branding) is not a fieldable bundle: no
   * presentation, no compose, no markup, no field facts, it presents
   * open. Each is still an element on the canvas (attributed by its uuid)
   * with a row in the tree, so it carries the naming spine's two words:
   * its type is the block plugin's admin label, the same word the Add
   * panel shows for it, and its name the configured label when that label
   * is a name (not rendered as content), else the type.
   */
  private function annotatePluginBlock(array $component): array {
    $field = $component['field'] ?? NULL;
    if (is_string($field) && $field !== '') {
      return $this->annotateFieldBlock($component, $field);
    }
    $plugin_id = $component['plugin_id'] ?? NULL;
    if (!is_string($plugin_id)) {
      return $component;
    }
    if (SlotBlock::slotIdOf($plugin_id) !== NULL) {
      $component['_element'] = [
        'name' => $this->pluginLabel($plugin_id),
        'type' => (string) $this->t('Slot'),
        'icon' => $this->glyph('slot'),
      ] + self::verdictFacts($component) + $this->showingFacts($component);
      return $component;
    }
    $type = $this->pluginLabel($plugin_id);
    $component['_element'] = [
      'name' => $this->labelAsName($component, !empty($component['label_rendered'])) ?? $type,
      'type' => $type,
      'icon' => $this->glyph('plugin-block'),
    ] + self::verdictFacts($component);
    return $component;
  }

  /**
   * Annotates a field node: the root's field it presents, or its words.
   */
  private function annotateFieldBlock(array $component, string $field): array {
    $definition = $this->root !== NULL && $this->root->hasField($field)
      ? $this->root->getFieldDefinition($field)
      : NULL;
    if ($definition !== NULL) {
      // A field block IS the field it renders: the field's label is both
      // what it is and what it is called.
      $label = (string) $definition->getLabel();
      $component['_element'] = [
        'name' => $label,
        'type' => $label,
        'icon' => $this->glyph('field-block'),
        'presentation' => $this->elementModel->fieldBlockPresentation($definition)->toArray(),
        'presented_field' => SelectableKey::field($this->root->uuid(), $definition->getName()),
      ];
    }
    else {
      $type = $this->subject !== NULL && $this->subject->hasField($field)
        ? (string) $this->subject->getFieldDefinition($field)->getLabel()
        : $field;
      $component['_element'] = [
        'name' => $this->labelAsName($component, !empty($component['label_rendered'])) ?? $type,
        'type' => $type,
        'icon' => $this->glyph('field-block'),
      ];
    }
    $component['_element'] += self::verdictFacts($component);
    return $component;
  }

  /**
   * A node's verdict as `_element` facts: `can` and `refused`.
   *
   * Copied from the spine's `_verdict` stamp on the node; a node the
   * stamper did not reach (a section with no uuid) carries neither key.
   *
   * @return array
   *   {can?: the five capabilities to booleans, refused?: {code,
   *   capabilities} entries}, empty for an unstamped node.
   */
  private static function verdictFacts(array $node): array {
    $verdict = $node[VerdictStamper::KEY] ?? NULL;
    if (!is_array($verdict) || !isset($verdict['can'])) {
      return [];
    }
    return [
      'can' => $verdict['can'],
      'refused' => $verdict['refused'] ?? [],
    ];
  }

  /**
   * The host-level verdict a delivered blueprint explains the page with.
   *
   * The root's `_verdict` (the host: its fields, its own style key, its
   * top-level flow) with each refusal's context resolved for a surface
   * that words and links: the owner gains its label, the portal (present
   * only when the actor may open the owner) gains the editing url, NULL
   * where the site has no such route. Rides the mirror envelope and every
   * mutation response as `verdict`; the document notice at the top of the
   * page elements panel reads nothing else.
   *
   * @param array $blueprint
   *   A serialized blueprint (annotated or not; the stamp is the spine's).
   *
   * @return array
   *   The verdict set {can, refused}, its refusals carrying code,
   *   capabilities, message, hint and context ({owner?: {entity_type,
   *   entity_id, label}, portal?: {entity_type, entity_id, label, url}}).
   */
  public function hostVerdict(array $blueprint): array {
    $verdict = $blueprint[VerdictStamper::KEY] ?? NULL;
    if (!is_array($verdict) || !isset($verdict['can'])) {
      throw new \LogicException('The blueprint carries no verdict stamp on its root.');
    }
    $refused = [];
    foreach ($verdict['refused'] ?? [] as $refusal) {
      $context = $refusal['context'] ?? [];
      if (isset($context['owner']['entity_type'], $context['owner']['entity_id'])) {
        $owner = $this->loadHostEntity($context['owner']['entity_type'], (string) $context['owner']['entity_id']);
        $context['owner']['entity_id'] = (string) $context['owner']['entity_id'];
        $context['owner']['label'] = $owner !== NULL ? (string) $owner->label() : (string) $context['owner']['entity_id'];
      }
      if (isset($context['portal']['entity_type'], $context['portal']['entity_id'])) {
        $context['portal']['url'] = $this->portalUrl($context['portal']['entity_type'], (string) $context['portal']['entity_id']);
      }
      $refusal['context'] = $context;
      $refused[] = $refusal;
    }
    return ['can' => $verdict['can'], 'refused' => $refused];
  }

  /**
   * The editing url of the host a portal names, or NULL.
   *
   * The spine names the host (Portal::toArray(): its entity type and id);
   * the url is this surface's derivation, NULL when the entity is gone or
   * the site has no editing route for it. One rule for the root's refusal
   * portals (hostVerdict()) and a join's showing (showingFacts()).
   */
  private function portalUrl(string $entity_type_id, string $id): ?string {
    $portal = $this->loadHostEntity($entity_type_id, $id);
    return $portal !== NULL && $this->daedalusUi->hasEditingUrl($portal)
      ? $this->daedalusUi->getEditingUrl($portal)->toString()
      : NULL;
  }

  /**
   * Loads the entity a refusal's context names, or NULL when it is gone.
   */
  private function loadHostEntity(string $entity_type_id, string $id): ?EntityInterface {
    return $this->entityTypeManager->getStorage($entity_type_id)->load($id);
  }

  /**
   * A block plugin's admin label as the type word.
   *
   * A plugin the manager no longer knows (its module uninstalled under a
   * placed block, a derivative whose menu is gone) still needs a word: the
   * plugin id, the one fact left, the same degrade as
   * DaedalusUi::bundleLabel().
   */
  private function pluginLabel(string $plugin_id): string {
    $definition = $this->blockManager->getDefinition($plugin_id, FALSE);
    $label = $definition['admin_label'] ?? NULL;
    $label = $label === NULL ? '' : (string) $label;
    return $label !== '' ? $label : $plugin_id;
  }

  /**
   * A component's configuration label when it is a NAME, else NULL.
   *
   * A visible label is rendered content (the heading precedent), never a
   * name by itself; a hidden, non-empty label is what the editor called
   * the block.
   */
  private static function labelAsName(array $component, bool $label_visible): ?string {
    $label = $component['label'] ?? NULL;
    return !$label_visible && is_string($label) && trim($label) !== '' ? $label : NULL;
  }

  /**
   * An inline-block component's name: what the element SAYS.
   *
   * In order: a merged block reads its content (the edit target's value:
   * the configuration label itself when the target is 'label', the
   * heading precedent, else the target block field) as a first-line
   * excerpt when that value is a non-empty string; else the presentation's
   * own label (the absorbed field's definition label); else the component's
   * configuration label when it is a NAME, which it is only when not
   * rendered as content; else the type word. Never empty.
   *
   * @param array $component
   *   The serialized component node.
   * @param \Drupal\daedalus_ui\Presentation\Presentation $presentation
   *   The node's presentation decision.
   * @param bool $label_visible
   *   Whether the configuration label renders as page content.
   * @param string $type
   *   The node's type word, the last resort.
   */
  private function componentName(array $component, Presentation $presentation, bool $label_visible, string $type): string {
    if ($presentation->mode === Presentation::MERGED && $presentation->editTarget !== NULL) {
      $value = $presentation->editTarget === 'label'
        ? ($component['label'] ?? NULL)
        : ($component['block_fields'][$presentation->editTarget] ?? NULL);
      if (is_string($value)) {
        $excerpt = self::excerpt($value);
        if ($excerpt !== NULL) {
          return $excerpt;
        }
      }
    }
    if ($presentation->label !== NULL && $presentation->label !== '') {
      return $presentation->label;
    }
    return self::labelAsName($component, $label_visible) ?? $type;
  }

  /**
   * A content value as a name: its first line, clamped to NAME_CAP.
   *
   * @return string|null
   *   The excerpt, or NULL for an empty (whitespace-only) value.
   */
  private static function excerpt(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }
    $line = explode("\n", $value)[0];
    return mb_strlen($line) > self::NAME_CAP
      ? mb_substr($line, 0, self::NAME_CAP - 1) . '…'
      : $line;
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
   * The component entities the host's delivered section data resolves.
   *
   * The blueprint's component nodes carry serialized values, not entities;
   * markup resolution needs the entities, so they come from the same
   * staged section data the blueprint was serialized from, matched back
   * to the serialized nodes by component uuid. The tree a read delivers
   * is every document the host's render shows (HostInterface::sections():
   * the template around a locked page, then the page's own slot
   * sections), each node attributed to the host whose document holds it
   * by the spine's verdict stamp; the entities are read from the same
   * list, so a template's inline block on a page canvas carries its
   * markup facts exactly as the page's own blocks do.
   *
   * @return array<string, \Drupal\Core\Entity\FieldableEntityInterface>
   *   Component uuid => block_content entity.
   */
  private function componentEntitiesOf(HostInterface $host): array {
    $entities = [];
    foreach ($host->sections() as $section) {
      $this->collectComponentEntities($section, $entities);
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
