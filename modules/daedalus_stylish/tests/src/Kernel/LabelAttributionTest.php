<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\Kernel;

use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the block-label selectable key reaching rendered markup.
 *
 * The placement label pseudo-field ("block:{component_uuid}:label") is
 * stamped by daedalus_edit_preprocess_block on title_attributes — the title
 * element itself, not the editing wrapper div — so label styles are direct
 * declarations on the element that carry them past the theme's own
 * h2/.block__title rules, instead of inherited values that lose to them.
 * Edit mode stamps unconditionally; a live render stamps only when the
 * style map demands the key. In both modes daedalus_ui's
 * InlineBlockFieldKeys rewrites the stamp from content-uuid form
 * ("field:{block_content_uuid}:label") to component-uuid form at the block
 * template boundary.
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class LabelAttributionTest extends KernelTestBase {

  use UserCreationTrait;

  private const COMPONENT_UUID = '44444444-4444-4444-4444-444444444444';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'entity_test',
    'daedalus_blueprint',
    'navigation',
    'twig_events',
    'daedalus_tempstore',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_layout',
    'field_sample_value',
    'filter',
    'editor',
    'daedalus_edit',
    'daedalus_stylish',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('block_content');
    $this->installConfig(['daedalus_stylish']);
    // The InlineBlockFieldKeys rewrite rides TwigRenderTemplateEvent, which
    // fires only for templates rendered through twig_events' theme engine:
    // the engine rides every installed twig theme (system_info_alter), but
    // core routes module-typed hooks through plain twig, so the block hook
    // dispatches only when the active theme overrides block.html.twig —
    // olivero does (and is the theme this seam is verified against live).
    $this->container->get('theme_installer')->install(['olivero']);
    $this->config('system.theme')->set('default', 'olivero')->save();
    // With no system config installed the defaults stylesheet compiles (at
    // installConfig(['daedalus_stylish']) and again inside hook_themes_installed(),
    // which core fires after its active-theme reset) for the request's
    // active theme, memoizing core's pseudo-theme in the theme manager
    // before olivero is the default; a config save never resets that memo,
    // so it is dropped here for the render to negotiate olivero.
    $this->container->get('theme.manager')->resetActiveTheme();
    $this->setUpCurrentUser([], ['view test entity']);
    $this->container->get('daedalus_stylish.field_manager')->enable('entity_test', 'entity_test');
    BlockContentType::create(['id' => 'heading', 'label' => 'Heading'])->save();
  }

  /**
   * Creates a styled host entity whose map holds the given entries.
   */
  private function hostWithMap(array $map): EntityTest {
    $host = EntityTest::create(['name' => 'host']);
    $host->set(DaedalusStylishFieldManager::FIELD_NAME, json_encode($map));
    $host->save();
    return $host;
  }

  /**
   * Creates the inline block's content entity, registered to a host.
   */
  private function blockContent(?EntityTest $host): BlockContent {
    $block_content = BlockContent::create(['type' => 'heading', 'info' => 'heading']);
    $block_content->save();
    if ($host !== NULL) {
      $this->container->get('daedalus_layout.root_entity_registry')->setRoot($block_content->uuid(), $host);
    }
    return $block_content;
  }

  /**
   * Renders an inline block the way Layout Builder's seams deliver it.
   *
   * @param \Drupal\block_content\Entity\BlockContent $block_content
   *   The inline block's content entity.
   * @param array $attributes
   *   The wrapper attributes LayoutBuilderBlockAttributes would have stamped
   *   (the component-uuid carrier InlineBlockFieldKeys parses).
   */
  private function renderBlock(BlockContent $block_content, array $attributes): string {
    $build = [
      '#theme' => 'block',
      '#attributes' => $attributes,
      '#configuration' => [
        'label' => 'Cyber Gorillas',
        'label_display' => 'visible',
        'provider' => 'layout_builder',
      ],
      '#plugin_id' => 'inline_block:heading',
      '#base_plugin_id' => 'inline_block',
      '#derivative_plugin_id' => 'heading',
      'content' => [
        '#markup' => 'body text',
        '#view_mode' => 'full',
        '#block_content' => $block_content,
      ],
    ];
    return (string) $this->container->get('renderer')->renderInIsolation($build);
  }

  /**
   * A styled label's key rides the title element on live renders.
   */
  public function testLiveStampWhenDemanded(): void {
    $host = $this->hostWithMap([
      'block:' . self::COMPONENT_UUID . ':label' => ['default' => ['color' => '#ff0f0f']],
    ]);
    $block_content = $this->blockContent($host);

    // Live wrapper: only the selection identity, as stamped on demand by
    // LayoutBuilderBlockAttributes.
    $markup = $this->renderBlock($block_content, ['data-selectable' => 'block:' . self::COMPONENT_UUID]);

    // The key is on the h2 itself, rewritten to component-uuid form.
    $this->assertMatchesRegularExpression(
      '/<h2[^>]* data-selectable="block:' . self::COMPONENT_UUID . ':label"/',
      $markup,
    );
    // The editing machinery stays off live renders.
    $this->assertStringNotContainsString('data-daedalus-edit-field-value-wrapper', $markup);
    $this->assertStringNotContainsString('daedalus-edit-field-value', $markup);
  }

  /**
   * An unstyled label renders live without any selectable key.
   */
  public function testNoLiveStampWithoutLabelStyles(): void {
    // The block itself is styled, but not its label.
    $host = $this->hostWithMap([
      'block:' . self::COMPONENT_UUID => ['default' => ['padding' => '1rem']],
    ]);
    $block_content = $this->blockContent($host);

    $markup = $this->renderBlock($block_content, ['data-selectable' => 'block:' . self::COMPONENT_UUID]);

    $this->assertDoesNotMatchRegularExpression('/<h2[^>]* data-selectable=/', $markup);
  }

  /**
   * Edit mode stamps the title element unconditionally, wrapper key-free.
   */
  public function testEditModeStamp(): void {
    $this->setUpCurrentUser([], ['view test entity', 'access inline editing']);
    $this->container->get('request_stack')->getCurrentRequest()->query->set('navigationMode', 'edit');
    // No styles anywhere: the edit-mode stamp does not depend on the map.
    $block_content = $this->blockContent(NULL);

    $markup = $this->renderBlock($block_content, ['data-layout-builder-block-uuid' => self::COMPONENT_UUID]);

    $this->assertMatchesRegularExpression(
      '/<h2[^>]* data-selectable="block:' . self::COMPONENT_UUID . ':label"/',
      $markup,
    );
    // The editing wrapper renders for the form machinery, but no longer
    // carries the selectable key — the h2 is the scope root.
    $this->assertMatchesRegularExpression(
      '/<div data-daedalus-edit-field-value-wrapper="block_content::\d+::label::block_property">/',
      $markup,
    );
  }

}
