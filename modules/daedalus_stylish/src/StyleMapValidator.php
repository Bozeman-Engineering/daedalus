<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish;

// cspell:ignore pading zzzx
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint\Materials\MaterialsRoster;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_blueprint\Result\BlueprintError;
use Drupal\daedalus_blueprint\Result\BlueprintWarning;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\AnimationStepsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRoster;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRosterBuilder;

/**
 * Validates styles against the vocabulary roster of the address they style.
 *
 * Every path that writes styles ends here: the daedalus_blueprint field
 * handlers cover whole-map and variant writes (blueprint create/update,
 * field updates), the update_style operation routes targeted patches
 * through the same checks, the variant gestures, the conformance judge
 * and the skin defaults converge on the same door. Widgets and the code
 * editor lint are conveniences; this is the enforcement product and never
 * trusts the client. The enforcement mode it enforces is the active
 * design system's (StyleGuide::enforcement(), read through the roster),
 * never site config.
 *
 * One door, two key grammars. validateAt() validates element-relative
 * styles at an ADDRESS (Drupal\daedalus_blueprint\Materials\Address: a
 * variant's scope, a skin default's scope), and the variant lane hands
 * its address. validate() validates a style map keyed by live element
 * keys, and the element lane derives each key's address from its
 * placement (resolveAddress()) before validating the entry at it. Past
 * the key grammar the engine is one: an entry validates against the
 * VOCABULARY ROSTER of its address (VocabularyRosterBuilder, the one
 * derivation of "what may this address style", the twin of the materials
 * roster), so the two lanes, the dialect catalog the AI reads and the
 * client payload can never disagree about a grant, a value space or a
 * corrective. There is no second door: a caller holding a scope string
 * parses it (Address::fromString(), or variantScope() for an untrusted
 * one) and comes through validateAt().
 *
 * Two phases, mirroring daedalus_blueprint:
 * - Structural: keys parse (selectable keys with a ::selector relative
 *   selector extension, RelativeSelector's one grammar: a part, a tag, the
 *   children, a type, their compounds; an element's
 *   direct children as one addressable; or, at an address, the slot-key
 *   grammar), an entry is a LAYERED object, an optional ordered "variants"
 *   reference list plus condition-keyed override blocks (overrides beat
 *   every referenced variant; later references beat earlier ones),
 *   condition names are well-formed, property values are scalars. Every
 *   reference is a structured {"variant", "fragment"} object, the one
 *   reference shape: a "" fragment wears the variant's root, a non-empty
 *   fragment wears ONE fragment of a composition variant (see
 *   validateFragmentReference()). On the map lane a reference may also
 *   carry "via", its provenance: the machine name of the composition
 *   variant whose apply distributed it onto the element (a non-empty
 *   string, validated no further, so a stale origin is inert). Provenance
 *   is a placement fact, so the variant styles lane rejects the key: a
 *   variant's nested list says what to distribute, never who did.
 * - Semantic: every property must belong to a property group, and is
 *   validated against the roster of the address its key names: the grant
 *   (STYLE_PROPERTY_NOT_DECLARED on a strict site), the value space (the
 *   effective constraint, node then site defaults) and the correctives
 *   are the roster's. There is no escape hatch: the property vocabulary
 *   IS the model, and anything outside it is an error, never a
 *   pass-through.
 *
 * Legality is the roster's; doctrine never enters it. The one doctrine
 * the element door voices is the owned-property WARNING
 * (STYLE_PROPERTY_AXIS_OWNED, ownedPropertyWarnings()): an element write
 * of a property one of its axes owns (the axis's `owns` qualifier, the
 * properties its values write) is legal and lands, and the warning names
 * the axis and its attribute spelling so the writer can wear the axis
 * instead. Never an error, and never on the variant lane, where writing
 * an owned property is exactly what an axis variant does. The warning
 * knows depth: a root-only axis is unwearable on a nested container, so
 * a write of a property it owns there is the recipe, not a miss, and
 * stays silent (isNestedContainer()).
 *
 * Errors are corrective wherever the legal alternatives are enumerable:
 * unknown properties come back with the closest recognized names, undeclared
 * properties with what the element may style, unknown parts with the
 * declared parts, missing and mis-scoped variants with the variants that DO
 * apply to the element's scope, each named by its description when it
 * carries one (the roster's "when to reach for this look" text, so the
 * rejection teaches the design language, not just a machine name to copy).
 * The same principle as the reference handlers' similar-label
 * suggestions: an AI (or human) should be able to self-correct from the
 * error alone, without a second discovery round-trip.
 *
 * Every material universe here is the site's materials roster's
 * (MaterialsRosterBuilder): the minted tokens var() references and scale
 * constraints resolve against, the published swatches the reserved
 * namespace may name, the published variants a reference may wear and the
 * candidates a corrective offers. The validator projects the roster and
 * never walks the skin or the storages itself, so what it accepts is
 * exactly what the dialect catalog, the cached prompt, the schema and the
 * delivery CSS carry; the exceptions are a skin validated for
 * self-containment, whose own tokens stand in (withMintedUniverse()), a
 * design system's MEMBER, which validates in its own system's universe
 * whether or not that system is active (withMemberUniverse(): the
 * system's roster partition and its default skin's tokens), because a
 * member's values only ever resolve there, and the conformance judge
 * (VariantConformance), which validates every offered variant against
 * the provider's own unjudged records (withUniverse()) because the
 * roster it would otherwise read is built FROM its verdict. A global
 * material validates in the roster, the active system's universe.
 *
 * Degradation is explicit, never silent bypass:
 * - block:{uuid} keys resolve to their per-block-type address when the
 *   host entity is in context and the placement is an inline block already
 *   in its layout; otherwise they fall back to the universal block kind.
 * - container:{uuid} keys resolve to their per-layout address when the
 *   host entity is in context and the section is in its layout; otherwise
 *   they fall back to the universal container kind.
 * - Condition names must name a condition a condition type plugin provides
 *   (or "default"), or an "a+b" compound of such names holding at most one
 *   condition per axis, parts accepted in any order (write paths
 *   canonicalize to axis-declaration order, see
 *   ConditionTypePluginManager::canonicalizeCondition()); variant
 *   references must name an existing variant whose scope pairs with the
 *   element's own address (see captureScope() and
 *   Address::pairsWith()), and an entry carries at most
 *   one reference per variant axis (axes are replacement lanes,
 *   composition happens across axes).
 */
class StyleMapValidator {

  /**
   * Entry keys with reserved meaning: never condition names.
   */
  public const RESERVED_ENTRY_KEYS = ['variants'];

  /**
   * The shape of a token name on its scale.
   *
   * The data half of the token vocabulary (scales are code, names are
   * minted on skins): lowercase letters, digits, and inner hyphens —
   * "4", "2xl", "accent-hover" — because the name becomes the tail of the
   * derived CSS custom property (StyleGuide::tokenVariable(),
   * --{prefix}-{name}) and pickers sort and match it case-insensitively.
   */
  public const TOKEN_NAME = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

  /**
   * The condition axes a token value may vary on.
   *
   * An allowlist, not a state-axis blocklist: token definitions live on the
   * token scope element, so only axes whose wrappers make sense THERE may
   * condition a value — width (@container against the page-level width
   * container), scheme and motion (@media user preferences). Interaction
   * states are per-element facts: a :hover on the scope element would
   * retheme the page when the pointer crosses it, which is never what
   * "hover token" means — hover treatments belong in style maps and
   * variants, where the state applies to the styled element itself.
   */
  public const TOKEN_CONDITION_AXES = ['width', 'scheme', 'motion'];

  /**
   * The instance-part namespace: minted rich-text identity classes.
   *
   * The suite-wide identity contract with the leading dot of a part
   * selector: daedalus_edit mints these onto semantic text elements
   * (IdentityMinter::CLASS_PATTERN server-side, its CKEditor Identity
   * plugin live), and daedalus_ui's markup view derives live rows from
   * them. As style targets they resolve through the spine's declared
   * instance namespaces (the `instances:` lane, after any owner-declared
   * exact or pattern part; the roster's one part rule), but they are
   * INSTANCE-BOUND: another element's content wears different
   * ids, so a variant carrying them would be inert there, or lie by
   * hitting an unrelated element that happens to reuse the id.
   * validateAt() therefore rejects them in variant styles, matching
   * the client capture's exclusion.
   */
  public const INSTANCE_PART = '/^\.s-[a-z0-9]+$/';

  /**
   * The style map key grammar in one hint, for every door that refuses a key.
   *
   * The root half is the element key grammar (parseKey()); the selector
   * half is RelativeSelector::GRAMMAR, composed here so no corrective can
   * spell a suffix the parser does not read.
   */
  /**
   * The element-relative (variant, skin) key grammar in one hint.
   */
  public const RELATIVE_KEY_GRAMMAR_HINT = 'Element-relative styles key by \'\' for the root element, a slot path of container(index), block(type,index) and field(name) segments the root structurally contains (e.g. "container(1)/block(text,1)"; a container\'s children are one ordered flow, so there are no region segments), and an optional "::" relative selector. ' . RelativeSelector::GRAMMAR;

  public const KEY_GRAMMAR_HINT = 'Keys are selectable keys: entity:{uuid}, field:{uuid}:{field_name}, container:{uuid}, block:{component_uuid}, or block:{component_uuid}:{field_name}, optionally followed by "::" and a relative selector. ' . RelativeSelector::GRAMMAR . ' Sections are containers: the retired section:{uuid} spelling is container:{uuid} now, and regions are not addressable (no region keys); style the container itself or the child element.';

  /**
   * The kinds the "kind:{kind}" scope family speaks.
   *
   * The element-model kinds MINUS region: regions are not addressable
   * elements (no region keys, no stampable root), so nothing could ever
   * wear a region-scoped variant. Every surface that enumerates or accepts
   * kind scopes (the variant door's gate, variantScope(); the field
   * schemas' scope catalogs) uses this list so the vocabulary cannot
   * fork. The gate is the variant door's, not the roster's: the roster
   * answers for every address an element key can capture (a region's
   * kind included), a variant scope is the subset a variant may be saved
   * from.
   */
  public const SCOPE_KINDS = ['entity', 'container', 'field', 'block'];

  protected const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

  protected const MACHINE_NAME = '[a-z0-9_]+';

  /**
   * How many variant candidates a corrective names.
   *
   * The roster's first ten in reading order (weight, then label): enough
   * to repair from, short enough that a hint on a site with dozens of
   * variants stays a hint.
   */
  protected const CANDIDATE_LIMIT = 10;

  /**
   * The roster the token universes below were regrouped from.
   *
   * Identity, not a copy: the builder memoizes per request and hands out
   * a NEW roster after any material change (a skin save mid-batch), so a
   * roster that is not this one means the universes are stale.
   */
  protected ?MaterialsRoster $universeRoster = NULL;

  /**
   * The token universe per theme: [theme => [scale => [name => value]]].
   *
   * The roster's tokens on the theme's declared scales, regrouped once
   * per roster: a whole-map validation checks many values against one
   * universe, and the correctives list the values.
   *
   * @var array<string, array<string, array<string, mixed>>>
   */
  protected array $universes = [];

  /**
   * Caller-supplied token universes standing in for the roster's, per flow.
   *
   * Set for the duration of withMintedUniverse(), keyed by FiberFlow::id():
   * a door's state is the calling flow's, never the service's, because a
   * validation parked in a fiber (core yields one from inside an entity
   * load) must not hand its universe to the flows that run meanwhile. A
   * skin validated for self-containment resolves var() references
   * against ITS OWN tokens, which are not (yet) the site's materials.
   *
   * @var array<int, array<string, array<string, array<string, mixed>>>>
   */
  protected array $universeOverrides = [];

  /**
   * Caller-supplied rosters standing in for the site's, per flow.
   *
   * Set for the duration of withUniverse(), keyed by FiberFlow::id() like
   * $universeOverrides: a member's swatch, animation and variant
   * references resolve against its own system's partition
   * (withMemberUniverse(): global plus that system,
   * MaterialsRosterBuilder::buildFor()), and variant conformance judges
   * the provider's unjudged records against themselves, never against
   * the roster being built from the verdict.
   *
   * @var array<int, \Drupal\daedalus_blueprint\Materials\MaterialsRoster>
   */
  protected array $rosterOverrides = [];

  /**
   * Whether the declared-defaults lane is validating.
   *
   * Set for the duration of validateDeclaredDefaults(): a declaration's
   * own defaults are CODE, checked at guide build, so their posture never
   * reads the site's enforcement config (build errors judge code, never
   * data): the property world stays open (recognition and grammar, no
   * closed-world grant check, constraints never law) while every token
   * reference is checked against the empty minted universe.
   */
  protected bool $declaredLane = FALSE;

  /**
   * Constructs the style map validator.
   *
   * @param \Drupal\daedalus_stylish\PropertyGroupPluginManager $propertyGroups
   *   The property group plugin manager.
   * @param \Drupal\daedalus_stylish\StyleGuide $styleGuide
   *   The style guide.
   * @param \Drupal\Core\Extension\ThemeHandlerInterface $themeHandler
   *   The theme handler.
   * @param \Closure $elementLocator
   *   Resolves to the daedalus_blueprint.element_locator service. A service
   *   closure, not the service: the locator pulls in the field handler
   *   manager, whose collection constructs our field handler, which needs
   *   this validator — eager injection makes that a circular reference the
   *   moment anything (the CSS printer, the operation handler) constructs
   *   the validator before the manager exists.
   * @param \Drupal\daedalus_stylish\ConditionTypePluginManager $conditionTypes
   *   The condition type plugin manager.
   * @param \Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder $rosterBuilder
   *   The materials roster builder.
   * @param \Drupal\daedalus_stylish\VariantRepository $variantRepository
   *   The variant repository.
   * @param \Drupal\daedalus_stylish\SwatchKindPluginManager $swatchKinds
   *   The swatch kind plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\daedalus_stylish\SkinManager $skinManager
   *   The skin manager.
   * @param \Drupal\daedalus_stylish\VariantConformance $variantConformance
   *   The variant conformance judge.
   * @param \Drupal\daedalus_stylish\Vocabulary\VocabularyRosterBuilder $vocabulary
   *   The vocabulary roster builder.
   */
  public function __construct(
    protected PropertyGroupPluginManager $propertyGroups,
    protected StyleGuide $styleGuide,
    protected ThemeHandlerInterface $themeHandler,
    protected \Closure $elementLocator,
    protected ConditionTypePluginManager $conditionTypes,
    protected MaterialsRosterBuilder $rosterBuilder,
    protected VariantRepository $variantRepository,
    protected SwatchKindPluginManager $swatchKinds,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SkinManager $skinManager,
    protected VariantConformance $variantConformance,
    protected VocabularyRosterBuilder $vocabulary,
  ) {}

  /**
   * The address a variant scope string names, or NULL when it is not one.
   *
   * The variant door's gate over the address grammar (Address): a variant
   * scope is a ROOT address whose kind is one of SCOPE_KINDS (regions are
   * not addressable elements) or whose base tag is in the closed
   * bare-markup vocabulary (StyleGuide::BASE_TAGS; base:div is not a
   * scope). An undeclared bundle, plugin or layout IS a scope: it resolves
   * to its universal kind's roster, so a variant may ship before its
   * declaring guide is installed. The callers that receive a scope string
   * from outside (a blueprint payload, a skin's defaults) gate here and
   * hand validateAt() the address; a stored scope parses straight through
   * Address::fromString().
   */
  public static function variantScope(string $scope): ?Address {
    $address = Address::parse($scope);
    if ($address === NULL || !$address->isRoot()) {
      return NULL;
    }
    return match ($address->family) {
      Address::FAMILY_KIND => in_array($address->id, self::SCOPE_KINDS, TRUE) ? $address : NULL,
      Address::FAMILY_BASE => in_array($address->id, StyleGuide::BASE_TAGS, TRUE) ? $address : NULL,
      default => $address,
    };
  }

  /**
   * The corrective for a string variantScope() refused, at "scope".
   */
  public static function invalidScopeError(string $scope): BlueprintError {
    return new BlueprintError('scope', 'INVALID_STYLE_SCOPE', sprintf('"%s" is not a variant scope.', $scope), ['valid_kinds' => self::SCOPE_KINDS], sprintf('A scope is "block_type:{bundle}", "block_plugin:{plugin_id}" (a base block plugin id), "layout:{layout_id}", "kind:{kind}" with kind one of: %s, or "base:{tag}" with a tag from the bare-markup vocabulary (%s). Regions are not addressable elements, so kind:region is not a scope.', implode(', ', self::SCOPE_KINDS), implode(', ', StyleGuide::BASE_TAGS)));
  }

  /**
   * Validates a whole style map: the element lane.
   *
   * Each key's address is derived from its placement (resolveAddress())
   * and the entry validates at it through the one engine (validateEntryAt()),
   * exactly as a variant's entries validate at the variant's address.
   *
   * @param array $map
   *   The decoded style map: selectable keys mapped to style data.
   * @param array $context
   *   Optional context:
   *   - host (\Drupal\daedalus_blueprint\Host\HostInterface): the host the
   *     map lives on. Enables per-block-type validation of block:{uuid} keys
   *     by resolving the placement's inline block bundle from its layout, and
   *     per-layout validation of container:{uuid} keys by resolving the
   *     section's layout plugin id.
   *   - theme (string): style guide theme; defaults to the site default theme
   *     (the theme styled pages render in — the active theme during an API
   *     request is often the admin theme and would resolve the wrong guide).
   * @param \Drupal\daedalus_blueprint\Result\BlueprintWarning[] $warnings
   *   Collects the door's non-blocking observations, at the same paths as
   *   the errors: STYLE_PROPERTY_AXIS_OWNED for an element write of a
   *   property one of the element's axes owns (ownedPropertyWarnings()).
   *   A caller that carries no warning lane may omit it.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors with paths relative to the map root
   *   ("{key}.{condition}.{property}"). The caller re-roots them onto its own
   *   path space. Empty when valid.
   */
  public function validate(array $map, array $context = [], array &$warnings = []): array {
    $theme = $context['theme'] ?? ($this->themeHandler->getDefault() ?: NULL);
    $closed = $this->propertiesClosed();
    $errors = [];

    foreach ($map as $key => $entry) {
      $key = (string) $key;
      $parsed = $this->parseKey($key);
      if ($parsed === NULL) {
        $errors[] = new BlueprintError($key, 'INVALID_STYLE_KEY', sprintf('"%s" is not a valid style map key.', $key), [], self::KEY_GRAMMAR_HINT);
        continue;
      }

      if (!is_array($entry)) {
        $errors[] = new BlueprintError($key, 'INVALID_STYLE_ENTRY', sprintf('The entry for "%s" is not a style entry.', $key), [], 'An entry is an object holding an optional "variants" reference list and/or condition keys mapping CSS properties to values, e.g. {"variants": [{"variant": "hero", "fragment": ""}], "default": {"padding": "2rem"}}.');
        continue;
      }

      // The layered entry: the "variants" reference list and
      // condition-keyed overrides coexist — overrides beat every referenced
      // variant, later references beat earlier ones.
      if (array_key_exists('variants', $entry)) {
        array_push($errors, ...$this->validateVariantList(
          $key,
          $entry['variants'],
          fn (string $reference_at, string $reference): array => $this->validateVariantReference($key, $reference_at, $reference, $context),
          map_lane: TRUE,
        ));
        unset($entry['variants']);
      }

      $address = $this->resolveAddress($parsed, $context);
      $roster = $this->resolveRoster($address, $theme, $key, $closed, $errors);
      if ($roster === NULL) {
        continue;
      }
      array_push($errors, ...$this->validateEntryConditions($key, $entry, $roster, $closed, $theme));
      if ($address->isRoot()) {
        array_push($warnings, ...$this->ownedPropertyWarnings($key, $entry, $roster, $this->isNestedContainer($parsed, $context)));
      }
    }

    return $errors;
  }

  /**
   * The owned-property warnings for one element entry: wear the axis.
   *
   * A property an axis of the element owns (VocabularyRoster::
   * axisOwning(), the declaration's `owns` qualifier: the properties the
   * axis's values write) is granted and lands when written directly, but
   * the write masks the axis's business, so the element door says so:
   * STYLE_PROPERTY_AXIS_OWNED at the property's path, naming the axis by
   * machine name and label and the attribute the dialect wears it as.
   * The element itself only (a root address): an axis dresses the
   * element's own box, not a part's or its children's, and never the
   * variant lane, where an axis variant writes exactly these. Doctrine,
   * never legality: a warning beside a clean error list.
   *
   * The warning learns depth: a root-only axis (the roster's `root_only`
   * stamp, the one fact the picker, the dialect and the move subscriber
   * read) is neither offered nor wearable on a nested container, so a
   * write of a property it owns there is the recorded recipe (a
   * full-width band with a nested container carrying its own max-width,
   * container-forms fork 2), not a miss: no warning. The axis is still
   * named on a root container, and an every-depth axis owns its
   * properties wherever the element sits. A key the locator cannot place
   * (no host in context, or a section not yet in its layout) reads as
   * root, the ungated posture, exactly as isNestedSection() degrades.
   *
   * @param string $at
   *   The entry's error path (the selectable key).
   * @param array $entry
   *   The entry's condition-keyed blocks, the "variants" list removed.
   * @param \Drupal\daedalus_stylish\Vocabulary\VocabularyRoster $roster
   *   The roster of the entry's root address.
   * @param bool $nested
   *   TRUE when the key names a container the locator places inside
   *   another section's region (isNestedContainer()).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintWarning[]
   *   Warnings at "{at}.{condition}.{property}".
   */
  protected function ownedPropertyWarnings(string $at, array $entry, VocabularyRoster $roster, bool $nested = FALSE): array {
    if ($roster->axisOwns() === []) {
      return [];
    }
    $warnings = [];
    $root = $roster->address()->root();
    // A layout's axes spell on the container the layout places, the
    // container kind's own tag; every other root spells on itself.
    $spelling_scope = $root->family === Address::FAMILY_LAYOUT ? Address::kind(Address::KIND_CONTAINER) : $root;
    $axes = $roster->axes();
    foreach ($entry as $condition => $block) {
      if (!is_array($block)) {
        continue;
      }
      foreach ($block as $property => $value) {
        $property = (string) $property;
        $axis = $roster->axisOwning($property);
        if ($axis === NULL) {
          continue;
        }
        if ($nested && ($axes[$axis]['root_only'] ?? FALSE)) {
          continue;
        }
        $label = $axes[$axis]['label'] ?? NULL;
        $attribute = DaedalusStylishMaterialsProvider::axisAttr($spelling_scope, $axis);
        $warnings[] = new BlueprintWarning(
          "$at.$condition.$property",
          'STYLE_PROPERTY_AXIS_OWNED',
          sprintf(
            '"%s" is owned by the %s axis%s on this element (the axis\'s values write it); the write landed, but wear the axis instead: %s="…" in the page language, or a variant reference on the "%s" axis.',
            $property,
            $axis,
            is_string($label) && $label !== '' && $label !== $axis ? sprintf(' ("%s")', $label) : '',
            $attribute,
            $axis,
          ),
        );
      }
    }
    return $warnings;
  }

  /**
   * Validates one entry's styles at an address: the one engine.
   *
   * Both lanes land here once their key grammar has named an address: the
   * element lane with the key's derived address (a root with the key's
   * part and children suffixes), the variant lane with the slot's element
   * address and the relative key's suffixes. The address's roster answers
   * whether the place is declared at all (resolveRoster(), the part, tag
   * tier and children gates) and, when it is, the grant and the value
   * spaces the conditions' properties validate against.
   *
   * @param string $at
   *   The entry's error path (the selectable key, or the relative key with
   *   '' for the root).
   * @param array $entry
   *   The entry's condition-keyed blocks, the "variants" list already
   *   validated and removed.
   * @param \Drupal\daedalus_blueprint\Materials\Address $address
   *   The address the entry's key resolved to.
   * @param string|null $theme
   *   The theme to validate against, or NULL for the default.
   * @param bool $closed
   *   Whether the grant posture is closed (strict enforcement).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at "{at}.{condition}.{property}" (no leading dot when the
   *   path is ''), or at "{at}" when the address is not a declared place.
   */
  protected function validateEntryAt(string $at, array $entry, Address $address, ?string $theme, bool $closed): array {
    $errors = [];
    $roster = $this->resolveRoster($address, $theme, $at, $closed, $errors);
    if ($roster === NULL) {
      return $errors;
    }
    return $this->validateEntryConditions($at, $entry, $roster, $closed, $theme);
  }

  /**
   * Validates an entry's condition-keyed style blocks against a roster.
   *
   * The condition/property half of entry validation, one path for both
   * lanes (validate(), path prefix = the selectable key; validateAt(),
   * prefix = the relative key, '' for the root element).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at "{prefix}.{condition}.{property}" (no leading dot when the
   *   prefix is '').
   */
  protected function validateEntryConditions(string $prefix, array $entry, VocabularyRoster $roster, bool $closed, ?string $theme): array {
    $errors = [];
    $canonical_seen = [];
    foreach ($entry as $condition => $properties) {
      $condition = (string) $condition;
      $at = $prefix === '' ? $condition : "$prefix.$condition";
      // The retired freeform slot gets its own corrective: the writer was
      // not reaching for a condition name, and the unknown-condition error
      // would point them at the wrong vocabulary. The slot is gone because
      // the recognized-property vocabulary covers standard CSS — the model
      // is the model, with no raw-CSS escape hatch beside it.
      if ($condition === 'freeform') {
        $errors[] = new BlueprintError($at, 'INVALID_STYLE_ENTRY', 'There is no "freeform" slot; styles are validated properties.', [], 'Set CSS properties under condition keys ("default", "hover", …); the recognized vocabulary covers standard CSS properties.');
        continue;
      }
      // The dead single-reference grammar gets its own corrective: "variant"
      // is not a condition, and the unknown-condition error would point the
      // writer at the wrong vocabulary entirely.
      if ($condition === 'variant') {
        $errors[] = new BlueprintError($at, 'INVALID_STYLE_ENTRY', 'There is no "variant" key; variant references are the "variants" list.', [], 'Reference variants as {"variants": [{"variant": "variant_id", "fragment": ""}]}: an ordered list of {"variant", "fragment"} objects (fragment "" wears the variant root), later references taking precedence.');
        continue;
      }
      // The condition vocabulary is the closed set the condition type
      // plugins provide (plus "default"), so an unknown name is an error,
      // not a pass-through. An "a+b" compound validates per part, at most
      // one condition per axis.
      if ($condition !== 'default') {
        $condition_errors = $this->validateCondition($at, $condition);
        if ($condition_errors !== []) {
          array_push($errors, ...$condition_errors);
          continue;
        }
        // Two spellings of the same compound would collide when the write
        // path canonicalizes part order, silently merging blocks the
        // writer meant as one — reject the ambiguity instead.
        $canonical = $this->conditionTypes->canonicalizeCondition($condition);
        if (isset($canonical_seen[$canonical])) {
          $errors[] = new BlueprintError($at, 'DUPLICATE_STYLE_CONDITION', sprintf('"%s" and "%s" are the same compound condition.', $canonical_seen[$canonical], $condition), ['canonical' => $canonical], 'Compound parts identify a condition regardless of order; merge the two blocks into one (the canonical spelling orders parts by axis declaration).');
          continue;
        }
        $canonical_seen[$canonical] = $condition;
      }
      if (!is_array($properties) || (array_is_list($properties) && $properties !== [])) {
        $errors[] = new BlueprintError($at, 'INVALID_STYLE_ENTRY', sprintf('The "%s" condition is not a property map.', $condition), [], 'A condition maps CSS property names to values, e.g. {"padding": "2rem"}.');
        continue;
      }
      foreach ($properties as $property => $value) {
        array_push($errors, ...$this->validateProperty($at, (string) $property, $value, $roster, $closed, $theme));
      }
    }
    return $errors;
  }

  /**
   * Validates a condition entry key: a condition name or an "a+b" compound.
   *
   * The compound grammar is the manager's (compoundParts()): a single
   * condition is the compound of one, and a multi-part compound must name a
   * known condition per part with at most one condition per axis — axes are
   * the WHEN counterpart of variant axes (one value per lane, composition
   * across lanes). Part order is NOT checked: any order is accepted and the
   * write paths canonicalize it, so an AI never has to learn the spelling
   * rule to write a valid compound. "default" is not a condition and cannot
   * join a compound — it is the absence of conditions.
   *
   * @param string $at
   *   The error path of the condition block.
   * @param string $condition
   *   The condition key to validate.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at that path; empty when the key is valid.
   */
  protected function validateCondition(string $at, string $condition): array {
    $parts = ConditionTypePluginManager::compoundParts($condition);
    if (count($parts) === 1) {
      if (!$this->conditionTypes->hasCondition($condition)) {
        $valid = ['default', ...$this->conditionTypes->getConditionNames()];
        return [
          new BlueprintError($at, 'INVALID_STYLE_CONDITION', sprintf('"%s" is not a known condition.', $condition), ['valid_conditions' => $valid], sprintf('Valid conditions: %s. Conditions on different axes combine with "+", e.g. "narrow+dark".', implode(', ', $valid))),
        ];
      }
      return [];
    }
    $errors = [];
    $axes = [];
    foreach ($parts as $part) {
      if (!$this->conditionTypes->hasCondition($part)) {
        $valid = $this->conditionTypes->getConditionNames();
        $errors[] = new BlueprintError($at, 'INVALID_STYLE_CONDITION', sprintf('"%s" (in the "%s" compound) is not a known condition.', $part, $condition), ['valid_conditions' => $valid], sprintf('Every "+" part must be a condition: %s. "default" cannot join a compound — it is the absence of conditions.', implode(', ', $valid)));
        continue;
      }
      $axis = $this->conditionTypes->getAxis($part);
      if (isset($axes[$axis])) {
        $errors[] = new BlueprintError($at, 'STYLE_CONDITION_AXIS_CONFLICT', sprintf('"%s" and "%s" are both on the "%s" axis.', $axes[$axis], $part, $axis), [
          'axis' => $axis,
          'conflicting_condition' => $axes[$axis],
        ], 'A compound holds at most one condition per axis: conditions sharing an axis are alternatives, not combinables — key each under its own condition instead.');
        continue;
      }
      $axes[$axis] = $part;
    }
    return $errors;
  }

  /**
   * Validates a skin's token records against the declared token scales.
   *
   * The token seam of the one validation engine: every path that writes
   * skin tokens — the daedalus_blueprint field handler (blueprint
   * create/update, AI ops), the token operations, and the disk import lane
   * converges here, so the grammar cannot fork per surface. The map is the
   * CANONICAL shape (SkinTokensItem::canonicalizeTokens(): the doors that
   * take authored text wrap a bare value into its record before calling),
   * so error paths name places in the canonical document.
   *
   * The grammar (scales are code, names and records are data):
   * - Scale keys must name a declared token scale
   *   (StyleGuide::getTokenScales()); minting a token on an undeclared
   *   scale is an error, never an implicit scale.
   * - Token names follow TOKEN_NAME: they become the tail of the derived
   *   custom property (--{prefix}-{name}).
   * - A token is a record {value, description, offered}
   *   (SkinTokensItem::RECORD_KEYS): value required, description a string,
   *   offered a boolean, nothing else (INVALID_TOKEN_RECORD).
   * - A value is a string (unconditional) or a condition-keyed map whose
   *   'default' entry is REQUIRED — a token undefined in the default state
   *   would make every var() reference silently unresolved outside its
   *   conditions, which is a data mistake, not a layering choice.
   * - Condition keys use the style map's condition vocabulary and compound
   *   grammar (validateCondition(): any part order, one condition per
   *   axis), restricted to TOKEN_CONDITION_AXES.
   * - Every value validates against its scale's declared shape through the
   *   property machinery's scalar paths
   *   (PropertyGroupPluginManager::validateShape()).
   * - Tokens may reference tokens (var() passes the shape structurally); on
   *   strict sites a var() reference resolving to a declared scale's
   *   namespace must name a token minted on THIS map — the submitted map is
   *   the skin's whole token set, so self-consistency is checkable without
   *   any active-skin lookup. Permissive sites pass unminted references
   *   (inert, never unsafe), mirroring the style-value posture.
   *
   * @param array $tokens
   *   The canonical tokens: {scale: {name: {value, description, offered}}}.
   * @param array $context
   *   Optional context: theme (string), as validate().
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors with paths relative to the tokens map ("{scale}",
   *   "{scale}.{name}", "{scale}.{name}.{record key}",
   *   "{scale}.{name}.value.{condition}"). The caller re-roots them onto
   *   its own path space. Empty when valid.
   */
  public function validateTokens(array $tokens, array $context = []): array {
    $theme = $context['theme'] ?? ($this->themeHandler->getDefault() ?: NULL);
    $scales = $this->styleGuide->getTokenScales($theme);
    $errors = [];

    // The reference universe for token-to-token var() checks: the submitted
    // map itself, declared scales only — computed once, NULL on permissive
    // sites (no check runs there).
    $minted = NULL;
    if ($this->styleGuide->propertiesClosed()) {
      $minted = [];
      foreach ($tokens as $scale => $names) {
        if (isset($scales[$scale]) && is_array($names) && !array_is_list($names)) {
          // A universe is a {name: value} map; a malformed record mints
          // nothing (its own error reports it).
          $minted[(string) $scale] = array_map(
            static fn (mixed $token): mixed => SkinTokensItem::isRecord($token) ? $token['value'] : NULL,
            array_filter($names, [SkinTokensItem::class, 'isRecord']),
          );
        }
      }
    }

    foreach ($tokens as $scale => $names) {
      $scale = (string) $scale;
      if (!isset($scales[$scale])) {
        $hint = $scales === []
          ? 'This site declares no token scales; scales are code, declared under the site declaration\'s token_scales section.'
          : sprintf('Declared scales: %s. Token names and values are data, but the scale a token is minted on must be declared in code.', implode(', ', array_keys($scales)));
        $errors[] = new BlueprintError($scale, 'TOKEN_SCALE_UNKNOWN', sprintf('"%s" is not a declared token scale.', $scale), ['valid_scales' => array_keys($scales)], $hint);
        continue;
      }
      if (!is_array($names) || (array_is_list($names) && $names !== [])) {
        $errors[] = new BlueprintError($scale, 'INVALID_TOKEN_SCALE_ENTRY', sprintf('The "%s" entry is not a map of token names to values.', $scale), [], 'Provide an object mapping token names to values, e.g. {"4": "1rem", "accent": {"default": "#1a1a2e", "dark": "#e8e8f0"}}.');
        continue;
      }
      $declaration = $scales[$scale];
      foreach ($names as $name => $value) {
        $name = (string) $name;
        $at = "$scale.$name";
        if (!preg_match(self::TOKEN_NAME, $name)) {
          $errors[] = new BlueprintError($at, 'INVALID_TOKEN_NAME', sprintf('"%s" is not a valid token name.', $name), [], 'Token names are lowercase letters, digits, and inner hyphens ("4", "2xl", "accent-hover"); the name becomes the tail of the CSS custom property.');
          continue;
        }
        array_push($errors, ...$this->validateTokenRecord($at, $value, $declaration, $scales, $minted));
      }
    }

    return $errors;
  }

  /**
   * Validates one token's record: {value, description, offered}.
   *
   * @param string $at
   *   The error path of the token ("{scale}.{name}").
   * @param mixed $record
   *   The token's stored record.
   * @param array $declaration
   *   The scale's declaration ({label, shape, ...}).
   * @param array $scales
   *   The declared token scales (for resolving var() references).
   * @param array|null $minted
   *   The submitted map's tokens per scale ({scale: {name: value}}), or
   *   NULL on permissive sites (no reference check runs).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at the record's path, its keys ("{at}.description",
   *   "{at}.offered") and its value ("{at}.value", conditions beneath).
   */
  protected function validateTokenRecord(string $at, mixed $record, array $declaration, array $scales = [], ?array $minted = NULL): array {
    if (!SkinTokensItem::isRecord($record)) {
      return [
        new BlueprintError($at, 'INVALID_TOKEN_RECORD', sprintf('%s is not a token record.', $at), ['keys' => SkinTokensItem::RECORD_KEYS], 'A token is a record {"value": ..., "description": "...", "offered": true}; a bare value ("1rem", or a condition-keyed map with a "default" entry) is accepted as shorthand for {"value": ...}.'),
      ];
    }
    if ($unknown = array_diff(array_keys($record), SkinTokensItem::RECORD_KEYS)) {
      return [
        new BlueprintError($at, 'INVALID_TOKEN_RECORD', sprintf('Unknown key(s) %s in the record of %s.', implode(', ', array_map(static fn ($key) => "\"$key\"", $unknown)), $at), ['keys' => SkinTokensItem::RECORD_KEYS], sprintf('A token record carries %s and nothing else.', implode(', ', SkinTokensItem::RECORD_KEYS))),
      ];
    }
    $errors = [];
    if (!is_string($record['description'])) {
      $errors[] = new BlueprintError("$at.description", 'INVALID_TOKEN_RECORD', sprintf('The description of %s is not a string.', $at), [], 'A description is prose: why the token exists and when to reach for it, or "" for none.');
    }
    if (!is_bool($record['offered'])) {
      $errors[] = new BlueprintError("$at.offered", 'INVALID_TOKEN_RECORD', sprintf('The offered flag of %s is not a boolean.', $at), [], 'offered is true (rows bound to the scale offer the token as an everyday step, the AI sees it) or false (minted and legal, offered nowhere).');
    }
    array_push($errors, ...$this->validateTokenValue("$at.value", $record['value'], $declaration, $scales, $minted));
    return $errors;
  }

  /**
   * Validates one token's value: a string or a condition-keyed map.
   *
   * @param string $at
   *   The error path of the token's value ("{scale}.{name}.value").
   * @param mixed $value
   *   The token's value.
   * @param array $declaration
   *   The scale's declaration ({label, shape, ...}).
   * @param array $scales
   *   The declared token scales (for resolving var() references).
   * @param array|null $minted
   *   The submitted map's tokens per scale ({scale: {name: value}}), or
   *   NULL on permissive sites (no reference check runs).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at that path (condition-keyed values at "{at}.{condition}").
   */
  protected function validateTokenValue(string $at, mixed $value, array $declaration, array $scales = [], ?array $minted = NULL): array {
    $shape = $declaration['shape'];
    $keywords = $declaration['keywords'] ?? [];
    if (is_scalar($value)) {
      $errors = $this->propertyGroups->validateShape($at, (string) $value, $shape, $keywords);
      array_push($errors, ...$this->checkTokenValueReferences($at, (string) $value, $scales, $minted));
      return $errors;
    }
    if (!is_array($value) || (array_is_list($value) && $value !== [])) {
      return [
        new BlueprintError($at, 'INVALID_TOKEN_VALUE', sprintf('The value of %s is not a token value.', $at), [], 'Provide a string ("1rem") or a condition-keyed map with a "default" entry ({"default": "#1a1a2e", "dark": "#e8e8f0"}).'),
      ];
    }
    $errors = [];
    if (!array_key_exists('default', $value)) {
      $errors[] = new BlueprintError($at, 'TOKEN_DEFAULT_REQUIRED', sprintf('The condition-keyed value of %s has no "default" entry.', $at), [], 'A conditioned token must define its default value; without one, var() references would be silently unresolved outside the listed conditions.');
    }
    $canonical_seen = [];
    foreach ($value as $condition => $conditioned) {
      $condition = (string) $condition;
      $condition_at = "$at.$condition";
      if ($condition !== 'default') {
        $condition_errors = $this->validateCondition($condition_at, $condition);
        if ($condition_errors === []) {
          $condition_errors = $this->checkTokenConditionAxes($condition_at, $condition);
        }
        if ($condition_errors !== []) {
          array_push($errors, ...$condition_errors);
          continue;
        }
        $canonical = $this->conditionTypes->canonicalizeCondition($condition);
        if (isset($canonical_seen[$canonical])) {
          $errors[] = new BlueprintError($condition_at, 'DUPLICATE_STYLE_CONDITION', sprintf('"%s" and "%s" are the same compound condition.', $canonical_seen[$canonical], $condition), ['canonical' => $canonical], 'Compound parts identify a condition regardless of order; merge the two entries into one (the canonical spelling orders parts by axis declaration).');
          continue;
        }
        $canonical_seen[$canonical] = $condition;
      }
      if (!is_scalar($conditioned)) {
        $errors[] = new BlueprintError($condition_at, 'INVALID_TOKEN_VALUE', sprintf('The "%s" value of %s is not a CSS value.', $condition, $at), [], 'Each condition maps to a plain string value; conditions do not nest.');
        continue;
      }
      array_push($errors, ...$this->propertyGroups->validateShape($condition_at, (string) $conditioned, $shape, $keywords));
      array_push($errors, ...$this->checkTokenValueReferences($condition_at, (string) $conditioned, $scales, $minted));
    }
    return $errors;
  }

  /**
   * Checks a token value's var() references against the submitted map.
   *
   * The token-to-token counterpart of checkTokenReferences(): same
   * resolution rule (StyleGuide::tokenReference()), same posture toward
   * out-of-namespace variables (pass — the spine owns only its declared
   * prefixes), but the universe is the SUBMITTED map, because the map being
   * validated is the skin's whole token set. Runs only when $minted is
   * non-NULL (strict sites).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at the given path; empty on permissive sites.
   */
  protected function checkTokenValueReferences(string $at, string $value, array $scales, ?array $minted): array {
    if ($minted === NULL || !str_contains($value, 'var(')) {
      return [];
    }
    $errors = [];
    preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $value, $matches);
    foreach (array_unique($matches[1]) as $variable) {
      // Skin tokens may reference swatches (the swatch file delivers before
      // the skin file for exactly this reason); the reserved namespace is
      // checked against the published universe like everywhere else.
      $swatch_error = $this->checkSwatchReference($at, $variable);
      if ($swatch_error !== NULL) {
        $errors[] = $swatch_error;
        continue;
      }
      $reference = $scales === [] ? NULL : StyleGuide::tokenReference($variable, $scales);
      if ($reference === NULL) {
        continue;
      }
      $tokens = $minted[$reference['scale']] ?? [];
      if (!array_key_exists($reference['name'], $tokens)) {
        $names = array_map('strval', array_keys($tokens));
        // The corrective enumerates what this skin DOES mint on the scale,
        // with each token's value (the same teaching move the style-value
        // path makes): the nearest valid reference is one of these, or a
        // fresh mint in this map.
        $hint = $tokens === []
          ? sprintf('This skin mints no tokens on the "%s" scale; mint the referenced token in this map first, or use a literal value.', $reference['scale'])
          : sprintf('Tokens this skin mints on the "%s" scale: %s. A token may reference other tokens of the same skin; reference one of these, mint the missing token, or use a literal value.', $reference['scale'], implode(', ', $this->mintedMaterials($reference['scale'], $scales[$reference['scale']], $tokens)));
        $errors[] = new BlueprintError($at, 'STYLE_TOKEN_NOT_MINTED', sprintf('"%s" is in the "%s" scale\'s namespace, but this skin mints no "%s" token on it.', $variable, $reference['scale'], $reference['name']), [
          'scale' => $reference['scale'],
          'minted' => $names,
        ], $hint);
      }
    }
    return $errors;
  }

  /**
   * Validates a swatch's value against its kind.
   *
   * The swatch seam of the one validation engine: every path that writes a
   * swatch value — the palette gestures (the manager lane), the disk
   * import lane, the daedalus_blueprint field handler when it lands —
   * converges here, so the grammar cannot fork per surface.
   *
   * The grammar mirrors token values deliberately (one value model for the
   * whole palette): a value is a string (unconditional) or a
   * condition-keyed map whose 'default' entry is REQUIRED, condition keys
   * ride the style map's vocabulary and compound grammar restricted to the
   * TOKEN_CONDITION_AXES (a swatch delivers on the token scope element,
   * exactly like a token, so only width/scheme/motion can condition it).
   * Each scalar dispatches to the KIND plugin, which owns the shape
   * ("color", "gradient"); on strict sites every var() reference resolves
   * against the same universes style values check — minted tokens per
   * declared scale, published swatches in the reserved namespace — while
   * permissive sites pass unresolved references (inert, never unsafe).
   *
   * @param string $kind
   *   The swatch kind: a SwatchKind plugin id.
   * @param mixed $value
   *   The swatch's value.
   * @param array $context
   *   Optional context: theme (string), as validate(); design_system
   *   (string|null), the swatch's membership, as validateAt().
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at "kind" (unknown kind), "value", or "value.{condition}",
   *   or at "design_system" when the member's system does not exist.
   *   The caller re-roots them onto its own path space. Empty when valid.
   */
  public function validateSwatchValue(string $kind, mixed $value, array $context = []): array {
    $theme = $context['theme'] ?? ($this->themeHandler->getDefault() ?: NULL);
    if ($design_system = $this->memberOf($context)) {
      return $this->withMemberUniverse($design_system, $theme, fn (): array => $this->validateSwatchValue($kind, $value, self::memberless($context, $theme)));
    }
    if (!$this->swatchKinds->hasDefinition($kind)) {
      $kinds = $this->swatchKinds->kinds();
      return [
        new BlueprintError('kind', 'SWATCH_KIND_UNKNOWN', sprintf('"%s" is not a swatch kind.', $kind), ['valid_kinds' => $kinds], sprintf('The swatch kinds are: %s. The kind owns the value\'s shape and decides where the swatch is offered.', implode(', ', $kinds))),
      ];
    }
    $plugin = $this->swatchKinds->createInstance($kind);
    assert($plugin instanceof SwatchKindInterface);
    $closed = $this->propertiesClosed();
    if (is_scalar($value)) {
      $errors = $plugin->validateValue('value', (string) $value);
      array_push($errors, ...$this->checkTokenReferences('value', (string) $value, $theme, $closed));
      return $errors;
    }
    if (!is_array($value) || (array_is_list($value) && $value !== [])) {
      return [
        new BlueprintError('value', 'INVALID_SWATCH_VALUE', 'The swatch value is not a CSS value.', [], 'Provide a string ("#1a1a2e") or a condition-keyed map with a "default" entry ({"default": "#1a1a2e", "dark": "#e8e8f0"}).'),
      ];
    }
    $errors = [];
    if (!array_key_exists('default', $value)) {
      $errors[] = new BlueprintError('value', 'SWATCH_DEFAULT_REQUIRED', 'The condition-keyed swatch value has no "default" entry.', [], 'A conditioned swatch must define its default value; without one, var() references would be silently unresolved outside the listed conditions.');
    }
    $canonical_seen = [];
    foreach ($value as $condition => $conditioned) {
      $condition = (string) $condition;
      $condition_at = "value.$condition";
      if ($condition !== 'default') {
        $condition_errors = $this->validateCondition($condition_at, $condition);
        if ($condition_errors === []) {
          $condition_errors = $this->checkTokenConditionAxes($condition_at, $condition);
        }
        if ($condition_errors !== []) {
          array_push($errors, ...$condition_errors);
          continue;
        }
        $canonical = $this->conditionTypes->canonicalizeCondition($condition);
        if (isset($canonical_seen[$canonical])) {
          $errors[] = new BlueprintError($condition_at, 'DUPLICATE_STYLE_CONDITION', sprintf('"%s" and "%s" are the same compound condition.', $canonical_seen[$canonical], $condition), ['canonical' => $canonical], 'Compound parts identify a condition regardless of order; merge the two entries into one (the canonical spelling orders parts by axis declaration).');
          continue;
        }
        $canonical_seen[$canonical] = $condition;
      }
      if (!is_scalar($conditioned)) {
        $errors[] = new BlueprintError($condition_at, 'INVALID_SWATCH_VALUE', sprintf('The "%s" value is not a CSS value.', $condition), [], 'Each condition maps to a plain string value; conditions do not nest.');
        continue;
      }
      array_push($errors, ...$plugin->validateValue($condition_at, (string) $conditioned));
      array_push($errors, ...$this->checkTokenReferences($condition_at, (string) $conditioned, $theme, $closed));
    }
    return $errors;
  }

  /**
   * Checks a valid condition's axes against the token allowlist.
   *
   * Runs after validateCondition() accepted the key, so every part is a
   * known condition; this narrows to the axes token definitions can deliver
   * on (TOKEN_CONDITION_AXES).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at the given path; empty when every part's axis is allowed.
   */
  protected function checkTokenConditionAxes(string $at, string $condition): array {
    $errors = [];
    foreach (ConditionTypePluginManager::compoundParts($condition) as $part) {
      $axis = $this->conditionTypes->getAxis($part);
      if (!in_array($axis, self::TOKEN_CONDITION_AXES, TRUE)) {
        $errors[] = new BlueprintError($at, 'TOKEN_CONDITION_AXIS_INVALID', sprintf('"%s" is on the "%s" axis, which cannot condition a token value.', $part, $axis), ['valid_axes' => self::TOKEN_CONDITION_AXES], sprintf('Token values may vary on the %s axes only. Interaction states are per-element: put hover treatments in style maps or variants, where the state applies to the styled element.', implode(', ', self::TOKEN_CONDITION_AXES)));
      }
    }
    return $errors;
  }

  /**
   * Validates an animation's keyframe steps.
   *
   * The steps grammar every animation write path converges on (the
   * Materials gestures, the blueprint lane, the disk lane): an
   * offset-keyed map, offsets `from`, `to` or `N%` (one grammar with the
   * storage's canonicalization, AnimationStepsItem::canonicalOffset(), so
   * two spellings of one offset are refused here as a duplicate), at least
   * one step, each step a property map. Each declaration validates through
   * the universal vocabulary with validateAt() semantics minus the
   * address: a keyframes block has no element, so no roster grants or
   * withholds here, every recognized property is declarable (no
   * STYLE_PROPERTY_NOT_DECLARED, strict sites included), the site's
   * authored constraint defaults bind exactly as on any granted property
   * (validateValue(), the value half of validateProperty()), and var()
   * references resolve as anywhere (strict sites refuse
   * unminted tokens and unpublished swatches). Three refusals are the
   * block's own: animation-* longhands other than animation-timing-function
   * (a keyframe cannot re-time or re-name the animation running it; the
   * per-step easing is the one legal one), the animation shorthand and
   * timeline-trigger (the WHEN of the animation is the wearer's business,
   * exactly as animation-trigger is), and media references (a keyframes
   * block resolves through no property definition, so a media token would
   * print as a literal).
   *
   * @param mixed $steps
   *   The steps as submitted.
   * @param array $context
   *   Optional context: theme (string), as validate(); design_system
   *   (string|null), the animation's membership, as validateAt().
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at "steps", "steps.{offset}" or "steps.{offset}.{property}",
   *   or at "design_system" when the member's system does not exist.
   *   The caller re-roots them onto its own path space. Empty when valid.
   */
  public function validateAnimationSteps(mixed $steps, array $context = []): array {
    $theme = $context['theme'] ?? ($this->themeHandler->getDefault() ?: NULL);
    if ($design_system = $this->memberOf($context)) {
      return $this->withMemberUniverse($design_system, $theme, fn (): array => $this->validateAnimationSteps($steps, self::memberless($context, $theme)));
    }
    if (!is_array($steps) || $steps === [] || array_is_list($steps)) {
      return [
        new BlueprintError('steps', 'INVALID_ANIMATION_STEPS', 'The animation steps are not a keyframes map.', [], 'Provide an object keyed by offset ("0%", "50%", "100%", or "from"/"to") mapping CSS properties to values, with at least one step, e.g. {"0%": {"opacity": "0"}, "100%": {"opacity": "1"}}.'),
      ];
    }
    $closed = $this->propertiesClosed();
    // The universal vocabulary: every recognized property, nothing
    // constrained beyond the site's authored defaults (when constraints
    // are law at all).
    $defaults = $this->constraintsAreLaw() ? $this->styleGuide->getConstraintDefaults($theme) : [];
    $errors = [];
    $seen = [];
    foreach ($steps as $offset => $declarations) {
      $offset = (string) $offset;
      $at = "steps.$offset";
      $canonical = AnimationStepsItem::canonicalOffset($offset);
      if ($canonical === NULL) {
        $errors[] = new BlueprintError($at, 'INVALID_ANIMATION_OFFSET', sprintf('"%s" is not a keyframe offset.', $offset), [], 'An offset is a percentage from 0% to 100%, or the keywords "from" (0%) and "to" (100%); one offset per step.');
        continue;
      }
      if (isset($seen[$canonical])) {
        $errors[] = new BlueprintError($at, 'DUPLICATE_ANIMATION_OFFSET', sprintf('"%s" and "%s" are the same keyframe offset.', $seen[$canonical], $offset), ['canonical' => $canonical], 'Offsets identify a step regardless of spelling ("from" is 0%, "to" is 100%); merge the two steps into one.');
        continue;
      }
      $seen[$canonical] = $offset;
      if (!is_array($declarations) || (array_is_list($declarations) && $declarations !== [])) {
        $errors[] = new BlueprintError($at, 'INVALID_ANIMATION_STEPS', sprintf('The "%s" step is not a property map.', $offset), [], 'A step maps CSS property names to values, e.g. {"opacity": "0", "transform": "translateY(1rem)"}.');
        continue;
      }
      foreach ($declarations as $property => $value) {
        $property = (string) $property;
        if (($property === 'animation' || $property === 'timeline-trigger' || str_starts_with($property, 'animation-')) && $property !== 'animation-timing-function') {
          $errors[] = new BlueprintError("$at.$property", 'INVALID_ANIMATION_STEP_PROPERTY', sprintf('"%s" cannot be set inside a keyframe.', $property), [], 'A keyframe describes the element at one moment of the animation running it, so animation-* and trigger properties do not apply there; only animation-timing-function (the easing from this step to the next) is a keyframe property. Set timing, iteration, fill and triggers on the wearing element instead.');
          continue;
        }
        if (is_string($value) && MediaToken::mentions($value)) {
          $errors[] = new BlueprintError("$at.$property", 'INVALID_STYLE_VALUE', sprintf('Media references are not valid inside a keyframe (%s).', $property), [], 'Keyframes resolve through no property definition, so a url(\'media:<uuid>\') token would print as a literal; put the media reference on the wearing element.');
          continue;
        }
        $group_id = $this->recognizeProperty("$at.$property", $property, $value, $closed);
        if ($group_id instanceof BlueprintError) {
          $errors[] = $group_id;
          continue;
        }
        $constraints = is_array($defaults[$property] ?? NULL) ? $defaults[$property] : [];
        array_push($errors, ...$this->validateValue("$at.$property", $group_id, $property, (string) $value, $constraints, $closed, $theme));
      }
    }
    return $errors;
  }

  /**
   * Validates element-relative styles at an address: the variant lane.
   *
   * The one door for styles that exist before any element does (a
   * variant's styles at its scope: disk-shipped variants at import,
   * AI-authored variants at create, the variant gestures, the conformance
   * judge; a skin's defaults at their scope), so the grammar cannot fork
   * per surface. No source element, no host entity: the caller hands the
   * ROOT address the styles are worn at, and every entry validates
   * against the vocabulary roster of the address its key resolves to
   * (validateEntryAt(), the same engine the element lane reaches after
   * deriving an address from placement).
   *
   * Structural checks (slot-key grammar, entry shape, nested reference
   * grammar) and semantic checks (conditions, the grant, the value
   * spaces) both run here: an address-only caller has no other grammar
   * gate in front of it. Keys parse through the SlotKey grammar with the
   * root kind the address implies, so a slot path the root does not
   * structurally contain is rejected exactly like a malformed key.
   * Slotted entries validate at the ADDRESS THEIR SLOT RESOLVES TO, not
   * the root's (slotScope(): container slots at the universal container
   * kind, block slots at their bundle, field slots at the universal field
   * kind), and a '::selector' suffix rides that address into the roster
   * walk: tags and types are legal here exactly as on a host map (a
   * structural relation transfers; only instance parts stay behind).
   *
   * Entries are ENTRY-SHAPED, exactly like style map entries (one grammar):
   * a fragment may carry a "variants" reference list beside its
   * condition-keyed blocks, validated by the same list walk the map lane
   * runs (shape, per-variant dedupe, at most one reference per axis). A
   * '' fragment reference must name an EXISTING variant whose scope pairs
   * with the address its slot resolves to (the variant's own address at
   * the root, the slot's element address on a path; Address::pairsWith()),
   * so dangling and mis-scoped nested references are save-time errors,
   * never render surprises; a non-empty fragment reference validates as a
   * bound lookup key exactly like on a map entry
   * (validateFragmentReference()). On top of the per-reference checks,
   * the transitive reference closure must stay a tree: a save whose
   * styles reach the saved variant itself (context key "variant_identity")
   * or any cycle among referenced variants is rejected
   * (checkReferenceCycles()), because apply distributes nested references
   * recursively and a cycle would never terminate.
   *
   * @param array $styles
   *   Element-relative styles, the variant storage shape: '' maps the root
   *   element's entry, slash-separated slot paths (e.g.
   *   "container(1)/block(text,1)") its structural children's, and a
   *   '::selector' suffix a relative selector under one.
   * @param \Drupal\daedalus_blueprint\Materials\Address $address
   *   The ROOT address the styles are worn at: a variant's scope
   *   ("block_type:{bundle}", "layout:{layout_id}",
   *   "block_plugin:{plugin_id}", "base:{tag}", "kind:{kind}"), parsed by
   *   the caller (Address::fromString() for a stored scope, variantScope()
   *   for a string from outside, which also keeps kind:region and an
   *   illegal base tag out). An undeclared bundle or layout resolves to
   *   its universal kind's roster, the same degradation the element lane
   *   applies, never an error, so a variant may ship before its declaring
   *   guide is installed.
   * @param array $context
   *   Optional context:
   *   - theme (string): as validate().
   *   - variant_identity (string[]): the reference identities (machine
   *     name, uuid) of the variant these styles are being saved INTO, when
   *     one exists. Lets the cycle walk recognize the saved variant in the
   *     transitive closure of the styles' nested references; without it
   *     only cycles among already-saved variants are detectable.
   *   - variant_axis (string): the axis the saved variant lives on. The
   *     root entry reserves it: apply places the variant's own reference
   *     there on every wearer, so a root '' reference sharing the axis is
   *     rejected at save (validateVariantList()'s $reserved_axis).
   *   - design_system (string|null): the saved variant's membership. A
   *     member validates in ITS system's universe (that system's members
   *     plus the global ones, its default skin's tokens), whether or not
   *     the system is active: a package lands whole while another system
   *     is active, and its variants resolve their own vocabulary. NULL or
   *     absent (a global variant) validates in the roster, the active
   *     system's universe.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors with paths relative to the styles map: root-element errors at
   *   "{condition}.{property}" (entry-level at ''), selector errors at
   *   "::.class.{condition}.{property}", a membership naming no design
   *   system at "design_system". The caller re-roots them onto its own
   *   path space. Empty when valid.
   *
   * @throws \InvalidArgumentException
   *   When the address is not a root: a selector is a key's business
   *   ('::.part', '::> *'), never the address styles are worn at. A
   *   caller bug, not bad data.
   */
  public function validateAt(array $styles, Address $address, array $context = []): array {
    if (!$address->isRoot()) {
      throw new \InvalidArgumentException(sprintf('Styles are validated at a root address; "%s" carries a suffix the relative key should carry.', $address));
    }
    $theme = $context['theme'] ?? ($this->themeHandler->getDefault() ?: NULL);
    if ($design_system = $this->memberOf($context)) {
      return $this->withMemberUniverse($design_system, $theme, fn (): array => $this->validateAt($styles, $address, self::memberless($context, $theme)));
    }
    $closed = $this->propertiesClosed();
    // base:{tag} addresses (tag treatments and site defaults alike) and
    // block_plugin addresses have no root kind, which parse() treats as
    // the path-less key space, exactly right: bare markup has no
    // structural children and plugin blocks carry no blueprint fields or
    // slot space, so '' and '::selector' are their only legal keys.
    $root_kind = SlotKey::rootKindForScope((string) $address);
    $errors = [];

    foreach ($styles as $relative => $entry) {
      $relative = (string) $relative;
      $slot = SlotKey::parse($relative, $root_kind);
      if ($slot === NULL) {
        $errors[] = new BlueprintError($relative, 'INVALID_STYLE_KEY', sprintf('"%s" is not an element-relative style key for scope "%s".', $relative, $address), [], self::RELATIVE_KEY_GRAMMAR_HINT);
        continue;
      }
      if (!is_array($entry) || (array_is_list($entry) && $entry !== [])) {
        $errors[] = new BlueprintError($relative, 'INVALID_STYLE_ENTRY', 'A variant style entry is a condition-keyed property map, optionally carrying a "variants" reference list.', [], 'For example {"default": {"padding": "2rem"}, "hover": {…}} or {"variants": [{"variant": "hero", "fragment": ""}], "default": {…}}.');
        continue;
      }
      // The element the slot resolves to: the root itself, or the
      // address of the path's last segment.
      $element = $this->slotScope($slot, $address);
      // Entry-shaped fragments (one grammar): a fragment carries a
      // "variants" reference list exactly like a style map entry. A ''
      // fragment reference scope-pairs against the address its SLOT
      // resolves to, because apply copies it onto the element that slot
      // resolves to; a rule entry's against the rule's LANDING under
      // that element (slotLanding()), because apply places it at the
      // same rule under the new wearer, where it delivers at the landing.
      if (array_key_exists('variants', $entry)) {
        $reserved_axis = $context['variant_axis'] ?? NULL;
        $landing = $this->slotLanding($slot, $element, $theme);
        array_push($errors, ...$this->validateVariantList(
          $relative,
          $entry['variants'],
          fn (string $reference_at, string $reference): array => $this->validateNestedRootReference($reference_at, $reference, $landing, $relative),
          // Only the ROOT entry reserves the saved variant's own axis: a
          // slot entry's same-axis root reference is the flagship
          // composition case — it lands on the resolved child beside the
          // fragment reference as the composition pair.
          $relative === '' && is_string($reserved_axis) && $reserved_axis !== '' ? $reserved_axis : NULL,
        ));
        unset($entry['variants']);
      }
      // Instance parts never travel in variants: identity classes
      // are minted per content instance, so carrying their styles makes
      // the variant inert on other elements, or a lie when an unrelated
      // element reuses the id. The client capture excludes them
      // (captureSources in variant-scope.js); this is the server's
      // matching gate for AI- and disk-authored variants, ahead of the
      // roster (whose part rule would answer them from the instance
      // namespaces, as it does for the element lane).
      $instance = self::instancePart($slot->selector);
      if ($instance !== NULL) {
        $errors[] = new BlueprintError($relative, 'STYLE_INSTANCE_PART_IN_VARIANT', sprintf('"%s" is an instance-bound identity part; variants cannot carry it.', $instance), [], 'Identity classes (s-*) are minted per content instance, so their styles do not transfer: another element\'s text wears different ids. Style the element directly ("{key}::.s-…" in its own style map) instead of through a variant.');
        continue;
      }
      array_push($errors, ...$this->validateEntryAt($relative, $entry, $element->withSelector($slot->selector), $theme, $closed));
    }

    // The tree invariant, checked at save and never at render: the styles'
    // transitive reference closure must not reach the saved variant itself
    // nor contain a cycle among saved variants.
    array_push($errors, ...$this->checkReferenceCycles($styles, $context['variant_identity'] ?? []));

    return $errors;
  }

  /**
   * Validates a skin's site defaults: scope-keyed element-relative styles.
   *
   * The defaults seam of the one validation engine, riding the address
   * door (validateAt()): every path that writes skin defaults (the
   * update_default operation and the disk import lane, which lands
   * shipped defaults itself) converges here, so the grammar cannot fork
   * per surface. A site default is exactly a variant's styles applied
   * globally to a declared scope, with two narrowings the global
   * application forces:
   * - The scope vocabulary is the variant scope vocabulary
   *   (variantScope(): the base:{tag} family included; kind:region is not
   *   a scope anywhere, see SCOPE_KINDS).
   * - Keys are element-only ('' and '::selector' keys): a slot path names
   *   a position inside ONE placement, but defaults have no placement to
   *   distribute fragments through — nothing could ever carry the class.
   * - Entries carry their styles directly: variant references are per-
   *   element choices, never global defaults (default_variants covers the
   *   birth-stamped case).
   *
   * @param array $defaults
   *   The decoded defaults: {scope: {relative key: entry}}.
   * @param array $context
   *   Optional context: theme (string), as validate().
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors with paths relative to the defaults map
   *   ("{scope}", "{scope}.{condition}.{property}",
   *   "{scope}::.class.{condition}.{property}"). The caller re-roots them
   *   onto its own path space. Empty when valid.
   */
  public function validateDefaults(array $defaults, array $context = []): array {
    $theme = $context['theme'] ?? ($this->themeHandler->getDefault() ?: NULL);
    // A skin's defaults deliver only while THAT skin is active, so their
    // var() references resolve against ITS tokens, not whatever skin
    // happens to be active at write time (a shipped or staged skin must be
    // self-contained). Callers pass the owning skin's canonical token map
    // (records per name) as 'skin_tokens'; without it the active-skin
    // universe applies.
    $skin_tokens = $context['skin_tokens'] ?? NULL;
    if (is_array($skin_tokens)) {
      return $this->withMintedUniverse($skin_tokens, $theme, fn (): array => $this->validateDefaults($defaults, ['theme' => $theme] + array_diff_key($context, ['skin_tokens' => TRUE])));
    }
    $errors = [];
    foreach ($defaults as $scope => $styles) {
      $scope = (string) $scope;
      $address = self::variantScope($scope);
      if ($address === NULL) {
        $errors[] = new BlueprintError($scope, 'SKIN_DEFAULT_SCOPE_INVALID', sprintf('"%s" is not a site-defaults scope.', $scope), [
          'valid_families' => [
            'base:{tag}',
            'block_type:{bundle}',
            'block_plugin:{plugin_id}',
            'layout:{layout_id}',
            'kind:{entity|container|field|block}',
          ],
        ], sprintf('A default\'s scope is "base:{tag}" (bare prose markup, tags: %s), "block_type:{bundle}", "block_plugin:{plugin_id}" (a base block plugin id), "layout:{layout_id}", or "kind:{kind}" with kind one of: entity, container, field, block. Regions are not addressable elements, so kind:region cannot host defaults.', implode(', ', StyleGuide::BASE_TAGS)));
        continue;
      }
      if (!is_array($styles) || (array_is_list($styles) && $styles !== [])) {
        $errors[] = new BlueprintError($scope, 'INVALID_SKIN_DEFAULTS_ENTRY', sprintf('The "%s" defaults are not element-relative styles.', $scope), [], 'Provide an object of element-relative keys ("" for the scope\'s element, "::" plus a relative selector for a part, its paragraphs, its children, ...), each mapping conditions to CSS properties, e.g. {"": {"default": {"max-width": "80rem"}}}. ' . RelativeSelector::GRAMMAR);
        continue;
      }
      foreach ($styles as $relative => $entry) {
        $relative = (string) $relative;
        $at = "$scope.$relative";
        // Element-only keys: a slot path could validate structurally, but a
        // default has no placement to distribute a fragment through, so it
        // would be data that can never deliver — rejected, not inert.
        if ($relative !== '' && !str_starts_with($relative, '::')) {
          $errors[] = new BlueprintError($at, 'SKIN_DEFAULT_KEY_NOT_ELEMENT', sprintf('"%s" is a slot path; site defaults style the scope\'s element itself.', $relative), [], 'Defaults keys are "" (the scope\'s element) and "::" plus a relative selector under it. To style a composed subtree, save a variant and apply it where placed.');
          unset($styles[$relative]);
          continue;
        }
        if (is_array($entry) && array_key_exists('variants', $entry)) {
          $errors[] = new BlueprintError($at, 'SKIN_DEFAULT_VARIANT_REF', 'A site default carries its styles directly; it cannot reference variants.', [], 'Variant references are per-element choices. To stamp a variant on new op-created placements, declare default_variants in the scope\'s style guide declaration; to restyle a scope site-wide, put the properties here.');
          unset($styles[$relative]);
        }
      }
      foreach ($this->validateAt($styles, $address, $context) as $error) {
        $path = $error->getPath();
        $errors[] = $error->withPath(
          $path === '' ? $scope : $scope . (str_starts_with($path, '::') ? $path : '.' . $path),
        );
      }
    }
    return $errors;
  }

  /**
   * Validates a declaration's own defaults: the declared-defaults lane.
   *
   * A block type, block plugin, or layout declaration may ship `defaults`
   * for exactly the scope it declares: the literal box facts its markup
   * needs (the picture is a block box, the linked root shrink-wraps it),
   * in the skin defaults grammar for that one scope. The seam rides the
   * defaults seam (validateDefaults(): element-only keys, styles carried
   * directly, one grammar) with the posture the stratum rule forces:
   * - The minted universe is EMPTY (withMintedUniverse([])), and the
   *   token check runs whatever enforcement the site configures, so any
   *   var() into a declared scale's namespace is STYLE_TOKEN_NOT_MINTED.
   *   Declared defaults are token-free markup facts (code); token-bearing
   *   box defaults are design values and belong on the skin's own
   *   block_type: defaults, where the member-universe validation lives.
   * - The property world stays OPEN: recognition and grammar only, no
   *   closed-world grant check, constraints never law. The check runs at
   *   guide build, so it must answer the same on every site: a code fact
   *   that failed only under strict enforcement would let a config flip
   *   brick the guide, and a markup owner declaring its own box is not an
   *   editor styling it (the grants describe what editors may write).
   *
   * Reached from StyleGuide::ensureValidated() through a service closure
   * (the validator depends on the guide); the guide names the declaration
   * in the build error it appends each result to.
   *
   * @param array $styles
   *   The declared defaults: {relative key: entry}.
   * @param string $scope
   *   The declaring refinement's scope: "block_type:{bundle}",
   *   "block_plugin:{plugin_id}", or "layout:{layout_id}".
   * @param string|null $theme
   *   The theme whose layered guide the declaration belongs to.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors with paths as validateDefaults() roots them
   *   ("{scope}.{condition}.{property}", "{scope}::.class.{...}",
   *   "{scope}.{relative}" for a rejected key). Empty when valid.
   */
  public function validateDeclaredDefaults(array $styles, string $scope, ?string $theme = NULL): array {
    $theme ??= $this->themeHandler->getDefault() ?: NULL;
    $saved = $this->declaredLane;
    $this->declaredLane = TRUE;
    try {
      return $this->withMintedUniverse([], $theme, fn (): array => $this->validateDefaults([$scope => $styles], ['theme' => $theme]));
    }
    finally {
      $this->declaredLane = $saved;
    }
  }

  /**
   * Whether the property world is closed for the running validation.
   *
   * The site's enforcement mode (StyleGuide::propertiesClosed()), except
   * on the declared-defaults lane, whose posture is fixed by code.
   */
  protected function propertiesClosed(): bool {
    return !$this->declaredLane && $this->styleGuide->propertiesClosed();
  }

  /**
   * Whether authored constraints bind the running validation.
   *
   * The site's enforcement mode (StyleGuide::constraintsAreLaw()), except
   * on the declared-defaults lane, whose posture is fixed by code.
   */
  protected function constraintsAreLaw(): bool {
    return !$this->declaredLane && $this->styleGuide->constraintsAreLaw();
  }

  /**
   * The variant axis vocabulary of a scope.
   *
   * The axis half of scope resolution, read from the scope's roster
   * (VocabularyRoster::axisNames(): the built-in "style" axis plus
   * whatever variant_axes the scope's effective declaration adds, in
   * declaration order). Every consumer that starts from a scope string
   * (the variant entity's own axis check, the variant styles field
   * handler, AI surfaces) resolves through here so the vocabulary can
   * never fork.
   *
   * @return string[]|null
   *   The axis machine names, "style" first, or NULL when the string is
   *   not a variant scope (variantScope()). base:{tag} scopes resolve like
   *   any other: their axes come from the site declaration's base section
   *   (the built-in "style" axis when the tag declares none), so
   *   base-scoped variants (tag treatments like link styles) save and
   *   compose exactly like element-scoped ones.
   */
  public function scopeAxes(string $scope, ?string $theme = NULL): ?array {
    $theme ??= $this->themeHandler->getDefault() ?: NULL;
    $address = self::variantScope($scope);
    return $address === NULL ? NULL : $this->vocabulary->build($address, $theme)->axisNames();
  }

  /**
   * Parses a style map key.
   *
   * @return array|null
   *   ['kind' => element kind, 'uuid' => qualifier uuid, 'selector' => the
   *   RelativeSelector after the key's "::" or NULL], or NULL when the key
   *   does not parse. block:{uuid} parses to kind "block";
   *   block:{uuid}:{field} to kind "field" (a field of an inline block
   *   styles like any field). The grammar here is charset only: which
   *   tags, types and parts a selector may name is semantic and enforced
   *   by resolveRoster() through the roster walk. This is the ELEMENT key
   *   grammar (a kind qualified by a live uuid); the selector suffix is
   *   the one relative selector grammar every key shares
   *   (RelativeSelector::splitKey(), canonical spelling only), and
   *   captureScope() resolves an element key to its address.
   */
  public function parseKey(string $key): ?array {
    $split = RelativeSelector::splitKey($key);
    if ($split === NULL) {
      return NULL;
    }
    [$key, $selector] = $split;
    $uuid = self::UUID;
    $name = self::MACHINE_NAME;
    $forms = [
      ['/^entity:(' . $uuid . ')$/', 'entity'],
      ['/^field:(' . $uuid . '):' . $name . '$/', 'field'],
      ['/^container:(' . $uuid . ')$/', 'container'],
      ['/^block:(' . $uuid . '):' . $name . '$/', 'field'],
      ['/^block:(' . $uuid . ')$/', 'block'],
    ];
    foreach ($forms as [$pattern, $kind]) {
      if (preg_match($pattern, $key, $matches)) {
        return ['kind' => $kind, 'uuid' => $matches[1], 'selector' => $selector];
      }
    }
    return NULL;
  }

  /**
   * The styling address a style map key resolves to.
   *
   * The address is the granularity the declaring style-guide YAML speaks:
   * blocks are addressed per block type ("block_type:{bundle}") or, for
   * plugin placements, per base block plugin ("block_plugin:{plugin_id}"),
   * containers per layout plugin ("layout:{layout_id}"), every other
   * element by its kind ("kind:{entity|field}"), and a key's relative
   * selector rides the address so the roster walk resolves it
   * ("layout:{id}::> pb-text"). The rule is ONE resolution rule at every
   * depth: a container's declaration IS its layout's declaration, whether
   * the container is a top-level row or nested inside another container
   * (nesting is position, not kind). The one derivation every reader of
   * "what does this key address" shares: validation's roster
   * (resolveRoster()), the media constraints (MediaValueResolver), the
   * style stack's floor (its root), and the variant scope captureScope()
   * lands on.
   *
   * @param string $key
   *   A style map key.
   * @param array $context
   *   The validate() context; "host" enables block bundle and section
   *   layout resolution.
   *
   * @return \Drupal\daedalus_blueprint\Materials\Address|null
   *   The address, or NULL when the key does not parse. A block placement
   *   whose bundle cannot be resolved addresses "kind:block", a container
   *   whose layout cannot be resolved "kind:container", the universal-kind
   *   degradation of resolveAddress().
   */
  public function keyAddress(string $key, array $context = []): ?Address {
    $parsed = $this->parseKey($key);
    return $parsed === NULL ? NULL : $this->resolveAddress($parsed, $context);
  }

  /**
   * Captures the variant scope of a key: the element scope wear pairs at.
   *
   * An element key captures its own address (keyAddress()): save-as-
   * variant captures this from the source element and the variant entity
   * stores its print, and applying a variant requires the target's scope
   * to pair (scopeMatches()). A rule key ("{key}::{selector}") captures
   * its LANDING, the element its last compound names (the vocabulary
   * roster's landingScope(): a type compound's root address, a tag
   * compound's base tag), because a rule's wear is delivered at the
   * rule's targets, so that is what the variant must be scoped for: a
   * text variant wears at "container:X::> pb-text", never a container
   * variant. A rule whose targets are no element scope (a part, the
   * children, a subjectless compound, a generated box: "::&::before",
   * "::> pb-text::after") captures NULL: no variant pairs there, and
   * validateVariantReference() says so.
   *
   * @param string $key
   *   A style map key.
   * @param array $context
   *   The validate() context; "entity" enables block bundle and section
   *   layout resolution, "theme" the declarations the roster is built for.
   *
   * @return \Drupal\daedalus_blueprint\Materials\Address|null
   *   The scope, a root address; NULL when the key does not parse or its
   *   rule lands on no element scope.
   */
  public function captureScope(string $key, array $context = []): ?Address {
    $address = $this->keyAddress($key, $context);
    if ($address === NULL) {
      return NULL;
    }
    $theme = $context['theme'] ?? ($this->themeHandler->getDefault() ?: NULL);
    return $this->vocabulary->build($address, $theme)->landingScope();
  }

  /**
   * Whether a variant scope matches a key's captured scope.
   *
   * The pairing rule is the address's (Address::pairsWith(), the one
   * family rule the materials roster's applicability predicate, the
   * dialect catalog and the client twin share) against captureScope():
   * an element's own address, a rule's landing. A variant designed around
   * a layout's parts and slots is offered and applied layout-exact, the
   * block family's two refinements never pair with each other, and a kind
   * on EITHER side pairs with any address of its family.
   *
   * The kind wildcard serves both ways a kind arises. Declared:
   * "kind:container" variants style what every layout shares, so they
   * apply to any container. Degraded capture (no host in context,
   * or a placement/section not yet in its layout): degraded context must
   * not fail styles that resolved context would accept.
   *
   * Blueprint ownership and root-only depth
   * share the VALIDATION posture of scope: existing references always
   * validate, render and stay detachable, because a whole-map write cannot
   * tell a new reference from an existing one it must tolerate. The apply
   * GESTURE hard-gates them through the applicability seam
   * (VariantApplicabilityCheckerInterface): BlueprintVariantOwnershipChecker
   * in daedalus_blueprint_library for ownership, RootOnlyVariantChecker here
   * for depth (the roster record's applies.depth stamp, the one fact the
   * picker, the dialect and the op spine read). Curation only the picker
   * respects isn't curation, so the op spine backstops the offer filter.
   */
  public function scopeMatches(string $scope, string $key, array $context = []): bool {
    $variant = Address::parse($scope);
    $captured = $this->captureScope($key, $context);
    return $variant !== NULL && $captured !== NULL && $variant->pairsWith($captured);
  }

  /**
   * Validates an entry's "variants" reference list.
   *
   * The ONE list grammar for both entry lanes — style map entries
   * (validate()) and variant style entries (validateAt(), the
   * entry-shaped fragments) — so the reference shape, dedupe, and axis
   * rules can never fork. Only the '' fragment's per-reference check
   * differs per lane (an element scope-matches by key, a fragment by its
   * slot's scope), so the caller passes it in as $validate_root.
   *
   * The list is application order: later references beat earlier ones.
   * Every list item is a structured {"variant": name, "fragment": slot-key}
   * object, the ONE reference shape (no bare-string shorthand, no
   * normalization paths):
   * - a "" fragment wears the variant's ROOT: the variant must exist and
   *   its scope must match the carrier's own (the same per-reference check
   *   the single-slot grammar ran, now once per item);
   * - a non-empty fragment wears ONE fragment of a composition variant,
   *   bound onto the element when apply distributed the variant over its
   *   resolved subtree. The fragment is a pure lookup key into the variant
   *   (bind-once: never a position claim, and the element keeps it wherever
   *   it moves), so no scope match against the CARRYING element applies —
   *   see validateFragmentReference().
   *
   * On the map lane ($map_lane) a reference may carry a third key, "via":
   * the machine name of the composition variant whose apply distributed
   * the reference onto this element, its provenance. Apply stamps it,
   * same-axis replace and remove reap by it (so a switch un-applies what
   * the leaving composition put there and nothing the editor applied by
   * hand), detach releases it, and a direct apply never writes it (the
   * editor took the axis over). The grammar asks only that it be a
   * non-empty string: a "via" naming a deleted variant is inert, nothing
   * reaps by it and the wear reads as the element's own. The variant
   * styles lane rejects the key: provenance is a placement fact the map
   * records, and a nested reference inside a variant is what to
   * distribute, never who distributed it.
   *
   * Applicability is axis-aware: every variant lives on one axis, an
   * element wears at most one variant per axis, and composition happens
   * ACROSS axes — with ONE within-axis exception, the composition pair.
   * Within an axis, two references of the same KIND conflict (two roots,
   * or two fragments), but a ROOT ('' fragment) reference and a FRAGMENT
   * reference COMPOSE: apply distributes a fragment's nested root
   * references beside the fragment reference itself, and the fragment's
   * values (the captured overrides of the element it dressed) deliver over
   * the nested variant (orderedEntryRefs()). Fragment references still
   * occupy their variant's axis in the gesture rules — a direct same-axis
   * apply replaces BOTH halves of the pair (partial-wearing honesty is
   * enforced by placement, DaedalusStylishOperationHandler::placeReference()). The
   * single-slot behavior is the degenerate case of a scope whose only axis
   * is the built-in "style" axis.
   *
   * The variant styles lane additionally RESERVES the saved variant's own
   * axis at its root entry ($reserved_axis): apply places that variant's
   * own reference on the axis for every element that wears it, so a root
   * '' reference sharing the axis could never be worn — a save-time error
   * beats an apply-time loud skip.
   *
   * @param string $owner_at
   *   The carrying entry's error path: a selectable key in the map lane,
   *   an element-relative key ('' for the scope root) in the variant
   *   styles lane.
   * @param mixed $references
   *   The "variants" list as authored.
   * @param callable $validate_root
   *   fn (string $reference_at, string $reference): BlueprintError[] — the
   *   lane's '' fragment check (existence plus scope pairing).
   * @param string|null $reserved_axis
   *   The containing variant's own axis, when the list sits on a variant's
   *   ROOT entry (the variant styles lane passes it through
   *   validateAt()'s "variant_axis" context): a '' fragment
   *   reference on this axis is rejected. NULL everywhere else.
   * @param bool $map_lane
   *   TRUE on the map lane, where a reference may carry "via" (see
   *   above); FALSE on the variant styles lane, which rejects it.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at "{owner}.variants" (list shape), "{owner}.variants.{index}"
   *   (per reference), or "{owner}.variants.{index}.fragment" (fragment
   *   grammar) — "variants…" with no leading dot when the owner is ''.
   */
  protected function validateVariantList(string $owner_at, mixed $references, callable $validate_root, ?string $reserved_axis = NULL, bool $map_lane = FALSE): array {
    $at = $owner_at === '' ? 'variants' : "$owner_at.variants";
    if (!is_array($references) || !array_is_list($references) || $references === []) {
      return [
        new BlueprintError($at, 'INVALID_STYLE_ENTRY', sprintf('The variant references for "%s" are not a reference list.', $owner_at), [], 'The "variants" key holds a non-empty ordered list of {"variant": machine name (or uuid), "fragment": slot-key} objects (fragment "" wears the variant root), e.g. [{"variant": "hero", "fragment": ""}]; later references take precedence. Omit the key entirely when the element references no variants.'),
      ];
    }
    $errors = [];
    $seen = [];
    $axes = [];
    foreach ($references as $index => $reference) {
      $reference_at = "$at.$index";
      // The reference carries exactly its keys, one shape and no
      // shorthand, so consumers never normalize; only the map lane may
      // carry "via" (see the method docblock).
      if (!is_array($reference) || array_is_list($reference)
        || array_diff(array_keys($reference), $map_lane ? ['variant', 'fragment', 'via'] : ['variant', 'fragment']) !== []
        || !isset($reference['variant']) || !is_string($reference['variant']) || $reference['variant'] === ''
        || !isset($reference['fragment']) || !is_string($reference['fragment'])
        || (array_key_exists('via', $reference) && (!is_string($reference['via']) || $reference['via'] === ''))) {
        $errors[] = $map_lane
          ? new BlueprintError($reference_at, 'INVALID_STYLE_ENTRY', 'A variant reference is a {"variant", "fragment"} object, optionally carrying "via".', [], 'Provide {"variant": machine name (or uuid), "fragment": an element-relative slot key of the variant\'s scope, "" for its root}. Non-empty fragment references and "via" (the machine name of the composition variant whose apply distributed the reference onto this element) are written by applying a composition variant, never by hand.')
          : new BlueprintError($reference_at, 'INVALID_STYLE_ENTRY', 'A nested variant reference is a {"variant", "fragment"} object.', [], 'Provide exactly {"variant": machine name (or uuid), "fragment": an element-relative slot key of the variant\'s scope, "" for its root}. Non-empty fragment references are written by applying a composition variant, which distributes them onto the resolved elements. A nested reference never carries "via": provenance is a placement fact the style map records when this variant is applied, not part of what the variant distributes.');
        continue;
      }
      $fragment = $reference['fragment'];
      $reference = $reference['variant'];
      if (isset($seen[$reference])) {
        $errors[] = new BlueprintError($reference_at, 'INVALID_STYLE_ENTRY', sprintf('The "%s" variant is referenced more than once.', $reference), [], 'Each variant may appear at most once in an entry\'s list — an element wears at most one fragment of a variant.');
        continue;
      }
      $seen[$reference] = TRUE;
      // A root reference ("" fragment) must scope-match the carrier
      // wearing it; a non-empty fragment is a bound lookup key with no
      // scope claim on its carrier.
      $reference_errors = $fragment === ''
        ? $validate_root($reference_at, $reference)
        : $this->validateFragmentReference($reference_at, $reference, $fragment);
      if ($reference_errors !== []) {
        array_push($errors, ...$reference_errors);
        continue;
      }
      // The reference resolved; the axis is the remaining applicability
      // gate (the load rides the storage's static cache after the check
      // above resolved the same reference).
      $axis = $this->loadVariant($reference)->getAxis();
      if ($reserved_axis !== NULL && $fragment === '' && $axis === $reserved_axis) {
        $errors[] = new BlueprintError($reference_at, 'STYLE_VARIANT_AXIS_CONFLICT', sprintf('The "%s" reference is on the "%s" axis — the axis this variant itself occupies on every element that wears it.', $reference, $axis), ['axis' => $axis], 'Apply places this variant\'s own reference on that axis at its root, so a root-entry reference sharing it could never be worn. Nest the reference under a slot entry, use a variant on another axis, or copy its styles in as plain properties.');
        continue;
      }
      // Within an axis, same-KIND references conflict; a root and a
      // fragment reference are the composition pair and compose (see the
      // method docblock).
      $kind = $fragment === '' ? 'root' : 'fragment';
      if (isset($axes[$axis][$kind])) {
        $errors[] = new BlueprintError($reference_at, 'STYLE_VARIANT_AXIS_CONFLICT', sprintf('The "%s" and "%s" variants are both on the "%s" axis.', $axes[$axis][$kind], $reference, $axis), [
          'axis' => $axis,
          'conflicting_reference' => $axes[$axis][$kind],
        ], 'An element wears at most one variant per axis: to switch treatments within an axis, replace that axis\'s reference; compose variants across axes.');
        continue;
      }
      $axes[$axis][$kind] = $reference;
    }
    return $errors;
  }

  /**
   * Validates a fragment reference: the variant exists, the fragment parses.
   *
   * The fragment half of the distributed-reference model: applying a
   * composition variant resolves its slot keys to the concrete subtree
   * elements ONCE and writes a {"variant", "fragment"} reference onto each
   * resolved element's own entry. After that binding the fragment is a pure
   * lookup key into the variant's element-relative styles — never a
   * position claim — so validation checks exactly two things: the variant
   * exists, and the fragment parses against the variant scope's slot-key
   * root kind. Deliberately NOT checked:
   * - scope match against the carrying element: a moved element keeps its
   *   reference wherever it lives (refs are live links, not snapshots), so
   *   the carrier's own scope is no constraint;
   * - whether the variant still HOLDS styles at the fragment: a fragment
   *   the variant no longer carries is valid but inert — it delivers
   *   nothing until a push-update fills it again, and erroring would make
   *   editing a variant retroactively corrupt every map wearing it.
   *
   * @param string $at
   *   The error path of the reference ("{key}.variants.{index}").
   * @param string $reference
   *   The referenced variant's machine name or uuid, as stored.
   * @param string $fragment
   *   The fragment (element-relative slot key) the reference wears.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at that path (missing variant) or "{at}.fragment" (grammar).
   */
  protected function validateFragmentReference(string $at, string $reference, string $fragment): array {
    $variant = $this->loadVariant($reference);
    if ($variant === NULL) {
      return [
        new BlueprintError($at, 'STYLE_VARIANT_NOT_FOUND', sprintf('"%s" is not a saved style variant.', $reference), [], 'A fragment reference names the composition variant it was distributed from; that variant no longer exists (or the reference is misspelled). Apply an existing composition variant instead of writing fragment references by hand.'),
      ];
    }
    $scope = $variant->getScope();
    if (SlotKey::parse($fragment, SlotKey::rootKindForScope($scope)) === NULL) {
      $held = array_slice(array_map('strval', array_keys($variant->getStyles())), 0, 10);
      return [
        new BlueprintError("$at.fragment", 'INVALID_STYLE_FRAGMENT', sprintf('"%s" is not a fragment of the "%s" variant\'s scope ("%s").', $fragment, $reference, $scope), $held !== [] ? [
          'variant_scope' => $scope,
          'variant_fragments' => $held,
        ] : ['variant_scope' => $scope], 'A fragment is an element-relative slot key the variant\'s scope structurally contains: "" for the root, slash-separated container(index), block(type,index) and field(name) segments for a structural child, with an optional "::" relative selector suffix.' . ($held !== [] ? sprintf(' Fragments the variant currently holds: %s.', implode(', ', array_map(static fn (string $key): string => "\"$key\"", $held))) : '')),
      ];
    }
    return [];
  }

  /**
   * The element address a variant style entry's slot resolves to.
   *
   * The variant-lane counterpart of captureScope(): the slot addresses an
   * element, and the leading segments are only the route to it. The root
   * (and its parts) is the variant's own address; a path's last segment
   * resolves the way the same element resolves live, with the same
   * degradations: a container slot is positional and carries no layout
   * id (kind:container, the family wildcard, so any layout-refined
   * variant pairs and the universal container kind grants), a block slot
   * is an instance of its bundle (an undeclared bundle degrades to the
   * kind in the roster), a field slot the universal field kind. Part
   * suffixes ride their slot's address, exactly as sub-element keys
   * capture their parent's; a nested '' fragment reference pairs against
   * this address (slotLanding() for a selector'd slot) because apply
   * copies it onto the element the slot resolves to.
   */
  protected function slotScope(SlotKey $slot, Address $scope): Address {
    if ($slot->segments === []) {
      return $scope;
    }
    $segment = $slot->segments[array_key_last($slot->segments)];
    return match ($segment['type']) {
      'container' => Address::kind(Address::KIND_CONTAINER),
      'block' => Address::blockType($segment['name']),
      'field' => Address::kind('field'),
    };
  }

  /**
   * The element scope a variant style entry's nested references pair at.
   *
   * The slot's element (slotScope()) for an element entry; for a slot
   * carrying a selector, that RULE's landing under the slot's element
   * (captureScope()'s rule for a host key, through the vocabulary
   * roster: a type compound's root address, a tag's base tag, NULL for a
   * part, the children or a subjectless compound, where no variant
   * pairs). Apply places a rule entry's references at the same rule
   * under the new wearer, where they deliver at the rule's landing.
   *
   * @return \Drupal\daedalus_blueprint\Materials\Address|null
   *   The scope, NULL for a rule landing on no element scope.
   */
  protected function slotLanding(SlotKey $slot, Address $element, ?string $theme): ?Address {
    if ($slot->selector === NULL) {
      return $element;
    }
    return $this->vocabulary->build($element->withSelector($slot->selector), $theme)->landingScope();
  }

  /**
   * Validates a nested '' fragment reference in variant styles.
   *
   * The scope lane's $validate_root for validateVariantList(): the same
   * two checks the map lane runs (the variant exists, its scope pairs with
   * the carrier's), against the slot's element scope instead of a captured
   * key. Both are SAVE-time integrity gates — a reference failing here
   * would be dangling or mis-scoped on every element apply distributes it
   * to. A miss is named the way the map lane names it: a published
   * member of an inactive system, then a published variant the style
   * guide withholds, then the plain not-found. The importer and the save
   * door validate a variant's nested references through the JUDGED
   * roster, so a shipped variant nesting a withheld one meets the first
   * two here; only the judge's own run (the universe override, where
   * every offered published variant resolves) never does.
   *
   * @param string $at
   *   The error path of the reference ("{relative}.variants.{index}").
   * @param string $reference
   *   The referenced variant's machine name or uuid, as stored.
   * @param \Drupal\daedalus_blueprint\Materials\Address $carrier
   *   The address of the slot's element scope, the carrier.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at that path.
   */
  protected function validateNestedRootReference(string $at, string $reference, ?Address $carrier, string $relative = ''): array {
    $variant = $this->loadVariant($reference);
    if ($variant === NULL) {
      $available = $this->availableVariantsForScope($carrier);
      $applies = $carrier === NULL ? sprintf('this rule ("%s")', $relative) : sprintf('this slot\'s scope ("%s")', $carrier);
      if ($inactive = $this->inactiveMember($reference)) {
        return [$this->inactiveMemberError($at, $reference, $inactive, $available, $applies, 'reference one of the active system\'s, or put the properties in this entry directly.')];
      }
      if ($withheld = $this->withheldMember($reference)) {
        return [$this->nonconformingError($at, $reference, $withheld, $available, $applies, 'A nested reference must name an offered variant: fix the referenced variant\'s own styles (update_material with kind variant) so the site offers it again, reference one of the variants listed instead, or put the properties in this entry directly.')];
      }
      $lead = match (TRUE) {
        $carrier === NULL => sprintf('No variant applies at this rule ("%s"): it lands on no element scope. ', $relative),
        $available !== [] => sprintf('Variants among the site\'s materials that apply to this slot\'s scope ("%s"): %s. ', $carrier, MaterialsRoster::describe($available)),
        default => sprintf('No saved variants apply to this slot\'s scope ("%s"). ', $carrier),
      };
      return [
        new BlueprintError($at, 'STYLE_VARIANT_NOT_FOUND', sprintf('"%s" is not a saved style variant.', $reference), self::candidateContext($reference, $available), $lead . 'A nested reference must name an existing variant — save the referenced variant first, or put the properties in this entry directly.'),
      ];
    }
    $scope = $variant->getScope();
    if ($carrier === NULL) {
      return [
        new BlueprintError($at, 'STYLE_VARIANT_SCOPE_MISMATCH', sprintf('The "%s" variant is scoped to "%s", but "%s" lands on no element scope.', $reference, $scope, $relative), [
          'variant_scope' => $scope,
          'element_scope' => NULL,
        ] + self::candidateContext($reference, []), 'A rule entry wears a variant at its landing, the element its last compound names: a type ("::> pb-text" wears text variants) or a declared tag ("::a" wears link variants). A part ("::.class-name"), the children ("::> *") and a compound with no subject land on no element scope, so no variant pairs there: put the properties in this entry directly, or reference the variant from the rule entry that names the element.'),
      ];
    }
    if (!Address::fromString($scope)->pairsWith($carrier)) {
      $available = $this->availableVariantsForScope($carrier);
      $hint = 'A nested reference applies to the element its slot resolves to, so its variant must be scoped to that element\'s kind. '
        . ($available !== [] ? sprintf('Variants among the site\'s materials for this slot\'s scope: %s. ', MaterialsRoster::describe($available)) : '')
        . 'Reference a variant of the slot\'s scope, or copy the styles into this entry.';
      return [
        new BlueprintError($at, 'STYLE_VARIANT_SCOPE_MISMATCH', sprintf('The "%s" variant is scoped to "%s", but this entry\'s slot addresses "%s".', $reference, $scope, $carrier), [
          'variant_scope' => $scope,
          'element_scope' => (string) $carrier,
        ] + self::candidateContext($reference, $available), $hint),
      ];
    }
    return [];
  }

  /**
   * Checks the styles' transitive reference closure for cycles.
   *
   * The tree invariant behind entry-shaped fragments, enforced at SAVE and
   * never at render: variants may reference variants, but the reference
   * graph must stay acyclic — apply distributes nested references
   * recursively, so a cycle would never terminate. There is no second
   * guard at apply: the judge (VariantConformance) runs this same check
   * over every offered variant, so a cycle written around the save gate
   * withholds its variants from the roster, and apply only ever
   * distributes what the roster offers. A save can only close a cycle
   * through itself, so the walk
   * starts at the saved styles' references and reports reaching the saved
   * variant ($identity, when the caller knows it) or any back-edge among
   * saved variants (a pre-existing cycle the save would newly reach —
   * flagged here because render never checks). Dangling references inside
   * OTHER variants' stored styles are skipped silently: they are not this
   * save's data, and apply skips them loudly.
   *
   * @param array $styles
   *   The element-relative styles being saved.
   * @param array $identity
   *   The saved variant's own reference identities (machine name, uuid);
   *   empty for a variant that does not exist yet (then only reachable
   *   pre-existing cycles are detectable, and a self-reference by the
   *   to-be-minted machine name already failed as dangling).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   One error per first-level reference whose closure contains a cycle,
   *   at "{relative}.variants.{index}" ("variants.{index}" at the root).
   */
  protected function checkReferenceCycles(array $styles, array $identity): array {
    $identity = array_values(array_filter(array_map('strval', $identity), static fn (string $id): bool => $id !== ''));
    $errors = [];
    // Variants whose closure proved cycle-free this walk; a whole-styles
    // check shares one memo so diamond graphs stay linear.
    $clean = [];
    foreach ($styles as $relative => $entry) {
      $relative = (string) $relative;
      $at = $relative === '' ? 'variants' : "$relative.variants";
      foreach (static::entryRefs($entry) as $index => $ref) {
        $chain = $this->findReferenceCycle($ref['variant'], $identity, [], $clean);
        if ($chain !== NULL) {
          $errors[] = new BlueprintError("$at.$index", 'STYLE_VARIANT_REFERENCE_CYCLE', sprintf('The "%s" reference closes a variant reference cycle (%s).', $ref['variant'], implode(' -> ', $chain)), ['cycle' => $chain], 'Variant references must form a tree: a variant cannot reference itself, directly or through other variants. Remove the reference that closes the loop, or copy the referenced styles in as plain properties.');
        }
      }
    }
    return $errors;
  }

  /**
   * Walks one reference's closure depth-first, hunting a cycle.
   *
   * @param string $reference
   *   The reference to walk (machine name or uuid).
   * @param string[] $identity
   *   The saved variant's own identities; reaching one closes the cycle
   *   the save would create.
   * @param string[] $path
   *   Machine names on the current walk path, root-first.
   * @param array $clean
   *   Memo of machine names whose closure proved cycle-free (by
   *   reference).
   *
   * @return string[]|null
   *   The cycle as a machine-name chain ending at the repeated node
   *   (the saved variant's name for identity hits), or NULL when the
   *   closure is a tree.
   */
  protected function findReferenceCycle(string $reference, array $identity, array $path, array &$clean): ?array {
    $variant = $this->loadVariant($reference);
    if ($variant === NULL) {
      return NULL;
    }
    $name = $variant->getMachineName();
    if (in_array($name, $identity, TRUE) || in_array((string) $variant->uuid(), $identity, TRUE)) {
      return [...$path, $name];
    }
    if (in_array($name, $path, TRUE)) {
      return [...array_slice($path, (int) array_search($name, $path, TRUE)), $name];
    }
    if (isset($clean[$name])) {
      return NULL;
    }
    $path[] = $name;
    foreach ($variant->getStyles() as $entry) {
      foreach (static::entryRefs($entry) as $ref) {
        $chain = $this->findReferenceCycle($ref['variant'], $identity, $path, $clean);
        if ($chain !== NULL) {
          return $chain;
        }
      }
    }
    $clean[$name] = TRUE;
    return NULL;
  }

  /**
   * Validates a variant reference: the variant is offered, its scope matches.
   *
   * "Offered" is the roster's word (loadVariant()): a variant that exists
   * and is published but is not on the roster is refused by NAME of the
   * reason it left, an inactive design system (inactiveMember()) or the
   * style guide's verdict on its values (withheldMember()), before the
   * plain not-found case. At a rule key two more refusals stand before
   * scope: a composition variant (any slot path among its style keys:
   * STYLE_VARIANT_COMPOSITION_AT_RULE, a rule has no one subtree to bind)
   * and a landing that is no element scope (captureScope() NULL:
   * STYLE_VARIANT_SCOPE_MISMATCH naming the landing rule). Root-only
   * depth is the apply gesture's (RootOnlyVariantChecker), as on elements.
   *
   * @param string $key
   *   The style map key of the entry wearing the reference.
   * @param string $at
   *   The error path of the reference ("{key}.variants.{index}").
   * @param string $reference
   *   The referenced variant's machine name or uuid, as stored.
   * @param array $context
   *   The validation context, as validate().
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at that path.
   */
  protected function validateVariantReference(string $key, string $at, string $reference, array $context): array {
    $variant = $this->loadVariant($reference);
    if ($variant === NULL) {
      $available = $this->availableVariants($key, $context);
      if ($inactive = $this->inactiveMember($reference)) {
        return [$this->inactiveMemberError($at, $reference, $inactive, $available, 'this element', 'reference one of the active system\'s.')];
      }
      if ($withheld = $this->withheldMember($reference)) {
        return [$this->nonconformingError($at, $reference, $withheld, $available, 'this element', 'Detach the reference from this element (or set custom styles the guide accepts), wear one of the variants listed instead, or fix the variant\'s own styles (update_material with kind variant) so the site offers it again.')];
      }
      $lead = $available !== []
        ? sprintf('Variants among the site\'s materials that apply to this element: %s. ', MaterialsRoster::describe($available))
        : 'No saved variants apply to this element. ';
      return [
        new BlueprintError(
          $at,
          'STYLE_VARIANT_NOT_FOUND',
          sprintf('"%s" is not a saved style variant.', $reference),
          self::candidateContext($reference, $available),
          $lead . 'Reference a variant by its machine name (or uuid), or set custom styles instead. A NEW variant joins the site\'s materials only when the user asks for one; when asked, create it with the create-entity tool with entity_type daedalus_variant and bundle daedalus_variant (fields: label, machine_name, scope, axis, description, styles).',
        ),
      ];
    }
    $scope = $variant->getScope();
    $rule = $this->parseKey($key)['selector'] ?? NULL;
    if ($rule !== NULL && ($paths = self::slotPaths($variant->getStyles())) !== []) {
      // A composition binds ONE subtree by ordinal at apply time; a rule
      // has many matches and no subtree to bind, so its slot paths could
      // never resolve (distribution is refused by the KEYS, never tried).
      return [
        new BlueprintError($at, 'STYLE_VARIANT_COMPOSITION_AT_RULE', sprintf('The "%s" variant is a composition (it styles %s), and "%s" is a rule: a rule wears flat variants only.', $reference, self::listedPaths($paths), $key), [
          'variant_scope' => $scope,
          'slot_paths' => $paths,
        ], 'A composition variant resolves its slot paths against ONE element\'s subtree when it is applied, and a rule\'s matches are many. Wear it on the element itself (the key without "::"), or wear a flat variant (one whose styles hold only "" and "::…" entries) at the rule.'),
      ];
    }
    if (!$this->scopeMatches($scope, $key, $context)) {
      $captured = $this->captureScope($key, $context);
      $available = $this->availableVariantsForScope($captured);
      if ($rule !== NULL && $captured === NULL) {
        return [
          new BlueprintError($at, 'STYLE_VARIANT_SCOPE_MISMATCH', sprintf('The "%s" variant is scoped to "%s", but "%s" lands on no element scope.', $reference, $scope, $key), [
            'variant_scope' => $scope,
            'element_scope' => NULL,
          ] + self::candidateContext($reference, []), 'A rule wears a variant at its landing, the element its last compound names: a type ("::> pb-text" wears text variants) or a declared tag ("::a" wears link variants). A part ("::.class-name", "::> pb-text .link"), the children ("::> *"), a compound with no subject and a generated box ("::&::before", "::> pb-text::after") land on no element scope, so no variant pairs there: style that rule with properties instead, or wear the variant at the rule that names the element.'),
        ];
      }
      $hint = 'A variant applies only to elements of the scope it was saved from' . ($rule === NULL ? '. ' : ', and a rule wears one for its landing, the element its last compound names. ')
        . ($available !== [] ? sprintf('Variants among the site\'s materials that DO apply %s: %s. ', $rule === NULL ? 'to this element' : 'at this rule', MaterialsRoster::describe($available)) : '')
        . ($rule === NULL
          ? 'Set custom styles on this element instead, or give it and its siblings one treatment through a selector entry on their container (container:{uuid}::> pb-text styles every text block child at once, and wears their variants); a variant never crosses its scope.'
          : 'Set custom styles at this rule instead, or wear the variant at the rule whose landing is its scope; a variant never crosses its scope.');
      return [
        new BlueprintError($at, 'STYLE_VARIANT_SCOPE_MISMATCH', sprintf('The "%s" variant is scoped to "%s", but "%s" is "%s".', $reference, $scope, $key, $captured), [
          'variant_scope' => $scope,
          'element_scope' => $captured?->__toString(),
        ] + self::candidateContext($reference, $available), $hint),
      ];
    }
    return [];
  }

  /**
   * The slot paths among a variant's style keys: what makes it a composition.
   *
   * A style key is `{slot path}::{selector}` or `{slot path}` (SlotKey);
   * a non-empty path binds a subtree element by ordinal. The read is by
   * the keys alone, never by resolving them: the one fact that decides
   * whether the variant may be worn at a rule.
   *
   * @return string[]
   *   The distinct non-empty slot paths, in stored key order.
   */
  protected static function slotPaths(array $styles): array {
    $paths = [];
    foreach (array_keys($styles) as $relative) {
      $relative = (string) $relative;
      $path = ($pos = strpos($relative, Address::SUFFIX)) === FALSE ? $relative : substr($relative, 0, $pos);
      if ($path !== '') {
        $paths[$path] = TRUE;
      }
    }
    return array_keys($paths);
  }

  /**
   * Renders slot paths as prose: "block(text,1)", "block(text,1) and container(1)".
   *
   * @param string[] $paths
   *   The paths.
   */
  protected static function listedPaths(array $paths): string {
    $last = array_pop($paths);
    return $paths === [] ? $last : implode(', ', $paths) . ' and ' . $last;
  }

  /**
   * The structured variant references a style entry carries, in list order.
   *
   * The one read semantics for every consumer of an entry's "variants"
   * list: each well-formed item (a {"variant", "fragment"} object with a
   * non-empty variant and a string fragment, the ONE reference shape)
   * reads as ['variant' => reference, 'fragment' => slot key, 'via' =>
   * origin], the origin being the item's "via" when it carries a
   * non-empty one (the composition variant whose apply distributed the
   * reference; validateVariantList()) and NULL otherwise. Anything else
   * in the slot (the retired bare-string shorthand included) is ignored,
   * the same posture the emit gates take toward malformed data. The
   * origin rides the read so no consumer that rewrites a list can drop
   * it by accident; consumers that only wear, count or order ignore it,
   * and the captures strip it on purpose (a variant's nested list says
   * what to distribute, never who did).
   *
   * @return array[]
   *   ['variant' => string, 'fragment' => string, 'via' => string|null]
   *   per reference, [] when the entry carries none.
   */
  public static function entryRefs(mixed $entry): array {
    if (!is_array($entry) || !isset($entry['variants']) || !is_array($entry['variants'])) {
      return [];
    }
    $refs = [];
    foreach ($entry['variants'] as $reference) {
      if (is_array($reference)
        && isset($reference['variant']) && is_string($reference['variant']) && $reference['variant'] !== ''
        && isset($reference['fragment']) && is_string($reference['fragment'])) {
        $refs[] = [
          'variant' => $reference['variant'],
          'fragment' => $reference['fragment'],
          'via' => isset($reference['via']) && is_string($reference['via']) && $reference['via'] !== '' ? $reference['via'] : NULL,
        ];
      }
    }
    return $refs;
  }

  /**
   * The variants a style entry references, in application order.
   *
   * The reference-string projection of entryRefs(), for the consumers that
   * care WHICH variants an entry wears, not which fragment — usage
   * counting, group gestures, attachment checks, axis-ordered delivery
   * (orderedEntryVariants()). A fragment reference counts as wearing its
   * variant exactly like a root reference does.
   *
   * @return string[]
   *   The references, [] when the entry carries none.
   */
  public static function entryVariants(mixed $entry): array {
    return array_column(self::entryRefs($entry), 'variant');
  }

  /**
   * The structured references a style entry carries, in delivery order.
   *
   * Delivery emits variant classes and resolved variant CSS in
   * AXIS-DECLARATION order: the built-in "style" axis first, then the
   * variant scope's declared variant_axes in declaration order
   * (StyleGuide::axes()). The stored list keeps application order (the
   * order ops layered the references in); reordering only at the emit
   * points makes rendering deterministic regardless of the sequence the
   * user applied variants in. Every delivery consumer — class stamping,
   * edit-mode and workspace inline CSS, the compiled stylesheet — orders
   * through here or through the same vocabulary rule, so the cascade among
   * co-occurring variants can never depend on click order. A fragment
   * reference orders by ITS VARIANT's axis exactly like a root reference:
   * the fragment is a lookup key into the variant, never an ordering fact.
   *
   * WITHIN an axis the tiebreak is structural: root ('' fragment)
   * references deliver BEFORE fragment references, list order among
   * equals. The pair sharing an axis is the composition pair (see
   * validateVariantList()): the fragment's values are the captured
   * overrides of the element it dressed, layered over the variant nested
   * within it when they were captured — so they must keep delivering over
   * it, regardless of the order the references were stored in.
   *
   * References that cannot be placed on the vocabulary (unresolvable
   * variant, or an axis the scope no longer declares) sort after every
   * placed reference, keeping kind-then-list order among themselves —
   * stale references still deliver, deterministically. Entries carrying
   * fewer than two references never load anything, so ordinary
   * single-reference stamping stays load-free.
   *
   * @return array[]
   *   entryRefs() items (['variant' => string, 'fragment' => string,
   *   'via' => string|null]) in delivery order, [] when the entry carries
   *   none.
   */
  public function orderedEntryRefs(mixed $entry, ?string $theme = NULL): array {
    $refs = static::entryRefs($entry);
    if (count($refs) < 2) {
      return $refs;
    }
    $sortable = [];
    foreach ($refs as $index => $ref) {
      $position = PHP_INT_MAX;
      $variant = $this->loadVariant($ref['variant']);
      if ($variant !== NULL) {
        $axes = $this->scopeAxes($variant->getScope(), $theme);
        $axis_index = $axes === NULL ? FALSE : array_search($variant->getAxis(), $axes, TRUE);
        if ($axis_index !== FALSE) {
          $position = $axis_index;
        }
      }
      // Within an axis: roots before fragments (the composition pair);
      // the index tiebreak keeps the sort stable across PHP versions.
      $sortable[] = [$position, $ref['fragment'] === '' ? 0 : 1, $index, $ref];
    }
    usort($sortable, static fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
    return array_column($sortable, 3);
  }

  /**
   * The variant references a style entry carries, in delivery order.
   *
   * The reference-string projection of orderedEntryRefs(), for delivery
   * consumers that care WHICH variants deliver and in what order, not
   * which fragment.
   *
   * @return string[]
   *   The references, [] when the entry carries none.
   */
  public function orderedEntryVariants(mixed $entry, ?string $theme = NULL): array {
    return array_column($this->orderedEntryRefs($entry, $theme), 'variant');
  }

  /**
   * The variants whose scope pairs with an element's key.
   *
   * The corrective payload for STYLE_VARIANT_NOT_FOUND: not "similar
   * names" (a variant reference is usually invented, not misspelled) but
   * the legal set, what the writer COULD reference on this element. Read
   * from the materials roster, so the candidates are exactly the published
   * variants the catalog, the prompt and the schema advertise, in the
   * order they advertise them.
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   Up to CANDIDATE_LIMIT records in roster order (weight, then label).
   */
  protected function availableVariants(string $key, array $context): array {
    return $this->availableVariantsForScope($this->captureScope($key, $context));
  }

  /**
   * The variants whose scope pairs with an element scope.
   *
   * The scope-driven body under availableVariants(), shared with the
   * nested-reference corrective (validateNestedRootReference()), where the
   * element side is a slot's scope rather than a key. Pairing is the
   * address's family rule alone (see scopeMatches() for why depth and
   * ownership are gesture gates, not validation facts).
   *
   * @return \Drupal\daedalus_blueprint\Materials\MaterialRecord[]
   *   Up to CANDIDATE_LIMIT records in roster order (weight, then label).
   */
  protected function availableVariantsForScope(?Address $scope): array {
    if ($scope === NULL) {
      return [];
    }
    $available = [];
    foreach ($this->roster()->variants() as $record) {
      if ($record->scope !== NULL && $record->scope->pairsWith($scope)) {
        $available[] = $record;
        if (count($available) === self::CANDIDATE_LIMIT) {
          break;
        }
      }
    }
    return $available;
  }

  /**
   * The structured context of a variant corrective, the dialect door's shape.
   *
   * `reference` (as written) and `candidates` [{kind, id, spelling}]
   * (MaterialsRoster::candidateFacts(), the spelling being the id on this
   * door), always both, an empty list when nothing applies: the same
   * facts in the same keys on both doors, so a client or a model reads one
   * contract. The hint is the prose half (MaterialsRoster::describe()),
   * where label and description live.
   *
   * @param string $reference
   *   The variant reference as written.
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $records
   *   The candidates.
   *
   * @return array{reference: string, candidates: array}
   *   The context.
   */
  protected static function candidateContext(string $reference, array $records): array {
    return [
      'reference' => $reference,
      'candidates' => MaterialsRoster::candidateFacts($records),
    ];
  }

  /**
   * Loads a published variant by reference: machine name first, then uuid.
   *
   * Public because it IS the reference-resolution semantics: every consumer
   * of an entry's "variants" reference strings (delivery, the operation
   * handler's detach materialization, the variant gestures, the stack
   * projection) must resolve them exactly the way validation does.
   * Published only: the materials roster is the published set, and a
   * variant outside it is not a material, so it neither validates, nor
   * delivers, nor loads for a gesture; a variant an administrator
   * unpublished is managed on its own entity form until it is published
   * again. Resolution rides the request-lifetime identity map
   * (VariantRepository): delivery resolves references from the innermost
   * render loop, so per-call queries here priced a page render at
   * thousands of queries for a handful of variants; the roster membership
   * read is a memoized lookup on top.
   */
  public function loadVariant(string $reference): ?DaedalusStylishVariantInterface {
    $variant = $this->variantRepository->resolve($reference);
    if ($variant === NULL || !$this->roster()->has(MaterialKind::Variant, $variant->getMachineName())) {
      return NULL;
    }
    return $variant;
  }

  /**
   * Derives a parsed element key's address from its placement.
   *
   * The element lane's half of the one derivation: the root by key kind
   * (a block's refinement by bundle or base plugin, a container's layout,
   * else the universal kind; captureScope() is this root), then the
   * relative selector the key carries, riding the address so the roster
   * walk resolves it. Depth-blind by design: one layout, one address,
   * any placement. A container's grant is its layout's grant whether it
   * is a page band or nested inside one; placement curates offers and
   * birth (the section-created defaults, the root-only axes), never what
   * validates here.
   */
  protected function resolveAddress(array $parsed, array $context): Address {
    if ($parsed['kind'] === 'block') {
      $refinement = $this->resolveBlockRefinement($parsed['uuid'], $context);
      $root = $refinement !== NULL ? Address::of($refinement[0], $refinement[1]) : Address::kind(Address::KIND_BLOCK);
    }
    elseif ($parsed['kind'] === 'container') {
      $layout_id = $this->resolveSectionLayout($parsed['uuid'], $context);
      $root = $layout_id !== NULL ? Address::layout($layout_id) : Address::kind(Address::KIND_CONTAINER);
    }
    else {
      $root = Address::kind($parsed['kind']);
    }
    return $root->withSelector($parsed['selector']);
  }

  /**
   * The roster an entry validates against, or NULL when its place is not.
   *
   * The declared-place gates over the roster: a root address always
   * names a declared place (an undeclared refinement degrades to its
   * kind), a selector address only when every compound of the roster
   * walk landed on a declaration (VocabularyRoster::declared(); the
   * walk is VocabularyRosterBuilder's). The first compound that did not
   * (VocabularyRoster::undeclared()) decides the corrective:
   * - A tag compound ("{key}::p") is the TAG TIER: the element-scoped
   *   middle tier of the three-tier text model (skin base:{tag} -> this
   *   element's tag tier -> minted part), a live descendant tag rule
   *   catching future instances of the tag too. Its vocabulary is the
   *   site declaration's base section, the ONE declaration of a prose
   *   tag's styleability (the same tags a skin's defaults may set), and
   *   an undeclared tag is rejected in EVERY enforcement mode: the key is
   *   only grammar for declared base tags, never an open-ended tag
   *   selector lane.
   * - A class part resolves by the one part rule (an exact declaration
   *   anywhere in the landing owner's elements tree, then an owner
   *   PATTERN part covering the class namespace its prefix matches, then
   *   the site instance namespaces covering minted identity classes on
   *   ANY owner: carrier ownership is a DOM fact, so the vocabulary
   *   cannot live on one owner kind). Undeclared, it follows the grant
   *   posture: a strict site rejects the key naming the owner's declared
   *   parts, an open site accepts it grammar-checked only (the roster
   *   with no grant).
   * - The children ("> *") are declared like a part, as a `children`
   *   section beside the element's own property_groups (an elements tree
   *   declares markup shells; "all direct children" is a styling
   *   addressable with no shell of its own); undeclared, the part posture
   *   again.
   * - A type compound ("> pb-text") lands on that type's own declaration;
   *   a bundle no declaration covers is undeclared, the part posture
   *   again, the corrective naming the declared types.
   * - A terminal pseudo-element ("&::before", ".quote::after", "> pb-text
   *   ::before") is the generated box of the chain's landing. Existence
   *   is universal, so only its vocabulary is declared: the host's pseudo
   *   section names it, else the roster derives the host's grant plus
   *   the pseudo-only groups (the walk) and reports the box undeclared
   *   (VocabularyRoster::KIND_PSEUDO); the part posture again, the strict
   *   corrective (STYLE_PSEUDO_NOT_DECLARED) naming the section to write.
   *   An undeclared host is corrected first: the box is never reached.
   *
   * @param \Drupal\daedalus_blueprint\Materials\Address $address
   *   The entry's address.
   * @param string|null $theme
   *   The theme to validate against, or NULL for the default.
   * @param string $at
   *   The error path (the style map key, or the element-relative key).
   * @param bool $closed
   *   Whether the grant posture is closed (strict enforcement).
   * @param array &$errors
   *   Collects the rejection error when the place is undeclared.
   *
   * @return \Drupal\daedalus_stylish\Vocabulary\VocabularyRoster|null
   *   The roster (grant-less when the place is undeclared on an open
   *   site), or NULL when the entry was rejected (error appended).
   */
  protected function resolveRoster(Address $address, ?string $theme, string $at, bool $closed, array &$errors): ?VocabularyRoster {
    $roster = $this->vocabulary->build($address, $theme);
    $undeclared = $roster->undeclared();
    if ($undeclared === NULL) {
      return $roster;
    }
    $name = $undeclared['name'];
    if ($undeclared['kind'] === RelativeSelector::TAG) {
      $tags = $this->styleGuide->baseTags($theme);
      $hint = $tags === []
        ? 'A tag compound is the element-scoped tag tier over the site\'s declared base tags, and this site declares no base section, so no tag tier exists. To style a sub-element, use a declared part ("::.class-name").'
        : sprintf('A tag compound ("::p", "::> pb-text p") styles every one of the element\'s text elements of that tag (the element-scoped tag tier over the site\'s base vocabulary). Declared base tags: %s. To style ONE text element, use its minted identity class ("::.s-…").', implode(', ', $tags));
      $errors[] = new BlueprintError($at, 'STYLE_TAG_NOT_DECLARED', sprintf('"%s" is not a declared base tag.', $name), ['declared_tags' => $tags], $hint);
      return NULL;
    }
    if (!$closed) {
      // Undeclared part, children or type on an open site: stylable,
      // grammar-checked only.
      return $roster;
    }
    // The owner the corrective names: the entry's own key up to the
    // suffix (a live element key, or a variant's slot path), else the
    // address the root entry is worn at.
    $owner = strpos($at, Address::SUFFIX) > 0 ? substr($at, 0, strpos($at, Address::SUFFIX)) : (string) $address->root();
    switch ($undeclared['kind']) {
      case RelativeSelector::CLASS_PART:
        $declared = array_keys($roster->landingParts());
        $errors[] = new BlueprintError($at, 'STYLE_ELEMENT_NOT_DECLARED', sprintf('The style guide does not declare a "%s" sub-element on "%s".', $name, $owner), ['declared_elements' => $declared], $declared ? sprintf('Declared sub-elements: %s.', implode(', ', $declared)) : 'This element declares no stylable sub-elements.');
        return NULL;

      case RelativeSelector::TYPE:
        $declared = array_map(static fn (string $bundle): string => RelativeSelector::typeName(Address::blockType($bundle)), $this->styleGuide->getBlockTypes($theme));
        $errors[] = new BlueprintError($at, 'STYLE_TYPE_NOT_DECLARED', sprintf('"%s" is not a declared element type.', $name), ['declared_types' => $declared], sprintf('A type compound is spelled as the page language spells the element: %s, plus %s (any container) and %s (any other block).', $declared === [] ? 'no block type is declared' : implode(', ', $declared), RelativeSelector::TYPE_CONTAINER, RelativeSelector::TYPE_BLOCK));
        return NULL;

      case VocabularyRoster::KIND_PSEUDO:
        $host = $address->selector->compounds === [] ? $owner : RelativeSelector::printCompounds($address->selector->compounds);
        $errors[] = new BlueprintError($at, 'STYLE_PSEUDO_NOT_DECLARED', sprintf('The style guide does not declare the ::%s pseudo-element of "%s".', $name, $host), ['pseudo' => $name], sprintf('Every element has a ::before and an ::after box, but this site enforces its style guide, so a generated box may be styled only where its host\'s declaration carries a pseudo section naming what it may style: {pseudo: {%s: {property_groups: {…}}}} on the element, part or type the selector lands on. The box shows once "content" is set ("", none or one media reference url(\'media:<uuid>\'); text belongs in a field).', $name));
        return NULL;

      default:
        $errors[] = new BlueprintError($at, 'STYLE_CHILDREN_NOT_DECLARED', sprintf('The style guide does not declare the children ("> *") of "%s".', $owner), [], 'A "> *" compound styles an element\'s direct children as one addressable; the element\'s declaration must carry a children section (with the property groups it accepts) for it to be stylable here.');
        return NULL;
    }
  }

  /**
   * The instance part a selector carries, or NULL when it carries none.
   *
   * Identity classes (INSTANCE_PART) are minted per content instance and
   * never travel in variants or defaults, wherever they stand in the
   * selector (first, or after a type compound).
   */
  public static function instancePart(?RelativeSelector $selector): ?string {
    foreach ($selector?->classes() ?? [] as $class) {
      if (preg_match(self::INSTANCE_PART, $class)) {
        return $class;
      }
    }
    return NULL;
  }

  /**
   * Resolves a block placement uuid to its declaration-family refinement.
   *
   * The ONE connector from a live block placement back to the declaration
   * spine, both bundle-like block families: an inline placement refines by
   * its block_content bundle ('block_type'), any other placement by its
   * BASE plugin id ('block_plugin' — derivatives share one template, so
   * the :derivative suffix strips, mirroring the file grammar).
   *
   * Best-effort: needs the host in context and the placement already
   * in its layout (a whole-blueprint create processes fields before layout,
   * so brand-new placements resolve on the next write). Unresolvable
   * placements resolve to NULL and validate against the universal block
   * kind.
   *
   * @return array{0: string, 1: string}|null
   *   [family, id] where family is 'block_type' or 'block_plugin' (the
   *   address families, Address::FAMILY_*), or NULL when the placement
   *   cannot resolve.
   */
  protected function resolveBlockRefinement(string $uuid, array $context): ?array {
    $host = $context['host'] ?? NULL;
    if (!$host instanceof HostInterface) {
      return NULL;
    }
    $located = ($this->elementLocator)()->locate($host, $uuid);
    if ($located === NULL || !$located->isLbComponent()) {
      return NULL;
    }
    $plugin_id = $located->target->getPluginId();
    if (str_starts_with($plugin_id, 'inline_block:')) {
      return [Address::FAMILY_BLOCK_TYPE, substr($plugin_id, strlen('inline_block:'))];
    }
    [$base_id] = explode(':', $plugin_id, 2);
    return [Address::FAMILY_BLOCK_PLUGIN, $base_id];
  }

  /**
   * Resolves a block placement uuid to its inline block bundle.
   *
   * The block_type half of resolveBlockRefinement(), for consumers whose
   * vocabulary is inline bundles only (the composition slot grammar's
   * capture contract). Plugin placements resolve to NULL.
   */
  protected function resolveBlockBundle(string $uuid, array $context): ?string {
    $refinement = $this->resolveBlockRefinement($uuid, $context);
    return $refinement !== NULL && $refinement[0] === Address::FAMILY_BLOCK_TYPE ? $refinement[1] : NULL;
  }

  /**
   * Whether a parsed key names a container nested inside another section.
   *
   * The depth fact behind ownedPropertyWarnings(): positional, read from
   * the host in context through the locator
   * (ElementLocator::isNestedSection()). Only container keys have a
   * depth; any other kind, a missing host, or a section the locator
   * cannot place reads as root, so depth-blind callers keep the ungated
   * posture rather than inventing a depth.
   *
   * @param array $parsed
   *   The key as parseKey() returns it.
   * @param array $context
   *   The validate() context (the optional host).
   */
  protected function isNestedContainer(array $parsed, array $context): bool {
    if ($parsed['kind'] !== 'container') {
      return FALSE;
    }
    $host = $context['host'] ?? NULL;
    if (!$host instanceof HostInterface) {
      return FALSE;
    }
    return ($this->elementLocator)()->isNestedSection($host, $parsed['uuid']);
  }

  /**
   * Resolves a section uuid to its layout plugin id.
   *
   * The container counterpart of resolveBlockBundle(): the layout plugin id
   * is to containers what the bundle is to inline blocks. Best-effort with
   * the same limits — needs the host in context and the section
   * already in its layout; unresolvable containers validate against the
   * universal container kind.
   */
  protected function resolveSectionLayout(string $uuid, array $context): ?string {
    $host = $context['host'] ?? NULL;
    if (!$host instanceof HostInterface) {
      return NULL;
    }
    $located = ($this->elementLocator)()->locate($host, $uuid);
    if ($located === NULL || !$located->isSection()) {
      return NULL;
    }
    return $located->target->getLayoutId();
  }

  /**
   * Validates one property of a condition block against a roster.
   *
   * Recognition first (recognizeProperty(): a scalar value naming a
   * property some property group owns), then the grant: the roster of
   * the entry's address either grants the property or not
   * (VocabularyRoster::grantsProperty(), the declared groups with their
   * allowlists resolved), and a property outside the grant is undeclared
   * on this element, the grant posture exactly (STYLE_PROPERTY_NOT_DECLARED
   * on a strict site, grammar-only otherwise), never a differently-shaped
   * error. The corrective is the roster's own (VocabularyRoster::
   * candidates(), corrective(): the nearest granted names and the
   * roster's home), the same sentence the dialect parser answers a
   * style="" miss with; the roster itself rides the context only, never
   * the hint.
   *
   * Then the value (validateValue()). Authored constraints bind only while
   * they are law (StyleGuide::constraintsAreLaw(), guarded and strict):
   * open mode drops them before any check runs, so a recognized property
   * with a grammatical value always passes there and the constraints
   * survive only as offer-shaping for the client's stop rows. While they
   * are law, the effective constraint is the roster's value space for
   * the property (VocabularyRoster::valueSpace(): the node's block first,
   * else the site's authored constraint default, the guide's base layer,
   * binding wherever the property is granted); a property outside the
   * grant keeps its usual posture, never a default-bound one.
   *
   * @param string $at
   *   The error path of the condition block the property sits in.
   * @param string $property
   *   The property name.
   * @param mixed $value
   *   The authored value.
   * @param \Drupal\daedalus_stylish\Vocabulary\VocabularyRoster $roster
   *   The roster of the entry's address.
   * @param bool $closed
   *   Whether the grant posture is closed (strict enforcement).
   * @param string|null $theme
   *   The theme to validate against, or NULL for the default.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at "{at}.{property}".
   */
  protected function validateProperty(string $at, string $property, mixed $value, VocabularyRoster $roster, bool $closed, ?string $theme): array {
    $path = "$at.$property";
    $group_id = $this->recognizeProperty($path, $property, $value, $closed);
    if ($group_id instanceof BlueprintError) {
      return [$group_id];
    }
    // A pseudo-only group's property (content, the existence switch of a
    // generated box) means nothing on a real box: refused in every mode,
    // before the grant, since no roster of a real element grants it.
    if ($roster->address()->selector?->pseudo === NULL && $this->propertyGroups->isPseudoOnly($group_id)) {
      return [
        new BlueprintError($path, 'STYLE_PROPERTY_PSEUDO_ONLY', sprintf('"%s" applies to a generated box only, and "%s" is not a pseudo-element.', $property, $at), ['pseudo_elements' => RelativeSelector::PSEUDOS], sprintf('%s styles the ::before or ::after box of an element: address the box with a terminal pseudo-element, "{key}::&::before" for the element\'s own, "{key}::.part::after" for a part\'s, "{key}::> pb-text::before" for a rule\'s. The box shows once content is "" (none removes it, and one media reference url(\'media:<uuid>\') shows an image at its own size); text belongs in a field.', $property)),
      ];
    }
    $granted = $roster->grantsProperty($property);
    if (!$granted && $closed) {
      $legal = $roster->granted();
      return [
        new BlueprintError(
          $path,
          'STYLE_PROPERTY_NOT_DECLARED',
          sprintf('The style guide does not declare "%s" as stylable on this element.', $property),
          [
            'similar' => $roster->candidates($property),
            'declared_properties' => $legal,
            'declared_groups' => $roster->groups(),
            // The ~100-name roster is identical for every miss against the
            // same address; the batch channel carries it once per batch
            // and points later errors at it.
            BlueprintError::SHARED_HINT => [
              'key' => 'stylable_roster:' . hash('xxh64', serialize($legal)),
              'context_keys' => ['declared_properties', 'declared_groups'],
            ],
          ],
          $legal !== [] ? $roster->corrective($property) : 'This element declares no stylable properties.',
        ),
      ];
    }
    $constraints = $granted && $this->constraintsAreLaw() ? $roster->valueSpace($property) : [];
    return $this->validateValue($path, $group_id, $property, (string) $value, $constraints, $closed, $theme);
  }

  /**
   * Recognizes a property: a scalar value naming a property a group owns.
   *
   * The recognition half of validateProperty(), shared with the lanes
   * that validate outside any address (a keyframes block): the value must
   * be a scalar (INVALID_STYLE_VALUE) and the property a name some
   * property group owns (INVALID_STYLE_PROPERTY, with the closest
   * recognized names). Recognition is the universal vocabulary's, never a
   * roster's: an unrecognized name is not a grant miss.
   *
   * @return string|\Drupal\daedalus_blueprint\Result\BlueprintError
   *   The owning property group's id, or the error at $path.
   */
  protected function recognizeProperty(string $path, string $property, mixed $value, bool $closed): string|BlueprintError {
    if (!is_scalar($value)) {
      return new BlueprintError($path, 'INVALID_STYLE_VALUE', sprintf('The value of %s is not a CSS value.', $property), [], 'Provide the value as a string of semantic CSS, e.g. "2rem".');
    }
    $group_id = $this->propertyGroups->getGroupIdForProperty($property);
    if ($group_id === NULL) {
      $similar = $this->similarProperties($property);
      $hint = $closed ? 'This site enforces its style guide; only recognized, declared properties may be styled.' : 'Only recognized CSS properties may be styled; check the spelling.';
      if ($similar !== []) {
        $hint = sprintf('Did you mean %s? ', implode(', ', $similar)) . $hint;
      }
      return new BlueprintError($path, 'INVALID_STYLE_PROPERTY', sprintf('"%s" is not a recognized style property.', $property), $similar !== [] ? ['similar' => $similar] : [], $hint);
    }
    return $group_id;
  }

  /**
   * Validates a recognized property's value under its effective constraint.
   *
   * The value half of validateProperty(), the one value law for every
   * lane (the address lanes hand the roster's value space, the keyframes
   * lane the site's authored default). Two token-vocabulary checks ride
   * here beside the group's own validation:
   * - A scale-referencing constraint ({scale: space}) closes the value
   *   space to the scale's minted tokens as var() references, plus any
   *   allowed entries declared beside it (validateScaleValue()); the group
   *   validation is skipped exactly as an allowed match skips it — the
   *   closed list IS the value space.
   * - On strict sites, every var() reference that resolves to a declared
   *   scale's namespace must name a MINTED token on the active skin
   *   (checkTokenReferences()); permissive sites pass it through — an
   *   unresolved var is inert, never unsafe (the wide-tier philosophy), and
   *   the client lints it as a warning.
   *
   * @param string $path
   *   The error path of the property.
   * @param string $group_id
   *   The property group that owns the property (recognizeProperty()).
   * @param string $property
   *   The property name.
   * @param string $value
   *   The authored value.
   * @param array $constraints
   *   The effective constraint block, [] when none binds.
   * @param bool $closed
   *   Whether the grant posture is closed (strict enforcement).
   * @param string|null $theme
   *   The theme to validate against, or NULL for the default.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at $path.
   */
  protected function validateValue(string $path, string $group_id, string $property, string $value, array $constraints, bool $closed, ?string $theme): array {
    if (isset($constraints['scale'])) {
      return $this->validateScaleValue($path, $property, $value, $constraints, $theme);
    }
    $group = $this->propertyGroups->createInstance($group_id);
    $errors = array_map(
      static fn (BlueprintError $error) => $error->withPath($path),
      $group->validate($property, $value, $constraints),
    );
    array_push($errors, ...$this->checkTokenReferences($path, $value, $theme, $closed));
    array_push($errors, ...$this->checkAnimationReferences($path, $property, $value, $closed));
    return $errors;
  }

  /**
   * Validates a value against a scale-referencing constraint.
   *
   * The constraint's value space is closed: the scale's minted tokens as
   * var() references (StyleGuide::tokenVariable(), the one derivation
   * rule), plus any allowed entries declared beside the scale ({scale:
   * space, allowed: [auto]} admits the keyword too). Matching is verbatim,
   * like every allowed list, and a passing value is safe by construction —
   * every member is generated or guide-authored, so no group validation
   * follows. Minted tokens are DATA (the active skin), so the legal set
   * varies with the skin while the constraint stays code; with no active
   * skin (or none minted on the scale) the corrective says so instead of
   * presenting an inexplicably empty list. Reached only when constraints
   * are law: open mode demotes the scale closure to offer-shaping and
   * validateProperty() never routes here.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at the given path; empty when the value is on the scale.
   */
  protected function validateScaleValue(string $path, string $property, string $value, array $constraints, ?string $theme): array {
    $scale = (string) $constraints['scale'];
    $declaration = $this->styleGuide->getTokenScales($theme)[$scale] ?? NULL;
    $minted = $declaration === NULL ? [] : ($this->mintedTokens($theme)[$scale] ?? []);
    $allowed = [];
    $materials = [];
    foreach ($minted as $name => $token_value) {
      $reference = 'var(' . StyleGuide::tokenVariable($scale, $declaration, (string) $name) . ')';
      $allowed[] = $reference;
      $materials[] = $reference . ' = ' . MaterialsRoster::valueProse($token_value);
    }
    foreach (is_array($constraints['allowed'] ?? NULL) ? $constraints['allowed'] : [] as $extra) {
      $allowed[] = (string) $extra;
      $materials[] = (string) $extra;
    }
    if (in_array(trim($value), $allowed, TRUE)) {
      return [];
    }
    // The corrective shows what each material IS, not just its name: the
    // model patches toward the nearest value ("1rem" -> var(--space-4)),
    // so a names-only list would trade the authored intent for whatever
    // reference happens to satisfy the constraint.
    $hint = $allowed === []
      ? sprintf('The style guide constrains %s to the "%s" token scale, but no tokens are minted on it (no active skin, or the skin defines none) — mint tokens on the active skin first.', $property, $scale)
      : sprintf('The style guide constrains %s to the "%s" token scale; use one of these materials: %s.', $property, $scale, implode(', ', $materials));
    return [
      new BlueprintError($path, 'STYLE_VALUE_NOT_ON_SCALE', sprintf('"%s" is not a "%s" scale token reference for %s.', $value, $scale, $property), [
        'scale' => $scale,
        'allowed' => $allowed,
      ], $hint),
    ];
  }

  /**
   * Checks a value's var() references against the minted token vocabulary.
   *
   * The strict half of "warn permissive / error strict": a var() reference
   * that RESOLVES to a declared scale's namespace
   * (StyleGuide::tokenReference()) must name a token minted on the active
   * skin. Variables outside every declared prefix are not token references
   * — the token spine owns exactly the namespaces its scales declare, and
   * custom properties a theme legitimately defines cannot be enumerated —
   * so they pass on every site. Permissive sites pass unminted references
   * too (an unresolved var is inert, never unsafe; deleting a referenced
   * token is tolerate-with-warning by design) and the client lints them as
   * warnings; errors here would also make token deletion retroactively
   * corrupt every map referencing it.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at the given path; empty on permissive sites.
   */
  protected function checkTokenReferences(string $path, string $value, ?string $theme, bool $closed): array {
    // The declared-defaults lane checks tokens on every site: its universe
    // is empty by construction, so the check is what makes a declaration
    // token-free (validateDeclaredDefaults()).
    if (!($closed || $this->declaredLane) || !str_contains($value, 'var(')) {
      return [];
    }
    $scales = $this->styleGuide->getTokenScales($theme);
    $errors = [];
    $minted = $scales === [] ? [] : $this->mintedTokens($theme);
    preg_match_all('/var\(\s*(--[A-Za-z0-9_-]+)/', $value, $matches);
    foreach (array_unique($matches[1]) as $variable) {
      // The swatch namespace ("--swatch-") is reserved before any scale
      // speaks, so its check runs regardless of what scales the site
      // declares.
      $swatch_error = $this->checkSwatchReference($path, $variable);
      if ($swatch_error !== NULL) {
        $errors[] = $swatch_error;
        continue;
      }
      $reference = $scales === [] ? NULL : StyleGuide::tokenReference($variable, $scales);
      if ($reference === NULL) {
        continue;
      }
      $tokens = $minted[$reference['scale']] ?? [];
      if (!array_key_exists($reference['name'], $tokens)) {
        $names = array_map('strval', array_keys($tokens));
        $hint = $tokens === []
          ? sprintf('No tokens are minted on the "%s" scale (no active skin, or the skin defines none); mint the token on the active skin, or use a literal value.', $reference['scale'])
          : sprintf('Tokens minted on the "%s" scale, among the site\'s materials: %s. Mint the token on the active skin, or reference one of these.', $reference['scale'], implode(', ', $this->mintedMaterials($reference['scale'], $scales[$reference['scale']], $tokens)));
        $errors[] = new BlueprintError($path, 'STYLE_TOKEN_NOT_MINTED', sprintf('"%s" is in the "%s" scale\'s namespace, but no "%s" token is minted on the active skin.', $variable, $reference['scale'], $reference['name']), [
          'scale' => $reference['scale'],
          'minted' => $names,
        ], $hint);
      }
    }
    return $errors;
  }

  /**
   * Checks one variable against the published-swatch universe.
   *
   * The swatch counterpart of the minted-token check, sharing its severity
   * posture exactly: a var() reference inside the reserved swatch
   * namespace (StyleGuide::swatchReference()) must name a swatch among the
   * site's materials on strict sites (the roster is the published set, and
   * all published swatches are live, so it IS the legal universe), while
   * permissive sites pass it through (an unresolved var is inert, never
   * unsafe; the client lints it as a warning, and erroring would make
   * swatch deletion retroactively corrupt every value referencing it).
   * Callers gate on strictness; this only answers for one variable.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError|null
   *   The error, or NULL when the variable is outside the swatch namespace
   *   or names a published swatch.
   */
  protected function checkSwatchReference(string $path, string $variable): ?BlueprintError {
    $name = StyleGuide::swatchReference($variable);
    if ($name === NULL) {
      return NULL;
    }
    $swatches = $this->roster()->swatches();
    if (isset($swatches[$name])) {
      return NULL;
    }
    // Values ride the hint for the same reason the token correctives carry
    // them: the model patches from this hint alone, and only the values
    // let it pick the swatch that preserves the rejected value's intent.
    $materials = [];
    foreach ($swatches as $record) {
      $materials[] = $record->spelling(MaterialRecord::SLOT_STYLE) . ' = ' . MaterialsRoster::valueProse($record->value);
    }
    $hint = $materials === []
      ? 'No swatches are published; mint the swatch first, or use a literal value.'
      : sprintf('Published swatches, among the site\'s materials: %s. Mint the swatch first, or reference one of these.', implode(', ', $materials));
    return new BlueprintError($path, 'STYLE_SWATCH_NOT_PUBLISHED', sprintf('"%s" is in the swatch namespace, but no published "%s" swatch exists.', $variable, $name), ['published_swatches' => array_keys($swatches)], $hint);
  }

  /**
   * Checks a value's keyframes names against the published animations.
   *
   * The property-scoped reference check: KeyframesNames::inValue() finds
   * the names (only animation-name and the animation shorthand carry any;
   * the generic style slot never scans for a bare ident), and each must
   * spell a published animation. The swatch check's severity posture
   * exactly: strict sites error, permissive sites pass an unresolved name
   * through (inert, never unsafe; the client lints it as a warning, and
   * erroring would make animation deletion retroactively corrupt every
   * wearer). Two ways to fail strict: a motion-{name} ident naming no
   * published animation, and a plain ident, a theme's own keyframes that
   * the closed world does not curate (`font-family: Helvetica` fails
   * strict the same way).
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   Errors at the given path; empty on permissive sites.
   */
  protected function checkAnimationReferences(string $path, string $property, string $value, bool $closed): array {
    if (!$closed) {
      return [];
    }
    $errors = [];
    foreach (KeyframesNames::inValue($property, $value) as $name) {
      $error = $this->checkAnimationReference($path, $name);
      if ($error !== NULL) {
        $errors[] = $error;
      }
    }
    return $errors;
  }

  /**
   * Checks one keyframes name against the published-animation universe.
   *
   * The roster's animation records are the legal universe (all published
   * animations are live). The corrective lists them by spelling and
   * label: the model patches from the hint alone.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError|null
   *   The error, or NULL when the name spells a published animation.
   */
  protected function checkAnimationReference(string $path, string $name): ?BlueprintError {
    $animations = $this->roster()->animations();
    $machine_name = StyleGuide::animationReference($name);
    if ($machine_name !== NULL && isset($animations[$machine_name])) {
      return NULL;
    }
    $materials = [];
    foreach ($animations as $record) {
      $materials[] = sprintf('%s (%s)', $record->spelling(MaterialRecord::SLOT_ANIMATION), $record->label);
    }
    $hint = $materials === []
      ? 'No animations are published; mint the animation first, or set "none".'
      : sprintf('Published animations, among the site\'s materials: %s. Mint the animation first, or wear one of these.', implode(', ', $materials));
    $message = $machine_name === NULL
      ? sprintf('"%s" is not a site animation; this site enforces its style guide, so keyframes names must name published animations (motion-{name}).', $name)
      : sprintf('"%s" is in the motion namespace, but no published "%s" animation exists.', $name, $machine_name);
    return new BlueprintError($path, 'STYLE_ANIMATION_NOT_PUBLISHED', $message, ['published_animations' => array_keys($animations)], $hint);
  }

  /**
   * The minted token vocabulary: the roster's tokens per declared scale.
   *
   * The materials roster's token records (the active skin's tokens on the
   * scales the guide declares, read once per request by the provider)
   * regrouped as {scale: {name: value}} over the theme's declared scales,
   * memoized per roster: a whole-map validation checks many values against
   * one universe. Scales the theme does not declare are ignored even if
   * the roster carries them (the same posture the compiled stylesheet
   * takes: undeclared scales are inert). Values ride along because the
   * correctives list them: models patch after an error rather than
   * regenerate, so a hint that shows what each material IS
   * (var(--space-4) = 1rem) is the only chance to preserve the intent
   * behind the rejected value. Under withMintedUniverse() the caller's
   * universe answers instead.
   *
   * @return array<string, array<string, mixed>>
   *   Token values keyed by scale machine name then token name (a value is
   *   a string or a condition-keyed map, as stored); [] when no skin is
   *   active.
   */
  protected function mintedTokens(?string $theme): array {
    $key = $theme ?? '';
    $override = $this->universeOverrides[FiberFlow::id()] ?? NULL;
    if ($override !== NULL) {
      return $override[$key] ?? [];
    }
    $roster = $this->roster();
    if ($roster !== $this->universeRoster) {
      $this->universeRoster = $roster;
      $this->universes = [];
    }
    if (!isset($this->universes[$key])) {
      $scales = $this->styleGuide->getTokenScales($theme);
      $tokens = [];
      foreach ($roster->tokens() as $record) {
        if (isset($scales[$record->group])) {
          $tokens[$record->group][$record->label] = $record->value;
        }
      }
      $this->universes[$key] = $tokens;
    }
    return $this->universes[$key];
  }

  /**
   * The site's materials roster, built (or memoized) for this request.
   *
   * The builder memoizes per workspace and is reset by the material entity
   * hooks, so a gesture that mints a material and then validates a value
   * referencing it in the same request reads the fresh roster.
   */
  protected function roster(): MaterialsRoster {
    return $this->rosterOverrides[FiberFlow::id()] ?? $this->rosterBuilder->build();
  }

  /**
   * The design system a validation's context names, or NULL for global.
   */
  protected function memberOf(array $context): ?string {
    $design_system = $context['design_system'] ?? NULL;
    return is_string($design_system) && $design_system !== '' ? $design_system : NULL;
  }

  /**
   * The context for the nested run under withMemberUniverse().
   *
   * The membership is consumed (the run validates as global inside the
   * swapped universe) and the theme pinned, so the nested mintedTokens()
   * calls hit the seeded universe key.
   */
  protected static function memberless(array $context, ?string $theme): array {
    return ['theme' => $theme] + array_diff_key($context, ['design_system' => TRUE]);
  }

  /**
   * Runs a validation in a design system's universe.
   *
   * The member seam: the roster becomes the system's partition (its
   * members plus the global ones, MaterialsRosterBuilder::buildFor()) so
   * swatch, animation and variant references resolve to what the site
   * offers WITH that system active, and the minted tokens become its
   * default skin's (withMintedUniverse()), so var() references and scale
   * constraints resolve against the vocabulary the member speaks. A
   * system whose default skin does not exist (yet) mints nothing, the
   * same posture as a site with no active skin. A membership naming no
   * design system is the one error the seam answers itself: the lanes
   * resolve membership from things that exist, so this is an authoring
   * fault (a blueprint payload's design_system), corrected at its path.
   *
   * @param string $design_system
   *   The member's design system id.
   * @param string|null $theme
   *   The theme the nested validation resolves scales for.
   * @param callable $run
   *   The validation to run; returns its errors.
   *
   * @return \Drupal\daedalus_blueprint\Result\BlueprintError[]
   *   The run's errors, or the DESIGN_SYSTEM_UNKNOWN error.
   */
  protected function withMemberUniverse(string $design_system, ?string $theme, callable $run): array {
    $system = $this->entityTypeManager->getStorage(DesignSystem::ENTITY_TYPE_ID)->load($design_system);
    if (!$system instanceof DesignSystemInterface) {
      $known = array_keys($this->entityTypeManager->getStorage(DesignSystem::ENTITY_TYPE_ID)->loadMultiple());
      return [
        new BlueprintError('design_system', 'DESIGN_SYSTEM_UNKNOWN', sprintf('"%s" is not a design system on this site.', $design_system), ['design_systems' => $known], $known === [] ? 'The site has no design systems; omit design_system for a global material.' : sprintf('The site\'s design systems: %s. Name one of these, or omit design_system for a global material (offered under every design system).', implode(', ', $known))),
      ];
    }
    $skin = $this->skinManager->loadSkinFor($design_system);
    return $this->withUniverse(
      $this->rosterBuilder->buildFor($design_system),
      fn (): array => $this->withMintedUniverse($skin?->tokens() ?? [], $theme, $run),
    );
  }

  /**
   * Runs a validation with an explicitly supplied materials universe.
   *
   * The universe door: for the run's duration every material read
   * (roster()) answers from the given roster instead of the site's
   * (MaterialsRosterBuilder::build()), tokens included (mintedTokens()
   * regroups the roster's token records, so a universe carrying tokens
   * is the whole vocabulary). The member seam rides it
   * (withMemberUniverse(): a system's partition), and so does variant
   * conformance (VariantConformance: the provider's own unjudged records,
   * judged BEFORE the roster is built from them). That second caller is
   * why the door exists as a public method: the site's roster withholds
   * the variants the judge refuses, so the judge cannot read build()
   * without recursing into the roster it feeds, and a universe handed in
   * outright is the only read that terminates. Nested calls restore the
   * outer universe afterwards.
   *
   * @param \Drupal\daedalus_blueprint\Materials\MaterialsRoster $universe
   *   The roster the nested validation resolves materials against.
   * @param callable $run
   *   The validation to run under it.
   *
   * @return mixed
   *   Whatever $run returns.
   */
  public function withUniverse(MaterialsRoster $universe, callable $run): mixed {
    $flow = FiberFlow::id();
    $saved = $this->rosterOverrides[$flow] ?? NULL;
    $this->rosterOverrides[$flow] = $universe;
    try {
      return $run();
    }
    finally {
      if ($saved === NULL) {
        unset($this->rosterOverrides[$flow]);
      }
      else {
        $this->rosterOverrides[$flow] = $saved;
      }
    }
  }

  /**
   * A published member of an inactive design system a reference names.
   *
   * The worn-inactive case behind STYLE_VARIANT_NOT_FOUND: the variant
   * exists and is published, but its design system is not the active
   * one, so the roster does not offer it. Named as such
   * (inactiveMemberError()), because the fix is to wear an active
   * variant (switching systems is a person's act, never the model's),
   * not to mint one.
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishVariantInterface|null
   *   NULL unless the reference names exactly that case.
   */
  protected function inactiveMember(string $reference): ?DaedalusStylishVariantInterface {
    $variant = $this->variantRepository->resolve($reference);
    if ($variant === NULL || !$variant->isPublished() || $variant->getDesignSystem() === NULL || $this->roster()->has(MaterialKind::Variant, $variant->getMachineName())) {
      return NULL;
    }
    return $variant;
  }

  /**
   * The corrective for a reference to an inactive design system's variant.
   *
   * @param string $at
   *   The error path of the reference.
   * @param string $reference
   *   The reference as stored.
   * @param \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant
   *   The referenced variant.
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $available
   *   The active variants that apply to the element, for the hint.
   * @param string $applies
   *   What the alternatives apply to, as the lead's phrase ("this element"
   *   for a host map's wearing, the slot's scope for a nested reference).
   * @param string $remedy
   *   The path's own way around activating the system, closing the hint.
   */
  protected function inactiveMemberError(string $at, string $reference, DaedalusStylishVariantInterface $variant, array $available, string $applies, string $remedy): BlueprintError {
    $system = (string) $variant->getDesignSystem();
    // The system by its label (the name the brief and a person use), its
    // id in the context; an id the entity storage no longer knows prints
    // as itself.
    $label = $this->entityTypeManager->getStorage(DesignSystem::ENTITY_TYPE_ID)->load($system)?->label() ?? $system;
    $lead = $available !== []
      ? sprintf('Variants among the active design system\'s materials that apply to %s: %s. ', $applies, MaterialsRoster::describe($available))
      : '';
    return new BlueprintError(
      $at,
      'STYLE_VARIANT_DESIGN_SYSTEM_INACTIVE',
      sprintf('The "%s" variant belongs to the %s design system, which is not active.', $reference, $label),
      ['design_system' => $system] + self::candidateContext($reference, $available),
      $lead . sprintf('A design system\'s materials are offered only while it is active; wear a variant of the active system, or %s', $remedy),
    );
  }

  /**
   * A published variant the style guide's verdict withholds.
   *
   * The worn-nonconforming case behind STYLE_VARIANT_NOT_FOUND, the
   * inactive member's sibling: the variant exists, is published, and is
   * global or the active system's, but its values fail the site's
   * CURRENT law (VariantConformance::withheld()), so the roster does not
   * offer it. Named as such (nonconformingError()), because the fix is
   * to detach it, wear a conforming variant, or repair the variant, not
   * to mint one. Asked on both reference paths, a host map's wearing
   * (validateVariantReference()) and a variant's nested reference
   * (validateNestedRootReference(), the importer's and the save door's
   * lane, which resolves through the judged roster). Safe while the judge
   * runs: its universe (StyleMapValidator::withUniverse()) holds every
   * offered published variant, so roster()->has() answers first and the
   * verdict is never asked for; VariantConformance::withheld()'s
   * re-entrance guard is the trip-wire should that ever change.
   *
   * @return \Drupal\daedalus_stylish\DaedalusStylishVariantInterface|null
   *   NULL unless the reference names exactly that case.
   */
  protected function withheldMember(string $reference): ?DaedalusStylishVariantInterface {
    $variant = $this->variantRepository->resolve($reference);
    if ($variant === NULL || !$variant->isPublished() || $this->roster()->has(MaterialKind::Variant, $variant->getMachineName())) {
      return NULL;
    }
    return isset($this->variantConformance->withheld()[$variant->getMachineName()]) ? $variant : NULL;
  }

  /**
   * The corrective for a reference to a variant the style guide withholds.
   *
   * The verdict's own errors ride along, as the provenance the wearer's
   * validation never had before: the refused values are the VARIANT's,
   * and the message names each one at its path in the variant's styles
   * ("default.border-radius: "0.5em" is not a "radius" scale token
   * reference ..."), so a save-as-variant refusal downstream, or a model
   * patching after this error, can tell the worn material's values from
   * the element's own.
   *
   * @param string $at
   *   The error path of the reference.
   * @param string $reference
   *   The reference as written (machine name or uuid).
   * @param \Drupal\daedalus_stylish\DaedalusStylishVariantInterface $variant
   *   The withheld variant it names.
   * @param \Drupal\daedalus_blueprint\Materials\MaterialRecord[] $available
   *   The offered variants that apply to the element, for the hint.
   * @param string $applies
   *   What the alternatives apply to, as the lead's phrase ("this element"
   *   for a host map's wearing, the slot's scope for a nested reference).
   * @param string $remedy
   *   The path's own ways out (detach, wear another, repair the variant,
   *   inline the properties), closing the hint after the law sentence.
   */
  protected function nonconformingError(string $at, string $reference, DaedalusStylishVariantInterface $variant, array $available, string $applies, string $remedy): BlueprintError {
    $name = $variant->getMachineName();
    // The same triples the client guide's `withheld` payload carries
    // (VariantConformance::refusals()): one spelling of the reason. The
    // mode rides the context for the client; the prose never names it
    // (the brief's Law line is the one place it is spelled).
    $refused = $this->variantConformance->refusals()[$name];
    $lead = $available !== []
      ? sprintf('Variants among the site\'s materials that apply to %s: %s. ', $applies, MaterialsRoster::describe($available))
      : sprintf('No offered variant applies to %s. ', $applies);
    return new BlueprintError(
      $at,
      'STYLE_VARIANT_NONCONFORMING',
      sprintf('The "%s" variant does not conform to the site\'s style guide: %s.', $reference, implode('; ', array_map(static fn (array $refusal): string => $refusal['path'] . ': ' . rtrim($refusal['message'], '.'), $refused))),
      ['variant' => $name, 'enforcement' => $this->styleGuide->enforcement(), 'refused' => $refused] + self::candidateContext($reference, $available),
      $lead . sprintf('A variant is offered only while the save gate would accept it today: its values under the site\'s current law, and its nested references (existing, offered, scope-paired, acyclic, off its own axis at the root); "%s" is withheld until it does. ', $name) . $remedy,
    );
  }

  /**
   * Renders a scale's minted tokens as "var(--space-4) = 1rem" pairs.
   *
   * The corrective spelling of a token vocabulary: each legal reference
   * paired with the value it resolves to (the roster's, or the submitted
   * map's for token-to-token checks). Models patch after an error rather
   * than regenerate, and the corrective is the only context at repair
   * time; a names-only list makes the model satisfy the constraint with
   * whatever reference is nearest in NAME, losing the intent the rejected
   * value carried. The variable derives through StyleGuide::tokenVariable()
   * because a universe is a {name: value} map (the submitted map of a skin
   * under validation has no roster records), the one derivation the
   * roster's provider spells with too.
   *
   * @param string $scale
   *   The scale machine name.
   * @param array $declaration
   *   The scale's declaration (for the variable derivation).
   * @param array $tokens
   *   The scale's tokens ({name: value}).
   *
   * @return string[]
   *   One "var({variable}) = {value}" line per token, in minted order.
   */
  protected function mintedMaterials(string $scale, array $declaration, array $tokens): array {
    $materials = [];
    foreach ($tokens as $name => $value) {
      $materials[] = 'var(' . StyleGuide::tokenVariable($scale, $declaration, (string) $name) . ') = ' . MaterialsRoster::valueProse($value);
    }
    return $materials;
  }

  /**
   * Runs a validation with the minted-token universe swapped out.
   *
   * The self-containment seam behind validateDefaults()'s skin_tokens
   * context: the given token map (a skin's own, in the entity's stored
   * shape, records per name) is projected to its values
   * (SkinTokensItem::values()) and becomes the universe every strict
   * var() check inside $run resolves against, scoped to the declared
   * scales exactly like mintedTokens(). The roster is not consulted
   * meanwhile: a skin under
   * validation must reference only what IT mints, whatever the active skin
   * carries. The override is dropped afterwards so the request's other
   * validations keep seeing the site's materials.
   *
   * @param array $skin_tokens
   *   The owning skin's canonical token map ({scale: {name: record}}).
   * @param string|null $theme
   *   The theme the nested validation resolves scales for; must match what
   *   the nested calls pass to mintedTokens() or the seed misses.
   * @param callable $run
   *   The validation to run under the swapped universe.
   *
   * @return mixed
   *   Whatever $run returns.
   */
  protected function withMintedUniverse(array $skin_tokens, ?string $theme, callable $run): mixed {
    $flow = FiberFlow::id();
    $saved = $this->universeOverrides[$flow] ?? NULL;
    $universe = [];
    $values = SkinTokensItem::values($skin_tokens);
    foreach ($this->styleGuide->getTokenScales($theme) as $scale => $declaration) {
      if (isset($values[$scale])) {
        $universe[$scale] = $values[$scale];
      }
    }
    $this->universeOverrides[$flow] = [($theme ?? '') => $universe];
    try {
      return $run();
    }
    finally {
      if ($saved === NULL) {
        unset($this->universeOverrides[$flow]);
      }
      else {
        $this->universeOverrides[$flow] = $saved;
      }
    }
  }

  /**
   * Picks the recognized property names closest to an unrecognized one.
   *
   * The corrective payload for INVALID_STYLE_PROPERTY, cousin to the
   * reference handlers' similar-label suggestions: near-misses by edit
   * distance ("pading" -> padding) and containment either way
   * ("font-color" -> color), ranked closest first.
   *
   * @return string[]
   *   Up to five candidates.
   */
  protected function similarProperties(string $property): array {
    $needle = strtolower($property);
    $scored = [];
    foreach ($this->propertyGroups->getPropertyNames() as $candidate) {
      $distance = levenshtein($needle, $candidate);
      // Needle-contains-candidate only counts for candidates long enough to
      // mean something: the wide tier's vocabulary includes single-letter
      // SVG presentation properties (x, y, r, d), and "zzzx" does not mean
      // the writer was reaching for "x".
      if ($distance <= 3 || str_contains($candidate, $needle) || (strlen($candidate) >= 4 && str_contains($needle, $candidate))) {
        $scored[$candidate] = $distance;
      }
    }
    asort($scored);
    return array_slice(array_keys($scored), 0, 5);
  }

}
