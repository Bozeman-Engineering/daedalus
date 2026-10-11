<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\layout_builder\SectionListTrait;

/**
 * The site frame config entity.
 *
 * Holds the frame's section list and, as third-party settings, its style
 * map; nothing else. The id is the theme's machine name, so a theme has at
 * most one frame and the frame depends on its theme (it leaves with it).
 * Rendered by the Daedalus page display variant for a theme that has one;
 * a theme without a frame keeps the block module's page variant. Its one
 * link is its canvas (FrameInterface::CANVAS_ROUTE, daedalus_layout's
 * route): the frame has no public page of its own, it is seen around
 * every page of its theme.
 *
 * The section list is stored as config (FrameStorage maps Section objects
 * to arrays and back, as core's display storage does), and the layout
 * sections' own third-party settings (daedalus_layout's section uuid,
 * daedalus_blueprint's slot) validate under the same schema a template's
 * sections do.
 */
#[ConfigEntityType(
  id: FrameInterface::ENTITY_TYPE_ID,
  label: new TranslatableMarkup('Site frame'),
  label_collection: new TranslatableMarkup('Site frames'),
  label_singular: new TranslatableMarkup('site frame'),
  label_plural: new TranslatableMarkup('site frames'),
  config_prefix: 'frame',
  entity_keys: [
    'id' => 'id',
  ],
  handlers: [
    'storage' => FrameStorage::class,
    'access' => FrameAccessControlHandler::class,
  ],
  admin_permission: FrameInterface::PERMISSION,
  links: [
    'canonical' => '/site-frame/{daedalus_frame}',
  ],
  label_count: [
    'singular' => '@count site frame',
    'plural' => '@count site frames',
  ],
  config_export: [
    'id',
    'sections',
  ],
)]
class Frame extends ConfigEntityBase implements FrameInterface {

  use SectionListTrait;

  /**
   * The theme's machine name.
   */
  protected string $id;

  /**
   * The sections, as Section objects once loaded.
   *
   * @var \Drupal\layout_builder\Section[]
   */
  protected array $sections = [];

  /**
   * {@inheritdoc}
   */
  public function theme(): string {
    return $this->id;
  }

  /**
   * {@inheritdoc}
   *
   * The frame is named after its theme: the theme's human name when the
   * theme is installed, its machine name otherwise.
   */
  public function label() {
    $theme_handler = \Drupal::service('theme_handler');
    return $theme_handler->themeExists($this->id) ? $theme_handler->getName($this->id) : $this->id;
  }

  /**
   * {@inheritdoc}
   */
  public function getSections() {
    return $this->sections;
  }

  /**
   * {@inheritdoc}
   */
  protected function setSections(array $sections) {
    $this->sections = array_values($sections);
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * The frame depends on its theme and on what its sections place: the
   * layout plugins and the block plugins, as a display's template does.
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $this->addDependency('theme', $this->id);
    foreach ($this->getSections() as $section) {
      $this->calculatePluginDependencies($section->getLayout());
      foreach ($section->getComponents() as $component) {
        $this->calculatePluginDependencies($component->getPlugin());
      }
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * A section whose layout plugin leaves the site goes with it; a component
   * whose block plugin leaves is removed from its section (core's rule for
   * a display's template).
   */
  public function onDependencyRemoval(array $dependencies) {
    $changed = parent::onDependencyRemoval($dependencies);
    foreach ($this->getSections() as $delta => $section) {
      $layout_dependencies = $this->getPluginDependencies($section->getLayout());
      if ($this->removedPluginDependencies($layout_dependencies, $dependencies)) {
        $this->removeSection($delta);
        $changed = TRUE;
        continue;
      }
      foreach ($section->getComponents() as $uuid => $component) {
        $plugin_dependencies = $this->getPluginDependencies($component->getPlugin());
        if ($this->removedPluginDependencies($plugin_dependencies, $dependencies)) {
          $section->removeComponent($uuid);
          $changed = TRUE;
        }
      }
    }
    return $changed;
  }

  /**
   * The intersection of a plugin's dependencies and the removed ones.
   *
   * Core keeps this helper on EntityDisplayBase, out of a plain config
   * entity's reach; the same computation, per dependency type: config and
   * content dependencies are keyed by name, module and theme dependencies
   * are lists of names (ConfigManager::callOnDependencyRemoval()).
   *
   * @param array[] $plugin_dependencies
   *   The plugin's dependencies, as calculateDependencies() shapes them.
   * @param array[] $removed_dependencies
   *   The removed dependencies, as onDependencyRemoval() receives them.
   *
   * @return array[]
   *   The intersection, empty when nothing the plugin needs is leaving.
   */
  protected function removedPluginDependencies(array $plugin_dependencies, array $removed_dependencies): array {
    $intersect = [];
    foreach ($plugin_dependencies as $type => $dependencies) {
      if (empty($removed_dependencies[$type])) {
        continue;
      }
      $removed = in_array($type, ['config', 'content'], TRUE)
        ? array_intersect_key($removed_dependencies[$type], array_flip($dependencies))
        : array_values(array_intersect($removed_dependencies[$type], $dependencies));
      if ($removed) {
        $intersect[$type] = $removed;
      }
    }
    return $intersect;
  }

}
