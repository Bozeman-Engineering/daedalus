<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Materials;

/**
 * One material as the roster carries it, whatever its kind.
 *
 * The typed record every material-facing surface projects from. Nothing
 * here is new data: each field already lives on some entity or file
 * (skin tokens, daedalus_swatch, daedalus_variant, blueprint,
 * daedalus_animation); the record is
 * the one shape those sources are read INTO, so the catalog, the cached
 * prompt, the schema, the validators, the client store and the delivery
 * CSS all read the same facts instead of walking the entities on their
 * own. Immutable: a record is a fact about the site at build time; a
 * change is a new roster build (MaterialsRosterBuilder::reset()).
 *
 * Fields by kind (NULL / [] / '' where a kind has nothing to say):
 * - kind, id, label: every record. The id is the kind-local identity a
 *   consumer speaks (a swatch or variant machine name, a blueprint id, a
 *   token's "{scale}/{name}" since token names repeat across scales).
 * - description: variants and blueprints (the when-to-reach-for-this
 *   text); '' elsewhere.
 * - value: tokens and swatches (a CSS string or a condition-keyed map
 *   whose 'default' always applies); variants carry their element-relative
 *   style map (relative key => condition => property => value), which the
 *   client's layer math needs for live preview and no prompt or schema
 *   surface prints; NULL for blueprints, whose payload is a subtree.
 * - group: the kind-local grouping consumers sort under: a token's
 *   scale, a swatch's kind (color, gradient), a variant's axis, a
 *   blueprint's type (its bundle, the permission axis: section,
 *   site_chrome, or a type the site added).
 * - spellings: slot => the string that references this material in that
 *   slot (see the SLOT_* constants). Contributed by the provider, the
 *   one place that knows "--swatch-" or that a width-axis variant spells
 *   as an attribute; the value grammar is an index over these. An axis
 *   attribute slot carries the record's scope (attrSlot()), so the same
 *   attribute value on two scopes is two spellings, never a collision.
 * - scope, applies, ownedBy: variants, and applies also blueprints. scope
 *   is the ADDRESS the variant was saved under (Address, a root one:
 *   "block_type:text", "kind:container", "base:a"; toArray() prints it,
 *   the string the client twin reads); applies carries structured applicability
 *   facts beyond the scope ({depth: root} for a root-only axis variant;
 *   {wears: [ids]} on a blueprint, the variant ids its root captured);
 *   ownedBy names the blueprint whose placements alone may wear it.
 * - published: always TRUE for records a provider serves today (rosters
 *   are the published set); carried so a delta channel can say
 *   "unpublished" without a second shape.
 * - weight: the roster's sort fact (weight-then-label, the variant
 *   entity's rule); 0 for kinds without one.
 * - icon: blueprints, RESOLVED to a root-relative URL through the icon
 *   value grammar's one seam (IconResolver), or NULL for the default
 *   icon (unset, or a media reference that no longer resolves). A URL
 *   rather than the grammar value because the record's one icon consumer
 *   is the client palette, which cannot resolve a media reference; the
 *   grammar value stays on the blueprint catalog and entity.
 * - blueprintScope: blueprints, SCOPE_BLOCK or SCOPE_PAGE (what the saved
 *   blueprint stands in for: one container placed into a page, or a whole
 *   page's document). A placement is always a container (roots are always
 *   containers), so the provenance grammar needs no kind from the record:
 *   a placement root prints blueprint="id" on a container, and the parser
 *   refuses the stamp on a block.
 * - origin: shipped (a module ships it), user (minted by a person), ai
 *   (minted by the AI on request). The mint gate's provenance: an AI
 *   mint must be visible as one for the life of the site.
 * - updatable: whether the update gesture may write this material in
 *   place (FALSE for file-shipped blueprints, which are read-only in every
 *   UI; TRUE for content entities, shipped or not).
 * - designSystem: the id of the design system the material is a member
 *   of, or NULL for a global material (one that belongs to no system and
 *   is offered under every one). The roster keeps global records and the
 *   active system's; a provider serves every record it has and the
 *   builder partitions (MaterialsRosterBuilder::build()).
 * - offered: whether the material is shared with the AI. TRUE by default
 *   (the AI's roster IS the design system; hiding by default would empty
 *   it); an author opts a material out for UI-only use, and the offered
 *   view of the roster (MaterialsRoster::offered(), what the brief and
 *   the schema read) leaves it out, so it is never advertised and its
 *   description has no audience. Legality never reads it: an unoffered
 *   material still resolves, validates and applies exactly as before.
 * - tree: blueprints, the definition's `layout` in the shape DialectPrinter
 *   prints (the list of root container nodes, every variant reference in
 *   the tree's reference shape: discovery's served grammar in
 *   daedalus_blueprint_library); NULL for every other kind. Id-less by
 *   construction (a definition has no identity: a file's tree carries
 *   none and the provider strips a content blueprint's document uuids),
 *   so a print of it is a fragment: the blueprint's sample content and worn
 *   looks as the page language spells them, which is how the schema shows
 *   a blueprint (MaterialsSchema). The worn ids are the definition's, resolved
 *   against the variant records of the same roster wherever they print.
 *   Server-side only: the delivery contract (toArray()) leaves it out,
 *   since the client places a blueprint by id and never prints one, and
 *   every blueprint's subtree on every page load would pay for nothing.
 * - entityId: blueprints, the saved blueprint's entity id when the
 *   blueprint is a content entity (a page of its own: the page tools
 *   address it as entity_type blueprint, entity_id this), NULL for a
 *   file-shipped blueprint (read-only, no page) and for every other kind.
 *   Server-side only, like the tree: the AI surfaces (the brief, the
 *   schema) print it so the model can read and edit the blueprint's body
 *   on demand; the client reaches the canvas through `url`.
 * - url: blueprints, the path of a content blueprint's canvas (its
 *   canonical route), NULL for a file-shipped blueprint (read-only, no
 *   page) and for every other kind. Delivered (toArray()): the Open
 *   blueprint verb on a detached placement reads it beside the record's
 *   `updatable` and `label`, the same provenance rule the Update verb
 *   already resolves through the record (a detached placement is the
 *   page's own copy, no join, so the element annotator's `showing`
 *   carries no portal for it). A record is actor-independent (the roster
 *   is cached by the vocabulary tag alone), so the url carries no grant:
 *   the canvas route enforces for real, as the update gesture does.
 * - requires: blueprints, what the definition needs of a host
 *   (BlueprintRequirements::derive() over its layout and allow-list: the
 *   subject's fields it places, the block types, plugins and layouts, the
 *   variants it wears, the `entity_type:bundle` allow-list), [] for every
 *   other kind. Server-side only: the delivery keeps only the blueprints
 *   the canvas host offers (CanvasOffer, the fit among its conditions) and
 *   place_blueprint refuses one that does not fit, so the client never
 *   judges a fit.
 * - groups: blueprints, the ids of the groups the blueprint is filed
 *   under (the `groups` key of its file envelope or its entity field),
 *   [] for a blueprint in no group and for every other kind. A group is a
 *   filter category on an offering surface and nothing else: no
 *   operation, fit or placement rule reads it, and the vocabulary (id to
 *   label and description) is the dialect catalog's (DialectCatalog::groups(),
 *   contributed from the site declaration's `groups` section by the
 *   layer that parses it), which every offering surface reads; a surface
 *   ignores an id the site does not declare. Delivered (toArray()): the
 *   Add panel files its rows by it.
 * - favorite: blueprints, whether the blueprint is a site favorite (the
 *   `favorite` key of its file envelope or its entity field): the
 *   default an offering surface lists under Favorites for everyone,
 *   over which each editor keeps their own marks. FALSE for every other
 *   kind. A listing fact and nothing else: no operation, fit or
 *   placement rule reads it. Delivered (toArray()), like groups.
 * - styles: blueprints, the definition's page-level (node-less) style
 *   entries: what the document says about ITS OWN root (the entity: and
 *   field: keys and their rule forms, NodeTreeBuilder::nodelessStyles()),
 *   keyed as the source stored them (a saved blueprint's under its own
 *   uuid, a shipped file's under none); [] for a document with none and
 *   for every other kind. A page's start copies them with the tree,
 *   re-addressed to the page's root (PageStart, through the whole-document
 *   copy); a placement never applies them, since a placed container is
 *   not a page root. Server-side only, like the tree.
 */
final class MaterialRecord {

  /**
   * The scopes a blueprint record carries (what the blueprint stands in for).
   *
   * The blueprint entity's own vocabulary (BlueprintInterface::SCOPES in
   * daedalus_blueprint_library), spelled here because the spine never
   * imports a library class.
   */
  public const SCOPE_BLOCK = 'block';
  public const SCOPE_PAGE = 'page';
  public const SCOPES = [self::SCOPE_BLOCK, self::SCOPE_PAGE];

  public const ORIGIN_SHIPPED = 'shipped';
  public const ORIGIN_USER = 'user';
  public const ORIGIN_AI = 'ai';
  public const ORIGINS = [self::ORIGIN_SHIPPED, self::ORIGIN_USER, self::ORIGIN_AI];

  /**
   * The style slot: a CSS declaration's value (tokens and swatches).
   *
   * The spelling is what a value writes: "var(--swatch-lime_green)".
   */
  public const SLOT_STYLE = 'style';

  /**
   * The class slot: one token of an element's class list.
   *
   * Style-axis variants; the spelling is the bare class: "button".
   */
  public const SLOT_CLASS = 'class';

  /**
   * The blueprint slot: the name a placement references.
   *
   * The spelling is the blueprint id: "hero".
   */
  public const SLOT_BLUEPRINT = 'blueprint';

  /**
   * The animation slot: the ident an animation-name value references.
   *
   * Keyframes animations; the spelling is the provider-prefixed ident a
   * writer puts in animation-name or the animation shorthand:
   * "motion-fade_in". Property-scoped on purpose: a bare ident means
   * something else in every other property (anchor-name and
   * container-name take identifiers too), so the style slot stays
   * var()/url()-only and only a consumer that knows the property is
   * animation-* resolves here.
   */
  public const SLOT_ANIMATION = 'animation';

  /**
   * The font slot: the family name a font-family value references.
   *
   * Self-hosted fonts; the spelling is the quoted family exactly as the
   * compiled @font-face declares it: "Inter". Property-scoped like the
   * animation slot: a quoted string means something else in every other
   * property (content, quotes, grid-area names), so only a consumer that
   * knows the property is font-family or the font shorthand resolves
   * here.
   */
  public const SLOT_FONT = 'font';

  /**
   * The prefix of an axis-attribute slot (see attrSlot()).
   *
   * An axis attribute's value slot is SCOPED: "attr:kind:container:width"
   * is the width attribute on a container element, "attr:kind:block:width"
   * the same attribute name on a block element, and the two are different
   * slots because they are different places in the document (as in HTML,
   * where an attribute value on one tag says nothing about the same value
   * on another). The spelling is the attribute value: "full". The scope
   * in the slot is the record's own scope, checked at construction, so
   * the partition of attribute values by scope has one source of truth
   * (the record's scope) and one validation path (here), and the roster's
   * flat spelling index partitions by construction: two scopes may spell
   * one value on one attribute name, one scope may not.
   */
  public const SLOT_ATTR_PREFIX = 'attr:';

  /**
   * The applies key naming a depth restriction, and its one value.
   */
  public const APPLIES_DEPTH = 'depth';
  public const DEPTH_ROOT = 'root';

  /**
   * The applies key carrying a blueprint root's captured wearings.
   *
   * A list of variant ids: what the blueprint's root element wears in the
   * definition (its root styles' variants). A capture fact, never an
   * authored one, and never a depth verdict: the provider that writes it
   * knows nothing about axes; MaterialsRoster::applicable() resolves the
   * ids against the variant records in the same roster and calls the
   * blueprint a page band when any of them is root-only.
   */
  public const APPLIES_WEARS = 'wears';

  /**
   * Constructs a record; the class doc says what each field carries.
   *
   * Spellings map slot => spelling (the SLOT_* constants); applies holds
   * structured applicability facts beyond the scope (APPLIES_*).
   */
  public function __construct(
    public readonly MaterialKind $kind,
    public readonly string $id,
    public readonly string $label,
    public readonly string $description = '',
    public readonly string|array|null $value = NULL,
    public readonly ?string $group = NULL,
    public readonly array $spellings = [],
    public readonly ?Address $scope = NULL,
    public readonly array $applies = [],
    public readonly ?string $ownedBy = NULL,
    public readonly bool $published = TRUE,
    public readonly int $weight = 0,
    public readonly ?string $icon = NULL,
    public readonly ?string $blueprintScope = NULL,
    public readonly string $origin = self::ORIGIN_USER,
    public readonly bool $updatable = TRUE,
    public readonly ?string $designSystem = NULL,
    public readonly bool $offered = TRUE,
    public readonly ?array $tree = NULL,
    public readonly ?string $entityId = NULL,
    public readonly array $requires = [],
    public readonly array $groups = [],
    public readonly bool $favorite = FALSE,
    public readonly array $styles = [],
    public readonly ?string $url = NULL,
  ) {
    if ($id === '') {
      throw new \InvalidArgumentException(sprintf('A %s record needs an id.', $kind->noun()));
    }
    if ($designSystem === '') {
      throw new \InvalidArgumentException(sprintf('The design system of %s "%s" is an id or NULL (global), never the empty string.', $kind->noun(), $id));
    }
    if (!in_array($origin, self::ORIGINS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown material origin "%s" on %s "%s"; one of %s.', $origin, $kind->noun(), $id, implode(', ', self::ORIGINS)));
    }
    if ($blueprintScope !== NULL && !in_array($blueprintScope, self::SCOPES, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown blueprint scope "%s" on %s "%s"; one of %s.', $blueprintScope, $kind->noun(), $id, implode(', ', self::SCOPES)));
    }
    if (!array_is_list($groups) || array_filter($groups, static fn ($group): bool => !is_string($group) || $group === '') !== []) {
      throw new \InvalidArgumentException(sprintf('The groups of %s "%s" must be a list of group ids (non-empty strings).', $kind->noun(), $id));
    }
    if ($scope !== NULL && !$scope->isRoot()) {
      throw new \InvalidArgumentException(sprintf('The scope of %s "%s" is "%s"; a material\'s scope is a root address, never a part or its children.', $kind->noun(), $id, $scope));
    }
    foreach ($spellings as $slot => $spelling) {
      if (!is_string($slot) || $slot === '' || !is_string($spelling) || $spelling === '') {
        throw new \InvalidArgumentException(sprintf('Spellings on %s "%s" must map a non-empty slot to a non-empty string.', $kind->noun(), $id));
      }
      if (!str_starts_with($slot, self::SLOT_ATTR_PREFIX)) {
        continue;
      }
      $slot_scope = self::slotScope($slot);
      if ($slot_scope === NULL) {
        throw new \InvalidArgumentException(sprintf('Attribute slot "%s" on %s "%s" is malformed; an axis attribute slot is %s{scope}:{attribute} (MaterialRecord::attrSlot()).', $slot, $kind->noun(), $id, self::SLOT_ATTR_PREFIX));
      }
      if ($slot_scope !== $scope?->__toString()) {
        throw new \InvalidArgumentException(sprintf('Attribute slot "%s" on %s "%s" names scope "%s", but the record\'s scope is %s; an axis attribute spells in its own scope only.', $slot, $kind->noun(), $id, $slot_scope, $scope === NULL ? 'unset' : '"' . $scope . '"'));
      }
    }
  }

  /**
   * The slot name for an axis attribute's value on a scope.
   *
   * "attr:kind:container:width" for the width attribute on the container
   * kind; the scope is the variant's own address (the one it was saved
   * under), printed: a slot is a string index, and the address travels
   * through it as its print exactly as it travels through toArray().
   */
  public static function attrSlot(string $scope, string $attribute): string {
    return self::SLOT_ATTR_PREFIX . $scope . ':' . $attribute;
  }

  /**
   * The attribute an axis slot names, or NULL for any other slot.
   *
   * NULL for a slot that is not a well-formed scoped attribute slot, the
   * unscoped "attr:width" included: there is no site-wide attribute slot.
   */
  public static function slotAttribute(string $slot): ?string {
    return self::attrSlotParts($slot)[1] ?? NULL;
  }

  /**
   * The scope an axis slot names, or NULL for any other slot.
   */
  public static function slotScope(string $slot): ?string {
    return self::attrSlotParts($slot)[0] ?? NULL;
  }

  /**
   * The [scope, attribute] pair of a scoped attribute slot, else NULL.
   *
   * The attribute is the segment after the last colon; the scope is what
   * sits between the prefix and it, and must print a root address
   * (Address::parse(): "kind:container", "block_type:text").
   *
   * @return array{0: string, 1: string}|null
   *   The scope (as printed) and the attribute, or NULL.
   */
  private static function attrSlotParts(string $slot): ?array {
    if (!str_starts_with($slot, self::SLOT_ATTR_PREFIX)) {
      return NULL;
    }
    $rest = substr($slot, strlen(self::SLOT_ATTR_PREFIX));
    $at = strrpos($rest, ':');
    if ($at === FALSE) {
      return NULL;
    }
    $scope = substr($rest, 0, $at);
    $attribute = substr($rest, $at + 1);
    if ($attribute === '' || Address::parse($scope)?->isRoot() !== TRUE) {
      return NULL;
    }
    return [$scope, $attribute];
  }

  /**
   * This material's spelling in a slot, or NULL when it has none there.
   */
  public function spelling(string $slot): ?string {
    return $this->spellings[$slot] ?? NULL;
  }

  /**
   * Whether the material belongs to no design system.
   */
  public function isGlobal(): bool {
    return $this->designSystem === NULL;
  }

  /**
   * Whether the material applies at top-level depth only.
   */
  public function rootOnly(): bool {
    return ($this->applies[self::APPLIES_DEPTH] ?? NULL) === self::DEPTH_ROOT;
  }

  /**
   * The variant ids a blueprint's root captured ([] for every other kind).
   *
   * @return string[]
   *   The ids, in definition order.
   */
  public function wears(): array {
    return $this->applies[self::APPLIES_WEARS] ?? [];
  }

  /**
   * The record as the delivery contract carries it.
   *
   * The one JSON shape for the page-load payload ({records: [...]}) and
   * the mutation response's materials delta ({upsert: [...], remove:
   * [{kind, id}]}); snake_case keys, every field present so a client
   * store never branches on absence. The blueprint's tree, page-level
   * styles, entity id and requirements stay server-side (see the class
   * doc); its groups, favorite and url ride, since the Add panel files and
   * lists rows by the first two and the Open blueprint verb leaves by the
   * third.
   */
  public function toArray(): array {
    return [
      'kind' => $this->kind->value,
      'id' => $this->id,
      'label' => $this->label,
      'description' => $this->description,
      'value' => $this->value,
      'group' => $this->group,
      'spellings' => $this->spellings,
      'scope' => $this->scope?->__toString(),
      'applies' => $this->applies,
      'owned_by' => $this->ownedBy,
      'published' => $this->published,
      'weight' => $this->weight,
      'icon' => $this->icon,
      'blueprint_scope' => $this->blueprintScope,
      'origin' => $this->origin,
      'updatable' => $this->updatable,
      'design_system' => $this->designSystem,
      'offered' => $this->offered,
      'groups' => $this->groups,
      'favorite' => $this->favorite,
      'url' => $this->url,
    ];
  }

}
