<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_blueprint\Functional;

use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves daedalus_blueprint round-trips the real recipe content model.
 *
 * Entity_blueprint's reason to exist is not a DOM behavior: it serializes a
 * Layout Builder entity to JSON for an AI, lets the AI propose JSON-shaped
 * operations, and applies those operations back to the live entity. The
 * surgical operations layer (read schema, add an element to a section, persist)
 * is exactly the path the daedalus_blueprint_ai eb_read_schema / eb_edit
 * function-call tools execute. Every kernel test in this module fabricates that
 * content model by hand (enableLayoutBuilder() on entity_test, hand-rolled
 * block_content types and fields). What no kernel test can prove is that the
 * services still work against the content model a real site actually runs: the
 * daedalus page node type, its Layout Builder default display with
 * allow_custom = TRUE, daedalus_layout section UUIDs, and the recipe's real inline
 * block types. This test pays the recipe-apply cost once to get exactly that,
 * then drives the real services in-process. If the recipe's content model ever
 * drifts so daedalus_blueprint can no longer serialize or operate on a page, this
 * single test fails -- that is the "our use case does not break on the real
 * assembled stack" proof.
 *
 * Why a plain Functional BrowserTestBase rather than a WebDriver/JS test: there
 * is no honest end-to-end AI-driven UI observation available in CI. The recipe
 * ships no API keys, so there is no LLM. The dev-tools prompt_capture provider
 * returns a TOOL-FREE response, so it can never emit the function call that
 * would drive an eb_* tool; and its in-memory PromptCapture sink is readable
 * only in-process, never across the HTTP/WebDriver boundary. A WebDriver test
 * could therefore only assert that a chat bubble renders -- which proves the
 * chatbot mounts, not that a blueprint round-trips. So the faithful proof is to
 * pay the recipe-apply once for the genuine assembled content model and then
 * exercise daedalus_blueprint's own services directly, observing the serialized
 * array shape, the BlueprintResult, and a freshly reloaded persisted entity --
 * all of which ARE observable in-process from the BrowserTestBase container.
 *
 * daedalus_blueprint must stay standalone: it depends on the daedalus_ai recipe
 * only as a require-dev test fixture and never makes daedalus_ai a runtime
 * dependency. This test does not import or touch daedalus_ai.
 *
 * One fixture, one scenario, one method. The site install plus recipe apply in
 * setUp() is by far the most expensive part of a run (the framework installs
 * and tears down per method), so all behavior lives in the single test method,
 * mirroring the other assembled-stack tests in this suite.
 */
#[Group('daedalus_blueprint')]
#[RunTestsInSeparateProcesses]
class AssembledStackBlueprintRoundTripTest extends BrowserTestBase {

  use RecipeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   *
   * The recipe is applied on top of a standard site (the page-builder base it
   * carries expects standard's content model and field storage), so 'standard'
   * is the faithful fixture, the same starting point a real site gives the
   * recipe.
   */
  protected $profile = 'standard';

  /**
   * {@inheritdoc}
   *
   * Inherited from the Daedalus base recipe: a few exported config values are
   * stored with a type that does not match their schema (harmless in production
   * where the checker is off, but it aborts recipe apply under the checker that
   * tests enable). This is a behavior test, not a config schema audit, so the
   * checker is disabled here, matching the other Daedalus recipe tests.
   */
  // phpcs:ignore DrupalPractice.Objects.StrictSchemaDisabled.StrictConfigSchema
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // drupal/daedalus_recipe is a require-dev dependency, so Composer installs it
    // as a sibling of the Drupal root. Applying it gives the real daedalus
    // page content model (Layout Builder default display, allow_custom,
    // daedalus_layout section UUIDs, the recipe's inline block types). A successful
    // apply is itself the first assertion: the whole multi-package stack
    // installs cleanly on a fresh standard site.
    // The scenario is the TAKEOVER of a pre-existing page bundle: the suite's
    // page recipe finds core's Basic page and its display already there and
    // enables Layout Builder on that display, which is where core seeds the
    // layout_onecol section asserted below. Standard shipped that bundle
    // through core 11.3; core 11.4 removed Article and Page from the Standard
    // profile (change record 3590571), so the fixture applies core's own
    // page_content_type recipe explicitly. On 11.3 the apply is a no-op (the
    // config already exists), so the fixture is identical on both cores.
    $this->applyRecipe(\Drupal::root() . '/core/recipes/page_content_type');
    $recipe = dirname(\Drupal::root()) . '/recipes/daedalus_recipe/ai';
    $this->assertDirectoryExists(
      $recipe,
      'The daedalus_ai recipe must be installed (require-dev) to run this test.',
    );
    $this->applyRecipe($recipe);
  }

  /**
   * A real page round-trips to JSON and an AI-style op applies back.
   *
   * One fixture (the applied umbrella recipe) drives the exact serialize ->
   * read-schema -> operate -> persist path the daedalus_blueprint_ai tools run,
   * against the recipe's real page content model:
   *
   * - Dispatch: the BlueprintBackendResolver routes node operations to the
   *   ContentBlueprintBackend on the real content model. This is the entry the
   *   eb_* tools resolve through; if no backend claimed the node type, every
   *   tool would be inert.
   * - Serialize (eb_read_entity): the backend serializes the saved page
   *   and the blueprint round-trips -- entity_type=node, bundle=page, the
   *   _root_entity pointer back to the saved node, and a layout
   *   key reflecting the Layout Builder section the recipe's default display
   *   seeds (daedalus_layout stamps it with a section UUID). That layout key is the
   *   load-bearing proof the LB serialization survives on the real display, not
   *   just on a hand-rolled entity_test fixture.
   * - Read schema (eb_read_schema): the backend builds the bundle schema and it
   *   advertises available_block_types for the page display (filtered by the
   *   recipe's layout_builder_restrictions). The test discovers a real block
   *   type and one of its text fields FROM THE SCHEMA rather than hardcoding a
   *   recipe-internal field name, which is precisely how the AI learns what to
   *   send and keeps this test robust against block-type drift.
   * - Operate (eb_edit): one atomic batch composes through the ONE anchor
   *   grammar -- add_section anchored on the root entity with a minted uuid,
   *   then add_element of the discovered type placed inside the newborn
   *   section, with the discovered field set. The returned BlueprintResult is
   *   successful, reached the 'hydrated' phase, carries the SAME (unsaved) node
   *   instance, and names the new component UUID in its targets -- the contract
   *   the AI tool formats back to the model. The new block is present in the
   *   entity's in-memory layout, but the saved node in the database is still
   *   untouched (operations never save implicitly).
   * - Persist (the tool's DB-commit path): EntityStorageHandler::persist with
   *   force_save writes straight to the database (the AI-tool commit, bypassing
   *   the LB tempstore an unforced edit would route to). A node reloaded fresh
   *   from storage now carries the added component in its layout override,
   *   proving the AI-style operation reached durable storage on the real model.
   *
   * What it deliberately does NOT do: there is no LLM, no agent loop, no chat
   * UI, and no assertion about model output. prompt_capture cannot emit tool
   * calls and its sink is in-process only, so a faithful AI-tool-driven JS test
   * is not achievable in CI; this proves everything up to (and the application
   * of) the JSON operation a real tool call would carry, which is the whole of
   * daedalus_blueprint's contract.
   */
  public function testPageBlueprintRoundTrip(): void {
    // Run as an all-permissions account: daedalus_blueprint enforces access as
    // the current user across serialize, schema, operate, and persist, so an
    // admin keeps the test access-transparent (access semantics are covered by
    // the dedicated kernel access tests, not here).
    $this->setCurrentUser($this->drupalCreateUser([], 'blueprint-admin', TRUE));

    // A real Layout Builder page from the recipe's content model.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Assembled-stack blueprint round-trip',
    ]);
    $node->save();
    $nid = (int) $node->id();

    // The dispatch layer resolves the content backend for the node type -- the
    // same entry point the eb_* tools route through.
    $resolver = \Drupal::service('daedalus_blueprint.backend_resolver');
    $backend = $resolver->resolve('node');
    $this->assertNotNull(
      $backend,
      'A blueprint backend claims the node entity type on the real recipe.',
    );
    $this->assertTrue(
      $backend->supports('node'),
      'The resolved backend supports node (the ContentBlueprintBackend path).',
    );

    // eb_read_entity: serialize the saved page through the backend.
    $blueprint = $backend->serialize($node);
    $this->assertSame('node', $blueprint['entity_type'], 'The blueprint reports the node entity type.');
    $this->assertSame('page', $blueprint['bundle'], 'The blueprint reports the page bundle.');
    $this->assertSame(
      ['field' => 'nid', 'value' => $nid],
      $blueprint['_root_entity'],
      'The blueprint carries the round-trip pointer back to the saved node.',
    );

    // The Layout Builder layout round-trips: the recipe's default display seeds
    // a section, so the serialized layout has at least one section, and daedalus_layout
    // stamps it with a section UUID the AI can target in later operations.
    $this->assertArrayHasKey(
      'layout',
      $blueprint,
      'The page serializes its Layout Builder layout (the LB round-trip on the real display).',
    );
    $this->assertNotEmpty($blueprint['layout'], 'The serialized layout has at least one section.');
    $first_section = $blueprint['layout'][0];
    $this->assertArrayHasKey('layout_id', $first_section, 'The serialized section names its layout plugin.');
    $this->assertArrayHasKey(
      'uuid',
      $first_section,
      'daedalus_layout stamps the section with a UUID the AI can target (proves the daedalus_layout adapter is wired on the real stack).',
    );
    // With core's Basic page already present (setUp applies its recipe) the
    // suite's recipe TAKES OVER that bundle rather than shipping a second one
    // (see daedalus_page's recipe comments): enabling Layout Builder on the
    // pre-existing display seeds a layout_onecol section carrying the
    // display's field blocks. That is the real default-section shape a
    // takeover apply produces (every 11.3 standard site, every upgrade), and a
    // multi-region section's slots serialize as region nodes under the
    // container (containers -- exercised by the add_section op below --
    // hold their ordered children directly; the shipped blank-placeholder
    // display only reaches sites where the import created the bundle).
    $this->assertSame('container', $first_section['kind']);
    $this->assertSame('layout_onecol', $first_section['layout_id'], 'The takeover display seeds core\'s one-column section.');
    $this->assertArrayNotHasKey('regions', $first_section, 'The tree has one child axis, never a regions map.');
    $this->assertSame('region', $first_section['children'][0]['kind'], 'A multi-region section holds its slots as region nodes.');
    $this->assertSame('content', $first_section['children'][0]['region']);

    // eb_read_schema: the backend advertises the block types the AI may place
    // into this display. Discover a real block type and a text field FROM THE
    // SCHEMA instead of hardcoding recipe internals -- the AI's own learn-then-
    // act flow, and what keeps this test robust to block-type drift.
    $schema = $backend->getSchema('node', 'page', 'full');
    $available = $schema['layout']['available_block_types'] ?? [];
    $this->assertNotEmpty(
      $available,
      'The schema advertises inline block types for the page display (eb_read_schema surface).',
    );

    [$block_type, $field_name] = $this->discoverBlockTypeWithTextField($available);
    $this->assertNotNull(
      $block_type,
      'The recipe ships at least one inline block type with an editable text field for the AI to populate.',
    );

    // eb_edit: the canonical compose gesture on a fresh page as ONE atomic
    // batch through the ONE anchor grammar -- mint the uuids first, add a
    // section anchored on the root entity, then place the discovered block
    // inside the newborn section by its minted uuid. (A fresh page's default
    // sections are display values until a write lands, so the AI composes
    // its own section rather than anchoring transient default identity.)
    $marker = 'Blueprint round-trip marker text';
    $operations = $backend->getOperations();
    $host = \Drupal::service('daedalus_blueprint.host_resolver')->fromEntity($node);
    $section_uuid = \Drupal::service('uuid')->generate();
    $new_uuid = \Drupal::service('uuid')->generate();
    $result = $operations->batchOperations($host, [
      [
        'op' => 'add_section',
        'uuid' => $section_uuid,
        'location' => ['anchor' => $node->uuid(), 'placement' => 'inside'],
      ],
      [
        'op' => 'add_element',
        'block_type' => $block_type,
        'uuid' => $new_uuid,
        'location' => ['anchor' => $section_uuid, 'placement' => 'inside'],
        'properties' => [
          $field_name => $this->valueForField($available[$block_type]['block_fields'][$field_name], $marker),
        ],
      ],
    ]);

    // The BlueprintResult contract the AI tool relies on.
    $this->assertTrue(
      $result->isSuccess(),
      'The add-element operation succeeded on the real page: '
      . implode('; ', array_map(fn($e) => $e->getCode() . ' ' . $e->getMessage(), $result->getErrors())),
    );
    $this->assertSame('hydrated', $result->getPhase(), 'A successful surgical operation reaches the hydrated phase.');
    $this->assertSame(
      $node,
      $result->getEntity(),
      'The operation returns the same (unsaved) node instance the caller controls.',
    );
    $element_uuids = $result->getElementUuids();
    $this->assertContains($section_uuid, $element_uuids, 'The result names the minted section among its targets.');
    $this->assertContains($new_uuid, $element_uuids, 'The result names the minted block among its targets.');

    // The new block is in the entity's in-memory layout...
    $this->assertNotNull(
      $operations->getElement($host, $new_uuid),
      'The added component is locatable in the entity layout before persistence.',
    );

    // ...but operations never save implicitly: the database node is untouched.
    $node_storage = \Drupal::entityTypeManager()->getStorage('node');
    $node_storage->resetCache([$nid]);
    $unsaved_reload = $node_storage->load($nid);
    $this->assertTrue(
      $unsaved_reload->get('layout_builder__layout')->isEmpty(),
      'Before persist, the saved node still has no layout override (operations never save implicitly).',
    );

    // The AI-tool commit path: force_save writes straight to the database,
    // bypassing the LB tempstore an unforced edit would route to.
    $storage_handler = $backend->getStorageHandler();
    $storage_handler->persist($host, FALSE, TRUE, $element_uuids);

    // A node reloaded fresh from storage now carries the added component in its
    // layout override -- the AI-style operation reached durable storage.
    $node_storage->resetCache([$nid]);
    $persisted = $node_storage->load($nid);
    $this->assertFalse(
      $persisted->get('layout_builder__layout')->isEmpty(),
      'After force_save the persisted node carries a Layout Builder override.',
    );

    $found_uuids = [];
    foreach ($persisted->get('layout_builder__layout') as $item) {
      foreach ($item->section->getComponents() as $component) {
        $found_uuids[] = $component->getUuid();
      }
    }
    $this->assertContains(
      $new_uuid,
      $found_uuids,
      'The component the AI-style operation added is present in the persisted, freshly reloaded entity.',
    );
  }

  /**
   * Finds a block type in the schema that has an editable text field.
   *
   * Mirrors how the AI reads available_block_types and picks a field to write.
   * Restricts to block types whose only required field is 'info' (auto-seeded
   * by addElement) plus the chosen text field, so a single supplied value
   * fully satisfies the block's create constraints -- the AI would equally have
   * to satisfy every required field, but a one-field round-trip is the minimal
   * faithful proof and keeps the assertion unambiguous. Prefers a
   * single-cardinality string field, then any text field.
   *
   * @param array $available_block_types
   *   The available_block_types array from the bundle schema.
   *
   * @return array{0: ?string, 1: ?string}
   *   The chosen block type machine name and field name, or [NULL, NULL] if no
   *   block type exposes a usable text field with no other required fields.
   */
  protected function discoverBlockTypeWithTextField(array $available_block_types): array {
    $text_types = ['string', 'text_long', 'text_with_summary', 'string_long'];
    $fallback = [NULL, NULL];

    foreach ($available_block_types as $block_type => $definition) {
      $fields = $definition['block_fields'] ?? [];
      foreach ($fields as $field_name => $field_schema) {
        $type = $field_schema['type'] ?? NULL;
        if (!in_array($type, $text_types, TRUE)) {
          continue;
        }
        // The required 'info' field is auto-seeded by addElement; do not pick
        // it, so the asserted field is a genuine AI-supplied content field.
        if ($field_name === 'info') {
          continue;
        }
        // Reject block types that carry OTHER required fields. addElement runs
        // a full-create validation, so an unsatisfied required field would fail
        // the operation for a reason unrelated to the round-trip under test.
        if ($this->hasOtherRequiredFields($fields, $field_name)) {
          continue;
        }
        $cardinality = $field_schema['cardinality'] ?? 1;
        if ($type === 'string' && $cardinality === 1) {
          return [$block_type, $field_name];
        }
        if ($fallback === [NULL, NULL]) {
          $fallback = [$block_type, $field_name];
        }
      }
    }

    return $fallback;
  }

  /**
   * Checks whether a block type requires a field beyond 'info' and the chosen.
   *
   * @param array $fields
   *   The block_fields schema for one block type.
   * @param string $chosen_field
   *   The field name the test intends to populate.
   *
   * @return bool
   *   TRUE if any required field other than 'info' and the chosen field exists.
   */
  protected function hasOtherRequiredFields(array $fields, string $chosen_field): bool {
    foreach ($fields as $field_name => $field_schema) {
      if ($field_name === 'info' || $field_name === $chosen_field) {
        continue;
      }
      if (!empty($field_schema['required'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Builds a field value the handler accepts for the discovered field type.
   *
   * @param array $field_schema
   *   The field's schema entry (carries its 'type').
   * @param string $text
   *   The text to place in the field.
   *
   * @return string|array
   *   A scalar for plain string fields, or a value/format array for formatted
   *   text fields, matching what the field handler deserializes.
   */
  protected function valueForField(array $field_schema, string $text): string|array {
    $type = $field_schema['type'] ?? 'string';
    if (in_array($type, ['text_long', 'text_with_summary'], TRUE)) {
      return ['value' => $text, 'format' => 'plain_text'];
    }
    return $text;
  }

}
