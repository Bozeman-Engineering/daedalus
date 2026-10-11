<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\daedalus_blueprint\Dialect\DialectSchemaProjector;

/**
 * Advertises daedalus_stylish's minting gate on the schema's materials.
 *
 * The roster and its schema fragment are core's (daedalus_blueprint renders
 * `materials` from the providers' records); what stays daedalus_stylish's is the
 * governance posture of ITS mint lanes (tokens, swatches and animations,
 * one setting). When the site closed AI minting (daedalus_stylish.settings ai_minting),
 * the fragment's guidance says so, so the brief's MINT line and the
 * schema never invite a save_material the provider would refuse: an
 * honest advertisement beats discovery by refusal.
 * Material visibility itself is never gated.
 *
 * Runs after core's hook so the sentence lands on the fragment core built;
 * the config tag rides the alter context either way, because the posture
 * flipping must rebuild the cached schema.
 */
final class MaterialsGateHooks {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_daedalus_blueprint_schema_alter().
   */
  #[Hook('daedalus_blueprint_schema_alter', order: new OrderAfter(modules: ['daedalus_blueprint']))]
  public function schemaAlter(array &$schema, array &$context): void {
    if (!isset($schema[DialectSchemaProjector::MATERIALS]['guidance'])) {
      return;
    }
    $context['cache_tags'][] = 'config:daedalus_stylish.settings';
    if ($this->configFactory->get('daedalus_stylish.settings')->get('ai_minting') === FALSE) {
      $schema[DialectSchemaProjector::MATERIALS]['guidance'] .= ' AI minting is DISABLED on this site (daedalus_stylish.settings ai_minting): never save_material a token, a swatch, an animation or a font (nor create a daedalus_swatch, daedalus_animation or daedalus_font entity); offer the published values, animations and fonts or a literal value instead.';
    }
  }

}
