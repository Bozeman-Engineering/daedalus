<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\daedalus_blueprint\ExtractedValue;
use Drupal\daedalus_blueprint\FieldHandler\TextFieldHandler;
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
 * re-save, and a format that allows a tag without class still saves with
 * that tag skipped), the blueprint stage-time seam (a FieldAppliedEvent
 * subscriber mints whatever the spine applies, a grammar value and an
 * ExtractedValue alike, with no save and no handler of daedalus_edit's own,
 * skipping the same tags without failing the operation), and the backfill
 * (legacy identity-less content is found, minted, and saved exactly once).
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
    'file',
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
   * A format allowing a tag without class saves; that tag stays unminted.
   */
  public function testPresaveBackstopSkipsUnmintableTags(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Partial',
      'field_body' => ['value' => '<p>Hi</p><h2>Title</h2>', 'format' => 'broken'],
    ]);
    $node->save();

    $stored = $this->reload($node)->get('field_body')->value;
    $this->assertCount(1, $this->ids($stored), 'Only the heading minted; the save was not refused.');
    $this->assertStringContainsString('<p>Hi</p>', $stored, 'The class-less paragraph was left untouched.');
  }

  /**
   * Stages one value onto the node's body through the spine, no save.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The subject.
   * @param mixed $value
   *   A grammar value or an ExtractedValue for field_body.
   */
  protected function stage(NodeInterface $node, mixed $value): void {
    $result = $this->container->get('daedalus_blueprint.operations')->batchOperations($node, [
      ['op' => 'update_element', 'element' => $node->uuid(), 'properties' => ['field_body' => $value]],
    ]);
    $this->assertTrue($result->isSuccess(), print_r($result->toArray(), TRUE));
  }

  /**
   * An ExtractedValue for field_body, built the way a form submit builds one.
   */
  protected function extracted(string $html, string $format): ExtractedValue {
    $source = Node::create([
      'type' => 'page',
      'title' => 'Source',
      'field_body' => ['value' => $html, 'format' => $format],
    ]);
    return new ExtractedValue($source->get('field_body'));
  }

  /**
   * A saved node whose stored body is already minted and byte-stable.
   */
  protected function savedNode(): NodeInterface {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Subject',
      'field_body' => ['value' => '<p>Saved</p>', 'format' => 'identity'],
    ]);
    $node->save();
    return $this->reload($node);
  }

  /**
   * The stage-time seam mints a grammar value the spine applies, unsaved.
   */
  public function testStageSeamMintsGrammarValue(): void {
    // daedalus_edit ships no text handler of its own any more: the grammar
    // path runs daedalus_blueprint's handler and the seam is the event.
    $definition = FieldConfig::load('node.page.field_body');
    $handler = $this->container->get('daedalus_blueprint.field_handler_manager')->getHandler($definition);
    $this->assertSame(TextFieldHandler::class, $handler::class, "The spine's own text handler serves formatted text; identity rides the applied seam.");

    $node = $this->savedNode();
    $stored = $node->get('field_body')->value;
    $this->stage($node, ['value' => '<p>Staged</p><h2>Two</h2>', 'format' => 'identity']);

    $staged = $node->get('field_body')->value;
    $this->assertCount(2, $this->ids($staged), 'Both vocabulary elements carry identity at stage time.');
    $this->assertSame($stored, $this->reload($node)->get('field_body')->value, 'Nothing was saved: the presave backstop did not run.');
  }

  /**
   * The stage-time seam mints an ExtractedValue the spine applies, unsaved.
   */
  public function testStageSeamMintsExtractedValue(): void {
    $node = $this->savedNode();
    $stored = $node->get('field_body')->value;
    $this->stage($node, $this->extracted('<p>Extracted</p><blockquote>Quote</blockquote>', 'identity'));

    $staged = $node->get('field_body')->value;
    $this->assertCount(2, $this->ids($staged), 'An ExtractedValue is minted although no handler ever saw it.');
    $this->assertSame($stored, $this->reload($node)->get('field_body')->value, 'Nothing was saved: the presave backstop did not run.');
  }

  /**
   * The stage-time seam skips class-less tags without failing the operation.
   */
  public function testStageSeamSkipsUnmintableTags(): void {
    $node = $this->savedNode();
    foreach ([
      'grammar' => ['value' => '<p>Staged</p><h2>Title</h2>', 'format' => 'broken'],
      'extracted' => $this->extracted('<p>Staged</p><h2>Title</h2>', 'broken'),
    ] as $kind => $value) {
      $this->stage($node, $value);
      $staged = $node->get('field_body')->value;
      $this->assertCount(1, $this->ids($staged), "Only the heading minted at stage time ($kind).");
      $this->assertStringContainsString('<p>Staged</p>', $staged, "The class-less paragraph was left untouched ($kind).");
    }
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
