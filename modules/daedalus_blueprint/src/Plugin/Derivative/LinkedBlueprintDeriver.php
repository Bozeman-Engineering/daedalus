<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derives one linked placement block per published block blueprint entity.
 *
 * Only an entity has a live document a render can show (a shipped file is
 * material for copies), and only a block-scope blueprint places into a
 * document (a page blueprint is a whole page's start), so the derivatives
 * are exactly the published block-scope blueprint entities, keyed by
 * machine name (`daedalus_blueprint_linked:{machine_name}`), each with
 * the blueprint's label as the block's label. The entity type is the
 * library module's, known here by its id (BlueprintHost::ENTITY_TYPE_ID)
 * so the spine stays loadable without it: without the library nothing
 * derives. Block definitions are cached without entity dependencies, so
 * a blueprint's save or delete rebuilds them (LinkedBlueprintHooks).
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
      'status' => 1,
      BlueprintHost::SCOPE_FIELD => MaterialRecord::SCOPE_BLOCK,
    ]);
    foreach ($blueprints as $blueprint) {
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
