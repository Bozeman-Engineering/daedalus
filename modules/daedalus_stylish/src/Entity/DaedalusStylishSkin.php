<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Entity\DesignSystemMemberTrait;
use Drupal\daedalus_stylish\Form\DaedalusStylishSkinForm;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinDefaultsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;
use Drupal\daedalus_stylish\DaedalusStylishSkinAccessControlHandler;
use Drupal\daedalus_stylish\DaedalusStylishSkinInterface;
use Drupal\daedalus_stylish\DaedalusStylishSkinListBuilder;
use Drupal\user\EntityOwnerTrait;

/**
 * The skin entity: a site's look as daedalus_stylish data.
 *
 * The variant pattern at site scope: revisionable and publishable, so the
 * Workspaces module stages skin edits workspace-wide for free, and on plain
 * sites every save is a revision — a bad token push is one revert away.
 * Identity fields (machine_name, provider, source_hash) are deliberately
 * NOT revisionable: the active pointer references the machine name and the
 * disk relationship belongs to the skin, so revision reverts never change
 * what a skin IS, only what it says.
 *
 * Unlike variants, skins DO have admin routes: the skin list and edit form
 * (/admin/appearance/skins) are the token editing UI, beside the AI
 * write surface. Delivery (the compiled skin stylesheet) is a separate
 * concern and never reads these forms.
 */
#[ContentEntityType(
  id: 'daedalus_skin',
  label: new TranslatableMarkup('Skin'),
  label_collection: new TranslatableMarkup('Skins'),
  label_singular: new TranslatableMarkup('skin'),
  label_plural: new TranslatableMarkup('skins'),
  label_count: [
    'singular' => '@count skin',
    'plural' => '@count skins',
  ],
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'uuid' => 'uuid',
    'label' => 'label',
    'langcode' => 'langcode',
    'published' => 'status',
    'owner' => 'uid',
    'uid' => 'uid',
  ],
  handlers: [
    'access' => DaedalusStylishSkinAccessControlHandler::class,
    'list_builder' => DaedalusStylishSkinListBuilder::class,
    'form' => [
      'default' => DaedalusStylishSkinForm::class,
      'add' => DaedalusStylishSkinForm::class,
      'edit' => DaedalusStylishSkinForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/appearance/skins',
    'add-form' => '/admin/appearance/skins/add',
    'edit-form' => '/admin/appearance/skins/{daedalus_skin}',
    'delete-form' => '/admin/appearance/skins/{daedalus_skin}/delete',
  ],
  admin_permission: 'administer skins',
  base_table: 'daedalus_skin',
  revision_table: 'daedalus_skin_revision',
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
)]
class DaedalusStylishSkin extends EditorialContentEntityBase implements DaedalusStylishSkinInterface {

  use EntityOwnerTrait;
  use MachineNameTrait;
  use ShippedSourceTrait;
  use DesignSystemMemberTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('The human name shown in the skin list, e.g. "Aurora dark".'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255);

    // Identity fields are deliberately NOT revisionable: a design system's
    // default_skin and skin YAML files reference the machine name, which
    // must survive revision reverts of the skin.
    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Machine name'))
      ->setDescription(new TranslatableMarkup("The stable name a design system's default_skin and skin YAML files reference. Auto-generated from the label when omitted."))
      ->setSetting('max_length', 255)
      ->addConstraint('UniqueField');

    $fields['tokens'] = BaseFieldDefinition::create('daedalus_skin_tokens')
      ->setLabel(new TranslatableMarkup('Tokens'))
      ->setDescription(new TranslatableMarkup("The design token values: {scale: {name: value}} against the site's declared token scales, each value a string or a condition-keyed map with a 'default' entry."))
      ->setRevisionable(TRUE);

    $fields['defaults'] = BaseFieldDefinition::create('daedalus_skin_defaults')
      ->setLabel(new TranslatableMarkup('Site defaults'))
      ->setDescription(new TranslatableMarkup('The site element defaults: {scope: element-relative styles} with LIVE semantics — they restyle existing placements through the compiled skin stylesheet, and per-entity style maps beat them by cascade.'))
      ->setRevisionable(TRUE);

    // Shipped-source identity (the disk lane): which module's
    // MODULE.daedalus_stylish.skins.yml this skin came from, and the hash of the
    // tokens as shipped. Non-revisionable like the other identity fields.
    $fields['provider'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Provider'))
      ->setDescription(new TranslatableMarkup('The module whose skins file ships this skin. Empty for skins created in the UI or by AI, which have no disk source and therefore no revert-to-defaults.'))
      ->setSetting('max_length', 255);

    // Membership: the design system this skin belongs to, or empty for a
    // global one. Non-revisionable identity beside provider; see
    // DesignSystemMemberInterface.
    $fields['design_system'] = static::designSystemBaseFieldDefinition();

    $fields['source_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Source hash'))
      ->setDescription(new TranslatableMarkup("Hash of the shipped tokens at the last import or sync. One stored hash, two comparisons: against the entity's current tokens (user modified?) and against the current disk tokens (defaults changed?)."))
      ->setSetting('max_length', 64);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setRevisionable(TRUE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    $this->checkDesignSystem();
    if ($this->get('machine_name')->isEmpty()) {
      $this->set('machine_name', $this->generateMachineName('skin'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMachineName(): string {
    return (string) $this->get('machine_name')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getTokens(): array {
    $item = $this->get('tokens')->first();
    return $item instanceof SkinTokensItem ? $item->getStyleMap() : [];
  }

  /**
   * {@inheritdoc}
   */
  public function setTokens(array $tokens): static {
    $list = $this->get('tokens');
    $item = $list->first() ?? $list->appendItem();
    assert($item instanceof SkinTokensItem);
    $item->setStyleMap($tokens);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaults(): array {
    $item = $this->get('defaults')->first();
    return $item instanceof SkinDefaultsItem ? $item->getStyleMap() : [];
  }

  /**
   * {@inheritdoc}
   */
  public function setDefaults(array $defaults): static {
    $list = $this->get('defaults');
    $item = $list->first() ?? $list->appendItem();
    assert($item instanceof SkinDefaultsItem);
    $item->setStyleMap($defaults);
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * The shipped payload covers everything a skins file ships — tokens AND
   * defaults — under stable keys, so adding a tier never silently changes
   * the hash of a tier-less skin ({} hashes the same under both shapes
   * only if the shape is keyed; hence the explicit map).
   */
  protected function shippedData(): array {
    return ['tokens' => $this->getTokens(), 'defaults' => $this->getDefaults()];
  }

}
