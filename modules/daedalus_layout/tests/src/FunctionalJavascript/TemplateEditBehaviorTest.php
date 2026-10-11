<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\FunctionalJavascript;

use Drupal\Core\Url;
use Drupal\daedalus_blueprint\NodeTree\DocumentInstantiator;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay;
use Drupal\node\Entity\Node;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives the page builder on a bundle's default layout: the template host.
 *
 * A Layout Builder enabled entity view display hosts a page document of
 * its own (the bundle's template), edited on core's manage-display layout
 * route with the same canvas, chrome and spine a page uses. This test
 * carries that whole path end to end on the recipe's Page type, whose
 * shipped template is blank, and then watches the template seed a page
 * born through the real creation door.
 *
 * The seams only a browser sees, in scenario order:
 * - ROUTE HOST TO MODE CHROME: Edit Mode entered on the field_ui route
 *   mounts the top bar, the Add panel and the layout root inside a root
 *   wrapper naming the display, and the mirror boots rooted on the
 *   display (a kernel render proves the markup; only a browser mounts it
 *   and boots the app on it).
 * - SAMPLE INERTNESS AND VALUE-BLOCK EDITING: a container, the title field
 *   block (placed through the Add panel, which lists the sample-context
 *   field blocks on a template under All) and a text block land on the
 *   template. The field block renders core's
 *   sample entity's title with no edit id and no selection identity; the
 *   text block, a value block on a display, edits in place and the edit
 *   stages in tempstore while the display's config still holds the blank
 *   marker. Four layers (attribution, the value block plugin, the editor
 *   door, the mutation swap) agree only in the DOM.
 * - THE FORM TRANSPORT FOR A PLUGIN BLOCK: the Configure verb opens core's
 *   UpdateBlockForm for the field block in the dialog, the formatter's
 *   label setting changes, Update rides the op queue as one update_element
 *   through the spine, and the canvas swaps the block by key (the
 *   container element survives, the field label leaves the block).
 * - A STYLE OP AGAINST A DISPLAY HOST: the inspector's Background row on
 *   the container commits one update_style against the display's map, the
 *   canvas paints it, and the top bar's Save commits the staged template
 *   as config: sections, the field block's formatter, the text block as
 *   values (no block_revision_id anywhere in the export) and the style map
 *   as the display's third-party setting.
 * - SEEDING ON THE REAL CREATION DOOR: a page created through the node
 *   form arrives in Edit Mode showing the template's bands under fresh,
 *   derived identity, its own title edited in place through the field
 *   block, the template's style re-keyed onto the seeded container in the
 *   page's own map and painted on the canvas, both on the arrival render
 *   (the style tag is printed before the canvas seeds, through a
 *   render-only host that seeds the same identity); editing the page's
 *   text leaves the
 *   template untouched, and a second new page shows the template's text
 *   under its own identity.
 *
 * The scenario runs as an administrator, by explicit exception to the
 * fixture's editor persona: the template surface is field_ui's
 * manage-display layout route (administer node display plus configure any
 * layout), the developers' door by decision, which the recipe's
 * daedalus_editor role does not and should not hold. Pages are created by
 * the same account so the seeding leg stays one browser session.
 *
 * One fixture, one scenario: DaedalusFixtureTrait (the daedalus recipe's
 * site) with field_ui installed on top, because the recipe does not enable
 * it and the template route exists only with it; a full site install from
 * the recipe is the dominant cost of a run, so all behavior lives in the
 * single test method.
 *
 * The harness constraint (the class note): the test process and the browser
 * are different PHP processes sharing one database. The browser's staged,
 * uncommitted template edits live in the layout tempstore (a DB table). A
 * test-process load of the display entity while that staged work exists (a
 * config loadUnchanged, the blueprint storage handler, or the host
 * resolver's sectionStorage) collides with it and makes the browser's Save
 * commit the pristine blank display instead of the staged document. So
 * every mid-session assertion about staging is made through the browser
 * (the mirror and the canvas), and the display config is read from the test
 * process only after Save, when the tempstore is committed and cleared, and
 * never between entering Edit Mode and Save on the same host.
 *
 * @group daedalus_layout
 */
#[Group('daedalus_layout')]
#[RunTestsInSeparateProcesses]
class TemplateEditBehaviorTest extends WebDriverTestBase {

  use EditModeTrait;
  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }

  /**
   * The template under test: the Page type's default display.
   */
  private const DISPLAY_ID = 'node.page.default';

  /**
   * Core's manage-display layout route for the Page type.
   */
  private const TEMPLATE_PATH = '/admin/structure/types/manage/page/display/default/layout';

  /**
   * The title field block's plugin id on the Page type.
   */
  private const TITLE_BLOCK = 'field_block:node:page:title';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->daedalusSetUp();
    // The template route is field_ui's; the recipe does not enable it.
    \Drupal::service('module_installer')->install(['field_ui']);
  }

  /**
   * Installs a counter of blueprint POSTs, per page load.
   *
   * "One gesture, one blueprint POST" is a wiring fact; the counter proxies
   * window.fetch, which the op spine's client uses. A reload kills the JS
   * context, so this runs again after every navigation.
   */
  private function countPosts(): void {
    $this->getSession()->executeScript(<<<'JS'
      window.__blueprintPosts = 0;
      const originalFetch = window.fetch;
      window.fetch = function (url, options) {
        const target = url && url.url ? url.url : String(url);
        const method = (options && options.method) || (url && url.method) || 'GET';
        if (target.includes('daedalus-blueprint') && method === 'POST') {
          window.__blueprintPosts++;
        }
        return originalFetch.apply(this, arguments);
      };
      JS);
  }

  /**
   * Waits for the blueprint mirror to boot on the current page.
   */
  private function waitForMirror(string $message): void {
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      $message,
    );
  }

  /**
   * Opens the Styles inspector when the cookie entry rendered it closed.
   */
  private function openInspector(): void {
    $this->assertSession()->waitForElement('css', '#daedalus_stylish_inspector');
    $this->getSession()->executeScript(<<<'JS'
      (() => {
        const panel = document.getElementById('daedalus_stylish_inspector');
        if (panel && panel.classList.contains('daedalus-ui-hidden')) {
          Drupal.Daedalus.SidebarManager.openSidebar('daedalus_stylish_inspector');
        }
      })();
      JS);
    $this->assertJsCondition(
      "(() => { const p = document.getElementById('daedalus_stylish_inspector');"
      . " return !!p && !p.classList.contains('daedalus-ui-hidden'); })()",
      20000,
      'The Styles inspector is open on the template.',
    );
  }

  /**
   * Clicks a panel element found by xpath, scrolled into the viewport.
   */
  private function clickXpath(string $xpath, string $message): void {
    $element = $this->assertSession()->waitForElement('xpath', $xpath, 20000);
    $this->assertNotNull($element, $message);
    $this->getSession()->executeScript(
      'document.evaluate(' . json_encode($xpath) . ', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null)'
      . ".singleNodeValue.scrollIntoView({ block: 'center' });"
    );
    $element->click();
  }

  /**
   * Edits a text block in place: double-click, type, blur.
   *
   * The text block's typing surface is the element carrying
   * data-daedalus-edit-text: the composed root itself for an unlinked
   * block, the nested anchor for a linked one.
   *
   * @param string $block_key
   *   The block's selectable key.
   * @param string $text
   *   The text to commit.
   * @param string $context
   *   A prefix for the assertion messages.
   */
  private function editTextInPlace(string $block_key, string $text, string $context): void {
    $session = $this->getSession();
    $surface = "[data-selectable='$block_key'] [data-daedalus-edit-text], [data-selectable='$block_key'][data-daedalus-edit-text]";
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_key');");
    $this->dispatchDoubleClick($surface);
    $this->assertJsCondition(
      "(() => { const text = document.querySelector(\"$surface\");"
      . " return Drupal.Daedalus.Edit.CurrentlyEditingElement !== null && !!text && text.isContentEditable; })()",
      20000,
      "$context: the double-click at the text block's leaf opened its in-place edit.",
    );
    $session->executeScript(<<<JS
      document.querySelector("$surface").textContent = '$text';
      Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();
      JS);
    $this->assertJsCondition(
      "JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$text')",
      20000,
      "$context: the blur commit reached the blueprint mirror.",
    );
    $this->assertJsCondition(
      "document.querySelector(\"$surface\")?.textContent === '$text'",
      20000,
      "$context: the swap re-rendered the leaf with the committed text.",
    );
  }

  /**
   * The template's display, loaded fresh from config.
   */
  private function display(): LayoutBuilderEntityViewDisplay {
    /** @var \Drupal\layout_builder\Entity\LayoutBuilderEntityViewDisplay $display */
    $display = \Drupal::entityTypeManager()->getStorage('entity_view_display')->loadUnchanged(self::DISPLAY_ID);
    return $display;
  }

  /**
   * The template is edited on its route and seeds a page born in the form.
   */
  public function testTemplateEditing(): void {
    $admin = $this->drupalCreateUser([], NULL, TRUE);
    $this->drupalLogin($admin);

    $session = $this->getSession();
    $assert_session = $this->assertSession();
    $page = $session->getPage();

    $template = $this->display();
    $display_uuid = $template->uuid();
    $this->assertSame('layout_builder_blank', $template->getSections()[0]->getLayoutId(), 'The recipe ships the Page template blank.');

    // ——— 1. Edit Mode on the manage-display layout route. ———
    // The server-rendered cookie path: the full chrome, every panel's
    // assets, the mirror rooted on the display.
    $this->drupalGet(self::TEMPLATE_PATH);
    $session->resizeWindow(1600, 1400);
    $this->enterEditModeViaCookies(self::TEMPLATE_PATH);
    $assert_session->elementExists('css', '#edit-mode-top-bar');
    $this->assertJsCondition("document.documentElement.getAttribute('data-daedalus-ui-mode') === 'edit'", 20000, 'The template route renders in Edit Mode from the cookie.');
    $assert_session->elementExists('css', '#edit-mode-top-bar #daedalus-ui-save');
    $assert_session->elementExists('css', '#place_block');
    $wrapper_id = 'entity_view_display::' . self::DISPLAY_ID . '::entity_view_display';
    $assert_session->elementExists('css', sprintf(
      '[data-daedalus-ui-entity-wrapper="%s"][data-main-entity][data-selectable="entity:%s"] #layout-builder.daedalus-layout-layout',
      $wrapper_id,
      $display_uuid,
    ));
    $this->waitForMirror('The blueprint mirror booted on the template route.');
    $this->assertJsCondition(
      sprintf("Drupal.Daedalus.Blueprint.getRoot()?.uuid === '%s'", $display_uuid),
      20000,
      'The mirror is rooted on the display.',
    );
    $this->assertJsCondition(
      "(() => { const ctx = Drupal.Daedalus.Blueprint.rootContext();"
      . " return ctx.entityType === 'entity_view_display' && ctx.entityId === '" . self::DISPLAY_ID . "'; })()",
      20000,
      'The root context addresses the display host.',
    );
    $this->assertJsCondition(
      "Drupal.Daedalus.Blueprint.getBlueprint()?._element?.type === 'Template' && !('fields' in Drupal.Daedalus.Blueprint.getBlueprint())",
      20000,
      'The template root presents as a Template with no fields.',
    );
    $this->assertJsCondition(
      "document.querySelector('#daedalus-layout-blank-page') !== null",
      20000,
      'The blank template shows the empty-page affordance.',
    );
    $this->countPosts();

    // ——— 2. A container, the title field block and a text block. ———
    // The container through the same batch a palette drop enqueues (the
    // blank marker yields to the first section); the two blocks through
    // the Add panel's click-to-place, armed on the container by the
    // Insert verb: the field block (the template's sample context derives
    // the bundle's field blocks) and the text block, each one POST.
    $container_uuid = \Drupal::service('uuid')->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            { op: 'add_section', uuid: '$container_uuid', location: { anchor: Blueprint.getRoot().uuid, placement: 'inside' } },
          ],
        })).catch((error) => {
          window.__containerError = error.message;
        });
      })();
      JS);
    $container = "[data-main-entity] [data-selectable='container:$container_uuid']";
    $this->assertJsCondition(
      "window.__containerError !== undefined || document.querySelector(\"$container\") !== null",
      20000,
      'The container placement resolved.',
    );
    $this->assertNull($session->evaluateScript('window.__containerError ?? null'), 'Placing the container on the template failed: ' . ($session->evaluateScript('window.__containerError ?? null') ?? ''));
    $this->assertJsCondition('window.__blueprintPosts === 1', 10000, 'The container was one blueprint POST.');

    $session->executeScript("Drupal.Daedalus.Selection.select('container:$container_uuid');");
    $this->assertJsCondition("Drupal.Daedalus.Selection.getSelected() === 'container:$container_uuid'", 10000, 'The container is selected.');
    $this->pressHotkey('i');
    $this->assertJsCondition(
      "document.documentElement.classList.contains('daedalus-layout-insert-pending')"
      . " && !document.getElementById('place_block').classList.contains('daedalus-ui-hidden')",
      10000,
      'The Insert verb armed click-to-place on the container and opened the Add panel.',
    );
    // The panel's faces on the template are the bundle's: its title field
    // block files under core's category in All.
    $title_row = $this->revealAddPanelRow(self::TITLE_BLOCK);
    $session->executeScript("document.querySelector('$title_row').click();");
    $this->assertJsCondition('window.__blueprintPosts === 2', 20000, 'The field block click was one blueprint POST.');
    $this->assertNotNull(
      $assert_session->waitForElement('css', "$container [data-layout-builder-block-uuid]", 20000),
      'The field block rendered inside the container.',
    );
    $field_uuid = (string) $session->evaluateScript("document.querySelector(\"$container [data-layout-builder-block-uuid]\").dataset.layoutBuilderBlockUuid");
    $this->assertNotEmpty($field_uuid, 'The placed field block carries its component uuid.');
    $field_block = "[data-main-entity] [data-selectable='block:$field_uuid']";
    $this->assertJsCondition(
      "Drupal.Daedalus.Selection.getSelected() === 'block:$field_uuid' && document.documentElement.classList.contains('daedalus-layout-insert-pending')",
      10000,
      'The placement selected the field block and advanced the armed anchor to it.',
    );
    $text_row = $this->revealAddPanelRow('inline_block:text');
    $session->executeScript("document.querySelector('$text_row').click();");
    $this->assertJsCondition('window.__blueprintPosts === 3', 20000, 'The text block click was one blueprint POST.');
    $this->assertJsCondition(
      "document.querySelectorAll(\"$container [data-layout-builder-block-uuid]\").length === 2",
      20000,
      'The text block rendered inside the container after the field block.',
    );
    $text_uuid = (string) $session->evaluateScript("document.querySelectorAll(\"$container [data-layout-builder-block-uuid]\")[1].dataset.layoutBuilderBlockUuid");
    $this->assertNotSame($field_uuid, $text_uuid, 'The two placements carry distinct identities.');
    $text_block = "[data-main-entity] [data-selectable='block:$text_uuid']";
    $this->assertJsCondition(
      "document.querySelector(\"{$text_block}[data-daedalus-edit-editor='text_composite']\") !== null",
      20000,
      'The text block rendered as the composed text block with its editor attribution.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      "!document.documentElement.classList.contains('daedalus-layout-insert-pending')",
      10000,
      'Escape ended the insert session.',
    );

    // The sample entity is inert: its title renders as the field block's
    // content with no editor leaf, no selection identity and no presented
    // field of the host (a template has no fields).
    $sample_facts = json_decode((string) $session->evaluateScript(<<<JS
      JSON.stringify((() => {
        const block = document.querySelector("$field_block");
        const field = block.querySelector('[data-element="kind:field"]');
        return {
          fieldText: field ? field.textContent.trim() : null,
          editIds: block.querySelectorAll('[data-daedalus-edit-id]').length,
          fieldKeys: block.querySelectorAll('[data-selectable^="field:"]').length,
          presented: block.hasAttribute('data-presented-field'),
        };
      })())
      JS), TRUE);
    $this->assertNotEmpty($sample_facts['fieldText'], "The sample entity's title renders inside the field block. " . print_r($sample_facts, TRUE));
    $this->assertSame(0, $sample_facts['editIds'], 'No editor leaf on the sample entity\'s title. ' . print_r($sample_facts, TRUE));
    $this->assertSame(0, $sample_facts['fieldKeys'], 'The sample entity\'s title carries no selection identity. ' . print_r($sample_facts, TRUE));
    $this->assertFalse($sample_facts['presented'], 'A field block on a template presents no field of the host. ' . print_r($sample_facts, TRUE));

    // The text block, a value block on the display, edits in place.
    $template_text = 'Template band text';
    $this->editTextInPlace("block:$text_uuid", $template_text, 'Template text');
    $this->assertJsCondition('window.__blueprintPosts === 4', 10000, 'The in-place edit was one blueprint POST.');

    // "Staged, not saved" is proven by Save below committing the staged
    // content; it is NOT checked mid-session here. A test-process load of
    // the display entity (config or storage handler) while the browser has
    // staged, uncommitted work corrupts the shared layout tempstore and
    // makes Save commit the blank display. See the class note.
    // ——— 3. The field block's Configure dialog on the form transport. ———
    // The formatter's label position is the representative setting; the
    // field renders as its bare items in this stack (no label markup at
    // any position), so the render fact is the swap by key and the op's
    // fact is the mirror, then the saved config below.
    $session->executeScript(<<<JS
      (() => {
        window.__containerBeforeDialog = document.querySelector("$container");
        window.__fieldBlockBeforeDialog = document.querySelector("$field_block");
        Drupal.Daedalus.Selection.select('block:$field_uuid');
        const verb = Drupal.Daedalus.Verbs.verbsFor('block:$field_uuid')
          .find((candidate) => candidate.id === 'daedalus_layout.configure');
        if (!verb) {
          throw new Error('The Configure verb does not apply to the field block.');
        }
        verb.run('block:$field_uuid');
      })();
      JS);
    $dialog_form = '#layout-builder-modal form[data-daedalus-edit-configure-dialog="block:' . $field_uuid . '"]';
    $label_select = $assert_session->waitForElementVisible('css', "$dialog_form select[name=\"settings[formatter][label]\"]", 20000);
    $this->assertNotNull($label_select, 'The Configure verb opened the field block dialog on the defaults storage, its form stamped for the transport.');
    $this->assertSame('above', $label_select->getValue(), 'The dialog shows the formatter label setting the placement holds.');
    $label_select->selectOption('hidden');
    $update_button = $assert_session->waitForElementVisible('css', "$dialog_form [data-daedalus-edit-configure-dialog-submit]");
    $this->assertNotNull($update_button, 'The dialog rendered its stamped Update button.');
    $update_button->click();
    $this->assertJsCondition(
      "(() => {"
      . " const block = document.querySelector(\"$field_block\");"
      . " const container = document.querySelector(\"$container\");"
      . " const modal = document.querySelector('#layout-builder-modal');"
      . " return !!block && block !== window.__fieldBlockBeforeDialog"
      . " && container === window.__containerBeforeDialog"
      . " && block.querySelector('[data-element=\"kind:field\"]') !== null"
      . " && block.querySelectorAll('[data-daedalus-edit-id]').length === 0"
      . " && (!modal || modal.getClientRects().length === 0);"
      . " })()",
      20000,
      'The dialog commit swapped the field block by key (the container survived, the sample title still inert) and closed the dialog.',
    );
    $this->assertJsCondition(
      "(() => { const walk = (nodes) => nodes.flatMap((node) => [node, ...walk(node.children ?? [])]);"
      . " const block = walk(Drupal.Daedalus.Blueprint.getBlueprint().layout ?? []).find((node) => node.uuid === '$field_uuid');"
      . " return !!block && block.configuration?.formatter?.label === 'hidden'; })()",
      20000,
      "The mirror carries the field block's configuration with the formatter label hidden.",
    );
    $this->assertJsCondition(
      "(() => { const entries = Drupal.Daedalus.History?.getState?.().entries ?? [];"
      . " const latest = entries[entries.length - 1];"
      . " return !!latest && latest.targets?.some((target) => target.uuid === '$field_uuid' && target.field_name === 'formatter'); })()",
      20000,
      'The dialog commit rode the spine: the history entry targets the formatter.',
    );

    // ——— 4. The container's background through the inspector, then Save. ———
    $session->executeScript("Drupal.Daedalus.Selection.select('container:$container_uuid');");
    $this->openInspector();
    $background_well_xpath = '//div[@id = "daedalus_stylish_inspector"]//*[contains(@class, "daedalus-stylish-inspector__widget--background")]'
      . '//*[contains(@class, "daedalus-stylish-inspector__widget-row")][.//*[contains(@class, "daedalus-stylish-inspector__widget-axis") and normalize-space() = "Background"]]'
      . '//button[contains(@class, "daedalus-stylish-swatch-well")]';
    $this->clickXpath($background_well_xpath, "The container's Basic panel renders the Background row's swatch well.");
    $stop_xpath = '//div[contains(@class, "daedalus-ui-popover")]//div[contains(@class, "daedalus-stylish-swatch-popover")]'
      . '//button[contains(@class, "daedalus-stylish-palette-grid__stop")][not(contains(@class, "daedalus-stylish-palette-grid__stop--active"))][1]';
    $this->clickXpath($stop_xpath, 'The well opened the palette popover offering a stop.');
    $map = $this->styleMapJs();
    $this->assertJsCondition(
      "typeof $map?.['container:$container_uuid']?.default?.['background-color'] === 'string'",
      20000,
      "The pick committed background-color on the container's key in the display's map.",
    );
    $background = (string) $session->evaluateScript("{$map}['container:$container_uuid'].default['background-color']");
    $this->assertNotEmpty($background, 'The committed background value is non-empty.');
    $this->assertJsCondition('window.__blueprintPosts === 5', 10000, 'The pick was one blueprint POST.');
    $painted = "(() => { const container = document.querySelector(\"$container\"); const probe = document.createElement('span');"
      . " probe.style.backgroundColor = " . json_encode($background) . "; container.append(probe);"
      . " const expected = getComputedStyle(probe).backgroundColor; probe.remove();"
      . " return expected !== 'rgba(0, 0, 0, 0)' && getComputedStyle(container).backgroundColor === expected; })()";
    $this->assertJsCondition($painted, 20000, "The container wears the picked background on the template canvas: $background.");

    // Save: the top bar commits the staged template as config and lands
    // the template route out of Edit Mode. No test-process read of the
    // display precedes this (see the "staged, not saved" note above).
    $session->executeScript("window.__preSave = true; document.querySelector('#daedalus-ui-save').click();");
    $this->assertJsCondition('window.__preSave === undefined', 20000, 'The Save button navigated.');
    $this->assertTrue($session->wait(20000, "document.readyState === 'complete'"), 'The save landed a document.');
    $landing = json_decode((string) $session->evaluateScript(
      "JSON.stringify({ path: window.location.pathname, mode: document.documentElement.getAttribute('data-daedalus-ui-mode') })"
    ), TRUE);
    // The expectation carries the base path the site is served under
    // (drupal.org's runner serves it under /web/).
    $this->assertSame(Url::fromUserInput(self::TEMPLATE_PATH)->toString(), $landing['path'], 'Save landed back on the template route. ' . print_r($landing, TRUE));
    $this->assertNotSame('edit', $landing['mode'], 'Save dropped Edit Mode on the template route. ' . print_r($landing, TRUE));

    $saved = $this->display();
    $sections = $saved->getSections();
    $this->assertCount(1, $sections, 'The saved template holds the one container.');
    $saved_shape = json_encode(array_map(
      fn($s) => [
        $s->getLayoutId(),
        $s->getThirdPartySetting('daedalus_layout', 'uuid'),
        array_keys($s->getComponents()),
      ],
      $sections,
    ));
    $this->assertSame($container_uuid, $sections[0]->getThirdPartySetting('daedalus_layout', 'uuid'), 'The saved container keeps the identity the canvas placed. Saved: ' . $saved_shape);
    $field_configuration = $sections[0]->getComponent($field_uuid)->get('configuration');
    $this->assertSame(self::TITLE_BLOCK, $field_configuration['id']);
    $this->assertSame('hidden', $field_configuration['formatter']['label'], 'The dialog commit landed in config.');
    $text_configuration = $sections[0]->getComponent($text_uuid)->get('configuration');
    $this->assertSame('inline_block:text', $text_configuration['id']);
    $this->assertArrayHasKey('block_values', $text_configuration, 'The text block saved as a value block.');
    $this->assertSame($template_text, $text_configuration['block_values']['field_text_text'] ?? NULL, 'The value block carries the in-place edit.');
    $this->assertArrayNotHasKey('block_revision_id', $text_configuration);
    $this->assertArrayNotHasKey('block_serialized', $text_configuration);
    $raw = $this->config('core.entity_view_display.' . self::DISPLAY_ID)->getRawData();
    $this->assertStringNotContainsString('block_revision_id', json_encode($raw), 'The display export names no content id.');
    $template_map = \Drupal::service('daedalus_blueprint.host_resolver')->fromEntity($saved)->styleMap();
    $this->assertSame($background, $template_map["container:$container_uuid"]['default']['background-color'] ?? NULL, "The display's style map holds the container's background.");
    // The save cleared the tempstore (the landing out of Edit Mode above is
    // its proof); a test-process has() check is not made, because resolving
    // the display's section storage from this process re-stages it (the
    // class note).
    // ——— 5. A page born through the node form seeds from the template. ———.
    $this->drupalGet('node/add/page');
    $page->fillField('title[0][value]', 'Seeded page one');
    $page->pressButton('Save');
    // The click returns before the submit lands: the test process reads the
    // node only once the browser has left the form for the page it created.
    $this->assertJsCondition("document.readyState === 'complete' && /\\/node\\/\\d+$/.test(window.location.pathname)", 20000, 'The node form submitted and redirected to a node page.');
    $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['title' => 'Seeded page one']);
    $this->assertCount(1, $nodes, 'The node form created the page.');
    /** @var \Drupal\node\NodeInterface $first */
    $first = reset($nodes);
    $this->assertTrue($session->wait(20000, "document.readyState === 'complete' && window.location.pathname === '" . $first->toUrl()->toString() . "'"), 'The form redirected to the new page.');
    $this->assertJsCondition("document.documentElement.getAttribute('data-daedalus-ui-mode') === 'edit'", 20000, 'The new page arrived in Edit Mode (the bundle\'s initial mode).');
    $this->waitForMirror('The mirror booted on the new page.');
    $this->assertJsCondition(sprintf("Drupal.Daedalus.Blueprint.getRoot()?.uuid === '%s'", $first->uuid()), 20000, 'The mirror is rooted on the page.');
    $this->countPosts();

    $seed_container = DocumentInstantiator::derivedUuid($first->uuid(), $container_uuid);
    $seed_field = DocumentInstantiator::derivedUuid($first->uuid(), $field_uuid);
    $seed_text = DocumentInstantiator::derivedUuid($first->uuid(), $text_uuid);
    $this->assertNotSame($container_uuid, $seed_container, 'The seeded identity is not the template\'s.');
    $seeded_container = "[data-main-entity] [data-selectable='container:$seed_container']";
    $this->assertNotNull($assert_session->waitForElement('css', $seeded_container, 20000), 'The page shows the template\'s band under its own derived identity.');
    $assert_session->elementNotExists('css', "[data-main-entity] [data-selectable='container:$container_uuid']");
    $assert_session->elementExists('css', "$seeded_container [data-selectable='block:$seed_text']");
    $assert_session->elementExists('css', "$seeded_container [data-selectable='block:$seed_field']");
    // The field block presents the PAGE's own title, editable in place.
    $this->assertJsCondition(
      "(() => { const block = document.querySelector(\"$seeded_container [data-selectable='block:$seed_field']\");"
      . " return !!block && block.querySelector(\"[data-selectable='field:{$first->uuid()}:title'] [data-daedalus-edit-id], [data-selectable='field:{$first->uuid()}:title'][data-daedalus-edit-id]\") !== null"
      . " && block.textContent.includes('Seeded page one'); })()",
      20000,
      "The seeded field block presents the page's own title as an editable leaf.",
    );
    $this->assertJsCondition(
      "document.querySelector(\"$seeded_container [data-selectable='block:$seed_text']\")?.textContent.trim() === '$template_text'",
      20000,
      'The seeded text block shows the template\'s text.',
    );
    $this->assertJsCondition(
      "$map?.['container:$seed_container']?.default?.['background-color'] === " . json_encode($background)
      . " && $map?.['container:$container_uuid'] === undefined",
      20000,
      'The template\'s style is re-keyed onto the seeded container in the page\'s own map; the template\'s key is nowhere on the page.',
    );
    // The paint, on the ARRIVAL render: daedalus_stylish's entity view
    // runs before the canvas seeds, so it reads the page through a host
    // over a disposable clone whose seed derives the same identity, and
    // the inline tag carries the template's background under the seeded
    // container's key (the shared lane's template-keyed copy is inert
    // against this canvas). Nothing of the seed reaches the saved page.
    $seeded_painted = "(() => { const container = document.querySelector(\"$seeded_container\"); if (!container) { return false; } const probe = document.createElement('span');"
      . " probe.style.backgroundColor = " . json_encode($background) . "; container.append(probe);"
      . " const expected = getComputedStyle(probe).backgroundColor; probe.remove();"
      . " return expected !== 'rgba(0, 0, 0, 0)' && getComputedStyle(container).backgroundColor === expected; })()";
    $this->assertJsCondition($seeded_painted, 20000, "The seeded container wears the template's background on the page canvas on arrival.");

    // Editing the page's text leaves the template untouched.
    $page_text = 'Page one text';
    $this->editTextInPlace("block:$seed_text", $page_text, 'Page one text');
    $this->assertJsCondition('window.__blueprintPosts === 1', 10000, "The page's in-place edit was one blueprint POST.");
    // The template is untouched by the page's edit: a SECOND fresh page
    // (below) shows the template's original text, which it could not if the
    // page edit had reached the template. The display config is NOT read
    // from the test process here, while the page's edit is staged, for the
    // same shared-tempstore reason noted above.
    // A second new page seeds its own copy: the template's text under its
    // own identity, none of page one's.
    $second = Node::create(['type' => 'page', 'title' => 'Seeded page two']);
    $second->save();
    $second_container = DocumentInstantiator::derivedUuid($second->uuid(), $container_uuid);
    $second_text = DocumentInstantiator::derivedUuid($second->uuid(), $text_uuid);
    $this->assertNotSame($seed_container, $second_container, 'Two pages seeded from one template share no element uuid.');
    $this->enterEditModeViaCookies($second->toUrl());
    $this->waitForMirror('The mirror booted on the second page.');
    $second_seeded = "[data-main-entity] [data-selectable='container:$second_container']";
    $this->assertNotNull($assert_session->waitForElement('css', "$second_seeded [data-selectable='block:$second_text']", 20000), 'The second page shows the template\'s band under its own identity.');
    $assert_session->elementNotExists('css', "[data-main-entity] [data-selectable='container:$seed_container']");
    $this->assertJsCondition(
      "document.querySelector(\"$second_seeded [data-selectable='block:$second_text']\")?.textContent.trim() === '$template_text'",
      20000,
      'The second page shows the template\'s text, not page one\'s edit.',
    );
    $this->assertStringNotContainsString($page_text, (string) $session->evaluateScript("document.querySelector('[data-main-entity]').textContent"), 'Page one\'s edit is nowhere on page two.');
  }

}
