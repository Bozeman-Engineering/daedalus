<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\Event\DialectCatalogBuildEvent;
use Drupal\daedalus_blueprint\Materials\Address;
use Drupal\daedalus_blueprint\Materials\RelativeSelector;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_stylish\ConditionTypePluginManager;
use Drupal\daedalus_stylish\Materials\DaedalusStylishMaterialsProvider;
use Drupal\daedalus_stylish\SkinManager;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\Vocabulary\VocabularyProse;
use Drupal\daedalus_stylish\Vocabulary\VocabularyRosterBuilder;
use Drupal\daedalus_stylish\Vocabulary\VocabularyValueSpaces;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Contributes daedalus_stylish's DECLARATIONS to the dialect catalog.
 *
 * The styling half of the site's page language that no material record carries,
 * from the same live sources every other styling surface reads. Daedalus
 * Stylish's materials (tokens, variants, animations, fonts) reach the catalog
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
 * - Conditions: the condition type plugins' name => [wrapper, axis]
 *   roster, in declaration order (the canonical compound order): the
 *   REFERENCES, names whose CSS the site owns. A literal clause the
 *   browser knows (a feature query, a supports test, a pseudo-class
 *   state) is never a roster entry: the digester reads it through the
 *   clause grammar as its own canonical CSS, and whether this site
 *   admits it beside the references is the validator's verdict by
 *   enforcement mode (StyleMapValidator::validateCondition()), taught
 *   by the prose's condition sentence, never by the table.
 * - Selector rosters per owner: the declared part classes of the
 *   container (the container layout's elements, the kind's beneath), of
 *   each declared block type (.text-block-link on <d-text>) and of any
 *   other block (the block kind's and every declared block plugin's,
 *   since <d-block> is the one spelling for all of them), each beside
 *   the base tags the site declares there (the declaration's base
 *   section). The roster is the DECLARED vocabulary, never the grammar:
 *   a selector is CSS's (a class, an attribute, a pseudo-class on any
 *   compound), and a tag, class, attribute or state the declaration
 *   does not name is admitted or refused by enforcement mode, the
 *   validator's verdict (StyleMapValidator::resolveRoster()).
 * - Instances: the spine's instance namespaces (identity classes and the
 *   declared tag vocabulary).
 * - Vocabulary rosters, in every posture: per element kind, the kind's
 *   vocabulary roster (VocabularyRosterBuilder, the one derivation of
 *   "what may this address style" and of the corrective for a miss)
 *   with the prose that teaches them (VocabularyProse), so the cached
 *   prompt teaches exactly the roster the validator judges against, in
 *   the words the schema uses. One roster per kind at every
 *   placement depth: a grant is a function of the declaration alone, so
 *   a nested container styles what a section styles. Beside the
 *   rosters ride their value spaces (VocabularyValueSpaces, the one
 *   derivation the JSON door's style_guide prints from): the site-wide
 *   bindings per property and the deviations per address, as data, so
 *   the brief can state the law its Law line tells the model to obey.
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
    protected VocabularyValueSpaces $valueSpaces,
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
   * Contributes axes, conditions, selectors, instances, rosters, birth, dress.
   */
  public function onCatalogBuild(DialectCatalogBuildEvent $event): void {
    $this->contributeAxes($event);
    $this->contributeConditions($event);
    $this->contributeSelectors($event);
    $this->contributeInstances($event);
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
   * element's tag speaks: a container's axes spell on <d-container>
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
    foreach ($skin->defaults() as $scope => $entries) {
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
   * The condition vocabulary: name => [wrapper, axis], in canonical order.
   *
   * The references. Every pseudo-class among them is a posable state
   * (position is selector grammar, never a condition), so the digester
   * behind the dialect's parser peels these off the addressed element
   * by name; a pseudo-class it does not publish peels as a LITERAL
   * state (":checked") and an at-rule prelude equal to none of the
   * wrappers reads as a literal clause, both admitted or refused by the
   * validator's mode, never by this table.
   */
  protected function contributeConditions(DialectCatalogBuildEvent $event): void {
    $conditions = [];
    foreach ($this->conditionTypeManager->getConditions() as $name => $definition) {
      $conditions[$name] = ['wrapper' => (string) $definition['wrapper'], 'axis' => (string) $definition['axis']];
    }
    $event->addConditions($conditions);
  }

  /**
   * The selector roster per owner: its declared parts and its tags.
   *
   * One owner per dialect spelling: <d-container> reads the container
   * layout's merged declaration (the kind's elements beneath the
   * layout's), each declared block type its own, and <d-block> (any
   * plugin block or undeclared type) the block kind's plus every
   * declared block plugin's, the union being what that one spelling may
   * name. Parts are the class-keyed elements at every depth of the
   * elements tree (flat, the map's own key grammar), labelled as the
   * roster labels them; pattern parts (".s-*") are the instance lane's.
   * The tags are the base section's, the same list on every owner: the
   * tag tier resolves to site.base.{tag} whoever owns the rule
   * (VocabularyRosterBuilder's walk), so the parser's corrective and
   * the validator's verdict stay one fact.
   */
  protected function contributeSelectors(DialectCatalogBuildEvent $event): void {
    $theme = $this->declarations->siteTheme();
    $tags = $this->styleGuide->baseTags($theme);
    $owners = [];
    $layout_id = $event->getContainerLayoutId();
    $owners[RelativeSelector::TYPE_CONTAINER] = [$layout_id === '' ? $this->styleGuide->getKind('container', $theme) : $this->styleGuide->getLayout($layout_id, $theme)];
    foreach (array_keys($event->getBlockTypes()) as $bundle) {
      $owners[DialectCatalog::tagForBlockType((string) $bundle)] = [$this->styleGuide->getBlockType((string) $bundle, $theme)];
    }
    $owners[RelativeSelector::TYPE_BLOCK] = [$this->styleGuide->getKind('block', $theme)];
    foreach ($this->styleGuide->getBlockPlugins($theme) as $plugin_id) {
      $owners[RelativeSelector::TYPE_BLOCK][] = $this->styleGuide->getBlockPlugin($plugin_id, $theme);
    }
    foreach ($owners as $owner => $declarations) {
      $parts = [];
      foreach ($declarations as $declaration) {
        foreach (PageBuilderDeclarations::flattenElements($declaration) as $selector => $part) {
          $selector = (string) $selector;
          if (preg_match(RelativeSelector::CLASS_PATTERN, $selector)) {
            $parts[$selector] ??= (string) ($part['label'] ?? $selector);
          }
        }
      }
      $event->addSelectors((string) $owner, ['parts' => $parts, 'tags' => $tags]);
    }
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
   * The vocabulary roster per element kind, the value spaces, the prose.
   *
   * Every posture contributes: the roster is the design system's
   * vocabulary whatever the site does outside it, and teaching it in
   * the cached catalog saves the model the miss-and-repair round trip
   * an untaught roster costs (models write the CSS they know, such as
   * the padding shorthand on a container that only grants the
   * longhands, repeatedly in one document). What varies with the
   * posture is one sentence, and the prose generator states it; a kind
   * whose roster names nothing teaches nothing. The value spaces are
   * the same law at the value level: the site-wide bindings and, over
   * the universal roots and every declared place under them (the set
   * the style map's style_guide covers), the deviations, derived once
   * by VocabularyValueSpaces so the brief and the JSON door print one
   * text.
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
    $rosters = $this->valueSpaces->rosters($this->valueSpaces->roots($theme), $theme);
    $event->addStylable(
      $stylable,
      VocabularyProse::of($this->styleGuide),
      $this->valueSpaces->bindings($theme),
      $this->valueSpaces->deviations($rosters, $theme),
    );
  }

}
