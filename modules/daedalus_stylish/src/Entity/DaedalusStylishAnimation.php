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
use Drupal\daedalus_stylish\Plugin\Field\FieldType\AnimationStepsItem;
use Drupal\daedalus_stylish\DaedalusStylishAnimationAccessControlHandler;
use Drupal\daedalus_stylish\DaedalusStylishAnimationInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * The animation entity: a named @keyframes block, the motion material.
 *
 * The swatch pattern at structure scope: revisionable and publishable, so
 * workspace staging and revision reverts come free, with the skin's blast
 * radius (an edit restyles every wearer). All published animations are
 * live (no active pointer): motion is a namespace, and names never
 * compete.
 *
 * No forms or routes here: animations are written by the Materials
 * gestures (mint, edit steps, rename; the manager lane) and by modules
 * shipping defaults (the disk lane); they compile to one @keyframes
 * at-rule each, they are not pages.
 */
#[ContentEntityType(
  id: 'daedalus_animation',
  label: new TranslatableMarkup('Animation'),
  label_collection: new TranslatableMarkup('Animations'),
  label_singular: new TranslatableMarkup('animation'),
  label_plural: new TranslatableMarkup('animations'),
  label_count: [
    'singular' => '@count animation',
    'plural' => '@count animations',
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
    'access' => DaedalusStylishAnimationAccessControlHandler::class,
  ],
  admin_permission: 'administer animations',
  base_table: 'daedalus_animation',
  revision_table: 'daedalus_animation_revision',
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
)]
class DaedalusStylishAnimation extends EditorialContentEntityBase implements DaedalusStylishAnimationInterface {

  use EntityOwnerTrait;
  use MachineNameTrait;
  use DesignSystemMemberTrait;
  use ShippedSourceTrait {
    setShippedSource as traitSetShippedSource;
  }

  /**
   * The shape of an animation machine name.
   *
   * Locked down hard because the name prints into the compiled stylesheet
   * as the keyframes ident tail (motion-{name}): lowercase letters, digits,
   * and inner hyphens or underscores, the swatch charset exactly.
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
      ->setDescription(new TranslatableMarkup('The human name shown in the Materials section, e.g. "Fade in".'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255);

    // Identity fields are deliberately NOT revisionable: a reference
    // written into a style value must survive revision reverts of the
    // animation, and an animation never changes what it is called.
    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Machine name'))
      ->setDescription(new TranslatableMarkup('The stable name style values reference as motion-{name}: the keyframes ident tail. Auto-generated from the label when omitted.'))
      ->setSetting('max_length', 255)
      ->addConstraint('UniqueField');

    // The AI-facing face: the materials roster serves it as "when to reach
    // for this motion", written as advice to a future designer.
    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('What this motion is for and when to reach for it, e.g. "A soft entrance for page bands". Served to the AI in the materials roster.'))
      ->setRevisionable(TRUE);

    // The AI opt-out, the variant's twin: offered by default, off for a
    // motion kept for UI use only (the roster's offered view omits it; the
    // keyframes still validate and compile). Revisionable like the
    // description it silences.
    $fields['ai_offered'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Offer to the AI'))
      ->setDescription(new TranslatableMarkup('Whether the AI is told about this animation. Off, it leaves the brief and the schema but motion-{name} still resolves.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(TRUE)
      ->setInitialValue(TRUE);

    $fields['steps'] = BaseFieldDefinition::create('daedalus_animation_steps')
      ->setLabel(new TranslatableMarkup('Steps'))
      ->setDescription(new TranslatableMarkup('The keyframe steps: offsets ("0%", "50%", "100%") mapping CSS properties to values.'))
      ->setRevisionable(TRUE);

    // Shipped-animation identity (the disk lane): which module's
    // MODULE.daedalus_stylish.animations.yml this animation came from, and the hash
    // of the steps as shipped. Non-revisionable like the other identity
    // fields.
    $fields['provider'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Provider'))
      ->setDescription(new TranslatableMarkup('The module whose animations file ships this animation. Empty for animations minted in the UI or by AI, which have no disk source and therefore no revert-to-defaults.'))
      ->setSetting('max_length', 255);

    // Membership: the design system this animation belongs to, or empty
    // for a global one. Non-revisionable identity beside provider; see
    // DesignSystemMemberInterface.
    $fields['design_system'] = static::designSystemBaseFieldDefinition();

    $fields['source_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Source hash'))
      ->setDescription(new TranslatableMarkup("Hash of the shipped steps at the last import or sync. One stored hash, two comparisons: against the entity's current steps (user modified?) and against the current disk steps (defaults changed?)."))
      ->setSetting('max_length', 64);

    // The mint gate's provenance, stamped by the lane at mint and carried
    // on the roster record for the life of the site. Identity, not a
    // revisioned fact: a revert never changes who minted it.
    $fields['origin'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Origin'))
      ->setDescription(new TranslatableMarkup("Who minted the animation: 'shipped' (a module), 'user' (a person) or 'ai' (the assistant, on the user's request)."))
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
    // An animation IS its steps: a keyframes block without one is not an
    // animation, and delivery would print an empty at-rule.
    if ($this->getSteps() === []) {
      throw new EntityMalformedException('An animation requires at least one keyframe step; set them with setSteps().');
    }
    if (!in_array($this->getOrigin(), MaterialRecord::ORIGINS, TRUE)) {
      throw new EntityMalformedException(sprintf('"%s" is not a material origin; one of %s.', $this->getOrigin(), implode(', ', MaterialRecord::ORIGINS)));
    }
    if ($this->get('machine_name')->isEmpty()) {
      $this->set('machine_name', $this->generateMachineName('animation'));
    }
    // The name prints into the compiled stylesheet as the keyframes ident
    // tail, so its charset is locked down hard (generated names always
    // fit; this gates authored ones).
    elseif (!preg_match(self::MACHINE_NAME, $this->getMachineName())) {
      throw new EntityMalformedException(sprintf('"%s" is not an animation machine name (lowercase letters, digits, and inner hyphens or underscores); it becomes the tail of the keyframes name motion-{name}.', $this->getMachineName()));
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
  public function getSteps(): array {
    $item = $this->get('steps')->first();
    return $item instanceof AnimationStepsItem ? $item->getSteps() : [];
  }

  /**
   * {@inheritdoc}
   */
  public function setSteps(array $steps): static {
    $list = $this->get('steps');
    $item = $list->first() ?? $list->appendItem();
    assert($item instanceof AnimationStepsItem);
    $item->setSteps($steps);
    return $this;
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
   * the source with: an animation a module ships is shipped, whichever
   * lane touched it before.
   */
  public function setShippedSource(string $provider, array $shipped_data): static {
    $this->traitSetShippedSource($provider, $shipped_data);
    return $this->setOrigin(MaterialRecord::ORIGIN_SHIPPED);
  }

  /**
   * {@inheritdoc}
   *
   * The shipped payload is the steps map (canonical offsets), the variant
   * precedent: the structure is the shipped fact the hash pins; label and
   * description are metadata the sync never fights the user over.
   */
  protected function shippedData(): array {
    return $this->getSteps();
  }

}
