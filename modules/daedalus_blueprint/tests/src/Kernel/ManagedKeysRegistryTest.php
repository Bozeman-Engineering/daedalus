<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Kernel;

use Drupal\daedalus_blueprint\ManagedKeysRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;

/**
 * Tests the ManagedKeysRegistry service.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ManagedKeysRegistry::class)]
#[Group('daedalus_blueprint')]
class ManagedKeysRegistryTest extends BlueprintKernelTestBase {

  /**
   * The registry under test.
   *
   * @var \Drupal\daedalus_blueprint\ManagedKeysRegistry
   */
  protected ManagedKeysRegistry $registry;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->registry = $this->container->get('daedalus_blueprint.managed_keys_registry');
  }

  /**
   * Tests that empty registry returns empty arrays.
   *
   * With no modules implementing the alter hook, there should be no
   * managed keys.
   */
  public function testEmptyRegistryReturnsEmptyArray(): void {
    $keys = $this->registry->getManagedKeys('entity_test', 'entity_test');
    $this->assertIsArray($keys);
    $this->assertEmpty($keys);
  }

  /**
   * Tests getManagedKeysForScope returns empty for unknown scope.
   */
  public function testGetManagedKeysForScopeEmpty(): void {
    $keys = $this->registry->getManagedKeysForScope(
      'section_layout_settings',
      'entity_test',
      'entity_test',
    );
    $this->assertIsArray($keys);
    $this->assertEmpty($keys);
  }

  /**
   * Tests isManaged returns FALSE when no keys are registered.
   */
  public function testIsManagedReturnsFalse(): void {
    $this->assertFalse(
      $this->registry->isManaged(
        'section_layout_settings',
        'some_key',
        'entity_test',
        'entity_test',
      ),
    );
  }

  /**
   * Tests that the alter hook is invoked and keys are collected.
   *
   * Uses Drupal's state system to simulate a hook implementation.
   */
  public function testHookCollectsManagedKeys(): void {
    // Install our test module that implements the alter hook.
    $this->enableModules(['daedalus_blueprint_test']);

    // Clear the cache and get a fresh registry.
    $this->container->get('cache.default')->deleteAll();
    $registry = $this->container->get('daedalus_blueprint.managed_keys_registry');

    // Set up test managed keys via state (our test module reads these).
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'section_layout_settings' => [
        'layout_onecol' => [
          'width' => [
            'label' => 'Layout width',
            'description' => 'Container width constraint.',
            'schema' => ['type' => 'string'],
          ],
        ],
      ],
      'inline_block_configuration' => [
        'heading' => [
          'title_size' => [
            'label' => 'Title size',
            'description' => 'CSS class for heading size.',
            'schema' => ['type' => 'string'],
          ],
        ],
      ],
    ]);

    $all_keys = $registry->getManagedKeys('entity_test', 'entity_test');

    $this->assertArrayHasKey('section_layout_settings', $all_keys);
    $this->assertArrayHasKey('layout_onecol', $all_keys['section_layout_settings']);
    $this->assertSame('Layout width', $all_keys['section_layout_settings']['layout_onecol']['width']['label']);

    $this->assertArrayHasKey('inline_block_configuration', $all_keys);
    $this->assertArrayHasKey('heading', $all_keys['inline_block_configuration']);

    // Test scope-specific lookup.
    $layout_keys = $registry->getManagedKeysForScope(
      'section_layout_settings',
      'entity_test',
      'entity_test',
    );
    $this->assertArrayHasKey('layout_onecol', $layout_keys);

    // Test isManaged.
    $this->assertTrue(
      $registry->isManaged(
        'inline_block_configuration',
        'heading',
        'entity_test',
        'entity_test',
      ),
    );
    $this->assertFalse(
      $registry->isManaged(
        'inline_block_configuration',
        'unknown_block',
        'entity_test',
        'entity_test',
      ),
    );
  }

  /**
   * Tests that results are cached.
   */
  public function testResultsAreCached(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    $this->container->get('cache.default')->deleteAll();
    $registry = $this->container->get('daedalus_blueprint.managed_keys_registry');

    // Set initial managed keys.
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'heading' => [
          'title_size' => ['label' => 'Original'],
        ],
      ],
    ]);

    // First call populates cache.
    $keys = $registry->getManagedKeys('entity_test', 'entity_test');
    $this->assertSame('Original', $keys['inline_block_configuration']['heading']['title_size']['label']);

    // Change state — cached result should still be returned.
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'heading' => [
          'title_size' => ['label' => 'Changed'],
        ],
      ],
    ]);

    $keys = $registry->getManagedKeys('entity_test', 'entity_test');
    $this->assertSame('Original', $keys['inline_block_configuration']['heading']['title_size']['label']);

    // Invalidate cache tag — should pick up new value.
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['daedalus_blueprint_managed_keys']);

    $keys = $registry->getManagedKeys('entity_test', 'entity_test');
    $this->assertSame('Changed', $keys['inline_block_configuration']['heading']['title_size']['label']);
  }

  /**
   * Tests that invalid scopes from hook are filtered out.
   */
  public function testInvalidScopesFilteredOut(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    $this->container->get('cache.default')->deleteAll();
    $registry = $this->container->get('daedalus_blueprint.managed_keys_registry');

    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'heading' => [
          'valid_key' => ['label' => 'Valid'],
        ],
      ],
      'invalid_scope_name' => [
        'some_key' => ['label' => 'Should be filtered'],
      ],
      'component_additional' => [
        'removed_scope' => ['label' => 'Also filtered'],
      ],
    ]);

    $keys = $registry->getManagedKeys('entity_test', 'entity_test');

    $this->assertArrayHasKey('inline_block_configuration', $keys);
    $this->assertArrayNotHasKey('invalid_scope_name', $keys);
    $this->assertArrayNotHasKey('component_additional', $keys);
  }

  /**
   * Tests different entity type/bundle pairs have separate caches.
   */
  public function testDifferentBundlesHaveSeparateCaches(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    $this->container->get('cache.default')->deleteAll();
    $registry = $this->container->get('daedalus_blueprint.managed_keys_registry');

    // The test module can vary keys by entity_type/bundle via state.
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'heading' => [
          'universal_key' => ['label' => 'Universal'],
        ],
      ],
    ]);

    $keys_a = $registry->getManagedKeys('entity_test', 'entity_test');
    $this->assertArrayHasKey('inline_block_configuration', $keys_a);

    // Different entity type should be a separate cache entry.
    // Change state to verify separate cache.
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'section_layout_settings' => [
        'layout_onecol' => [
          'other_key' => ['label' => 'Other'],
        ],
      ],
    ]);

    // entity_test should still return cached result.
    $keys_a2 = $registry->getManagedKeys('entity_test', 'entity_test');
    $this->assertArrayHasKey('inline_block_configuration', $keys_a2);

    // entity_test_rev should compute fresh.
    $keys_b = $registry->getManagedKeys('entity_test_rev', 'entity_test_rev');
    $this->assertArrayHasKey('section_layout_settings', $keys_b);

    // entity_test_rev should NOT have the hook key from the first state
    // (proving separate cache entries).
    $heading_keys = $keys_b['inline_block_configuration']['heading'] ?? [];
    $this->assertArrayNotHasKey('universal_key', $heading_keys);
  }

  /**
   * Tests inline_block_configuration scope is accepted.
   */
  public function testInlineBlockConfigurationScopeAccepted(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    $this->container->get('cache.default')->deleteAll();
    $registry = $this->container->get('daedalus_blueprint.managed_keys_registry');

    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'heading' => [
          'title_size' => [
            'label' => 'Title size',
            'schema' => ['type' => 'string'],
          ],
        ],
      ],
    ]);

    $keys = $registry->getManagedKeys('entity_test', 'entity_test');

    $this->assertArrayHasKey('inline_block_configuration', $keys);
    $this->assertArrayHasKey('heading', $keys['inline_block_configuration']);
    $this->assertArrayHasKey('title_size', $keys['inline_block_configuration']['heading']);
    $this->assertSame('Title size', $keys['inline_block_configuration']['heading']['title_size']['label']);
  }

  /**
   * Tests YAML discovery provides managed keys without hooks.
   */
  public function testYamlDiscoveryProvidesManagedKeys(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    $this->container->get('cache.default')->deleteAll();
    $registry = $this->container->get('daedalus_blueprint.managed_keys_registry');

    // No state set — hook contributes nothing, only YAML.
    $keys = $registry->getManagedKeys('entity_test', 'entity_test');

    $this->assertArrayHasKey('inline_block_configuration', $keys);
    $this->assertArrayHasKey('yaml_test_block', $keys['inline_block_configuration']);
    $this->assertArrayHasKey('yaml_setting', $keys['inline_block_configuration']['yaml_test_block']);
    $this->assertSame('YAML Setting', $keys['inline_block_configuration']['yaml_test_block']['yaml_setting']['label']);
    $this->assertSame('A setting declared via YAML.', $keys['inline_block_configuration']['yaml_test_block']['yaml_setting']['description']);
  }

  /**
   * Tests hook keys coexist with YAML keys.
   */
  public function testHookLayersOnTopOfYaml(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    $this->container->get('cache.default')->deleteAll();
    $registry = $this->container->get('daedalus_blueprint.managed_keys_registry');

    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'section_layout_settings' => [
        'layout_onecol' => [
          'hook_key' => [
            'label' => 'Hook Key',
            'schema' => ['type' => 'string'],
          ],
        ],
      ],
    ]);

    $keys = $registry->getManagedKeys('entity_test', 'entity_test');

    // YAML keys should be present.
    $this->assertArrayHasKey('inline_block_configuration', $keys);
    $this->assertArrayHasKey('yaml_test_block', $keys['inline_block_configuration']);

    // Hook keys should also be present.
    $this->assertArrayHasKey('section_layout_settings', $keys);
    $this->assertArrayHasKey('layout_onecol', $keys['section_layout_settings']);
    $this->assertArrayHasKey('hook_key', $keys['section_layout_settings']['layout_onecol']);
  }

  /**
   * Tests hook values override YAML values for the same key.
   */
  public function testHookOverridesYamlValues(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    $this->container->get('cache.default')->deleteAll();
    $registry = $this->container->get('daedalus_blueprint.managed_keys_registry');

    // Hook sets the same key as YAML with a different label.
    \Drupal::state()->set('daedalus_blueprint_test.managed_keys', [
      'inline_block_configuration' => [
        'yaml_test_block' => [
          'yaml_setting' => [
            'label' => 'Hook Override',
            'description' => 'Overridden by hook.',
            'schema' => ['type' => 'integer'],
          ],
        ],
      ],
    ]);

    $keys = $registry->getManagedKeys('entity_test', 'entity_test');

    // Hook value should win.
    $this->assertSame('Hook Override', $keys['inline_block_configuration']['yaml_test_block']['yaml_setting']['label']);
    $this->assertSame('Overridden by hook.', $keys['inline_block_configuration']['yaml_test_block']['yaml_setting']['description']);
  }

  /**
   * Tests scoped YAML discovery provides keys for matching bundle.
   */
  public function testScopedYamlDiscoveryProvidesManagedKeys(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    $this->container->get('cache.default')->deleteAll();
    $registry = $this->container->get('daedalus_blueprint.managed_keys_registry');

    // No state set — only YAML (universal + scoped) contribute.
    $keys = $registry->getManagedKeys('entity_test', 'entity_test');

    // Universal YAML keys should be present.
    $this->assertArrayHasKey('yaml_test_block', $keys['inline_block_configuration']);

    // Scoped YAML keys should also be present for entity_test.entity_test.
    $this->assertArrayHasKey('yaml_scoped_block', $keys['inline_block_configuration']);
    $this->assertSame('Scoped Setting', $keys['inline_block_configuration']['yaml_scoped_block']['scoped_setting']['label']);
  }

  /**
   * Tests scoped YAML keys do not appear for non-matching bundles.
   */
  public function testScopedYamlNotAppliedToOtherBundles(): void {
    $this->enableModules(['daedalus_blueprint_test']);

    $this->container->get('cache.default')->deleteAll();
    $registry = $this->container->get('daedalus_blueprint.managed_keys_registry');

    // entity_test_rev should NOT get entity_test.entity_test scoped keys.
    $keys = $registry->getManagedKeys('entity_test_rev', 'entity_test_rev');

    // Universal YAML keys should still be present.
    $this->assertArrayHasKey('inline_block_configuration', $keys);
    $this->assertArrayHasKey('yaml_test_block', $keys['inline_block_configuration']);

    // Scoped keys should NOT be present.
    $this->assertArrayNotHasKey('yaml_scoped_block', $keys['inline_block_configuration']);
  }

}
