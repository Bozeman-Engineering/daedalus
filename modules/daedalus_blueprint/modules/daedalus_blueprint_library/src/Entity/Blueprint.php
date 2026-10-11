<?php

declare(strict_types=1);

namespace Drupal\daedalus_blueprint_library\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityMalformedException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_blueprint\Entity\DesignSystemMemberTrait;
use Drupal\daedalus_blueprint\Host\BlueprintHost;
use Drupal\daedalus_blueprint\Host\ContentHost;
use Drupal\daedalus_blueprint\Materials\MaterialRecord;
use Drupal\daedalus_blueprint_library\BlueprintAccessControlHandler;
use Drupal\daedalus_blueprint_library\BlueprintDiscoveryInterface;
use Drupal\daedalus_blueprint_library\BlueprintInterface;
use Drupal\daedalus_blueprint_library\BlueprintListBuilder;
use Drupal\daedalus_blueprint_library\Form\BlueprintAddForm;
use Drupal\daedalus_blueprint_library\Form\BlueprintForm;
use Drupal\daedalus_blueprint_library\Routing\BlueprintHtmlRouteProvider;
use Drupal\media\MediaInterface;
use Drupal\user\EntityOwnerTrait;

/**
 * The blueprint content entity: a saved, reusable page document that is a page.
 *
 * The content source of the blueprint library, beside module-shipped
 * blueprint files. Both carry the same face (label, description, icon,
 * scope, subject); this one is persisted by the capture gesture, composed
 * on its own canvas (the canonical route, in Edit Mode) and revisioned on
 * every commit. Its document is not a field of its own: it is core's
 * Layout Builder override field (the type's `full` display ships Layout
 * Builder enabled with overrides allowed) and the style map field the
 * styling module attaches when Edit Mode is enabled for the bundle, read
 * and written through its host (daedalus_blueprint's BlueprintHost) like a
 * page's.
 *
 * Its bundle is the blueprint TYPE (BlueprintType, config): the one axis
 * every permission over a blueprint runs on (BlueprintPermissions), with
 * the type's own `full` display carrying the document. The library ships
 * `section` and `site_chrome`; a capture mints a `section` unless told
 * otherwise, and the type is identity like the machine name and the scope
 * (what a blueprint is for does not change with its content).
 *
 * The admin UI here (listing, the add page and the per-type add form that
 * lands on the canvas, the details form) manages CONTENT blueprints only.
 * File-shipped blueprints are read-only everywhere by design: no shadowing, no override-with-revert, and they never appear in
 * these forms because they are not entities at all. Who changes one follows
 * the distro lock (DistroLock): on a consuming site an editor forks a file
 * blueprint into a new content blueprint; on the package author's site the
 * blueprint's document is the input of the ship command, which rewrites
 * the file in its module.
 */
#[ContentEntityType(
  id: 'blueprint',
  label: new TranslatableMarkup('Blueprint'),
  label_collection: new TranslatableMarkup('Blueprints'),
  label_singular: new TranslatableMarkup('blueprint'),
  label_plural: new TranslatableMarkup('blueprints'),
  label_count: [
    'singular' => '@count blueprint',
    'plural' => '@count blueprints',
  ],
  bundle_label: new TranslatableMarkup('Blueprint type'),
  entity_keys: [
    'id' => 'id',
    'revision' => 'revision_id',
    'bundle' => 'type',
    'uuid' => 'uuid',
    'label' => 'label',
    'langcode' => 'langcode',
    'published' => 'status',
    'owner' => 'uid',
    'uid' => 'uid',
  ],
  handlers: [
    'view_builder' => EntityViewBuilder::class,
    'access' => BlueprintAccessControlHandler::class,
    'list_builder' => BlueprintListBuilder::class,
    'form' => [
      'add' => BlueprintAddForm::class,
      'edit' => BlueprintForm::class,
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => BlueprintHtmlRouteProvider::class,
    ],
  ],
  links: [
    'canonical' => '/blueprint/{blueprint}',
    'collection' => '/admin/content/blueprints',
    'add-page' => '/admin/content/blueprints/add',
    'add-form' => '/admin/content/blueprints/add/{blueprint_type}',
    'edit-form' => '/admin/content/blueprints/{blueprint}/edit',
    'delete-form' => '/admin/content/blueprints/{blueprint}/delete',
  ],
  bundle_entity_type: 'blueprint_type',
  permission_granularity: 'bundle',
  base_table: 'blueprint',
  revision_table: 'blueprint_revision',
  revision_metadata_keys: [
    'revision_user' => 'revision_user',
    'revision_created' => 'revision_created',
    'revision_log_message' => 'revision_log_message',
  ],
)]
class Blueprint extends EditorialContentEntityBase implements BlueprintInterface {

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
    // blueprint never changes what it is called in the vocabulary.
    $fields['machine_name'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Machine name'))
      ->setDescription(new TranslatableMarkup('The blueprint ID in discovery: one flat namespace shared with file-shipped blueprint IDs. Auto-generated from the label when omitted.'))
      ->setSetting('max_length', 255)
      ->addConstraint('UniqueField');

    // Required to mirror the file grammar, where discovery loudly rejects
    // an empty description: the description is half the palette entry's
    // face (the Add panel tooltip and the AI both read it), whichever
    // source the blueprint comes from.
    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('What the blueprint is for, shown in the palette and, while offered, read by the AI.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 5,
        'settings' => ['rows' => 3],
      ]);

    // What the material stands in for: a container placed into a page
    // (block) or a whole page's document (page). Identity like the machine
    // name: a blueprint's content changes, its scope does not.
    $fields[BlueprintHost::SCOPE_FIELD] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Scope'))
      ->setDescription(new TranslatableMarkup("What the blueprint stands in for: 'block' (a container placed into a page) or 'page' (a whole page's document)."))
      ->setRequired(TRUE)
      ->setSetting('allowed_values', [
        self::SCOPE_BLOCK => new TranslatableMarkup('Block'),
        self::SCOPE_PAGE => new TranslatableMarkup('Page'),
      ])
      ->setDefaultValue(self::SCOPE_BLOCK)
      ->setInitialValue(self::SCOPE_BLOCK);

    // The subject: the kind of host the blueprint is material for. Its
    // canvas renders against core's sample entity of this bundle and a
    // field block's derivative resolves against it (BlueprintHost). Stamped
    // at capture from the source host's display target; editable in the
    // details, and revisioned with them, so a revert restores the subject
    // the document was composed for.
    $fields[BlueprintHost::SUBJECT_TYPE_FIELD] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Subject entity type'))
      ->setDescription(new TranslatableMarkup('The entity type the blueprint is material for (e.g. node): its canvas renders against a sample entity of the subject bundle.'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', EntityTypeInterface::ID_MAX_LENGTH)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 2,
      ]);
    $fields[BlueprintHost::SUBJECT_BUNDLE_FIELD] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Subject bundle'))
      ->setDescription(new TranslatableMarkup('The bundle the blueprint is material for (e.g. page).'))
      ->setRequired(TRUE)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', EntityTypeInterface::BUNDLE_MAX_LENGTH)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 3,
      ]);

    // The admin's allow-list over duck-typed fit: `entity_type:bundle`
    // pairs; empty means any host the blueprint's requirements fit.
    $fields['bundles'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Allowed hosts'))
      ->setDescription(new TranslatableMarkup('The hosts this blueprint may be placed on, as entity_type:bundle pairs (e.g. node:page). Empty: any host it fits.'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 4,
      ]);

    // The groups the blueprint is filed under: filter categories on an
    // offering surface, ids of the site's group vocabulary (the dialect
    // catalog's groups, BlueprintInterface::GROUP_ID_PATTERN). Shape-checked where it is
    // written as the allow-list is; a surface ignores an undeclared id.
    $fields['groups'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Groups'))
      ->setDescription(new TranslatableMarkup('The groups the blueprint is filed under on the surfaces that offer it (filters only), as group ids the site declares (its group vocabulary). Empty: no group.'))
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setRevisionable(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 4,
      ]);

    // The site favorite: the default an offering surface lists under
    // Favorites for everyone (the Add panel, the chooser's order), over
    // which each editor keeps their own marks (daedalus_layout's editor
    // settings). A listing fact and nothing else, like the groups: no
    // operation, fit or placement rule reads it. Off by default; a
    // presentation fact like the description, so revisionable.
    $fields['favorite'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Favorite'))
      ->setDescription(new TranslatableMarkup('Listed under Favorites for everyone where blueprints are offered. Each editor keeps their own favorites over this.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(FALSE)
      ->setInitialValue(FALSE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 5,
        'settings' => ['display_label' => TRUE],
      ]);

    // The AI opt-out: offered by default (the AI's roster is the design
    // system; hiding by default would empty it), off for a composition
    // kept for the palette only. The roster's offered view omits it, so
    // it leaves the brief and the schema; the palette still places it and
    // a placement still validates (legality never reads the flag). The
    // daedalus_stylish materials carry the same field. A presentation fact
    // like the description, so revisionable.
    $fields['ai_offered'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Offer to the AI'))
      ->setDescription(new TranslatableMarkup('Whether the AI is told about this blueprint. Off, it leaves the brief and the schema but still places from the palette.'))
      ->setRevisionable(TRUE)
      ->setDefaultValue(TRUE)
      ->setInitialValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 6,
        'settings' => ['display_label' => TRUE],
      ]);

    // A media reference, not a path string, because the user lane picks
    // icons from the media library. Deliberately unconstrained by media
    // type: pickers constrain what is OFFERED; the icon value grammar's
    // resolver accepts any file-backed media, and mirroring a bundle roster
    // here would be a second gate that could drift. Optional: an unset icon
    // is legitimate, and each consumer renders its default blueprint icon.
    $fields['icon'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Icon'))
      ->setDescription(new TranslatableMarkup('The palette icon media. Empty renders the default blueprint icon.'))
      ->setSetting('target_type', 'media')
      ->setRevisionable(TRUE);

    // The mint gate's provenance: who minted the blueprint, user (a person,
    // in the editor) or ai (the assistant, on request), stamped by the lane
    // at save and carried on the roster record for the life of the site.
    // Module-shipped blueprint files never become entities, so the shipped
    // origin is derived, never stored. Identity, not a revisioned fact: a
    // revert never changes who minted it.
    $fields['origin'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Origin'))
      ->setDescription(new TranslatableMarkup("Who minted the blueprint: 'user' (a person) or 'ai' (the assistant, on the user's request)."))
      ->setRequired(TRUE)
      ->setSetting('max_length', 16)
      ->setDefaultValue(MaterialRecord::ORIGIN_USER)
      ->setInitialValue(MaterialRecord::ORIGIN_USER);

    // Membership: the design system this blueprint belongs to, or empty for
    // a global one. Non-revisionable identity like the machine name; the
    // same field every member kind carries, see DesignSystemMemberInterface.
    // Stamped by the capture lane (the active system unless the gesture
    // opts out) and, on owned variants, copied from the blueprint at
    // adoption.
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
   *
   * Every save of an existing blueprint is a new revision. The spine's
   * storage handler asks the type (BlueprintType::shouldCreateNewRevision(),
   * TRUE for every type) and stamps the revision before this runs; a plain
   * save that never passed through the handler gets the same here: a
   * revision stamped to the saver and the request time with the log
   * cleared, so history attributes the save to who made it. A caller that
   * marked the revision itself (the manager's push, with its log message)
   * keeps its stamp.
   *
   * The storage door of the blueprint's identity facts: a bad origin, a
   * bad scope, a dangling subject, a reserved or taken machine name and a
   * block-scope document of several roots are each refused with
   * EntityMalformedException, whatever road the save came by.
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    $this->checkDesignSystem();
    if (!in_array($this->getOrigin(), MaterialRecord::ORIGINS, TRUE)) {
      throw new EntityMalformedException(sprintf('"%s" is not a material origin; one of %s.', $this->getOrigin(), implode(', ', MaterialRecord::ORIGINS)));
    }
    if (!in_array($this->getScope(), self::SCOPES, TRUE)) {
      throw new EntityMalformedException(sprintf('"%s" is not a blueprint scope; one of %s.', $this->getScope(), implode(', ', self::SCOPES)));
    }
    $this->checkSubject();
    if ($this->get('machine_name')->isEmpty()) {
      $this->set('machine_name', $this->generateMachineName($storage));
    }
    // The two starts that are not blueprints (blank, template) share the
    // slot a blueprint id is named in, so they are reserved: refused here
    // for a content blueprint, at discovery for a shipped file.
    elseif (in_array($this->getMachineName(), self::RESERVED_IDS, TRUE)) {
      throw new EntityMalformedException(sprintf('The blueprint machine name "%s" is reserved: %s name the starts that are not blueprints (an empty page, the bundle\'s template) beside a page blueprint\'s id.', $this->getMachineName(), implode(' and ', array_map(static fn (string $id): string => "\"$id\"", self::RESERVED_IDS))));
    }
    // Blueprint IDs are one flat site-wide vocabulary across content
    // blueprints AND module-shipped blueprint files, and collisions are
    // always loud: presave catches a content save colliding with another
    // content blueprint or with a shipped file; discovery catches the other
    // direction (a module shipping the ID of an already-saved content
    // blueprint, installed after it).
    elseif ($this->machineNameTaken($storage, $this->getMachineName())) {
      throw new EntityMalformedException(sprintf('The blueprint machine name "%s" is already taken; blueprint IDs are one flat site-wide vocabulary across module-shipped blueprint files and content blueprints.', $this->getMachineName()));
    }
    // A block blueprint stands in for ONE container placed into a page, so
    // its document holds one root (zero while it is composing). The one
    // rule has three doors: discovery refuses a block-scope file of several
    // roots, BlueprintScopePolicy refuses a second top-level insert on the
    // canvas, and this is the storage door, the one core's default-content
    // importer writes through with no op and no entity validation.
    if ($this->getScope() === self::SCOPE_BLOCK && $this->hasField(ContentHost::LAYOUT_FIELD)) {
      $roots = count($this->get(ContentHost::LAYOUT_FIELD)->getSections());
      if ($roots > 1) {
        throw new EntityMalformedException(sprintf('The block blueprint "%s" holds %d root containers; a block-scope blueprint is one container placed into a page and its document holds exactly one root. A document of several roots is a page-scope blueprint.', $this->getMachineName(), $roots));
      }
    }
    if (!$this->isNew() && !$this->isNewRevision()) {
      $this->setNewRevision();
      $this->setRevisionUserId((int) \Drupal::currentUser()->id());
      $this->setRevisionCreationTime(\Drupal::time()->getRequestTime());
      $this->setRevisionLogMessage('');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return $this->bundle();
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
  public function isFavorite(): bool {
    return (bool) $this->get('favorite')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setFavorite(bool $favorite): static {
    $this->set('favorite', $favorite);
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
  public function getScope(): string {
    $scope = $this->get(BlueprintHost::SCOPE_FIELD)->value;
    return is_string($scope) && $scope !== '' ? $scope : self::SCOPE_BLOCK;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubjectType(): string {
    return (string) $this->get(BlueprintHost::SUBJECT_TYPE_FIELD)->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubjectBundle(): string {
    return (string) $this->get(BlueprintHost::SUBJECT_BUNDLE_FIELD)->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setSubject(string $entity_type_id, string $bundle): static {
    $this->set(BlueprintHost::SUBJECT_TYPE_FIELD, $entity_type_id);
    $this->set(BlueprintHost::SUBJECT_BUNDLE_FIELD, $bundle);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getBundles(): array {
    return array_values(array_map(static fn (array $item): string => (string) $item['value'], $this->get('bundles')->getValue()));
  }

  /**
   * {@inheritdoc}
   */
  public function setBundles(array $bundles): static {
    $this->set('bundles', array_values($bundles));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getGroups(): array {
    return array_values(array_map(static fn (array $item): string => (string) $item['value'], $this->get('groups')->getValue()));
  }

  /**
   * {@inheritdoc}
   */
  public function setGroups(array $groups): static {
    $this->set('groups', array_values($groups));
    return $this;
  }

  /**
   * Refuses a subject naming no fieldable bundle on this site.
   *
   * The host resolves the subject to core's sample entity of the bundle,
   * and a dangling subject would fail there, on every read, with the
   * sample generator's error; the save is where the fact is checked once.
   *
   * @throws \Drupal\Core\Entity\EntityMalformedException
   */
  protected function checkSubject(): void {
    $entity_type_id = $this->getSubjectType();
    $bundle = $this->getSubjectBundle();
    $definition = $this->entityTypeManager()->getDefinition($entity_type_id, FALSE);
    if ($definition === NULL || !$definition->entityClassImplements(FieldableEntityInterface::class)) {
      throw new EntityMalformedException(sprintf('"%s" is not a fieldable entity type on this site; a blueprint is material for a fieldable bundle.', $entity_type_id));
    }
    $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo($entity_type_id);
    if (!isset($bundles[$bundle])) {
      throw new EntityMalformedException(sprintf('"%s" is not a bundle of %s; a blueprint is material for a bundle that exists (one of %s).', $bundle, $entity_type_id, implode(', ', array_keys($bundles))));
    }
  }

  /**
   * Derives a machine name from the label, unique among content blueprints.
   *
   * A reserved id (RESERVED_IDS) counts as taken, so a blueprint labelled
   * "Blank" or "Template" saves under a suffixed name instead of failing.
   */
  protected function generateMachineName(EntityStorageInterface $storage): string {
    $base = \Drupal::transliteration()->transliterate((string) $this->label(), 'en');
    $base = trim(preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower($base)) ?? '', '_');
    if ($base === '') {
      $base = 'blueprint';
    }
    $name = $base;
    $suffix = 2;
    while (in_array($name, self::RESERVED_IDS, TRUE) || $this->machineNameTaken($storage, $name)) {
      $name = $base . '_' . $suffix++;
    }
    return $name;
  }

  /**
   * Checks a machine name against the combined vocabulary.
   *
   * Combined means both sources: the other content blueprints (any
   * publication state, an unpublished blueprint still reserves its ID) and
   * module-shipped blueprint files. This entity itself never counts: the
   * content side excludes it by ID, and a discovery hit whose source is
   * content can only BE this entity, since any other content blueprint was
   * already caught by the query. Discovery's own half of the rule refuses
   * a shipped file whose ID a content blueprint already holds.
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
    $existing = \Drupal::service(BlueprintDiscoveryInterface::class)->getBlueprint($name);
    return $existing !== NULL && $existing['source'] === BlueprintDiscoveryInterface::SOURCE_MODULE;
  }

}
