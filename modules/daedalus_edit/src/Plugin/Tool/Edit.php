<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit\Plugin\Tool;

use Drupal\daedalus_ui\Attribute\Tool;
use Drupal\daedalus_ui\ToolPluginBase;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\daedalus_edit\IdentityMinter;
use Drupal\daedalus_edit\LinkAutocomplete;
use Drupal\editor\Plugin\EditorManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The Edit tool — the armed edit session's toolbar entrance.
 *
 * Activating it arms a page-wide session where clicks resolve to the exact
 * editable leaf under the cursor. The session itself lives in the
 * daedalus_ui app; this module registers the editing engine.
 *
 * @see daedalus_edit/js/daedalus_edit/edit-session-engine.js
 */
#[Tool(
  id: 'edit',
  label: new TranslatableMarkup('Edit'),
  hot_key: 'e',
  weight: 40,
)]
final class Edit extends ToolPluginBase {

  public function __construct(
    ContainerInterface $container,
    array $configuration,
    $plugin_id,
    $plugin_definition,
    ModuleExtensionList $extensionList,
    protected IdentityMinter $identityMinter,
    protected LinkAutocomplete $linkAutocomplete,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EditorManager $editorManager,
  ) {
    parent::__construct($container, $configuration, $plugin_id, $plugin_definition, $extensionList);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container,
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('extension.list.module'),
      $container->get('daedalus_edit.identity_minter'),
      $container->get('daedalus_edit.link_autocomplete'),
      $container->get('entity_type.manager'),
      $container->get('plugin.manager.editor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getIconsPath(): array {
    $path = $this->extensionList->getPath('daedalus_edit');
    return [
      'pack_id' => 'daedalus_edit',
      'icon_id' => 'pencil',
      'mouse_icon' => "url('/$path/assets/edit-tool-mouse.svg') 3 3",
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function addAttachments(array &$attachments): void {
    $attachments['library'][] = 'daedalus_edit/library';
    // The eager-identity contract the canvas CKEditor plugin mirrors live
    // (vocabulary, class shape, id alphabet): delivered from the one
    // server truth (IdentityMinter, whose vocabulary is the spine's
    // instances declaration) so the client carries no copies.
    $attachments['drupalSettings']['daedalusEdit']['identity'] = $this->identityMinter->clientContract();
    // The composed link widget's suggestion endpoints: one minted core
    // autocomplete url per configured target type, per composed link
    // field. Attached session-wide because any composed block type can be
    // added mid-session.
    $attachments['drupalSettings']['daedalusEdit']['linkAutocomplete'] = $this->linkAutocomplete->settings();
    // Get libraries required for CKEditors.
    $formats = $this->entityTypeManager->getStorage('filter_format')->loadMultiple();
    unset($formats['webform_default']);
    $editor_attachments = $this->editorManager->getAttachments(array_keys($formats));
    $attachments = NestedArray::mergeDeep($attachments, $editor_attachments);
  }

}
