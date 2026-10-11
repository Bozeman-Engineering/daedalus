<?php

declare(strict_types=1);

namespace Drupal\daedalus_stylish\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Entity\DesignSystemMemberTrait;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_stylish\DaedalusStylishFontAccessControlHandler;
use Drupal\daedalus_stylish\DaedalusStylishFontInterface;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\FontFacesItem;
use Drupal\user\EntityOwnerTrait;

/**
 * The font entity: a self-hosted family, the type material.
 *
 * The animation pattern at family scope: revisionable and publishable, so
 * workspace staging and revision reverts come free, with the skin's blast
 * radius (an edit restyles every element set in the family). All
 * published fonts are live (no active pointer): families are a namespace
 * and never compete.
 *
 * No forms or routes here beyond the file door: fonts are written by the
 * Materials gestures (mint from files, edit faces, rename; the manager
 * lane) and by modules shipping defaults (the disk lane); they compile to
 * font-face at-rules, they are not pages. The family and faces fields
 * are internal on purpose: the blueprint's generic entity ops neither read
 * nor write them, so the one door to a font's substance is the material
 * gesture, whose validator sees the files.
 */
#[ContentEntityType(
  id: 'daedalus_font',
  label: new TranslatableMarkup('Font'),
  label_collection: new TranslatableMarkup('Fonts'),
  label_singular: new TranslatableMarkup('font'),
  label_plural: new TranslatableMarkup('fonts'),
  label_count: [
    'singular' => '@count font',
    'plural' => '@count fonts',
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
    'access' => DaedalusStylishFontAccessControlHandler::class,
  ],
  admin_permission: 'administer fonts',
  base_table: 'daedalus_font',
  revision_table: 'daedalus_font_revision',
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
)]
class DaedalusStylishFont extends EditorialContentEntityBase implements DaedalusStylishFontInterface {

  use EntityOwnerTrait;
  use MachineNameTrait;
  use DesignSystemMemberTrait;
  use ShippedSourceTrait {
    setShippedSource as traitSetShippedSource;
  }

  /**
   * The shape of a font machine name.
   *
   * The swatch charset exactly: the name is the roster id and the disk
   * lane's file key, so it stays lowercase letters, digits, and inner
   * hyphens or underscores.
   */
  public const MACHINE_NAME = '/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/';

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('The human name shown in the Materials section, e.g. "Inter".'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255);

    // Identity fields are deliberately NOT revisionable: the roster id
    // and the disk file's key survive revision reverts, and a font never
    // changes what it is called.
    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Machine name'))
      ->setDescription(new TranslatableMarkup('The stable name the roster and the disk lane reference. Auto-generated from the label when omitted.'))
      ->setSetting('max_length', 255)
      ->addConstraint('UniqueField');

    // The family a font-family value names, bare ("Inter"); the compiled
    // at-rules and the roster spelling quote it (StyleGuide::fontFamily()).
    // Internal: written by the material gestures alone, whose validator
    // refuses generic and CSS-wide keywords.
    $fields['family'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Family'))
      ->setDescription(new TranslatableMarkup('The family name font-family values reference, e.g. "Inter".'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setInternal(TRUE)
      ->setSetting('max_length', 255);

    // The AI-facing face: the materials roster serves it as "when to reach
    // for this family", written as advice to a future designer.
    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('What this family is for and when to reach for it, e.g. "The display face for headings". Served to the AI in the materials roster.'))
      ->setRevisionable(TRUE);

    // The AI opt-out, the animation's twin: offered by default, off for a
    // family kept for UI use only (the roster's offered view omits it; the
    // faces still validate and compile).
    $fields['ai_offered'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Offer to the AI'))
      ->setDescription(new TranslatableMarkup('Whether the AI is told about this font. Off, it leaves the brief and the schema but the family still resolves.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(TRUE)
      ->setInitialValue(TRUE);

    // The faces: one per weight and style, each naming its file through
    // the typed source map. Internal for the same reason as the family:
    // the material gesture is the one door, because its validator resolves
    // the files.
    $fields['faces'] = BaseFieldDefinition::create('daedalus_font_faces')
      ->setLabel(new TranslatableMarkup('Faces'))
      ->setDescription(new TranslatableMarkup('The faces the family ships: weight, style and source ({file: uuid}) per face.'))
      ->setRevisionable(TRUE)
      ->setInternal(TRUE);

    // Shipped-font identity (the disk lane): which module's fonts file
    // this font came from, and the hash of the family and faces as
    // shipped. Non-revisionable like the other identity fields.
    $fields['provider'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Provider'))
      ->setDescription(new TranslatableMarkup('The module whose fonts file ships this font. Empty for fonts minted in the UI or by AI, which have no disk source and therefore no revert-to-defaults.'))
      ->setSetting('max_length', 255);

    // Membership: the design system this font belongs to, or empty for a
    // global one. Non-revisionable identity beside provider; see
    // DesignSystemMemberInterface.
    $fields['design_system'] = static::designSystemBaseFieldDefinition();

    $fields['source_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Source hash'))
      ->setDescription(new TranslatableMarkup("Hash of the shipped family and faces at the last import or sync. One stored hash, two comparisons: against the entity's current data (user modified?) and against the current disk data (defaults changed?)."))
      ->setSetting('max_length', 64);

    // The mint gate's provenance, stamped by the lane at mint and carried
    // on the roster record for the life of the site. Identity, not a
    // revisioned fact: a revert never changes who minted it.
    $fields['origin'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Origin'))
      ->setDescription(new TranslatableMarkup("Who minted the font: 'shipped' (a module), 'user' (a person) or 'ai' (the assistant, on the user's request)."))
      ->setRequired(TRUE)
      ->setSetting('max_length', 16)
      ->setDefaultValue(MaterialRecord::ORIGIN_USER)
      ->setInitialValue(MaterialRecord::ORIGIN_USER);

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
    // A font IS its faces and its family: without a face nothing delivers,
    // and without a family nothing can be referenced.
    if ($this->getFaces() === []) {
      throw new EntityMalformedException('A font requires at least one face; set them with setFaces().');
    }
    if (trim($this->getFamily()) === '') {
      throw new EntityMalformedException('A font requires a family name; set it with setFamily().');
    }
    if (!in_array($this->getOrigin(), MaterialRecord::ORIGINS, TRUE)) {
      throw new EntityMalformedException(sprintf('"%s" is not a material origin; one of %s.', $this->getOrigin(), implode(', ', MaterialRecord::ORIGINS)));
    }
    if ($this->get('machine_name')->isEmpty()) {
      $this->set('machine_name', $this->generateMachineName('font'));
    }
    elseif (!preg_match(self::MACHINE_NAME, $this->getMachineName())) {
      throw new EntityMalformedException(sprintf('"%s" is not a font machine name (lowercase letters, digits, and inner hyphens or underscores).', $this->getMachineName()));
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
  public function getFamily(): string {
    return (string) $this->get('family')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setFamily(string $family): static {
    $this->set('family', trim($family));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->get('description')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setDescription(string $description): static {
    $this->set('description', $description);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isOffered(): bool {
    return (bool) $this->get('ai_offered')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setOffered(bool $offered): static {
    $this->set('ai_offered', $offered);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getFaces(): array {
    $item = $this->get('faces')->first();
    return $item instanceof FontFacesItem ? $item->getFaces() : [];
  }

  /**
   * {@inheritdoc}
   */
  public function setFaces(array $faces): static {
    $list = $this->get('faces');
    $item = $list->first() ?? $list->appendItem();
    assert($item instanceof FontFacesItem);
    $item->setFaces($faces);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function fileUuids(): array {
    $uuids = [];
    foreach ($this->getFaces() as $face) {
      $uuid = $face['source'][FontFacesItem::SOURCE_FILE] ?? NULL;
      if (is_string($uuid) && $uuid !== '') {
        $uuids[$uuid] = $uuid;
      }
    }
    return array_values($uuids);
  }

  /**
   * {@inheritdoc}
   */
  public function getOrigin(): string {
    $origin = $this->get('origin')->value;
    return is_string($origin) && $origin !== '' ? $origin : MaterialRecord::ORIGIN_USER;
  }

  /**
   * {@inheritdoc}
   */
  public function setOrigin(string $origin): static {
    $this->set('origin', $origin);
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * The disk lane's origin stamp rides the same call the importer records
   * the source with: a font a module ships is shipped, whichever lane
   * touched it before.
   */
  public function setShippedSource(string $provider, array $shipped_data): static {
    $this->traitSetShippedSource($provider, $shipped_data);
    return $this->setOrigin(MaterialRecord::ORIGIN_SHIPPED);
  }

  /**
   * {@inheritdoc}
   *
   * The shipped payload is the family and the canonical faces, each face
   * naming its file by CONTENT (FontFiles::contentAddressed(), {hash:
   * sha256} in the source slot): what the at-rules print, the animation
   * precedent (structure is the shipped fact the hash pins; label and
   * description are metadata the sync never fights the user over). By
   * content because the site holds a face's file by uuid and a module by
   * basename under assets/fonts/, and neither name crosses the disk lane:
   * the importer hashes the module's assets into the same shape
   * (FontImporter::shippedPayload()), so one stored hash still answers
   * both questions, and swapping a face's bytes on either side reads as
   * a change.
   */
  protected function shippedData(): array {
    return [
      'family' => $this->getFamily(),
      'faces' => \Drupal::service('daedalus_stylish.font_files')->contentAddressed($this->getFaces()),
    ];
  }

}
