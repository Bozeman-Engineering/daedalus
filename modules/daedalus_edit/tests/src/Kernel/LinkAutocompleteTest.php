<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the composed link widget's autocomplete plumbing.
 *
 * The endpoint itself is core's system.entity_autocomplete; under test
 * here is daedalus_edit's server half of the contract: the per-field
 * `daedalus_edit.link_target_types` third-party setting resolves to one
 * minted endpoint per target type (defaulting to nodes), every minted
 * key registers core's selection settings in the entity_autocomplete
 * keyValue store (the endpoint 404s an unregistered key), and the
 * drupalSettings payload carries the {type, label, url} descriptor shape
 * the link widget fans out over, keyed by field config id.
 *
 * @group daedalus_edit
 */
#[RunTestsInSeparateProcesses]
class LinkAutocompleteTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The daedalus_ui hard dependencies (daedalus_blueprint +
   * daedalus_blueprint_api) are invisible to kernel environments and must
   * be listed explicitly or the container fails to compile. node supplies
   * the default target type; daedalus_edit_text_block supplies a real
   * composed block type carrying a link field.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'link',
    'options',
    'editor',
    'node',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'file',
    'daedalus_ui',
    'field_sample_value',
    'daedalus_edit',
    'daedalus_layout',
    'daedalus_edit_text_block',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('block_content');
    $this->installConfig(['daedalus_edit_text_block']);
  }

  /**
   * Builds the payload through the service, as the form panel attaches it.
   */
  protected function settings(): array {
    return \Drupal::service('daedalus_edit.link_autocomplete')->settings();
  }

  /**
   * Extracts the settings key from a minted endpoint url and asserts shape.
   *
   * @param string $url
   *   The endpoint url.
   * @param string $target_type
   *   The target type the url must route to.
   *
   * @return string
   *   The selection settings key.
   */
  protected function assertEndpointUrl(string $url, string $target_type): string {
    $this->assertMatchesRegularExpression(
      '{/entity_reference_autocomplete/' . $target_type . '/default/([A-Za-z0-9_-]+)$}',
      $url,
      'The endpoint url targets core\'s autocomplete route with the default selection handler.'
    );
    preg_match('{/([A-Za-z0-9_-]+)$}', $url, $matches);
    return $matches[1];
  }

  /**
   * An unconfigured link field mints the node default, key registered.
   */
  public function testDefaultPayloadAndMintedKey(): void {
    $settings = $this->settings();
    // The one composed link field enabled in this environment.
    $this->assertSame(['block_content.text.field_text_url'], array_keys($settings));
    $endpoints = $settings['block_content.text.field_text_url'];
    $this->assertCount(1, $endpoints);
    $this->assertSame(['type', 'label', 'url'], array_keys($endpoints[0]));
    $this->assertSame('node', $endpoints[0]['type']);
    $this->assertSame('Content', $endpoints[0]['label']);
    $key = $this->assertEndpointUrl($endpoints[0]['url'], 'node');
    // The key resolves in core's store to the exact selection settings the
    // matcher will hand the default:node handler.
    $store = \Drupal::keyValue('entity_autocomplete');
    $this->assertTrue($store->has($key), 'The minted key is registered in the entity_autocomplete keyValue store.');
    $this->assertSame(['match_operator' => 'CONTAINS', 'match_limit' => 10], $store->get($key));
  }

  /**
   * Configured target types mint one resolving endpoint each, in order.
   */
  public function testConfiguredTargetTypes(): void {
    $field = FieldConfig::load('block_content.text.field_text_url');
    $field->setThirdPartySetting('daedalus_edit', 'link_target_types', ['node', 'user']);
    $field->save();

    $endpoints = $this->settings()['block_content.text.field_text_url'];
    $this->assertSame(['node', 'user'], array_column($endpoints, 'type'));
    $this->assertSame(['Content', 'User'], array_column($endpoints, 'label'));
    $store = \Drupal::keyValue('entity_autocomplete');
    $keys = [];
    foreach ($endpoints as $endpoint) {
      $key = $this->assertEndpointUrl($endpoint['url'], $endpoint['type']);
      $this->assertTrue($store->has($key), "The {$endpoint['type']} key is registered in the entity_autocomplete keyValue store.");
      $keys[] = $key;
    }
    // The target type is hashed into the key, so the endpoints differ.
    $this->assertCount(2, array_unique($keys));
  }

  /**
   * A field configured to suggest nothing is omitted from the payload.
   */
  public function testNoTargetTypesOmitsField(): void {
    $field = FieldConfig::load('block_content.text.field_text_url');
    $field->setThirdPartySetting('daedalus_edit', 'link_target_types', []);
    $field->save();

    $this->assertArrayNotHasKey('block_content.text.field_text_url', $this->settings());
  }

}
