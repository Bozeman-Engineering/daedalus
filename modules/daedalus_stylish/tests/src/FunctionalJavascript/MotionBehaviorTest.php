<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_stylish\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\daedalus\Traits\EditModeTrait;
use Drupal\Tests\daedalus\Traits\DaedalusFixtureTrait;
use Drupal\Tests\daedalus\Traits\DesignSystemActivationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Drives the motion capability (animations as a material) on a real page.
 *
 * The capability under test: a named @keyframes block is a MATERIAL of
 * the animation kind, referenced by the prefixed ident `motion-{name}`
 * inside a value, delivered as a compiled keyframes file (and, in the
 * editing document, re-printed live into a preview style tag), worn in
 * basic mode through the Animation variant axis, and honoured under a
 * reduced-motion preference by the wearer's OWN unset. Every model here
 * is node-tested (value-space, animations-model, keyframes-names, the
 * printer twin) and every server contract is kernel-pinned (validation,
 * the roster's records, the stylesheet, the importer, the shipped
 * family); every step here locks a WIRING seam that only exists where
 * the guide payload, the widgets, the op spine, the materials store and
 * the browser's animation engine meet:
 * - The advanced Motion row wears a shipped animation (seam: guide
 *   payload -> the Motion section by data -> the animation_name value
 *   space -> magnetic capture on settle -> update_style -> mutation
 *   response -> the COMPILED keyframes file actually applying on the
 *   canvas). The row is a detented free input whose stop picker (the
 *   shared menu behind the chevron) offers the roster (a select only
 *   under an allowed: constraint), so the pick is the spelling settling
 *   through the row; the assertion is not the
 *   map entry alone but `getAnimations()` on the canvas element being
 *   non-empty with the computed animation-name equal to the spelling:
 *   the ident the server accepted is the ident the file declares.
 * - A mint reaches the completion source and the preview tag without a
 *   reload (seam: the Materials > Animations mint gesture -> save_material
 *   -> the response's materials delta -> the store -> (a) the code view's
 *   island completing the fresh spelling, (b) the leader source's
 *   animation preview stamp carrying its @keyframes). The island then
 *   commits the completed spelling and the canvas animates with the
 *   MINTED keyframes, which in this document can only come from the
 *   preview tag (the page's link to the compiled file predates the mint):
 *   the delta-to-stamp seam applying for real, not just printing.
 * - Basic mode wears an entrance and honours reduced motion (seam: the
 *   wear bar's add row -> the variant picker's Animation group ->
 *   apply_variant -> the worn Animation row -> the variant's delivered
 *   CSS on the canvas -> the Motion chip's media emulation rewriting
 *   the variant's own reduced-motion condition in the CSSOM). The block
 *   is IN VIEW when it wears the entrance, so the pin requires the Rise
 *   animation to run or finish at once, never to stay paused: the
 *   shipped activation range spells its end (`entry 20% exit 100%`),
 *   and a lone start would hold an in-view element for good (its range
 *   ends at `entry 100%`, already behind it). On the polyfill path the
 *   runtime stamps an in-view element regardless, so that pin only bites
 *   natively (Chromium 146+). The
 *   negative rides along: an element wearing a direct animation-name
 *   with no unset of its own keeps animating under the same emulation,
 *   which is the decision that the SETTER carries the unset.
 * - An entrance below the fold waits to be seen (seam: the shipped
 *   variant's trigger pair -> the printer's markers and `@supports not`
 *   hold -> the motion-triggers runtime binding the re-rendered element
 *   (or the browser's native trigger) -> the IntersectionObserver firing
 *   on scroll -> the stamp lifting the hold -> the animation engine).
 *   The observable is `getAnimations()` on the canvas element: paused
 *   below the fold, running or finished once scrolled in, and a later
 *   start time after Replay from the Motion trigger control and again
 *   after Replay from the advanced Motion section's header (the same
 *   affordance in advanced, gated on the effective animation-trigger at
 *   the pose: a block with no trigger to re-arm shows no pill). The run
 *   logs `CSS.supports('animation-trigger', 'none')` so it says which
 *   path (native or polyfill) it locked.
 * - A wear row renders POSED and never retargets (seam: the State chip's
 *   force state -> the vector-derived editing compound -> wearAtPose
 *   over the worn variant's own blocks -> the row's sub-line -> the
 *   doorway opening the editing session while the canvas stays posed).
 *   Fade in sets nothing at Hover; Lift worn on the hover axis lists its
 *   hover block and the forced state shows the 4px lift on the canvas,
 *   while the block's own map entry gains no hover block.
 * Satellite parity stays smoke-only: representative-of-class is already
 * locked by the gradient scenario over the same relay seams.
 *
 * The fixture is DaedalusFixtureTrait (the daedalus recipe's site as
 * daedalus_editor: strict posture, so every commit below passes the
 * closed world, and the animation reference check is law). The recipe's
 * install sweep imports daedalus_stylish's shipped animations (fade_in, rise, ...)
 * and the block animation-axis variants (block_fade_in, block_rise, ...),
 * so the roster arrives populated with no fixture layer. One fixture, one
 * scenario: the site install dominates the run cost, so all behavior
 * lives in the single test method.
 *
 * @group daedalus_stylish
 */
#[Group('daedalus_stylish')]
#[RunTestsInSeparateProcesses]
class MotionBehaviorTest extends WebDriverTestBase {

  use DesignSystemActivationTrait;
  use EditModeTrait;
  use DaedalusFixtureTrait {
    setUp as daedalusSetUp;
  }

  /**
   * {@inheritdoc}
   *
   * The recipe's olivero ships open, and the animation reference check
   * below is law only under strict (a permissive site passes an unresolved
   * keyframes name through), so after the fixture install the scenario
   * puts the recipe's system at strict, the way a site builder picking the
   * mode does. The strict reference system (guide_example) is not the
   * route here: its package binds animation-duration to a duration scale,
   * and the scenario's literal durations are what its timing claims hold
   * still with.
   */
  protected function setUp(): void {
    $this->daedalusSetUp();
    $this->enforce('strict');
  }

  /**
   * {@inheritdoc}
   *
   * Core's WebDriverTestBase installs css_disable_transitions_test by
   * default (`* { animation: none !important }`) so ordinary scenarios
   * never wait on motion. This scenario IS the motion: every canvas
   * assertion below reads a computed animation-name and `getAnimations()`,
   * which that reset would silence to `none` and empty on every element.
   */
  protected $disableCssAnimations = FALSE;

  /**
   * The advanced Motion section's Animation name row input.
   *
   * The row is the free input DETENTED by the roster (its stop picker lists the
   * animations), in every enforcement mode; only an `allowed:` constraint
   * closes it to a select, and the recipe's block kind constrains nothing on
   * motion.
   */
  private const ANIMATION_NAME_INPUT = "#daedalus_stylish_inspector details.daedalus-stylish-inspector__section input[aria-label='Animation name']";

  /**
   * The leader's animation preview tag.
   *
   * The server's workspace inline tag shares the attribute (daedalus_stylish's
   * page_top element), so readers take the LAST one: the client's tag is
   * appended to the body and wins by document order either way.
   */
  private const PREVIEW_TAG_TEXT = "(() => { const tags = document.querySelectorAll('style[data-daedalus-stylish-animation-preview]'); return tags.length ? tags[tags.length - 1].textContent : ''; })()";

  /**
   * Opens the Styles inspector when the cookie entry rendered it closed.
   */
  private function openInspector(): void {
    $session = $this->getSession();
    $this->assertSession()->waitForElement('css', '#daedalus_stylish_inspector');
    $session->executeScript(<<<JS
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
      'The Styles inspector is open.',
    );
  }

  /**
   * Places one formatted_text block through the op spine.
   *
   * @param string $anchor
   *   The anchor uuid.
   * @param string $placement
   *   Either 'inside' (a section anchor) or 'after' (a sibling anchor).
   * @param string $block_uuid
   *   The new block's uuid.
   * @param string $text
   *   The block's paragraph text.
   * @param string $sentinel
   *   A per-call window variable name for the placement error.
   */
  private function placeTextBlock(string $anchor, string $placement, string $block_uuid, string $text, string $sentinel): void {
    $session = $this->getSession();
    $value = json_encode("<p>$text</p>");
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            {
              op: 'add_element',
              uuid: '$block_uuid',
              block_type: 'formatted_text',
              properties: {
                field_formatted_text_text: {
                  value: $value,
                  format: 'daedalus_edit_formatted_text',
                },
              },
              location: { anchor: '$anchor', placement: '$placement' },
            },
          ],
        })).catch((error) => {
          window.$sentinel = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.$sentinel !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$block_uuid')",
      20000,
      'The block placement resolved.',
    );
    $placement_error = $session->evaluateScript("window.$sentinel ?? null");
    $this->assertNull($placement_error, 'Block placement failed: ' . ($placement_error ?? ''));
    $this->assertNotNull(
      $this->assertSession()->waitForElement('css', "[data-selectable='block:$block_uuid']"),
      'The formatted text block rendered on the canvas.',
    );
  }

  /**
   * Commits style properties on a key through the op spine.
   *
   * The way any non-panel consumer writes (an update_style op through
   * the blueprint facade), for the values a step needs in place before
   * the gesture under test runs.
   */
  private function commitStyle(string $key, array $properties, string $sentinel): void {
    $session = $this->getSession();
    $json = json_encode($properties);
    $session->executeScript(<<<JS
      (() => {
        const Blueprint = Drupal.Daedalus.Blueprint;
        Blueprint.mutate(Object.assign({}, Blueprint.rootContext(), {
          operations: [
            { op: 'update_style', key: '$key', properties: $json },
          ],
        })).catch((error) => {
          window.$sentinel = error.message;
        });
      })();
      JS);
    $checks = [];
    foreach ($properties as $property => $value) {
      $checks[] = $this->mapJs($key) . '?.default?.[' . json_encode($property) . '] === ' . json_encode($value);
    }
    $this->assertJsCondition(
      "window.$sentinel !== undefined || (" . implode(' && ', $checks) . ')',
      20000,
      'The style commit resolved.',
    );
    $op_error = $session->evaluateScript("window.$sentinel ?? null");
    $this->assertNull($op_error, 'Style commit failed: ' . ($op_error ?? ''));
  }

  /**
   * A JS expression for a key's entry in the blueprint mirror's style map.
   */
  private function mapJs(string $key): string {
    return '(Drupal.Daedalus.Blueprint.getBlueprint()?.fields?.daedalus_stylish_styles ?? {})[' . json_encode($key) . ']';
  }

  /**
   * A JS expression for an advanced section's <details> by its label.
   *
   * The label is the summary's own span: the summary may also carry a
   * header action (the Motion section's Replay pill).
   */
  private function sectionJs(string $label): string {
    return "Array.from(document.querySelectorAll('#daedalus_stylish_inspector details.daedalus-stylish-inspector__section'))"
      . ".find((details) => details.querySelector(':scope > summary .daedalus-stylish-inspector__section-label')?.textContent.trim() === " . json_encode($label) . ')';
  }

  /**
   * Clicks a panel element for real, scrolled to center so the pointer lands.
   */
  private function clickPanel(string $selector): void {
    $element = $this->assertSession()->waitForElement('css', $selector);
    $this->assertNotNull($element, sprintf('The panel element %s is present to click.', $selector));
    $this->getSession()->executeScript(
      'document.querySelector(' . json_encode($selector) . ").scrollIntoView({ block: 'center' });"
    );
    $element->click();
  }

  /**
   * Clicks a panel element found by XPath, scrolled into the viewport.
   */
  private function clickPanelXpath(string $xpath): void {
    $element = $this->assertSession()->waitForElement('xpath', $xpath);
    $this->assertNotNull($element, sprintf('The panel element %s is present to click.', $xpath));
    $this->getSession()->executeScript(
      'document.evaluate(' . json_encode($xpath) . ', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null)'
      . ".singleNodeValue.scrollIntoView({ block: 'center' });"
    );
    $element->click();
  }

  /**
   * Flips the inspector's mode through the mode segment's real button.
   */
  private function switchMode(string $mode): void {
    $this->clickPanelXpath('//div[contains(@class, "daedalus-stylish-inspector__ui-mode")]/button[normalize-space() = "' . $mode . '"]');
    $this->assertJsCondition(
      "(() => { const active = document.querySelector('.daedalus-stylish-inspector__ui-mode button[aria-pressed=\"true\"]');"
      . " return !!active && active.textContent.trim() === " . json_encode($mode) . '; })()',
      20000,
      sprintf('The inspector flipped to %s mode.', $mode),
    );
  }

  /**
   * The xpath of a worn wear-bar row by its axis label.
   */
  private function wornRowXpath(string $axis): string {
    return '//div[contains(@class, "daedalus-stylish-wear-bar__row")][.//span[contains(@class, "daedalus-stylish-wear-bar__label")][normalize-space() = "' . $axis . '"]]';
  }

  /**
   * The xpath of a variant picker option by its label under its axis group.
   *
   * The group's aria-label is the axis label (the picker renders headed
   * groups whenever the vocabulary has more than one axis).
   */
  private function pickerOptionXpath(string $axis, string $label): string {
    return '//div[contains(@class, "daedalus-stylish-variant-picker__group")][@aria-label = "' . $axis . '"]'
      . '//div[@role = "option"][.//span[contains(@class, "daedalus-stylish-variant-picker__label")][normalize-space() = "' . $label . '"]]';
  }

  /**
   * Applies a variant through the wear bar's one apply gesture.
   *
   * The add row ("Apply variant") opens the variant picker; the option is
   * clicked for real, which closes the picker and commits apply_variant.
   *
   * @param string $axis
   *   The axis label heading the option's group.
   * @param string $label
   *   The variant's label.
   */
  private function pickVariant(string $axis, string $label): void {
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-wear-bar__add');
    $this->clickPanelXpath($this->pickerOptionXpath($axis, $label));
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker')",
      20000,
      sprintf('The pick of %s closed the variant picker.', $label),
    );
  }

  /**
   * Types into a Preact-controlled panel input, without settling it.
   *
   * The native value setter plus a real 'input' event is the same wiring
   * a keystroke (or a stop-menu pick) runs. The settle is dispatched in a
   * later call (blurPanel), like a real user's separate tick.
   */
  private function typeIntoPanel(string $selector, string $text): void {
    $this->getSession()->executeScript(
      '(() => {'
      . ' const input = document.querySelector(' . json_encode($selector) . ');'
      . " const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;"
      . ' setter.call(input, ' . json_encode($text) . ');'
      . " input.dispatchEvent(new Event('input', { bubbles: true }));"
      . ' })();'
    );
  }

  /**
   * Settles a panel input (blur), in its own tick per typeIntoPanel.
   */
  private function blurPanel(string $selector): void {
    $this->getSession()->executeScript(
      'document.querySelector(' . json_encode($selector) . ").dispatchEvent(new FocusEvent('blur'));"
    );
  }

  /**
   * A JS expression for a canvas element's computed animation-name.
   */
  private function animationNameJs(string $canvas): string {
    return 'getComputedStyle(document.querySelector(' . json_encode($canvas) . ')).animationName';
  }

  /**
   * A JS expression for a canvas element's running animation count.
   */
  private function animationCountJs(string $canvas): string {
    return 'document.querySelector(' . json_encode($canvas) . ').getAnimations().length';
  }

  /**
   * A JS expression listing a canvas element's animations, as evidence.
   *
   * Type, name (or transition property), play state and the runtime's
   * stamp: the facts a failed "nothing animates" assertion needs.
   */
  private function animationsDumpJs(string $canvas): string {
    return '(() => { const el = document.querySelector(' . json_encode($canvas) . ');'
      . ' return JSON.stringify(el.getAnimations().map((animation) => ({'
      . ' type: animation.constructor.name, name: animation.animationName ?? animation.transitionProperty ?? null,'
      . ' state: animation.playState, currentTime: animation.currentTime,'
      . " stamped: el.hasAttribute('data-daedalus-stylish-triggered'), computedName: getComputedStyle(el).animationName,"
      . ' }))); })()';
  }

  /**
   * The motion scenario: the advanced row, the mint relay, the worn axis.
   *
   * The scripted scenario, in order:
   * - Fixture: a page node, Edit Mode via the cookie path, one section
   *   with one formatted_text block (A) through the op spine, the
   *   animation timing longhands (a long, infinite run, so the animation
   *   under test stays current for `getAnimations()`) committed on A
   *   through an update_style op, the inspector opened, A selected, the
   *   inspector flipped to Advanced through the real mode button.
   * - The advanced Motion row: the Motion section arrives open (it carries the
   *   committed timings), its Animation name input is detented by the roster
   *   (the stop picker lists the shipped animations by name beside their
   *   spelling); picking `Fade in` (its spelling settling through the input)
   *   commits `animation-name: motion-fade_in` to the map, and the canvas block
   *   runs it: computed animation-name is the spelling and `getAnimations()` is
   *   non-empty.
   * - The mint relay: on the Materials surface's Animations section,
   *   "+ New animation" opens the mint card, naming it `Wiggle` and
   *   saving lands the save_material op; without a reload the store holds
   *   the record, the row lists it, the preview tag carries `@keyframes
   *   motion-wiggle`. Back on the Elements surface in code mode, A's
   *   island wakes on a real click; retyping its animation-name value as
   *   `motion-w` opens the completion whose one option is the fresh
   *   spelling; accepting it and settling (a canvas click blurs the
   *   island) commits `animation-name: motion-wiggle`, and the canvas
   *   animates with the MINTED keyframes: computed animation-name is the
   *   fresh spelling and `getAnimations()` is non-empty although the
   *   page's compiled keyframes link predates the mint.
   * - The worn axis: a second block (B) is placed and selected in Basic
   *   mode; the wear bar renders no Animation row (nothing worn) and its
   *   add row opens the variant picker, whose Animation group offers the
   *   shipped family by label; picking `Rise` commits apply_variant (B's
   *   map entry wears block_rise, the Animation row appears reading Rise),
   *   the canvas B runs `motion-rise`. The Motion chip's
   *   menu poses the canvas at Reduced: the vector stamps the document,
   *   the emulation rewrites the variant's own reduced-motion condition,
   *   and B's computed animation-name is `none` with no animation left,
   *   while A (a direct wear without an unset of its own) keeps running.
   * - Below the fold: the Motion chip un-poses to Auto; a 3000px margin
   *   under B pushes a third block (C) out of view; C wears Rise through
   *   the picker and its entrance is HELD (`getAnimations()`
   *   carries motion-rise at playState paused, no stamp); scrolled into
   *   view it runs (running or finished; on the polyfill path the runtime
   *   stamped it); Replay in the Motion trigger control re-arms it (a
   *   later Animation.startTime; on the polyfill path the stamp went off
   *   then on again); flipped to Advanced, the Motion section's header
   *   carries the Replay pill for C (its worn entrance binds
   *   animation-trigger) and a click re-arms it once more, while A's
   *   Motion section (a direct animation-name, no trigger) carries no
   *   pill; the inspector flips back to Basic.
   * - Posed wear rows: B swaps its wear to Fade in (the picker's same-axis
   *   pick replaces Rise in place); the State chip poses Hover (the forced
   *   state on B's canvas element); the Animation row's sub-line reads
   *   "Fade in sets nothing at Hover"; Lift picked under the picker's Hover
   *   group lists `transform: translateY(-4px)` in the Hover row's sub-line and
   *   the canvas shows the lift as computed transform, while B's own map
   *   entry gains no hover block; clicking the sub-line opens the editing
   *   session on Lift with the canvas still posed at Hover.
   * - Staged, not committed: the saved node holds no style map, and the
   *   whole scenario ran in one document.
   */
  public function testMotionCapabilityScenario(): void {
    $this->loginAsEditor();

    $session = $this->getSession();
    $assert_session = $this->assertSession();

    // The scenario runs at the strict posture setUp put the recipe's
    // system in: the animation reference check is law, so every commit
    // below is exercised against the closed world (and the mint proves
    // authoring stays open).
    $this->assertSame(
      'strict',
      \Drupal::service('daedalus_stylish.style_guide')->enforcement(),
      'The scenario put the style guide in strict enforcement: it runs against the closed world.',
    );

    // The roster the scenario spends: the install sweep imported the
    // shipped animations and the block family, so the client guide payload
    // carries them and the compiled keyframes file declares them.
    $animation_storage = \Drupal::entityTypeManager()->getStorage('daedalus_animation');
    $this->assertNotEmpty(
      $animation_storage->loadByProperties(['machine_name' => 'fade_in']),
      'The recipe install imported the shipped fade_in animation.',
    );
    $this->assertNotEmpty(
      \Drupal::entityTypeManager()->getStorage('daedalus_variant')->loadByProperties(['machine_name' => 'block_rise']),
      'The recipe install imported the shipped block_rise variant.',
    );

    // A real Layout Builder page: the recipe enables styling for the
    // page bundle, so its daedalus_stylish_styles field hosts the style map.
    $node = Node::create([
      'type' => 'page',
      'title' => 'Motion behavior page',
    ]);
    $node->save();
    $nid = (int) $node->id();

    $this->drupalGet($node->toUrl());

    // A wide window: the editing compound follows the measured stage
    // width, and a squeezed stage would land every write condition-keyed
    // under `narrow` instead of `default`.
    $session->resizeWindow(1920, 1200);

    $this->enterEditModeViaCookies($node->toUrl(), 'selector');
    $this->assertJsCondition(
      'window.Drupal && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()',
      20000,
      'The blueprint mirror booted.',
    );

    // The reload sentinel: every round trip below must happen in THIS
    // document (the "without reload" half of every seam).
    $session->executeScript('window.__sameDocument = true;');

    // Which trigger path this browser exercises: Chromium 146+ honours
    // the Animation Triggers draft natively (the animation waits
    // `paused` on the trigger itself); an older one takes the printer's
    // `@supports not` hold plus the runtime's stamp. Every observable
    // below is the same either way. The run can only SAY which path it
    // locked through its assertion messages: core's phpunit.xml.dist is
    // strict about output (risky, and risky fails) and the parent of a
    // separate-process test turns anything on STDERR into an error, so
    // the path rides every in-view assertion's message instead.
    $native_triggers = (bool) $session->evaluateScript("CSS.supports('animation-trigger', 'none')");
    $path_note = sprintf(
      " [CSS.supports('animation-trigger', 'none') = %s: %s path on %s]",
      $native_triggers ? 'true' : 'false',
      $native_triggers ? 'native' : 'polyfill',
      (string) $session->evaluateScript('navigator.userAgent'),
    );

    // A section and block A through the op spine.
    $uuid_service = \Drupal::service('uuid');
    $section_uuid = $uuid_service->generate();
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
          ],
        })).catch((error) => {
          window.__sectionError = error.message;
        });
      })();
      JS);
    $this->assertJsCondition(
      "window.__sectionError !== undefined"
      . " || JSON.stringify(Drupal.Daedalus.Blueprint.getBlueprint()).includes('$section_uuid')",
      20000,
      'The section placement resolved.',
    );
    $section_error = $session->evaluateScript('window.__sectionError ?? null');
    $this->assertNull($section_error, 'Section placement failed: ' . ($section_error ?? ''));

    $block_a_uuid = $uuid_service->generate();
    $this->placeTextBlock($section_uuid, 'inside', $block_a_uuid, 'Motion canvas text', '__placementErrorA');
    $block_a_key = "block:$block_a_uuid";
    $canvas_a = "[data-selectable='$block_a_key']";
    $entry_a = $this->mapJs($block_a_key);

    // The timing longhands, written the way any non-panel consumer writes
    // (an update_style op): a long infinite run keeps whatever
    // animation-name lands below CURRENT, so `getAnimations()` reports it
    // (a finished, fill-less animation is not relevant and drops out).
    // They also give the Motion section a value to arrive open on.
    $this->commitStyle($block_a_key, [
      'animation-duration' => '30s',
      'animation-iteration-count' => 'infinite',
    ], '__timingErrorA');
    $this->assertSame(
      'none',
      (string) $session->evaluateScript($this->animationNameJs($canvas_a)),
      'Before any name lands the canvas block wears no animation (the observable baseline).',
    );

    $this->openInspector();

    // Select A: the facade is the sanctioned programmatic face of a
    // canvas click, and the panel follows the committed selection.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_a_key');");
    $this->switchMode('Advanced');

    // ----- The advanced Motion row wears a shipped animation. -----
    // The Motion section exists by data (the curated group granted on the
    // block kind, in the payload's groups) and arrives open on the
    // committed timings; its Animation name row is the detented input
    // whose stop picker offers the roster's animations by name beside
    // their spelling (the animation_name value space reaching the row's
    // DOM through the shared menu primitive, no native list).
    $this->assertJsCondition(
      '(' . $this->sectionJs('Motion') . ')?.open === true',
      20000,
      'The Motion section rendered and arrived open on the committed timing longhands.',
    );
    $name_input = self::ANIMATION_NAME_INPUT;
    $this->assertNotNull(
      $assert_session->waitForElement('css', $name_input),
      'The Motion section renders the Animation name row as the detented text input.',
    );
    $name_chevron = 'document.querySelector(' . json_encode($name_input) . ").parentElement.querySelector('.daedalus-stylish-stop-menu__chevron')";
    $this->assertJsCondition(
      '(() => { const input = document.querySelector(' . json_encode($name_input) . ');'
      . " const chevron = $name_chevron;"
      . " return !!input && !input.hasAttribute('list') && !!chevron && chevron.getAttribute('aria-haspopup') === 'menu'; })()",
      20000,
      'The Animation name input carries the stop picker chevron (aria-haspopup=menu) and no native list.',
    );
    $session->executeScript("$name_chevron.click();");
    $this->assertJsCondition(
      "(() => { const menu = document.querySelector('.daedalus-ui-menu'); if (!menu) { return false; }"
      . " const labels = Array.from(menu.querySelectorAll('.daedalus-ui-menu__label')).map((label) => label.textContent);"
      . " return labels.some((label) => label.includes('Fade in') && label.includes('motion-fade_in'))"
      . " && labels.some((label) => label.includes('Rise') && label.includes('motion-rise')); })()",
      20000,
      'The chevron opens the shared menu listing the shipped animations by name beside their spelling (Fade in · motion-fade_in).',
    );
    $session->executeScript("document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }));");
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-menu')",
      20000,
      'An outside pointerdown dismissed the Animation name stop picker.',
    );

    // The pick: the spelling settling through the input (a menu pick
    // and a typed spelling run the same input event, then the blur
    // settles it through magnetic capture onto the roster stop).
    $this->typeIntoPanel($name_input, 'motion-fade_in');
    $this->assertJsCondition(
      'document.querySelector(' . json_encode($name_input) . ").value === 'motion-fade_in'",
      20000,
      'The picked spelling echoed into the controlled Animation name input.',
    );
    $this->blurPanel($name_input);
    $this->assertJsCondition(
      "$entry_a?.default?.['animation-name'] === 'motion-fade_in'",
      20000,
      'The settle committed the animation reference spelling to the map (row, value space, op, mirror) under the strict reference check.',
    );
    // The browser half only this tier sees: the re-rendered canvas block
    // wears the ident AND the compiled keyframes file declares it, so an
    // animation actually runs.
    $this->assertJsCondition(
      $this->animationNameJs($canvas_a) . " === 'motion-fade_in'",
      20000,
      'The canvas block\'s computed animation-name is the committed spelling.',
    );
    $this->assertJsCondition(
      $this->animationCountJs($canvas_a) . ' > 0',
      20000,
      'The canvas block is animating: getAnimations() is non-empty, so the compiled keyframes file declares the spelling the value wears.',
    );

    // ----- The mint relay: a fresh animation reaches every consumer. -----
    // The Materials place (the chrome row's Materials toggle), Animations
    // section (the third catalog segment), the "+ New animation" gesture
    // (offered: the editor role carries "create animations", the human
    // authoring gate).
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-inspector__place');
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-inspector__catalog .daedalus-ui-segment:nth-child(3)');
    $this->assertJsCondition(
      "[...document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-animations .daedalus-stylish-library__name')]"
      . ".some((name) => name.textContent.trim() === 'Fade in')",
      20000,
      'The Animations section lists the shipped animations from the store.',
    );
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-animations .daedalus-stylish-palette__mint-open');
    $this->assertNotNull(
      $assert_session->waitForElement('css', '.daedalus-stylish-animations-mint'),
      'The "+ New animation" gesture opened the mint card.',
    );
    $this->assertJsCondition(
      "document.querySelectorAll('.daedalus-stylish-animations-mint .daedalus-stylish-keyframes__handle').length >= 2",
      20000,
      'The mint card seeded its keyframes editor with the fade steps.',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript(self::PREVIEW_TAG_TEXT . ".includes('@keyframes motion-wiggle')"),
      'Before the mint the preview tag carries no wiggle keyframes.',
    );

    $mint_label = '.daedalus-stylish-animations-mint .daedalus-stylish-library-card__label';
    $this->typeIntoPanel($mint_label, 'Wiggle');
    $this->assertJsCondition(
      "document.querySelector('.daedalus-stylish-animations-mint .daedalus-stylish-library-card__action')?.disabled === false",
      20000,
      'Naming the animation armed the Save animation gesture.',
    );
    $this->clickPanel('.daedalus-stylish-animations-mint .daedalus-stylish-library-card__action');
    $this->assertJsCondition(
      "(() => { return !document.querySelector('.daedalus-stylish-animations-mint')"
      . " && Drupal.Daedalus.Materials.has('animation', 'wiggle')"
      . " && [...document.querySelectorAll('#daedalus_stylish_inspector .daedalus-stylish-animations .daedalus-stylish-library__name')]"
      . ".some((name) => name.textContent === 'Wiggle'); })()",
      20000,
      'The mint landed: the card closed, the store learned the record (the response\'s materials delta) and the list grew the Wiggle row.',
    );
    // The delta-to-stamp seam: the leader source re-printed every rostered
    // animation into its preview tag, the fresh @keyframes included.
    $this->assertJsCondition(
      self::PREVIEW_TAG_TEXT . ".includes('@keyframes motion-wiggle')",
      20000,
      'The leader\'s animation preview tag carries the minted @keyframes without a reload.',
    );
    // The mint persisted as a site-wide entity under strict: authoring is
    // never strict-gated.
    $animation_storage->resetCache();
    $minted = $animation_storage->loadByProperties(['machine_name' => 'wiggle']);
    $this->assertNotEmpty($minted, 'The mint persisted as a daedalus_animation entity (machine name from the label).');

    // The completion source: back in the styling place in code mode (a
    // mode click always lands in the styling place, so the one click
    // leaves the catalog), A's island (found by its printed animation-name
    // value) wakes on a real click.
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-inspector__ui-mode-button--code');
    $session->executeScript(<<<'JS'
      window.__motion = {
        island(needle) {
          return Array.from(document.querySelectorAll('.daedalus-stylish-page-tree .daedalus-stylish-island__editor'))
            .find((editor) => (editor.textContent ?? '').includes(needle)) ?? null;
        },
        view() {
          // CodeMirror's own DOM-to-view hook (EditorView.findFromDOM): the
          // content element's tile, then its root's view.
          const content = document.querySelector('.daedalus-stylish-page-tree .cm-editor .cm-content');
          return content?.cmTile?.root?.view ?? null;
        },
        completions() {
          return Array.from(document.querySelectorAll('.cm-tooltip-autocomplete .cm-completionLabel'))
            .map((label) => label.textContent);
        },
      };
      JS);
    $this->assertJsCondition(
      "window.__motion.island('motion-fade_in') !== null",
      20000,
      'The code-mode tree prints A\'s island with the committed animation-name line.',
    );
    $session->executeScript("window.__motion.island('motion-fade_in').scrollIntoView({ block: 'center' });");
    $print = $session->getPage()->find(
      'xpath',
      '//div[contains(@class, "daedalus-stylish-page-tree")]//*[contains(@class, "daedalus-stylish-island__editor")][contains(., "motion-fade_in")]//*[contains(@class, "daedalus-stylish-island__print")]',
    );
    $this->assertNotNull($print, 'A\'s printed island is present to wake.');
    $print->click();
    $this->assertJsCondition(
      "window.__motion.view() !== null && document.querySelectorAll('.daedalus-stylish-page-tree .cm-editor').length === 1",
      20000,
      'The click woke A\'s island into the one live editor.',
    );

    // Retype the animation-name value as a prefix, the way keystrokes
    // arrive (an input.type transaction): the completion source (the
    // roster's animations for the keyframes properties, straight from the
    // store) must offer exactly the fresh spelling. The partial value
    // never commits: the cursor's own line is held until it settles.
    $session->executeScript(<<<'JS'
      (() => {
        const view = window.__motion.view();
        const doc = view.state.doc.toString();
        const from = doc.indexOf('motion-fade_in');
        view.dispatch({
          changes: { from, to: from + 'motion-fade_in'.length, insert: 'motion-w' },
          selection: { anchor: from + 'motion-w'.length },
          userEvent: 'input.type',
        });
      })();
      JS);
    $this->assertJsCondition(
      "JSON.stringify(window.__motion.completions()) === JSON.stringify(['motion-wiggle'])",
      20000,
      'Typing motion-w in the island offered exactly the minted spelling as the completion (delta, store, completion source).',
    );
    $this->assertJsCondition(
      "[...document.querySelectorAll('.cm-tooltip-autocomplete .cm-completionDetail')].some((detail) => detail.textContent.includes('Wiggle'))",
      20000,
      'The completion is labelled by the animation\'s name.',
    );
    // Accept it with a real click on the option (the tooltip's own
    // mousedown path), then settle by a real canvas click: the blur
    // commits the active line's now-complete value.
    $option = $session->getPage()->find('xpath', '//div[contains(@class, "cm-tooltip-autocomplete")]//li[contains(., "motion-wiggle")]');
    $this->assertNotNull($option, 'The motion-wiggle completion option is present to click.');
    $option->click();
    $this->assertJsCondition(
      "(window.__motion.view()?.state.doc.toString() ?? '').includes('animation-name: motion-wiggle')",
      20000,
      'Accepting the completion wrote the full minted spelling into the island line.',
    );
    $assert_session->elementExists('css', $canvas_a)->click();
    $this->assertJsCondition(
      "$entry_a?.default?.['animation-name'] === 'motion-wiggle'",
      20000,
      'The island settle committed the minted reference to the map under the strict reference check (the server\'s roster learned the mint too).',
    );
    // The seam's proof: the page's compiled keyframes link predates the
    // mint, so the wiggle animation running on the canvas can only be the
    // preview tag's @keyframes applying.
    $this->assertJsCondition(
      $this->animationNameJs($canvas_a) . " === 'motion-wiggle'",
      20000,
      'The canvas block\'s computed animation-name is the minted spelling.',
    );
    $this->assertJsCondition(
      $this->animationCountJs($canvas_a) . ' > 0',
      20000,
      'The canvas block animates with the MINTED keyframes: the preview tag\'s @keyframes apply in this document.',
    );

    // ----- Basic mode wears an entrance and honours reduced motion. -----
    // A fresh block B: A's own animation-name would beat any variant
    // (the topmost setter wins per property), and A is the negative
    // control for the emulation step.
    $this->switchMode('Basic');
    $block_b_uuid = $uuid_service->generate();
    $this->placeTextBlock($block_a_uuid, 'after', $block_b_uuid, 'Rising canvas text', '__placementErrorB');
    $block_b_key = "block:$block_b_uuid";
    $canvas_b = "[data-selectable='$block_b_key']";
    $entry_b = $this->mapJs($block_b_key);
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_b_key');");

    // The wear bar renders WORN references only: a fresh block shows no
    // Animation row, just the add row. The picker it opens groups the
    // offer by axis: the block kind's animation axis (declared by data in
    // daedalus_stylish.page_builder.yml, labelled through the payload's axisLabels)
    // heads the shipped family by label.
    $animation_row = $this->wornRowXpath('Animation');
    $animation_row_js = 'document.evaluate(' . json_encode($animation_row) . ', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue';
    $add_row = '#daedalus_stylish_inspector .daedalus-stylish-wear-bar__add';
    $this->assertNotNull(
      $assert_session->waitForElement('css', $add_row),
      'The wear bar renders its add row for the selected block.',
    );
    $this->assertJsCondition(
      "!$animation_row_js && !document.querySelector(" . json_encode($add_row) . ').disabled',
      20000,
      'Nothing worn, no Animation row: the wear bar shows only the enabled add row.',
    );
    $this->clickPanel($add_row);
    $this->assertJsCondition(
      "(() => { const group = document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker__group[role=\"group\"][aria-label=\"Animation\"]');"
      . " if (!group) { return false; }"
      . " const labels = Array.from(group.querySelectorAll('[role=\"option\"] .daedalus-stylish-variant-picker__label')).map((label) => label.textContent.trim());"
      . " return labels.includes('Rise') && labels.includes('Fade in')"
      . " && !group.querySelector('.daedalus-stylish-variant-picker__option--worn')"
      . " && document.activeElement === document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker__input'); })()",
      20000,
      'The add row opened the variant picker: its Animation group offers the shipped entrances by label (Rise, Fade in), none worn, the search field focused.',
    );
    $this->assertSame(
      'none',
      (string) $session->evaluateScript($this->animationNameJs($canvas_b)),
      'Block B wears no animation before the pick (the observable baseline).',
    );

    // A real pick: the option commits apply_variant, the worn row appears,
    // and the variant's delivered CSS animates the re-rendered canvas
    // block.
    $this->clickPanelXpath($this->pickerOptionXpath('Animation', 'Rise'));
    $this->assertJsCondition(
      "JSON.stringify($entry_b?.variants ?? []).includes('block_rise')",
      20000,
      'The picker pick committed apply_variant: block B\'s map entry wears block_rise.',
    );
    $this->assertJsCondition(
      "!document.querySelector('.daedalus-ui-popover .daedalus-stylish-variant-picker')"
      . " && ($animation_row_js)?.querySelector('.daedalus-stylish-wear-bar__variant-label')?.textContent.trim() === 'Rise'",
      20000,
      'The picker closed and the worn Animation row appeared, its swap trigger reading Rise.',
    );
    $this->assertJsCondition(
      $this->animationNameJs($canvas_b) . " === 'motion-rise'",
      20000,
      'The canvas block B\'s computed animation-name is the variant\'s motion-rise (the wear delivered on the canvas).',
    );
    // B is IN VIEW when it wears Rise (the AI's, the picker's and the
    // basic control's case), and the shipped range spells its end
    // (`entry 20% exit 100%`), so the trigger is active the moment it is
    // created and the entrance plays at once. A lone start would leave
    // an in-view element's animation `paused` at 0 for good (its native
    // range ends at `entry 100%`, already behind the element), the block
    // held on the `from` frame. The pin reads the Rise animation by name
    // and requires it to be running or finished, never paused: the
    // remaining animations are dumped as evidence when it is not.
    $in_view_b = '(() => { const rect = document.querySelector(' . json_encode($canvas_b) . ').getBoundingClientRect();'
      . ' return rect.top < window.innerHeight && rect.bottom > 0; })()';
    $this->assertTrue(
      (bool) $session->evaluateScript($in_view_b),
      'Block B sits in view while it wears Rise (the in-view wear case).',
    );
    $rise_b_js = 'document.querySelector(' . json_encode($canvas_b) . ").getAnimations().find((animation) => animation.animationName === 'motion-rise')";
    $session->wait(20000, "['running', 'finished'].includes(($rise_b_js)?.playState)");
    $this->assertContains(
      (string) $session->evaluateScript("($rise_b_js)?.playState ?? 'missing'"),
      ['running', 'finished'],
      'Block B, worn in view, plays its entrance at once: the Rise animation is running or finished, not held paused (the activation range reaches exit 100%, so an element already in view activates the trigger on creation). Animations: '
      . (string) $session->evaluateScript($this->animationsDumpJs($canvas_b)) . $path_note,
    );

    // The Motion chip: its menu poses the canvas motion axis at Reduced.
    // The vector stamps the document and the emulation rewrites every
    // prefers-reduced-motion term in the CSSOM, the variant's own
    // reduced-motion condition included, so the variant's `animation:
    // none` wins on B.
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-chip-row .daedalus-stylish-chip--motion');
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__label')",
      20000,
      'The Motion chip opened its menu on the shared primitive.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.includes('Reduced')).click();"
    );
    $this->assertJsCondition(
      "document.documentElement.getAttribute('data-canvas-motion') === 'reduce'",
      20000,
      'The Motion chip posed the canvas vector at reduce (the document stamp).',
    );
    $this->assertJsCondition(
      $this->animationNameJs($canvas_b) . " === 'none'",
      20000,
      'Under the reduced-motion emulation block B\'s computed animation-name is none: the variant\'s own reduced-motion unset won.',
    );
    $session->wait(20000, $this->animationCountJs($canvas_b) . ' === 0');
    $this->assertSame(
      '[]',
      (string) $session->evaluateScript($this->animationsDumpJs($canvas_b)),
      'Block B stopped animating under reduced motion (the remaining animations are listed).',
    );
    // The negative that gives the unset its teeth: A wears a direct
    // animation-name with no reduced-motion condition of its own, so the
    // same emulation leaves it running (the setter carries the unset).
    $this->assertSame(
      'motion-wiggle',
      (string) $session->evaluateScript($this->animationNameJs($canvas_a)),
      'Block A, a direct wear without its own reduced-motion unset, keeps its animation under the emulation.',
    );
    $this->assertJsCondition(
      "(() => { const chip = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-chip--motion');"
      . " return !!chip && chip.classList.contains('daedalus-stylish-chip--lit') && chip.textContent.includes('Reduced'); })()",
      20000,
      'The Motion chip wears the posed face, lit.',
    );

    // ----- An entrance below the fold waits to be seen. -----
    // The Motion chip poses the canvas back to Auto first: under the
    // reduced-motion emulation the variants' own unset kills every
    // entrance, and this step is about WHEN one plays.
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-chip-row .daedalus-stylish-chip--motion');
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__label')",
      20000,
      'The Motion chip re-opened its menu.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.includes('Auto')).click();"
    );
    $this->assertJsCondition(
      "document.documentElement.getAttribute('data-canvas-motion') === 'auto'",
      20000,
      'The Motion chip posed the canvas vector back at auto.',
    );
    $this->assertJsCondition(
      $this->animationNameJs($canvas_b) . " === 'motion-rise'",
      20000,
      'Un-posing reduced motion gave block B its entrance back.',
    );

    $this->assertTrue(
      (bool) $session->evaluateScript("typeof Drupal.Daedalus?.Stylish?.motionTriggers?.replay === 'function'"),
      'The motion-triggers runtime is attached to the editing document (a dependency of daedalus_stylish/inspector).',
    );

    // The fold: a tall margin under B pushes whatever follows it far
    // below the viewport, so block C renders out of view.
    $this->commitStyle($block_b_key, ['margin-bottom' => '3000px'], '__foldError');
    $block_c_uuid = $uuid_service->generate();
    $this->placeTextBlock($block_b_uuid, 'after', $block_c_uuid, 'Late canvas text', '__placementErrorC');
    $block_c_key = "block:$block_c_uuid";
    $canvas_c = "[data-selectable='$block_c_key']";
    $entry_c = $this->mapJs($block_c_key);
    $below_fold = 'document.querySelector(' . json_encode($canvas_c) . ').getBoundingClientRect().top > window.innerHeight';
    $this->assertTrue(
      (bool) $session->evaluateScript($below_fold),
      'Block C rendered below the fold (its box starts under the viewport).',
    );

    // C wears Rise through the picker (the facade selects without
    // scrolling, so C stays out of view while the wear lands). The
    // shipped entrance carries the trigger pair by data, so the
    // re-rendered block is bound and HELD: the animation exists (a
    // paused CSS animation is current) and its play state is paused,
    // before any stamp.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_c_key');");
    $this->assertJsCondition(
      "!$animation_row_js && !!document.querySelector(" . json_encode($add_row) . ')',
      20000,
      'The wear bar re-rendered for block C: no Animation row (nothing worn), the add row present.',
    );
    $this->pickVariant('Animation', 'Rise');
    $this->assertJsCondition(
      "JSON.stringify($entry_c?.variants ?? []).includes('block_rise')",
      20000,
      'Block C wears block_rise.',
    );
    $rise_js = 'document.querySelector(' . json_encode($canvas_c) . ").getAnimations().find((animation) => animation.animationName === 'motion-rise')";
    $this->assertJsCondition(
      "($rise_js)?.playState === 'paused'",
      20000,
      'Below the fold, block C\'s entrance is held: getAnimations() carries motion-rise and its playState is paused (the trigger has not fired).' . $path_note,
    );
    $this->assertTrue(
      (bool) $session->evaluateScript($below_fold),
      'Block C is still below the fold while its entrance is held.',
    );
    $this->assertFalse(
      (bool) $session->evaluateScript('document.querySelector(' . json_encode($canvas_c) . ").hasAttribute('data-daedalus-stylish-triggered')"),
      'Nothing stamped block C before it was seen.',
    );

    // Seen: scrolling C into view meets the activation range (entry
    // 20% exit 100%), the trigger fires (natively, or the runtime stamps the
    // element and lifts the hold) and the entrance runs.
    $session->executeScript('document.querySelector(' . json_encode($canvas_c) . ").scrollIntoView({ block: 'center' });");
    $this->assertJsCondition(
      "['running', 'finished'].includes(($rise_js)?.playState)",
      20000,
      'Scrolled into view, block C\'s entrance plays: its playState is running or finished.' . $path_note,
    );
    if (!$native_triggers) {
      $this->assertJsCondition(
        'document.querySelector(' . json_encode($canvas_c) . ").hasAttribute('data-daedalus-stylish-triggered')",
        20000,
        'On the polyfill path the runtime stamped block C to lift the hold.' . $path_note,
      );
    }

    // Replay from the Motion trigger control re-arms the entrance: the
    // Animation's start time moves forward (a fresh run, not the old
    // one continuing) and it plays again. On the polyfill path the
    // stamp is lifted (the hold pauses the animation again) and then
    // re-applied when the still-in-view element re-fires: the attribute
    // log records exactly that off-then-on.
    $canvas_c_json = json_encode($canvas_c);
    $session->executeScript(<<<JS
      (() => {
        const el = document.querySelector($canvas_c_json);
        window.__replayStartBefore = ($rise_js).startTime;
        window.__stampLog = [];
        new MutationObserver(() => {
          window.__stampLog.push(el.hasAttribute('data-daedalus-stylish-triggered'));
        }).observe(el, { attributes: true, attributeFilter: ['data-daedalus-stylish-triggered'] });
      })();
      JS);
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-inspector__widget--motion-trigger .daedalus-stylish-inspector__widget-action');
    $this->assertJsCondition(
      "(() => { const animation = $rise_js; return !!animation"
      . " && ['running', 'finished'].includes(animation.playState)"
      . " && animation.startTime > window.__replayStartBefore; })()",
      20000,
      'Replay re-armed block C\'s entrance: it plays again from a later start time.' . $path_note,
    );
    if (!$native_triggers) {
      $this->assertJsCondition(
        "JSON.stringify(window.__stampLog) === JSON.stringify([false, true])",
        20000,
        'On the polyfill path Replay lifted the stamp (paused again under the hold) and the in-view element re-fired (stamped, running).' . $path_note,
      );
    }

    // The same affordance in advanced: the Motion section's header
    // carries the Replay pill while the selection's effective
    // animation-trigger at the pose binds a trigger (C's worn Rise
    // delivers `--in-view play-once` through the layer stack), and a
    // click re-arms the entrance exactly as the basic control did.
    $this->switchMode('Advanced');
    $header_replay = '#daedalus_stylish_inspector details.daedalus-stylish-inspector__section summary .daedalus-stylish-inspector__section-action';
    $this->assertJsCondition(
      '(() => { const section = ' . $this->sectionJs('Motion') . ';'
      . " return !!section && !!section.querySelector(':scope > summary .daedalus-stylish-inspector__section-action'); })()",
      20000,
      'The advanced Motion section header carries the Replay pill for block C (its worn entrance binds a trigger).',
    );
    $session->executeScript(<<<JS
      (() => {
        window.__replayStartBefore = ($rise_js).startTime;
        window.__stampLog = [];
      })();
      JS);
    $this->clickPanel($header_replay);
    $this->assertJsCondition(
      "(() => { const animation = $rise_js; return !!animation"
      . " && ['running', 'finished'].includes(animation.playState)"
      . " && animation.startTime > window.__replayStartBefore; })()",
      20000,
      'Replay from the advanced Motion section header re-armed block C\'s entrance: it plays again from a later start time.' . $path_note,
    );
    if (!$native_triggers) {
      $this->assertJsCondition(
        "JSON.stringify(window.__stampLog) === JSON.stringify([false, true])",
        20000,
        'On the polyfill path the header Replay lifted the stamp and the in-view element re-fired.' . $path_note,
      );
    }
    $this->assertJsCondition(
      '(' . $this->sectionJs('Motion') . ")?.open === true",
      20000,
      'The header Replay left the Motion section open: the pill is its own activation, not a summary toggle.',
    );

    // Nothing to re-arm, no pill: A runs a direct animation-name with no
    // trigger pair anywhere in its layer stack (C's header carried the
    // pill a moment ago, so a Motion section without one is A's render).
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_a_key');");
    $this->assertJsCondition(
      '(() => { const section = ' . $this->sectionJs('Motion') . ';'
      . " return !!section && !section.querySelector(':scope > summary .daedalus-stylish-inspector__section-action'); })()",
      20000,
      'Block A\'s Motion section carries no Replay pill: its effective animation-trigger binds nothing.',
    );
    $this->switchMode('Basic');

    // ----- Posed wear rows: a wear renders at the pose, never retargets. -----
    // Block B (in view, at the default width band on this wide window)
    // switches its Animation wear to Fade in, so the sub-line names the
    // entrance the task reads.
    $session->executeScript("Drupal.Daedalus.Selection.select('$block_b_key');");
    $this->assertJsCondition(
      "($animation_row_js)?.querySelector('.daedalus-stylish-wear-bar__variant-label')?.textContent.trim() === 'Rise'",
      20000,
      'The Animation row re-rendered for block B wearing Rise.',
    );
    $this->pickVariant('Animation', 'Fade in');
    $this->assertJsCondition(
      "(() => { const refs = JSON.stringify($entry_b?.variants ?? []);"
      . " return refs.includes('block_fade_in') && !refs.includes('block_rise'); })()",
      20000,
      'Block B now wears block_fade_in in place of block_rise (one axis, one wear).',
    );
    $this->assertJsCondition(
      "!document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-wear-bar__row--posed')",
      20000,
      'At the default pose no wear row carries a sub-line.',
    );

    // The State chip poses Hover: the force state stamps the selected
    // canvas element, the editing compound becomes hover, and the
    // Animation row SAYS what its wear contributes there: Fade in's
    // only other block is reduced-motion, so nothing at Hover.
    $this->clickPanel('#daedalus_stylish_inspector .daedalus-stylish-chip-row .daedalus-stylish-chip--state');
    $this->assertJsCondition(
      "!!document.querySelector('.daedalus-ui-menu .daedalus-ui-menu__label')",
      20000,
      'The State chip opened its menu.',
    );
    $session->executeScript(
      "Array.from(document.querySelectorAll('.daedalus-ui-menu__item'))"
      . ".find((item) => item.querySelector('.daedalus-ui-menu__label')?.textContent.trim() === 'Hover').click();"
    );
    $this->assertJsCondition(
      'document.querySelector(' . json_encode($canvas_b) . ").getAttribute('data-force-state') === 'hover'",
      20000,
      'The State chip forced hover on the selected canvas element.',
    );
    $animation_pose = $animation_row . '[contains(@class, "daedalus-stylish-wear-bar__row--posed")]/button[contains(@class, "daedalus-stylish-wear-bar__pose")]';
    $this->assertJsCondition(
      '(() => { const pose = document.evaluate(' . json_encode($animation_pose) . ', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue;'
      . " return !!pose && pose.classList.contains('daedalus-stylish-wear-bar__pose--nothing')"
      . " && pose.textContent.trim() === 'Fade in sets nothing at Hover'; })()",
      20000,
      'Posed at Hover, the Animation row\'s sub-line reads "Fade in sets nothing at Hover".',
    );

    // Lift picked under the picker's Hover group wears the Hover row: the
    // wear itself stays unconditioned (the material owns its hover block),
    // the row's sub-line lists that block's declarations, and the forced
    // state makes the canvas show it (the 4px lift as computed transform).
    $hover_row = $this->wornRowXpath('Hover');
    $this->pickVariant('Hover', 'Lift');
    $this->assertJsCondition(
      "JSON.stringify($entry_b?.variants ?? []).includes('block_lift')",
      20000,
      'The Hover pick committed apply_variant: block B wears block_lift.',
    );
    $this->assertNotNull(
      $assert_session->waitForElement('xpath', $hover_row),
      'The wear bar renders the worn Hover row for the selected block.',
    );
    $hover_pose = $hover_row . '[contains(@class, "daedalus-stylish-wear-bar__row--posed")]/button[contains(@class, "daedalus-stylish-wear-bar__pose")]';
    $this->assertJsCondition(
      '(() => { const pose = document.evaluate(' . json_encode($hover_pose) . ', document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue;'
      . " if (!pose || pose.classList.contains('daedalus-stylish-wear-bar__pose--nothing')) { return false; }"
      . " const text = pose.querySelector('.daedalus-stylish-wear-bar__pose-text')?.textContent.trim();"
      . " const declarations = pose.querySelector('.daedalus-stylish-wear-bar__pose-declarations')?.textContent ?? '';"
      . " return text === 'Lift at Hover:' && declarations.includes('transform: translateY(-4px)'); })()",
      20000,
      'The Hover row\'s sub-line lists Lift\'s hover block, transform included.',
    );
    $this->assertJsCondition(
      'getComputedStyle(document.querySelector(' . json_encode($canvas_b) . ")).transform === 'matrix(1, 0, 0, 1, 0, -4)'",
      20000,
      'Under the forced hover state the canvas block B shows Lift\'s translateY(-4px) (the variant\'s own hover block delivered).',
    );
    $this->assertJsCondition(
      "JSON.stringify($entry_b?.hover ?? null) === 'null'",
      20000,
      'The wear never retargeted: block B\'s map entry gained no hover block of its own.',
    );

    // The doorway: the sub-line opens the editing session on Lift at
    // the canvas's pose. The session has no condition of its own; its
    // rows address the canvas's compound, so "at Hover" is the forced
    // state staying on while the session names Lift.
    $this->clickPanelXpath($hover_pose);
    $this->assertJsCondition(
      "(() => { const frame = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-session'); if (!frame) { return false; }"
      . " const switcher = frame.querySelector('.daedalus-stylish-session__switcher');"
      . " const label = frame.querySelector('.daedalus-stylish-session__label');"
      . " return (switcher ? switcher.value === 'block_lift' : label?.textContent.trim() === 'Lift'); })()",
      20000,
      'The sub-line opened the editing session on Lift.',
    );
    $this->assertJsCondition(
      'document.querySelector(' . json_encode($canvas_b) . ").getAttribute('data-force-state') === 'hover'"
      . " && (() => { const chip = document.querySelector('#daedalus_stylish_inspector .daedalus-stylish-chip--state');"
      . " return !!chip && chip.classList.contains('daedalus-stylish-chip--lit') && chip.textContent.includes('Hover'); })()",
      20000,
      'The session opened AT Hover: the forced state stays on the canvas and the State chip wears Hover, lit.',
    );

    // ----- Staged, not committed, and truly no reload. -----
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $storage->resetCache([$nid]);
    $saved = $storage->load($nid);
    $this->assertTrue(
      $saved->get('daedalus_stylish_styles')->isEmpty(),
      'Every style write is staged in tempstore, not committed to the node.',
    );
    $this->assertTrue(
      (bool) $session->evaluateScript('window.__sameDocument === true'),
      'The whole scenario ran without a page reload.',
    );
  }

}
