<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Host\HostInterface;

/**
 * Captures an element's look as variant styles, from the staged map.
 *
 * The server twin of captureEntries() + saveStyles() in
 * js/src/variant-scope.js, for the lane that has no client: save_material
 * / update_material with kind variant naming an `element` (the AI's door).
 * The client keeps its own walk for live preview and the re-attach's
 * shadow-clearing ops, which read the same sources; this walk answers the
 * one question "what look does this element have", element-relative, in
 * the variant entity's storage shape:
 * - OWN: the element's root entry ('') and every selector entry under it
 *   ('::.class', '::p', '::> *', '::> pb-text'). The root travels
 *   overrides-only (its `variants` list is the axis fold's business,
 *   below); a RULE entry's worn ROOT references travel verbatim as the
 *   captured '::selector' entry's `variants` list (a rule's wear is part
 *   of the look, delivered at the rule's matches; apply places it at the
 *   same rule under the new wearer, continued by descent under a rule
 *   wearer). Instance parts ('::.s-xx', identity classes minted per
 *   content instance, wherever they stand in a selector) stay behind:
 *   the server refuses them in variant styles, and the capture must not
 *   author a save that refuses.
 * - CHILDREN: every entry of the staged subtree the slot grammar can
 *   address, its key translated to a positional slot path
 *   (SubtreeSlotKeys::slotPaths(), "container(1)/block(text,2)", a block
 *   field riding its block as "field(name)"), overrides-only; a child base
 *   entry's worn ROOT references, and a child's rule entry's, travel
 *   verbatim as the entry's `variants` list (refs stay refs: the minted
 *   variant re-distributes them instead of baking their values in).
 *   Shallow-first, so apply distributes a parent before its children.
 * - FOLD: save means "save this LOOK", not "save my tweaks": the root's
 *   same-axis wearings (the ones minting this axis's replacement retires)
 *   resolve through their own fragment (SlotKey::fragmentStyles(), the
 *   read delivery uses) and bake BENEATH the captured overrides in
 *   delivery order; a child's same-axis FRAGMENT wearing folds when the
 *   re-attach would strip it (the child is captured, or the fragment came
 *   from the replaced root composition). Cross-axis references never
 *   flatten: baking axes together is what the axis model forbids.
 */
final class VariantCapture {

  private const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

  public function __construct(
    protected StyleMapValidator $validator,
    protected SubtreeSlotKeys $slotKeys,
  ) {}

  /**
   * The element-relative styles that reproduce an element's look.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose staged style map and document answer for the element.
   * @param string $key
   *   The element's selectable key (a ROOT element key: container:{uuid},
   *   block:{uuid}, entity:{uuid}).
   * @param string $axis
   *   The axis the new variant competes on: its same-axis wearings fold.
   *
   * @return array
   *   Relative key => style entry, [] when the element carries no look.
   */
  public function capture(HostInterface $host, string $key, string $axis): array {
    $map = $host->styleMap();
    $entries = [];
    foreach ($map as $map_key => $entry) {
      $map_key = (string) $map_key;
      if (!is_array($entry) || !$this->isOwn($map_key, $key, $relative)) {
        continue;
      }
      $own = StyleEntry::stripVariants($entry);
      $refs = $relative === '' ? [] : $this->travellingRefs($entry);
      if ($own !== [] || $refs !== []) {
        $entries[$relative] = $refs !== [] ? ['variants' => $refs] + $own : $own;
      }
    }

    $paths = $this->slotKeys->slotPaths($host, $key);
    $direct = [];
    $captured_bases = [];
    foreach ($map as $map_key => $entry) {
      $map_key = (string) $map_key;
      if (!is_array($entry) || $map_key === $key || str_starts_with($map_key, $key . '::')) {
        continue;
      }
      $parsed = $this->validator->parseKey($map_key);
      if ($parsed === NULL || StyleMapValidator::instancePart($parsed['selector']) !== NULL) {
        continue;
      }
      $overrides = StyleEntry::stripVariants($entry);
      $refs = $this->travellingRefs($entry);
      if ($overrides === [] && $refs === []) {
        continue;
      }
      $base = $this->base($map_key);
      $path = $this->slotPath($base, $parsed, $paths);
      if ($path === NULL) {
        continue;
      }
      $relative = $path . ($parsed['selector'] !== NULL ? '::' . $parsed['selector'] : '');
      $direct[] = [
        'relative' => $relative,
        'entry' => $refs !== [] ? ['variants' => $refs] + $overrides : $overrides,
        'depth' => substr_count($path, '/') + 1,
      ];
      $captured_bases[$base] = TRUE;
    }
    usort($direct, static fn (array $a, array $b): int => $a['depth'] <=> $b['depth']);
    foreach ($direct as ['relative' => $relative, 'entry' => $entry]) {
      $entries[$relative] = isset($entries[$relative]) ? StyleEntry::merge($entry, $entries[$relative]) : $entry;
    }

    // The root's same-axis wearings fold wholesale; the root-kind ones are
    // the compositions whose distributed fragments the group cleanup
    // strips from the subtree.
    $root_worn = $this->sameAxisWearings($map[$key] ?? NULL, $axis, FALSE);
    $this->fold($entries, '', $root_worn);
    $replaced = array_map(static fn (array $worn): string => $worn['variant']->getMachineName(), array_filter($root_worn, static fn (array $worn): bool => $worn['fragment'] === ''));
    foreach ($map as $base => $entry) {
      $base = (string) $base;
      if ($base === $key || str_contains($base, '::') || !is_array($entry)) {
        continue;
      }
      $worn = $this->sameAxisWearings($entry, $axis, TRUE);
      if ($worn === []) {
        continue;
      }
      $stripped = array_filter($worn, static fn (array $wearing): bool => in_array($wearing['variant']->getMachineName(), $replaced, TRUE)) !== [];
      if (!isset($captured_bases[$base]) && !$stripped) {
        continue;
      }
      $parsed = $this->validator->parseKey($base);
      $path = $parsed === NULL ? NULL : $this->slotPath($base, $parsed, $paths);
      if ($path === NULL || $path === '') {
        continue;
      }
      $this->fold($entries, $path, $worn);
    }
    return $entries;
  }

  /**
   * An entry's references that travel with a capture.
   *
   * The entry's ROOT ('' fragment) references as {variant, fragment}
   * only: a variant's nested list says what to distribute, never who
   * did, so the origin the read carries ("via") is stripped here and
   * the variant styles lane, which rejects the key, accepts the save. A
   * FRAGMENT reference never travels: it is owned by the composition
   * that distributed it (the fold, or the element, keeps it).
   *
   * @return array<int, array{variant: string, fragment: string}>
   *   The references, in the entry's application order.
   */
  protected function travellingRefs(array $entry): array {
    return array_values(array_map(static fn (array $ref): array => [
      'variant' => $ref['variant'],
      'fragment' => $ref['fragment'],
    ], array_filter(
      StyleMapValidator::entryRefs($entry),
      static fn (array $ref): bool => $ref['fragment'] === '',
    )));
  }

  /**
   * Whether a map key is the element's own root or a selector under it.
   *
   * Every selector travels (a structural relation transfers with the
   * look) except one carrying an instance part, which is bound to this
   * content's minted identities.
   *
   * @param string $map_key
   *   The style map key under test.
   * @param string $key
   *   The element's own selectable key.
   * @param string|null $relative
   *   Set to the relative key ('' or '::selector') when it is.
   */
  protected function isOwn(string $map_key, string $key, ?string &$relative): bool {
    if ($map_key === $key) {
      $relative = '';
      return TRUE;
    }
    if (!str_starts_with($map_key, $key . '::')) {
      return FALSE;
    }
    $relative = substr($map_key, strlen($key));
    $parsed = $this->validator->parseKey($map_key);
    return $parsed !== NULL && StyleMapValidator::instancePart($parsed['selector']) === NULL;
  }

  /**
   * A map key without its part suffix.
   */
  protected function base(string $map_key): string {
    $position = strpos($map_key, '::');
    return $position === FALSE ? $map_key : substr($map_key, 0, $position);
  }

  /**
   * The slot path a subtree key holds, a block field riding its block.
   *
   * NULL when the grammar cannot address the key (a non-inline placement,
   * a key outside the subtree, the root block itself).
   */
  protected function slotPath(string $base, array $parsed, array $paths): ?string {
    if ($parsed['kind'] === 'field' && preg_match('/^block:(' . self::UUID . '):([a-z0-9_]+)$/', $base, $matches)) {
      $parent = $paths['block:' . $matches[1]] ?? NULL;
      return $parent === NULL ? NULL : ($parent === '' ? '' : $parent . '/') . sprintf('field(%s)', $matches[2]);
    }
    $path = $paths[$base] ?? NULL;
    return $path === NULL || $path === '' ? NULL : $path;
  }

  /**
   * An entry's worn references on one axis, resolved.
   *
   * @return array<int, array{variant: \Drupal\daedalus_stylish\DaedalusStylishVariantInterface, fragment: string}>
   *   The wearings, in the entry's application order.
   */
  protected function sameAxisWearings(mixed $entry, string $axis, bool $fragments_only): array {
    $worn = [];
    foreach (StyleMapValidator::entryRefs($entry) as $ref) {
      if ($fragments_only && $ref['fragment'] === '') {
        continue;
      }
      $variant = $this->validator->loadVariant($ref['variant']);
      if ($variant === NULL || $variant->getAxis() !== $axis) {
        continue;
      }
      $worn[] = ['variant' => $variant, 'fragment' => $ref['fragment']];
    }
    return $worn;
  }

  /**
   * Bakes wearings beneath the captured entries at a base relative key.
   *
   * Delivery within an axis is structural (roots before fragments, list
   * order among equals) and later delivery beats earlier, so the fold runs
   * in REVERSE delivery order: the last-delivered values land first and
   * each earlier layer slots beneath what is already there (captured
   * overrides stay on top throughout).
   */
  protected function fold(array &$entries, string $base, array $worn): void {
    $roots = array_filter($worn, static fn (array $wearing): bool => $wearing['fragment'] === '');
    $fragments = array_filter($worn, static fn (array $wearing): bool => $wearing['fragment'] !== '');
    foreach (array_reverse([...$roots, ...$fragments]) as ['variant' => $variant, 'fragment' => $fragment]) {
      foreach (SlotKey::fragmentStyles($variant->getStyles(), $fragment) as $suffix => $entry) {
        $relative = $base . $suffix;
        $entries[$relative] = isset($entries[$relative]) ? StyleEntry::merge($entries[$relative], $entry) : $entry;
      }
    }
  }

}
