<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Functional;

use Drupal\layout_builder\Plugin\SectionStorage\OverridesSectionStorage;
use Drupal\layout_builder\SectionComponent;
use Drupal\node\Entity\Node;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The block-form door answers for the view mode the page actually rendered.
 *
 * The editing engine fetches a placement's Layout Builder block form from
 * the block-form endpoint (DaedalusLayoutRouteSubscriber, BlockFormController)
 * with the view mode the block wrapper carries as viewMode; the form build
 * validates it against the display repository's view modes for
 * block_content plus `default` (DaedalusEditFormTrait::getViewMode) and
 * throws, HTTP 500, for a mode the site does not define. Core's InlineBlock
 * defaults a placement's view_mode to `full`, and the op spine's add_element
 * writes that default into every placement that names no view mode, so a
 * site without block_content's `full` view mode loses the form path for
 * every such block while `default` keeps answering.
 *
 * This tier, and not a Kernel one, because the gap is a recipe's: a recipe
 * `install:` skips the installed modules' default config, so a recipe-built
 * site carries block_content's `full` view mode only if an ingredient
 * imports it (the engine ingredient does, from core's own file), while a
 * Kernel class's module install creates that default config and cannot see
 * the mode go missing. The fixture here is the recipe's product
 * (DaedalusFixtureTrait), the one tier where the import's absence is
 * visible without a browser: the two FunctionalJavascript classes that
 * reach the same door (InlineEditBehaviorTest and
 * ImageBlockBlueprintEditBehaviorTest) each cost a browser run.
 */
#[Group('daedalus_edit')]
#[RunTestsInSeparateProcesses]
class BlockFormViewModeTest extends BrowserTestBase {

  use DaedalusFixtureTrait;

  /**
   * A `full` placement's block form is 200, as `default` is.
   */
  public function testBlockFormAnswersRenderedViewMode(): void {
    $this->loginAsEditor();
    $node = Node::create([
      'type' => 'page',
      'title' => 'Block form view mode page',
    ]);
    $node->save();

    // One text block placed the way the op spine places it (a document
    // write, then committed), with the view mode core's InlineBlock and
    // add_element default to spelled out on the block node.
    $host = \Drupal::service('daedalus_blueprint.host_resolver')->fromEntity($node);
    $result = \Drupal::service('daedalus_blueprint.document_instantiator')->instantiateDocument($host, [
      [
        'kind' => 'container',
        'layout_id' => 'layout_onecol',
        'children' => [
          [
            'kind' => 'region',
            'region' => 'content',
            'children' => [
              [
                'kind' => 'block',
                'block_type' => 'text',
                'view_mode' => 'full',
                'block_fields' => ['field_text_text' => ['value' => 'Block form view mode text']],
              ],
            ],
          ],
        ],
      ],
    ], [], ['indexed' => TRUE, 'batch' => ['system' => TRUE, 'admitted' => TRUE]]);
    $this->assertTrue($result->isSuccess(), implode("\n", array_map(static fn ($error) => $error->getPath() . ': ' . $error->getMessage(), $result->getErrors())));
    \Drupal::service('daedalus_blueprint.storage_handler')->commit($host);

    // The committed placement is an inline block whose component
    // configuration carries view_mode `full`: the mode the wrapper is
    // stamped with and the client sends back as viewMode. Read from the
    // component config, not a rendered stamp.
    $component = $this->inlineBlockComponent((int) $node->id());
    $this->assertSame('full', $component->get('configuration')['view_mode'], 'The placement renders in the full view mode.');

    $path = '/daedalus-edit/block-form/overrides/node.' . $node->id() . '/' . $component->getUuid();
    $assert_session = $this->assertSession();

    // The door, as the client fetches it for this placement: the block form
    // built (the panel's Update button, PanelUpdateBlockForm::buildForm),
    // not an exception page.
    $this->drupalGet($path, ['query' => ['viewMode' => 'full', '_wrapper_format' => 'drupal_ajax']]);
    $assert_session->statusCodeEquals(200);
    $assert_session->responseContains('daedalus-edit-update-button');

    // The control: `default` is always a valid mode, so a 200 here with a
    // 500 above is the missing view mode and nothing else about the door.
    $this->drupalGet($path, ['query' => ['viewMode' => 'default', '_wrapper_format' => 'drupal_ajax']]);
    $assert_session->statusCodeEquals(200);
    $assert_session->responseContains('daedalus-edit-update-button');
  }

  /**
   * The one inline block component of the node's committed layout override.
   */
  private function inlineBlockComponent(int $nid): SectionComponent {
    $node = Node::load($nid);
    $components = [];
    foreach ($node->get(OverridesSectionStorage::FIELD_NAME)->getSections() as $section) {
      foreach ($section->getComponents() as $component) {
        if (str_starts_with($component->getPluginId(), 'inline_block:')) {
          $components[] = $component;
        }
      }
    }
    $this->assertCount(1, $components, 'The committed override holds exactly one inline block.');
    return $components[0];
  }

}
