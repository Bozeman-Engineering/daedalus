<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\LayoutBuilder;

use Drupal\Core\Config\Entity\ThirdPartySettingsInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\daedalus_blueprint\Host\ContentHost;

/**
 * A config host's style map: a third-party setting on its config entity.
 *
 * The display host and the frame host keep their document's styles in the
 * entity's daedalus_stylish third-party settings (config, with schema the
 * styling module declares), where a content host keeps them in a field.
 * The store exists whenever the style map field type exists on the site
 * (the styling module is installed): nothing has to be attached to config.
 * The one place the spine names the third-party setting: readers and
 * writers go through styleMap() and setStyleMap().
 *
 * Requires the using host to answer entity() with a config entity carrying
 * third-party settings, and a `$fieldTypeManager` property holding
 * \Drupal\Core\Field\FieldTypePluginManagerInterface.
 */
trait ConfigStyleMapTrait {

  /**
   * The third-party provider the style map is stored under.
   *
   * The styling module's name, spelled here so the host stays loadable
   * without it (as ContentHost names the style map field type).
   */
  public const STYLES_PROVIDER = 'daedalus_stylish';

  /**
   * The third-party setting key holding the style map.
   */
  public const STYLES_KEY = 'styles';

  /**
   * The grammar definition the style map validates against, memoized.
   */
  protected ?FieldDefinitionInterface $styleMapDefinition = NULL;

  /**
   * The config entity carrying the store.
   */
  protected function styleStore(): ThirdPartySettingsInterface {
    $entity = $this->entity();
    if (!$entity instanceof ThirdPartySettingsInterface) {
      throw new \LogicException(sprintf('%s %s carries no third-party settings to keep a style map in.', $this->entityTypeId(), $this->id() ?? 'new'));
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function hasStyleStore(): bool {
    return $this->fieldTypeManager->hasDefinition(ContentHost::STYLE_MAP_FIELD_TYPE);
  }

  /**
   * {@inheritdoc}
   *
   * The grammar is the style map field type's; the definition is minted
   * over the config entity (no field is attached to config) so the one
   * validation seam, the type's field handler, dispatches on it as it does
   * for a content host's field.
   */
  public function styleMapDefinition(): ?FieldDefinitionInterface {
    if (!$this->hasStyleStore()) {
      return NULL;
    }
    if ($this->styleMapDefinition === NULL) {
      $this->styleMapDefinition = BaseFieldDefinition::create(ContentHost::STYLE_MAP_FIELD_TYPE)
        ->setName(self::STYLES_KEY)
        ->setLabel('Styles')
        ->setTargetEntityTypeId($this->entityTypeId());
    }
    return $this->styleMapDefinition;
  }

  /**
   * {@inheritdoc}
   */
  public function styleMap(): array {
    if (!$this->hasStyleStore()) {
      return [];
    }
    $rows = $this->styleStore()->getThirdPartySetting(self::STYLES_PROVIDER, self::STYLES_KEY, []);
    if (!is_array($rows)) {
      return [];
    }
    $map = [];
    foreach ($rows as $row) {
      if (is_array($row) && isset($row['key']) && is_string($row['key']) && array_key_exists('entry', $row)) {
        $map[$row['key']] = $row['entry'];
      }
    }
    return $map;
  }

  /**
   * {@inheritdoc}
   */
  public function setStyleMap(array $map): void {
    if (!$this->hasStyleStore()) {
      throw new \LogicException(sprintf('%s %s has no style store.', $this->entityTypeId(), $this->id() ?? 'new'));
    }
    $store = $this->styleStore();
    // An empty map is no setting at all: "no styles" and "no value" are
    // one state, and the export carries nothing for it.
    if ($map === []) {
      $store->unsetThirdPartySetting(self::STYLES_PROVIDER, self::STYLES_KEY);
      return;
    }
    // Stored as a list of {key, entry} rows, map order kept: config refuses
    // a dot anywhere in a key (ConfigBase::validateKeys), and a part or
    // rule key carries one ("block:{uuid}::.quote",
    // "entity:{uuid}::.daedalus-layout-layout"). The entry's own keys
    // (conditions, properties) never do.
    $rows = [];
    foreach ($map as $key => $entry) {
      $rows[] = ['key' => (string) $key, 'entry' => $entry];
    }
    $store->setThirdPartySetting(self::STYLES_PROVIDER, self::STYLES_KEY, $rows);
  }

}
