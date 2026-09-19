<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_stencil\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Routing\AdminHtmlRouteProvider;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Entity\DesignSystemMemberTrait;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint_stencil\Form\StencilForm;
use Drupal\daedalus_blueprint_stencil\Plugin\Field\FieldType\StencilElementItem;
use Drupal\daedalus_blueprint_stencil\StencilAccessControlHandler;
use Drupal\daedalus_blueprint_stencil\StencilDiscoveryInterface;
use Drupal\daedalus_blueprint_stencil\StencilInterface;
use Drupal\daedalus_blueprint_stencil\StencilListBuilder;
use Drupal\media\MediaInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * The stencil content entity: a user-saved, reusable composed subtree.
 *
 * The second stencil source, next to module-shipped stencil files. Both
 * carry the same definition face (label, description, element in the
 * file grammar); this one is persisted by the save-as-stencil gesture and
 * revisioned by push-update, following DaedalusStylishVariant structurally:
 * revisionable + publishable is exactly what makes it workspace-aware, and
 * a bad push is one revision revert away.
 *
 * The admin UI here (listing + metadata edit form) manages CONTENT stencils
 * only. File-shipped stencils are read-only everywhere by design: no
 * shadowing, no override-with-revert, and they never appear in these forms
 * because they are not entities at all. Who changes one follows the distro
 * lock (DistroLock). On a consuming site (the lock holds) a site editor
 * tweaks a distro stencil by placing it, adjusting on canvas, and saving a
 * NEW content stencil. On the package author's site (the developer gate,
 * `$settings['daedalus_blueprint_developer'] = TRUE`) the same placement,
 * SAVED, is the input of drush `daedalus-blueprint:stencil-ship`, which
 * rewrites the file in its module; there is still no entity and no UI
 * door, the file's lane is the command.
 *
 * The element subtree is deliberately absent from the edit form: it is
 * written by the canvas gestures (save-as-stencil, push-update), not typed
 * into an admin page.
 */
#[ContentEntityType(
  id: 'stencil',
  label: new TranslatableMarkup('Stencil'),
  label_collection: new TranslatableMarkup('Stencils'),
  label_singular: new TranslatableMarkup('stencil'),
  label_plural: new TranslatableMarkup('stencils'),
  label_count: [
    'singular' => '@count stencil',
    'plural' => '@count stencils',
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
    'access' => StencilAccessControlHandler::class,
    'list_builder' => StencilListBuilder::class,
    'form' => [
      'edit' => StencilForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/content/stencils',
    'edit-form' => '/admin/content/stencils/{stencil}/edit',
    'delete-form' => '/admin/content/stencils/{stencil}/delete',
  ],
  admin_permission: 'save stencils',
  base_table: 'stencil',
  revision_table: 'stencil_revision',
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
)]
class Stencil extends EditorialContentEntityBase implements StencilInterface {

  use EntityOwnerTrait;
  use DesignSystemMemberTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::ownerBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('The human name shown in the palette, e.g. "Call to Action".'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ]);

    // Identity is deliberately NOT revisionable: a placement's provenance
    // stamp and any palette reference must survive revision reverts, and a
    // stencil never changes what it is called in the vocabulary.
    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Machine name'))
      ->setDescription(new TranslatableMarkup('The stencil ID in discovery: one flat namespace shared with file-shipped stencil IDs. Auto-generated from the label when omitted.'))
      ->setSetting('max_length', 255)
      ->addConstraint('UniqueField');

    // Required to mirror the file grammar, where discovery loudly rejects
    // an empty description: the description is half the palette entry's
    // face (the Add panel tooltip and the AI both read it), whichever
    // source the stencil comes from.
    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('What the stencil is for, shown in the palette and, while offered, read by the AI.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 5,
        'settings' => ['rows' => 3],
      ]);

    // The AI opt-out: offered by default (the AI's roster is the design
    // system; hiding by default would empty it), off for a composition
    // kept for the palette only. The roster's offered view omits it, so
    // it leaves the brief and the schema; the palette still places it and
    // a placement still validates (legality never reads the flag). The
    // daedalus_stylish materials carry the same field. A presentation fact like
    // the description, so revisionable.
    $fields['ai_offered'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Offer to the AI'))
      ->setDescription(new TranslatableMarkup('Whether the AI is told about this stencil. Off, it leaves the brief and the schema but still places from the palette.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(TRUE)
      ->setInitialValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 6,
        'settings' => ['display_label' => TRUE],
      ]);

    $fields['element'] = BaseFieldDefinition::create('stencil_element')
      ->setLabel(new TranslatableMarkup('Element'))
      ->setDescription(new TranslatableMarkup("The blueprint element subtree stamped at placement, in the same grammar as a stencil file's element key."))
      ->setRevisionable(TRUE);

    // A media reference, not a path string, because the user lane picks
    // icons from the media library. Deliberately unconstrained by media
    // type: pickers constrain what is OFFERED; the icon value grammar's
    // resolver accepts any file-backed media, and mirroring a bundle roster
    // here would be a second gate that could drift. Optional: an unset icon
    // is legitimate, and each consumer renders its default stencil icon.
    $fields['icon'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Icon'))
      ->setDescription(new TranslatableMarkup('The palette icon media. Empty renders the default stencil icon.'))
      ->setSetting('target_type', 'media')
      ->setRevisionable(TRUE);

    // The mint gate's provenance: who minted the stencil, user (a person,
    // in the editor) or ai (the assistant, on request), stamped by the
    // lane at save and carried on the roster record for the life of the
    // site. Module-shipped stencil files never become entities, so the
    // shipped origin is derived, never stored. Identity, not a revisioned
    // fact: a revert never changes who minted it.
    $fields['origin'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Origin'))
      ->setDescription(new TranslatableMarkup("Who minted the stencil: 'user' (a person) or 'ai' (the assistant, on the user's request)."))
      ->setRequired(TRUE)
      ->setSetting('max_length', 16)
      ->setDefaultValue(MaterialRecord::ORIGIN_USER)
      ->setInitialValue(MaterialRecord::ORIGIN_USER);

    // Membership: the design system this stencil belongs to, or empty for
    // a global one. Non-revisionable identity like the machine name; the
    // same field every member kind carries, see DesignSystemMemberInterface.
    // Stamped by the save lane (the active system unless the gesture opts
    // out) and, on owned variants, copied from the stencil at adoption.
    $fields['design_system'] = static::designSystemBaseFieldDefinition();

    $status = $fields['status'];
    assert($status instanceof BaseFieldDefinition);
    $status->setDisplayOptions('form', [
      'type' => 'boolean_checkbox',
      'weight' => 10,
      'settings' => ['display_label' => TRUE],
    ]);

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
    // A stencil IS its element: without one the palette entry would stamp
    // nothing, so it can never be saved. The gestures always capture a
    // subtree before saving; only programmatic misuse gets here.
    if ($this->getElement() === []) {
      throw new EntityMalformedException('A stencil requires an element subtree; capture it from the staged element with StencilExporter.');
    }
    if (!in_array($this->getOrigin(), MaterialRecord::ORIGINS, TRUE)) {
      throw new EntityMalformedException(sprintf('"%s" is not a material origin; one of %s.', $this->getOrigin(), implode(', ', MaterialRecord::ORIGINS)));
    }
    if ($this->get('machine_name')->isEmpty()) {
      $this->set('machine_name', $this->generateMachineName($storage));
    }
    // Stencil IDs are one flat site-wide vocabulary across content stencils
    // AND module-shipped stencil files, and collisions are always loud:
    // presave catches a content save colliding with the existing vocabulary;
    // discovery catches the other direction (a module shipping the ID of an
    // already-saved content stencil is installed later).
    elseif ($this->machineNameTaken($storage, $this->getMachineName())) {
      throw new EntityMalformedException(sprintf('The stencil machine name "%s" is already taken; stencil IDs are one flat site-wide vocabulary across module-shipped stencil files and content stencils.', $this->getMachineName()));
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
   */
  public function getIcon(): ?MediaInterface {
    $media = $this->get('icon')->entity;
    return $media instanceof MediaInterface ? $media : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function setIcon(?MediaInterface $media): static {
    $this->set('icon', $media?->id());
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getElement(): array {
    $item = $this->get('element')->first();
    return $item instanceof StencilElementItem ? $item->getElement() : [];
  }

  /**
   * {@inheritdoc}
   */
  public function setElement(array $element): static {
    $list = $this->get('element');
    $item = $list->first() ?? $list->appendItem();
    assert($item instanceof StencilElementItem);
    $item->setElement($element);
    return $this;
  }

  /**
   * Derives a machine name from the label, unique in the combined vocabulary.
   */
  protected function generateMachineName(EntityStorageInterface $storage): string {
    $base = \Drupal::transliteration()->transliterate((string) $this->label(), 'en');
    $base = trim(preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower($base)) ?? '', '_');
    if ($base === '') {
      $base = 'stencil';
    }
    $name = $base;
    $suffix = 2;
    while ($this->machineNameTaken($storage, $name)) {
      $name = $base . '_' . $suffix++;
    }
    return $name;
  }

  /**
   * Checks a machine name against the combined stencil vocabulary.
   *
   * Combined means both sources: other stencil content entities (any
   * publication state, an unpublished stencil still reserves its ID) and
   * module-shipped stencil files. This entity itself never counts: the
   * content side excludes it by ID, and a discovery hit whose source is
   * content can only BE this entity, since any other content stencil was
   * already caught by the query.
   */
  protected function machineNameTaken(EntityStorageInterface $storage, string $name): bool {
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('machine_name', $name);
    if (!$this->isNew()) {
      $query->condition('id', $this->id(), '<>');
    }
    if ($query->range(0, 1)->execute()) {
      return TRUE;
    }
    $discovery = \Drupal::service(StencilDiscoveryInterface::class);
    $existing = $discovery->getStencil($name);
    return $existing !== NULL && $existing['source'] === StencilDiscoveryInterface::SOURCE_MODULE;
  }

}
