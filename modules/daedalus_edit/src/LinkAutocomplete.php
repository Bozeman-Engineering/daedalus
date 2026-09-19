<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;

/**
 * Entity autocomplete plumbing for composed-block link fields.
 *
 * The composed link widget suggests entities while the user types a
 * title, reusing core's system.entity_autocomplete endpoint wholesale: no
 * bespoke route, multi-type is a client-side fan-out of one request per
 * configured target type. The endpoint is `_access: TRUE` but only
 * pre-registered hmac'd selection-settings keys resolve, and the matcher
 * access-filters results through the selection handler, so minting keys
 * here (outside any form) is exactly as safe as core's own form render.
 *
 * Which entity types a field suggests is per-field configuration: the
 * `daedalus_edit.link_target_types` third-party setting on the link field's
 * config (FieldConfigFormAlter), defaulting to nodes.
 *
 * The payload rides the Edit tool's drupalSettings as
 * `daedalusEdit.linkAutocomplete`, keyed by field config id
 * (`block_content.BUNDLE.FIELD_NAME`), each entry a list of endpoint
 * descriptors: {type, label, url}. The label is the entity TYPE label,
 * the dropdown's group heading for that fan-out request's results.
 */
class LinkAutocomplete {

  /**
   * The default target types for a link field that has never been configured.
   */
  public const DEFAULT_TARGET_TYPES = ['node'];

  /**
   * The selection settings minted behind every endpoint key.
   *
   * Core's EntityAutocomplete element defaults; the endpoint feeds them to
   * the 'default' selection handler, which resolves to the target type's
   * default: derivative and access-filters the matches.
   */
  protected const SELECTION_SETTINGS = [
    'match_operator' => 'CONTAINS',
    'match_limit' => 10,
  ];

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected KeyValueFactoryInterface $keyValueFactory,
    protected PageBuilderDeclarations $declarations,
  ) {}

  /**
   * Builds the per-field autocomplete payload, minting endpoint keys.
   *
   * Covers every link field on every composed block type: the edit
   * session can add any of them mid-session, so the payload cannot be
   * scoped to the blocks already on the page.
   *
   * @return array
   *   Endpoint descriptor lists keyed by field config id
   *   (block_content.BUNDLE.FIELD_NAME). Each descriptor is
   *   ['type' => entity type id, 'label' => entity type label,
   *   'url' => the ready-to-query autocomplete url]. A field configured
   *   to suggest nothing is omitted entirely.
   */
  public function settings(): array {
    $settings = [];
    $field_config_storage = $this->entityTypeManager->getStorage('field_config');
    foreach ($this->declarations->getBlockTypes() as $bundle) {
      if (!($this->declarations->getBlockType($bundle)['composed'] ?? FALSE)) {
        continue;
      }
      $fields = $field_config_storage->loadByProperties([
        'entity_type' => 'block_content',
        'bundle' => $bundle,
        'field_type' => 'link',
      ]);
      /** @var \Drupal\Core\Field\FieldConfigInterface $field */
      foreach ($fields as $field) {
        $endpoints = [];
        $target_types = $field->getThirdPartySetting('daedalus_edit', 'link_target_types', self::DEFAULT_TARGET_TYPES);
        foreach ($target_types as $target_type) {
          $endpoints[] = [
            'type' => $target_type,
            'label' => (string) $this->entityTypeManager->getDefinition($target_type)->getLabel(),
            'url' => $this->mintEndpointUrl($target_type),
          ];
        }
        if ($endpoints) {
          $settings[$field->id()] = $endpoints;
        }
      }
    }
    return $settings;
  }

  /**
   * Mints the autocomplete endpoint url for one target type.
   *
   * Core's own key recipe, replicated from
   * EntityAutocomplete::processEntityAutocomplete(): hmac the serialized
   * selection settings with the site salt, register them in the
   * entity_autocomplete keyValue store under that key. Idempotent, and the
   * store is permanent, so a render-cached payload's keys keep resolving.
   *
   * @param string $target_type
   *   The target entity type id.
   *
   * @return string
   *   The endpoint url, ready for the client to append ?q=.
   */
  protected function mintEndpointUrl(string $target_type): string {
    $selection_handler = 'default';
    $data = serialize(self::SELECTION_SETTINGS) . $target_type . $selection_handler;
    $key = Crypt::hmacBase64($data, Settings::getHashSalt());
    $key_value_storage = $this->keyValueFactory->get('entity_autocomplete');
    if (!$key_value_storage->has($key)) {
      $key_value_storage->set($key, self::SELECTION_SETTINGS);
    }
    return Url::fromRoute('system.entity_autocomplete', [
      'target_type' => $target_type,
      'selection_handler' => $selection_handler,
      'selection_settings_key' => $key,
    ])->toString();
  }

}
