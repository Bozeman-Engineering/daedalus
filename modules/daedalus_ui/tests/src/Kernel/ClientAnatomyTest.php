<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_ui\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_ui\ElementModel\ClientAnatomy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the anatomy client payload projection.
 */
#[Group('daedalus_ui')]
#[RunTestsInSeparateProcesses]
class ClientAnatomyTest extends KernelTestBase {

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
   * The service under test.
   */
  protected ClientAnatomy $clientAnatomy;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install([self::THEME]);
    // The payload resolves the site default theme (one resolution with the
    // AI schema fragment), so the fixture theme is made the default.
    $this->config('system.theme')->set('default', self::THEME)->save();
    $this->clientAnatomy = $this->container->get('daedalus_ui.client_anatomy');
  }

  /**
   * The payload carries the markup model as authored, foreign keys stripped.
   */
  public function testAnatomyProjection(): void {
    $payload = $this->clientAnatomy->build();
    $this->assertSame(['kinds', 'blockTypes', 'blockPlugins', 'layouts', 'instances'], array_keys($payload));

    // The instance namespaces serve label + tags (the declared vocabulary
    // markup-verify derives its prefix terms from); foreign sections are
    // stripped like everywhere else, and a theme-withdrawn namespace is
    // absent.
    $this->assertSame(
      ['label' => 'Module instance', 'tags' => ['p' => 'Extra paragraph', 'h2' => 'Heading 2']],
      $payload['instances']['.in-*'],
    );
    $this->assertArrayNotHasKey('.tx-*', $payload['instances']);

    // The shipped field shell rides through with its labels and slots; a
    // foreign key inside a part (the fixture's annotations) is stripped —
    // the payload is markup-model vocabulary only.
    $label = $payload['kinds']['field']['elements']['.field__label'];
    $this->assertSame('Theme label', $label['label']);
    $this->assertArrayNotHasKey('annotations', $label);

    // A kind that declares no markup model contributes nothing: existence
    // and presentation reach the client as _element annotations, never
    // here.
    $this->assertArrayNotHasKey('entity', $payload['kinds']);
    $this->assertArrayNotHasKey('fields', $payload['kinds']['block'] ?? []);
    $this->assertArrayNotHasKey('presentation', $payload['kinds']['block'] ?? []);

    // Block types are EFFECTIVE (the universal block kind underneath), with
    // nested parts and contains slots as authored, part tags riding along,
    // and the foreign styling sections (default_variants, property_groups)
    // stripped.
    $card = $payload['blockTypes']['card'];
    // The theme layer flips the card composed, and the flag rides through.
    $this->assertSame(['composed', 'elements'], array_keys($card));
    $this->assertSame('Inner', $card['elements']['.block__inner']['label']);
    $this->assertSame(['field:field_title'], $card['elements']['.card']['contains']);
    $this->assertSame('div', $card['elements']['.card']['tag']);
    $this->assertSame('field:field_body', $card['elements']['.card']['elements']['.card__body']['contains']);

    // The full markup model rides AS AUTHORED — the element resolver's
    // client twin consumes this payload as its declaration input, so the
    // ordered root forms, a part's data-decided present condition, and its
    // declared attributes must all survive the projection byte-for-byte.
    $banner = $payload['blockTypes']['banner'];
    $this->assertSame([
      'forms' => [
        [
          'tag' => 'a',
          'present' => ['field' => 'field_banner_url'],
          'attributes' => ['href' => ['field' => 'field_banner_url']],
        ],
        ['tag' => 'div'],
      ],
    ], $banner['root']);
    $media = $banner['elements']['.banner__media'];
    $this->assertSame('img', $media['tag']);
    $this->assertSame(['media' => 'raster'], $media['present']);
    $this->assertSame(['role' => 'presentation'], $media['attributes']);

    // A multi-form part ships its ordered forms as authored, per-form
    // labels included: the twin resolves the matched form exactly as the
    // PHP resolver does, and the matched-form label is the client's only
    // way to name the form (compose degrades to the part-level label when
    // the model does not speak).
    $this->assertSame([
      'label' => 'Banner picture',
      'forms' => [
        ['tag' => 'img', 'present' => ['media' => 'raster'], 'label' => 'Raster'],
        ['tag' => 'svg', 'present' => ['media' => 'vector'], 'label' => 'Vector'],
      ],
    ], $banner['elements']['.banner__picture']);

    // Block plugins are effective the same way against the block kind,
    // keyed by base plugin id, foreign sections stripped.
    $menu = $payload['blockPlugins']['system_menu_block'];
    $this->assertSame(['elements'], array_keys($menu));
    $this->assertSame('Inner', $menu['elements']['.block__inner']['label']);
    $this->assertSame('Menu link', $menu['elements']['.menu']['elements']['.menu-item']['label']);

    // Layouts are effective the same way against the section kind, their
    // multi-form root (the depth-conditioned section/div swap) riding as
    // authored: the resolver twin consumes the whole model.
    $layout = $payload['layouts']['daedalus_stylish_twocol'];
    $this->assertSame([
      'forms' => [
        ['tag' => 'section', 'present' => ['depth' => 'root']],
        ['tag' => 'div'],
      ],
    ], $layout['root']);
    $aside = $layout['elements']['.layout__aside'];
    $this->assertSame('region:aside', $aside['contains']);
    $this->assertSame('aside', $aside['tag']);
    $this->assertArrayNotHasKey('present', $aside);
    $this->assertSame('Section inner', $layout['elements']['.section__inner']['label']);
  }

}
