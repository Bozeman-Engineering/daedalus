<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

/**
 * Resolves a handled style value to its canonical stored CSS string.
 *
 * The style grammar's input tier: a style property value may arrive as a
 * plain CSS string, as a handled-value object dispatched on its marker key,
 * or, on media-capable properties, as a list of layers (strings and
 * handled objects) that joins to one comma-separated value. Handlers are
 * the object half of that tier: each registers ONE marker key (a service
 * tagged daedalus_stylish.style_value_handler), and an object value dispatches to
 * the handler whose marker key it carries. Daedalus Stylish itself registers no
 * handlers; an object carrying no registered marker is a validation error.
 *
 * Storage stays exactly the plain-CSS doctrine: the input tier is resolved
 * at write time by StyleValueResolver, and only the handler's canonical
 * resolved string is ever stored, printed, or delivered.
 *
 * Two phases, and the split is the contract:
 * - CHECK (check() + getStandIn()) runs BEFORE StyleMapValidator and must
 *   be side-effect-free: shape-validate the object, then provide a plain
 *   CSS stand-in that is sound where the resolved value will go, so the
 *   validator runs unchanged over an all-strings map. eb_validate and
 *   failed writes only ever reach this phase.
 * - COMMIT (resolve()) runs only after the whole map validated: perform
 *   the handled value's effect and return the canonical CSS fragment that
 *   replaces the object in storage. A failing write never commits, so
 *   handlers whose effects mint things (media entities, jobs) mint nothing
 *   on failure.
 *
 * Every phase method receives the same $context array:
 * - key: (string) the style-map key the value sits under (a selectable
 *   key, or an element-relative key in the variant-styles lane, '' for a
 *   variant's root element).
 * - condition: (string) the condition block, e.g. "default".
 * - property: (string) the CSS property name.
 * - path: (string) the validator-convention error path of the value
 *   ("{key}.{condition}.{property}", condition-rooted when key is '').
 * - layer: (int|null) the value's index within a layers list, NULL for a
 *   bare object value.
 * - host: (\Drupal\daedalus_blueprint\Host\HostInterface|null) the host
 *   whose map is being written (a page's or a template's), NULL on the
 *   variant lane.
 * - conversation: (string) the conversation the write belongs to (the op
 *   spine's 'conversation' option, the chat thread id the page tools and
 *   the HTTP op endpoint pass), '' when the caller has none. A handler
 *   whose value names something the conversation holds (a file, by the
 *   name the alias layer records) resolves it for this conversation.
 */
interface StyleValueHandlerInterface {

  /**
   * The marker key this handler owns, e.g. "_generate".
   *
   * An object value carrying this key dispatches here. One handler per
   * marker: registering a second handler on the same marker is a wiring
   * error (the resolver refuses at construction).
   */
  public function getMarkerKey(): string;

  /**
   * One or two sentences teaching this marker's object form.
   *
   * Composed into the style-map field guidance's input-tier passage, so
   * the schema teaches exactly the handled values this site registers.
   * Speak the value's grammar (marker key, params) and its effect; the
   * tier mechanics (layers lists, write-time resolution, plain-CSS
   * storage) are taught once by the field guidance itself.
   */
  public function getGuidance(): string;

  /**
   * CHECK phase: shape-validates a handled object. Side-effect-free.
   *
   * @param array $value
   *   The handled object as written, marker key included.
   * @param array $context
   *   The value's context (see the interface docblock).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Shape errors with paths relative to the value ('' or a key within
   *   the object); the resolver re-roots them onto the map path. Empty
   *   when the object is sound.
   */
  public function check(array $value, array $context): array;

  /**
   * The validation stand-in for a checked object. Side-effect-free.
   *
   * A plain CSS fragment that is syntactically sound where the resolved
   * value will go (e.g. "none" in an <image> slot), so StyleMapValidator
   * validates the surrounding map exactly as it would a plain write. Never
   * stored: the commit phase's resolved string replaces it. Called only
   * after check() returned no errors.
   *
   * @param array $value
   *   The checked handled object.
   * @param array $context
   *   The value's context (see the interface docblock).
   */
  public function getStandIn(array $value, array $context): string;

  /**
   * COMMIT phase: performs the value's effect, returns the canonical CSS.
   *
   * Called only after the whole map validated, once per handled object.
   * The returned fragment is stored verbatim (joined with ", " into its
   * layers list when the value was a layer) and is not re-validated: the
   * handler owns producing canonical plain CSS, e.g. url('media:<uuid>').
   *
   * @param array $value
   *   The checked handled object.
   * @param array $context
   *   The value's context (see the interface docblock).
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Collector for non-blocking observations about the effect (e.g. "the
   *   capability behind this value is disabled, a fallback was stored").
   *   Append, never replace; locate a warning with $context['path']. The
   *   lanes surface these with the write's (successful) result.
   */
  public function resolve(array $value, array $context, array &$warnings): string;

}
