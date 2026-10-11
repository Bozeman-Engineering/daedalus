<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_stylish\Selector\SelectorMatcher;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;

/**
 * Projects one style-map key's styles as its layer stack.
 *
 * The server twin of the client's style stack (js/src/style-stack.js):
 * every layer that styles the key, in DELIVERY order, bottom to top, each
 * carrying its condition-keyed styles RAW (as stored, no cascade, no
 * winner marks), so the stack reads like the printed document: a property
 * set by several layers appears once per layer and the LAST (topmost)
 * declaration at a condition wins. linesAt() is the one winner read: the
 * lines of one exact condition with index winners, what the code view
 * renders as in-place strikethrough with a winner dot and the explain
 * tool prints as "lost to" marks.
 *
 * The layers, bottom to top:
 * - the declared tier (declared:{scope}): the element's declaration's own
 *   defaults, the captured scope's family kind block then its refinement
 *   block, read at the key's relative position;
 * - the active skin's base:{tag} block (skin:base:{tag}), keyed by the
 *   READ SUBJECT's tag (subjectTag(): the one element the key reads,
 *   from the blueprint tree's annotation, `_element.markup.tag` for an
 *   element key, the declared part's tag for a part key; a tag tier, a
 *   chain or a generated box has no subject, so no base layer; without a tree none
 *   either, and baseTags beside the layers names the tags the skin
 *   carries, so a reader states that tier honestly);
 * - the skin tier (skin:{scope}): the active PUBLISHED skin's defaults,
 *   kind block then refinement block, the same ranks the defaults
 *   stylesheet prints (DefaultsStylesheet::orderedDefaults());
 * - the ancestor tier, present only when the caller passes the blueprint
 *   tree: one GROUP of layers per ancestor rule position that REACHES
 *   the key (SelectorMatcher::reaches(): an ancestor's selector entry
 *   whose targets are this element's root, or, for a tag or part key,
 *   the tag inside it or that part), farthest ancestor first and, per
 *   ancestor, positions in partSuffixes() order. A group is the
 *   position's map layers with their identities kept: the variants
 *   delivered there (the rule's own wear and its owner's worn fragments,
 *   delivery order), one layer each as
 *   ancestor:{owner}::{position}|{variant} carrying the variant's
 *   identity and its `carrier` (the address whose reference supplies it:
 *   the rule, or the owner's root), then the rule's own entry as
 *   ancestor:{owner}::{position}. Each layer folds the structural facts
 *   the element satisfies into the posable remainder of its compounds on
 *   its own (a satisfied `first-child` block delivers at rest; an
 *   unsatisfied one never applies and drops), so own beats worn by order
 *   inside the group as it does at the rule's own key. A worn layer
 *   joins the group only where it delivers (a reference is a fact of its
 *   carrier, listed empty or stale at the rule's own key; a member's
 *   tier carries what reaches the member); a group exists when any of
 *   its layers delivers at the key, and the own layer always rides its
 *   group, empty or not (a rule that only wears is still a rule that
 *   exists; an entry delivering nothing lists nothing). The
 *   tier sits here because delivery puts it here: an ancestor's rule
 *   prints at the weight of the element's own equivalent rule, so it
 *   beats every bare default and loses to the element's own `@scope` by
 *   proximity. Its tier keeps every "this element wears" reader honest:
 *   a worn ancestor layer is a variant the RULE wears, never this
 *   element (ancestorVariant() reads the identity);
 * - one layer per worn reference (variant:{name}) in delivery order
 *   (StyleMapValidator::orderedEntryRefs()), each reference's styles
 *   looked up through ITS OWN worn fragment at the key's relative
 *   position (SlotKey::fragmentStyles, the one fragment-lookup semantics
 *   delivery and detach share); a composition pair (root plus fragment
 *   reference of one variant sharing an axis) is two distinct layers with
 *   one variant, exactly as it delivers;
 * - the key's own entry (element), always present and last, the one
 *   editable layer.
 *
 * A tier layer that declares nothing at the key is not a layer (the
 * floor holds only what delivers); a worn reference is always a layer,
 * stale or empty, because the reference itself is a fact of its carrier.
 * A key's references come from its CARRIERS, in delivery order
 * (ruleCarriers(), then the root): a rule key ("{root}::{selector}")
 * wears variants of its own, delivered inside the rule owner's @scope
 * before the element lanes (VariantStylesheet::ruleWearCss()), the
 * variant's root entry at the rule's targets and its '::sel' entries
 * continued under the rule by descent (RelativeSelector::under()); then
 * the ROOT key's references answer for the root and every '::selector'
 * key under it, the selector resolving as a plain suffix (the relative
 * key the variant stores). So a rule's own stack folds its worn
 * fragments beneath its entry exactly as a root's does, and the ancestor
 * layer a rule contributes to a member's stack (ancestorLayers()) folds
 * them beneath the rule's entry too. Resolution mirrors the client.
 *
 * One theme per read: the reference order (a variant's axis position in
 * its scope's vocabulary) and the declared tier both resolve through the
 * ONE theme the caller passes, defaulted ONCE to the site's default theme
 * (the declaration spine's siteTheme(), the theme delivery compiles for),
 * never through two separate fallbacks.
 */
class StyleStack {

  /**
   * The tier of a declaration's own defaults layer.
   */
  public const TIER_DECLARED = 'declared';

  /**
   * The tier of an active skin's defaults layer.
   */
  public const TIER_SKIN = 'skin';

  /**
   * The tier of an ancestor's rule reaching the key.
   */
  public const TIER_ANCESTOR = 'ancestor';

  /**
   * The tier of a worn reference's layer.
   */
  public const TIER_VARIANT = 'variant';

  /**
   * The tier (and id) of the element's own layer.
   */
  public const TIER_ELEMENT = 'element';

  public function __construct(
    protected StyleMapValidator $validator,
    protected MaterialsRosterBuilder $rosterBuilder,
    protected StyleGuide $styleGuide,
    protected SkinManager $skinManager,
    protected PageBuilderDeclarations $declarations,
    protected ConditionTypePluginManager $conditionTypes,
  ) {}

  /**
   * The layer stack a style-map key wears, bottom to top.
   *
   * @param array $map
   *   The whole style map.
   * @param string $key
   *   A full style-map key (root, '::selector' relative, or block-field
   *   form).
   * @param array $context
   *   The validate() context; "host" (a HostInterface) enables bundle and layout resolution
   *   for the key's captured scope (the floor's block addresses).
   * @param string|null $theme
   *   The theme whose declarations order delivery and answer the declared
   *   tier; NULL = the site's default theme, resolved once for both.
   * @param array|null $tree
   *   The blueprint's lean node tree (the delivered `layout`, or
   *   NodeTreeBuilder::build()), when the caller holds
   *   it: keys the ancestor tier and the skin's base:{tag} layer (the
   *   read subject's tag, subjectTag()). NULL leaves both out.
   *
   * @return array
   *   ['key' => the key, 'rootKey' => its root key, 'relative' => the
   *   key's suffix ('' at a root), 'scope' => the captured root address as
   *   printed, 'layers' => bottom to top, each ['id' => 'declared:{scope}'
   *   | 'skin:base:{tag}' | 'skin:{scope}' | 'ancestor:{owner}::{position}'
   *   | 'ancestor:{owner}::{position}|{variant}' | 'variant:{name}'
   *   | 'element', 'tier' => one of the TIER_* constants, 'scope' => the
   *   declaring block address (tier layers), the owning ancestor's key
   *   (ancestor layers, own and worn) or the variant's roster scope (NULL
   *   when unresolvable or own), 'variant' => the reference (NULL for
   *   tier, own-ancestor and own layers; a worn ancestor layer's is the
   *   variant the RULE wears), 'fragment' => the worn fragment or NULL,
   *   'via' => the composition that distributed this wearing onto its
   *   carrier (its machine name) or NULL for a wearing the editor applied
   *   and for every other layer, 'viaLabel' => that composition's roster
   *   label (NULL when none or unresolvable: a stale origin is inert),
   *   'label' / 'description' / 'axis' => the variant's roster record's
   *   facts (an own ancestor layer's label is its owner's minted name or
   *   NULL; NULL otherwise), 'owner' => an ancestor layer's (own and
   *   worn) owner name, the owner node's `_element.name` minted by
   *   daedalus_ui's annotator (NULL when the tree names none; absent on
   *   every other layer), 'resolved' => bool (FALSE = stale reference,
   *   delivers nothing), 'editable' => TRUE for the own layer only,
   *   'carrier' => the address ['key' => root key, 'selector' => the
   *   rule's relative selector, or NULL for the root entry] whose entry
   *   wears the reference a layer reads (a variant layer, a worn ancestor
   *   layer), NULL for every other layer, 'styles' => condition =>
   *   property => value, raw as stored], 'baseTags' => the tags the active
   *   skin carries base:{tag} defaults for, stored order ([] without a
   *   skin), 'skin' => whether an active published skin exists].
   *
   * @throws \InvalidArgumentException
   *   When the key is not a style map key.
   */
  public function stack(array $map, string $key, array $context = [], ?string $theme = NULL, ?array $tree = NULL): array {
    $theme ??= $this->declarations->siteTheme();
    $root = $this->validator->keyAddress($key, $context)?->root()
      ?? throw new \InvalidArgumentException(sprintf('"%s" is not a style map key.', $key));
    $pos = strpos($key, '::');
    $root_key = $pos === FALSE ? $key : substr($key, 0, $pos);
    $relative = substr($key, strlen($root_key));
    // The tree, indexed once for both tiers it keys: the subject's tag
    // for the base layer and the ancestor walk.
    $matcher = $tree === NULL ? NULL : SelectorMatcher::fromTree($tree);
    $tag = $matcher === NULL ? NULL : $this->subjectTag($matcher, $root_key, $relative);

    // The floor's block addresses: the family kind's block beneath the
    // refinement's, the rank order delivery prints both tiers in.
    $scopes = [];
    $kind = $root->elementKind();
    if ($kind !== NULL && !$root->isKind()) {
      $scopes[] = (string) Address::kind($kind);
    }
    $scopes[] = (string) $root;

    $skin = $this->skinManager->loadActiveSkin();
    $skin_defaults = $skin !== NULL && $skin->isPublished() ? array_filter($skin->defaults(), 'is_array') : NULL;

    $layers = [];
    $declared = $this->styleGuide->declaredDefaults($theme);
    foreach ($scopes as $scope) {
      $this->pushTierLayer($layers, self::TIER_DECLARED, $scope, $declared[$scope][$relative] ?? NULL);
    }
    if ($tag !== NULL && $skin_defaults !== NULL) {
      // A base block styles the subject element itself, whatever key
      // names it, so it reads at the root position.
      $base = (string) Address::base($tag);
      $this->pushTierLayer($layers, self::TIER_SKIN, $base, $skin_defaults[$base][''] ?? NULL);
    }
    foreach ($scopes as $scope) {
      $this->pushTierLayer($layers, self::TIER_SKIN, $scope, $skin_defaults[$scope][$relative] ?? NULL);
    }

    $roster = $this->rosterBuilder->build();
    if ($matcher !== NULL) {
      array_push($layers, ...$this->ancestorLayers($map, $root_key, $relative, $theme, $roster, $matcher));
    }
    array_push($layers, ...$this->mapLayers($map, $root_key, $relative, $theme, $roster));

    $base_tags = [];
    foreach (array_keys($skin_defaults ?? []) as $scope) {
      $address = Address::parse((string) $scope);
      if ($address !== NULL && $address->isBase()) {
        $base_tags[] = $address->id;
      }
    }
    return [
      'key' => $key,
      'rootKey' => $root_key,
      'relative' => $relative,
      'scope' => (string) $root,
      'layers' => $layers,
      'baseTags' => $base_tags,
      'skin' => $skin_defaults !== NULL,
    ];
  }

  /**
   * The map's layers at a key: its carriers' worn references, then the own entry.
   *
   * The carriers in delivery order: the rules wearing at the key
   * (ruleCarriers(), a '::' key only), then the root; each reference
   * layer names its carrier (the rule's address, or the root's with a
   * NULL selector).
   *
   * @return array[]
   *   The variant layers in delivery order, the element layer last.
   */
  protected function mapLayers(array $map, string $root_key, string $relative, ?string $theme, MaterialsRoster $roster): array {
    $layers = [];
    foreach ($this->ruleCarriers($map, $root_key, $relative) as [$rule_key, $at]) {
      $carrier = ['key' => $root_key, 'selector' => substr($rule_key, strlen($root_key) + strlen(RelativeSelector::SUFFIX))];
      foreach ($this->validator->orderedEntryRefs($map[$rule_key], $theme) as $ref) {
        $layers[] = $this->referenceLayer($ref, $at, $roster, $carrier);
      }
    }
    foreach ($this->validator->orderedEntryRefs($map[$root_key] ?? NULL, $theme) as $ref) {
      $layers[] = $this->referenceLayer($ref, $relative, $roster, ['key' => $root_key, 'selector' => NULL]);
    }
    $key = $root_key . $relative;
    $own = is_array($map[$key] ?? NULL) ? $map[$key] : [];
    $layers[] = [
      'id' => self::TIER_ELEMENT,
      'tier' => self::TIER_ELEMENT,
      'scope' => NULL,
      'variant' => NULL,
      'fragment' => NULL,
      'via' => NULL,
      'viaLabel' => NULL,
      'label' => NULL,
      'description' => NULL,
      'axis' => NULL,
      'resolved' => TRUE,
      'editable' => TRUE,
      'carrier' => NULL,
      'styles' => $this->conditionValues($own),
    ];
    return $layers;
  }

  /**
   * One worn reference's layer, its styles read at a relative position.
   *
   * @param array $ref
   *   An entryRefs() item.
   * @param string $at
   *   The relative position inside the reference's fragment ('' or a
   *   '::selector' suffix).
   * @param \Drupal\daedalus_blueprint\Materials\MaterialsRoster $roster
   *   The roster the reference's facts come from.
   * @param array $carrier
   *   The address whose entry wears the reference: ['key' => the root
   *   key, 'selector' => the rule's relative selector, or NULL for the
   *   root entry].
   */
  protected function referenceLayer(array $ref, string $at, MaterialsRoster $roster, array $carrier): array {
    // The entity carries the styles; the roster record carries the
    // material's facts (loadVariant() answers for roster members only).
    $variant = $this->validator->loadVariant($ref['variant']);
    $record = $variant === NULL ? NULL : $roster->get(MaterialKind::Variant, $variant->getMachineName());
    $styles = $variant === NULL
      ? []
      : SlotKey::fragmentStyles($variant->getStyles(), $ref['fragment'])[$at] ?? [];
    // The origin resolves the same way: the map stores its machine
    // name, the roster names it for the reader.
    $origin = $ref['via'] === NULL ? NULL : $this->validator->loadVariant($ref['via']);
    return [
      'id' => self::TIER_VARIANT . ':' . $ref['variant'],
      'tier' => self::TIER_VARIANT,
      'scope' => $record?->scope?->__toString(),
      'variant' => $ref['variant'],
      'fragment' => $ref['fragment'],
      'via' => $ref['via'],
      'viaLabel' => $origin === NULL ? NULL : $roster->get(MaterialKind::Variant, $origin->getMachineName())?->label,
      'label' => $record?->label,
      'description' => $record?->description,
      'axis' => $record?->group,
      'resolved' => $record !== NULL,
      'editable' => FALSE,
      'carrier' => $carrier,
      'styles' => $this->conditionValues($styles),
    ];
  }

  /**
   * The rules whose wear delivers at a '::' key, in delivery order.
   *
   * Wear at a rule prints inside the owner's @scope with the variant's
   * suffixes continued under the rule by descent (the printer's rebase,
   * RelativeSelector::under()), so a rule R = "{root}::{r}" delivers at
   * the key K = "{root}::{sel}" when K is R itself (the variant's root
   * entry, read at '') or when one of R's worn variants holds a '::s'
   * entry with under(r, s) printing sel (read at '::s'). The rules come
   * in map order, as VariantStylesheet::ruleWearCss() prints them; a
   * rule's every reference is then a layer at that position, empty or
   * not, exactly as a root's references are at a part key.
   *
   * @return array<int, array{string, string}>
   *   [rule key, relative position] pairs; [] at a root key.
   */
  protected function ruleCarriers(array $map, string $root_key, string $relative): array {
    if ($relative === '') {
      return [];
    }
    $key = $root_key . $relative;
    $sel = substr($relative, strlen(RelativeSelector::SUFFIX));
    $carriers = [];
    foreach ($map as $rule_key => $entry) {
      $rule_key = (string) $rule_key;
      if (!is_array($entry) || !str_starts_with($rule_key, $root_key . RelativeSelector::SUFFIX)) {
        continue;
      }
      if ($rule_key === $key) {
        $carriers[] = [$rule_key, ''];
        continue;
      }
      $rule = RelativeSelector::splitKey($rule_key)[1] ?? NULL;
      if ($rule === NULL) {
        continue;
      }
      $ats = [];
      foreach (StyleMapValidator::entryRefs($entry) as $ref) {
        $variant = $this->validator->loadVariant($ref['variant']);
        if ($variant === NULL) {
          continue;
        }
        foreach (array_keys(SlotKey::fragmentStyles($variant->getStyles(), $ref['fragment'])) as $suffix) {
          $suffix = (string) $suffix;
          $continued = $suffix === '' ? NULL : RelativeSelector::splitKey($suffix)[1] ?? NULL;
          if ($continued !== NULL && (string) RelativeSelector::under($rule, $continued) === $sel) {
            $ats[$suffix] = TRUE;
          }
        }
      }
      foreach (array_keys($ats) as $at) {
        $carriers[] = [$rule_key, (string) $at];
      }
    }
    return $carriers;
  }

  /**
   * The read subject's tag: the ONE element a style-map key reads.
   *
   * The subject is the element itself for an element key (its rendered
   * root tag, the annotation's `_element.markup.tag`), its declared part
   * for a part key (`::.cls`: the part's declared tag, NULL for a part
   * declaring none), and nothing for a tag tier or a chain (`::p`,
   * `::> *`, `::> pb-text .link`: a rule's matches are many, so no one
   * tag stands for them) nor for a pseudo-element (`::&::before`,
   * `::.cls::after`: a generated box is no element and renders as no
   * tag, so no base:{tag} block styles it; it is floorless there like a
   * chain). The tag keys the skin's base:{tag} layer; it is a fact of
   * the tree, never of a rendered document, so every reader of the
   * stack answers alike (style-stack.js subjectTag mirror). NULL for a
   * key the tree does not carry (an entity or field key, a bare key
   * outside it) and for every subjectless key.
   *
   * @param \Drupal\daedalus_stylish\Selector\SelectorMatcher $matcher
   *   The matcher over the blueprint tree.
   * @param string $root_key
   *   The key's root.
   * @param string $relative
   *   The key's '::selector' suffix ('' at the root).
   *
   * @return string|null
   *   The subject's tag, or NULL.
   */
  protected function subjectTag(SelectorMatcher $matcher, string $root_key, string $relative): ?string {
    $facts = $matcher->facts($root_key);
    if ($facts === NULL) {
      return NULL;
    }
    if ($relative === '') {
      return $facts['tag'];
    }
    // A declared part: ONE unqualified class compound reached by descent
    // (the anatomy's spelling), with no generated box behind it.
    $selector = RelativeSelector::parse(substr($relative, strlen(RelativeSelector::SUFFIX)));
    if ($selector === NULL || $selector->pseudo !== NULL || $selector->count() !== 1) {
      return NULL;
    }
    $compound = $selector->first();
    $part = $compound['combinator'] === RelativeSelector::DESCENDANT
      && $compound['qualifiers'] === []
      && $compound['kind'] === RelativeSelector::CLASS_PART
      && array_key_exists($compound['name'], $facts['parts']);
    return $part ? $facts['parts'][$compound['name']] : NULL;
  }

  /**
   * The ancestor tier: every ancestor rule position reaching the key as a
   * group of layers, farthest ancestor first.
   *
   * A key has ancestor layers at its root (the ancestor's rule targets
   * this element), at a tag tier (`::p`: the rule's subject is that tag
   * inside this element), at a part (`::.cls`: the rule qualifies a
   * type compound this element matches) and at the generated box of any
   * of those (`::&::before`, `::p::after`, `::.cls::before`: the rule
   * ends in the same pseudo-element and reaches the host as it would the
   * pseudo-free position, SelectorMatcher::reaches()); any other
   * selector key is a rule of its own. Per ancestor, its selector suffixes come in the
   * order partSuffixes() lists them (the rules' continued suffixes, the
   * root's worn fragments' suffixes, then the map's); each reaching
   * position contributes its map layers (mapLayers() at the ancestor's
   * position: the rule's own wear and the owner's wear at the position,
   * then the rule's entry) with their identities kept, each folded on
   * its own (foldFacts()), the worn ones as worn ancestor layers naming
   * the variant and its carrier where they deliver, the entry as the own
   * ancestor layer. A group exists when any of its layers delivers at
   * the key, and the own layer rides its group empty or not; a position
   * delivering nothing contributes no layer. The client's
   * ancestorLayers() mirror.
   *
   * @param \Drupal\daedalus_stylish\Selector\SelectorMatcher $matcher
   *   The matcher over the blueprint tree.
   *
   * @return array[]
   *   The ancestor layers.
   */
  protected function ancestorLayers(array $map, string $root_key, string $relative, ?string $theme, MaterialsRoster $roster, SelectorMatcher $matcher): array {
    if (!$matcher->has($root_key)) {
      return [];
    }
    $sub = NULL;
    if ($relative !== '') {
      $selector = RelativeSelector::parse(substr($relative, strlen(RelativeSelector::SUFFIX)));
      if ($selector === NULL) {
        return [];
      }
      // The `::p` or `::.cls` tier is ONE unqualified compound reached by
      // descent, with or without its generated box behind it; the
      // owner's own box (`::&::before`) has no compound at all. A
      // qualified compound (`p:has(a)`) or a chain is a rule of its own.
      $tier = $selector->count() === 0
        || ($selector->count() === 1
          && $selector->first()['combinator'] === RelativeSelector::DESCENDANT
          && $selector->first()['qualifiers'] === []
          && in_array($selector->first()['kind'], [RelativeSelector::TAG, RelativeSelector::CLASS_PART], TRUE));
      if (!$tier) {
        return [];
      }
      // The position with its pseudo: the matcher compares the boxes by
      // name and answers the host.
      $sub = $selector;
    }
    $layers = [];
    foreach ($matcher->ancestors($root_key) as $ancestor) {
      // The owner's name for the sentences: the element-naming spine's
      // minted word on the owner node (the matcher's `name` fact), the
      // client twin's ownerOf().
      $owner = $matcher->facts($ancestor)['name'];
      foreach ($this->partSuffixes($map, $ancestor, $theme) as $suffix) {
        $position = substr($suffix, strlen(RelativeSelector::SUFFIX));
        $selector = RelativeSelector::parse($position);
        if ($selector === NULL || !$matcher->reaches($ancestor, $selector, $root_key, $sub)) {
          continue;
        }
        $group = [];
        $delivers = FALSE;
        foreach ($this->mapLayers($map, $ancestor, $suffix, $theme, $roster) as $layer) {
          $styles = $this->foldFacts($layer['styles'], $matcher, $root_key);
          $delivers = $delivers || $styles !== [];
          if ($layer['tier'] === self::TIER_ELEMENT) {
            // The position's own layer: the rule's entry, read from the
            // member's place.
            $group[] = [
              'id' => self::TIER_ANCESTOR . ':' . $ancestor . $suffix,
              'tier' => self::TIER_ANCESTOR,
              'scope' => $ancestor,
              'variant' => NULL,
              'fragment' => NULL,
              'via' => NULL,
              'viaLabel' => NULL,
              'label' => $owner,
              'owner' => $owner,
              'description' => NULL,
              'axis' => NULL,
              'resolved' => TRUE,
              'editable' => FALSE,
              'carrier' => NULL,
              'styles' => $styles,
            ];
            continue;
          }
          // A worn layer of the position re-identified as the ancestor
          // tier's, where it delivers (the reference is a fact of its
          // carrier, listed empty or stale at the rule's own key; a
          // member's tier carries what reaches the member): the
          // variant's identity and its carrier kept, scoped to the owner
          // like the own layer, never editable (a doorway edits at the
          // carrier).
          if ($styles === []) {
            continue;
          }
          $layer['id'] = self::TIER_ANCESTOR . ':' . $ancestor . $suffix . '|' . $layer['variant'];
          $layer['tier'] = self::TIER_ANCESTOR;
          $layer['scope'] = $ancestor;
          $layer['editable'] = FALSE;
          $layer['styles'] = $styles;
          $layer['owner'] = $owner;
          $group[] = $layer;
        }
        if ($delivers) {
          array_push($layers, ...$group);
        }
      }
    }
    return $layers;
  }

  /**
   * The rule an ancestor layer id names: the position its value reaches
   * the key through, for the own and the worn spelling alike.
   *
   * The client's ancestorRule() twin (js/src/code/layers.js): the id
   * after the tier prefix is `{owner}::{position}`, with `|{variant}`
   * appended for a worn layer; `|` is a character neither the selector
   * grammar nor a machine name admits, so the tail after the LAST one is
   * the variant. A worn layer's carrier (the address wearing the
   * variant) is the layer's own `carrier` field, not the id.
   *
   * @param string $id
   *   A layer id.
   *
   * @return array|null
   *   ['key' => the owner's key, 'selector' => the position], or NULL for
   *   any id that is not an ancestor layer's.
   */
  public function ancestorRule(string $id): ?array {
    $prefix = self::TIER_ANCESTOR . ':';
    if (!str_starts_with($id, $prefix)) {
      return NULL;
    }
    $scope = substr($id, strlen($prefix));
    $bar = strrpos($scope, '|');
    $rule = $bar === FALSE ? $scope : substr($scope, 0, $bar);
    $pos = strpos($rule, RelativeSelector::SUFFIX);
    return $pos === FALSE
      ? NULL
      : ['key' => substr($rule, 0, $pos), 'selector' => substr($rule, $pos + strlen(RelativeSelector::SUFFIX))];
  }

  /**
   * The variant a worn ancestor layer id carries, or NULL for an own
   * ancestor layer and for any other id (the client's ancestorVariant()
   * twin).
   */
  public function ancestorVariant(string $id): ?string {
    if (!str_starts_with($id, self::TIER_ANCESTOR . ':')) {
      return NULL;
    }
    $bar = strrpos($id, '|');
    return $bar === FALSE ? NULL : substr($id, $bar + 1);
  }

  /**
   * Folds a rule's structural facts into what it delivers to one element.
   *
   * A condition compound carrying facts (`first-child`, `hover+odd`)
   * applies to the element only when every fact holds for it
   * (SelectorMatcher::satisfies() by the fact's printed wrapper): a
   * compound with an unsatisfied fact drops, a satisfied one delivers
   * under its posable remainder (`default`, `hover`), where its values
   * beat a remainder that carried fewer facts (each fact is one
   * pseudo-class of specificity) and, at equal facts, a later block
   * beats an earlier one (document order). The element's own keys keep
   * their fact conditions raw (the code view lights them); only another
   * element's rule is read from this element's place.
   *
   * The client's foldFacts() mirror.
   */
  protected function foldFacts(array $entry, SelectorMatcher $matcher, string $key): array {
    $table = $this->conditionTypes->getConditions();
    $folded = [];
    $weights = [];
    foreach ($entry as $condition => $properties) {
      $keep = [];
      $facts = 0;
      $holds = TRUE;
      foreach (explode('+', (string) $condition) as $part) {
        if (!$this->conditionTypes->isFact($part)) {
          $keep[] = $part;
          continue;
        }
        $facts++;
        if (!$matcher->satisfies($key, (string) ($table[$part]['wrapper'] ?? ''))) {
          $holds = FALSE;
          break;
        }
      }
      if (!$holds) {
        continue;
      }
      $target = $keep === [] ? 'default' : implode('+', $keep);
      foreach ($properties as $property => $value) {
        if (isset($weights[$target][$property]) && $weights[$target][$property] > $facts) {
          continue;
        }
        $folded[$target][$property] = $value;
        $weights[$target][$property] = $facts;
      }
    }
    return $folded;
  }

  /**
   * The stack's lines at one exact condition, winners marked by index.
   *
   * The cascade's own reading, at a stored condition (no cascade across
   * conditions: the client's posed read is a client-only projection): per
   * property the LAST defining layer wins, delivery order with the own
   * layer on top, and every earlier defining layer is lost to it. Lines
   * come in layer order, then the layer's property order.
   *
   * @param array $stack
   *   A stack() result.
   * @param string $condition
   *   A stored condition ('default', 'hover', 'narrow', a compound).
   *
   * @return array[]
   *   Each ['layer' => index into the stack's layers, 'property' =>
   *   string, 'value' => string, 'lost' => bool, 'winner' => the winning
   *   layer's index when lost, else NULL]; [] when no layer sets anything
   *   at the condition.
   */
  public function linesAt(array $stack, string $condition): array {
    $winners = [];
    foreach ($stack['layers'] as $index => $layer) {
      foreach (array_keys($layer['styles'][$condition] ?? []) as $property) {
        $winners[$property] = $index;
      }
    }
    $lines = [];
    foreach ($stack['layers'] as $index => $layer) {
      foreach ($layer['styles'][$condition] ?? [] as $property => $value) {
        $lost = $winners[$property] !== $index;
        $lines[] = [
          'layer' => $index,
          'property' => $property,
          'value' => $value,
          'lost' => $lost,
          'winner' => $lost ? $winners[$property] : NULL,
        ];
      }
    }
    return $lines;
  }

  /**
   * The '::selector' suffixes any layer styles at an element key.
   *
   * The key's own suffix space: selector entries the map carries beside
   * the key, plus the selector styles each worn reference's fragment
   * delivers (selectors ride their element), plus the selectors a rule's
   * wear continues under it (a rule "::> pb-text" wearing a variant with
   * a '::.link' entry delivers at "::> pb-text .link", the same
   * continuation the printer rebases by). A generated box is one more
   * suffix of the root: `::&::before` and `::.quote::after` list as
   * siblings of `::.quote`, never under it, and a rule's worn `::&::before`
   * entry continues under the rule as `::> pb-text::before` (the anchor
   * binds to the rule's landing, RelativeSelector::under()). Delivery
   * order: the rules' continued suffixes first (rule wear prints before
   * the element lanes, rules in map order), then the root's
   * worn-reference suffixes, then map-only suffixes in map order.
   *
   * @return string[]
   *   '::selector' suffixes, deduplicated; [] for a key that already
   *   carries a selector (selectors do not nest under a key, and a
   *   pseudo-element is terminal).
   */
  public function partSuffixes(array $map, string $key, ?string $theme = NULL): array {
    if (str_contains($key, '::')) {
      return [];
    }
    $theme ??= $this->declarations->siteTheme();
    $suffixes = [];
    foreach ($map as $rule_key => $entry) {
      $rule_key = (string) $rule_key;
      if (!is_array($entry) || !str_starts_with($rule_key, $key . RelativeSelector::SUFFIX)) {
        continue;
      }
      $rule = RelativeSelector::splitKey($rule_key)[1] ?? NULL;
      if ($rule === NULL) {
        continue;
      }
      foreach ($this->validator->orderedEntryRefs($entry, $theme) as $ref) {
        $variant = $this->validator->loadVariant($ref['variant']);
        if ($variant === NULL) {
          continue;
        }
        foreach (array_keys(SlotKey::fragmentStyles($variant->getStyles(), $ref['fragment'])) as $suffix) {
          $continued = $suffix === '' ? NULL : RelativeSelector::splitKey((string) $suffix)[1] ?? NULL;
          $under = $continued === NULL ? NULL : RelativeSelector::under($rule, $continued);
          if ($under !== NULL) {
            $suffixes[RelativeSelector::SUFFIX . $under] = TRUE;
          }
        }
      }
    }
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
   * Appends a floor tier's block as a layer when it declares anything.
   */
  protected function pushTierLayer(array &$layers, string $tier, string $scope, mixed $entry): void {
    $styles = is_array($entry) ? $this->conditionValues($entry) : [];
    if ($styles === []) {
      return;
    }
    $layers[] = [
      'id' => "$tier:$scope",
      'tier' => $tier,
      'scope' => $scope,
      'variant' => NULL,
      'fragment' => NULL,
      'via' => NULL,
      'viaLabel' => NULL,
      'label' => NULL,
      'description' => NULL,
      'axis' => NULL,
      'resolved' => TRUE,
      'editable' => FALSE,
      'carrier' => NULL,
      'styles' => $styles,
    ];
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

}
