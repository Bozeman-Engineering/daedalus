<?php

declare(strict_types=1);

namespace Drupal\Tests\daedalus_edit\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\daedalus_blueprint_api\Ajax\BlueprintApplyResponseCommand;
use Drupal\daedalus_blueprint_api\Render\SelectableKey;
use Drupal\daedalus_edit\Form\PanelUpdateBlockForm;
use Drupal\daedalus_edit\FormCommit;
use Drupal\daedalus_edit\FormCommitOutcome;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the form transport's server half: one property, one subject, one spine.
 *
 * The entity form hosted in the edit panel (the node form through
 * InlineEntityFormAlter, the block form through PanelUpdateBlockForm)
 * is a widget host; its own submit is the transport. Under test is what a
 * submit naming a property (FormCommit::FIELD_INPUT) does: the real form is
 * submitted programmatically, so every site alter, widget extraction and
 * validation runs, and then exactly the named property reaches the spine as
 * an ExtractedValue (or the placement label's text), addressed to the
 * translation the form is for; the AJAX callback answers the standard
 * mutation-response payload as one daedalusBlueprintApplyResponse command,
 * built under the edit-mode response convention so the page title's H1 rides
 * it by key; a form validation error answers the rebuilt form and writes
 * nothing.
 *
 * @group daedalus_edit
 */
#[RunTestsInSeparateProcesses]
class FormCommitTest extends FormCommitTestBase {

  /**
   * The entity form commits exactly the named field, and answers the contract.
   */
  public function testEntityFormCommitsOneField(): void {
    $node = $this->createPlainNode();
    [$form_object, $form_state] = $this->nodeFormState($this->working($node), [
      'title' => [['value' => 'Changed']],
      'field_subtitle' => [['value' => 'Also changed']],
      FormCommit::FIELD_INPUT => 'title',
    ]);
    $response = $this->submitAndAnswer($form_object, $form_state, $this->formRequest($node, '/daedalus-edit/entity-form/node/' . $node->id()));

    // One field per commit: the form built both changes, the spine got one.
    $working = $this->working($node);
    $this->assertSame('Changed', $working->getTitle());
    $this->assertSame('Sub', $working->get('field_subtitle')->value, 'The other field the form built is discarded.');
    $this->assertSame('Saved', $this->stored($node)->getTitle(), 'Staging never saves.');

    $outcome = $form_state->getTemporaryValue(FormCommit::OUTCOME);
    $this->assertInstanceOf(FormCommitOutcome::class, $outcome);
    $this->assertTrue($outcome->result->isSuccess(), print_r($outcome->result->toArray(), TRUE));
    $this->assertSame('tempstore', $outcome->persisted);
    $targets = [['uuid' => $node->uuid(), 'field_name' => 'title']];
    $this->assertSame($targets, $outcome->result->getTargets());

    // The answer: the mutation-response payload as one command.
    $payload = $this->appliedPayload($response);
    $this->assertTrue($payload['success']);
    $this->assertSame('node', $payload['entity_type']);
    $this->assertSame('en', $payload['langcode']);
    $this->assertSame($targets, $payload['targets']);
    $this->assertSame('tempstore', $payload['persisted']);
    $this->assertArrayHasKey('blueprint', $payload);

    // History rides the payload, annotated: the entry is labeled by the
    // field and targeted so undo swaps the field, not the whole entity.
    $entries = $payload['history']['entries'];
    $latest = end($entries);
    $this->assertSame('Edited Title', $latest['label']);
    $this->assertSame($targets, $latest['targets']);
    $this->assertFalse($latest['baseline']);

    // Built under the edit-mode response convention: the page title's H1
    // comes back under the label key (a swap), not as the unattributed
    // warning.
    $this->assertSame([], $payload['warnings']);
    $label_key = SelectableKey::field($node->uuid(), 'title');
    $this->assertArrayHasKey($label_key, $payload['markup']);
    $this->assertStringStartsWith('<h1', $payload['markup'][$label_key]);
    $this->assertStringContainsString('Changed', $payload['markup'][$label_key]);
  }

  /**
   * A form validation error answers the rebuilt form and writes nothing.
   */
  public function testValidationErrorWritesNothing(): void {
    $node = $this->createPlainNode();
    [$form_object, $form_state] = $this->nodeFormState($this->working($node), [
      'title' => [['value' => str_repeat('x', 300)]],
      FormCommit::FIELD_INPUT => 'title',
    ]);
    $response = $this->submitAndAnswer($form_object, $form_state, $this->formRequest($node, '/daedalus-edit/entity-form/node/' . $node->id()));

    $this->assertTrue($form_state->hasAnyErrors(), 'The title length constraint failed the form.');
    $this->assertNull($form_state->getTemporaryValue(FormCommit::OUTCOME), 'The submit handler never ran.');
    $this->assertSame('Saved', $this->working($node)->getTitle(), 'Nothing was staged.');
    $this->assertSame([], $this->container->get('daedalus_tempstore.history_manager')->metadata($this->workingHost($node))['entries'], 'No history entry was produced.');

    $commands = $response->getCommands();
    $names = array_column($commands, 'command');
    $this->assertNotContains(BlueprintApplyResponseCommand::COMMAND, $names, 'No mutation payload is answered.');
    $replaces = array_values(array_filter($commands, fn (array $command) => $command['command'] === 'insert' && $command['method'] === 'replaceWith'));
    $this->assertCount(1, $replaces, 'The rebuilt form replaces the cached one.');
    $this->assertSame('#daedalus-edit-form_node-' . $node->id(), $replaces[0]['selector']);
    $html = (string) $replaces[0]['data'];
    $this->assertStringContainsString('data-daedalus-edit-form-id="node::' . $node->id() . '"', $html);
    $this->assertStringContainsString('error', $html, 'The rebuilt form carries its validation error.');
    $this->assertContains('editModeMessage', $names, 'The validation message is delivered.');
  }

  /**
   * The block form commits one field, then the placement label, by component.
   */
  public function testBlockFormCommitsOneProperty(): void {
    $node = $this->createPageNode();
    $path = '/daedalus-edit/block-form/overrides/node.' . $node->id() . '.default/' . self::BLOCK_UUID;

    $form_state = (new FormState())->addBuildInfo('args', [
      $this->blockFormStorage($node),
      0,
      'content',
      self::BLOCK_UUID,
    ]);
    $form_state->setValues([
      'settings' => [
        'label' => 'My Block',
        'block_form' => [
          'field_fb_text' => [['value' => 'New']],
          'field_fb_other' => [['value' => 'Changed too']],
        ],
      ],
      FormCommit::FIELD_INPUT => 'field_fb_text',
      'op' => 'Update',
    ]);
    $response = $this->submitAndAnswer(PanelUpdateBlockForm::class, $form_state, $this->formRequest($node, $path));

    $targets = [['uuid' => self::BLOCK_UUID, 'field_name' => 'field_fb_text']];
    $outcome = $form_state->getTemporaryValue(FormCommit::OUTCOME);
    $this->assertInstanceOf(FormCommitOutcome::class, $outcome);
    $this->assertTrue($outcome->result->isSuccess(), print_r($outcome->result->toArray(), TRUE));
    $this->assertSame($targets, $outcome->result->getTargets());
    $block = $this->workingBlock($node);
    $this->assertSame('New', $block->get('field_fb_text')->value);
    $this->assertSame('Keep', $block->get('field_fb_other')->value, 'The other field the form built is discarded.');

    $payload = $this->appliedPayload($response);
    $this->assertTrue($payload['success']);
    $this->assertSame($node->uuid(), $payload['entity_uuid'], 'The payload describes the root the block lives in.');
    $this->assertSame($targets, $payload['targets']);
    $entries = $payload['history']['entries'];
    $this->assertSame('Edited Text', end($entries)['label']);
    $this->assertSame($targets, end($entries)['targets']);

    // The placement label: the spine's `label` property, from a fresh form
    // over the staged layout, as the next request would build it.
    $form_state = (new FormState())->addBuildInfo('args', [
      $this->blockFormStorage($node),
      0,
      'content',
      self::BLOCK_UUID,
    ]);
    $form_state->setValues([
      'settings' => [
        'label' => 'Renamed',
        'block_form' => ['field_fb_text' => [['value' => 'Ignored']]],
      ],
      FormCommit::FIELD_INPUT => 'label',
      'op' => 'Update',
    ]);
    $response = $this->submitAndAnswer(PanelUpdateBlockForm::class, $form_state, $this->formRequest($node, $path));

    $this->assertSame('Renamed', $this->workingComponent($node)->get('configuration')['label']);
    $this->assertSame('New', $this->workingBlock($node)->get('field_fb_text')->value, 'A label commit writes no field.');
    $payload = $this->appliedPayload($response);
    $this->assertTrue($payload['success']);
    $this->assertSame([['uuid' => self::BLOCK_UUID, 'field_name' => 'label']], $payload['targets']);
    $entries = $payload['history']['entries'];
    $this->assertSame('Edited Title', end($entries)['label']);
  }

  /**
   * A translation's form commits the translation, and only it.
   */
  public function testTranslationFormCommitsTheTranslation(): void {
    $node = $this->createPlainNode();
    $node->addTranslation('es', ['title' => 'Guardado', 'field_subtitle' => 'Sub'])->save();

    [$form_object, $form_state] = $this->nodeFormState($this->working($node)->getTranslation('es'), [
      'title' => [['value' => 'Cambiado']],
      FormCommit::FIELD_INPUT => 'title',
    ]);
    // The form langcode is negotiated from the request's content language
    // (ContentEntityForm::initFormLangcodes), which the client's fetch URL
    // carries as its language prefix; here it is named on the form state,
    // the seam core offers for exactly that.
    $form_state->set('langcode', 'es');
    $response = $this->submitAndAnswer($form_object, $form_state, $this->formRequest($node, '/daedalus-edit/entity-form/node/' . $node->id()));

    $outcome = $form_state->getTemporaryValue(FormCommit::OUTCOME);
    $this->assertInstanceOf(FormCommitOutcome::class, $outcome);
    $this->assertTrue($outcome->result->isSuccess(), print_r($outcome->result->toArray(), TRUE));
    $this->assertSame('es', $outcome->langcode);
    $working = $this->working($node);
    $this->assertSame('Cambiado', $working->getTranslation('es')->getTitle());
    $this->assertSame('Saved', $working->getTitle(), 'The default translation is untouched.');
    $stored = $this->stored($node);
    $this->assertSame('Guardado', $stored->getTranslation('es')->getTitle());
    $payload = $this->appliedPayload($response);
    $this->assertTrue($payload['success']);
    $this->assertSame('es', $payload['langcode']);
    $this->assertSame([['uuid' => $node->uuid(), 'field_name' => 'title']], $payload['targets']);
  }

}
