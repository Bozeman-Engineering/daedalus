<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Dialect\Event\DialectCatalogBuildEvent;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\ConditionTypePluginManager;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\daedalus_stylish\SkinManager;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\Vocabulary\VocabularyProse;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRosterBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Contributes daedalus_stylish's DECLARATIONS to the dialect catalog.
 *
 * The styling half of the site's page language that no material record
 * carries, from the same live sources every other styling surface reads.
 * Daedalus Stylish's materials (tokens, swatches, variants) reach the catalog
 * through the materials roster (DaedalusStylishMaterialsProvider), never here:
 * one published set, one spelling derivation, one did-you-mean.
 * - Axes per variant scope: the container kind's, the block kind's,
 *   each declared block type's and block plugin's variant_axes in
 *   declaration order, spelled as attributes by the provider's one rule
 *   (DaedalusStylishMaterialsProvider::axisAttr()), each with its description
 *   and the properties it owns (the two qualifiers StyleGuide validates
 *   at build; VocabularyRoster carries them to the client the same way).
 *   Which variants each axis offers and whether it is root-only are the
 *   variant records' facts.
 * - Birth defaults per placement scope: the container layout's and each
 *   declared block type's default_variants resolved for a top-level
 *   placement (StyleGuide::defaultVariants(), the same read the birth
 *   stamp makes), keyed by the axis attribute the element's tag speaks.
 * - The skin: the active published skin's label (the design system's
 *   default_skin, SkinManager::loadActiveSkin()), so the brief names it
 *   beside the system, and the dressed tags: the base tags its defaults
 *   dress (its base:{tag} scopes), so "pick the tag, the skin sizes it"
 *   is data.
 * - Conditions: the condition type plugins' name => wrapper roster, in
 *   declaration order (the canonical compound order).
 * - Parts: each declared block type's class-keyed elements
 *   (.text-block-link on <pb-text>).
 * - Instances: the spine's instance namespaces (identity classes and the
 *   declared tag vocabulary).
 * - Vocabulary rosters, in every posture: per element kind, the kind's
 *   vocabulary roster (VocabularyRosterBuilder, the one derivation of
 *   "what may this address style" and of the corrective for a miss)
 *   with the prose that teaches them (VocabularyProse), so the cached
 *   prompt teaches exactly the roster the validator judges against, in
 *   the words the schema uses. One roster per kind at every
 *   placement depth: a grant is a function of the declaration alone, so
 *   a nested container styles what a page band styles.
 *
 * Theme resolution is PageBuilderDeclarations::siteTheme(), the one the
 * materials provider, the schema and the validators resolve: the catalog
 * is the AI's vocabulary for the site's pages, whatever route builds it.
 */
class DialectVocabularySubscriber implements EventSubscriberInterface {

  /**
   * The element kinds the dialect addresses, in teaching order.
   *
   * 'block' first: it is typically the widest roster, so the prompt
   * renderer can print it in full and spell the others as deltas. The
   * 'entity' and 'region' kinds stay out: the dialect styles containers,
   * blocks, and their fields; the page root and regions are not dialect
   * styling surfaces.
   */
  protected const STYLABLE_KINDS = ['block', 'container', 'field'];

  public function __construct(
    protected ConditionTypePluginManager $conditionTypeManager,
    protected StyleGuide $styleGuide,
    protected VocabularyRosterBuilder $vocabularyRosters,
    protected PageBuilderDeclarations $declarations,
    protected SkinManager $skinManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      DialectCatalogBuildEvent::EVENT_NAME => 'onCatalogBuild',
    ];
  }

  /**
   * Contributes axes, conditions, parts, instances, rosters, birth and dress.
   */
  public function onCatalogBuild(DialectCatalogBuildEvent $event): void {
    $this->contributeAxes($event);
    $this->contributeConditions($event);
    $this->contributeParts($event);
    $this->contributeInstances($event);
    $this->contributeTextTags($event);
    $this->contributeStylable($event);
    $this->contributeBirthDefaults($event);
    $this->contributeDressedTags($event);
  }

  /**
   * Each tagged scope's axes in declaration order (style axis out), with facts.
   *
   * The scopes the provider spells attributes for: the two kinds, and the
   * block types and block plugins the declarations explicitly cover (an
   * undeclared type's effective declaration is the block kind's, whose
   * axes ride the kind:block scope). Each axis carries its description
   * and owned properties as the declaration qualifies them.
   */
  protected function contributeAxes(DialectCatalogBuildEvent $event): void {
    $theme = $this->declarations->siteTheme();
    $scopes = [
      [Address::kind(Address::KIND_CONTAINER), $this->styleGuide->getKind('container', $theme)],
      [Address::kind(Address::KIND_BLOCK), $this->styleGuide->getKind('block', $theme)],
    ];
    foreach ($this->styleGuide->getBlockTypes($theme) as $bundle) {
      $scopes[] = [Address::blockType($bundle), $this->styleGuide->getBlockType($bundle, $theme)];
    }
    foreach ($this->styleGuide->getBlockPlugins($theme) as $plugin_id) {
      $scopes[] = [Address::blockPlugin($plugin_id), $this->styleGuide->getBlockPlugin($plugin_id, $theme)];
    }
    foreach ($scopes as [$scope, $declaration]) {
      $axes = [];
      $descriptions = StyleGuide::axisDescriptions($declaration);
      $owns = StyleGuide::axisOwns($declaration);
      foreach (StyleGuide::axes($declaration) as $axis) {
        if ($axis !== StyleGuide::STYLE_AXIS) {
          $axes[DaedalusStylishMaterialsProvider::axisAttr($scope, $axis)] = [
            'description' => $descriptions[$axis] ?? NULL,
            'owns' => $owns[$axis] ?? [],
          ];
        }
      }
      if ($axes !== []) {
        $event->addAxes((string) $scope, $axes);
      }
    }
  }

  /**
   * The birth defaults of the container layout and each declared block type.
   *
   * The variants an op-created placement of the scope is born wearing at
   * the top level (StyleGuide::defaultVariants() over the effective
   * declaration, the kind's defaults layered under the refinement's, the
   * same read the birth stamp makes), keyed by the axis attribute the
   * element's tag speaks: a container's axes spell on <pb-container>
   * whichever layout places it, a block type's on its own tag.
   */
  protected function contributeBirthDefaults(DialectCatalogBuildEvent $event): void {
    $theme = $this->declarations->siteTheme();
    $scopes = [];
    if (($layout_id = $event->getContainerLayoutId()) !== '') {
      $scopes[] = [
        Address::layout($layout_id),
        Address::kind(Address::KIND_CONTAINER),
        $this->styleGuide->getLayout($layout_id, $theme),
      ];
    }
    foreach ($this->styleGuide->getBlockTypes($theme) as $bundle) {
      $scopes[] = [
        Address::blockType($bundle),
        Address::blockType($bundle),
        $this->styleGuide->getBlockType($bundle, $theme),
      ];
    }
    foreach ($scopes as [$scope, $spelling_scope, $declaration]) {
      $defaults = [];
      foreach (StyleGuide::defaultVariants($declaration) as $axis => $variant) {
        $defaults[DaedalusStylishMaterialsProvider::axisAttr($spelling_scope, (string) $axis)] = $variant;
      }
      if ($defaults !== []) {
        $event->addBirthDefaults((string) $scope, $defaults);
      }
    }
  }

  /**
   * The active published skin's label and the base tags its defaults dress.
   *
   * The label is what the brief names the skin by; the base:{tag}
   * default scopes, in the skin's authored order, are what lets a
   * document pick a heading tag and leave its size to the skin. No
   * active published skin contributes nothing; one dressing no tag
   * contributes its label alone.
   */
  protected function contributeDressedTags(DialectCatalogBuildEvent $event): void {
    $skin = $this->skinManager->loadActiveSkin();
    if ($skin === NULL || !$skin->isPublished()) {
      return;
    }
    $event->setSkin((string) $skin->label());
    $tags = [];
    foreach ($skin->getDefaults() as $scope => $entries) {
      $address = Address::parse((string) $scope);
      if ($address?->isBase() && is_array($entries) && $entries !== []) {
        $tags[] = $address->id;
      }
    }
    if ($tags !== []) {
      $event->addDressedTags($tags);
    }
  }

  /**
   * The condition vocabulary: name => wrapper, in canonical order.
   */
  protected function contributeConditions(DialectCatalogBuildEvent $event): void {
    $conditions = [];
    foreach ($this->conditionTypeManager->getConditions() as $name => $definition) {
      $conditions[$name] = (string) $definition['wrapper'];
    }
    $event->addConditions($conditions);
  }

  /**
   * Declared part classes: each block type's class-keyed elements.
   */
  protected function contributeParts(DialectCatalogBuildEvent $event): void {
    $parts = [];
    $theme = $this->declarations->siteTheme();
    foreach (array_keys($event->getBlockTypes()) as $bundle) {
      $tag = 'pb-' . str_replace('_', '-', $bundle);
      foreach (array_keys($this->styleGuide->getBlockType($bundle, $theme)['elements'] ?? []) as $element_key) {
        if (str_starts_with((string) $element_key, '.')) {
          $parts[substr((string) $element_key, 1)][] = $tag;
        }
      }
    }
    $event->addParts($parts);
  }

  /**
   * The spine's instance namespaces: identity classes and minted tags.
   *
   * The dialect is the third consumer of the one resolution rule (exact
   * part -> owner pattern part -> site instance namespaces), so its
   * catalog reads the SAME `instances:` lane every other resolver reads
   * (StyleMapValidator, the client style guide, the minter's vocabulary,
   * the annotator's labels): label and tags only, and the tags are
   * IDENTITY (what the minter mints), not styleability; the styling
   * sections (property_groups, controls) stay the guide's, judged at
   * write time.
   */
  protected function contributeInstances(DialectCatalogBuildEvent $event): void {
    $instances = [];
    foreach ($this->styleGuide->getInstances($this->declarations->siteTheme()) as $pattern => $declaration) {
      if (!is_array($declaration)) {
        continue;
      }
      $tags = is_array($declaration['tags'] ?? NULL) ? $declaration['tags'] : [];
      $instances[(string) $pattern] = [
        'label' => (string) ($declaration['label'] ?? $pattern),
        'tags' => array_map(strval(...), $tags),
      ];
    }
    if ($instances !== []) {
      $event->addInstances($instances);
    }
  }

  /**
   * The addressable text-tag vocabulary: the site's declared base tags.
   *
   * The tag tier's vocabulary ("#id p" in the dialect, "{key}::p" on the
   * map) is the site declaration's base section, the one declaration of
   * a prose tag's styleability, the same read the validator resolves the
   * tier through (StyleGuide::baseTagElement()); teaching the catalog the
   * same list keeps the parser's corrective and the validator's verdict
   * one fact.
   */
  protected function contributeTextTags(DialectCatalogBuildEvent $event): void {
    $tags = $this->styleGuide->baseTags($this->declarations->siteTheme());
    if ($tags !== []) {
      $event->addTextTags($tags);
    }
  }

  /**
   * The vocabulary roster per element kind, with its prose.
   *
   * Every posture contributes: the roster is the design system's
   * vocabulary whatever the site does outside it, and teaching it in
   * the cached catalog saves the model the miss-and-repair round trip
   * an untaught roster costs (models write the CSS they know, such as
   * the padding shorthand on a container that only grants the
   * longhands, repeatedly in one document). What varies with the
   * posture is one sentence, and the prose generator states it; a kind
   * whose roster names nothing teaches nothing.
   */
  protected function contributeStylable(DialectCatalogBuildEvent $event): void {
    $stylable = [];
    $theme = $this->declarations->siteTheme();
    foreach (self::STYLABLE_KINDS as $kind) {
      $roster = $this->vocabularyRosters->build(Address::kind($kind), $theme);
      if ($roster->granted() === []) {
        continue;
      }
      $stylable[$kind] = $roster;
    }
    $event->addStylable($stylable, VocabularyProse::of($this->styleGuide));
  }

}
