<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\daedalus_blueprint_api\Ajax\BlueprintApplyResponseCommand;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_edit\Form\ConfigureDialogFormAlterBase;
use Drupal\daedalus_edit\FormCommit;
use Drupal\daedalus_edit\FormCommitOutcome;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\layout_builder\Form\ConfigureSectionForm;
use Drupal\layout_builder\Form\UpdateBlockForm;
use Drupal\node\NodeInterface;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

/**
 * Tests the Configure dialog on the form transport.
 *
 * The Configure verb (daedalus_layout) opens core's UpdateBlockForm and
 * ConfigureSectionForm in the edit-mode dialog. Under test is what their
 * submit does once daedalus_edit's alters put them on the transport: the
 * real core form is submitted programmatically (its widgets, validation
 * and plugin submit run as core wrote them), every property the dialog
 * changed reaches the spine as ONE update_element on the subject (the
 * placement's label, Display title toggle, view mode and the block's
 * fields as ExtractedValues; a section's label and layout settings), the
 * AJAX callback answers the mutation payload as one
 * daedalusBlueprintApplyResponse command (the subject re-rendered by key,
 * never core's whole #layout-builder rebuild) plus the close of the
 * dialog, an untouched dialog writes nothing and still closes, and a form
 * validation error answers the rebuilt form with nothing written.
 *
 * @group daedalus_edit
 */
#[RunTestsInSeparateProcesses]
class ConfigureDialogCommitTest extends FormCommitTestBase {

  /**
   * A request shaped like the dialog form's AJAX submit.
   *
   * The dialog was opened through ModePluginBase.dialog() with the mode on
   * the query, which core's #ajax keeps on every submit; the core route
   * carries the section storage the response's root is derived from.
   */
  protected function dialogRequest(string $route_name, string $pattern, string $path, NestedSectionStorageInterface $storage, array $raw): Request {
    $request = $this->editModeRequest($path);
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, new Route($pattern, ['plugin_id' => NULL]));
    $request->attributes->set('section_storage', $storage);
    foreach ($raw as $name => $value) {
      $request->attributes->set($name, $value);
    }
    $request->attributes->set('_raw_variables', new InputBag([
      'section_storage_type' => 'overrides',
      'section_storage' => 'node.' . $raw['node'] . '.default',
    ] + $raw));
    return $request;
  }

  /**
   * The block dialog's request for a page node.
   */
  protected function blockDialogRequest(NodeInterface $node, NestedSectionStorageInterface $storage, string $region = 'content'): Request {
    return $this->dialogRequest(
      'layout_builder.update_block',
      '/layout_builder/update/block/{section_storage_type}/{section_storage}/{delta}/{region}/{uuid}',
      sprintf('/layout_builder/update/block/overrides/node.%d.default/0/%s/%s', $node->id(), $region, self::BLOCK_UUID),
      $storage,
      ['node' => $node->id(), 'delta' => 0, 'region' => $region, 'uuid' => self::BLOCK_UUID],
    );
  }

  /**
   * The section dialog's request for a page node.
   */
  protected function sectionDialogRequest(NodeInterface $node, NestedSectionStorageInterface $storage): Request {
    return $this->dialogRequest(
      'layout_builder.configure_section',
      '/layout_builder/configure/section/{section_storage_type}/{section_storage}/{delta}/{plugin_id}',
      sprintf('/layout_builder/configure/section/overrides/node.%d.default/0?section_uuid=%s', $node->id(), self::SECTION_UUID),
      $storage,
      ['node' => $node->id(), 'delta' => 0],
    );
  }

  /**
   * The block dialog's form state: core's build args and the given values.
   */
  protected function blockDialogState(NestedSectionStorageInterface $storage, array $settings, string $region = 'content'): FormState {
    $form_state = (new FormState())->addBuildInfo('args', [$storage, 0, $region, self::BLOCK_UUID]);
    $form_state->setValues(['settings' => $settings, 'op' => 'Update']);
    return $form_state;
  }

  /**
   * The dialog's answer: the apply command's payload, then the close.
   */
  protected function dialogPayload(AjaxResponse $response): array {
    $commands = $response->getCommands();
    $this->assertCount(2, $commands, 'The dialog answers the payload and the close.');
    $this->assertSame(BlueprintApplyResponseCommand::COMMAND, $commands[0]['command']);
    $this->assertSame('closeDialog', $commands[1]['command']);
    $this->assertSame(ConfigureDialogFormAlterBase::DIALOG_SELECTOR, $commands[1]['selector'], 'The close targets the dialog the verb opened.');
    return $commands[0]['payload'];
  }

  /**
   * The block dialog commits every changed property as one op on the component.
   */
  public function testBlockDialogCommitsChangedProperties(): void {
    // A second view mode with an enabled display, so the dialog offers the
    // view mode select (core hides it with a single option).
    EntityViewMode::create(['id' => 'block_content.teaser', 'targetEntityType' => 'block_content', 'label' => 'Teaser'])->save();
    EntityViewDisplay::create([
      'targetEntityType' => 'block_content',
      'bundle' => 'form_block',
      'mode' => 'teaser',
      'status' => TRUE,
    ])->setComponent('field_fb_text', ['type' => 'string'])->save();
    $node = $this->createPageNode();
    $storage = $this->blockFormStorage($node);

    // The dialog's every control touched: the label, the Display title
    // checkbox unchecked (absent from the input), the view mode, one block
    // field; the other block field left as staged.
    $form_state = $this->blockDialogState($storage, [
      'label' => 'Configured',
      'view_mode' => 'teaser',
      'block_form' => [
        'field_fb_text' => [['value' => 'Dialog text']],
        'field_fb_other' => [['value' => 'Keep']],
      ],
    ]);
    $response = $this->submitAndAnswer(UpdateBlockForm::class, $form_state, $this->blockDialogRequest($node, $storage));

    $outcome = $form_state->getTemporaryValue(FormCommit::OUTCOME);
    $this->assertInstanceOf(FormCommitOutcome::class, $outcome);
    $this->assertTrue($outcome->result->isSuccess(), print_r($outcome->result->toArray(), TRUE));
    $this->assertSame('tempstore', $outcome->persisted);
    $targets = [
      ['uuid' => self::BLOCK_UUID, 'field_name' => 'field_fb_text'],
      ['uuid' => self::BLOCK_UUID, 'field_name' => 'label'],
      ['uuid' => self::BLOCK_UUID, 'field_name' => 'label_display'],
      ['uuid' => self::BLOCK_UUID, 'field_name' => 'view_mode'],
    ];
    $this->assertSame($targets, $outcome->result->getTargets(), 'Exactly the changed properties were written.');

    // Staged, never saved: the placement values and the one changed field.
    $configuration = $this->workingComponent($node)->get('configuration');
    $this->assertSame('Configured', $configuration['label']);
    $this->assertSame('0', $configuration['label_display']);
    $this->assertSame('teaser', $configuration['view_mode']);
    $block = $this->workingBlock($node);
    $this->assertSame('Dialog text', $block->get('field_fb_text')->value);
    $this->assertSame('Keep', $block->get('field_fb_other')->value);
    $stored = $this->stored($node)->get('layout_builder__layout')->get(0)->section->getComponent(self::BLOCK_UUID)->get('configuration');
    $this->assertSame('My Block', $stored['label'], 'Staging never saves.');

    // The answer: the payload (the block re-rendered by key, no
    // #layout-builder rebuild), then the dialog's close.
    $payload = $this->dialogPayload($response);
    $this->assertTrue($payload['success']);
    $this->assertSame($node->uuid(), $payload['entity_uuid'], 'The payload describes the root the block lives in.');
    $this->assertSame($targets, $payload['targets']);
    $this->assertSame('tempstore', $payload['persisted']);
    // Every re-rendered subtree is the block's own (a placement value has
    // no stamped element of its own and degrades to the block root): never
    // core's #layout-builder rebuild, never a whole-entity swap.
    $this->assertNotEmpty($payload['markup']);
    foreach (array_keys($payload['markup']) as $key) {
      $this->assertStringStartsWith(SelectableKey::block(self::BLOCK_UUID), $key, "Swapped by the block's key: $key");
    }
    $entries = $payload['history']['entries'];
    $latest = end($entries);
    $this->assertSame('Configured My Block', $latest['label']);
    $this->assertSame($targets, $latest['targets']);
  }

  /**
   * An untouched dialog writes nothing, records nothing, and still closes.
   */
  public function testBlockDialogUntouchedWritesNothing(): void {
    $node = $this->createPageNode();
    $storage = $this->blockFormStorage($node);

    $form_state = $this->blockDialogState($storage, [
      'label' => 'My Block',
      'label_display' => 'visible',
      'block_form' => [
        'field_fb_text' => [['value' => 'Original']],
        'field_fb_other' => [['value' => 'Keep']],
      ],
    ]);
    $response = $this->submitAndAnswer(UpdateBlockForm::class, $form_state, $this->blockDialogRequest($node, $storage));

    $outcome = $form_state->getTemporaryValue(FormCommit::OUTCOME);
    $this->assertInstanceOf(FormCommitOutcome::class, $outcome);
    $this->assertTrue($outcome->result->isSuccess(), print_r($outcome->result->toArray(), TRUE));
    $this->assertSame([], $outcome->result->getTargets());
    $this->assertNull($outcome->persisted, 'Nothing was staged.');
    $this->assertSame([], $this->container->get('daedalus_tempstore.history_manager')->metadata($node)['entries'], 'No history entry was produced.');
    $payload = $this->dialogPayload($response);
    $this->assertTrue($payload['success']);
    $this->assertSame([], $payload['targets']);
  }

  /**
   * A form validation error answers the rebuilt form in the dialog, no write.
   */
  public function testBlockDialogValidationErrorWritesNothing(): void {
    $node = $this->createPageNode();
    $storage = $this->blockFormStorage($node);

    $form_state = $this->blockDialogState($storage, [
      'label' => 'Configured',
      'label_display' => 'visible',
      'block_form' => [
        'field_fb_text' => [['value' => str_repeat('x', 300)]],
        'field_fb_other' => [['value' => 'Keep']],
      ],
    ]);
    $response = $this->submitAndAnswer(UpdateBlockForm::class, $form_state, $this->blockDialogRequest($node, $storage));

    $this->assertTrue($form_state->hasAnyErrors(), 'The string length constraint failed the form.');
    $this->assertNull($form_state->getTemporaryValue(FormCommit::OUTCOME), 'The submit handler never ran.');
    $this->assertSame('My Block', $this->workingComponent($node)->get('configuration')['label'], 'Nothing was staged.');
    $commands = $response->getCommands();
    $names = array_column($commands, 'command');
    $this->assertNotContains(BlueprintApplyResponseCommand::COMMAND, $names, 'No mutation payload is answered.');
    $this->assertNotContains('closeDialog', $names, 'The dialog stays open.');
    $this->assertCount(1, $commands);
    $this->assertSame('insert', $commands[0]['command']);
    $this->assertSame('[data-drupal-selector="layout-builder-update-block"]', $commands[0]['selector'], 'The rebuilt form replaces itself in place.');
    $this->assertStringContainsString('error', (string) $commands[0]['data'], 'The rebuilt form carries its validation error.');
  }

  /**
   * The section dialog commits the label and the changed layout settings.
   */
  public function testSectionDialogCommitsLayoutSettings(): void {
    $node = $this->createPageNode('layout_twocol_section', 'first');
    $storage = $this->blockFormStorage($node);

    $form_state = (new FormState())->addBuildInfo('args', [$storage, 0, NULL]);
    $form_state->setValues([
      'layout_settings' => [
        'label' => 'Hero',
        'column_widths' => '33-67',
      ],
      'op' => 'Update',
    ]);
    $response = $this->submitAndAnswer(ConfigureSectionForm::class, $form_state, $this->sectionDialogRequest($node, $storage));

    $outcome = $form_state->getTemporaryValue(FormCommit::OUTCOME);
    $this->assertInstanceOf(FormCommitOutcome::class, $outcome);
    $this->assertTrue($outcome->result->isSuccess(), print_r($outcome->result->toArray(), TRUE));
    $targets = [
      ['uuid' => self::SECTION_UUID, 'field_name' => 'label'],
      ['uuid' => self::SECTION_UUID, 'field_name' => 'layout_settings'],
    ];
    $this->assertSame($targets, $outcome->result->getTargets());
    $settings = $this->workingSection($node)->getLayoutSettings();
    $this->assertSame('Hero', $settings['label']);
    $this->assertSame('33-67', $settings['column_widths']);
    $this->assertSame('50-50', $this->stored($node)->get('layout_builder__layout')->get(0)->section->getLayoutSettings()['column_widths'], 'Staging never saves.');

    $payload = $this->dialogPayload($response);
    $this->assertTrue($payload['success']);
    $this->assertSame($targets, $payload['targets']);
    $this->assertArrayHasKey(SelectableKey::container(self::SECTION_UUID), $payload['markup'], 'The section re-renders by its container key.');
    $entries = $payload['history']['entries'];
    $latest = end($entries);
    $this->assertSame('Configured Two column', $latest['label']);
    $this->assertSame($targets, $latest['targets']);
    $entry_count = count($entries);

    // Untouched next time, from a fresh form over the staged layout (as
    // the next request would build it): the label is its own property,
    // the settings patch omits what did not change, nothing is written.
    $storage = $this->blockFormStorage($node);
    $form_state = (new FormState())->addBuildInfo('args', [$storage, 0, NULL]);
    $form_state->setValues([
      'layout_settings' => ['label' => 'Hero', 'column_widths' => '33-67'],
      'op' => 'Update',
    ]);
    $response = $this->submitAndAnswer(ConfigureSectionForm::class, $form_state, $this->sectionDialogRequest($node, $storage));
    $outcome = $form_state->getTemporaryValue(FormCommit::OUTCOME);
    $this->assertInstanceOf(FormCommitOutcome::class, $outcome);
    $this->assertSame([], $outcome->result->getTargets());
    $this->assertNull($outcome->persisted);
    $this->assertCount($entry_count, $this->dialogPayload($response)['history']['entries'], 'No second history entry.');
  }

}
