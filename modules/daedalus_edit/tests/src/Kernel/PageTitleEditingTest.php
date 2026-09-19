<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\daedalus_edit\PageTitleEditing;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the outside-rendered-label rule behind page-title inline editing.
 *
 * The entity's label field presents AS the root row (the element model's
 * derived edit target), but the label usually renders OUTSIDE the entity
 * as the theme's page-title element. PageTitleEditing decides when the
 * page title stands in for the label as its one in-place leaf: exactly
 * when the model names it AND the entity render itself does not produce
 * the field on the canonical page, so the title element is the label's
 * ONE canvas render (duplicate leaf identities would make the form-swap
 * and markup replacement ambiguous). The rule answers from proven render
 * truths only:
 * a node's non-configurable title (template_preprocess_node pulls it out of
 * page content) and Layout Builder's unpreserved root fields; everything
 * else stays inert. The same rule gates both delivery halves, attribution
 * (preprocessPageTitle) and the commit round-trip's replacement render
 * (titleRender), so they agree by construction.
 *
 * @group daedalus_edit
 */
#[RunTestsInSeparateProcesses]
class PageTitleEditingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The daedalus_ui hard dependencies (daedalus_blueprint +
   * daedalus_blueprint_api) are invisible to kernel environments and must be
   * listed explicitly or the container fails to compile. daedalus_layout registers
   * the preserved-fields existence feeder and owns the preserved_fields
   * third-party setting the LB branch reads.
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
   * The service under test.
   */
  protected PageTitleEditing $pageTitleEditing;

  /**
   * The node whose label the page title may stand in for.
   */
  protected Node $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('block_content');
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    EntityViewDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ])->save();
    $this->node = Node::create(['type' => 'page', 'title' => 'Hello there']);
    $this->pageTitleEditing = $this->container->get('daedalus_edit.page_title_editing');
  }

  /**
   * The bundle's render display, fresh from storage.
   */
  protected function display(): EntityViewDisplay {
    return EntityViewDisplay::collectRenderDisplay($this->node, 'default');
  }

  /**
   * The proven render truths decide when the page title IS the label.
   */
  public function testOutsideLabelField(): void {
    // A node's title is not display configurable and never renders inside
    // the entity on its canonical page (template_preprocess_node moves it
    // to the `label` variable, printed only on non-page views): the page
    // title is the label's one render, plain display or not.
    $this->assertSame('title', $this->pageTitleEditing->outsideLabelField($this->node));

    // The same holds on a Layout Builder display with the title unpreserved
    // (the site shape that motivated the rule)...
    $display = $this->display();
    $display->enableLayoutBuilder()->save();
    $this->assertSame('title', $this->pageTitleEditing->outsideLabelField($this->node));

    // ...and even preserved: preservation keeps the FIELD in the render
    // array, but node preprocess still pulls it out of page content; the
    // node rule outranks the LB branch (which speaks for configurable
    // labels on non-node roots).
    $display = $this->display();
    $display->setThirdPartySetting('daedalus_layout', 'preserved_fields', ['title'])->save();
    $this->assertSame('title', $this->pageTitleEditing->outsideLabelField($this->node));

    // A root without an edit target has nothing for the page title to
    // stand in for: block_content's label key (info) is declared
    // element: false, so the model names no label for the root at all.
    BlockContentType::create(['id' => 'basic', 'label' => 'Basic', 'revision' => FALSE])->save();
    $block = BlockContent::create(['type' => 'basic', 'info' => 'Admin label']);
    $this->assertNull($this->pageTitleEditing->outsideLabelField($block));
  }

  /**
   * The titleRender() method delivers the H1 only for the outside label.
   */
  public function testTitleRender(): void {
    // The update round-trip's replacement render: the same rule, keyed on
    // the field the update targets.
    $render = $this->pageTitleEditing->titleRender($this->node, 'title');
    $this->assertSame('page_title', $render['#type']);
    $this->assertSame('Hello there', $render['#title']);

    // Any other field updates inside the entity render: no title render.
    $this->assertNull($this->pageTitleEditing->titleRender($this->node, 'body'));
  }

}
