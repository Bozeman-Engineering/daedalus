<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\SectionStorage;

use Drupal\layout_builder\Section;
use Drupal\layout_builder\SectionComponent;
use Drupal\layout_builder\SectionListInterface;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;

/**
 * A SectionListInterface over one nested-section container component.
 *
 * Under the sections-all-the-way-down model a nested section is an ordinary
 * SectionComponent (the daedalus_layout_section container) whose configuration embeds
 * exactly ONE Section::toArray() value. This list is the storage adapter that
 * lets ANY core-shaped Layout Builder form or op speak (section list, delta)
 * to that embedded value with no knowledge of the embedding:
 *
 * - getSection(0) is the embedded section. Hydration is memoized and
 *   identity-stable: core forms mutate the Section returned by
 *   getSection($delta) IN PLACE and never call a setter
 *   (ConfigureSectionForm, ConfigureBlockFormBase), so handing out a fresh
 *   Section::fromArray() per call would silently lose every submit.
 * - flush() writes the memoized section back into the container component's
 *   configuration via Section::toArray(). It is called UNCONDITIONALLY
 *   (no dirty flag) at the tempstore set() seam, deepest-first, by
 *   NestedAwareSectionStorage::bubbleChangesToRoot().
 * - Cardinality is structural: a container holds exactly one section.
 *   The list tolerates a transient second entry so the core replace pattern
 *   (insertSection($delta, $new) then removeSection($delta + 1)) works, but
 *   flushing with more than one section is a logic error.
 * - Removing the only section removes the container component itself: an
 *   empty container is not a state, it is the absence of the section.
 *   Inserting into an emptied list re-creates the container in place.
 *
 * The write channel is always SectionComponent::setConfiguration() with a
 * fresh toArray(); never a plugin instance (SectionComponent::getPlugin()
 * returns a fresh plugin per call, and BlockPluginTrait::setConfiguration()'s
 * mergeDeep can resurrect stale trees).
 */
class EmbeddedSectionList implements SectionListInterface {

  /**
   * The memoized hydrated sections, keyed 0..n.
   *
   * Normally exactly one entry; transiently two during a replace.
   *
   * @var \Drupal\layout_builder\Section[]
   */
  protected array $sections = [];

  /**
   * Whether the embedded section has been hydrated yet.
   */
  protected bool $hydrated = FALSE;

  /**
   * Whether the container component has been removed from its parent.
   */
  protected bool $containerRemoved = FALSE;

  /**
   * The region the container occupied, for re-creation after emptying.
   */
  protected string $region;

  /**
   * The weight the container carried, for re-creation after emptying.
   */
  protected int $weight;

  /**
   * Nesting depth (1 = container sits in a root section).
   *
   * Recorded by the tree-index walk; bubbleChangesToRoot() flushes
   * deepest-first so child writes land in parent section objects before the
   * parents themselves serialize.
   */
  protected int $depth = 1;

  public function __construct(
    protected Section $parentSection,
    protected SectionComponent $component,
  ) {
    $this->region = $component->getRegion();
    $this->weight = (int) $component->getWeight();
  }

  /**
   * Re-binds the list to the component's current location.
   *
   * Structural ops move the live component object between parent sections;
   * the tree-index walk calls this on every rebuild so removal/re-creation
   * always target the section the container actually sits in.
   */
  public function rebind(Section $parent_section, SectionComponent $component): void {
    $this->parentSection = $parent_section;
    $this->component = $component;
    $this->region = $component->getRegion();
    $this->weight = (int) $component->getWeight();
  }

  /**
   * The container component this list adapts.
   */
  public function getContainerComponent(): SectionComponent {
    return $this->component;
  }

  /**
   * The section this container sits in.
   */
  public function getParentSection(): Section {
    return $this->parentSection;
  }

  /**
   * Whether the container component has been removed from its parent.
   */
  public function isContainerRemoved(): bool {
    return $this->containerRemoved;
  }

  /**
   * Sets the recorded nesting depth.
   */
  public function setDepth(int $depth): void {
    $this->depth = $depth;
  }

  /**
   * Gets the recorded nesting depth.
   */
  public function getDepth(): int {
    return $this->depth;
  }

  /**
   * Hydrates the embedded section once, identity-stable.
   */
  protected function hydrate(): void {
    if ($this->hydrated) {
      return;
    }
    $this->hydrated = TRUE;
    if ($this->containerRemoved) {
      return;
    }
    $value = $this->component->get('configuration')[SectionBlock::SECTION_KEY] ?? NULL;
    if (!is_array($value) || $value === []) {
      throw new \LogicException(sprintf('The %s component "%s" holds no embedded section. A nested-section container always embeds exactly one section; an empty container is invalid state (removing a nested section means removing its container component).', SectionBlock::PLUGIN_ID, $this->component->getUuid()));
    }
    $this->sections = [Section::fromArray($value)];
  }

  /**
   * {@inheritdoc}
   */
  public function getSections() {
    $this->hydrate();
    return $this->sections;
  }

  /**
   * {@inheritdoc}
   */
  public function getSection($delta) {
    $this->hydrate();
    if (!isset($this->sections[$delta])) {
      throw new \OutOfBoundsException(sprintf('Invalid section delta "%s", there are %d sections.', $delta, count($this->sections)));
    }
    return $this->sections[$delta];
  }

  /**
   * Sets the section for the given delta.
   *
   * Mirrors \Drupal\layout_builder\SectionListTrait::setSection() so forms
   * and ops written against trait-backed lists work here unchanged.
   *
   * @return $this
   */
  public function setSection($delta, Section $section) {
    $this->hydrate();
    $this->sections[$delta] = $section;
    $this->setSectionsInternal($this->sections);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function count(): int {
    $this->hydrate();
    return count($this->sections);
  }

  /**
   * {@inheritdoc}
   */
  public function appendSection(Section $section) {
    $this->hydrate();
    $this->sections[] = $section;
    $this->setSectionsInternal($this->sections);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function insertSection($delta, Section $section) {
    $this->hydrate();
    if (isset($this->sections[$delta])) {
      $start = array_slice($this->sections, 0, $delta);
      $end = array_slice($this->sections, $delta);
      $this->setSectionsInternal(array_merge($start, [$section], $end));
    }
    else {
      $this->appendSection($section);
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * Removing the only section removes the container component itself.
   * Core's trait would seed a layout_builder_blank section here; an embedded
   * blank container is not a state this model has.
   */
  public function removeSection($delta) {
    $this->hydrate();
    if (!isset($this->sections[$delta])) {
      throw new \OutOfBoundsException(sprintf('Invalid section delta "%s", there are %d sections.', $delta, count($this->sections)));
    }
    unset($this->sections[$delta]);
    $this->setSectionsInternal($this->sections);
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * $set_blank is ignored: an emptied container is removed, not blanked.
   */
  public function removeAllSections($set_blank = FALSE) {
    $this->hydrate();
    $this->setSectionsInternal([]);
    return $this;
  }

  /**
   * Applies a new section set and syncs the container's existence.
   *
   * An emptied list detaches the container component from its parent; a
   * later insert re-creates it in place.
   *
   * @param \Drupal\layout_builder\Section[] $sections
   *   The new sections.
   */
  protected function setSectionsInternal(array $sections): void {
    $this->sections = array_values($sections);
    if ($this->sections === [] && !$this->containerRemoved) {
      // Capture placement before detaching so a subsequent insert re-creates
      // the container where it stood.
      $this->region = $this->component->getRegion();
      $this->weight = (int) $this->component->getWeight();
      $this->parentSection->removeComponent($this->component->getUuid());
      $this->containerRemoved = TRUE;
    }
    elseif ($this->sections !== [] && $this->containerRemoved) {
      $component = SectionBlock::createComponent($this->sections[0], $this->region, $this->weight);
      $this->parentSection->appendComponent($component);
      // appendComponent re-weights to the end of the region; restore the
      // captured placement.
      $component->setWeight($this->weight);
      $this->component = $component;
      $this->containerRemoved = FALSE;
    }
  }

  /**
   * Writes the memoized section back into the container's configuration.
   *
   * Unconditional by design: there is no dirty flag, because core forms
   * mutate the hydrated Section in place with no signal. Writing back what
   * was read is an idempotent no-op; not writing back a mutation is silent
   * data loss.
   */
  public function flush(): void {
    if ($this->containerRemoved || !$this->hydrated) {
      // Removed containers have nothing to write to; never-hydrated lists
      // handed out no Section object anyone could have mutated, so the
      // stored configuration is already canonical.
      return;
    }
    if (count($this->sections) > 1) {
      throw new \LogicException(sprintf('The nested-section container "%s" holds %d sections at flush time. A container embeds exactly ONE section; replace patterns must remove the displaced section before persisting.', $this->component->getUuid(), count($this->sections)));
    }
    if ($this->sections === []) {
      return;
    }

    $section = $this->sections[0];
    $uuid = $section->getThirdPartySetting('daedalus_layout', 'uuid');
    if (!$uuid) {
      $uuid = \Drupal::service('uuid')->generate();
      $section->setThirdPartySetting('daedalus_layout', 'uuid', $uuid);
    }

    $configuration = $this->component->get('configuration');
    $configuration[SectionBlock::SECTION_KEY] = $section->toArray();

    if ($uuid !== $this->component->getUuid()) {
      // The section was replaced with one carrying a different identity:
      // component uuid == section uuid is the one-identity invariant, so the
      // wrapper follows the section.
      $this->rekeyContainer($uuid, $configuration);
    }
    else {
      $this->component->setConfiguration($configuration);
    }
  }

  /**
   * Re-keys the container component to a new uuid, in place.
   *
   * Component uuids are constructor-fixed, so re-keying swaps a replacement
   * component into the parent section at the same region and weight.
   *
   * @param string $new_uuid
   *   The uuid to re-key to (the embedded section's identity).
   * @param array|null $configuration
   *   The configuration for the replacement, or NULL to carry the current
   *   configuration over unchanged.
   *
   * @return \Drupal\layout_builder\SectionComponent
   *   The replacement component (or the original when the parent no longer
   *   holds it, in which case no swap is possible).
   */
  public function rekeyContainer(string $new_uuid, ?array $configuration = NULL): SectionComponent {
    $old_uuid = $this->component->getUuid();
    try {
      $this->parentSection->getComponent($old_uuid);
    }
    catch (\InvalidArgumentException) {
      // The component moved out from under this binding; the next tree-index
      // rebuild re-binds and repairs.
      return $this->component;
    }

    $configuration ??= $this->component->get('configuration');
    $weight = (int) $this->component->getWeight();
    $replacement = new SectionComponent($new_uuid, $this->component->getRegion(), $configuration, $this->component->get('additional') ?? []);
    $this->parentSection->removeComponent($old_uuid);
    $this->parentSection->appendComponent($replacement);
    $replacement->setWeight($weight);
    $this->component = $replacement;
    return $replacement;
  }

}
