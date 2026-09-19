<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\PropertyGroup;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_stylish\Attribute\PropertyGroup;
use Drupal\daedalus_stylish\Plugin\Derivative\WidePropertyGroupDeriver;
use Drupal\daedalus_stylish\PropertyGroupBase;

/**
 * The generated wide-tier property groups, one per CSS spec area.
 *
 * Grows the spine's recognized-property vocabulary to every standard CSS
 * property: the deriver reads data/wide-properties.json (generated from
 * mdn-data) into one derivative group per spec area, weighted below every
 * curated group so curated ownership always wins where they overlap —
 * curated constraints, controls, and AI guidance stay authoritative.
 *
 * Typed definitions (~70% of the file) validate through the ordinary
 * PropertyGroupBase machinery; 'any' definitions are the honest tail
 * (shorthands, url- and function-valued grammars): the server guarantees
 * name membership plus value hygiene only, the client lints the real
 * grammar via csstree's embedded lexer, and a safe-but-ungrammatical value
 * is inert in the browser, never unsafe.
 */
#[PropertyGroup(
  id: 'wide',
  label: new TranslatableMarkup('Wide'),
  weight: 100,
  deriver: WidePropertyGroupDeriver::class,
)]
final class Wide extends PropertyGroupBase {

  /**
   * {@inheritdoc}
   */
  public function getProperties(): array {
    return $this->pluginDefinition['properties'];
  }

}
