<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\Component\Utility\Html;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The panel header contract over the module's own sidebar panels.
 *
 * Every rail panel wears the one header grammar
 * (SidebarPluginBase::buildPanelHeader): a .daedalus-ui-panel__header row holding
 * the title and a .daedalus-ui-panel__actions cluster whose last member is the
 * close control (data-sidebar-close, concealed at clean density only,
 * the pack's minus glyph). This is the server contract the FJS round trip
 * (EditModeBehaviorTest's History leg) rides; the cheapest tier that sees
 * a plugin dropping the helper.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class SidebarPanelHeaderTest extends KernelTestBase {

  /**
   * The fixture theme the Page Elements anatomy payload resolves against.
   */
  protected const THEME = 'daedalus_ui_declarations_test_theme';

  /**
   * {@inheritdoc}
   *
   * The module's hard dependencies are invisible to kernel
   * environments and must be listed explicitly or the container fails to
   * compile.
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'link',
    'options',
    'file',
    'image',
    'media',
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
    'daedalus_ui_declarations_test',
    'daedalus_ui_declarations_test_extra',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The Page Elements panel attaches the anatomy payload, which resolves
    // the site default theme's declarations.
    $this->container->get('theme_installer')->install([self::THEME]);
    $this->config('system.theme')->set('default', self::THEME)->save();
  }

  /**
   * The panels and the header actions each declares before the close.
   *
   * @return array<string, array{string, string, string[]}>
   *   Plugin id, title, and the CSS selectors of the plugin's own actions in
   *   cluster order.
   */
  public static function panelProvider(): array {
    return [
      'settings' => ['edit_mode_settings', 'Edit mode settings', []],
      'history' => ['history', 'History', ['#history-undo', '#history-redo']],
      'notifications' => ['notifications', 'Notifications', ['#clear-notifications']],
      'page elements' => ['page_elements', 'Page elements', ['#page-elements-show-menu.daedalus-ui-panel__slot']],
    ];
  }

  /**
   * Each panel renders one header row with its title, actions, and close.
   */
  #[DataProvider('panelProvider')]
  public function testPanelHeaderContract(string $plugin_id, string $title, array $action_selectors): void {
    $build = $this->container->get('plugin.manager.daedalus_ui_sidebars')->createInstance($plugin_id)->build();
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $document = Html::load($html);
    $xpath = new \DOMXPath($document);

    // One header row, holding the title.
    $headers = $this->query($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " daedalus-ui-panel__header ")]');
    $this->assertCount(1, $headers, "$plugin_id renders exactly one .daedalus-ui-panel__header");
    $header = $headers[0];
    $this->assertSame('div', $header->tagName);
    $titles = $this->query($xpath, './h3', $header);
    $this->assertCount(1, $titles, "$plugin_id's header holds one h3 title");
    $this->assertSame($title, trim($titles[0]->textContent));

    // One action cluster, the plugin's actions in order, the close last.
    $clusters = $this->query($xpath, './/*[contains(concat(" ", normalize-space(@class), " "), " daedalus-ui-panel__actions ")]', $header);
    $this->assertCount(1, $clusters, "$plugin_id's header holds one .daedalus-ui-panel__actions cluster");
    $cluster = $clusters[0];
    $members = $this->query($xpath, './*', $cluster);
    $this->assertCount(count($action_selectors) + 1, $members, "$plugin_id's cluster holds its actions and the close");
    foreach ($action_selectors as $index => $selector) {
      $this->assertSame($members[$index], $this->querySelector($document, $selector), "$plugin_id's action $selector sits at cluster position $index");
    }

    // The close control: exactly one in the whole panel, the cluster's last
    // member, wired to the sidebar-close behavior, concealed at clean only,
    // labelled, wearing the pack glyph.
    $closes = $this->query($xpath, '//*[contains(concat(" ", normalize-space(@class), " "), " daedalus-ui-panel__close ")]');
    $this->assertCount(1, $closes, "$plugin_id renders exactly one .daedalus-ui-panel__close");
    $close = $closes[0];
    $this->assertSame(end($members), $close, "$plugin_id's close is the cluster's last member");
    $this->assertSame('button', $close->tagName);
    $this->assertSame('button', $close->getAttribute('type'));
    $this->assertTrue($close->hasAttribute('data-sidebar-close'));
    $this->assertSame('clean', $close->getAttribute('data-daedalus-ui-conceal'));
    $this->assertSame("Close $title", $close->getAttribute('aria-label'));
    $this->assertSame('Close', $close->getAttribute('data-daedalus-ui-tooltip'));
    $classes = explode(' ', $close->getAttribute('class'));
    $this->assertContains('daedalus-ui-icon-button', $classes);
    $this->assertContains('daedalus-ui-icon-button--muted', $classes);
    $glyphs = $this->query($xpath, './/svg[contains(concat(" ", normalize-space(@class), " "), " daedalus-ui-panel__close-icon ")]', $close);
    $this->assertCount(1, $glyphs, "$plugin_id's close wraps the pack's minus glyph");
    $this->assertSame('0 0 256 256', $glyphs[0]->getAttribute('viewBox'));
    $this->assertCount(1, $this->query($xpath, './path', $glyphs[0]));

    // The retired local header markup is gone.
    $this->assertStringNotContainsString('header-wrapper', $html);
    $this->assertStringNotContainsString('history-actions', $html);
  }

  /**
   * Runs an XPath query and returns the matched elements as a list.
   *
   * @return \DOMElement[]
   *   The matched elements.
   */
  private function query(\DOMXPath $xpath, string $expression, ?\DOMNode $context = NULL): array {
    return iterator_to_array($xpath->query($expression, $context), FALSE);
  }

  /**
   * Resolves the test's simple id-and-class selectors to one element.
   */
  private function querySelector(\DOMDocument $document, string $selector): \DOMElement {
    [$id, $class] = array_pad(explode('.', $selector, 2), 2, NULL);
    $element = $document->getElementById(ltrim($id, '#'));
    $this->assertNotNull($element, "$selector is rendered");
    if ($class !== NULL) {
      $this->assertContains($class, explode(' ', $element->getAttribute('class')), "$selector wears .$class");
    }
    return $element;
  }

}
