<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout;

use Drupal\daedalus_blueprint\Dialect\DialectCatalog;
use Drupal\daedalus_blueprint\Dialect\Event\DialectCatalogBuildEvent;
use Drupal\daedalus_ui\ElementModel\DeclarationException;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * The site's group vocabulary: the site declaration's groups, contributed.
 *
 * A group is the site's word for what a block is for: blueprints and
 * block types are filed under groups on the surfaces that offer them
 * (the Add panel, the page chooser, the model's index), and a group is a
 * filter category and nothing else. A developer declares the groups in
 * the `PROVIDER.page_builder*.yml` family under the reserved `site` key,
 * the way variant axes are declared: a `groups` map of group id to
 * `label` and optional `description`, layered across modules and themes
 * like every site section (a higher layer refines an entry, NULL
 * withdraws it).
 *
 * The vocabulary is the spine's surface: it rides the dialect catalog
 * beside the variant axes (DialectCatalog::groups()), and every consumer
 * reads it there. The spine cannot read the declaration family itself
 * (daedalus_ui parses it and depends on the spine), so this class is the
 * contributor: it parses and validates the section and adds it on
 * DialectCatalogBuildEvent for the site theme, the one the materials
 * provider, the schema and the validators resolve. Nothing reads this
 * service for an answer.
 *
 * daedalus_ui owns the `site` lane and validates nothing inside it
 * (every section there is foreign); this reader is the section's owner
 * and validates it as authored, loudly, so a misspelled declaration
 * fails the catalog build instead of silently filtering nothing. The id
 * DialectCatalog::UNGROUPED is the reserved bucket for the things in no
 * group and is refused as a declared id.
 */
final class SiteGroups implements EventSubscriberInterface {

  /**
   * The site declaration section the vocabulary rides.
   */
  public const SECTION = 'groups';

  /**
   * The keys a group declaration may carry.
   */
  private const KEYS = ['label', 'description'];

  public function __construct(
    private readonly PageBuilderDeclarations $declarations,
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
   * Contributes the declared groups to the catalog, in declaration order.
   */
  public function onCatalogBuild(DialectCatalogBuildEvent $event): void {
    $event->addGroups($this->all($this->declarations->siteTheme()));
  }

  /**
   * The declared groups, validated.
   *
   * @param string|null $theme
   *   The theme whose layered declarations answer, or NULL for the active
   *   theme (PageBuilderDeclarations::getSite()).
   *
   * @return array<string, array{label: string, description: string}>
   *   The groups keyed by id, in declaration order; the description is ''
   *   when the declaration wrote none.
   *
   * @throws \Drupal\daedalus_ui\ElementModel\DeclarationException
   *   When the section is malformed (see parse()).
   */
  public function all(?string $theme = NULL): array {
    return self::parse($this->declarations->getSite($theme));
  }

  /**
   * Validates and normalizes the groups section of a site map.
   *
   * The grammar: the section, when present, is a map of group id (a
   * machine name, never the reserved DialectCatalog::UNGROUPED) to a map
   * with a required non-empty `label` and an optional non-empty
   * `description`; no other key. Every problem is collected and thrown
   * once, the declarations' own posture.
   *
   * @param array $site
   *   The layered site declaration (PageBuilderDeclarations::getSite()).
   *
   * @return array<string, array{label: string, description: string}>
   *   The groups keyed by id, the description '' when unwritten.
   *
   * @throws \Drupal\daedalus_ui\ElementModel\DeclarationException
   */
  public static function parse(array $site): array {
    if (!array_key_exists(self::SECTION, $site)) {
      return [];
    }
    $section = $site[self::SECTION];
    $at = sprintf('The site declaration\'s %s section', self::SECTION);
    if (!is_array($section) || ($section !== [] && array_is_list($section))) {
      throw new DeclarationException("$at must be a map of group id to {label, description}.");
    }
    $errors = [];
    $groups = [];
    foreach ($section as $id => $declaration) {
      $id = (string) $id;
      if (!preg_match(DialectCatalog::GROUP_ID_PATTERN, $id)) {
        $errors[] = sprintf('%s keys the group "%s"; a group id is a machine name (lowercase letters, digits and underscores), the id a blueprint\'s groups list names.', $at, $id);
        continue;
      }
      if ($id === DialectCatalog::UNGROUPED) {
        $errors[] = sprintf('%s declares the group "%s"; that id is reserved for the blueprints and block types in no group, so a declared group never takes it.', $at, $id);
        continue;
      }
      if (!is_array($declaration) || $declaration === [] || array_is_list($declaration)) {
        $errors[] = sprintf('%s declares the group "%s" as %s; a group is a map with a label and an optional description.', $at, $id, var_export($declaration, TRUE));
        continue;
      }
      if ($unknown = array_diff(array_keys($declaration), self::KEYS)) {
        $errors[] = sprintf('%s declares the group "%s" with unknown key(s) %s; a group carries %s.', $at, $id, implode(', ', array_map(static fn ($key) => var_export($key, TRUE), $unknown)), implode(' and ', array_map(static fn (string $key): string => "\"$key\"", self::KEYS)));
      }
      $label = $declaration['label'] ?? NULL;
      if (!is_string($label) || trim($label) === '') {
        $errors[] = sprintf('%s declares the group "%s" without a label; a group label is a non-empty string, the filter\'s name.', $at, $id);
      }
      $description = $declaration['description'] ?? '';
      if (!is_string($description) || (array_key_exists('description', $declaration) && trim($description) === '')) {
        $errors[] = sprintf('%s sets the description of the group "%s" to %s; a group description is a non-empty string saying what belongs in the group (omit it rather than declaring an empty one).', $at, $id, var_export($declaration['description'], TRUE));
      }
      if (is_string($label) && is_string($description)) {
        $groups[$id] = ['label' => trim($label), 'description' => trim($description)];
      }
    }
    if ($errors !== []) {
      throw new DeclarationException(implode("\n", $errors));
    }
    return $groups;
  }

}
