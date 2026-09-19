<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\daedalus_edit\FieldHandler\IdentityMintingTextFieldHandler;
use Drupal\daedalus_edit\IdentityMinter;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the wiring that runs the identity minter on every write path.
 *
 * The minter itself is covered by IdentityMinterTest; under test here are
 * the three wires of the canonical guarantee: the hook_entity_presave()
 * backstop (every saving write path leaves storage minted, byte-stable on
 * re-save, loud on an unmintable format), the blueprint stage-time seam
 * (daedalus_edit's handler outranks daedalus_blueprint's for formatted text and
 * mints at deserialize, surfacing unmintable formats as blueprint errors),
 * and the backfill (legacy identity-less content is found, minted, and
 * saved exactly once).
 *
 * @group daedalus_edit
 */
#[RunTestsInSeparateProcesses]
class IdentityMintingWiringTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   *
   * The minting wires only need filter + node, but compiling daedalus_edit's
   * container pulls in daedalus_ui and its invisible-to-kernel hard
   * dependencies (see PageTitleEditingTest), so the full stack is listed.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
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
    'daedalus_ui',
    'field_sample_value',
    'daedalus_edit',
    'daedalus_layout',
  ];

  /**
   * A format allowing class on the whole vocabulary (identity-capable).
   */
  protected FilterFormat $identity;

  /**
   * A format allowing <p> but stripping its class (identity-incapable).
   */
  protected FilterFormat $broken;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->identity = FilterFormat::create([
      'format' => 'identity',
      'name' => 'Identity',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => [
            'allowed_html' => '<p class> <h2 class> <h3 class> <h4 class> <blockquote class> <ul class> <ol class> <li class> <strong> <em> <a href>',
          ],
        ],
      ],
    ]);
    $this->identity->save();
    $this->broken = FilterFormat::create([
      'format' => 'broken',
      'name' => 'Broken',
      'filters' => [
        'filter_html' => [
          'status' => TRUE,
          'settings' => ['allowed_html' => '<p> <h2 class>'],
        ],
      ],
    ]);
    $this->broken->save();

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FieldStorageConfig::create([
      'field_name' => 'field_body',
      'entity_type' => 'node',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_body',
      'entity_type' => 'node',
      'bundle' => 'page',
    ])->save();

    // The blueprint handler enforces the 'use' permission on deserialize.
    $this->setUpCurrentUser([], [
      $this->identity->getPermissionName(),
      $this->broken->getPermissionName(),
    ]);
  }

  /**
   * All s-* ids in the HTML, in document order.
   *
   * @return string[]
   *   The minted ids.
   */
  protected function ids(string $html): array {
    preg_match_all('/class="([^"]*)"/', $html, $matches);
    $ids = [];
    foreach ($matches[1] as $classes) {
      foreach (preg_split('/\s+/', $classes, -1, PREG_SPLIT_NO_EMPTY) as $class) {
        if (preg_match(IdentityMinter::CLASS_PATTERN, $class, $m)) {
          $ids[] = $m[1];
        }
      }
    }
    return $ids;
  }

  /**
   * The node, fresh from storage with no static-cache residue.
   */
  protected function reload(NodeInterface $node): NodeInterface {
    /** @var \Drupal\node\NodeInterface */
    return $this->container->get('entity_type.manager')
      ->getStorage('node')
      ->loadUnchanged($node->id());
  }

  /**
   * The presave backstop mints on save, byte-stable across re-saves.
   */
  public function testPresaveBackstopMints(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Minted',
      'field_body' => ['value' => '<p>One</p><h2>Two</h2>', 'format' => 'identity'],
    ]);
    $node->save();

    $stored = $this->reload($node)->get('field_body')->value;
    $this->assertCount(2, $this->ids($stored), 'Both vocabulary elements were minted at save.');

    // A re-save of minted content is byte-stable: the minter's no-op returns
    // the input untouched, so the backstop never manufactures phantom diffs.
    $resave = $this->reload($node);
    $resave->save();
    $this->assertSame($stored, $this->reload($node)->get('field_body')->value);
  }

  /**
   * An identity-incapable format fails the save loudly.
   */
  public function testPresaveBackstopThrowsOnUnmintableFormat(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Broken',
      'field_body' => ['value' => '<p>Hi</p>', 'format' => 'broken'],
    ]);
    // Entity storage wraps presave exceptions in EntityStorageException; the
    // minter's LogicException message rides along.
    $this->expectException(EntityStorageException::class);
    $this->expectExceptionMessageMatches('/allows <p> but not its class attribute/');
    $node->save();
  }

  /**
   * The stage-time seam outranks the parent handler and mints on stage.
   */
  public function testBlueprintStageSeamMints(): void {
    $definition = FieldConfig::load('node.page.field_body');
    $handler = $this->container->get('daedalus_blueprint.field_handler_manager')->getHandler($definition);
    $this->assertInstanceOf(IdentityMintingTextFieldHandler::class, $handler, "daedalus_edit's handler supersedes daedalus_blueprint's for formatted text.");

    $result = $handler->deserialize(['value' => '<p>Staged</p>', 'format' => 'identity'], $definition);
    $this->assertFalse($result->hasErrors());
    $this->assertCount(1, $this->ids($result->getValue()['value']), 'The staged value carries identity without any save.');
  }

  /**
   * The stage-time seam surfaces an unmintable format as a blueprint error.
   */
  public function testBlueprintStageSeamSurfacesUnmintableFormat(): void {
    $definition = FieldConfig::load('node.page.field_body');
    $handler = $this->container->get('daedalus_blueprint.field_handler_manager')->getHandler($definition);

    $result = $handler->deserialize(['value' => '<p>Staged</p>', 'format' => 'broken'], $definition);
    $this->assertTrue($result->hasErrors(), 'The mutation fails instead of staging identity the render would strip.');
    $codes = array_map(fn($error) => $error->getCode(), $result->getErrors());
    $this->assertContains('IDENTITY_UNMINTABLE_FORMAT', $codes);
  }

  /**
   * The backfill finds legacy identity-less content, mints and saves it once.
   */
  public function testBackfillMintsLegacyContent(): void {
    // Seed content the presave backstop cannot have minted: save with
    // nothing mintable, then rewrite the field table directly, the shape of
    // content written before eager identity landed.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Legacy',
      'field_body' => ['value' => '<div>plain</div>', 'format' => 'identity'],
    ]);
    $node->save();
    $this->container->get('database')->update('node__field_body')
      ->fields(['field_body_value' => '<p>Legacy</p><blockquote>Old</blockquote>'])
      ->condition('entity_id', $node->id())
      ->execute();

    $backfill = $this->container->get('daedalus_edit.identity_backfill');
    $this->assertContains('node', $backfill->entityTypeIds());

    $result = $backfill->processChunk('node');
    $this->assertSame(1, $result['processed']);
    $this->assertSame(1, $result['saved'], 'The legacy node was minted and saved.');
    $this->assertCount(2, $this->ids($this->reload($node)->get('field_body')->value));

    // A second sweep changes nothing: the backfill only saves entities
    // minting actually changed.
    $result = $backfill->processChunk('node');
    $this->assertSame(1, $result['processed']);
    $this->assertSame(0, $result['saved']);
  }

}
