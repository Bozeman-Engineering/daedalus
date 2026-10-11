<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\daedalus_layout\Controller\BlueprintSaveDialog;
use Drupal\Tests\media\Traits\MediaTypeCreationTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Kernel tests for the save-as-blueprint, update, and edit-details dialogs.
 *
 * The dialogs are inert markup the client JS drives (the Change layout
 * chooser pattern): the ids and data attributes asserted here are the
 * client/server contract js/verbs/blueprint.js and
 * js/src/blueprint-gestures.js read values and click through, and the
 * routes' permission is the same gate the gestures enforce for real. The
 * details dialog's scope, subject, allow-list, groups and favorite
 * sections need a resolved blueprint, so they are proven beside the
 * library's fixtures (BlueprintOwnedVariantTest); here they are absent by
 * contract.
 */
#[CoversClass(BlueprintSaveDialog::class)]
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class BlueprintSaveDialogTest extends KernelTestBase {

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
    // The routes' gate is the library's any-type access check: the
    // blueprint types it reads are the library's install config.
    'options',
    'daedalus_blueprint_library',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('blueprint');
    $this->installConfig(['daedalus_blueprint_library']);
  }

  /**
   * Tests the dialog route's contract: path and permission gate.
   */
  public function testRouteContract(): void {
    $route = $this->container->get('router.route_provider')
      ->getRouteByName('daedalus_layout.blueprint_save_dialog');
    $this->assertSame('/daedalus-layout/blueprint-save-dialog', $route->getPath());
    $this->assertSame('create', $route->getRequirement('_blueprint_type_access'));
    $this->assertNull($route->getRequirement('_permission'));
  }

  /**
   * Tests the dialog markup contract the blueprint verb JS drives.
   */
  public function testDialogBuild(): void {
    $build = BlueprintSaveDialog::create($this->container)->build(new Request());

    $this->assertSame('daedalus-layout-blueprint-label', $build['label_field']['input']['#attributes']['id']);
    // Without daedalus_stylish (and thus without the ownership field) the adoption
    // section never renders; the dialog is the plain two-field contract.
    $this->assertArrayNotHasKey('adopt', $build);
    // Without host coordinates on the query the icon picker cannot name an
    // access subject, so it degrades away too.
    $this->assertArrayNotHasKey('icon_field', $build);
    $this->assertSame('daedalus-layout-blueprint-description', $build['description_field']['input']['#attributes']['id']);
    // No in-dialog message area: a refused save reports through the one
    // edit-mode message display, and the precheck is reportValidity() on
    // the inputs, which needs them marked required.
    $this->assertArrayNotHasKey('message', $build);
    $this->assertSame('required', $build['label_field']['input']['#attributes']['required']);
    $this->assertSame('required', $build['description_field']['input']['#attributes']['required']);
    $this->assertArrayHasKey('data-blueprint-save', $build['actions']['save']['#attributes']);
    $this->assertArrayHasKey('data-blueprint-cancel', $build['actions']['cancel']['#attributes']);

    // The dialog renders without the theme registry choking on it and the
    // textarea stays a properly closed element (a void #value would render
    // <textarea/> and swallow the following markup).
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('id="daedalus-layout-blueprint-label"', $html);
    $this->assertStringContainsString('</textarea>', $html);
    $this->assertStringContainsString('data-blueprint-save', $html);
  }

  /**
   * Tests the page capture's dialog: the same dialog, worded for a page.
   *
   * "Save page as blueprint…" opens the save dialog with the host's own
   * uuid as the element, the spelling the spine reads as the whole
   * document (BlueprintManager::capture()); the dialog words its intro for
   * a page-scope capture and states the scope read-only, while any other
   * element keeps the container wording and the block scope. The commit
   * contract is unchanged: one listener, one op.
   */
  public function testPageDialogBuild(): void {
    $this->installEntitySchema('user');
    // The first saved user is uid 1; the host is an ordinary entity.
    User::create(['name' => 'first'])->save();
    $host = User::create(['name' => 'composer']);
    $host->save();
    $query = [
      'entity_type' => 'user',
      'entity_id' => (string) $host->id(),
    ];

    $page = BlueprintSaveDialog::create($this->container)->build(new Request($query + ['element' => $host->uuid()]));
    $this->assertStringContainsString('whole page', (string) $page['intro']['#value']);
    $this->assertStringContainsString('not offered in the Add panel', (string) $page['intro']['#value']);
    $this->assertSame('page', $page['scope_field']['value']['#attributes']['data-blueprint-scope']);
    $this->assertStringContainsString("a whole page's document", (string) $page['scope_field']['value']['#value']);
    // The same fields and the same commit: the client's one save listener
    // submits save_material with the root's uuid as the element.
    $this->assertSame('daedalus-layout-blueprint-label', $page['label_field']['input']['#attributes']['id']);
    $this->assertSame('daedalus-layout-blueprint-description', $page['description_field']['input']['#attributes']['id']);
    // The name's placeholder says where a page blueprint is found: not the
    // Add panel, which never offers page scope.
    $this->assertStringContainsString('page blueprints', (string) $page['label_field']['input']['#attributes']['placeholder']);
    $this->assertArrayHasKey('data-blueprint-save', $page['actions']['save']['#attributes']);
    $this->assertArrayHasKey('data-blueprint-cancel', $page['actions']['cancel']['#attributes']);
    $html = (string) $this->container->get('renderer')->renderInIsolation($page);
    $this->assertStringContainsString('data-blueprint-scope="page"', $html);

    // A container's uuid on the same host: the block wording.
    $block = BlueprintSaveDialog::create($this->container)->build(new Request($query + ['element' => $this->container->get('uuid')->generate()]));
    $this->assertStringContainsString('Save this container', (string) $block['intro']['#value']);
    $this->assertSame('block', $block['scope_field']['value']['#attributes']['data-blueprint-scope']);
    // No coordinates at all: nothing can be the host's uuid, block scope.
    $bare = BlueprintSaveDialog::create($this->container)->build(new Request(['element' => $host->uuid()]));
    $this->assertSame('block', $bare['scope_field']['value']['#attributes']['data-blueprint-scope']);
  }

  /**
   * Tests the save dialog route refuses an actor who may not save blueprints.
   *
   * The route's gate is the any-type form of what the capture op enforces
   * for real (the create permission of SOME blueprint type; the op judges
   * the type the capture names); the page verb and the container verb
   * share the route, so one refusal covers both doors. The accounts are
   * doubles answering the one question the access check asks, so the
   * refusal is proven against the route as routed, not against a
   * requirement string.
   */
  public function testSaveDialogRefusedWithoutTheGrant(): void {
    $access_manager = $this->container->get('access_manager');
    $route_name = 'daedalus_layout.blueprint_save_dialog';

    $editor = $this->createMock(AccountInterface::class);
    $editor->method('hasPermission')->willReturnCallback(static fn (string $permission): bool => $permission === 'create section blueprint');
    $this->assertTrue($access_manager->checkNamedRoute($route_name, [], $editor), 'An actor with the grant reaches the dialog.');

    $visitor = $this->createMock(AccountInterface::class);
    $visitor->method('hasPermission')->willReturn(FALSE);
    $this->assertFalse($access_manager->checkNamedRoute($route_name, [], $visitor), 'An actor without the grant is refused the dialog.');
  }

  /**
   * Tests the update dialog route's contract: path and permission gate.
   */
  public function testUpdateRouteContract(): void {
    $route = $this->container->get('router.route_provider')
      ->getRouteByName('daedalus_layout.blueprint_update_dialog');
    $this->assertSame('/daedalus-layout/blueprint-update-dialog', $route->getPath());
    $this->assertSame('update', $route->getRequirement('_blueprint_type_access'));
  }

  /**
   * Tests the update dialog markup contract the blueprint verb JS drives.
   */
  public function testUpdateDialogBuild(): void {
    $build = BlueprintSaveDialog::create($this->container)->update(new Request(['blueprint' => 'missing']));

    // The push gesture's confirm step collects no label/description (the
    // Add panel's details dialog owns those); without the library module
    // the reference cannot resolve and the confirm copy just doesn't name
    // it.
    $this->assertArrayNotHasKey('label_field', $build);
    $this->assertArrayNotHasKey('description_field', $build);
    $this->assertStringContainsString('Update this blueprint', (string) $build['intro']['#value']);
    // Without daedalus_stylish the adoption section never renders here
    // either, and an unresolvable blueprint reference means no owned
    // roster.
    $this->assertArrayNotHasKey('adopt', $build);
    $this->assertArrayNotHasKey('owned', $build);
    $this->assertArrayNotHasKey('message', $build);
    // Its own commit attribute: the save listener must never cross-fire.
    $this->assertArrayHasKey('data-blueprint-update', $build['actions']['save']['#attributes']);
    $this->assertArrayNotHasKey('data-blueprint-save', $build['actions']['save']['#attributes']);
    $this->assertArrayHasKey('data-blueprint-cancel', $build['actions']['cancel']['#attributes']);
    // No bespoke stylesheet: the dialogs speak core's shared form
    // vocabulary and the site's one dialog skin styles it.
    $this->assertArrayNotHasKey('#attached', $build);

    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('data-blueprint-update', $html);
    $this->assertStringContainsString('data-blueprint-cancel', $html);
  }

  /**
   * Tests the details dialog route's contract: path and permission gate.
   */
  public function testDetailsRouteContract(): void {
    $route = $this->container->get('router.route_provider')
      ->getRouteByName('daedalus_layout.blueprint_details_dialog');
    $this->assertSame('/daedalus-layout/blueprint-details-dialog', $route->getPath());
    $this->assertSame('update', $route->getRequirement('_blueprint_type_access'));
  }

  /**
   * Tests the details dialog markup contract the Add panel menu JS drives.
   */
  public function testDetailsDialogBuild(): void {
    $build = BlueprintSaveDialog::create($this->container)->details(new Request());

    // The prefill guard class distinguishes this dialog from the save-as
    // one, which shares the field ids but must open empty.
    $this->assertContains('daedalus-layout-blueprint-details', $build['#attributes']['class']);
    // Without the library module the query's blueprint reference cannot
    // resolve, so no icon picker renders and neither do the scope, subject,
    // allow-list, groups and favorite sections; the client then omits
    // their keys and the stored values stand (the details route's
    // presence semantics).
    $this->assertArrayNotHasKey('icon_field', $build);
    $this->assertArrayNotHasKey('scope_field', $build);
    $this->assertArrayNotHasKey('subject_field', $build);
    $this->assertArrayNotHasKey('bundles_field', $build);
    $this->assertArrayNotHasKey('groups_field', $build);
    $this->assertArrayNotHasKey('favorite_field', $build);
    $this->assertSame('daedalus-layout-blueprint-label', $build['label_field']['input']['#attributes']['id']);
    $this->assertSame('daedalus-layout-blueprint-description', $build['description_field']['input']['#attributes']['id']);
    $this->assertArrayNotHasKey('message', $build);
    $this->assertSame('required', $build['label_field']['input']['#attributes']['required']);
    $this->assertSame('required', $build['description_field']['input']['#attributes']['required']);
    // Its own data attributes: the save-as-blueprint listener must never
    // cross-fire on the details dialog's buttons.
    $this->assertArrayHasKey('data-blueprint-details-save', $build['actions']['save']['#attributes']);
    $this->assertArrayHasKey('data-blueprint-details-cancel', $build['actions']['cancel']['#attributes']);
    $this->assertArrayNotHasKey('data-blueprint-save', $build['actions']['save']['#attributes']);

    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('id="daedalus-layout-blueprint-label"', $html);
    $this->assertStringContainsString('</textarea>', $html);
    $this->assertStringContainsString('data-blueprint-details-save', $html);
  }

  /**
   * Tests the save dialog's icon picker contract when the lane is live.
   *
   * With media_library present, a visual media type installed, and host
   * coordinates on the query, the save dialog renders the same picker
   * markup contract as the daedalus_layout_icon_picker form element: the keyed
   * wrapper icon-picker.js drives, the hidden value input the opener's
   * selection response targets (empty: a blueprint being born has no icon),
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

    $build = BlueprintSaveDialog::create($this->container)->build(new Request($query));

    $picker = $build['icon_field']['picker'];
    $this->assertSame('blueprint-save-icon', $picker['#attributes']['data-daedalus-layout-icon-picker']);
    $this->assertNotContains('has-icon', $picker['#attributes']['class']);
    $this->assertSame('', $picker['value']['#attributes']['value']);
    $this->assertSame('blueprint-save-icon', $picker['value']['#attributes']['data-daedalus-layout-icon-picker-value']);
    $this->assertContains('use-ajax', $picker['open']['#attributes']['class']);
    $this->assertStringContainsString('media_library_opener_id=daedalus_layout.icon_picker.opener', urldecode($picker['open']['#attributes']['href']));

    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringContainsString('data-daedalus-layout-icon-picker-value="blueprint-save-icon"', $html);

    $update = BlueprintSaveDialog::create($this->container)->update(new Request($query + ['blueprint' => 'anything']));
    $this->assertArrayNotHasKey('icon_field', $update);
  }

}
