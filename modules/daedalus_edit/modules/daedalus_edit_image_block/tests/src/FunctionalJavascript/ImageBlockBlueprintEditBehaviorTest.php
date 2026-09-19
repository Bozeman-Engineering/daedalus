<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit_image_block\FunctionalJavascript;

use Drupal\file\Entity\File;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\Tests\daedalus\Traits\ImageMediaCreationTrait;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives the image block's blueprint inline editing on a real page-built page.
 *
 * The image block is the second markup-collapsing blueprint leaf after the
 * text block, and its defining difference is that the composed root is (or
 * wraps) a VOID element: nothing types into an img, so the whole gesture is
 * click -> chrome (the chip face's edit-scoped media verb plus the Link
 * widget's
 * link control), and the media half of the block has no client-side value
 * at all (the src is derived server-side from the media's configured
 * display). This test covers the behaviors that shape follows from, on a
 * real page:
 * - The empty placeholder: an image block placed with no media renders an
 *   explicit placeholder <div> root on edit-capable renders (an img with no
 *   src is broken markup, and collapsed blocks are excluded from the
 *   content-preview placeholder, so without this an empty block would be
 *   invisible in Edit Mode). The placeholder carries the full collapsed
 *   contract on ONE element: the selectable key, the editor attribution and
 *   its server-built media library URL, and the drop-to-replace bag.
 * - The empty leaf's edit() auto-opens the media library: picking an image
 *   is the placeholder's whole point, so the gesture saves the click.
 * - The immediate media commit: a media selection commits as its OWN
 *   history entry the moment it returns from the modal — exactly one
 *   update_element op — because no client-side preview exists; the
 *   mutation's markup swap (placeholder div -> attributed img) IS the
 *   preview. The edit survives that swap with a re-taken snapshot, so a
 *   following blur diffs empty and sends NOTHING (the snapshot echo).
 * - The link round trip, including from EMPTY: the same form-only-stub
 *   lesson the text block pinned — an empty link field must snapshot as
 *   null, or linking an unlinked image spreads the stub into the sent value
 *   and the write silently no-ops. Linking wraps the root in an anchor with
 *   the whole identity migrating onto it; a real click on that anchor
 *   selects instead of navigating; Escape restores a previewed link without
 *   sending; clearing the input unlinks back to the bare img root.
 * - The img part: the stable image-block-image class marks "the picture"
 *   in both link states — hoisted onto the root when the img IS the root,
 *   stamped literally (img, never the anchor) when linked. The client-side
 *   link preview resolves the link states through the resolver's client
 *   twin and morphs by model key, the img continuing as the same physical
 *   element with its class and source set staying put while the identity
 *   bag migrates between roots — the old re-seating fixups have no
 *   successor. A part style written to block:{uuid}::.image-block-image
 *   must land on the img in both shapes (the printer's :scope.cls half on
 *   the part-is-root img, the .cls half on the linked descendant) and
 *   never retarget onto the anchor root — the reason the picture controls
 *   are element-bound.
 * - The picture part holds through BOTH media forms: the part's ordered
 *   forms (img on raster, svg on vector) keep .image-block-image
 *   on whatever element the media renders, so swapping to an svg keeps the
 *   three element-bound controls (Image width, Corners and border,
 *   Shadow) offered and the stored part style live on the modeled svg
 *   root. Vector is a different form of the same part, not an absent part;
 *   the inline svg is a rendered element like any other, wearing the
 *   hoisted attributes bag (part class, viewBox, derived intrinsic size,
 *   the alt as aria-label). Only a browser sees the chain agree: the
 *   guide's bindings, the leader's live-DOM presence facts, and the basic
 *   panel's offer rule.
 * - The selection frame hugs the picture in BOTH link states (the declared
 *   defaults geometry seam): the block type's declaration ships its box
 *   facts as declared defaults (root display: block; width: fit-content,
 *   picture display: block), compiled into the defaults stylesheet and
 *   stamped onto the root as daedalus-stylish-defaults-- scope classes. Without
 *   them the linked anchor root is display: inline, whose own border box
 *   is a font strip at the baseline, and every piece of geometry-driven
 *   chrome (the frame, hover, drag targets, the hit area) measures that
 *   strip, not the picture: a full-width sliver under the image. The
 *   frame paints getBoundingClientRect() of the root verbatim
 *   (geometry-tracker.js), so the primary stroke's rect must equal the
 *   picture's within a pixel with the anchor root shrink-wrapping it, and
 *   again after the unlink re-renders the img as the root. Kernel tests
 *   pin the compiled rules and the stamps; only a browser sees the file
 *   actually land on the anchor's box and the frame measure it.
 * - The fallback tier: with the field_image widget flipped away from
 *   media_library_widget, the same block renders with NO blueprint editor
 *   attribution — instead the form path's lookup attributes (all with the
 *   'value' property segment configurable fields resolve to) hoist onto the
 *   collapsed root, and a click edits the field through the real entity
 *   form in the sidebar. The form path is never taken away; it is how
 *   non-media-library widgets keep working.
 *
 * The fixture is DaedalusFixtureTrait (the daedalus recipe's site, as
 * daedalus_editor); the recipe ships the image block type (via the
 * daedalus_image_block ingredient) and the image media type, so no extra
 * module install is needed. One fixture, one scenario: a full site install
 * from the recipe is by far the most expensive part of a run, so all
 * behavior lives in the single test method.
 *
 * @group daedalus_edit
 * @group daedalus_edit_image_block
 */
#[Group('daedalus_edit')]
#[Group('daedalus_edit_image_block')]
#[RunTestsInSeparateProcesses]
class ImageBlockBlueprintEditBehaviorTest extends WebDriverTestBase {

  use EditModeTrait;
  use ImageMediaCreationTrait;
  use DaedalusFixtureTrait;

  /**
   * An image block edits in place through the blueprint mutation spine.
   */
  public function testImageBlockBlueprintEditing(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // Two library entries: the first is the "add from empty" selection, the
    // second is the "replace" selection. Distinct fixtures and alts make the
    // rendered swap unambiguous.
    $first_media = $this->createImageMedia('image-1.png', 'First media image', 'First alt');
    $second_media = $this->createImageMedia('image-2.jpg', 'Second media image', 'Second alt');
    $first_id = (int) $first_media->id();
    $second_id = (int) $second_media->id();

    // A real Layout Builder page, the surface blueprint editing operates on.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Image block behavior page',
    ]);
    $node->save();
    $nid = (int) $node->id();

    $this->drupalGet($node->toUrl());
    $session->resizeWindow(1200, 1600);
    $this->enterEditModeViaCookies($node->toUrl(), 'selector');
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted.',
    );

    // Place an EMPTY image block — no media, no link — through the same
    // mutation batch a palette drop enqueues. Client-named UUIDs let the
    // test wait on the rendered element without racing the response.
    $uuid_service = \Drupal::service('uuid');
    $section_uuid = $uuid_service->generate();
    $component_uuid = $uuid_service->generate();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_section',
              uuid: '$section_uuid',
              location: { anchor: Blueprint.getRoot().uuid, placement: 'inside' },
            },
            {
              op: 'add_element',
              uuid: '$component_uuid',
              block_type: 'image',
              location: { anchor: '$section_uuid', placement: 'inside' },
            },
          ],
        })).catch((error) => {
          window.__placementError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__placementError !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$component_uuid')",
      20000,
      'The image block placement resolved.',
    );
    $placement_error = $session->evaluateScript('window.__placementError ?? null');
    $this->assertNull($placement_error, 'Image block placement failed: ' . ($placement_error ?? ''));

    // The empty placeholder IS the collapsed contract on one element: an img
    // with no src is broken markup, so the edit-capable render's root is an
    // explicit placeholder <div> carrying the selectable key, the blueprint
    // editor attribution, and the drop-to-replace bag — the container
    // ROOT's direct child (the one-box container: no part element
    // between), nothing above it holding the block identity (regions
    // have no markup or keys under the container model).
    $wrapper = "[data-selectable='block:$component_uuid']";
    $leaf = "{$wrapper}[data-daedalus-edit-editor='image_composite']";
    $placeholder = $assert_session->waitForElement(
      'css',
      "div{$leaf}.daedalus-edit-image-block-placeholder",
    );
    $this->assertNotNull($placeholder, 'The empty image block rendered the attributed placeholder div root.');
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          const root = document.querySelector("$leaf");
          const settings = JSON.parse(root.getAttribute('data-daedalus-edit-editor-settings'));
          return root.parentElement.matches("[data-selectable='container:$section_uuid']")
            && root.closest('[data-layout-builder-block-uuid]') === root
            && root.getAttribute('data-media-reference') === 'field_image'
            && root.getAttribute('data-media-bundle') === 'image'
            && (root.getAttribute('data-accepted-files') || '').includes('png')
            && typeof settings.media_library_url === 'string'
            && settings.media_library_url.includes('media-library');
        })()
        JS),
      'The placeholder root is the container root\'s direct child and carries the editor settings and the drop-to-replace bag.',
    );

    // Arm the edit session.
    $this->activateTool('edit');

    // A navigation sentinel (any page navigation produces a fresh JS context
    // without this property) plus a blueprint POST counter: the "exactly one
    // op per gesture" half of the commit grammar. Installed after the
    // placement so only edit gestures count.
    $session->executeScript(<<<JS
      window.__notNavigated = true;
      window.__blueprintPosts = 0;
      const originalFetch = window.fetch;
      window.fetch = function (url, options) {
        if (String(url).includes('daedalus-blueprint') && options && options.method === 'POST') {
          window.__blueprintPosts++;
        }
        return originalFetch.apply(this, arguments);
      };
      JS);

    // Click the placeholder: the edit opens with its Link widget on the
    // chip, and — because the leaf is empty — edit() auto-opens the media
    // library modal (the server-built URL from the editor settings, opened
    // as a Drupal AJAX modal; the widget never constructs library state).
    $session->executeScript("document.querySelector(\"$leaf\").scrollIntoView({ block: 'center' });");
    $session->getPage()->find('css', $leaf)->click();
    $this->assertJsCondition(
      <<<JS
      (() => {
        return Drupal.Daedalus.Edit.CurrentlyEditingElement !== null
          && !!document.querySelector("[data-daedalus-edit-toggle='link']")
          && !!document.querySelector(".media-library-widget-modal");
      })()
      JS,
      20000,
      'Clicking the empty placeholder opened the edit and auto-opened the media library modal.',
    );

    // The media gesture is an EDIT-SCOPED verb on the selection chip's face
    // (daedalus_edit_image_block.media): with this editor's edit open, the face
    // swaps to the edit's own verbs instead of hiding, and the verb names
    // the gesture from the edit's snapshot — "Add an image" while the block
    // references no media.
    $this->assertJsCondition(
      "!!document.querySelector(\".daedalus-ui-frame--editing .daedalus-ui-frame__chip--primary"
      . " .daedalus-ui-frame__verbs button[aria-label='Add an image']\")",
      20000,
      "The open edit's chip face offers the Add an image verb.",
    );

    // Select the first media and insert it. The checkbox's change handler
    // syncs the modal's hidden selection field; the Insert button's AJAX
    // binds on mousedown (core's default for form buttons). The selection
    // returns as ONE AJAX command routed to the current edit's
    // mediaSelected(), which commits IMMEDIATELY: exactly one update_element
    // op, no blur involved.
    $insert_selected = <<<JS
      (() => {
        const modal = document.querySelector('.media-library-widget-modal');
        const button = modal.querySelector('input[value="Insert selected"], button.media-library-select');
        if (!button) {
          throw new Error('Insert selected button not found in the media library modal.');
        }
        button.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true }));
      })();
      JS;
    $first_checkbox = $assert_session->waitForElement(
      'css',
      ".media-library-widget-modal input[type='checkbox'][value='$first_id']",
    );
    $this->assertNotNull($first_checkbox, 'The media library modal rendered the first media item.');
    $session->executeScript("document.querySelector(\".media-library-widget-modal input[type='checkbox'][value='$first_id']\").click();");
    $session->executeScript($insert_selected);

    // The commit's markup swap replaces the placeholder div with the
    // attributed img — the server-derived src (the media display's image
    // style) and the media's alt, with the whole collapsed identity riding
    // the img itself. The edit SURVIVES the swap: same current edit, the
    // chip's widgets still standing, and the snapshot re-taken from the freshly
    // replaced mirror
    // carries the new target_id (which is also what suppresses the empty
    // leaf's auto-open on the resume). The modal's close command rode the
    // selection response.
    $this->assertJsCondition(
      <<<JS
      (() => {
        const root = document.querySelector("$leaf");
        return window.__blueprintPosts === 1
          && !!root && root.matches("img[src*='image-1'][alt='First alt']")
          && Drupal.Daedalus.Edit.CurrentlyEditingElement !== null
          && Drupal.Daedalus.Edit.CurrentlyEditingElement.snapshot.field_image?.target_id === $first_id
          && !!document.querySelector("[data-daedalus-edit-toggle='link']")
          && !document.querySelector(".media-library-widget-modal");
      })()
      JS,
      20000,
      'The selection committed immediately as one op; the swap delivered the img root and the edit survived with a fresh snapshot.',
    );

    // The Link widget renders for the VOID element too. An img takes no
    // focus, so any focus-derived showing of the editor's chrome would
    // hide it here; the frame renders the widget purely because the
    // store's editingKey IS the primary selection (the seam a
    // text-leaf-only suite never crosses).
    $this->assertJsCondition(
      <<<JS
      (() => {
        const widget = document.querySelector(".daedalus-ui-frame--editing .daedalus-ui-frame__chip--primary .daedalus-ui-frame__widget [data-daedalus-edit-toggle='link']");
        return !!widget && getComputedStyle(widget).display !== "none";
      })()
      JS,
      10000,
      'The void img leaf shows the Link widget on the selection chip.',
    );

    // The img is the block's declared styling part ("the picture", the
    // stable target the unstable root cannot be). Unlinked the img IS the
    // root, so the part class rides the hoisted attributes bag onto it —
    // the part-is-root state the part printer's :scope half exists for.
    $this->assertJsCondition(
      "document.querySelector(\"$leaf\").classList.contains('image-block-image')",
      20000,
      'Unlinked, the img root itself carries the image-block-image part class via the attributes bag.',
    );

    // Blur after the media commit sends NOTHING: the media reference has no
    // client-side value to read back (readProperties echoes the snapshot),
    // so the gesture's diff is empty. This is the seam that keeps the
    // immediate commit its own history entry instead of double-committing.
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement === null && window.__blueprintPosts === 1',
      20000,
      'Blur after the media commit ended the edit without sending a second op.',
    );

    // Staged, not committed: the whole layout lives in tempstore; the saved
    // node still has no layout override.
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$nid]);
    $this->assertTrue(
      $storage->load($nid)->get('layout_builder__layout')->isEmpty(),
      'The image block edits are staged in tempstore, not committed to the node.',
    );

    // The link round trip, from EMPTY — the form-only-stub lesson: the never
    // -set link field sits in the mirror as a form-only stub, and the fresh
    // edit's snapshot must read it as null. A stub kept in the snapshot gets
    // spread into the sent value, which the server treats as "leave this
    // field alone", silently dropping the link the user just typed.
    $session->executeScript("document.querySelector(\"$leaf\").scrollIntoView({ block: 'center' });");
    $session->getPage()->find('css', $leaf)->click();
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null && !!document.querySelector(\".daedalus-edit-link-input\")"
      . " && !document.querySelector(\".media-library-widget-modal\")",
      20000,
      'Re-clicking the img root opened the edit (and, non-empty now, did not auto-open the library).',
    );
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement.snapshot.field_image_url === null',
      20000,
      'The empty link field snapshots as null, not as the mirror\'s form-only stub.',
    );
    $session->executeScript(<<<JS
      (() => {
        const input = document.querySelector(".daedalus-edit-link-input");
        input.value = 'https://example.com/';
        input.dispatchEvent(new Event('input', { bubbles: true }));
      })();
      JS);
    // The wrap preview rides the resolver twin: the linked bag resolves to
    // the anchor-root form with the img nested, and the morph tracks
    // identity by model key — the img is the SAME physical element keeping
    // its part class and server-derived src while the identity bag (the
    // selectable key, the editor attribution the a{$leaf} selector needs)
    // migrates onto the fresh anchor root, which wears the typed href by
    // model. Only a browser can see the whole chain (twin resolve, morph,
    // bag migration) produce the server's linked shape.
    $this->assertJsCondition(
      <<<JS
      (() => {
        const anchor = document.querySelector("a{$leaf}[href='https://example.com/']");
        const img = anchor && anchor.querySelector('img.image-block-image');
        return !!img
          && !anchor.classList.contains('image-block-image')
          && (img.getAttribute('src') || '').includes('image-1');
      })()
      JS,
      20000,
      'The wrap preview nested the same img (part class and src intact) inside the attributed anchor root carrying the typed href.',
    );
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition(
      <<<JS
      (() => {
        return window.__blueprintPosts === 2
          && !!document.querySelector("a{$leaf}[href='https://example.com/'] > img[src*='image-1']")
          && JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('https://example.com/');
      })()
      JS,
      20000,
      'Linking from empty committed the typed uri and the swap re-rendered the anchor root wrapping the img.',
    );
    // The server-rendered linked shape agrees with the preview: the class
    // is stamped literally on the img, never on the anchor root.
    $this->assertJsCondition(
      <<<JS
      (() => {
        const anchor = document.querySelector("a{$leaf}");
        return !!anchor
          && !anchor.classList.contains('image-block-image')
          && !!anchor.querySelector('img.image-block-image');
      })()
      JS,
      20000,
      'Linked server render: the img carries the part class, the anchor root does not.',
    );

    // Links are selection surfaces, not navigation: with the session armed,
    // a REAL pointer click on the rendered anchor must open the edit instead
    // of leaving the page. Then a typed uri previews the href in place
    // (identity stays on the root through the preview), and Escape restores
    // the snapshot — previewed link included — sending nothing.
    $session->executeScript("document.querySelector(\"$leaf\").scrollIntoView({ block: 'center' });");
    $session->getPage()->find('css', $leaf)->click();
    $this->assertJsCondition(
      <<<JS
      (() => {
        return window.__notNavigated === true
          && Drupal.Daedalus.Edit.CurrentlyEditingElement !== null
          && !!document.querySelector(".daedalus-edit-link-input");
      })()
      JS,
      20000,
      'Clicking the rendered link did not navigate; it opened the in-place edit.',
    );
    $session->executeScript(<<<JS
      (() => {
        const input = document.querySelector(".daedalus-edit-link-input");
        input.value = 'https://drupal.org/';
        input.dispatchEvent(new Event('input', { bubbles: true }));
      })();
      JS);
    $this->assertJsCondition(
      "document.querySelector(\"$leaf\").getAttribute('href') === 'https://drupal.org/'",
      20000,
      'Typing a uri previewed the href on the anchor root in place.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      <<<JS
      (() => {
        return Drupal.Daedalus.Edit.CurrentlyEditingElement === null
          && !!document.querySelector("a{$leaf}[href='https://example.com/']")
          && window.__blueprintPosts === 2;
      })()
      JS,
      20000,
      'Escape restored the snapshot link (previewed href reverted) and sent no mutation.',
    );

    // Unlink: clear the input and blur. The commit clears the field and the
    // swap re-renders the bare img root — the whole identity migrating back
    // off the anchor.
    $session->getPage()->find('css', $leaf)->click();
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement !== null && !!document.querySelector(".daedalus-edit-link-input")',
      20000,
      'Re-clicking the anchor root opened the edit for the unlink gesture.',
    );
    $session->executeScript(<<<JS
      (() => {
        const input = document.querySelector(".daedalus-edit-link-input");
        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
      })();
      JS);
    // The unwrap direction: the unlinked bag resolves to the img-root form
    // (the coincidence hoist) and the morph un-nests — the img element
    // continues as the root, the identity bag migrates back onto it, and
    // its part class and href-free state come from the model (classes
    // merge by token, never overwrite wholesale, which is what buried the
    // old root-swap's re-seating hack).
    $this->assertJsCondition(
      "(() => { const root = document.querySelector(\"$leaf\");"
      . " return !!root && root.matches('img.image-block-image') && !root.hasAttribute('href')"
      . " && (root.getAttribute('src') || '').includes('image-1'); })()",
      20000,
      'The unwrap preview made the img the root again: part class and src intact, no stranded href.',
    );
    $session->executeScript('Drupal.Daedalus.Edit.CurrentlyEditingElement.plugin.blur();');
    $this->assertJsCondition(
      <<<JS
      (() => {
        const root = document.querySelector("$leaf");
        return window.__blueprintPosts === 3
          && !!root && root.matches("img[src*='image-1']")
          && !JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('example.com');
      })()
      JS,
      20000,
      'The unlink committed and the swap re-rendered the bare img root.',
    );

    // Replace the image through the verb face's own entry: with the edit
    // open on a non-empty leaf (no auto-open), the chip face's media verb
    // reads "Replace image" (menuLabel over the snapshot's reference), and
    // running it opens the same modal. The new selection is another
    // immediate one-op commit whose swap delivers the new server-derived
    // src and alt, with the edit surviving into a fresh snapshot again —
    // the verb face -> modal -> mediaSelected seam, the edit open the
    // whole way.
    $session->executeScript("document.querySelector(\"$leaf\").scrollIntoView({ block: 'center' });");
    $session->getPage()->find('css', $leaf)->click();
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement !== null && !!document.querySelector(".daedalus-edit-link-input")',
      20000,
      'Re-clicking the img root opened the edit for the replace gesture.',
    );
    $replace_verb = ".daedalus-ui-frame--editing .daedalus-ui-frame__chip--primary"
      . " .daedalus-ui-frame__verbs button[aria-label='Replace image']";
    $this->assertJsCondition(
      "!!document.querySelector(\"$replace_verb\")",
      20000,
      "The open edit's chip face offers the Replace image verb.",
    );
    $session->executeScript("document.querySelector(\"$replace_verb\").click();");
    $second_checkbox = $assert_session->waitForElement(
      'css',
      ".media-library-widget-modal input[type='checkbox'][value='$second_id']",
    );
    $this->assertNotNull($second_checkbox, 'The Replace image verb opened the media library modal.');
    $session->executeScript("document.querySelector(\".media-library-widget-modal input[type='checkbox'][value='$second_id']\").click();");
    $session->executeScript($insert_selected);
    $this->assertJsCondition(
      <<<JS
      (() => {
        const root = document.querySelector("$leaf");
        return window.__blueprintPosts === 4
          && !!root && root.matches("img[src*='image-2'][alt='Second alt']")
          && Drupal.Daedalus.Edit.CurrentlyEditingElement !== null
          && Drupal.Daedalus.Edit.CurrentlyEditingElement.snapshot.field_image?.target_id === $second_id;
      })()
      JS,
      20000,
      'The replace selection committed as one op and the swap delivered the new img with the edit surviving.',
    );
    $this->pressHotkey('Escape');
    $this->assertJsCondition(
      'Drupal.Daedalus.Edit.CurrentlyEditingElement === null && window.__blueprintPosts === 4',
      20000,
      'Escape after the replace ended the edit without sending (the media commit already landed).',
    );

    // ——— The picture part is stylable in BOTH link states. ———
    // One part, one key (block:{uuid}::.image-block-image), two DOM
    // shapes: unlinked the img IS the root (the part printer's :scope.cls
    // half), linked it is a descendant of the anchor root (the .cls half).
    // Only a browser can see the whole chain agree — the YML declaration
    // accepted by the guide, the op validated against the part's border
    // group, the emitted CSS, and the class stamping — and only a browser
    // can see the point of the part: the style follows the picture across
    // the link toggle instead of silently retargeting onto the anchor.
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_style',
            key: 'block:$component_uuid::.image-block-image',
            properties: { 'border-radius': '9px' },
          }],
        })).catch((error) => {
          window.__partStyleError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__partStyleError !== undefined"
      . " || getComputedStyle(document.querySelector(\"$wrapper\")).borderTopLeftRadius === '9px'",
      20000,
      'The part style write resolved.',
    );
    $part_style_error = $session->evaluateScript('window.__partStyleError ?? null');
    $this->assertNull($part_style_error, 'Styling the img part failed: ' . ($part_style_error ?? ''));

    // Link the styled block: the root becomes the anchor, and the SAME
    // part entry must keep styling the img — now a descendant — while the
    // anchor root stays untouched (radius on the anchor clips nothing).
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_element',
            element: '$component_uuid',
            properties: { field_image_url: { uri: 'https://example.com/' } },
          }],
        })).catch((error) => {
          window.__partStyleError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        if (window.__partStyleError !== undefined) {
          return true;
        }
        const anchor = document.querySelector("a$wrapper");
        if (!anchor) {
          return false;
        }
        const img = anchor.querySelector('img.image-block-image');
        return !!img
          && getComputedStyle(img).borderTopLeftRadius === '9px'
          && getComputedStyle(anchor).borderTopLeftRadius !== '9px';
      })()
      JS,
      20000,
      'Linked, the part style follows the img into the anchor and does not retarget onto the root.',
    );
    $part_style_error = $session->evaluateScript('window.__partStyleError ?? null');
    $this->assertNull($part_style_error, 'Linking the styled block failed: ' . ($part_style_error ?? ''));

    // ——— The frame hugs the picture: the declared defaults geometry. ———
    // The block is linked inside the container, so the anchor is the root.
    // Select it with the Selector (the frame serves both selection grammars;
    // this is the plain user gesture): the primary stroke paints the root's
    // own getBoundingClientRect(), so if the declaration's box facts did
    // not land (display: block; width: fit-content from the compiled
    // defaults stylesheet, reached through the stamped scope classes inside
    // the token scope) the anchor is inline and the stroke is a font strip
    // under the picture instead of the picture. The helper waits for the
    // stroke, the root and the picture to agree, then asserts each side
    // within a pixel with the measurements in the message. The root
    // equalling the picture in BOTH dimensions is what tells fit-content
    // from a full-width block box.
    $this->activateTool('selector');
    $session->executeScript("Drupal.Daedalus.Selection.select('block:$component_uuid');");
    $this->assertFrameHugsPicture($component_uuid, 'linked (anchor root)');

    // Unlink again (the fallback tier below asserts on the bare img root):
    // the style lands back on the part-is-root shape, closing the round
    // trip.
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_element',
            element: '$component_uuid',
            properties: { field_image_url: null },
          }],
        })).catch((error) => {
          window.__partStyleError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        if (window.__partStyleError !== undefined) {
          return true;
        }
        const root = document.querySelector("$wrapper");
        return !!root && root.matches('img.image-block-image')
          && getComputedStyle(root).borderTopLeftRadius === '9px';
      })()
      JS,
      20000,
      'Unlinked again, the part style is back on the img root: the picture stayed the target through the whole toggle.',
    );
    $part_style_error = $session->evaluateScript('window.__partStyleError ?? null');
    $this->assertNull($part_style_error, 'Unlinking the styled block failed: ' . ($part_style_error ?? ''));

    // The unlink swapped the root (the img IS the root again): the
    // selection survives the swap (the tracker re-resolves by key) and the
    // frame measures the img's box, which the same declared defaults make
    // a block box (the part entry) and the same root entry shrink-wraps.
    $this->assertFrameHugsPicture($component_uuid, 'unlinked (img root)');

    // ——— Phase 2: the picture part holds through both media forms. ———
    // The three picture controls are element-bound to .image-block-image,
    // and an element-bound control is offered while its bound part exists
    // in the canvas DOM (daedalus_stylish offered()'s presence gate over the layout
    // facts). Vector media used to be the honest absent case; under the
    // forms model the part MATCHES its svg form instead (img/Raster on
    // raster, svg/Vector on vector), so presence — and the offer — holds
    // through the swap in both directions. The root-bound Spacing control
    // rides along in every panel read as the liveness anchor.
    $session->executeScript(<<<JS
      Drupal.Daedalus.Selection.select('block:$component_uuid');
      Drupal.Daedalus.SidebarManager.openSidebar('daedalus_stylish_inspector');
      JS);
    // Presence is asserted by widget class: a multi-row control's label
    // (Corners and border) appears nowhere in the DOM now that sections
    // replaced per-control headers, and a single-row control's label is
    // its row label. The class carries the WIDGET plugin id with hyphens
    // (daedalus_stylish widget-classes.js), so the restated width control is
    // --width, not --image-width.
    $controls_offered = "['width', 'corners-border', 'elevation', 'spacing']"
      . ".every((id) => !!document.querySelector('.daedalus-stylish-inspector__widget--' + id))";
    $this->assertJsCondition(
      $controls_offered,
      20000,
      'Raster media: the three element-bound picture controls and the root-bound Spacing control are all offered.',
    );

    // Swap to vector media: the inliner returns the sanitized root svg's
    // attributes plus inner markup, the renderer builds the svg like any
    // other root element, and the hoisted attributes bag stamps the part
    // class onto it — the svg IS the picture, unwrapped, part-is-root.
    $svg_markup = '<?xml version="1.0" encoding="UTF-8"?>'
      . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
      . '<rect width="10" height="10" fill="currentColor"/>'
      . '</svg>';
    file_put_contents('public://presence-icon.svg', $svg_markup);
    $svg_file = File::create(['uri' => 'public://presence-icon.svg']);
    $svg_file->setPermanent();
    $svg_file->save();
    $svg_media = Media::create([
      'bundle' => 'vector_image',
      'name' => 'Vector media image',
      'field_media_svg' => [
        'target_id' => $svg_file->id(),
        'alt' => 'Vector alt',
      ],
    ]);
    $svg_media->save();
    $svg_id = (int) $svg_media->id();
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_element',
            element: '$component_uuid',
            properties: { field_image: { target_id: $svg_id } },
          }],
        })).catch((error) => {
          window.__presenceError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      <<<JS
      (() => {
        if (window.__presenceError !== undefined) {
          return true;
        }
        const root = document.querySelector("svg{$leaf}.image-block-image");
        return !!root
          && root.getAttribute('viewBox') === '0 0 10 10'
          && root.getAttribute('width') === '10'
          && root.getAttribute('aria-label') === 'Vector alt'
          && !!root.querySelector('rect')
          && getComputedStyle(root).borderTopLeftRadius === '9px';
      })()
      JS,
      20000,
      'The svg swap resolved: the inline svg IS the root, wearing the hoisted part class, its viewBox and content, the derived intrinsic size, the alt as aria-label, and the stored part style.',
    );
    $presence_error = $session->evaluateScript('window.__presenceError ?? null');
    $this->assertNull($presence_error, 'Swapping to vector media failed: ' . ($presence_error ?? ''));
    $this->assertJsCondition(
      $controls_offered,
      20000,
      'Vector media: the element-bound picture controls stay offered — the part matched its svg form, so presence holds.',
    );

    // Swap back to raster: the part matches its img form again and the
    // stored part style lands back on the part-is-root img — one part
    // entry styling both forms, nothing orphaned across the detour.
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [{
            op: 'update_element',
            element: '$component_uuid',
            properties: { field_image: { target_id: $second_id } },
          }],
        })).catch((error) => {
          window.__presenceBackError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__presenceBackError !== undefined"
      . " || (() => { const root = document.querySelector(\"$leaf\");"
      . " return !!root && root.matches(\"img.image-block-image[src*='image-2']\")"
      . " && getComputedStyle(root).borderTopLeftRadius === '9px'; })()",
      20000,
      'The raster swap-back resolved: the img root returned with the part class and the stored 9px radius.',
    );
    $presence_error = $session->evaluateScript('window.__presenceBackError ?? null');
    $this->assertNull($presence_error, 'Swapping back to raster media failed: ' . ($presence_error ?? ''));
    $this->assertJsCondition(
      $controls_offered,
      20000,
      'Raster again: all four controls still offered after the round trip.',
    );

    // ——— The fallback tier. ———
    // The layer gate is the configured widget: anything other than
    // media_library_widget renders NO blueprint editor attribution — the
    // form path's lookup attributes hoist onto the collapsed root instead
    // (the hidden field renders no per-item wrappers to carry them), and a
    // click edits the field through the real entity form in the sidebar.
    // The property segment is 'value', not target_id: configurable fields
    // have no getMainPropertyName() for the render-side stamper to consult,
    // and the form-side stampers hardcode 'value', so 'value' is the
    // grammar's de facto property for every configurable field.
    \Drupal::service('entity_display.repository')
      ->getFormDisplay('block_content', 'image')
      ->setComponent('field_image', ['type' => 'entity_reference_autocomplete'])
      ->save();
    // The tier gate runs in preprocess, whose output render-caches: flush so
    // the reload below re-renders the block under the flipped widget.
    drupal_flush_all_caches();

    $this->enterEditModeViaCookies($node->toUrl(), 'selector');
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted on the fallback-tier reload.',
    );

    // The staged block re-rendered from tempstore on the fallback tier: the
    // same collapsed img root, now carrying the form path's two required
    // lookup attributes (plus the page-element id the sidebar form uses to
    // find the page element) and NO blueprint editor attribution.
    $fallback_root = $assert_session->waitForElement('css', "img{$wrapper}[data-daedalus-edit-id]");
    $this->assertNotNull($fallback_root, 'The staged image block re-rendered as a collapsed img root on the fallback tier.');
    $this->assertTrue(
      $session->evaluateScript(<<<JS
        (() => {
          const root = document.querySelector("$wrapper");
          const id = root.getAttribute('data-daedalus-edit-id') || '';
          const valueWrapper = root.getAttribute('data-daedalus-edit-field-value-wrapper') || '';
          return !root.hasAttribute('data-daedalus-edit-editor')
            && id.includes('::field_image::') && id.endsWith('::value')
            && valueWrapper.endsWith('::field_image::value')
            && (root.getAttribute('data-daedalus-edit-page-element-id') || '').endsWith('::value')
            && root.matches("img[src*='image-2']");
        })()
        JS),
      'The fallback tier hoisted the form-path lookup attributes (property segment "value") onto the root and stamped no blueprint editor.',
    );

    // Clicking the collapsed root on this tier opens the FORM path: the real
    // entity form loads in the sidebar with the configured autocomplete
    // widget revealed. The click is synthetic into the session's document
    // -level handler, the same path as a real pointer click.
    $this->activateTool('edit');
    $session->executeScript(<<<JS
      document.querySelector("$wrapper").scrollIntoView({ block: 'center' });
      document.querySelector("$wrapper")
        .dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
      JS);
    $this->assertJsCondition(
      "Drupal.Daedalus.Edit.CurrentlyEditingElement !== null"
      . " && Drupal.Daedalus.Edit.CurrentlyEditingElement.info.fieldName === 'field_image'",
      20000,
      'Clicking the collapsed root on the fallback tier opened the field_image form edit.',
    );
    // The honest panel: the real entity form loaded in the sidebar and the
    // configured autocomplete widget revealed in-flow (the inline block's
    // form nests the field, so the widget is resolved through the editing
    // element's own form-item wrapper, not by input name).
    $this->assertJsCondition(
      <<<JS
      (() => {
        try {
          const editing = Drupal.Daedalus.Edit.CurrentlyEditingElement;
          const wrapper = editing?.getFormItemWrapper?.();
          return !!wrapper
            && !wrapper.classList.contains('daedalus-edit-hidden')
            && !!wrapper.closest('[data-sidebar-type="daedalus_edit_form"]')
            && !!wrapper.querySelector('input.form-autocomplete');
        }
        catch (error) {
          return false;
        }
      })()
      JS,
      20000,
      'The sidebar entity form revealed the configured autocomplete widget in-flow.',
    );
  }

  /**
   * Asserts the selection frame's rect equals the picture's within a pixel.
   *
   * Measures the selected block's root (the element wearing the selectable
   * key: the anchor when linked, the img when not), its picture (the
   * .image-block-image part, the root itself when the part is the root),
   * and the frame's primary stroke. The frame paints each animation frame
   * from the root's own rect, so the wait lets the stroke catch up with a
   * fresh selection or a swapped root before the sides are compared; the
   * comparison then runs in PHP so a failure names the side and carries
   * every measurement. Also pins what makes the box: the root's computed
   * display, the declared-defaults scope stamps, and the token scope
   * ancestor the compiled rules are scoped under.
   *
   * @param string $component_uuid
   *   The block's component uuid (its selectable key is block:{uuid}).
   * @param string $state
   *   A label for the link state, for the messages.
   */
  private function assertFrameHugsPicture(string $component_uuid, string $state): void {
    $session = $this->getSession();
    $measure = <<<JS
      (() => {
        const root = document.querySelector("[data-selectable='block:$component_uuid']");
        const stroke = document.querySelector('.daedalus-ui-frame__stroke--primary');
        const picture = root
          ? (root.matches('.image-block-image') ? root : root.querySelector('.image-block-image'))
          : null;
        const rect = (element) => {
          const box = element.getBoundingClientRect();
          return { left: box.left, top: box.top, width: box.width, height: box.height };
        };
        return {
          selected: Drupal.Daedalus.Selection.getSelected(),
          strokeHidden: !stroke || stroke.hidden,
          root: root ? rect(root) : null,
          picture: picture ? rect(picture) : null,
          stroke: stroke ? rect(stroke) : null,
          rootTag: root ? root.tagName.toLowerCase() : null,
          display: root ? getComputedStyle(root).display : null,
          stamps: root
            ? [...root.classList].filter((name) => name.startsWith('daedalus-stylish-defaults--')).sort()
            : [],
          inTokenScope: !!(root && root.closest('.daedalus-stylish-token-scope')),
        };
      })()
      JS;
    $session->wait(20000, <<<JS
      (() => {
        const measured = $measure;
        if (!measured.root || !measured.picture || !measured.stroke || measured.strokeHidden) {
          return false;
        }
        const within = (a, b) => ['left', 'top', 'width', 'height']
          .every((side) => Math.abs(a[side] - b[side]) <= 1);
        return within(measured.stroke, measured.picture) && within(measured.root, measured.picture);
      })()
      JS);
    $measured = json_decode((string) $session->evaluateScript("JSON.stringify($measure)"), TRUE);
    $detail = "[$state] " . json_encode($measured);

    $this->assertSame("block:$component_uuid", $measured['selected'], "The image block is the selection. $detail");
    $this->assertNotNull($measured['root'], "The selected root is in the canvas. $detail");
    $this->assertNotNull($measured['picture'], "The picture part is in the canvas. $detail");
    $this->assertFalse($measured['strokeHidden'], "The frame's primary stroke is painted. $detail");
    // A real picture box, so two degenerate boxes cannot agree by accident;
    // the sliver this leg exists for is a font strip about 20px tall.
    $this->assertGreaterThan(30, $measured['picture']['height'], "The picture has a real box. $detail");
    foreach (['left', 'top', 'width', 'height'] as $side) {
      $this->assertEqualsWithDelta(
        $measured['picture'][$side],
        $measured['stroke'][$side],
        1.0,
        "The frame's $side equals the picture's within a pixel. $detail",
      );
      $this->assertEqualsWithDelta(
        $measured['picture'][$side],
        $measured['root'][$side],
        1.0,
        "The root's $side equals the picture's within a pixel (the root shrink-wraps the picture). $detail",
      );
    }
    $this->assertSame('block', $measured['display'], "The root is a block box from the declared defaults. $detail");
    $this->assertSame(
      ['daedalus-stylish-defaults--block_type-image', 'daedalus-stylish-defaults--kind-block'],
      $measured['stamps'],
      "The root wears the declared-defaults scope stamps. $detail",
    );
    $this->assertTrue($measured['inTokenScope'], "The root renders inside the token scope the compiled rules are scoped under. $detail");
  }

}
