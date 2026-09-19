<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_layout\Traits;

/**
 * Places blocks on a Layout Builder page via the real placement pipeline.
 *
 * Placing a block is a shared move across the page-builder behavior tests, so
 * this trait gathers it in daedalus_layout, the module that owns the placement
 * pipeline. It is meant for a WebDriverTestBase: the method relies on
 * getSession() and assertJsCondition() from the using class, and on a page
 * already opened in Edit Mode (so the edit-mode app and the blueprint mirror
 * are booted).
 */
trait PlaceBlockTrait {

  /**
   * Places a block on the current layout via the real placement pipeline.
   *
   * Fires the exact blueprint operation batch an Add panel caret drop enqueues,
   * minus the native HTML5 drag gesture that WebDriver cannot reliably
   * synthesize: the same grammar, through the same daedalus_blueprint_api
   * endpoint. Only the drag input is skipped; the behavior under test is
   * not. A client-named add_section goes inside the root entity (the "blank
   * page" destination) and the add_element lands in it.
   *
   * @param string $plugin_id
   *   The block plugin id to place (e.g. 'inline_block:basic').
   *
   * @return string
   *   The new component's UUID. The ops name it client-side (the batch
   *   grammar honors provided UUIDs), so callers can wait on the rendered
   *   element without racing the response.
   */
  protected function placeBlockViaTool(string $plugin_id): string {
    $session = $this->getSession();
    $uuid_service = \Drupal::service('uuid');
    $section_uuid = $uuid_service->generate();
    $component_uuid = $uuid_service->generate();

    // The Add panel normalizes the inline_block:{type} plugin-id spelling to a
    // block_type (the grammar rejects that spelling for plugin_id).
    if (str_starts_with($plugin_id, 'inline_block:')) {
      $kind = sprintf("block_type: '%s'", substr($plugin_id, strlen('inline_block:')));
    }
    else {
      $kind = sprintf("plugin_id: '%s'", $plugin_id);
    }

    $this->assertJsCondition("typeof Drupal !== 'undefined' && Drupal.Daedalus && Drupal.Daedalus.Blueprint && !!Drupal.Daedalus.Blueprint.getRoot()", 20000);

    $script = <<<JS
(function () {
  var Blueprint = Drupal.Daedalus.Blueprint;
  var context = Blueprint.rootContext();
  Blueprint.mutate(Object.assign({}, context, {
    operations: [
      {
        op: 'add_section',
        uuid: '$section_uuid',
        location: { anchor: Blueprint.getRoot().uuid, placement: 'inside' }
      },
      {
        op: 'add_element',
        uuid: '$component_uuid',
        $kind,
        populate: 'sample',
        location: { anchor: '$section_uuid', placement: 'inside' }
      }
    ]
  })).catch(function (error) {
    console.error('placeBlockViaTool failed: ' + error.message);
  });
})();
JS;
    $session->executeScript($script);

    return $component_uuid;
  }

}
