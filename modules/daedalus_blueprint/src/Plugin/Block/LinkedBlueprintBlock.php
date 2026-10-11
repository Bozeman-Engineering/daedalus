<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Plugin\Block;

use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\Context\ContextDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Host\InnerRender;
use Drupal\daedalus_blueprint\Plugin\Derivative\LinkedBlueprintDeriver;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A linked placement: a blueprint shown by reference inside a document.
 *
 * The blueprint is the one shared-composition entity, placed into a
 * document in one of two modes. Detached (place_blueprint's default) is a
 * copy: the instantiator stamps the blueprint's tree into the host with
 * fresh identity and a provenance stamp, and nothing propagates forward.
 * Linked is this block: one node in the host's document
 * (`daedalus_blueprint_linked:{machine_name}`, one derivative per
 * published block-scope blueprint entity, LinkedBlueprintDeriver) that
 * renders the blueprint's own sections LIVE through the one render
 * primitive (InnerRender::render(): the blueprint's document under the
 * outer host's contexts, so a field block inside the blueprint shows the
 * outer page's own field), so editing the blueprint changes every page
 * that links it. The subject is optional: the site frame places a linked
 * composition around every route, and a view page or a form has no
 * entity, so the blueprint then renders with no subject and a field block
 * in it renders nothing. The configuration is the context mapping and
 * nothing else (a template and the site frame carry it as config); the blueprint
 * is named by the plugin id, the lean tree spells the node as
 * `{kind: linked, blueprint: <machine_name>}` and the dialect as
 * `<pb-linked blueprint="">` (LayoutSerializer, DialectPrinter).
 *
 * The node is foreign on the page that links it: its content is the
 * blueprint's document, judged by the blueprint host, so the ownership
 * policy refuses `content` on the node with FOREIGN_DOCUMENT carrying a
 * portal to the blueprint's canvas for an actor who may update it; the
 * placement itself (where it sits, its own box styles) stays the page's.
 * Styles follow the blueprint host: the primitive delivers the inner
 * host's style map inside the render and roots the blueprint's inline
 * blocks on the blueprint, so a blueprint-owned variant worn inside is
 * at home (the render IS the owner's document; no stamp needed). The
 * blueprint entity is a cache dependency of the render, so publishing a
 * new revision reaches every linking page. Only an ENTITY is linkable: a
 * shipped file has no host and no live document.
 */
#[Block(
  id: 'daedalus_blueprint_linked',
  admin_label: new TranslatableMarkup('Linked blueprint'),
  category: new TranslatableMarkup('Linked blueprints'),
  deriver: LinkedBlueprintDeriver::class,
  context_definitions: [
    'entity' => new ContextDefinition('entity', new TranslatableMarkup('Subject'), required: FALSE),
    'view_mode' => new ContextDefinition('string', new TranslatableMarkup('View mode')),
  ],
)]
final class LinkedBlueprintBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The base plugin id.
   *
   * A linked placement is `daedalus_blueprint_linked:{machine_name}`.
   */
  public const PLUGIN_ID = 'daedalus_blueprint_linked';

  /**
   * The lean tree's kind for a linked node.
   */
  public const KIND = 'linked';

  /**
   * The lean tree's key naming the linked blueprint (its machine name).
   */
  public const BLUEPRINT_KEY = 'blueprint';

  /**
   * The class the render's wrapper wears: the blueprint document's root.
   */
  public const WRAPPER_CLASS = 'daedalus-blueprint-linked';

  /**
   * The default context mapping: the outer render's subject and view mode.
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
   * Whether a block plugin id is a linked placement.
   */
  public static function isLinked(string $plugin_id): bool {
    return str_starts_with($plugin_id, self::PLUGIN_ID . ':');
  }

  /**
   * The machine name a linked plugin id names, or NULL for any other block.
   *
   * The one reader of the name inside the plugin id: the serializer (the
   * linked node's `blueprint` key), the ownership policy (the content
   * refusal's portal) and the op door (the fit gate) all ask here.
   */
  public static function blueprintOf(string $plugin_id): ?string {
    if (!self::isLinked($plugin_id)) {
      return NULL;
    }
    $name = substr($plugin_id, strlen(self::PLUGIN_ID) + 1);
    return $name === '' ? NULL : $name;
  }

  /**
   * The plugin id linking a blueprint by machine name.
   */
  public static function pluginId(string $machine_name): string {
    return self::PLUGIN_ID . ':' . $machine_name;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      // '0', not FALSE: a linked placement may live in a defaults display,
      // whose block settings schema types label_display as a string.
      'label_display' => '0',
      'context_mapping' => self::CONTEXT_MAPPING,
    ];
  }

  /**
   * The linked blueprint's machine name: the derivative.
   */
  public function machineName(): string {
    return (string) $this->getDerivativeId();
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $contexts = $this->getContexts();
    if (!$contexts['view_mode']->hasContextValue()) {
      throw new ContextException(sprintf('The linked blueprint "%s" was rendered without its view_mode context. Render linked placements with the Layout Builder contexts (layout_builder.entity, view_mode) available.', $this->machineName()));
    }

    $blueprint = $this->innerRender->blueprintHost($this->machineName());
    if ($blueprint === NULL) {
      // The name resolves to no published blueprint entity (unpublished,
      // deleted, or shipped as a file): nothing shows, until one is
      // published under the name. On the canvas the block stand-in says
      // the block built nothing.
      return ['#cache' => ['tags' => ['blueprint_list']]];
    }
    $document = $this->innerRender->render($blueprint, $blueprint->ownSections(), $contexts, $this->inPreview);
    if ($document === []) {
      return ['#cache' => ['tags' => $blueprint->entity()->getCacheTags()]];
    }
    // The blueprint document's root is a wrapper of its own inside the
    // block: the carrier of what the primitive's consumers put on the
    // inner render's root (daedalus_stylish's root key for the blueprint's
    // document-level styles). It is a CHILD of the block build, never the
    // build itself, because core hoists a block build's #attributes onto
    // the block template root, which is the placement's own element (its
    // selectable key, the portal stamp) and must stay so.
    $attributes = $document['#attributes'] ?? [];
    unset($document['#attributes']);
    $attributes['class'][] = self::WRAPPER_CLASS;
    return [
      'document' => [
        '#type' => 'container',
        '#attributes' => $attributes,
        'sections' => $document,
      ],
    ];
  }

}
