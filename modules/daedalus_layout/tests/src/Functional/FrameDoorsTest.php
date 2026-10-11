<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Functional;

use Drupal\daedalus_blueprint\Entity\Frame;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The site frame's four doors from the admin UI.
 *
 * A frame (one per theme, keyed by the theme's machine name) has no page
 * of its own: its canonical link is its canvas, a front-end route nothing
 * in the admin UI would otherwise name, and a framed page offers a door
 * only through a portal chip on a frame-owned node that happens to render.
 * So daedalus_layout puts a door on every admin page where a site builder
 * looks for a theme's own things (FrameDoors) and in the administration
 * menu under Appearance (FrameMenuLink):
 *
 * - the theme list: a "Site frame" operation on the framed theme's row;
 * - the theme settings form: an item at the top with the link;
 * - block layout: a warning that the theme renders its site frame instead
 *   of block layout (FramePageVariant replaces the block module's page
 *   variant, so a block placed there never renders), with the link;
 * - the Appearance menu: "Site frame: @theme", rendered here by core's
 *   Navigation toolbar, the toolbar a site runs.
 *
 * Every door is a server-rendered link or message, so this is the cheapest
 * tier that sees a door go missing. Each shows only for a theme that has a
 * frame and only when the actor may update it (`configure the site
 * frame`); the block layout warning stays for an actor who may not, with
 * the plain sentence and no link, because the page would lie without it.
 * Stark carries the frame; claro, installed beside it, has none and gets
 * nothing. The frame is empty: the doors depend on the frame existing,
 * never on what it renders, which is the point of giving it doors.
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class FrameDoorsTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'navigation',
    'daedalus_layout',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The framed theme: the default theme, whose frame is empty.
   */
  private const FRAMED = 'stark';

  /**
   * The theme without a frame, installed beside the framed one.
   */
  private const UNFRAMED = 'claro';

  /**
   * The canvas href every door must carry: the frame's canonical link.
   *
   * Read from the entity after it saves, so the href carries the base path
   * the site is served under (drupal.org's runner serves it under /web/).
   */
  private string $canvas;

  /**
   * The block layout warning, as stark renders a status_messages element.
   *
   * Core's base template carries no messages--warning class (the admin
   * themes add it); the role and the data attribute are the template's own,
   * and the form scope keeps the page's messages region out.
   */
  private const BLOCK_WARNING = '#block-admin-display-form [data-drupal-messages] [role="contentinfo"]';

  /**
   * The sentence the block layout warning carries, after the theme's name.
   */
  private const WARNING = 'renders its site frame instead of block layout. Blocks placed here do not appear on its pages.';

  /**
   * The permissions a site builder holds beside the frame's own.
   *
   * The three admin pages ask the first two; the Navigation toolbar renders
   * only for an actor with the last two.
   *
   * @var string[]
   */
  private const ADMIN_PERMISSIONS = [
    'administer themes',
    'administer blocks',
    'access administration pages',
    'access navigation',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('theme_installer')->install([self::UNFRAMED]);
    $frame = Frame::create(['id' => self::FRAMED]);
    $frame->save();
    $this->canvas = $frame->toUrl()->toString();
  }

  /**
   * Every door for the site builder; none without the permission or a frame.
   */
  public function testDoors(): void {
    $assert = $this->assertSession();

    // The site builder: every door carries the canvas URL.
    $this->drupalLogin($this->drupalCreateUser([...self::ADMIN_PERMISSIONS, 'configure the site frame']));

    // The theme list: the operation on the framed theme's row (the default
    // theme's), and no site frame link on any other row.
    $this->drupalGet('admin/appearance');
    $assert->statusCodeEquals(200);
    $assert->elementsCount('css', '.theme-selector.theme-default a[href="' . $this->canvas . '"]', 1);
    $assert->elementTextEquals('css', '.theme-selector.theme-default a[href="' . $this->canvas . '"]', 'Site frame');
    $assert->elementAttributeContains('css', '.theme-selector.theme-default a[href="' . $this->canvas . '"]', 'title', 'Edit the site frame of Stark');
    $assert->elementNotExists('css', '.theme-selector:not(.theme-default) a[href^="/site-frame/"]');

    // The Appearance menu, as the Navigation toolbar renders it on every
    // admin page: one child titled after the theme.
    $assert->elementsCount('css', '.toolbar-menu__item--level-1 a[href="' . $this->canvas . '"]', 1);
    $assert->elementTextEquals('css', '.toolbar-menu__item--level-1 a[href="' . $this->canvas . '"]', 'Site frame: Stark');

    // The theme settings form of the framed theme: the item with the link.
    $this->drupalGet('admin/appearance/settings/' . self::FRAMED);
    $assert->statusCodeEquals(200);
    $assert->elementsCount('css', '#system-theme-settings a[href="' . $this->canvas . '"]', 1);
    $assert->elementTextEquals('css', '#system-theme-settings a[href="' . $this->canvas . '"]', 'Edit the site frame');
    $assert->elementTextContains('css', '#system-theme-settings', 'The header, footer and page chrome of Stark are edited in its site frame');

    // The theme settings form of the unframed theme: nothing.
    $this->drupalGet('admin/appearance/settings/' . self::UNFRAMED);
    $assert->statusCodeEquals(200);
    $assert->elementNotExists('css', '#system-theme-settings a[href^="/site-frame/"]');
    $assert->elementTextNotContains('css', '#system-theme-settings', 'site frame');

    // Block layout of the framed theme, by the theme route parameter and by
    // the default theme's route: the warning, with the link.
    foreach (['admin/structure/block/list/' . self::FRAMED, 'admin/structure/block'] as $path) {
      $this->drupalGet($path);
      $assert->statusCodeEquals(200);
      $assert->elementTextContains('css', self::BLOCK_WARNING, 'Stark ' . self::WARNING);
      $assert->elementsCount('css', self::BLOCK_WARNING . ' a[href="' . $this->canvas . '"]', 1);
      $assert->elementTextEquals('css', self::BLOCK_WARNING . ' a[href="' . $this->canvas . '"]', 'Edit the site frame');
    }

    // Block layout of the unframed theme: no warning, no link.
    $this->drupalGet('admin/structure/block/list/' . self::UNFRAMED);
    $assert->statusCodeEquals(200);
    $assert->elementNotExists('css', self::BLOCK_WARNING);
    $assert->elementNotExists('css', '#block-admin-display-form a[href^="/site-frame/"]');

    // The same actor without the frame's permission: no door anywhere, and
    // block layout keeps the plain sentence without the link.
    $this->drupalLogin($this->drupalCreateUser(self::ADMIN_PERMISSIONS));
    $this->drupalGet('admin/appearance');
    $assert->statusCodeEquals(200);
    $assert->elementNotExists('css', 'a[href^="/site-frame/"]');
    $assert->pageTextNotContains('Site frame');
    $this->drupalGet('admin/appearance/settings/' . self::FRAMED);
    $assert->statusCodeEquals(200);
    $assert->elementNotExists('css', 'a[href^="/site-frame/"]');
    $assert->elementTextNotContains('css', '#system-theme-settings', 'site frame');
    $this->drupalGet('admin/structure/block/list/' . self::FRAMED);
    $assert->statusCodeEquals(200);
    $assert->elementTextContains('css', self::BLOCK_WARNING, 'Stark ' . self::WARNING);
    $assert->elementNotExists('css', 'a[href^="/site-frame/"]');
    $assert->elementTextNotContains('css', self::BLOCK_WARNING, 'Edit the site frame');
  }

}
