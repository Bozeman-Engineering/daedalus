<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\Result\BlueprintError;

/**
 * Resolves the style grammar's input tier ahead of map validation.
 *
 * A style property value's INPUT grammar is a superset of its storage
 * grammar: a plain CSS string (the storage form, untouched), a
 * handled-value object dispatched on its marker key to a registered
 * StyleValueHandlerInterface, or, on media-capable properties (mirroring
 * CSS's own comma-layer model), a list of layers (strings and handled
 * objects) that joins to one comma-separated value. Storage only ever
 * holds the resolved plain-CSS string.
 *
 * The resolver is the one seam all three style write lanes pass
 * (StyleMapFieldHandler whole-map writes, DaedalusStylishOperationHandler's
 * update_style patch, VariantStylesFieldHandler variant styles), wired
 * directly beside the StyleMapValidator call each lane already makes, in
 * two phases:
 * - check() walks the map BEFORE validation: shape-validates handled
 *   objects (the handler's own CHECK), substitutes each input-tier value
 *   with a sound plain-CSS stand-in, and records what will need
 *   resolving. The validator then runs unchanged over an all-strings map,
 *   and stays side-effect-free: eb_validate never commits anything.
 * - StyleValueResolution::commit() runs ONLY after validation succeeded:
 *   handlers perform their effects and the canonical resolved strings
 *   replace the stand-ins, so a failed write mints nothing.
 *
 * Daedalus Stylish registers no handlers itself: the tier is generic machinery, and
 * what a marker means (e.g. daedalus_ai's `_generate`) is its handler's
 * business. An object value carrying no registered marker key is a
 * validation error, never a pass-through.
 */
final class StyleValueResolver {

  /**
   * The registered handlers, keyed by marker key.
   *
   * @var array<string, \Drupal\daedalus_stylish\StyleValueHandlerInterface>
   */
  private array $handlers = [];

  /**
   * Constructs the resolver.
   *
   * @param iterable<\Drupal\daedalus_stylish\StyleValueHandlerInterface> $handlers
   *   The handled-value seam (tag daedalus_stylish.style_value_handler).
   * @param \Drupal\daedalus_stylish\PropertyGroupPluginManager $propertyGroups
   *   The property group plugin manager.
   */
  public function __construct(
    iterable $handlers,
    private readonly PropertyGroupPluginManager $propertyGroups,
  ) {
    foreach ($handlers as $handler) {
      $marker = $handler->getMarkerKey();
      if (isset($this->handlers[$marker])) {
        throw new \LogicException(sprintf('Two style value handlers register the "%s" marker key (%s and %s); a marker has exactly one handler.', $marker, $this->handlers[$marker]::class, $handler::class));
      }
      $this->handlers[$marker] = $handler;
    }
  }

  /**
   * The registered handlers' guidance sentences, keyed by marker key.
   *
   * The schema-teaching half of the tier: StyleMapFieldHandler composes
   * these into its guidance so the AI is taught exactly the handled
   * values this site registers, and nothing when none are.
   *
   * @return array<string, string>
   *   Marker key => the guidance sentence.
   */
  public function getHandlerGuidance(): array {
    return array_map(
      static fn (StyleValueHandlerInterface $handler): string => $handler->getGuidance(),
      $this->handlers,
    );
  }

  /**
   * CHECK phase: resolves a style map's input tier to validatable strings.
   *
   * Walks only what is soundly shaped (a map of entries, condition blocks
   * of properties); anything malformed stays untouched for the validator's
   * own structural errors, which are the corrective product. The 'variants'
   * entry key is the reference list, never a condition block, and is
   * skipped.
   *
   * @param array $map
   *   The style map as written: full selectable-key maps, element-relative
   *   variant styles, and update_style's one-key patch all share the entry
   *   shape.
   * @param \Drupal\daedalus_blueprint\Host\HostInterface|null $host
   *   The host being written, when the lane has one (the op spine's and
   *   the style map field's; the variant lane carries none).
   * @param string $conversation
   *   The conversation the write belongs to (the op spine's 'conversation'
   *   option), '' when the caller has none (handler context).
   *
   * @return \Drupal\daedalus_stylish\StyleValueResolution
   *   The resolution: errors when any input-tier value is unsound (do not
   *   validate or commit), otherwise the stand-in map to validate plus the
   *   pending commits.
   */
  public function check(array $map, ?HostInterface $host = NULL, string $conversation = ''): StyleValueResolution {
    $errors = [];
    $pending = [];
    foreach ($map as $key => $entry) {
      $key = (string) $key;
      if (!is_array($entry) || array_is_list($entry)) {
        continue;
      }
      foreach ($entry as $condition => $properties) {
        $condition = (string) $condition;
        if ($condition === 'variants' || !is_array($properties) || array_is_list($properties)) {
          continue;
        }
        foreach ($properties as $property => $value) {
          if (!is_array($value) || $value === []) {
            continue;
          }
          $property = (string) $property;
          $at = $key === '' ? "$condition.$property" : "$key.$condition.$property";
          $context = [
            'key' => $key,
            'condition' => $condition,
            'property' => $property,
            'path' => $at,
            'layer' => NULL,
            'host' => $host,
            'conversation' => $conversation,
          ];
          $layers = array_is_list($value)
            ? $this->checkLayers($value, $at, $context, $errors)
            : $this->checkHandled($value, $at, $context, $errors);
          if ($layers === NULL) {
            continue;
          }
          $map[$key][$condition][$property] = implode(', ', array_map(
            static fn (array $layer): string => isset($layer['handler']) ? $layer['handler']->getStandIn($layer['value'], $layer['context']) : $layer['literal'],
            $layers,
          ));
          if (array_filter($layers, static fn (array $layer): bool => isset($layer['handler']))) {
            $pending[] = ['key' => $key, 'condition' => $condition, 'property' => $property, 'layers' => $layers];
          }
        }
      }
    }
    return new StyleValueResolution($map, $errors, $pending);
  }

  /**
   * Checks one handled-value object, dispatching on its marker key.
   *
   * @param array $value
   *   The handled-value object.
   * @param string $at
   *   The error path of the value.
   * @param array $context
   *   The resolution context.
   * @param array $errors
   *   Collects errors, re-rooted onto the map path.
   *
   * @return array[]|null
   *   The single resolved layer ({handler, value, context}), or NULL on
   *   error.
   */
  private function checkHandled(array $value, string $at, array $context, array &$errors): ?array {
    $matched = array_intersect_key($this->handlers, $value);
    if (count($matched) !== 1) {
      $known = array_keys($this->handlers);
      if ($matched === []) {
        $hint = $known === []
          ? 'No handled-value markers are available on this site; provide the value as a plain CSS string.'
          : sprintf('An object value must carry exactly one registered marker key: %s. Otherwise provide the value as a plain CSS string.', implode(', ', $known));
        $errors[] = new BlueprintError($at, 'UNKNOWN_STYLE_VALUE_MARKER', sprintf('The object value of %s carries no recognized handled-value marker.', $context['property']), ['known_markers' => $known], $hint);
      }
      else {
        $errors[] = new BlueprintError($at, 'UNKNOWN_STYLE_VALUE_MARKER', sprintf('The object value of %s carries more than one handled-value marker (%s).', $context['property'], implode(', ', array_keys($matched))), ['known_markers' => $known], 'Carry exactly one marker key per handled value; split layers into a list to combine handled values.');
      }
      return NULL;
    }
    $handler = reset($matched);
    if ($shape_errors = $handler->check($value, $context)) {
      foreach ($shape_errors as $error) {
        $errors[] = $error->withPath($error->getPath() === '' ? $at : "$at." . $error->getPath());
      }
      return NULL;
    }
    return [['handler' => $handler, 'value' => $value, 'context' => $context]];
  }

  /**
   * Checks a layers list: strings and handled objects, joined on commit.
   *
   * The list form mirrors CSS's own comma-layer model, so it is only legal
   * on media-capable properties: the properties whose grammar layers
   * (background-image and kin). An unrecognized property passes through
   * ungated: the validator's unknown-property corrective (with its
   * closest-name suggestions) is the better error, and a failing map never
   * commits anyway.
   *
   * @return array[]|null
   *   The resolved layers in order ({literal} or {handler, value,
   *   context}), or NULL on error.
   */
  private function checkLayers(array $value, string $at, array $context, array &$errors): ?array {
    $property = $context['property'];
    if ($this->propertyGroups->getGroupIdForProperty($property) !== NULL && !$this->propertyGroups->isMediaCapable($property)) {
      $errors[] = new BlueprintError($at, 'INVALID_STYLE_VALUE', sprintf('%s does not accept a list of layers.', $property), [], 'Only media-capable properties (those whose values layer with commas, e.g. background-image) accept a list; provide a single plain CSS string or handled-value object.');
      return NULL;
    }
    $layers = [];
    $sound = TRUE;
    foreach ($value as $index => $layer) {
      $layer_at = "$at.$index";
      if (is_string($layer)) {
        if (trim($layer) === '') {
          $errors[] = new BlueprintError($layer_at, 'INVALID_STYLE_VALUE', sprintf('Layer %d of %s is empty.', $index, $property), [], 'Each layer is a non-empty CSS fragment or a handled-value object; drop empty layers.');
          $sound = FALSE;
          continue;
        }
        $layers[] = ['literal' => trim($layer)];
        continue;
      }
      if (is_array($layer) && $layer !== [] && !array_is_list($layer)) {
        $resolved = $this->checkHandled($layer, $layer_at, ['layer' => $index, 'path' => $layer_at] + $context, $errors);
        if ($resolved === NULL) {
          $sound = FALSE;
          continue;
        }
        $layers[] = $resolved[0];
        continue;
      }
      $errors[] = new BlueprintError($layer_at, 'INVALID_STYLE_VALUE', sprintf('Layer %d of %s is neither a CSS string nor a handled-value object.', $index, $property), [], 'Each layer is a plain CSS fragment ("linear-gradient(…)") or a handled-value object dispatched on its marker key; the list joins to one comma-separated value.');
      $sound = FALSE;
    }
    return $sound ? $layers : NULL;
  }

}
