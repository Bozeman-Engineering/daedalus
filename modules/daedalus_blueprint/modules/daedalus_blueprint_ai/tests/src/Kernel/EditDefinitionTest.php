<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint_ai\Kernel;

use Drupal\daedalus_blueprint_ai\Plugin\AiFunctionCall\Edit;
use Drupal\Tests\daedalus\Kernel\BlueprintKernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Pins the guidance tiering contract on the eb_edit tool's definition.
 *
 * Eb_edit, the one mutation tool, is the always-on carrier of the op
 * grammar, so its definition has a size discipline: the tool description
 * carries only the universal contract (atomicity, sequencing over minted
 * uuids, the literal text rule), the operations parameter states the
 * shared placement grammar and the identity (uuid-minting) contract each
 * exactly ONCE with lean per-op lines referencing them, op names are
 * rolled call exactly once, and module-registered ops arrive as
 * "op (router line)" appended once by
 * daedalus_blueprint_ai_ai_function_call_info_alter(). The examples teach
 * both scales: a trivial one-op batch and the canonical compose-and-style
 * batch. These assertions are the guardrail against the surface regrowing
 * duplicated doctrine.
 *
 * The definition has two site shapes: without a container layout in
 * the dialect catalog the full layout grammar rides (the default fixture,
 * no layout_discovery); with one, the page language speaks for pages and
 * the alter recomposes the description without the layout grammar or the
 * page-only handler roll-call. Both are pinned here.
 *
 * @group daedalus_blueprint
 */
#[RunTestsInSeparateProcesses]
class EditDefinitionTest extends BlueprintKernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Merged with the base class modules. 'key' is an ai dependency.
   * daedalus_blueprint_test registers the test_set_name operation handler,
   * so the alter's module-registered roll-call has an op to emit.
   */
  protected static $modules = [
    'key',
    'ai',
    'daedalus_blueprint_ai',
    'daedalus_blueprint_test',
  ];

  /**
   * Tests the tiered definition: contract, deduped grammar, examples.
   */
  public function testDefinitionTiering(): void {
    $definitions = $this->container->get('plugin.manager.ai.function_calls')->getDefinitions();
    $this->assertArrayHasKey('daedalus_blueprint:edit', $definitions);
    $definition = $definitions['daedalus_blueprint:edit'];
    $description = (string) $definition['description'];
    $operations = (string) $definition['context_definitions']['operations']->getDescription();
    $surface = $description . "\n" . $operations;

    // The tool description is the universal contract, not an op catalog:
    // atomicity, sequencing over minted uuids, and the literal text rule.
    $this->assertStringContainsString('atomically', $description);
    $this->assertStringContainsString('rolled back', $description);
    $this->assertStringContainsString('never HTML-escaped', $description);
    $this->assertStringNotContainsString('update_element', $description, 'The description does not enumerate ops; the roll-call lives once in the operations parameter.');
    $this->assertStringNotContainsString('add_section', $description, 'The description does not enumerate ops; the roll-call lives once in the operations parameter.');

    // The placement grammar is stated exactly once, then referenced.
    $this->assertSame(1, substr_count($surface, 'placement: before|after|inside'), 'The placement grammar is taught once, not per op.');
    $this->assertSame(1, substr_count($surface, 'root flow holds only sections'), 'The capability rule is taught once, not per op.');

    // Identity (uuid minting) is stated exactly once, then referenced.
    $this->assertSame(1, substr_count($surface, 'UUID v4 you mint'), 'The minting contract is taught once, not per creating op.');

    // The built-in roll-call appears once: one Content ops list, one Config
    // ops list, and ops that appear nowhere else are named exactly once.
    $this->assertSame(1, substr_count($surface, 'Content ops:'));
    $this->assertSame(1, substr_count($surface, 'Config ops:'));
    foreach ([
      'remove_element',
      'duplicate_element',
      'move_element',
      'reorder_field',
      'update_properties',
    ] as $op) {
      // A lookbehind keeps ops from matching inside longer op names
      // (move_element inside remove_element).
      $this->assertSame(1, preg_match_all('/(?<![a-z_])' . $op . '/', $surface), "The op $op is rolled call exactly once.");
    }

    // The trivial one-op example rides the parameter. There is no
    // compose-and-style example: composing a page is the page language's
    // job (page_edit), and teaching the JSON compose gesture here would
    // re-grow the surface the dialect shrank.
    $this->assertStringContainsString('[{"op": "update_element", "element": "<uuid>", "properties": {"label": "Hero"}}]', $operations, 'A trivial one-op example is present.');

    // The alter emits module-registered ops once, as "op (router line)",
    // on the operations parameter only. The DOM ops are always in the
    // registry ahead of it, so the roll-call is asserted by membership
    // rather than by leading position.
    $this->assertSame(1, substr_count($surface, 'Module-registered ops:'), 'The module-registered roll-call is emitted once.');
    $this->assertSame(1, substr_count($surface, 'test_set_name'), 'A registered op is named exactly once across the definition.');
    $this->assertStringContainsString('Module-registered ops: ', $operations, 'The roll-call rides the operations parameter.');
    $this->assertStringContainsString('test_set_name (', $operations, 'The registered op arrives in "op (router line)" form.');
    $this->assertStringNotContainsString('test_set_name', $description, 'The description carries no second roll-call.');
  }

  /**
   * Tests the page-language shape: the layout grammar leaves the surface.
   *
   * With a container layout discoverable (daedalus_blueprint_test's
   * eb_test_container, once layout_discovery provides the layout plugin
   * manager), Edit::execute() refuses pages, so the operations
   * description composes from the root-field grammar and the config ops
   * only, and the module-registered roll-call drops the page-only ops
   * (here the always-registered DOM vocabulary) while a generic
   * registered op (test_set_name) survives.
   */
  public function testPageLanguageSiteDefinition(): void {
    $this->enableModules(['layout_discovery']);
    $manager = $this->container->get('plugin.manager.ai.function_calls');
    $manager->clearCachedDefinitions();
    $definitions = $manager->getDefinitions();
    $definition = $definitions['daedalus_blueprint:edit'];
    $operations = (string) $definition['context_definitions']['operations']->getDescription();

    // The composition is byte-exact from the shared constants: intro,
    // root-field grammar, config ops, the root-field example.
    $this->assertStringStartsWith(
      Edit::OPERATIONS_INTRO . Edit::OPERATIONS_ROOT_GRAMMAR . Edit::OPERATIONS_CONFIG_OPS . Edit::OPERATIONS_ROOT_EXAMPLE,
      $operations,
      'The page-language shape composes from the shared constants.',
    );

    // The layout grammar is gone whole: placement, minting, and every
    // layout op the page language owns.
    $this->assertStringNotContainsString('placement: before|after|inside', $operations);
    $this->assertStringNotContainsString('UUID v4 you mint', $operations);
    foreach ([
      'add_element',
      'add_section',
      'remove_element',
      'duplicate_element',
      'move_element',
      'reorder_field',
    ] as $op) {
      $this->assertStringNotContainsString($op, $operations, "The layout op $op left the surface.");
    }

    // The kept grammar: root-field update_element and the config ops.
    $this->assertSame(2, preg_match_all('/(?<![a-z_])update_element/', $operations), 'The root-field update_element rides the grammar line and the example, nowhere else.');
    $this->assertStringContainsString('Config ops: update_properties, add_plugin, update_plugin, remove_plugin, reorder_plugins.', $operations);

    // The roll-call drops the page-only ops (the DOM vocabulary is
    // registered in this fixture) and keeps the generic registered op.
    $this->assertStringContainsString('Module-registered ops: ', $operations);
    $this->assertStringContainsString('test_set_name (', $operations, 'A generic (non-page) registered op keeps its router line.');
    $this->assertStringNotContainsString('a page-language op', $operations, 'The DOM ops\' router lines left the roll-call.');
    foreach (['insert (', 'replace (', 'style ('] as $needle) {
      $this->assertStringNotContainsString($needle, $operations, "The page-only router line \"$needle\" left the roll-call.");
    }
  }

}
