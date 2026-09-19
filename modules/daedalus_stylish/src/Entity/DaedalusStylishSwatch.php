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
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SwatchValueItem;
use Drupal\daedalus_stylish\DaedalusStylishSwatchAccessControlHandler;
use Drupal\daedalus_stylish\DaedalusStylishSwatchInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * The swatch entity: a named CSS value in the palette.
 *
 * The variant pattern at value scope — revisionable and publishable, so
 * workspace staging and revision reverts come free — with the skin's
 * blast radius: an edit restyles every element referencing the swatch. All
 * published swatches are live (no active pointer): the palette is a
 * namespace, and names never compete.
 *
 * No forms or routes here: swatches are written by the palette gestures
 * (mint, rename, edit — the manager lane) and by modules shipping defaults
 * (the disk lane); they compile to one CSS custom property each, they are
 * not pages.
 */
#[ContentEntityType(
  id: 'daedalus_swatch',
  label: new TranslatableMarkup('Swatch'),
  label_collection: new TranslatableMarkup('Swatches'),
  label_singular: new TranslatableMarkup('swatch'),
  label_plural: new TranslatableMarkup('swatches'),
  label_count: [
    'singular' => '@count swatch',
    'plural' => '@count swatches',
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
    'access' => DaedalusStylishSwatchAccessControlHandler::class,
  ],
  admin_permission: 'administer swatches',
  base_table: 'daedalus_swatch',
  revision_table: 'daedalus_swatch_revision',
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
)]
class DaedalusStylishSwatch extends EditorialContentEntityBase implements DaedalusStylishSwatchInterface {

  use EntityOwnerTrait;
  use MachineNameTrait;
  use DesignSystemMemberTrait;
  use ShippedSourceTrait {
    setShippedSource as traitSetShippedSource;
  }

  /**
   * The shape of a swatch machine name.
   *
   * Locked down hard because the name prints into the compiled stylesheet
   * as the custom property tail (--swatch-{name}): lowercase letters,
   * digits, and inner hyphens or underscores — the token-name charset plus
   * the underscore the machine-name generator produces.
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
      ->setDescription(new TranslatableMarkup('The human name shown in the palette, e.g. "Hero fade".'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255);

    // Identity fields are deliberately NOT revisionable: a var() reference
    // written into a style value must survive revision reverts of the
    // swatch, and a swatch never changes what it applies to.
    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Machine name'))
      ->setDescription(new TranslatableMarkup('The stable name style values reference as var(--swatch-{name}) — the custom property tail. Auto-generated from the label when omitted.'))
      ->setSetting('max_length', 255)
      ->addConstraint('UniqueField');

    // The description is the swatch's AI-facing face, the variant's twin:
    // the design system brief serves it as "when to reach for this
    // value", written as advice to a future designer (human or model).
    // Optional, but every swatch should want one: an undescribed swatch
    // is a value with no place in the system's voice.
    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('What this swatch is for and when to reach for it, e.g. "The one accent: the call to action and nothing else". Served to the AI in the design system brief.'))
      ->setRevisionable(TRUE);

    // The AI opt-out, the variant's twin: offered by default, off for a
    // value kept for UI use only (the roster's offered view omits it; the
    // custom property still validates and delivers). Revisionable like
    // the description it silences.
    $fields['ai_offered'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Offer to the AI'))
      ->setDescription(new TranslatableMarkup('Whether the AI is told about this swatch. Off, it leaves the brief and the schema but its var(--swatch-*) still resolves.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(TRUE)
      ->setInitialValue(TRUE);

    $fields['kind'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Kind'))
      ->setDescription(new TranslatableMarkup('The SwatchKind plugin id ("color", "gradient"). The kind owns the value\'s shape validation and decides where the swatch is offered.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['value'] = BaseFieldDefinition::create('daedalus_swatch_value')
      ->setLabel(new TranslatableMarkup('Value'))
      ->setDescription(new TranslatableMarkup("The CSS value: a string or a condition-keyed map with a 'default' entry, conditions on the width/scheme/motion axes."))
      ->setRevisionable(TRUE);

    // Shipped-swatch identity (the disk lane): which module's
    // MODULE.daedalus_stylish.swatches.yml this swatch came from, and the hash of
    // the payload as shipped. Non-revisionable like the other identity
    // fields.
    $fields['provider'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Provider'))
      ->setDescription(new TranslatableMarkup('The module whose swatches file ships this swatch. Empty for swatches minted in the UI or by AI, which have no disk source and therefore no revert-to-defaults.'))
      ->setSetting('max_length', 255);

    // Membership: the design system this swatch belongs to, or empty for
    // a global one. Non-revisionable identity beside provider; see
    // DesignSystemMemberInterface.
    $fields['design_system'] = static::designSystemBaseFieldDefinition();

    $fields['source_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Source hash'))
      ->setDescription(new TranslatableMarkup("Hash of the shipped payload at the last import or sync. One stored hash, two comparisons: against the entity's current data (user modified?) and against the current disk data (defaults changed?)."))
      ->setSetting('max_length', 64);

    // The mint gate's provenance: who minted the swatch, shipped (a module's
    // swatches file, stamped by the importer through setShippedSource()), user
    // (a person, in the editor) or ai (the assistant, on the user's
    // request), stamped by the lane at mint and carried on the roster
    // record for the life of the site. Identity, not a revisioned fact: a
    // revert never changes who minted it.
    $fields['origin'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Origin'))
      ->setDescription(new TranslatableMarkup("Who minted the swatch: 'shipped' (a module), 'user' (a person) or 'ai' (the assistant, on the user's request)."))
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
    // The kind is identity: it decides where the swatch is offered and
    // which shape validation its value passed, so a kind no plugin
    // provides could never validate or preview — it can never be saved.
    $kinds = \Drupal::service('plugin.manager.daedalus_swatch_kind')->kinds();
    if (!in_array($this->getKind(), $kinds, TRUE)) {
      throw new EntityMalformedException(sprintf('"%s" is not a swatch kind; the kinds are: %s.', $this->getKind(), implode(', ', $kinds)));
    }
    // A swatch IS its value — a named value without one is not a swatch,
    // and delivery would print an empty custom property.
    if ($this->getSwatchValue() === '') {
      throw new EntityMalformedException('A swatch requires a value; set it with setSwatchValue().');
    }
    if (!in_array($this->getOrigin(), MaterialRecord::ORIGINS, TRUE)) {
      throw new EntityMalformedException(sprintf('"%s" is not a material origin; one of %s.', $this->getOrigin(), implode(', ', MaterialRecord::ORIGINS)));
    }
    if ($this->get('machine_name')->isEmpty()) {
      $this->set('machine_name', $this->generateMachineName('swatch'));
    }
    // The name prints into the compiled stylesheet as the custom property
    // tail, so its charset is locked down hard (generated names always
    // fit; this gates authored ones).
    elseif (!preg_match(self::MACHINE_NAME, $this->getMachineName())) {
      throw new EntityMalformedException(sprintf('"%s" is not a swatch machine name (lowercase letters, digits, and inner hyphens or underscores); it becomes the tail of the CSS custom property --swatch-{name}.', $this->getMachineName()));
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
   * the source with: a swatch a module ships is shipped, whichever lane
   * touched it before.
   */
  public function setShippedSource(string $provider, array $shipped_data): static {
    $this->traitSetShippedSource($provider, $shipped_data);
    return $this->setOrigin(MaterialRecord::ORIGIN_SHIPPED);
  }

  /**
   * {@inheritdoc}
   */
  public function getKind(): string {
    return (string) $this->get('kind')->value;
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
  public function getSwatchValue(): string|array {
    $item = $this->get('value')->first();
    return $item instanceof SwatchValueItem ? $item->getSwatchValue() : '';
  }

  /**
   * {@inheritdoc}
   */
  public function setSwatchValue(string|array $value): static {
    $list = $this->get('value');
    $item = $list->first() ?? $list->appendItem();
    assert($item instanceof SwatchValueItem);
    $item->setSwatchValue($value);
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * The shipped payload covers everything a swatches file ships — kind AND
   * value — under stable keys, so the hash pins both halves of what the
   * swatch is.
   */
  protected function shippedData(): array {
    return ['kind' => $this->getKind(), 'value' => $this->getSwatchValue()];
  }

}
