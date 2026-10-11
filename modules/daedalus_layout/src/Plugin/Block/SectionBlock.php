<?php

namespace Drupal\daedalus_layout\Plugin\Block;

use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\PluginDependencyTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Host\InnerRender;
use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The nested-section container: a component whose config embeds ONE Section.
 *
 * This plugin is dev-facing plumbing, exactly like the layout field row is at
 * the top level. It never surfaces to users or the AI: a nested section is
 * addressed by its SECTION uuid at every surface, and this wrapper is how that
 * section rides inside a parent section's component list. There is no special
 * nestable kind of section; top-level vs nested is position, not kind.
 *
 * Contracts (load-bearing, do not relax):
 * - The `section` config key holds exactly ONE Section::toArray() value.
 *   Always produce it via Section::toArray(); SectionComponent::fromArray()
 *   and Section::fromArray() are not defensive, so hand-written arrays are
 *   forbidden. An empty/missing value is invalid state and build() throws.
 * - Component uuid == embedded section uuid (the section's daedalus_layout
 *   third-party uuid). One identity: selection, ops, styling, and the tree
 *   all speak container:{uuid}, and the wrapper component is findable by that
 *   same uuid with no mapping table. Copy/duplicate must re-mint BOTH
 *   together (TreeIndex collision repair keeps them in lockstep). Use
 *   ::createComponent() so the invariant holds by construction.
 * - Context forwarding is a HARD contract, not a convenience: the plugin
 *   forwards the render's contexts to the embedded section's render under
 *   every id consumers read. The view mode is required (rendering without
 *   it throws). The subject (the entity every block reads, under
 *   layout_builder.entity and entity) is forwarded when the render has
 *   one and absent when it has none: the site frame renders around every
 *   route, and a view page or a form has no subject, so a subtree then
 *   renders with no entity in context and a block that needs one renders
 *   nothing (InnerRender::sectionRenderArray(), the one section render
 *   every nested depth goes through). The root entity
 *   (daedalus_layout.root_entity, the host whose document the subtree
 *   belongs to and whose map its styles live on) is forwarded as received
 *   when the render names one, else it is the subject, as it was when the
 *   two could never differ.
 *
 * The write channel for the embedded section is always
 * $component->setConfiguration() with a fresh Section::toArray(); never write
 * through a plugin instance (SectionComponent::getPlugin() returns a fresh
 * instance per call, and BlockPluginTrait::setConfiguration()'s mergeDeep can
 * resurrect stale trees).
 */
#[Block(
  id: 'daedalus_layout_section',
  admin_label: new TranslatableMarkup('Container'),
  category: new TranslatableMarkup('Daedalus Layout'),
  context_definitions: [
    // Generic 'entity' data type: any fieldable entity can be the subject.
    // (EntityContextDefinition would mint the invalid type entity:entity.)
    'entity' => new ContextDefinition('entity', new TranslatableMarkup('Subject'), required: FALSE),
    'view_mode' => new ContextDefinition('string', new TranslatableMarkup('View mode')),
    // The root entity, under the render id itself: never mapped (a
    // mapping names a context every render must carry), read when the
    // render names a root other than the subject (an inner document's
    // host, InnerRender::ROOT_CONTEXT_ID).
    NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID => new ContextDefinition('entity', new TranslatableMarkup('Root entity'), required: FALSE),
  ],
)]
final class SectionBlock extends BlockBase implements ContainerFactoryPluginInterface {

  use PluginDependencyTrait;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected InnerRender $innerRender,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('daedalus_blueprint.inner_render'));
  }

  /**
   * The block plugin id.
   */
  public const PLUGIN_ID = 'daedalus_layout_section';

  /**
   * The configuration key holding the embedded Section::toArray() value.
   */
  public const SECTION_KEY = 'section';

  /**
   * The default context mapping.
   *
   * Satisfies the forwarding contract in both render paths: live
   * (buildSections) and preview (getContextsDuringPreview) both provide
   * layout_builder.entity and view_mode.
   */
  public const CONTEXT_MAPPING = [
    'entity' => 'layout_builder.entity',
    'view_mode' => 'view_mode',
  ];

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      // '0', not FALSE: container components live in defaults displays too,
      // and core's block_settings schema types label_display as a string
      // (Choice '0'/'visible'), so a boolean fails strict config validation.
      'label_display' => '0',
      self::SECTION_KEY => NULL,
      'context_mapping' => self::CONTEXT_MAPPING,
    ];
  }

  /**
   * {@inheritdoc}
   *
   * A container's dependencies are its subtree's dependencies: the embedded
   * section's layout plugin plus every child component's plugin,
   * recursively (a child container's plugin recurses here again). This is
   * what keeps core's one-level dependency walks correct at depth: a
   * defaults display calculating its config dependencies asks each root
   * component's plugin, and a container answers for everything inside it.
   *
   * The surgical counterpart lives in
   * \Drupal\daedalus_layout\Entity\LayoutBuilderEntityViewDisplay::onDependencyRemoval(),
   * which prunes the one offending descendant instead of letting core drop
   * the whole container because of the aggregation done here.
   */
  public function calculateDependencies() {
    $this->dependencies = parent::calculateDependencies();
    $section = $this->getSection();
    $this->calculatePluginDependencies($section->getLayout());
    foreach ($section->getComponents() as $component) {
      $this->calculatePluginDependencies($component->getPlugin());
    }
    return $this->dependencies;
  }

  /**
   * {@inheritdoc}
   *
   * Direct plugin renders only: the component render path never gets here.
   * NestedSectionRenderArray short-circuits the component build event and
   * renders through buildSection() instead, because only the event knows
   * whether the render is a Layout Builder preview.
   */
  public function build() {
    return $this->buildSection();
  }

  /**
   * Renders the embedded section with the forwarded contexts.
   *
   * The container render seam: NestedSectionRenderArray calls this with the
   * event's preview flag so preview shape propagates into the embedded
   * section's own component builds (plugin build() cannot see preview).
   *
   * @param bool $in_preview
   *   Whether the render is a Layout Builder preview.
   *
   * @return array
   *   The embedded section's render array.
   */
  public function buildSection(bool $in_preview = FALSE): array {
    return $this->innerRender->sectionRenderArray($this->getSection(), $this->forwardedContexts(), $in_preview);
  }

  /**
   * Hydrates the embedded section.
   *
   * This is a per-plugin-instance hydration for rendering and inspection.
   * Mutation flows MUST NOT use it: identity-stable hydration (the same
   * Section object per component per request, so core forms mutating in
   * place are not lost) is owned by the scoped section list machinery.
   *
   * @return \Drupal\layout_builder\Section
   *   The embedded section.
   */
  public function getSection(): Section {
    $section = $this->configuration[self::SECTION_KEY] ?? NULL;
    if (!is_array($section) || $section === []) {
      throw new \LogicException(sprintf('The %s component "%s" holds no embedded section. A nested-section container always embeds exactly one section; an empty container is invalid state (removing a nested section means removing its container component).', self::PLUGIN_ID, $this->configuration['uuid'] ?? 'unknown'));
    }
    return Section::fromArray($section);
  }

  /**
   * Builds the contexts forwarded to the embedded section's render.
   *
   * Under the sections-all-the-way-down model there is no document
   * boundary inside the tree: the subject flowing down is the subject at
   * every depth, and the root (the document's host) is the root at every
   * depth. Within one document the two are the same entity; inside an
   * inner document rendered under an outer one (a linked blueprint, the
   * site frame) the render names the inner host as the root, and the
   * container passes it on.
   *
   * @return \Drupal\Component\Plugin\Context\ContextInterface[]
   *   The forwarded contexts.
   */
  protected function forwardedContexts(): array {
    // getContext() materializes a valueless Context from the definition when
    // no context was applied (it does not throw), so the view mode contract
    // is enforced here: a container rendered without it is a caller bug.
    $view_mode_context = $this->getContext('view_mode');
    if (!$view_mode_context->hasContextValue()) {
      throw new ContextException(sprintf('The %s component "%s" was rendered without its view_mode context. Forwarding it into the embedded section is a hard contract; render containers with the Layout Builder contexts (layout_builder.entity, view_mode) available.', self::PLUGIN_ID, $this->configuration['uuid'] ?? 'unknown'));
    }
    $contexts = ['view_mode' => $view_mode_context];
    $entity_context = $this->getContext('entity');
    if ($entity_context->hasContextValue()) {
      $contexts['entity'] = $entity_context;
      $contexts['layout_builder.entity'] = $entity_context;
    }
    $root_context = $this->getContext(NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID);
    if ($root_context->hasContextValue()) {
      $contexts[NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID] = $root_context;
    }
    elseif ($entity_context->hasContextValue()) {
      $contexts[NestedSectionStorageInterface::ROOT_ENTITY_CONTEXT_ID] = $entity_context;
    }
    return $contexts;
  }

  /**
   * Creates a container component embedding the given section.
   *
   * The one blessed constructor: it enforces component uuid == section uuid
   * and stamps the default context mapping. The section is stamped with an
   * daedalus_layout uuid if it does not carry one yet.
   *
   * @param \Drupal\layout_builder\Section $section
   *   The section to embed.
   * @param string $region
   *   The parent section region to place the component in.
   * @param int $weight
   *   The component weight.
   * @param array $additional
   *   Opaque additional data the component carries (third-party seam, e.g.
   *   blueprint provenance).
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The container component, uuid-locked to the embedded section.
   */
  public static function createComponent(Section $section, string $region, int $weight = 0, array $additional = []): SectionComponent {
    $uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    if (!$uuid) {
      $uuid = \Drupal::service('uuid')->generate();
      $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
    }
    $component = new SectionComponent($uuid, $region, [
      'id' => self::PLUGIN_ID,
      'label_display' => '0',
      self::SECTION_KEY => $section->toArray(),
      'context_mapping' => self::CONTEXT_MAPPING,
    ], $additional);
    $component->setWeight($weight);
    return $component;
  }

  /**
   * Determines whether a component is a nested-section container.
   *
   * Cheap (no plugin instantiation); the check TreeIndex-style recursion
   * uses to descend into embedded sections.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component to check.
   *
   * @return bool
   *   TRUE if the component embeds a section.
   */
  public static function isSectionComponent(SectionComponent $component): bool {
    return $component->getPluginId() === self::PLUGIN_ID;
  }

  /**
   * Hydrates the section a container component embeds, when it is one.
   *
   * Read-only value walks (rendering-adjacent consumers like daedalus_stylish's
   * container-roots CSS) descend the embedded tree through this; mutation
   * flows go through the scoped section list machinery, which owns
   * identity-stable hydration.
   *
   * @param \Drupal\layout_builder\SectionComponent $component
   *   The component to hydrate from.
   *
   * @return \Drupal\layout_builder\Section|null
   *   A freshly hydrated section, or NULL when the component is not a
   *   nested-section container (or holds no valid embedded value).
   */
  public static function embeddedSection(SectionComponent $component): ?Section {
    if (!self::isSectionComponent($component)) {
      return NULL;
    }
    $section = $component->get('configuration')[self::SECTION_KEY] ?? NULL;
    if (!is_array($section) || $section === []) {
      return NULL;
    }
    return Section::fromArray($section);
  }

}
