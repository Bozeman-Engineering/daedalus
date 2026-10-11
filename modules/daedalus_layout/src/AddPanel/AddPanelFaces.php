<?php

declare(strict_types=1);

namespace Drupal\daedalus_layout\AddPanel;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\daedalus_blueprint\Dialect\DialectCatalogBuilder;
use Drupal\daedalus_blueprint\Host\HostInterface;
use Drupal\daedalus_blueprint\IconResolver;
use Drupal\daedalus_blueprint\LayoutBuilder\PlacementRestrictionsInterface;
use Drupal\daedalus_layout\Plugin\Block\SectionBlock;
use Drupal\daedalus_ui\ElementModel\PageBuilderDeclarations;
use Drupal\daedalus_ui\EditorSettings;

/**
 * The face: what a canvas can place, as one list.
 *
 * The Add panel is one list of placeables, each with one face, shown as
 * folding sets (Favorites, each group, All). This service answers the
 * server's half of that list for a canvas host: one face per placeable
 * BLOCK, the site's group vocabulary and the editor's own marks
 * (AddPanelAnswer). Blueprints are not in the answer: they are materials
 * and reach the client live through the materials store, whose records
 * carry the same face keys (`groups`, `favorite`, a resolved `icon`), so
 * one client renderer draws every row from two feeds.
 *
 * A face is
 * `{id, kind, label, description, icon, groups, favorite, editable}`:
 * - `id` is the block plugin id, the grammar the panel's items and the
 *   editor's marks already use (`inline_block:text`,
 *   `field_block:node:page:title`; a blueprint's is `blueprint:ID`).
 * - `kind` is where the face files under All: KIND_BASIC for the
 *   Container and the block types (the site's own pieces), core's block
 *   category for every other plugin (what kind of thing it is, which is
 *   the one thing the categories are good at; they are never groups).
 * - `label` is the plugin's admin label; `description` the block type's,
 *   '' for a plugin that carries none.
 * - `icon` is a root-relative URL, or NULL for "the default icon", the
 *   roster record's contract: a block type's icon is its setting
 *   resolved through the icon value grammar (IconResolver); the
 *   Container wears the panel's own; no other plugin has one.
 * - `groups` and `favorite` are the site's facts on the thing: a block
 *   type's two settings beside its icon
 *   (`block_content.type.*.third_party.daedalus_layout`), a group id the
 *   catalog does not declare dropped (a shipped type from a package whose
 *   groups this site lacks; the form keeps the id, the surface ignores
 *   it). Any other plugin is in no group and is no site default (it has
 *   no face to store one on); the Container is the one exception, a
 *   favorite by the panel's own word, since it leads Favorites and has
 *   no setting to say so. The editor's marks lie over
 *   `favorite`: the effective favorite is the client model's to compute.
 * - `editable` is whether the actor may write the face's `favorite` for
 *   everyone (the row menu's "Favorite for everyone", which the client
 *   offers only where this says so, never by trying): a block type's
 *   update access, the bundle's own admin permission, which the route
 *   that writes it (Controller\BlockTypeFavorite) enforces for real.
 *   The Container and every other plugin have no face to write, so
 *   FALSE. A blueprint's row answers the same question from its record
 *   (`updatable`) and the Add panel's `canSaveBlueprints` grant.
 *
 * The blocks are what PlacementRestrictions admits on the host's own
 * section storage: a page's, a template's, or a saved blueprint's, whose
 * storage answers its SUBJECT as the entity being laid out, so a
 * blueprint's canvas offers its subject bundle's field blocks under the
 * subject display's restrictions, never the blueprint type's. Nothing is
 * read per display any more: a favorite is a fact on the thing, so a
 * blueprint's canvas gets the same favorites as any other.
 *
 * The marks are the one per-user part: `favorites` under this module's
 * namespace in daedalus_ui's EditorSettings, a map of id to TRUE (added)
 * or FALSE (a default removed), written by the client through the
 * generic settings route (`daedalus_ui.settings.save_setting`, the whole
 * map as an application/json body, the route's structured shape) and
 * normalized on read here, as every server-side read of a setting is:
 * anything but a map collapses to no marks, and each value to a boolean.
 * Purely visual state (which sets are folded) is not here; it lives in
 * the browser, the rule EditorSettings states.
 */
final class AddPanelFaces {

  /**
   * The EditorSettings namespace the marks live under.
   */
  public const SETTING_NAMESPACE = 'daedalus_layout';

  /**
   * The EditorSettings key of the editor's marks.
   */
  public const FAVORITES_SETTING = 'favorites';

  /**
   * The kind of the site's own pieces: the Container and the block types.
   */
  public const KIND_BASIC = 'basic';

  /**
   * The prefix of an inline block plugin id, the block type after it.
   */
  private const INLINE_BLOCK_PREFIX = 'inline_block:';

  public function __construct(
    private readonly PlacementRestrictionsInterface $placementRestrictions,
    private readonly BlockManagerInterface $blockManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly IconResolver $iconResolver,
    private readonly DialectCatalogBuilder $catalogBuilder,
    private readonly EditorSettings $editorSettings,
    private readonly ModuleExtensionList $moduleList,
  ) {}

  /**
   * The answer for a canvas host: its faces, the vocabulary, the marks.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host whose canvas the panel serves. A host with no document
   *   places nothing and answers no faces (the panel never mounts there).
   */
  public function answer(HostInterface $host): AddPanelAnswer {
    $cacheability = (new CacheableMetadata())
      // The marks are the actor's.
      ->addCacheContexts(['user'])
      // The block types' faces, the set of them, and the vocabulary.
      ->addCacheTags(['config:block_content_type_list', PageBuilderDeclarations::CACHE_TAG]);
    return new AddPanelAnswer(
      $this->faces($host, $cacheability),
      $this->groups(),
      $this->favorites(),
      $cacheability,
    );
  }

  /**
   * The editor's own marks, normalized.
   *
   * @return array<string, bool>
   *   Block id to TRUE (a favorite of theirs) or FALSE (a default they
   *   removed); [] for anonymous, nothing stored, or a stored value that
   *   is not a map.
   */
  public function favorites(): array {
    $stored = $this->editorSettings->get(self::SETTING_NAMESPACE, self::FAVORITES_SETTING, []);
    if (!is_array($stored)) {
      return [];
    }
    $marks = [];
    foreach ($stored as $id => $mark) {
      if (is_string($id) && $id !== '') {
        $marks[$id] = (bool) $mark;
      }
    }
    return $marks;
  }

  /**
   * The site's group vocabulary in declaration order, as a list.
   *
   * @return array<int, array{id: string, label: string, description: string}>
   *   The catalog's groups (DialectCatalog::groups()), each with its id.
   */
  public function groups(): array {
    $groups = [];
    foreach ($this->catalogBuilder->build()->groups() as $id => $group) {
      $groups[] = ['id' => (string) $id] + $group;
    }
    return $groups;
  }

  /**
   * One face per placeable block on the host, the Container first.
   *
   * @param \Drupal\daedalus_blueprint\Host\HostInterface $host
   *   The host.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Accumulates what the faces depend on: the host's display and each
   *   block type read.
   *
   * @return array<int, array{id: string, kind: string, label: string, description: string, icon: string|null, groups: string[], favorite: bool, editable: bool}>
   *   The faces; [] when the host holds no document.
   */
  public function faces(HostInterface $host, CacheableMetadata $cacheability): array {
    $storage = $host->sectionStorage();
    if ($storage === NULL) {
      return [];
    }
    $display = $host->display();
    if ($display !== NULL) {
      $cacheability->addCacheableDependency($display);
    }
    $declared = array_keys($this->catalogBuilder->build()->groups());

    $container = $this->blockManager->getDefinition(SectionBlock::PLUGIN_ID);
    $faces = [
      [
        'id' => SectionBlock::PLUGIN_ID,
        'kind' => self::KIND_BASIC,
        'label' => (string) $container['admin_label'],
        'description' => '',
        'icon' => '/' . $this->moduleList->getPath('daedalus_layout') . '/assets/container.svg',
        'groups' => [],
        'favorite' => TRUE,
        'editable' => FALSE,
      ],
    ];

    foreach ($this->placementRestrictions->allowedBlockDefinitions($storage) as $plugin_id => $definition) {
      if ($plugin_id === SectionBlock::PLUGIN_ID) {
        continue;
      }
      $face = [
        'id' => $plugin_id,
        'kind' => (string) $definition['category'],
        'label' => (string) $definition['admin_label'],
        'description' => isset($definition['description']) ? (string) $definition['description'] : '',
        'icon' => NULL,
        'groups' => [],
        'favorite' => FALSE,
        'editable' => FALSE,
      ];
      if (str_starts_with($plugin_id, self::INLINE_BLOCK_PREFIX)) {
        // An inline_block definition exists only through block_content's
        // deriver, so the type is loadable; the deriver drops a type that
        // vanished mid-request with the next rebuild.
        $block_type = $this->entityTypeManager->getStorage('block_content_type')->load(substr($plugin_id, strlen(self::INLINE_BLOCK_PREFIX)));
        if ($block_type !== NULL) {
          $tags = $block_type->getCacheTags();
          $face['kind'] = self::KIND_BASIC;
          $face['description'] = (string) $block_type->getDescription();
          $face['icon'] = $this->iconResolver->resolve($block_type->getThirdPartySetting(BlockTypeFace::NAMESPACE, BlockTypeFace::ICON_SETTING), $tags);
          $face['groups'] = array_values(array_intersect(
            (array) $block_type->getThirdPartySetting(BlockTypeFace::NAMESPACE, BlockTypeFace::GROUPS_SETTING, []),
            $declared,
          ));
          $face['favorite'] = (bool) $block_type->getThirdPartySetting(BlockTypeFace::NAMESPACE, BlockTypeFace::FAVORITE_SETTING, FALSE);
          // The bundle's own admin permission, the gate the write route
          // enforces; its cacheability (user.permissions) rides the answer.
          $access = $block_type->access('update', NULL, TRUE);
          $cacheability->addCacheableDependency($access);
          $face['editable'] = $access->isAllowed();
          $cacheability->addCacheTags($tags);
        }
      }
      $faces[] = $face;
    }
    return $faces;
  }

}
