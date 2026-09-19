<?php

namespace Drupal\daedalus_edit\Drush\Commands;

use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Extension\ModuleExtensionList;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Update CKEditor Inline Editor.
 */
final class UpdateInlineEditor extends DrushCommands {

  public function __construct(
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('extension.list.module'),
    );
  }

  /**
   * Update version.
   */
  #[CLI\Command(name: 'daedalus_edit:update-ckeditor-version', aliases: ['dd-edit-update'])]
  #[CLI\Usage(name: 'dd-edit-update', description: 'Extracts the ckeditor version that core is currently using and updates the daedalus package.json.')]
  public function updateVersion() {
    $cores_packages = Json::decode(file_get_contents(DRUPAL_ROOT . '/core/package.json'));
    $ckeditor5_version = $cores_packages['devDependencies']['ckeditor5'];
    $daedalus_path = $this->moduleExtensionList->getPath('daedalus');
    $package_json_path = DRUPAL_ROOT . "/$daedalus_path/package.json";
    $daedalus_packages = Json::decode(file_get_contents($package_json_path));
    $daedalus_packages['devDependencies']['@ckeditor/ckeditor5-editor-inline'] = $ckeditor5_version;
    file_put_contents($package_json_path, json_encode($daedalus_packages, JSON_PRETTY_PRINT));
    $this->logger()->success(dt("Updated @ckeditor/ckeditor5-editor-inline in daedalus/package.json to $ckeditor5_version."));
  }

  /**
   * Move inline editor library.
   */
  #[CLI\Command(name: 'daedalus_edit:move-library', aliases: ['dd-edit-move'])]
  #[CLI\Usage(name: 'dd-edit-move', description: 'Extracts the inline editor library from node_modules and moves it to assets/vendor.')]
  public function moveLibrary() {
    $daedalus_path = $this->moduleExtensionList->getPath('daedalus');
    $daedalus_edit_path = $this->moduleExtensionList->getPath('daedalus_edit');
    $from_library_path = DRUPAL_ROOT . "/$daedalus_path/node_modules/@ckeditor/ckeditor5-editor-inline/build/editor-inline.js";
    $to_library_path = DRUPAL_ROOT . "/$daedalus_edit_path/assets/vendor/ckeditor5/editor-inline.js";
    copy($from_library_path, $to_library_path);
    $this->logger()->success(dt("Moved @ckeditor/ckeditor5-editor-inline library to $to_library_path."));
  }

}
