<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Plugin\Block;

use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Entity\FrameInterface;
use Drupal\daedalus_blueprint\Frame\RouteOutput;
use Drupal\daedalus_blueprint\Host\HostResolver;
use Drupal\daedalus_blueprint\Host\InnerRender;
use Drupal\daedalus_blueprint\LayoutBuilder\FrameHost;
use Drupal\daedalus_blueprint\Plugin\Derivative\SlotDeriver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A slot: the place in a document that the document beneath it fills.
 *
 * Placed by the site builder in a template or the site frame, one
 * derivative per slot id the site declares (SlotDeriver): the
 * configuration is the slot id (the derivative) and an optional default,
 * nothing else, so a template and the frame, which hold only what config
 * can carry, carry it. On every render of the document around it the
 * block shows the innermost filler's section through the one render
 * primitive (InnerRender::slotFiller() then render()): a page's own
 * section tagged with the slot id, under the page as the subject, so a
 * field block in the slot shows the page's own field; when nothing fills
 * the slot, the default, a published blueprint named by machine name,
 * rendered the same way (the first shape of a linked placement); when
 * there is no default either, nothing, which on the editing canvas wears
 * the block stand-in daedalus_ui gives any block that built nothing,
 * labelled with the slot's label, and for a visitor is dropped like any
 * empty block.
 *
 * The outer host, whose document holds the slot block, is the host the
 * fillers are read beneath. In a template render it is the render host
 * of the subject (the template, on a locked page); in the site frame's
 * render it is the frame, which the frame render names in its contexts
 * (FrameHost::CONTEXT_ID), since no walk from a page reaches the frame.
 * One slot of the frame asks no filler: the slot hosting the route's
 * output (InnerRender::routeSlot(): the main slot, FrameHost::MAIN_SLOT,
 * on every page; on a blueprint's canvas the door whose default is that
 * blueprint, so the chrome is edited in situ) prints what the route
 * produced (RouteOutput), the main landmark of every page of the theme,
 * and when that slot is a door the main slot prints the route output's
 * stand-in instead, the sentence saying each page's content shows there.
 *
 * The block is offered where a document declares slots (a template
 * canvas, the frame canvas; PlacementRestrictions): a page fills slots,
 * it never declares them. The inner sections render in the outer's
 * posture: core's component build event hands every preview-aware block
 * the canvas's preview flag (BlockPluginTrait::$inPreview) before build()
 * runs, and the primitive passes it down. The view mode is a hard
 * contract; the subject is not: a frame slot on a view page or a form has
 * none, and then nothing beneath the frame can fill it.
 */
#[Block(
  id: 'daedalus_slot',
  admin_label: new TranslatableMarkup('Slot'),
  category: new TranslatableMarkup('Slots'),
  deriver: SlotDeriver::class,
  context_definitions: [
    'entity' => new ContextDefinition('entity', new TranslatableMarkup('Subject'), required: FALSE),
    'view_mode' => new ContextDefinition('string', new TranslatableMarkup('View mode')),
    FrameHost::CONTEXT_ID => new EntityContextDefinition('entity:' . FrameInterface::ENTITY_TYPE_ID, new TranslatableMarkup('Site frame'), required: FALSE),
    RouteOutput::CONTEXT_ID => new ContextDefinition('any', new TranslatableMarkup('Route output'), required: FALSE),
  ],
)]
final class SlotBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The base plugin id; a slot block is `daedalus_slot:{id}`.
   */
  public const PLUGIN_ID = 'daedalus_slot';

  /**
   * The config object carrying the site's slot roster.
   */
  public const CONFIG_NAME = 'daedalus_blueprint.settings';

  /**
   * The roster key in that config: slot id to label.
   */
  public const ROSTER_KEY = 'slots';

  /**
   * The configuration key naming the default blueprint, or NULL.
   */
  public const DEFAULT_KEY = 'default_blueprint';

  /**
   * The DOM attribute a slot block's root wears on the editing canvas.
   *
   * Its value is the slot id. Stamped by daedalus_ui's block attributes
   * for editors; the drag layer reads it as the empty-door drop target.
   */
  public const DOOR_ATTRIBUTE = 'data-daedalus-slot';

  /**
   * The DOM attribute a claimable slot door wears on the editing canvas.
   *
   * Its value is the machine name of the default blueprint the door shows:
   * stamped by daedalus_ui's block attributes when the canvas host may fill
   * the slot (its insert verdict on the door) and nothing beneath the door
   * fills it yet, so the portal chip offers "Detach into this slot"
   * (detach_blueprint with `slot`) exactly where the op would succeed.
   */
  public const CLAIM_ATTRIBUTE = 'data-daedalus-slot-default';

  /**
   * The default context mapping: the outer render's subject and view mode.
   *
   * The frame and route output contexts are never mapped: they are read
   * under their own ids from the frame render alone, and a mapping to a
   * context a template render never carries would refuse to build.
   */
  public const CONTEXT_MAPPING = [
    'entity' => 'layout_builder.entity',
    'view_mode' => 'view_mode',
  ];

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected InnerRender $innerRender,
    protected HostResolver $hostResolver,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('daedalus_blueprint.inner_render'),
      $container->get('daedalus_blueprint.host_resolver'),
    );
  }

  /**
   * Whether a block plugin id is a slot block.
   */
  public static function isSlot(string $plugin_id): bool {
    return str_starts_with($plugin_id, self::PLUGIN_ID . ':');
  }

  /**
   * The slot id a block plugin id declares, or NULL for any other block.
   *
   * The one reader of the id inside the plugin id: the ownership policy
   * (a slot block is the door a document beneath inserts through), the op
   * door (a placement anchored inside the door lands in the slot's
   * section) and the serializer (the block node's `slot` key) all ask
   * here.
   */
  public static function slotIdOf(string $plugin_id): ?string {
    if (!self::isSlot($plugin_id)) {
      return NULL;
    }
    $id = substr($plugin_id, strlen(self::PLUGIN_ID) + 1);
    return $id === '' ? NULL : $id;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      // '0', not FALSE: a slot lives in a defaults display, whose block
      // settings schema types label_display as a string.
      'label_display' => '0',
      self::DEFAULT_KEY => NULL,
      'context_mapping' => self::CONTEXT_MAPPING,
    ];
  }

  /**
   * The slot id: the derivative.
   */
  public function slotId(): string {
    return (string) $this->getDerivativeId();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $contexts = $this->getContexts();
    if (!$contexts['view_mode']->hasContextValue()) {
      throw new ContextException(sprintf('The slot "%s" was rendered without its view_mode context. Render slots with the Layout Builder contexts (layout_builder.entity, view_mode) available.', $this->slotId()));
    }
    $subject = $contexts['entity']->hasContextValue() ? $contexts['entity']->getContextValue() : NULL;
    if ($subject !== NULL && !$subject instanceof FieldableEntityInterface) {
      $subject = NULL;
    }
    $frame = $contexts[FrameHost::CONTEXT_ID]->hasContextValue() ? $contexts[FrameHost::CONTEXT_ID]->getContextValue() : NULL;

    if ($frame instanceof FrameInterface) {
      $outer = $this->hostResolver->fromEntity($frame);
      $output = $contexts[RouteOutput::CONTEXT_ID]->hasContextValue() ? $contexts[RouteOutput::CONTEXT_ID]->getContextValue() : NULL;
      // The slot hosting the route's output prints it; with no output in
      // the render that slot is main, which refuses to build without one.
      $route_slot = $output instanceof RouteOutput ? $this->innerRender->routeSlot($outer, $output->host) : FrameHost::MAIN_SLOT;
      if ($this->slotId() === $route_slot) {
        return $this->mainContent($output);
      }
      if ($this->slotId() === FrameHost::MAIN_SLOT) {
        // Another slot hosts the route (a blueprint's canvas in its door):
        // main shows where each page's content lands, the stand-in the
        // route output carries, with no landmark of its own since the
        // door prints the page's one main landmark.
        assert($output instanceof RouteOutput);
        return $output->standIn;
      }
    }
    else {
      if ($subject === NULL) {
        // Outside the frame a slot is a template's, and a template renders
        // for a subject (the page, or the canvas's sample): a slot rendered
        // without one is a caller bug, never a silently empty slot.
        throw new ContextException(sprintf('The slot "%s" was rendered without its subject. Render template slots with the Layout Builder contexts (layout_builder.entity, view_mode) available.', $this->slotId()));
      }
      $outer = $this->hostResolver->renderHost($this->hostResolver->fromEntity($subject));
    }

    $fill = $this->innerRender->slotFiller($this->slotId(), $outer, $subject);
    if ($fill !== NULL) {
      return $this->innerRender->render($fill->host, [$fill->section], $contexts, $this->inPreview);
    }
    return $this->defaultDocument($contexts);
  }

  /**
   * The route's output in the frame slot hosting it.
   *
   * The main landmark of the page and the skip link's target ride the
   * slot: the frame's document is the whole page, and the main content is
   * where the route's output lands in it (InnerRender::routeSlot()), so
   * the theme's page template prints the frame and nothing else.
   *
   * @param \Drupal\daedalus_blueprint\Frame\RouteOutput|null $output
   *   The route's output, as the frame render carries it.
   *
   * @return array
   *   The main landmark holding the main content.
   */
  protected function mainContent(?RouteOutput $output): array {
    if ($output === NULL) {
      throw new \LogicException(sprintf('The frame\'s slot "%s" was rendered without the route\'s output. Render a frame through InnerRender::renderFrame().', $this->slotId()));
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<main role="main"><a id="main-content" tabindex="-1"></a>{{ content }}</main>',
      '#context' => ['content' => $output->mainContent],
    ];
  }

  /**
   * The slot's default when nothing fills it: a blueprint, or nothing.
   *
   * @param \Drupal\Core\Plugin\Context\ContextInterface[] $contexts
   *   The render's contexts.
   *
   * @return array
   *   The default blueprint's document under the outer's contexts, a
   *   cache-only stub when the default names no published blueprint, or
   *   nothing.
   */
  protected function defaultDocument(array $contexts): array {
    $default = $this->configuration[self::DEFAULT_KEY] ?? NULL;
    if (!is_string($default) || $default === '') {
      return [];
    }
    $blueprint = $this->innerRender->blueprintHost($default);
    if ($blueprint === NULL) {
      // The default names no published blueprint entity: nothing shows,
      // until one is published under the name.
      return ['#cache' => ['tags' => ['blueprint_list']]];
    }
    return $this->innerRender->render($blueprint, $blueprint->ownSections(), $contexts, $this->inPreview);
  }

}
