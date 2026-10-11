<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Host;

use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContext;
use Drupal\Core\Plugin\PreviewAwarePluginInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\Frame\RouteOutput;
use Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\LayoutBuilder\LayoutSerializer;
use Drupal\daedalus_blueprint\Plugin\Block\SlotBlock;
use Drupal\daedalus_blueprint\Slot\SlotDoor;
use Drupal\daedalus_blueprint\Slot\SlotFill;
use Drupal\daedalus_blueprint\Slot\SlotRoster;
use Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;

/**
 * The one primitive every join of the document stack renders through.
 *
 * A page is a stack of documents, each a host, each linked for everything
 * beneath it and leaving slots the next layer fills: the site frame, the
 * bundle template, the page. Every join between two layers is one act,
 * "render an inner document inside an outer render, under the outer
 * host's contexts": the inner host's sections are rendered with the
 * OUTER render's subject as the entity every block in them reads (so a
 * field block inside the inner shows the outer page's own field) and
 * each section keeps the identity of the host whose document holds it
 * (its own section uuid, stamped for the canvas), never the outer's. The
 * template slot (the inner is the page's own slot section), the linked
 * blueprint (the inner is a blueprint, foreign to the page) and the
 * frame's slots are the instances; the slot block and the linked
 * placement are thin adapters over render(), and the page display
 * variant over renderFrame().
 *
 * The outermost layer is rendered by the same primitive as a document
 * root (renderDocument()): the site frame's own sections, around the
 * route's output, with the route's entity as the subject when the route
 * has one. A view page, a form or a 404 has none, so a render may carry
 * NO subject: the inner's sections render with no entity in context, a
 * block that needs one (a field block) renders nothing, and the view mode
 * stays the one hard contract.
 *
 * Nothing here decides access or ownership: each node is judged by the
 * host whose document holds it, which the annotator attributes from the
 * stamped identity, and a render is a stack of hosts rather than one.
 *
 * Styles follow the inner host, and the primitive says so in two ways.
 * The inner host's entity rides the render as daedalus_layout's root
 * entity context (self::ROOT_CONTEXT_ID), the one context that names
 * which document a block's styles live on, so the inner document's inline
 * blocks are rooted on the INNER entity in the root entity registry as
 * they build, and daedalus_stylish's render styles, the live-attribution
 * demand and daedalus_ui's field ownership answer the inner host for them
 * (the subject stays what every block READS; the root is what owns it).
 * And hook_daedalus_blueprint_inner_render_alter() hands the finished
 * render to the modules that deliver on a document root, with the inner
 * host and the outer subject, so the inner's style map lands inside the
 * render when the inner is not the subject's own document (a slot filled
 * by the page's own section is the page's; its own view delivers).
 */
class InnerRender {

  /**
   * The context ids the inner sections read the outer's subject under.
   *
   * Core's own id (`layout_builder.entity`) and the generic `entity` every
   * context-aware block maps by default. Both carry the subject when the
   * outer render has one, neither when it has none.
   */
  protected const SUBJECT_CONTEXT_IDS = ['entity', 'layout_builder.entity'];

  /**
   * The context id naming the entity a render's blocks are rooted on.
   *
   * The stable root-entity id of daedalus_layout
   * (NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID), spelled here
   * rather than through the module's constant so the spine stays loadable
   * without it. The primitive sets it to the INNER host's entity; the
   * container block forwards it through every nested depth.
   */
  public const ROOT_CONTEXT_ID = 'daedalus_layout.root_entity';

  /**
   * Constructs the primitive.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostResolver $hostResolver
   *   The host resolver.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Block\BlockManagerInterface $blockManager
   *   The block manager (a component's context definitions).
   * @param \Drupal\Core\Theme\ThemeManagerInterface $themeManager
   *   The theme manager: the frame around a render is the active theme's.
   * @param \Drupal\daedalus_blueprint\LayoutBuilder\DaedalusLayoutAdapter $layoutAdapter
   *   The layout adapter: the slot blocks a document places.
   * @param \Drupal\daedalus_blueprint\Slot\SlotRoster $slotRoster
   *   The slot roster: which tier of the stack declares a slot, so only a
   *   frame-tier door of the frame can host the route's output.
   */
  public function __construct(
    protected HostResolver $hostResolver,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected ModuleHandlerInterface $moduleHandler,
    protected BlockManagerInterface $blockManager,
    protected ThemeManagerInterface $themeManager,
    protected DaedalusLayoutAdapter $layoutAdapter,
    protected SlotRoster $slotRoster,
  ) {}

  /**
   * Renders sections of an inner host inside an outer render.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $inner
   *   The host whose document holds the sections.
   * @param \Drupal\layout_builder\Section[] $sections
   *   The sections to render, in order: a slot's one filling section, or
   *   a linked blueprint's whole document.
   * @param \Drupal\Core\Plugin\Context\ContextInterface[] $contexts
   *   The outer render's contexts: the view mode under `view_mode`
   *   (required) and the subject under `entity` or
   *   `layout_builder.entity` when the outer render has one: what a block
   *   placed in the outer document receives. A render with no subject
   *   (the frame on a view page or a form) carries none.
   * @param bool $in_preview
   *   Whether the outer render is the editing canvas (core's preview
   *   flag), so the inner blocks render in the same posture.
   *
   * @return array
   *   One child per section, keyed by delta, each the section's layout
   *   render array stamped with its identity for the canvas at nested
   *   depth, carrying the inner host's cacheability. Empty when there are
   *   no sections.
   *
   * @throws \Drupal\Component\Plugin\Exception\ContextException
   *   When the outer contexts carry no view mode: a hard contract, since
   *   rendering without it would silently change every block's mode.
   */
  public function render(HostInterface $inner, array $sections, array $contexts, bool $in_preview = FALSE): array {
    return $this->renderSections($inner, $sections, $contexts, $in_preview, TRUE);
  }

  /**
   * Renders a host's own document as a root, outside any other document.
   *
   * The outermost layer of a render: the host's own sections, stamped as
   * root sections of the host's storage and wrapped in daedalus_layout's
   * layout root (the one element root sections render inside, the
   * document's own root for styles and selection), exactly as an entity's
   * sections render on its own page. The site frame is the one host
   * rendered this way today, by the page display variant.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose document is rendered.
   * @param \Drupal\Core\Plugin\Context\ContextInterface[] $contexts
   *   The render's contexts, as for render().
   * @param bool $in_preview
   *   Whether the render is an editing canvas.
   *
   * @return array
   *   The layout root holding the sections, or an empty array when the
   *   document has none.
   */
  public function renderDocument(HostInterface $host, array $contexts, bool $in_preview = FALSE): array {
    return $this->renderSections($host, $host->ownSections(), $contexts, $in_preview, FALSE);
  }

  /**
   * Renders a site frame around what a route produced.
   *
   * The frame render's contexts are built here, the one place that spells
   * them: the frame's view mode; the frame itself under
   * FrameHost::CONTEXT_ID (the slot block's outer host); the route's
   * output under RouteOutput::CONTEXT_ID (the slot routeSlot() names
   * prints its main content, a placed title block shows its title); and
   * the route's
   * entity as the subject when the route has one, so a field block in a
   * linked composition the frame places shows the page's own field.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $frame
   *   The frame host.
   * @param \Drupal\daedalus_blueprint\Frame\RouteOutput $output
   *   What the route produced.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $subject
   *   The route's entity, or NULL on a route without one.
   * @param bool $in_preview
   *   Whether the render is the frame's editing canvas.
   *
   * @return array
   *   The frame's layout root holding its sections, or an empty array for
   *   an empty frame.
   */
  public function renderFrame(HostInterface $frame, RouteOutput $output, ?FieldableEntityInterface $subject, bool $in_preview = FALSE): array {
    return $this->renderDocument($frame, $this->frameContexts($frame, $output, $subject), $in_preview);
  }

  /**
   * The slot of a frame that hosts the route's output.
   *
   * The one rule placing what the route produced inside the frame, asked
   * by every frame slot block (the slot named prints the output, main
   * prints the stand-in when it is not the one) and by the portal stamp
   * (the slot hosting the route is the page's own, never a door to the
   * frame). The frame's main slot (FrameHost::MAIN_SLOT) hosts every route
   * but one kind: a blueprint's canonical route, its canvas, lands in the
   * frame-tier door whose default (SlotBlock::DEFAULT_KEY) names the
   * blueprint being edited, so the chrome's canvas renders in situ, in the
   * door that shows the blueprint by reference on every other page, and
   * the frame around it is the context it is edited for. A blueprint no
   * door defaults to, a door placed under the template tier (no door of
   * the frame, SlotRoster::declaredBy()) and every other route answer
   * main. The first matching door wins, in document order: two doors
   * defaulting to one blueprint would show it twice on a page already.
   * The rule reads the route's host alone (RouteOutput::$host), so the
   * showing of a frame slot (VerdictStamper::showing()) asks it with the
   * canvas host and the render with the output's.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $frame
   *   The frame host.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $route_host
   *   The route's host, or NULL on a host-less route.
   *
   * @return string
   *   The slot id.
   */
  public function routeSlot(HostInterface $frame, ?HostInterface $route_host): string {
    if (!$route_host instanceof BlueprintHost) {
      return FrameHost::MAIN_SLOT;
    }
    $machine_name = $route_host->machineName();
    foreach ($this->layoutAdapter->components($frame->ownSections()) as $component) {
      $slot = SlotBlock::slotIdOf($component->getPluginId());
      if ($slot === NULL || $slot === FrameHost::MAIN_SLOT || !$this->slotRoster->declaredBy($frame, $slot)) {
        continue;
      }
      if ((new SlotDoor($frame, $component))->default() === $machine_name) {
        return $slot;
      }
    }
    return FrameHost::MAIN_SLOT;
  }

  /**
   * The contexts a site frame's document renders under.
   *
   * The one spelling of the frame render's contexts, for renderFrame() and
   * for the frame's editing canvas (daedalus_layout's layout_builder_plus
   * element renders the frame's sections one by one, in the canvas
   * posture, under exactly these). The frame storage's own `entity`
   * context (the frame itself) is deliberately NOT among them: the frame
   * is nobody's subject, and a block reading `entity` must find the
   * route's entity or nothing.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $frame
   *   The frame host.
   * @param \Drupal\daedalus_blueprint\Frame\RouteOutput $output
   *   What the route produced (a stand-in on the canvas).
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $subject
   *   The route's entity, or NULL on a route without one.
   *
   * @return \Drupal\Core\Plugin\Context\ContextInterface[]
   *   The contexts, as render() takes them.
   */
  public function frameContexts(HostInterface $frame, RouteOutput $output, ?FieldableEntityInterface $subject): array {
    $contexts = [
      'view_mode' => new Context(new ContextDefinition('string'), $frame->viewMode()),
      FrameHost::CONTEXT_ID => EntityContext::fromEntity($frame->entity()),
      RouteOutput::CONTEXT_ID => new Context(new ContextDefinition('any'), $output),
    ];
    if ($subject !== NULL) {
      $contexts += array_fill_keys(self::SUBJECT_CONTEXT_IDS, EntityContext::fromEntity($subject));
    }
    return $contexts;
  }

  /**
   * The render of render(), renderDocument() and renderFrame().
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $inner
   *   The host whose document holds the sections.
   * @param \Drupal\layout_builder\Section[] $sections
   *   The sections.
   * @param \Drupal\Core\Plugin\Context\ContextInterface[] $contexts
   *   The outer render's contexts.
   * @param bool $in_preview
   *   The preview flag.
   * @param bool $nested
   *   TRUE inside an outer document (the sections are stamped at nested
   *   depth, no storage, no layout root); FALSE for a document root (the
   *   host's storage stamped, the sections wrapped in the layout root).
   *
   * @return array
   *   The render.
   */
  protected function renderSections(HostInterface $inner, array $sections, array $contexts, bool $in_preview, bool $nested): array {
    $view_mode = $contexts['view_mode'] ?? NULL;
    if ($view_mode === NULL || !$view_mode->hasContextValue()) {
      throw new ContextException(sprintf('A document of %s %s was rendered without the render\'s view_mode context.', $inner->entityTypeId(), $inner->id() ?? 'new'));
    }
    $subject = $contexts['entity'] ?? $contexts['layout_builder.entity'] ?? NULL;
    if ($subject !== NULL && !$subject->hasContextValue()) {
      $subject = NULL;
    }
    $entity = $inner->entity();
    // The subject is what every block reads, under both ids it is read by;
    // the root is the inner host, whose document the blocks belong to. A
    // render with no subject forwards none: a valueless entity context
    // would read as a missing required value to the blocks that need one,
    // the same as no context, so the ids are simply absent.
    $forwarded = [self::ROOT_CONTEXT_ID => EntityContext::fromEntity($entity), 'view_mode' => $view_mode];
    $forwarded += $subject !== NULL
      ? array_fill_keys(self::SUBJECT_CONTEXT_IDS, $subject) + $contexts
      : array_diff_key($contexts, array_flip(self::SUBJECT_CONTEXT_IDS));

    $build = [];
    $stamps = $this->moduleHandler->moduleExists('daedalus_layout');
    $storage = $nested || !$stamps ? NULL : $inner->sectionStorage();
    foreach (array_values($sections) as $delta => $section) {
      $build[$delta] = $this->sectionRenderArray($section, $forwarded, $in_preview);
      if ($stamps) {
        // The section's identity for the canvas (its selectable key, the
        // local delta and the layout address), the stamp daedalus_layout
        // puts on every section it renders, root or nested. A nested
        // section sits inside the outer document's markup, so the composed
        // container resolves its form at nested depth, the way an embedded
        // section does; a root section wears its storage's type and id
        // like a page's own. The inner host's entity is the demand host
        // for the key: its map is where the section's styles live.
        LayoutBuilderEntityViewDisplay::getSectionAttributes($delta, $build[$delta], $storage, $section, $entity);
        if ($nested) {
          $build[$delta]['#daedalus_layout_nested'] = TRUE;
        }
      }
    }
    if ($build !== [] && !$nested) {
      $build['#type'] = 'container';
      if ($stamps) {
        $build['#attributes']['class'][] = LayoutBuilderEntityViewDisplay::LAYOUT_ROOT_CLASS;
      }
    }
    if ($build !== []) {
      CacheableMetadata::createFromRenderArray($build)
        ->addCacheableDependency($entity)
        ->applyTo($build);
    }
    $subject_entity = $subject?->getContextValue();
    $this->moduleHandler->alter('daedalus_blueprint_inner_render', $build, $inner, $subject_entity);
    return $build;
  }

  /**
   * The render array of one section under a render's contexts.
   *
   * Core's Section::toRenderArray() with one rule added: a component whose
   * plugin requires a context the render does not carry a value for
   * renders nothing, where core would throw from the component's context
   * mapping. A render may carry no subject (the frame on a view page),
   * and a block that needs one (a field block, a composition that holds
   * one) is then unresolvable rather than fatal: the spine's fit refuses
   * such a block where the host has no subject, and this is the render's
   * side of the same rule for whatever reaches it. Every Daedalus render
   * of a section inside another document goes through here, the
   * container block's embedded section included, so nested depth answers
   * the same.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section.
   * @param \Drupal\Core\Plugin\Context\ContextInterface[] $contexts
   *   The render's contexts.
   * @param bool $in_preview
   *   The preview flag.
   *
   * @return array
   *   The section's layout render array.
   */
  public function sectionRenderArray(Section $section, array $contexts, bool $in_preview = FALSE): array {
    $regions = [];
    foreach ($section->getComponents() as $component) {
      if (!$this->satisfiable($component, $contexts)) {
        continue;
      }
      $output = $component->toRenderArray($contexts, $in_preview);
      if ($output) {
        $regions[$component->getRegion()][$component->getUuid()] = $output;
      }
    }
    $layout = $section->getLayout($contexts);
    if ($layout instanceof PreviewAwarePluginInterface) {
      $layout->setInPreview($in_preview);
    }
    return $layout->build($regions);
  }

  /**
   * Whether a render's contexts satisfy every context a component requires.
   *
   * The condition core's context mapping throws on
   * (ContextHandler::applyContextMapping(): a required context absent or
   * valueless under the id the component maps it from), asked before the
   * component builds. An unknown plugin is left to core, whose fallback
   * renders in its place.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component.
   * @param \Drupal\Core\Plugin\Context\ContextInterface[] $contexts
   *   The render's contexts.
   *
   * @return bool
   *   TRUE when the component can build under these contexts.
   */
  protected function satisfiable(SectionComponent $component, array $contexts): bool {
    $definition = $this->blockManager->getDefinition($component->getPluginId(), FALSE);
    if ($definition === NULL) {
      return TRUE;
    }
    $mapping = $component->get('configuration')['context_mapping'] ?? [];
    foreach ($definition['context_definitions'] ?? [] as $name => $context_definition) {
      if (!$context_definition->isRequired()) {
        continue;
      }
      $context_id = $mapping[$name] ?? $name;
      if (!isset($contexts[$context_id]) || !$contexts[$context_id]->hasContextValue()) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * The innermost host filling a slot in a render.
   *
   * The slot block sits in the document of the outer host (the template
   * whose render the page shows, or the site frame around the route).
   * Every host beneath it in the stack may fill the slot with a top-level
   * section of its own tagged with the slot id (LayoutSerializer::slotOf()):
   * the subject's host first, then each foreign owner up to, and never
   * including, the outer (a document never fills its own slot). The first
   * fill found is the innermost: a page over its template over the slot's
   * default. For a frame slot the walk runs out of owners (the template
   * has none) without ever reaching the frame, so a template's own fill of
   * a frame slot is read. Read as the store stands: no host is seeded
   * here. A render with no subject (a view page inside the frame) has no
   * document beneath the outer, so nothing fills.
   *
   * @param string $slot
   *   The slot id.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $outer
   *   The host whose document holds the slot block.
   * @param \Drupal\Core\Entity\FieldableEntityInterface|null $subject
   *   The subject the outer render shows (its `layout_builder.entity`),
   *   or NULL when the render has none.
   *
   * @return \Drupal\daedalus_blueprint\Slot\SlotFill|null
   *   The filler and its section, or NULL when nothing fills the slot.
   */
  public function slotFiller(string $slot, HostInterface $outer, ?FieldableEntityInterface $subject): ?SlotFill {
    if ($subject === NULL) {
      return NULL;
    }
    $candidate = $this->hostResolver->fromEntity($subject);
    while ($candidate !== NULL && !self::sameHost($candidate, $outer)) {
      $candidate->declineSeed();
      foreach ($candidate->ownSections() as $section) {
        if (LayoutSerializer::slotOf($section) === $slot) {
          return new SlotFill($candidate, $section);
        }
      }
      $candidate = $this->hostResolver->foreignOwner($candidate);
    }
    return NULL;
  }

  /**
   * The door of a slot above a host: the slot block declaring it.
   *
   * The mirror image of slotFiller(). A host fills slots declared by the
   * documents around it: the template it renders inside (its foreign
   * owner, and that owner's owner, up the stack) and the site frame around
   * every route of the theme. The first document found declaring the slot
   * (a slot block daedalus_slot:{id} at any depth) is the door; the frame's
   * main slot is never one (it holds the route's output, which no document
   * fills). NULL when nothing around the host declares the slot, which is
   * also the answer for a host that fills no slots (the frame, a
   * blueprint: the frame renders around them too, but their sections are
   * never read as fills). The frame is the active theme's: the one
   * rendered around the request, as the page display variant chooses it.
   *
   * @param string $slot
   *   The slot id.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host beneath the door (a page, or a template filling a frame
   *   slot).
   *
   * @return \Drupal\daedalus_blueprint\Slot\SlotDoor|null
   *   The door, or NULL.
   */
  public function slotDoor(string $slot, HostInterface $host): ?SlotDoor {
    if ($slot === FrameHost::MAIN_SLOT || !self::fillsSlots($host)) {
      return NULL;
    }
    $candidate = $this->hostResolver->foreignOwner($host);
    while ($candidate !== NULL) {
      $door = $this->doorIn($candidate, $slot);
      if ($door !== NULL) {
        return $door;
      }
      $candidate = $this->hostResolver->foreignOwner($candidate);
    }
    $frame = $this->frameHost();
    return $frame === NULL ? NULL : $this->doorIn($frame, $slot);
  }

  /**
   * Whether a section of a host's own document renders through a slot.
   *
   * A slot section's place on the page is the slot's: the document around
   * the host renders it inside its slot block (slotFiller() reads it
   * there), so the host's own root render (its entity view, its canvas)
   * leaves it out, or the page would show the fill twice (a locked page's
   * own sections are never rendered by core at all; an overridable page's
   * are, in its own flow, and this is the one rule that keeps a fill out
   * of it). A slot section whose slot nothing around the host declares
   * (a slot the frame or template stopped placing) has no place but the
   * flow and renders there, visible rather than lost.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose own document holds the section.
   * @param \Drupal\layout_builder\Section $section
   *   A top-level section of that document.
   *
   * @return bool
   *   TRUE when a slot around the host renders the section, so the host's
   *   own root render skips it.
   */
  public function renderedBySlot(HostInterface $host, Section $section): bool {
    $slot = LayoutSerializer::slotOf($section);
    return $slot !== NULL && $this->slotDoor($slot, $host) !== NULL;
  }

  /**
   * Whether a host is one that fills slots of the documents around it.
   *
   * A page (content host) and a template (display host, filling a frame
   * slot for every page of its bundle) do; the frame declares slots and
   * fills none, and a blueprint is material shown inside documents, never
   * a layer of the stack.
   */
  public static function fillsSlots(HostInterface $host): bool {
    return $host->hasDocument() && !$host instanceof FrameHost && !$host instanceof BlueprintHost;
  }

  /**
   * The slot block declaring a slot in a holder's own document, as a door.
   */
  protected function doorIn(HostInterface $holder, string $slot): ?SlotDoor {
    $holder->declineSeed();
    foreach ($this->layoutAdapter->components($holder->ownSections()) as $component) {
      if (SlotBlock::slotIdOf($component->getPluginId()) === $slot) {
        return new SlotDoor($holder, $component);
      }
    }
    return NULL;
  }

  /**
   * The site frame of a theme, as a host.
   *
   * The active theme's when none is named: the frame the page display
   * variant renders around the request's route (FramePageVariant chooses
   * by the same name), so a question asked during a request about "the
   * frame around this page" and the render agree.
   *
   * @param string|null $theme
   *   The theme's machine name, or NULL for the active theme.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The frame host, or NULL when the theme has no frame.
   */
  public function frameHost(?string $theme = NULL): ?HostInterface {
    $theme ??= $this->themeManager->getActiveTheme()->getName();
    $frame = $this->entityTypeManager->getStorage(FrameInterface::ENTITY_TYPE_ID)->load($theme);
    return $frame instanceof FrameInterface && $this->hostResolver->hosts($frame)
      ? $this->hostResolver->fromEntity($frame)
      : NULL;
  }

  /**
   * The host of a published blueprint entity, by machine name.
   *
   * The default of a slot and a linked placement name a blueprint by its
   * machine name; only an ENTITY has a document a render can show live (a
   * shipped file is material for copies, not a host). The entity type is
   * the library module's, known here by its id so the spine needs none of
   * its classes; without the library no blueprint exists.
   *
   * @param string $machine_name
   *   The blueprint's machine name.
   *
   * @return \Drupal\daedalus_blueprint\Host\HostInterface|null
   *   The blueprint host, or NULL when no published blueprint entity
   *   carries the name.
   */
  public function blueprintHost(string $machine_name): ?HostInterface {
    if (!$this->entityTypeManager->hasDefinition(BlueprintHost::ENTITY_TYPE_ID)) {
      return NULL;
    }
    $matches = $this->entityTypeManager->getStorage(BlueprintHost::ENTITY_TYPE_ID)->loadByProperties([
      BlueprintHost::MACHINE_NAME_FIELD => $machine_name,
      'status' => 1,
    ]);
    $blueprint = reset($matches);
    return $blueprint instanceof FieldableEntityInterface ? $this->hostResolver->fromEntity($blueprint) : NULL;
  }

  /**
   * Whether two host objects address the same document.
   *
   * Hosts are fresh objects per resolution; identity is the entity's.
   */
  protected static function sameHost(HostInterface $a, HostInterface $b): bool {
    return $a->entityTypeId() === $b->entityTypeId() && $a->id() === $b->id();
  }

}
