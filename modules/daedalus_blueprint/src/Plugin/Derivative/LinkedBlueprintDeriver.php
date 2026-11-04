<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derives one linked placement block per block-scope blueprint entity.
 *
 * Only an entity has a live document a render can show (a shipped file is
 * material for copies), and only a block-scope blueprint places into a
 * document (a page blueprint is a whole page's start), so the derivatives
 * are exactly the block-scope blueprint entities, published or not, keyed
 * by machine name (`daedalus_blueprint_linked:{machine_name}`), each with
 * the blueprint's label as the block's label. The derivative follows the
 * ENTITY, not its publication: a join must exist to declare what it read,
 * so an unpublish keeps the plugin and the block's build answers the
 * primitive's list-tag stub (InnerRender::renderBlueprint()), which the
 * republish invalidates; publication is the fit gate's question
 * (BlueprintOperations) and the primitive's (InnerRender::blueprintHost()
 * resolves published entities only), never the derivative's. The entity
 * type is the library module's, known here by its id
 * (BlueprintHost::ENTITY_TYPE_ID) so the spine stays loadable without it:
 * without the library nothing derives. Block definitions are cached
 * without entity dependencies, so a blueprint's save or delete rebuilds
 * them (LinkedBlueprintHooks).
 */
final class LinkedBlueprintDeriver extends DeriverBase implements ContainerDeriverInterface {

  public function __construct(protected EntityTypeManagerInterface $entityTypeManager) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    if (!$this->entityTypeManager->hasDefinition(BlueprintHost::ENTITY_TYPE_ID)) {
      return $this->derivatives;
    }
    $blueprints = $this->entityTypeManager->getStorage(BlueprintHost::ENTITY_TYPE_ID)->loadByProperties([
      BlueprintHost::SCOPE_FIELD => MaterialRecord::SCOPE_BLOCK,
    ]);
    foreach ($blueprints as $blueprint) {
      assert($blueprint instanceof FieldableEntityInterface);
      $machine_name = (string) $blueprint->get('machine_name')->value;
      if ($machine_name === '') {
        continue;
      }
      $this->derivatives[$machine_name] = [
        'admin_label' => new TranslatableMarkup('@label', ['@label' => (string) $blueprint->label()]),
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
