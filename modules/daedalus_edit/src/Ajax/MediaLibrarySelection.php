<?php

namespace Drupal\daedalus_edit\Ajax;

use Drupal\Core\Ajax\CommandInterface;

/**
 * Delivers a media library selection to the current blueprint edit.
 *
 * The client-side handler routes the selected ids to the editor of the
 * current edit (Drupal.Daedalus.Edit.CurrentlyEditingElement), which commits the
 * new target_id through the blueprint mutation spine.
 *
 * @see \Drupal\daedalus_edit\DaedalusEditMediaLibraryOpener::getSelectionResponse()
 * @see js/daedalus_edit/media-library-selection.js
 */
class MediaLibrarySelection implements CommandInterface {

  /**
   * Constructs a MediaLibrarySelection command.
   *
   * @param array $selectedIds
   *   The selected media entity ids.
   */
  public function __construct(
    protected array $selectedIds,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render() {
    return [
      'command' => 'daedalusEditMediaLibrarySelection',
      'selectedIds' => array_values($this->selectedIds),
    ];
  }

}
