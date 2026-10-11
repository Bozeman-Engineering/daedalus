<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint_library\BlueprintTypeInterface;

/**
 * The blueprint type config entity: a bundle of the blueprint entity.
 *
 * The library ships two as install config, `section` and `site_chrome`,
 * each with its `full` Layout Builder display (overrides allowed) and the
 * override field that display adds, since a type's canvas is its display:
 * a type without one has no document. A site that adds a type ships the
 * same two pieces of config beside it, the way a node type ships its
 * displays; nothing is created in code, so config stays the one record of
 * a type. Permissions are derived per type (BlueprintPermissions), so a
 * new type is grantable the moment its config lands.
 *
 * There is no admin UI for types: the two shipped ones are the product's
 * vocabulary and a site extends it by config.
 */
#[ConfigEntityType(
  id: 'blueprint_type',
  label: new TranslatableMarkup('Blueprint type'),
  label_collection: new TranslatableMarkup('Blueprint types'),
  label_singular: new TranslatableMarkup('blueprint type'),
  label_plural: new TranslatableMarkup('blueprint types'),
  label_count: [
    'singular' => '@count blueprint type',
    'plural' => '@count blueprint types',
  ],
  config_prefix: 'blueprint_type',
  bundle_of: 'blueprint',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  config_export: [
    'id',
    'label',
    'description',
    'frame_only',
  ],
)]
class BlueprintType extends ConfigEntityBundleBase implements BlueprintTypeInterface {

  /**
   * The machine name.
   */
  protected string $id;

  /**
   * The human label.
   */
  protected string $label;

  /**
   * What blueprints of this type are for, shown where a type is chosen.
   */
  protected string $description = '';

  /**
   * Whether the type's blueprints are the site frame's material.
   *
   * The spelling is BlueprintHost::TYPE_FRAME_ONLY_KEY, which the spine
   * reads off the bundle entity for the fit; absent from a site's config
   * (a type written before the key existed) it reads FALSE.
   */
  protected bool $frame_only = FALSE;

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * {@inheritdoc}
   */
  public function isFrameOnly(): bool {
    return $this->frame_only;
  }

  /**
   * {@inheritdoc}
   */
  public function setDescription($description): static {
    $this->description = (string) $description;
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * Every commit of a blueprint is a new revision (a bad update is one
   * revert away): the answer the spine's storage handler asks of a bundle
   * (EntityStorageHandler::applyRevisionPolicy()), TRUE for every type.
   * Blueprint::preSave() gives the same answer to a plain save that never
   * passed through the handler.
   */
  public function shouldCreateNewRevision() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   *
   * A blueprint type is a page-builder bundle by construction: a blueprint
   * is a page of its own, composed on its canvas in Edit Mode, so the
   * bundle gate daedalus_ui's `enabled` setting carries for a node type or
   * a block type (read by daedalus_stylish's field manager to open the
   * style store, and by every other reader of the setting) is on for every
   * type and cannot be switched off. Answered here rather than stored: the
   * library does not depend on daedalus_ui, so its shipped types cannot
   * carry that module's setting as config, and a type form that could
   * toggle it does not exist.
   */
  public function getThirdPartySetting($module, $key, $default = NULL) {
    if ($module === 'daedalus_ui' && $key === 'enabled') {
      return TRUE;
    }
    return parent::getThirdPartySetting($module, $key, $default);
  }

}
