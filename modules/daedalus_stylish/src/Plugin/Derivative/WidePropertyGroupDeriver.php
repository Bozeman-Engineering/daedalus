<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derives the generated wide-tier property groups from the committed data.
 *
 * This is the ONE reader of data/wide-properties.json (generated from
 * mdn-data by js/src/tools/generate-wide-properties.mjs at developer
 * gesture, committed for a reviewable diff). One group derives per CSS
 * spec area ("Box Alignment", "Transforms", ...), so the wide tier rides
 * the existing plugin spine: the validator, strict mode, guide
 * declarations, and AI schema all see ordinary property groups.
 *
 * Property definition labels stay the file's plain strings rather than
 * TranslatableMarkup: they are generated data, the definitions are
 * server-side vocabulary (never served to the client, see
 * PropertyGroupPluginManager::getCuratedGroups()), and every consumer of a
 * definition label casts to string.
 *
 * A missing or malformed data file fails loudly here — the wide tier is
 * part of the recognized-property vocabulary, and silently shrinking that
 * vocabulary would flip stored wide-tier styles to validation errors.
 */
final class WidePropertyGroupDeriver extends DeriverBase implements ContainerDeriverInterface {

  public function __construct(protected ModuleExtensionList $moduleList) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id): static {
    return new static($container->get('extension.list.module'));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition): array {
    $path = $this->moduleList->getPath('daedalus_stylish') . '/data/wide-properties.json';
    $data = json_decode(file_get_contents($path), TRUE, flags: JSON_THROW_ON_ERROR);
    $areas = [];
    foreach ($data['properties'] as $name => $definition) {
      $areas[$definition['area']][$name] = $definition;
    }
    ksort($areas);
    foreach ($areas as $area => $properties) {
      $id = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($area)), '_');
      $this->derivatives[$id] = [
        'label' => new TranslatableMarkup('@area', ['@area' => $area]),
        'properties' => $properties,
        'generated' => TRUE,
      ] + $base_plugin_definition;
    }
    return $this->derivatives;
  }

}
