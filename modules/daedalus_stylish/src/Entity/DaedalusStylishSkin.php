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
use Drupal\daedalus_blueprint\DesignSystem\DesignSystemInterface;
use Drupal\daedalus_blueprint\Entity\DesignSystem;
use Drupal\daedalus_blueprint\Entity\DesignSystemMemberTrait;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinAnimationsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinDefaultsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinFontsItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\SkinTokensItem;
use Drupal\daedalus_stylish\Plugin\Field\FieldType\StyleMapItem;
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
 * A skin has no add or edit form. It is authored on the canvas, in Edit
 * Mode's Design system place, through the same operations the AI submits:
 * tokens, animations and fonts as materials (save_material,
 * update_material, remove_material with kind token, animation or font)
 * and defaults through update_default, and its
 * lifecycle (choose, copy, restore, revert) through SkinManager's routes.
 * The admin routes that remain are the skin list (/admin/appearance/skins)
 * and the delete form. Delivery (the compiled skin stylesheet) is a
 * separate concern and reads none of them.
 *
 * A skin is two layers on one entity. The PACKAGE layer (tokens,
 * animations, fonts, defaults) is what a module ships: the disk lane's
 * fields, the only ones the importer writes and the shipper reads, the
 * payload the source hash covers. The SITE layer (site_tokens,
 * site_animations, site_fonts, site_defaults) holds what the site added
 * or changed over it, by name down to the fact (a token's value, an
 * animation's steps, a font's faces, a description or offered) or the
 * property (a default): a package
 * update lands in its own layer and shows through wherever the site
 * changed nothing, and removing a site entry reveals the package's. Every
 * reader but the disk lane reads the merge (tokens(), animations(),
 * fonts(), defaults()). A skin
 * with no provider is the site's own and has one layer, the package one,
 * edited directly. Both layers are revisionable and the identity fields
 * are not, so a revision revert changes what either layer says and never
 * what the skin is.
 *
 * A member skin is born wearable: the site's own skin that joins a design
 * system and brings no defaults starts from the defaults the system's
 * default skin wears (preSave(); see inheritBirthDefaults()), because a
 * skin of tokens alone renders a page bare, with no gutter and no heading
 * scale, every one of those living in defaults.
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
      'delete' => ContentEntityDeleteForm::class,
    ],
    'route_provider' => [
      'html' => AdminHtmlRouteProvider::class,
    ],
  ],
  links: [
    'collection' => '/admin/appearance/skins',
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
      ->setDescription(new TranslatableMarkup("The design tokens: {scale: {name: {value, description, offered}}} against the site's declared token scales, each value a string or a condition-keyed map with a 'default' entry; a bare value is shorthand for {value}."))
      ->setRevisionable(TRUE);

    $fields['animations'] = BaseFieldDefinition::create('daedalus_skin_animations')
      ->setLabel(new TranslatableMarkup('Animations'))
      ->setDescription(new TranslatableMarkup("The skin's motion: {name: {steps, description, offered}}, each steps map keyframe offsets ('0%', '50%', '100%') to CSS properties; a bare steps map is shorthand for {steps}. Delivered as @keyframes motion-{name} in the compiled skin file."))
      ->setRevisionable(TRUE);

    $fields['fonts'] = BaseFieldDefinition::create('daedalus_skin_fonts')
      ->setLabel(new TranslatableMarkup('Fonts'))
      ->setDescription(new TranslatableMarkup("The skin's type: {name: {family, faces, description, offered}}, each faces list naming an uploaded font file per weight and style ({weight, style, source: {file: uuid}}). Delivered as one @font-face per face at the head of the compiled skin file; a font-family stack names the family quoted."))
      ->setRevisionable(TRUE);

    $fields['defaults'] = BaseFieldDefinition::create('daedalus_skin_defaults')
      ->setLabel(new TranslatableMarkup('Site defaults'))
      ->setDescription(new TranslatableMarkup('The site element defaults: {scope: element-relative styles} with LIVE semantics — they restyle existing placements through the compiled skin stylesheet, and per-entity style maps beat them by cascade.'))
      ->setRevisionable(TRUE);

    // The site layer: what the site added or changed over the package
    // layer, merged per token fact (site_tokens) and per property
    // (site_defaults). Internal, deliberately: the blueprint's generic
    // field spine skips internal fields, so its whole-field handlers
    // (which canonicalize to full records and validate a whole map) never
    // reach the layer; its doors are the token operations and
    // update_default, which write through the entity.
    $fields['site_tokens'] = BaseFieldDefinition::create('daedalus_skin_site_tokens')
      ->setLabel(new TranslatableMarkup('Site tokens'))
      ->setDescription(new TranslatableMarkup("The site's layer over the shipped tokens: {scale: {name: the facts the site changed or the record it minted}}; an unstated fact shows through from the package layer."))
      ->setRevisionable(TRUE)
      ->setInternal(TRUE);

    $fields['site_animations'] = BaseFieldDefinition::create('daedalus_skin_site_animations')
      ->setLabel(new TranslatableMarkup('Site animations'))
      ->setDescription(new TranslatableMarkup("The site's layer over the shipped animations: {name: the facts the site changed or the record it minted}; an unstated fact shows through from the package layer."))
      ->setRevisionable(TRUE)
      ->setInternal(TRUE);

    $fields['site_fonts'] = BaseFieldDefinition::create('daedalus_skin_site_fonts')
      ->setLabel(new TranslatableMarkup('Site fonts'))
      ->setDescription(new TranslatableMarkup("The site's layer over the shipped fonts: {name: the facts the site changed or the record it minted}; an unstated fact shows through from the package layer."))
      ->setRevisionable(TRUE)
      ->setInternal(TRUE);

    $fields['site_defaults'] = BaseFieldDefinition::create('daedalus_skin_defaults')
      ->setLabel(new TranslatableMarkup('Site layer defaults'))
      ->setDescription(new TranslatableMarkup("The site's layer over the shipped defaults, the same {scope: element-relative styles} grammar; a site property replaces the package's, everything else shows through."))
      ->setRevisionable(TRUE)
      ->setInternal(TRUE);

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
      ->setDescription(new TranslatableMarkup("Hash of the shipped {tokens, animations, fonts, defaults} payload (the package layer, tokens, animations and fonts as records, each font face named by the sha256 of its file) at the last import or sync. Sync compares it against the current disk payload (defaults changed?); the ship lane compares it against the entity's package layer (edited under the developer gate?)."))
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
    if ($this->isNew()) {
      $this->inheritBirthDefaults();
    }
  }

  /**
   * A new member skin starts from its system's default skin's defaults.
   *
   * The gutter, the heading scale and every base-tag rule live in a
   * skin's defaults, so a skin of tokens alone is wearable in name only:
   * a page built under it renders bare. The site's own skin (no provider)
   * that joins a design system and brings no defaults of its own is born
   * with the defaults the system's default skin wears, both layers
   * merged, the look the site has; every road to a new skin (the AI's
   * create door, the skin card's copy of a defaults-less skin, a test)
   * passes here, and re-valuing them afterwards is update_default's job
   * as on any skin. A shipped skin's defaults are its package's, even
   * when empty; a global skin has no system to inherit from; a system
   * whose default skin is this skin, or is not minted on the site, gives
   * nothing.
   */
  protected function inheritBirthDefaults(): void {
    if ($this->getProvider() !== NULL || $this->packageDefaults() !== []) {
      return;
    }
    $system_id = $this->getDesignSystem();
    if ($system_id === NULL) {
      return;
    }
    $system = $this->entityTypeManager()->getStorage(DesignSystem::ENTITY_TYPE_ID)->load($system_id);
    $default = $system instanceof DesignSystemInterface ? (string) $system->getDefaultSkin() : '';
    if ($default === '' || $default === $this->getMachineName()) {
      return;
    }
    $sources = $this->entityTypeManager()->getStorage('daedalus_skin')->loadByProperties(['machine_name' => $default]);
    $source = reset($sources);
    if (!$source instanceof DaedalusStylishSkinInterface) {
      return;
    }
    $defaults = $source->defaults();
    if ($defaults !== []) {
      $this->setPackageDefaults($defaults);
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
  public function packageTokens(): array {
    return $this->layer('tokens');
  }

  /**
   * {@inheritdoc}
   */
  public function setPackageTokens(array $tokens): static {
    return $this->setLayer('tokens', $tokens);
  }

  /**
   * {@inheritdoc}
   */
  public function siteTokens(): array {
    return $this->layer('site_tokens');
  }

  /**
   * {@inheritdoc}
   */
  public function setSiteTokens(array $tokens): static {
    return $this->setLayer('site_tokens', $tokens);
  }

  /**
   * {@inheritdoc}
   */
  public function tokens(): array {
    return SkinTokensItem::mergeLayers($this->packageTokens(), $this->siteTokens());
  }

  /**
   * {@inheritdoc}
   */
  public function packageAnimations(): array {
    return $this->layer('animations');
  }

  /**
   * {@inheritdoc}
   */
  public function setPackageAnimations(array $animations): static {
    return $this->setLayer('animations', $animations);
  }

  /**
   * {@inheritdoc}
   */
  public function siteAnimations(): array {
    return $this->layer('site_animations');
  }

  /**
   * {@inheritdoc}
   */
  public function setSiteAnimations(array $animations): static {
    return $this->setLayer('site_animations', $animations);
  }

  /**
   * {@inheritdoc}
   */
  public function animations(): array {
    return SkinAnimationsItem::mergeLayers($this->packageAnimations(), $this->siteAnimations());
  }

  /**
   * {@inheritdoc}
   */
  public function packageFonts(): array {
    return $this->layer('fonts');
  }

  /**
   * {@inheritdoc}
   */
  public function setPackageFonts(array $fonts): static {
    return $this->setLayer('fonts', $fonts);
  }

  /**
   * {@inheritdoc}
   */
  public function siteFonts(): array {
    return $this->layer('site_fonts');
  }

  /**
   * {@inheritdoc}
   */
  public function setSiteFonts(array $fonts): static {
    return $this->setLayer('site_fonts', $fonts);
  }

  /**
   * {@inheritdoc}
   */
  public function fonts(): array {
    return SkinFontsItem::mergeLayers($this->packageFonts(), $this->siteFonts());
  }

  /**
   * {@inheritdoc}
   */
  public function packageDefaults(): array {
    return $this->layer('defaults');
  }

  /**
   * {@inheritdoc}
   */
  public function setPackageDefaults(array $defaults): static {
    return $this->setLayer('defaults', $defaults);
  }

  /**
   * {@inheritdoc}
   */
  public function siteDefaults(): array {
    return $this->layer('site_defaults');
  }

  /**
   * {@inheritdoc}
   */
  public function setSiteDefaults(array $defaults): static {
    return $this->setLayer('site_defaults', $defaults);
  }

  /**
   * {@inheritdoc}
   */
  public function defaults(): array {
    return SkinDefaultsItem::mergeLayers($this->packageDefaults(), $this->siteDefaults());
  }

  /**
   * One layer field's stored map, empty when the field is.
   */
  protected function layer(string $field): array {
    $item = $this->get($field)->first();
    return $item instanceof StyleMapItem ? $item->getStyleMap() : [];
  }

  /**
   * Writes one layer field through its item, which canonicalizes.
   */
  protected function setLayer(string $field, array $map): static {
    $list = $this->get($field);
    $item = $list->first() ?? $list->appendItem();
    assert($item instanceof StyleMapItem);
    $item->setStyleMap($map);
    return $this;
  }

  /**
   * {@inheritdoc}
   *
   * The shipped payload is the PACKAGE layer, everything a skins file
   * ships — tokens, animations, fonts AND defaults — under stable keys, so
   * adding a tier never silently changes the hash of a tier-less skin ({}
   * hashes the same under both shapes only if the shape is keyed; hence
   * the explicit map). The site layer is outside it: a site's additions
   * and changes are not a modification of what the package shipped.
   *
   * The fonts tier hashes CONTENT-ADDRESSED (FontFiles::contentAddressed():
   * each face's source {hash: sha256 of its bytes} in place of {file:
   * uuid}): the site holds a face's file by uuid and a module by basename
   * under assets/fonts/, and neither name crosses the disk lane, so the
   * importer hashes the module's assets into the same shape
   * (SkinImporter::shippedFonts()) and one stored hash still answers both
   * questions; swapping a face's bytes on either side reads as a change.
   */
  protected function shippedData(): array {
    return [
      'tokens' => $this->packageTokens(),
      'animations' => $this->packageAnimations(),
      'fonts' => \Drupal::service('daedalus_stylish.font_files')->contentAddressed($this->packageFonts()),
      'defaults' => $this->packageDefaults(),
    ];
  }

}
