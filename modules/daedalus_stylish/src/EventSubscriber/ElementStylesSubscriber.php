<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ThemeHandlerInterface;
use Drupal\daedalus_blueprint\ElementLocator;
use Drupal\daedalus_blueprint\Event\ElementCreatedEvent;
use Drupal\daedalus_blueprint\Event\ElementDuplicatedEvent;
use Drupal\daedalus_blueprint\Event\ElementMovedEvent;
use Drupal\daedalus_blueprint\Event\LifecycleEventBase;
use Drupal\daedalus_blueprint\Event\SectionCreatedEvent;
use Drupal\daedalus_blueprint\Materials\MaterialKind;
use Drupal\daedalus_blueprint\Materials\MaterialsRosterBuilder;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\StyleMapItem;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\StyleMapValidator;
use Drupal\daedalus_stylish\DaedalusStylishFieldManager;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Carries styles through placement, section lifecycle, duplication and moves.
 *
 * Four jobs, all on the host entity's style map:
 * - DEFAULT VARIANTS ON PLACE: when a block type's style guide declaration
 *   names default_variants (a map of axis => variant machine name), an
 *   undressed add_element writes one variant REFERENCE per resolvable
 *   axis for the new block into the host map — the layered-entry
 *   lifecycle (references compose per axis, overrides layer on top,
 *   explicit detach/push) owns it from there. This is the styles half of
 *   the rule that a native block is layout block + content + styles.
 * - DEFAULT VARIANTS ON SECTION ADD: every undressed add_section writes
 *   the section layout's declared default_variants as references at
 *   container:{uuid} — at EVERY depth: a nested section is an ordinary
 *   section (position, not kind), its key stamps at its own root, and the
 *   house default is as right inside a section as it is on a page row.
 *   The depth-aware exceptions: axes the declaration qualifies
 *   {axis: {depth: root}} (StyleGuide::rootOnlyAxes()) are page-band
 *   business, so for NESTED sections they are skipped when defaulting on
 *   add; and a depth-qualified default entry ({variant: name, depth:
 *   root}, resolved by StyleGuide::defaultVariants()) skips nested adds
 *   the same way while its axis stays offered at every depth. An explicit
 *   apply_variant later in the same batch simply replaces the same-axis
 *   reference. A section is born with its REAL layout (the container
 *   ladder's pick, or an explicit layout on add_section) and keeps it for
 *   life, so birth is the one seam — there is no layout change and no
 *   re-evaluation.
 * - DUPLICATE CARRY: duplicate_element deep-clones with fresh UUIDs, and
 *   style map keys embed those UUIDs verbatim, so every entry keyed by
 *   a regenerated identity is copied to the rewritten key. Variant
 *   references and custom style blocks copy alike; the clone starts
 *   styled exactly like its source.
 * - ROOT-ONLY WEAR FOLLOWS DEPTH ON MOVE: a container's root-only wear
 *   (width on the shipped container: page-band business) is a fact of
 *   its depth, never a memory. move_element landing a section at nested
 *   depth strips every root reference whose roster record is root-only
 *   (MaterialRecord::rootOnly(), the one stamp the picker, the dialect and
 *   RootOnlyVariantChecker read); every-depth wear and the entry's own
 *   styles stay, and an entry the strip empties is dropped. A section
 *   coming out to root is redressed with its layout's root defaults on
 *   the axes it does not already wear (a band re-rooted comes back
 *   wearing the house width, never the width it had before it nested). A
 *   root-to-root move touches nothing: a reorder never restyles.
 *   Components wear no root-only axis, so their moves are ignored.
 *
 * THE POSTURE RULE: birth defaults are for UNDRESSED creations only. The
 * one fact behind birth is whether an element arrives dressed (its styles
 * come with it) or undressed (a blank drop from the palette, a flat
 * add_section/add_element from the AI or a script). The batch declares it
 * (BlueprintOperationsInterface::batchOperations()'s `dressed` option) and
 * every created event carries it (LifecycleEventBase::dressed()), so this
 * subscriber reads one fact and never guesses from the door: a stencil
 * expansion and a document dialect are total statements (the element
 * wears exactly what the definition or document says, and the style map
 * grammar is declarative: an absent reference means wears NONE), and
 * stamping defaults under them would make a deliberately stacked or
 * full-bleed section unauthorable; a blank op-door add starts with no
 * authored styles, so the house style is right whoever adds it. Sections
 * authored inside a create-entity blueprint never pass this seam at all
 * (BlueprintDeserializer dispatches no created event): the same total
 * statement by construction. The schema teaches the rule
 * (LayoutDefaultsSchemaHooks serializes each layout's declared defaults
 * with the stamp-on-add fact onto its available_layouts entry).
 *
 * Seam choice: the daedalus_blueprint lifecycle events. They are the
 * only placement seams that carry both the host entity and the identity
 * the map key needs — daedalus_layout's PlaceBlockEvent sees only the block
 * plugin, and the field handler never sees layout operations.
 * ElementCreatedEvent fires for every block add; `populate: 'sample'` on
 * an add means field sampling only and says nothing about styling.
 * Every map write here is declared as a target on the event (the root
 * entity's style map field): the mutation response re-renders only the
 * targets the result names, and the entity-level style tag that carries
 * the written CSS rides a whole-entity swap only, so an undeclared write
 * would land in the saved entity and reach the page on reload alone.
 *
 * A placement must never fail because of styling: every guard here skips
 * silently — a missing/unpublished/mis-scoped default variant degrades to
 * an unstyled placement, exactly what an author sees and fixes.
 */
class ElementStylesSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected StyleGuide $styleGuide,
    protected StyleMapValidator $validator,
    protected ThemeHandlerInterface $themeHandler,
    protected ElementLocator $elementLocator,
    protected MaterialsRosterBuilder $rosterBuilder,
  ) {}

  /**
   * Writes the block type's default variant references for a new placement.
   */
  public function onElementCreated(ElementCreatedEvent $event): void {
    // A dressed creation wears exactly what its batch declares.
    if ($event->dressed()) {
      return;
    }
    $entity = $event->getEntity();
    if (!$entity->hasField(DaedalusStylishFieldManager::FIELD_NAME)) {
      return;
    }
    // Only inline blocks have a block type to declare default variants.
    $configuration = $event->getComponent()->get('configuration');
    $id = $configuration['id'] ?? '';
    if (!is_string($id) || !str_starts_with($id, 'inline_block:')) {
      return;
    }
    $bundle = substr($id, strlen('inline_block:'));

    // The declaration theme matches validation/schema: the site default
    // theme, not the active (often admin) theme of the placing request.
    $theme = $this->themeHandler->getDefault() ?: NULL;
    $references = $this->defaultVariantReferences(
      $this->styleGuide->getBlockType($bundle, $theme),
      ["block_type:$bundle", 'kind:block'],
    );
    if ($references === []) {
      return;
    }

    $key = 'block:' . $event->getComponent()->getUuid();
    $map = $this->getStyleMap($entity);
    // Never override styles the placement already carries (explicit UUIDs
    // can re-place over existing entries).
    if ($this->hasEntryFor($map, $key)) {
      return;
    }
    $map[$key] = ['variants' => array_map([$this, 'rootReference'], array_values($references))];
    $this->setStyleMap($event, $map);
  }

  /**
   * Writes the layout's default variant references for a new page section.
   */
  public function onSectionCreated(SectionCreatedEvent $event): void {
    // A dressed creation wears exactly what its batch declares.
    if ($event->dressed()) {
      return;
    }
    $entity = $event->getEntity();
    if (!$entity->hasField(DaedalusStylishFieldManager::FIELD_NAME)) {
      return;
    }
    // Style map keys address sections by their daedalus_layout UUID; without one
    // the section is not styleable at all.
    $section_uuid = $event->getSectionUuid();
    if ($section_uuid === NULL) {
      return;
    }
    $references = $this->layoutDefaultVariants(
      $event->getSection()->getLayoutId(),
      $this->elementLocator->isNestedSection($entity, $section_uuid),
    );
    if ($references === []) {
      return;
    }

    $key = "container:$section_uuid";
    $map = $this->getStyleMap($entity);
    // Never override styles the section already carries (explicit UUIDs
    // can re-add over existing entries).
    if ($this->hasEntryFor($map, $key)) {
      return;
    }
    $map[$key] = ['variants' => array_map([$this, 'rootReference'], array_values($references))];
    $this->setStyleMap($event, $map);
  }

  /**
   * Copies style entries to a duplicated element's rewritten keys.
   */
  public function onElementDuplicated(ElementDuplicatedEvent $event): void {
    $entity = $event->getEntity();
    if (!$entity->hasField(DaedalusStylishFieldManager::FIELD_NAME)) {
      return;
    }
    $map = $this->getStyleMap($entity);
    if ($map === []) {
      return;
    }

    $uuid_map = $event->getUuidMap();
    $additions = [];
    foreach ($map as $key => $entry) {
      $rewritten = $this->rewriteKey((string) $key, $uuid_map);
      if ($rewritten !== NULL && !isset($map[$rewritten])) {
        // Variant references and custom style blocks copy alike — the
        // entry is placement-independent data; only the key names the
        // placement.
        $additions[$rewritten] = $entry;
      }
    }
    if ($additions) {
      $this->setStyleMap($event, $map + $additions);
    }
  }

  /**
   * Strips or redresses a moved section's root-only wear by its new depth.
   *
   * Sections only: blocks wear no root-only axis. The depth facts are the
   * event's (the operation knew them while it moved); the depth stamp is
   * the roster record's. Only ROOT references ("" fragment) are the
   * container's own axis wear and so the strip's and the redress's
   * business: a fragment reference is a composition an ancestor wears and
   * distributes, which the validator already keeps apart from root wear
   * on the same axis (a root and a fragment reference compose).
   */
  public function onElementMoved(ElementMovedEvent $event): void {
    if (!$event->isSection()) {
      return;
    }
    // Root to root: a reorder never restyles.
    if (!$event->wasNested() && !$event->isNested()) {
      return;
    }
    $entity = $event->getEntity();
    if (!$entity->hasField(DaedalusStylishFieldManager::FIELD_NAME)) {
      return;
    }
    $key = 'container:' . $event->getUuid();
    $map = $this->getStyleMap($entity);
    $map = $event->isNested()
      ? $this->stripRootOnlyWear($map, $key)
      : $this->redressAtRoot($map, $key, $entity, $event->getUuid());
    if ($map !== NULL) {
      $this->setStyleMap($event, $map);
    }
  }

  /**
   * Removes a nested container's root-only references from its entry.
   *
   * @return array|null
   *   The rewritten map, or NULL when nothing was stripped. An entry left
   *   with no reference and no styles is dropped rather than kept as an
   *   empty object; one keeping own styles or every-depth wear is
   *   rewritten in place.
   */
  protected function stripRootOnlyWear(array $map, string $key): ?array {
    if (!isset($map[$key]) || !is_array($map[$key])) {
      return NULL;
    }
    $entry = $map[$key];
    $references = $entry['variants'] ?? NULL;
    if (!is_array($references) || $references === []) {
      return NULL;
    }
    $roster = $this->rosterBuilder->build();
    $kept = [];
    foreach ($references as $reference) {
      $name = is_array($reference) ? ($reference['variant'] ?? NULL) : NULL;
      $fragment = is_array($reference) ? ($reference['fragment'] ?? NULL) : NULL;
      if (is_string($name) && $fragment === '') {
        $record = $roster->get(MaterialKind::Variant, $name);
        if ($record !== NULL && $record->rootOnly()) {
          continue;
        }
      }
      $kept[] = $reference;
    }
    if (count($kept) === count($references)) {
      return NULL;
    }
    if ($kept === []) {
      unset($entry['variants']);
    }
    else {
      $entry['variants'] = $kept;
    }
    if ($entry === []) {
      unset($map[$key]);
    }
    else {
      $map[$key] = $entry;
    }
    return $map;
  }

  /**
   * Stamps the layout's root defaults on the axes a re-rooted band lacks.
   *
   * @return array|null
   *   The rewritten map, or NULL when every defaulted axis was already
   *   worn (or the layout declares no usable default).
   */
  protected function redressAtRoot(array $map, string $key, FieldableEntityInterface $entity, string $uuid): ?array {
    $context = $this->elementLocator->locateSection($entity, $uuid);
    if ($context === NULL) {
      return NULL;
    }
    $defaults = $this->layoutDefaultVariants($context->section->getLayoutId());
    if ($defaults === []) {
      return NULL;
    }
    $entry = isset($map[$key]) && is_array($map[$key]) ? $map[$key] : [];
    $references = isset($entry['variants']) && is_array($entry['variants']) ? $entry['variants'] : [];
    $worn = [];
    foreach (StyleMapValidator::entryRefs($entry) as $reference) {
      if ($reference['fragment'] !== '') {
        continue;
      }
      $variant = $this->validator->loadVariant($reference['variant']);
      if ($variant !== NULL) {
        $worn[$variant->getAxis()] = TRUE;
      }
    }
    $additions = [];
    foreach ($defaults as $axis => $name) {
      if (!isset($worn[$axis])) {
        $additions[] = $this->rootReference($name);
      }
    }
    if ($additions === []) {
      return NULL;
    }
    $entry['variants'] = [...$references, ...$additions];
    $map[$key] = $entry;
    return $map;
  }

  /**
   * Resolves a layout's declared default variants to usable references.
   *
   * @param string $layout_id
   *   The section's layout plugin ID.
   * @param bool $nested
   *   Whether the section sits nested inside another section's region.
   *   Root-only axes ({axis: {depth: root}} in the declaration) resolve to
   *   no default at nested depth: a nested section is always constrained
   *   by its parent container, so page-band axes have nothing to default
   *   there. Depth-qualified default entries ({variant: name, depth:
   *   root}) skip nested depth the same way with their axis still offered.
   *
   * @return array<string, string>
   *   Axis => variant machine name (see defaultVariantReferences()); []
   *   when the layout declares none.
   */
  protected function layoutDefaultVariants(string $layout_id, bool $nested = FALSE): array {
    // The declaration theme matches validation/schema: the site default
    // theme, not the active (often admin) theme of the editing request.
    $theme = $this->themeHandler->getDefault() ?: NULL;
    $declaration = $this->styleGuide->getLayout($layout_id, $theme);
    return $this->defaultVariantReferences(
      $declaration,
      ["layout:$layout_id", 'kind:container'],
      $nested ? StyleGuide::rootOnlyAxes($declaration) : [],
      $nested,
    );
  }

  /**
   * Resolves a declaration's default_variants map to usable references.
   *
   * @param array $declaration
   *   The effective style guide declaration of the placement's scope.
   * @param string[] $scopes
   *   The scopes the target matches: its exact refinement and its family's
   *   kind wildcard.
   * @param string[] $skipped_axes
   *   Axes to resolve no default for regardless of what the declaration
   *   says: the caller's depth gate (root-only axes at nested depth).
   * @param bool $nested
   *   Whether the placement sits nested inside another section's region;
   *   depth-qualified default entries ({variant: name, depth: root})
   *   resolve to no default there.
   *
   * @return array<string, string>
   *   Axis => variant machine name, in axis-vocabulary order ("style"
   *   first, then variant_axes declaration order) so the written reference
   *   list is deterministic regardless of authored map order. Axes whose
   *   declared default is unusable are absent; an axis outside the
   *   vocabulary is skipped too (the guide build already failed loudly on
   *   it — placement never does).
   */
  protected function defaultVariantReferences(array $declaration, array $scopes, array $skipped_axes = [], bool $nested = FALSE): array {
    $declared = StyleGuide::defaultVariants($declaration, $nested);
    if ($declared === []) {
      return [];
    }
    $references = [];
    foreach (StyleGuide::axes($declaration) as $axis) {
      if (in_array($axis, $skipped_axes, TRUE)) {
        continue;
      }
      $reference = $this->validDefaultVariant($declared[$axis] ?? NULL, $scopes, $axis);
      if ($reference !== NULL) {
        $references[$axis] = $reference;
      }
    }
    return $references;
  }

  /**
   * Validates a declared default variant reference before it is written.
   *
   * The reference must be valid where it lands: the variant is a material
   * (loadVariant() answers for published variants only: an unpublished
   * default is not in the compiled stylesheet and would place
   * invisibly-unstyled), its scope is one the target matches
   * under the scope family rule, and its axis is the one it was declared
   * under (a mismatched axis would break the one-reference-per-axis
   * invariant the map validator enforces) — the same verdicts validation
   * reaches once the entry is in the map.
   *
   * @param mixed $reference
   *   The declared axis's default_variants value, if any.
   * @param string[] $scopes
   *   The scopes the target matches: its exact refinement and its family's
   *   kind wildcard.
   * @param string $axis
   *   The axis the reference was declared under.
   *
   * @return string|null
   *   The validated machine name, or NULL to skip silently.
   */
  protected function validDefaultVariant(mixed $reference, array $scopes, string $axis): ?string {
    if (!is_string($reference) || $reference === '') {
      return NULL;
    }
    $variant = $this->validator->loadVariant($reference);
    if ($variant === NULL || $variant->getAxis() !== $axis) {
      return NULL;
    }
    return in_array($variant->getScope(), $scopes, TRUE) ? $reference : NULL;
  }

  /**
   * The stored root-wearing reference for a variant: the one shape.
   *
   * @return array{variant: string, fragment: string}
   *   The {"variant", "fragment"} reference object, "" fragment (root).
   */
  protected function rootReference(string $reference): array {
    return ['variant' => $reference, 'fragment' => ''];
  }

  /**
   * Whether the map already holds an entry for a key or its sub-elements.
   */
  protected function hasEntryFor(array $map, string $key): bool {
    foreach (array_keys($map) as $existing) {
      if ($existing === $key || str_starts_with((string) $existing, "$key::")) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Rewrites a style map key against a UUID map.
   *
   * Keys embed exactly one UUID as their second colon-delimited segment
   * (entity:{uuid}, field:{uuid}:{name}, container:{uuid},
   * block:{uuid}, block:{uuid}:{field}), optionally
   * followed by a ::.class sub-element suffix.
   *
   * @return string|null
   *   The rewritten key, or NULL when the key's UUID was not regenerated.
   */
  protected function rewriteKey(string $key, array $uuid_map): ?string {
    $sub = '';
    if (($pos = strpos($key, '::')) !== FALSE) {
      $sub = substr($key, $pos);
      $key = substr($key, 0, $pos);
    }
    $parts = explode(':', $key);
    if (count($parts) < 2 || !isset($uuid_map[$parts[1]])) {
      return NULL;
    }
    $parts[1] = $uuid_map[$parts[1]];
    return implode(':', $parts) . $sub;
  }

  /**
   * Reads the entity's style map.
   */
  protected function getStyleMap(FieldableEntityInterface $entity): array {
    $item = $entity->get(DaedalusStylishFieldManager::FIELD_NAME)->first();
    return $item instanceof StyleMapItem ? $item->getStyleMap() : [];
  }

  /**
   * Writes the style map back onto the event's entity and declares it.
   *
   * Write and declaration are one call: the event's target is what makes
   * the mutation response re-render the entity-level style tag, so no
   * handler can write the map without declaring the change.
   */
  protected function setStyleMap(LifecycleEventBase $event, array $map): void {
    $entity = $event->getEntity();
    $items = $entity->get(DaedalusStylishFieldManager::FIELD_NAME);
    $item = $items->first() ?? $items->appendItem();
    $item->setStyleMap($map);
    $event->addTarget($entity->uuid(), DaedalusStylishFieldManager::FIELD_NAME);
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ElementCreatedEvent::class => ['onElementCreated'],
      SectionCreatedEvent::class => ['onSectionCreated'],
      ElementDuplicatedEvent::class => ['onElementDuplicated'],
      ElementMovedEvent::class => ['onElementMoved'],
    ];
  }

}
