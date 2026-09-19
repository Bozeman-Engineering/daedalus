<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\EventSubscriber;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\daedalus_stylish\DefaultsStylesheet;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Recompiles the defaults stylesheet when config it bakes in changes.
 *
 * The compiled file depends on two config objects. daedalus_stylish.settings, two
 * ways: the breakpoints thresholds are baked into the @container wrappers
 * of width-conditioned blocks, and container_roots gates the
 * page-container rule. system.theme, one way: the declared tier compiles
 * for the site's DEFAULT theme (declarations layer per theme, and the
 * file is one per site; see DefaultsStylesheet), so a default-theme
 * change swaps the layering the file serves. (WHICH skin prints is the
 * active design system's call, daedalus_blueprint.settings;
 * DesignSystemSwitchSubscriber reacts to that.) Reacting at the config
 * save event covers every writer (admin form, drush config-set, config
 * sync) through one seam. Recompiling on any daedalus_stylish.settings save is
 * deliberately broad: the compile is cheap and a byte-identical result
 * touches nothing but cache tags; the system.theme reaction is narrowed
 * to the default key because that config saves on unrelated admin-theme
 * edits too.
 *
 * The VARIANTS and SWATCHES files share the daedalus_stylish.settings dependencies
 * (breakpoint thresholds and the container_roots gate bake into them too)
 * but are NOT recompiled here: their compiles always query their entity
 * storage (daedalus_variant, daedalus_swatch), which does not exist yet when
 * default config imports at module install. A settings change therefore
 * leaves those files stale until the next variant/swatch save (or the
 * next design-system switch), the accepted staleness class breakpoints
 * edits always had for the variants file. (The DEFAULTS compile survives
 * install-order: daedalus_stylish's entity types are installed before its default
 * config imports, and with no design system active it loads no skin.)
 */
final class DefaultsSettingsSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected DefaultsStylesheet $defaultsStylesheet,
  ) {}

  /**
   * Recompiles when daedalus_stylish.settings or the default theme changes.
   */
  public function onConfigSave(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    $recompile = match ($config->getName()) {
      'daedalus_stylish.settings' => TRUE,
      'system.theme' => $event->isChanged('default'),
      default => FALSE,
    };
    if ($recompile) {
      $this->defaultsStylesheet->compile();
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      ConfigEvents::SAVE => 'onConfigSave',
    ];
  }

}
