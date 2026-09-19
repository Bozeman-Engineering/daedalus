<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;

/**
 * Projects one style-map key's styles as its layer stack.
 *
 * The server twin of the code view's layered document (islandLines in
 * js/src/code/island.js, generalized over conditions): every worn layer's
 * declarations at the key, in DELIVERY order, each reference's fragment
 * styles first (orderedEntryRefs order) and the element's own overrides
 * last, so the stack reads like the cascade: a property set by several
 * layers appears once per layer and the LAST (topmost) declaration wins.
 * Losing declarations are marked with the winning layer, which is exactly
 * what the code view renders as in-place strikethrough with a winner dot.
 *
 * Resolution mirrors the client (effectiveEntry/islandLines): the ROOT
 * key's references answer for the root and its '::.class' parts, each
 * reference's styles looked up through ITS OWN worn fragment at the key's
 * relative position (SlotKey::fragmentStyles, the one fragment-lookup
 * semantics delivery and detach share). A composition pair (root plus
 * fragment reference of one variant sharing an axis) is two distinct
 * layers, exactly as it delivers.
 *
 * Beneath every layer sits the defaults floor (the element's declaration's
 * own defaults, then the active skin's over them). floor() enumerates the
 * half of it that lives in declaration space (the captured scope's kind and
 * refinement blocks of both tiers, the same entries the code view's floor
 * lines print), so the model and the printed document tell one story. The
 * skin's base:{tag} half keys off the element's RENDERED tag, a live-DOM
 * fact the server never holds, so floor() names the tags the skin carries
 * and callers say that tier resolves in the browser.
 */
class StyleStack {

  public function __construct(
    protected StyleMapValidator $validator,
    protected MaterialsRosterBuilder $rosterBuilder,
    protected StyleGuide $styleGuide,
    protected SkinManager $skinManager,
  ) {}

  /**
   * The layers a style-map key wears, bottom to top.
   *
   * @param array $map
   *   The whole style map.
   * @param string $key
   *   A full style-map key (root, '::.class' part, or block-field form).
   * @param string|null $theme
   *   The theme whose axis declarations order delivery; NULL = active.
   *
   * @return array[]
   *   One layer per worn reference in delivery order, then the element's own
   *   layer (always present, last): ['variant' => reference, NULL for the
   *   element's own layer, 'fragment' => worn fragment or NULL, 'via' => the
   *   composition that distributed this wearing onto the key (the reference's
   *   provenance, its machine name) or NULL for a wearing the editor applied
   *   and for the own layer, 'via_label' => that composition's roster label
   *   (NULL when none or unresolvable: a stale origin is inert and the wearing
   *   reads as the element's own), 'label'/'axis'/'scope' => the variant's
   *   roster record's facts (NULL when unresolvable or own), 'resolved' => bool
   *   (FALSE = stale reference, delivers nothing), 'styles' => condition =>
   *   property => ['value' => string, 'lost' => bool, 'winner' => layer index
   *   or NULL]]. For any (condition, property) the LAST defining layer wins;
   *   every earlier defining layer is lost to it.
   */
  public function layers(array $map, string $key, ?string $theme = NULL): array {
    $pos = strpos($key, '::');
    $root_key = $pos === FALSE ? $key : substr($key, 0, $pos);
    $relative = substr($key, strlen($root_key));

    $layers = [];
    $roster = $this->rosterBuilder->build();
    foreach ($this->validator->orderedEntryRefs($map[$root_key] ?? NULL, $theme) as $ref) {
      // The entity carries the styles; the roster record carries the
      // material's facts (loadVariant() answers for roster members only).
      $variant = $this->validator->loadVariant($ref['variant']);
      $record = $variant === NULL ? NULL : $roster->get(MaterialKind::Variant, $variant->getMachineName());
      $styles = $variant === NULL
        ? []
        : SlotKey::fragmentStyles($variant->getStyles(), $ref['fragment'])[$relative] ?? [];
      // The origin resolves the same way: the map stores its machine
      // name, the roster names it for the reader.
      $origin = $ref['via'] === NULL ? NULL : $this->validator->loadVariant($ref['via']);
      $layers[] = [
        'variant' => $ref['variant'],
        'fragment' => $ref['fragment'],
        'via' => $ref['via'],
        'via_label' => $origin === NULL ? NULL : $roster->get(MaterialKind::Variant, $origin->getMachineName())?->label,
        'label' => $record?->label,
        'description' => $record?->description,
        'axis' => $record?->group,
        'scope' => $record?->scope?->__toString(),
        'resolved' => $record !== NULL,
        'styles' => $this->conditionValues($styles),
      ];
    }
    $own = is_array($map[$key] ?? NULL) ? $map[$key] : [];
    $layers[] = [
      'variant' => NULL,
      'fragment' => NULL,
      'via' => NULL,
      'via_label' => NULL,
      'label' => NULL,
      'description' => NULL,
      'axis' => NULL,
      'scope' => NULL,
      'resolved' => TRUE,
      'styles' => $this->conditionValues($own),
    ];

    return $this->markWinners($layers);
  }

  /**
   * The defaults floor beneath a key's stack: the tiers this read can see.
   *
   * The declaration-space half of the defaults tier, enumerated the way
   * the code view's floor lines are (skinDefaultEntry and
   * declaredDefaultEntry in js/src/variant-scope.js): the key's captured
   * root address (captureScope(); the host entity in $context resolves a
   * block's bundle and a container's layout, else the family kind answers)
   * selects, for each tier, the family kind's block and the refinement's,
   * read at the key's relative position ('' the root, '::.part' a declared
   * part). The tiers stack bottom to top in delivery rank order
   * (DefaultsStylesheet::orderedDefaults(): a kind's block before its
   * refinement's, and the declared tier prints beneath the skin's at equal
   * specificity), so the last defining tier wins among them and every
   * stack layer beats them all. The skin reads like delivery: the active
   * skin's published revision, nothing without one.
   *
   * The base:{tag} half stays out: a skin's base defaults key off the
   * element's RENDERED tag, which only the browser knows, so the floor
   * lists the tags the skin carries and a caller states that tier honestly
   * instead of guessing the element's markup.
   *
   * @param array $map
   *   The whole style map.
   * @param string $key
   *   A full style-map key (root, '::.class' part, or block-field form).
   * @param array $context
   *   The validate() context; "entity" enables bundle and layout resolution.
   * @param string|null $theme
   *   The theme whose declarations answer the declared tier; NULL = active.
   *
   * @return array
   *   ['scope' => the captured root address as printed, 'tiers' => bottom
   *   to top, each ['tier' => 'declared'|'skin', 'scope' => the block's
   *   address, 'styles' => condition => property => ['value' => string,
   *   'lost' => bool, 'winner' => ['tier' => floor index] | ['layer' =>
   *   index into layers()] | NULL]], 'base_tags' => the tags the active
   *   skin carries base:{tag} defaults for, stored order ([] without a
   *   skin), 'skin' => whether an active published skin exists].
   *
   * @throws \InvalidArgumentException
   *   When the key is not a style map key.
   */
  public function floor(array $map, string $key, array $context = [], ?string $theme = NULL): array {
    $root = $this->validator->captureScope($key, $context)
      ?? throw new \InvalidArgumentException(sprintf('"%s" is not a style map key.', $key));
    $pos = strpos($key, '::');
    $relative = $pos === FALSE ? '' : substr($key, $pos);
    $scopes = [];
    $kind = $root->elementKind();
    if ($kind !== NULL && !$root->isKind()) {
      $scopes[] = (string) Address::kind($kind);
    }
    $scopes[] = (string) $root;

    $skin = $this->skinManager->loadActiveSkin();
    $skin_defaults = $skin !== NULL && $skin->isPublished() ? array_filter($skin->getDefaults(), 'is_array') : NULL;
    $sources = [
      'declared' => $this->styleGuide->declaredDefaults($theme),
      'skin' => $skin_defaults ?? [],
    ];
    $tiers = [];
    foreach ($sources as $tier => $defaults) {
      foreach ($scopes as $scope) {
        $entry = $defaults[$scope][$relative] ?? NULL;
        $styles = is_array($entry) ? $this->conditionValues($entry) : [];
        if ($styles !== []) {
          $tiers[] = ['tier' => $tier, 'scope' => $scope, 'styles' => $styles];
        }
      }
    }
    $base_tags = [];
    foreach (array_keys($skin_defaults ?? []) as $scope) {
      $address = Address::parse((string) $scope);
      if ($address !== NULL && $address->isBase()) {
        $base_tags[] = $address->id;
      }
    }
    return [
      'scope' => (string) $root,
      'tiers' => $this->markFloorWinners($tiers, $this->layers($map, $key, $theme)),
      'base_tags' => $base_tags,
      'skin' => $skin_defaults !== NULL,
    ];
  }

  /**
   * The '::.class' part suffixes any layer styles at an element key.
   *
   * The key's own suffix space: part entries the map carries beside the
   * key, plus the part styles each worn reference's fragment delivers
   * (parts ride their element). Worn-reference suffixes come first in
   * delivery order, then map-only suffixes in map order.
   *
   * @return string[]
   *   '::.class' suffixes, deduplicated; [] for a key that already names
   *   a part (parts have no sub-parts).
   */
  public function partSuffixes(array $map, string $key, ?string $theme = NULL): array {
    if (str_contains($key, '::')) {
      return [];
    }
    $suffixes = [];
    foreach ($this->validator->orderedEntryRefs($map[$key] ?? NULL, $theme) as $ref) {
      $variant = $this->validator->loadVariant($ref['variant']);
      if ($variant === NULL) {
        continue;
      }
      foreach (array_keys(SlotKey::fragmentStyles($variant->getStyles(), $ref['fragment'])) as $suffix) {
        if ($suffix !== '') {
          $suffixes[$suffix] = TRUE;
        }
      }
    }
    foreach ($map as $map_key => $entry) {
      $map_key = (string) $map_key;
      if (str_starts_with($map_key, $key . '::') && is_array($entry)) {
        $suffixes[substr($map_key, strlen($key))] = TRUE;
      }
    }
    return array_keys($suffixes);
  }

  /**
   * A style entry's condition-keyed values, normalized to strings.
   *
   * The values-only read: the reserved "variants" key is not a condition
   * and never reads here; non-array conditions and null values drop.
   */
  protected function conditionValues(array $entry): array {
    $conditions = [];
    foreach (array_diff_key($entry, array_flip(StyleMapValidator::RESERVED_ENTRY_KEYS)) as $condition => $values) {
      if (!is_array($values)) {
        continue;
      }
      foreach ($values as $property => $value) {
        if ($value === NULL || is_array($value)) {
          continue;
        }
        $conditions[(string) $condition][(string) $property] = trim((string) $value);
      }
    }
    return $conditions;
  }

  /**
   * Marks each declaration lost or winning across the stack.
   *
   * Per (condition, property) the LAST defining layer wins: delivery
   * order with the own layer on top, the cascade's own reading.
   */
  protected function markWinners(array $layers): array {
    $winners = [];
    foreach ($layers as $index => $layer) {
      foreach ($layer['styles'] as $condition => $values) {
        foreach (array_keys($values) as $property) {
          $winners[$condition][$property] = $index;
        }
      }
    }
    foreach ($layers as $index => &$layer) {
      foreach ($layer['styles'] as $condition => &$values) {
        foreach ($values as $property => $value) {
          $lost = $winners[$condition][$property] !== $index;
          $values[$property] = [
            'value' => $value,
            'lost' => $lost,
            'winner' => $lost ? $winners[$condition][$property] : NULL,
          ];
        }
      }
      unset($values);
    }
    unset($layer);
    return $layers;
  }

  /**
   * Marks each floor declaration lost or winning beneath the stack.
   *
   * The floor's own reading of markWinners(): per (condition, property)
   * the last defining tier wins among the tiers, and any stack layer
   * defining it beats every tier (a floor value never wins over a map
   * value, the code view's rule too), so a lost declaration names either
   * the tier or the layer that beat it.
   */
  protected function markFloorWinners(array $tiers, array $layers): array {
    $winners = [];
    foreach ($tiers as $index => $tier) {
      foreach ($tier['styles'] as $condition => $values) {
        foreach (array_keys($values) as $property) {
          $winners[$condition][$property] = ['tier' => $index];
        }
      }
    }
    foreach ($layers as $index => $layer) {
      foreach ($layer['styles'] as $condition => $values) {
        foreach (array_keys($values) as $property) {
          $winners[$condition][$property] = ['layer' => $index];
        }
      }
    }
    foreach ($tiers as $index => &$tier) {
      foreach ($tier['styles'] as $condition => &$values) {
        foreach ($values as $property => $value) {
          $lost = $winners[$condition][$property] !== ['tier' => $index];
          $values[$property] = [
            'value' => $value,
            'lost' => $lost,
            'winner' => $lost ? $winners[$condition][$property] : NULL,
          ];
        }
      }
      unset($values);
    }
    unset($tier);
    return $tiers;
  }

}
