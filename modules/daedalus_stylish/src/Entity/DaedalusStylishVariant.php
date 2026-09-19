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
use Drupal\daedalus_stylish\Plugin\Field\FieldType\VariantStylesItem;
use Drupal\daedalus_stylish\StyleGuide;
use Drupal\daedalus_stylish\DaedalusStylishVariantAccessControlHandler;
use Drupal\daedalus_stylish\DaedalusStylishVariantInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * The style variant entity.
 *
 * Revisionable and publishable, which is exactly what makes it
 * workspace-aware: the Workspaces module supports any revisionable,
 * publishable content entity type, so "update variant" inside a workspace
 * stages the change workspace-wide and publishes with it. On plain sites the
 * push is immediate and REVISIONED: a bad push is one revert away.
 *
 * No forms or routes here: variants are written by the save-as-variant and
 * push-update gestures and by recipes shipping default content; they
 * compile to CSS at render, they are not pages.
 */
#[ContentEntityType(
  id: 'daedalus_variant',
  label: new TranslatableMarkup('Style variant'),
  label_collection: new TranslatableMarkup('Style variants'),
  label_singular: new TranslatableMarkup('style variant'),
  label_plural: new TranslatableMarkup('style variants'),
  label_count: [
    'singular' => '@count style variant',
    'plural' => '@count style variants',
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
    'access' => DaedalusStylishVariantAccessControlHandler::class,
  ],
  admin_permission: 'administer variants',
  base_table: 'daedalus_variant',
  revision_table: 'daedalus_variant_revision',
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
)]
class DaedalusStylishVariant extends EditorialContentEntityBase implements DaedalusStylishVariantInterface {

  use EntityOwnerTrait;
  use MachineNameTrait;
  use DesignSystemMemberTrait;
  use ShippedSourceTrait {
    setShippedSource as traitSetShippedSource;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('The human name shown in the variant library, e.g. "Testimonial — bold".'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255);

    // The description is the variant's AI-facing face: the style guide's
    // variants roster serves it as "when to reach for this look", so it is
    // written as advice to a future designer (human or model). Optional,
    // unlike a stencil's (variants predate the roster), but every variant
    // should want one: an undescribed variant teaches nothing.
    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('What this variant is for and when to reach for it, e.g. "A full-bleed opener for landing pages". Served to the AI in the style guide\'s variants roster.'))
      ->setRevisionable(TRUE);

    // The AI opt-out: offered by default (the AI's roster is the design
    // system; hiding by default would empty it), off for a look kept for
    // UI use only. The roster's offered view omits it, so the description
    // above has no audience; legality never reads it. A presentation fact
    // like the description, so revisionable.
    $fields['ai_offered'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Offer to the AI'))
      ->setDescription(new TranslatableMarkup('Whether the AI is told about this variant. Off, it leaves the brief and the schema but still applies wherever its scope fits.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(TRUE)
      ->setInitialValue(TRUE);

    // Identity fields are deliberately NOT revisionable: a reference written
    // into a style map must survive revision reverts of the variant, and a
    // variant never changes what it applies to.
    // Ordering within a variant family: rosters sort weight-then-label, so
    // a family with a real scale (the width variants) can present in scale
    // order while user-minted variants (default 0) stay alphabetical among
    // themselves. Revisionable like label: a presentation fact, not
    // identity.
    $fields['weight'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Weight'))
      ->setDescription(new TranslatableMarkup('Sort position among the variants of a scope: rosters order by weight, then label. Lighter floats up.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(0);

    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Machine name'))
      ->setDescription(new TranslatableMarkup('The stable name style maps ({"variants": [...]}) and style-guide YAML (default_variants) reference. Auto-generated from the label when omitted.'))
      ->setSetting('max_length', 255)
      ->addConstraint('UniqueField');

    $fields['scope'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Scope'))
      ->setDescription(new TranslatableMarkup('The styling address captured from the source element at save, printed: "block_type:{bundle}", "layout:{layout_id}", "block_plugin:{plugin_id}", "kind:{entity|container|field|block}", or "base:{tag}" for a bare-markup tag treatment. The variant applies only where the target element\'s address pairs; base-scoped variants pair everywhere and are offered by rendered tag.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['axis'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Axis'))
      ->setDescription(new TranslatableMarkup('The variant axis this variant competes on within its scope. An element wears at most one variant per axis (applying another replaces it) and composes variants across axes. Every scope has the built-in "style" axis, the default when omitted; a scope\'s style guide declaration may add more under variant_axes.'))
      ->setSetting('max_length', 255);

    // Shipped-variant identity (the disk lane): which module's
    // MODULE.daedalus_stylish.variants.yml this variant came from, and the hash of the
    // styles as shipped. Non-revisionable like the other identity fields:
    // the disk relationship belongs to the variant, not to any one revision,
    // so reverting a revision never resurrects a stale shipped-source state.
    $fields['provider'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Provider'))
      ->setDescription(new TranslatableMarkup('The module whose variants file ships this variant. Empty for variants created in the UI or by AI, which have no disk source and therefore no revert-to-defaults.'))
      ->setSetting('max_length', 255);

    // Membership: the design system this variant belongs to, or empty for
    // a global one. Non-revisionable identity beside provider; see
    // DesignSystemMemberInterface.
    $fields['design_system'] = static::designSystemBaseFieldDefinition();

    $fields['source_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Source hash'))
      ->setDescription(new TranslatableMarkup("Hash of the shipped styles at the last import or sync. One stored hash, two comparisons: against the entity's current styles (user modified?) and against the current disk styles (defaults changed?)."))
      ->setSetting('max_length', 64);

    $fields['styles'] = BaseFieldDefinition::create('daedalus_variant_styles')
      ->setLabel(new TranslatableMarkup('Styles'))
      ->setDescription(new TranslatableMarkup('The style data: element-relative keys ("" for the root element, "::.class-name" for sub-elements) mapped to condition-keyed style entries.'))
      ->setRevisionable(TRUE);

    // The mint gate's provenance: who minted the variant, shipped (a module's
    // variants file, stamped by the importer through setShippedSource()), user
    // (a person, in the editor) or ai (the assistant, on the user's
    // request), stamped by the lane at mint and carried on the roster
    // record for the life of the site. Identity, not a revisioned fact: a
    // revert never changes who minted it.
    $fields['origin'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Origin'))
      ->setDescription(new TranslatableMarkup("Who minted the variant: 'shipped' (a module), 'user' (a person) or 'ai' (the assistant, on the user's request)."))
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
    // The scope is auto-captured from the source element at save: a
    // scope-less variant would be offerable nowhere and validatable
    // against nothing, so it can never be saved.
    if ($this->get('scope')->isEmpty()) {
      throw new EntityMalformedException('A style variant requires a scope; capture it from the source element with StyleMapValidator::captureScope().');
    }
    if (!in_array($this->getOrigin(), MaterialRecord::ORIGINS, TRUE)) {
      throw new EntityMalformedException(sprintf('"%s" is not a material origin; one of %s.', $this->getOrigin(), implode(', ', MaterialRecord::ORIGINS)));
    }
    if ($this->get('machine_name')->isEmpty()) {
      $this->set('machine_name', $this->generateMachineName('variant'));
    }
    // Every variant lives on exactly one axis of its scope: axes are
    // replacement lanes in an element's reference list, composed across
    // axes. An omitted axis lands on the built-in "style" axis, which every
    // scope has; a declared axis must belong to the scope's vocabulary, or
    // the variant could never compose coherently anywhere it applies.
    $axes = \Drupal::service('daedalus_stylish.style_map_validator')->scopeAxes($this->getScope());
    if ($axes === NULL) {
      throw new EntityMalformedException(sprintf('"%s" is not a variant scope; a scope is a root address: "block_type:{bundle}", "block_plugin:{plugin_id}", "layout:{layout_id}", "kind:{kind}", or "base:{tag}".', $this->getScope()));
    }
    if ($this->get('axis')->isEmpty()) {
      $this->set('axis', StyleGuide::STYLE_AXIS);
    }
    elseif (!in_array($this->getAxis(), $axes, TRUE)) {
      throw new EntityMalformedException(sprintf('"%s" is not an axis of the "%s" scope; its axes are: %s.', $this->getAxis(), $this->getScope(), implode(', ', $axes)));
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
   * the source with: a variant a module ships is shipped, whichever lane
   * touched it before.
   */
  public function setShippedSource(string $provider, array $shipped_data): static {
    $this->traitSetShippedSource($provider, $shipped_data);
    return $this->setOrigin(MaterialRecord::ORIGIN_SHIPPED);
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
  public function getWeight(): int {
    return (int) $this->get('weight')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setWeight(int $weight): static {
    $this->set('weight', $weight);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getScope(): string {
    return (string) $this->get('scope')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setScope(string $scope): static {
    $this->set('scope', $scope);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getAxis(): string {
    return (string) $this->get('axis')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setAxis(string $axis): static {
    $this->set('axis', $axis);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getStyles(): array {
    $item = $this->get('styles')->first();
    return $item instanceof VariantStylesItem ? $item->getStyleMap() : [];
  }

  /**
   * {@inheritdoc}
   */
  public function setStyles(array $styles): static {
    $list = $this->get('styles');
    $item = $list->first() ?? $list->appendItem();
    assert($item instanceof VariantStylesItem);
    $item->setStyleMap($styles);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function hashStyles(array $styles): string {
    return static::hashData($styles);
  }

  /**
   * {@inheritdoc}
   */
  protected function shippedData(): array {
    return $this->getStyles();
  }

}
