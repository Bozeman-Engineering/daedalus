<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\OrderAfter;
use Drupal\daedalus_blueprint\Dialect\DialectSchemaProjector;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;

/**
 * Advertises daedalus_stylish's minting gate on the JSON door's materials.
 *
 * The roster and its schema fragment are core's (daedalus_blueprint renders
 * `materials` from the providers' records); what stays daedalus_stylish's is
 * the governance posture of ITS mint lanes (tokens, animations and
 * fonts, one setting). When the site closed AI minting
 * (daedalus_stylish.settings ai_minting), the provider's mintPosture() says so,
 * and this hook lands that one sentence on the fragment's guidance for
 * the JSON door (eb_edit, the API, the CLI), the door with no brief to
 * lean on. The dialect door hears the same sentence on the design system
 * brief's MINT line (DialectCatalogPrompt, through the roster builder's
 * mintPostures()), the always-on layer, since a mint can be asked on any
 * turn and the dialect schema's guidance is the composition manual's,
 * not this fragment's. An honest advertisement beats discovery by
 * refusal. Material visibility itself is never gated.
 *
 * Runs after core's hook so the sentence lands on the fragment core built;
 * the config tag rides the alter context either way, because the posture
 * flipping must rebuild the cached schema.
 */
final class MaterialsGateHooks {

  public function __construct(
    protected DaedalusStylishMaterialsProvider $materialsProvider,
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
    $posture = $this->materialsProvider->mintPosture();
    if ($posture !== NULL) {
      $schema[DialectSchemaProjector::MATERIALS]['guidance'] .= ' ' . $posture;
    }
  }

}
