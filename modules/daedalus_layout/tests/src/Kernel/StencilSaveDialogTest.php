<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_layout\Controller\StencilSaveDialog;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for the save-as-stencil, update, and edit-details dialogs.
 *
 * The dialogs are inert markup the client JS drives (the Change layout
 * chooser pattern): the ids and data attributes asserted here are the
 * client/server contract js/verbs/stencil.js and
 * js/add-panel/stencil-items.js read values and click through, and the
 * routes' permission is the same "save stencils" gate the gestures
 * enforce for real.
 */
#[CoversClass(StencilSaveDialog::class)]
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class StencilSaveDialogTest extends KernelTestBase {

  use MediaTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'block_content',
    'layout_discovery',
    'layout_builder',
    'field',
    'text',
    'filter',
    'daedalus_tempstore',
    // daedalus_ui hard-depends on daedalus_blueprint_api (its services are
    // autowired into daedalus_ui's container); kernel tests do not
    // resolve info.yml dependencies, so the chain is listed explicitly.
    'daedalus_blueprint',
    'daedalus_blueprint_api',
    'daedalus_ui',
    'daedalus_layout',
    // The media stack registers the icon picker opener
    // (DaedalusLayoutServiceProvider is media_library-conditional); the dialogs
    // still degrade to their two-field contract until a test supplies a
    // host and a visual media type.
    'file',
    'image',
    'media',
    'views',
    'media_library',
  ];

  /**
   * Tests the dialog route's contract: path and permission gate.
   */
  public function testRouteContract(): void {
    $route = $this->container->get('router.route_provider')
      ->getRouteByName('daedalus_layout.stencil_save_dialog');
    $this->assertSame('/daedalus-layout/stencil-save-dialog', $route->getPath());
    $this->assertSame('save stencils', $route->getRequirement('_permission'));
  }

  /**
   * Tests the dialog markup contract the stencil verb JS drives.
   */
  public function testDialogBuild(): void {
    $build = StencilSaveDialog::create($this->container)->build(new Request());

    $this->assertSame('daedalus-layout-stencil-label', $build['label_field']['input']['#attributes']['id']);
    // Without daedalus_stylish (and thus without the ownership field) the adoption
    // section never renders; the dialog is the plain two-field contract.
    $this->assertArrayNotHasKey('adopt', $build);
    // Without host coordinates on the query the icon picker cannot name an
    // access subject, so it degrades away too.
    $this->assertArrayNotHasKey('icon_field', $build);
    $this->assertSame('daedalus-layout-stencil-description', $build['description_field']['input']['#attributes']['id']);
    // No in-dialog message area: a refused save reports through the one
    // edit-mode message display, and the precheck is reportValidity() on
    // the inputs, which needs them marked required.
    $this->assertArrayNotHasKey('message', $build);
    $this->assertSame('required', $build['label_field']['input']['#attributes']['required']);
    $this->assertSame('required', $build['description_field']['input']['#attributes']['required']);
    $this->assertArrayHasKey('data-stencil-save', $build['actions']['save']['#attributes']);
    $this->assertArrayHasKey('data-stencil-cancel', $build['actions']['cancel']['#attributes']);

    // The dialog renders without the theme registry choking on it and the
    // textarea stays a properly closed element (a void #value would render
    // <textarea/> and swallow the following markup).
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('id="daedalus-layout-stencil-label"', $html);
    $this->assertStringContainsString('</textarea>', $html);
    $this->assertStringContainsString('data-stencil-save', $html);
  }

  /**
   * Tests the update dialog route's contract: path and permission gate.
   */
  public function testUpdateRouteContract(): void {
    $route = $this->container->get('router.route_provider')
      ->getRouteByName('daedalus_layout.stencil_update_dialog');
    $this->assertSame('/daedalus-layout/stencil-update-dialog', $route->getPath());
    $this->assertSame('save stencils', $route->getRequirement('_permission'));
  }

  /**
   * Tests the update dialog markup contract the stencil verb JS drives.
   */
  public function testUpdateDialogBuild(): void {
    $build = StencilSaveDialog::create($this->container)->update(new Request(['stencil' => 'missing']));

    // The push gesture's confirm step collects no label/description (the
    // Add panel's details dialog owns those); without the stencil module the
    // reference cannot resolve and the confirm copy just doesn't name it.
    $this->assertArrayNotHasKey('label_field', $build);
    $this->assertArrayNotHasKey('description_field', $build);
    $this->assertStringContainsString('Update this stencil', (string) $build['intro']['#value']);
    // Without daedalus_stylish the adoption section never renders here either, and
    // an unresolvable stencil reference means no owned roster.
    $this->assertArrayNotHasKey('adopt', $build);
    $this->assertArrayNotHasKey('owned', $build);
    $this->assertArrayNotHasKey('message', $build);
    // Its own commit attribute: the save listener must never cross-fire.
    $this->assertArrayHasKey('data-stencil-update', $build['actions']['save']['#attributes']);
    $this->assertArrayNotHasKey('data-stencil-save', $build['actions']['save']['#attributes']);
    $this->assertArrayHasKey('data-stencil-cancel', $build['actions']['cancel']['#attributes']);
    // No bespoke stylesheet: the dialogs speak core's shared form
    // vocabulary and the site's one dialog skin styles it.
    $this->assertArrayNotHasKey('#attached', $build);

    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('data-stencil-update', $html);
    $this->assertStringContainsString('data-stencil-cancel', $html);
  }

  /**
   * Tests the details dialog route's contract: path and permission gate.
   */
  public function testDetailsRouteContract(): void {
    $route = $this->container->get('router.route_provider')
      ->getRouteByName('daedalus_layout.stencil_details_dialog');
    $this->assertSame('/daedalus-layout/stencil-details-dialog', $route->getPath());
    $this->assertSame('save stencils', $route->getRequirement('_permission'));
  }

  /**
   * Tests the details dialog markup contract the Add panel menu JS drives.
   */
  public function testDetailsDialogBuild(): void {
    $build = StencilSaveDialog::create($this->container)->details(new Request());

    // The prefill guard class distinguishes this dialog from the save-as
    // one, which shares the field ids but must open empty.
    $this->assertContains('daedalus-layout-stencil-details', $build['#attributes']['class']);
    // Without the stencil module the query's stencil reference cannot
    // resolve, so no icon picker renders; the client then omits the icon
    // key and the stored icon stands (the details route's presence
    // semantics).
    $this->assertArrayNotHasKey('icon_field', $build);
    $this->assertSame('daedalus-layout-stencil-label', $build['label_field']['input']['#attributes']['id']);
    $this->assertSame('daedalus-layout-stencil-description', $build['description_field']['input']['#attributes']['id']);
    $this->assertArrayNotHasKey('message', $build);
    $this->assertSame('required', $build['label_field']['input']['#attributes']['required']);
    $this->assertSame('required', $build['description_field']['input']['#attributes']['required']);
    // Its own data attributes: the save-as-stencil listener must never
    // cross-fire on the details dialog's buttons.
    $this->assertArrayHasKey('data-stencil-details-save', $build['actions']['save']['#attributes']);
    $this->assertArrayHasKey('data-stencil-details-cancel', $build['actions']['cancel']['#attributes']);
    $this->assertArrayNotHasKey('data-stencil-save', $build['actions']['save']['#attributes']);

    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('id="daedalus-layout-stencil-label"', $html);
    $this->assertStringContainsString('</textarea>', $html);
    $this->assertStringContainsString('data-stencil-details-save', $html);
  }

  /**
   * Tests the save dialog's icon picker contract when the lane is live.
   *
   * With media_library present, a visual media type installed, and host
   * coordinates on the query, the save dialog renders the same picker
   * markup contract as the daedalus_layout_icon_picker form element: the keyed
   * wrapper icon-picker.js drives, the hidden value input the opener's
   * selection response targets (empty — a stencil being born has no icon),
   * and the use-ajax media library link. The update dialog stays
   * picker-free even then: the icon is the palette face's, edited in the
   * Add panel's Edit details… dialog.
   */
  public function testSaveDialogIconPicker(): void {
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('media');
    $this->installConfig(['media']);
    $this->createMediaType('image', ['id' => 'image']);
    $host = User::create(['name' => 'composer']);
    $host->save();
    $query = [
      'entity_type' => 'user',
      'entity_id' => (string) $host->id(),
    ];

    $build = StencilSaveDialog::create($this->container)->build(new Request($query));

    $picker = $build['icon_field']['picker'];
    $this->assertSame('stencil-save-icon', $picker['#attributes']['data-daedalus-layout-icon-picker']);
    $this->assertNotContains('has-icon', $picker['#attributes']['class']);
    $this->assertSame('', $picker['value']['#attributes']['value']);
    $this->assertSame('stencil-save-icon', $picker['value']['#attributes']['data-daedalus-layout-icon-picker-value']);
    $this->assertContains('use-ajax', $picker['open']['#attributes']['class']);
    $this->assertStringContainsString('media_library_opener_id=daedalus_layout.icon_picker.opener', urldecode($picker['open']['#attributes']['href']));

    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('data-daedalus-layout-icon-picker-value="stencil-save-icon"', $html);

    $update = StencilSaveDialog::create($this->container)->update(new Request($query + ['stencil' => 'anything']));
    $this->assertArrayNotHasKey('icon_field', $update);
  }

}
