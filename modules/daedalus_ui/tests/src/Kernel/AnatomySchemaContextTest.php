<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\block_content\Entity\BlockContentType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the anatomy feed into daedalus_blueprint's schema context seam.
 *
 * The AI is a schema-time consumer with no per-instance `_element`
 * annotations and no client anatomy payload, so
 * hook_daedalus_blueprint_context_alter() delivers the element model's
 * markup shells as a bundle-schema fragment: kinds at the context root,
 * per-block-type and per-layout fragments inlined into their catalog
 * entries, and the composed compose fact plus the auto-rendered example
 * markup riding the fragment. Theme resolution is the site default theme
 * (the schema describes rendered pages to an off-page consumer).
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class AnatomySchemaContextTest extends KernelTestBase {

  protected const THEME = 'daedalus_ui_declarations_test_theme';

  /**
   * {@inheritdoc}
   *
   * Navigation_plus's hard dependencies are invisible to kernel
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
    $this->container->get('theme_installer')->install([self::THEME]);
    $this->config('system.theme')->set('default', self::THEME)->save();
    $this->installEntitySchema('block_content');
    // The composed fixture type's bundle: examples sample REAL bundles
    // only (a declaration without a bundle has nothing to render).
    BlockContentType::create(['id' => 'card', 'label' => 'Card'])->save();
  }

  /**
   * The context carries the anatomy fragment for the default theme.
   */
  public function testAnatomyContextFeed(): void {
    $context = $this->container->get('daedalus_blueprint.context_resolver')
      ->getContext('user', 'user');

    // Kind anatomy at the context root, with the reader's orientation notes
    // naming where the per-type fragments land.
    $this->assertStringContainsString('available_block_types[*].anatomy', $context['anatomy']['guidance']);
    $this->assertStringContainsString('anatomy.composed', $context['anatomy']['guidance']);
    $this->assertStringContainsString('anatomy.example', $context['anatomy']['guidance']);
    $field_kind = $context['anatomy']['kinds']['field']['elements'];
    $this->assertSame('Theme label', $field_kind['.field__label']['label']);
    // The default theme's layers apply: its NULL withdrew the items wrapper.
    $this->assertArrayNotHasKey('.field__items', $field_kind);

    // Block-type fragments are the EFFECTIVE anatomy with the composed
    // compose fact riding along (declared by the extra fixture module).
    $card = $context['block_types']['card']['anatomy'];
    $this->assertTrue($card['composed']);
    $this->assertSame('Theme card', $card['elements']['.card']['label']);
    $this->assertSame('Body copy', $card['elements']['.card']['elements']['.card__body']['label']);

    // The composed type's example: auto-rendered through the resolver
    // from a sample entity (bare here, field_sample_value not installed),
    // printed in the projector's format with the placeholder key. Parts
    // whose tag the grammar leaves undeclared (the block kind's shells)
    // cannot print, exactly as on a live page.
    $this->assertSame(implode("\n", [
      '<div data-key="block:UUID" data-type="card">',
      '  <div class="card" data-claims-content="">',
      '    <div class="card__body" data-claims-content=""></div>',
      '  </div>',
      '</div>',
    ]), $card['example']);

    // Layout fragments inline the same way.
    $this->assertSame(
      'region:aside',
      $context['layouts']['daedalus_stylish_twocol']['anatomy']['elements']['.layout__aside']['contains'],
    );

    // Layout examples per depth: the depth-conditioned root forms make
    // the shapes differ (a real <section> page band at root depth, a div
    // nested); the unclaimed regions bucket mounts children directly in
    // the one-box root, beside the named slot part.
    $layout_anatomy = $context['layouts']['daedalus_stylish_twocol']['anatomy'];
    $this->assertSame(implode("\n", [
      '<section data-key="container:UUID" data-type="daedalus_stylish_twocol">',
      '  <aside class="layout__aside"></aside>',
      '  <!-- child blocks and nested sections mount here -->',
      '</section>',
    ]), $layout_anatomy['example']);
    $this->assertSame(implode("\n", [
      '<div data-key="container:UUID" data-type="daedalus_stylish_twocol">',
      '  <aside class="layout__aside"></aside>',
      '  <!-- child blocks and nested sections mount here -->',
      '</div>',
    ]), $layout_anatomy['example_nested']);

    // Plugin blocks have no catalog row to carry their fragment, so their
    // effective anatomy rides the top-level fragment keyed by base plugin
    // id, and the notes orient the reader to it.
    $this->assertStringContainsString('anatomy.block_plugins', $context['anatomy']['guidance']);
    $menu = $context['anatomy']['block_plugins']['system_menu_block'];
    $this->assertSame('Inner', $menu['elements']['.block__inner']['label']);
    $this->assertSame('Menu link', $menu['elements']['.menu']['elements']['.menu-item']['label']);

    // The fragment derives from the layered declarations: the context and
    // schema caches must rebuild when the declaration spine does.
    $this->assertContains('daedalus_ui_page_builder', $context['cache_tags']);
  }

}
