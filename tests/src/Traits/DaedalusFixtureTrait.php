<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus\Traits;

use Drupal\FunctionalTests\Core\Recipe\RecipeTestTrait;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;

/**
 * The Daedalus fixture for browser tests: the recipe's product, as its user.
 *
 * Every page-builder behavior test runs against the same site: the one the
 * daedalus recipe installs. This trait is that fixture's ONE copy, so a
 * recipe change is absorbed here rather than in every module's test. It is
 * meant for a WebDriverTestBase and does three things:
 *
 * - Installs Drupal FROM the recipe, with no install profile. This is the
 *   exact contract `drush si recipes/daedalus_recipe` gives a new site: core
 *   replaces the profile install tasks with recipe application, so the site
 *   under test is built from the recipe alone (not standard-plus-recipe,
 *   which carries content types, formats and roles no Daedalus site has).
 *   The recipe's own config passes the strict config schema checker, so the
 *   checker stays ON.
 * - Installs daedalus_edit_test_fixture (daedalus_edit/tests/modules) on top. The
 *   recipe ships product only; the entity-form editing path's fixtures are
 *   test-only bundles derived from the machinery inventory: the 'plain'
 *   non-Layout-Builder node bundle, the 'target' reference bundle (which
 *   cannot self-reference), and the 'form_block' form-edited block_content
 *   type. Because the fixture's bundles are created AFTER the recipe's
 *   daedalus_editor_roles ran, the trait grants the plain bundle's
 *   permissions onto the editor role itself (the consumer contract:
 *   per-bundle permission strings do not exist until the bundle does).
 * - Logs in as the editor persona (loginAsEditor()): a user whose ONLY role
 *   is daedalus_editor, the permission contract the recipe ships. Under
 *   this fixture the site IS the recipe's product, so that role is its only
 *   honest user. Every step a test runs as this user doubles as a
 *   sufficiency proof of the role: a route it cannot reach, a verb hidden by
 *   a missing permission, a tempstore it cannot write, are browser-only
 *   failures that admin would pass silently through. A test that goes red
 *   because the role lacks a grant has found a recipe bug; the fix lands in
 *   daedalus_editor_roles. Admin is for an admin-only subject, by explicit
 *   exception, with a docblock saying why.
 *
 * setUp() assigns $profile = '' and $defaultTheme = 'daedalus_theme' before
 * installing. PHP refuses a trait property whose default differs from the one
 * the base class declares, but both are only read at install time
 * (installParameters() and installDefaultThemeFromClassProperty(), inside
 * installDrupal()), so an assignment ahead of parent::setUp() is equivalent to
 * the class declaring them. The empty profile is what selects the no-profile
 * route:
 * install_begin_request() normalizes '' to FALSE, the supported "site has no
 * install profile" state (install.core.inc). The theme must be explicit: on
 * the no-profile route the harness's $defaultTheme wins over the recipe's
 * system.theme action, and daedalus_theme is the one whose shipped
 * header/footer block placements exist (placements are per-theme).
 *
 * A class that needs its own setUp() aliases this one and calls it:
 * @code
 * use DaedalusFixtureTrait { setUp as daedalusSetUp; }
 * @endcode
 */
trait DaedalusFixtureTrait {

  use RecipeTestTrait;

  /**
   * The daedalus recipe directory.
   *
   * The recipe is a require-dev dependency of every module that uses this
   * fixture, so Composer installs it as a sibling of the Drupal root:
   * <project>/recipes/daedalus_recipe. Its recipe.yml includes resolve from web/
   * to that same recipes/ directory, so applying it here is the exact
   * composition a real site runs.
   */
  protected function daedalusRecipePath(): string {
    return dirname($this->root) . '/recipes/daedalus_recipe';
  }

  /**
   * The recipe the site is installed from.
   *
   * The base daedalus by default. A test of a layer that only exists on
   * a superset (daedalus_ai on daedalus_ai) overrides this to install from
   * that umbrella; the umbrella includes daedalus, so the base fixture
   * (the non-LB bundle, the editor role) is still what this trait expects.
   */
  protected function installRecipePath(): string {
    return $this->daedalusRecipePath();
  }

  /**
   * {@inheritdoc}
   */
  protected function installParameters() {
    $parameters = parent::installParameters();
    // Core's FileSystem::saveData() stages every file write through
    // temporary://. The harness points file_temp_path at
    // sites/simpletest/<id>/temp but nothing creates that directory before
    // the in-process installer runs the recipe, and daedalus_stylish compiles its
    // variant stylesheet (a file save) during hook_modules_installed. Real
    // sites are unaffected (no file_temp_path setting; the OS temp dir is
    // used).
    mkdir(DRUPAL_ROOT . '/' . $this->siteDirectory . '/temp', 0775);
    $parameters['parameters']['recipe'] = $this->installRecipePath();
    return $parameters;
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // Read by installDrupal(); see the trait docblock.
    $this->profile = '';
    $this->defaultTheme = 'daedalus_theme';
    parent::setUp();
    $this->installFixture();
  }

  /**
   * Installs the test-only fixture on top of the recipe's product.
   *
   * The default is daedalus_edit_test_fixture (see the trait docblock). A
   * capability with a fixture module of its own overrides this to install
   * that one instead: two fixtures shipping the same storage config (the
   * node.body storage this one ships) cannot install on one site, and the
   * recipe's editor role gains the overriding fixture's bundle permissions
   * here for the same reason the default grants the plain bundle's.
   */
  protected function installFixture(): void {
    \Drupal::service('module_installer')->install(['daedalus_edit_test_fixture']);
    // The fixture's bundles did not exist when the recipe's editor role was
    // built, so their permissions are granted here (see the trait docblock).
    Role::load('daedalus_editor')
      ->grantPermission('create plain content')
      ->grantPermission('edit any plain content')
      ->grantPermission('delete any plain content')
      ->save();
  }

  /**
   * Creates and logs in the editor persona: only the daedalus_editor role.
   *
   * No admin bypass, no per-test permission list (see the trait docblock).
   *
   * @return \Drupal\user\UserInterface
   *   The logged-in editor.
   */
  protected function loginAsEditor(): UserInterface {
    $account = $this->drupalCreateUser();
    $account->addRole('daedalus_editor');
    $account->save();
    $this->drupalLogin($account);
    return $account;
  }

}
