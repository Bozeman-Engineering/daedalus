<?php

declare(strict_types=1);

namespace Drupal\daedalus_edit;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\daedalus_blueprint\BlueprintOperationsInterface;
use Drupal\daedalus_blueprint\EntityStorageHandler;
use Drupal\daedalus_blueprint_api\Ajax\BlueprintApplyResponseCommand;
use Drupal\daedalus_blueprint_api\MutationResponseBuilder;
use Drupal\daedalus_layout\NestedSectionStorageInterface;
use Drupal\daedalus_tempstore\History\HistoryManager;
use Drupal\daedalus_tempstore\History\HistoryRecorder;
use Drupal\daedalus_ui\Message\EditModeAjaxMessages;
use Drupal\layout_builder\SectionStorageInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The form transport's server half: one property of one subject, on the spine.
 *
 * A Drupal form hosted by the edit mode (the entity form in the panel, the
 * Configure dialog's core Layout Builder forms) is a widget host and value
 * extractor, never a write path. Its own AJAX submit stays the transport
 * (full Drupal form semantics: form cache, uploads, multi-step widgets,
 * site validate and submit handlers), and this service is where the
 * extracted values meet the op spine: the submit handler hands a property
 * bag, each entry an ExtractedValue off the form-built entity or a plain
 * placement value (the label, a configuration key, a section's layout
 * settings), to BlueprintOperations::batchOperations() as ONE update_element
 * on the subject's root working copy, persists through the storage handler,
 * annotates the history entry, and the AJAX callback answers the standard
 * mutation-response payload as one BlueprintApplyResponseCommand. A panel
 * form commits exactly the one property the client named (FIELD_INPUT);
 * a Configure dialog commits every property the dialog changed. Anything
 * else the form built is discarded.
 *
 * Subjects are entity-generic: the root entity (element = its uuid), a
 * block component or a section (element = its uuid), whichever the form
 * edits. The commit runs with the translation the form is for as the batch
 * langcode, so the named translation is what changes.
 *
 * Form validation errors never reach here: the form builder skips the
 * submit handlers, and the callback answers the rebuilt form (rejected()).
 */
final class FormCommit {

  /**
   * The request input naming the property to commit.
   *
   * Sent beside the form's own fields by the client transport (a plain POST
   * parameter, never a form element: the server builds no chrome for it).
   * Absent, the submit is not a form-transport commit.
   */
  public const FIELD_INPUT = 'daedalus_edit_commit_field';

  /**
   * The form-state temporary value the submit handler leaves for the callback.
   */
  public const OUTCOME = 'daedalus_edit_form_commit';

  public function __construct(
    private readonly EntityStorageHandler $storageHandler,
    private readonly BlueprintOperationsInterface $operations,
    private readonly MutationResponseBuilder $responseBuilder,
    private readonly HistoryRecorder $historyRecorder,
    private readonly HistoryManager $historyManager,
    private readonly EditModeAjaxMessages $messages,
  ) {}

  /**
   * The property a submit asks to commit, or NULL when it is not a commit.
   *
   * Read from the raw user input, the one place both form shapes share (the
   * block form nests its widgets under settings.block_form).
   */
  public static function requestedProperty(FormStateInterface $form_state): ?string {
    $property = $form_state->getUserInput()[self::FIELD_INPUT] ?? NULL;
    return is_string($property) && $property !== '' ? $property : NULL;
  }

  /**
   * Commits properties of one subject through the spine and stages them.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $root
   *   The subject's root entity (the page); only its type and id are used,
   *   the batch runs on the storage handler's working copy.
   * @param string $element_uuid
   *   The spine element the properties belong to: the root's own uuid for
   *   root fields, the component uuid for a block's fields, label and
   *   configuration, the section uuid for its label and layout settings.
   * @param array<string, \Drupal\daedalus_blueprint\ExtractedValue|mixed> $properties
   *   The property bag of one update_element: a field name to the
   *   form-built field's items as an ExtractedValue, or a placement
   *   property ('label', a configuration key, 'layout_settings') to its
   *   plain value. A panel form passes exactly one entry.
   * @param string|null $langcode
   *   The translation the form is for; NULL for the entity as loaded.
   * @param string|\Stringable $label
   *   The history entry's label ("Edited Title", "Configured Hero").
   * @param bool $changed
   *   Whether the bag differs from the staged state. FALSE for a widget
   *   round that changed nothing (an AJAX-touched widget re-extracting the
   *   staged value, a dialog submitted untouched): the op still runs and
   *   the answer keeps the contract's shape, but nothing persists and no
   *   history entry is written.
   *
   * @return \Drupal\daedalus_edit\FormCommitOutcome
   *   The outcome, persisted on success.
   */
  public function commit(FieldableEntityInterface $root, string $element_uuid, array $properties, ?string $langcode, string|\Stringable $label, bool $changed = TRUE): FormCommitOutcome {
    $working = $this->stagedRoot($root);
    if ($working === NULL) {
      throw new \RuntimeException(sprintf('Daedalus Edit: no working copy for %s:%s to commit to.', $root->getEntityTypeId(), $root->id()));
    }

    $result = $this->operations->batchOperations($working, [
      [
        'op' => 'update_element',
        'element' => $element_uuid,
        'properties' => $properties,
      ],
    ], ['langcode' => $langcode]);

    $persisted = NULL;
    if ($result->isSuccess() && $changed) {
      // Annotate the history entry this persist produces: the server names
      // the gesture (the client transport knows only the property), the
      // result supplies the targets that drive undo's re-render subtree.
      $this->historyRecorder->annotate((string) $label, $result->getTargets());
      $persisted = $this->storageHandler->persist(
        $result->getEntity(),
        element_uuids: $result->getElementUuids(),
      );
    }

    return new FormCommitOutcome($working, $result, $langcode, $persisted);
  }

  /**
   * The root's working copy: the staged entity a commit runs on.
   *
   * The form builds on its own copy of the entity (a clone); the working
   * copy is what the spine mutates and what a submit's value is compared
   * against to tell a change from a widget round that changed nothing.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $root
   *   The subject's root entity; only its type and id are used.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The working copy, or NULL when the root cannot be loaded.
   */
  public function stagedRoot(FieldableEntityInterface $root): ?FieldableEntityInterface {
    return $this->storageHandler->load($root->getEntityTypeId(), $root->id());
  }

  /**
   * Whether a form-built field differs from the staged one.
   *
   * The one comparison every form path runs to tell a change from a
   * widget round that changed nothing. Two empty lists are equal whatever
   * their item count: a widget extracts an empty value as no items
   * (WidgetBase::extractFormValues filters them) while a stored empty
   * field may hold one item of its default (`revision_log`'s ''), and
   * FieldItemList::equals() would read that as a change and commit it.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   The form-built field.
   * @param \Drupal\Core\Field\FieldItemListInterface|null $staged
   *   The staged field, or NULL when nothing is staged to compare with.
   *
   * @return bool
   *   TRUE when the commit carries a change.
   */
  public static function fieldChanged(FieldItemListInterface $items, ?FieldItemListInterface $staged): bool {
    if ($staged === NULL) {
      return TRUE;
    }
    if ($items->isEmpty() && $staged->isEmpty()) {
      return FALSE;
    }
    return !$items->equals($staged);
  }

  /**
   * The view mode the canvas shows a section storage's root in.
   *
   * The root display's view mode lives on the root section storage's
   * context (a nested scope answers through its root), with the mutation
   * endpoint's 'full' fallback when the storage carries none. The block
   * form and the Configure dialogs render their response in it.
   *
   * @param \Drupal\layout_builder\SectionStorageInterface $storage
   *   The section storage a form was built on.
   *
   * @return string
   *   The view mode id.
   */
  public static function storageViewMode(SectionStorageInterface $storage): string {
    if ($storage instanceof NestedSectionStorageInterface) {
      $storage = $storage->getRootStorage();
    }
    $view_mode = $storage->getContexts()['view_mode'] ?? NULL;
    $view_mode = $view_mode?->getContextValue();
    return is_string($view_mode) && $view_mode !== '' ? $view_mode : 'full';
  }

  /**
   * Answers a commit with the mutation-response payload as one AJAX command.
   *
   * The payload is built under the edit-mode response convention every spine
   * road establishes (the mode on the request query, the entity on the route
   * match): the form's AJAX submit carries both by construction, since the
   * form was fetched through ModePluginBase.ajax() (which puts the mode on
   * the fetch URL) and core's #ajax keeps the fetch URL's query on every
   * submit, while both form routes resolve the subject's root from their
   * parameters. Failed results answer the same shape (success false, the
   * spine's errors) so the client rejects the commit as any op error.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The form's AJAX request.
   * @param \Drupal\daedalus_edit\FormCommitOutcome $outcome
   *   What the submit handler committed.
   * @param string $view_mode
   *   The view mode the canvas displays the root in, for the re-render.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   One BlueprintApplyResponseCommand carrying the payload.
   */
  public function respond(Request $request, FormCommitOutcome $outcome, string $view_mode): AjaxResponse {
    $extra = [];
    if ($outcome->result->isSuccess()) {
      $extra['persisted'] = $outcome->persisted;
      // Flush now (instead of the end-of-request fallback) so the response
      // carries the timeline the commit just extended.
      $this->historyRecorder->flush();
      $extra['history'] = $this->historyManager->metadata($outcome->root);
    }

    $json = $this->responseBuilder->build($request, $outcome->root, $outcome->result, $outcome->langcode, $extra, $view_mode);
    $payload = Json::decode($json->getContent());

    $response = new AjaxResponse();
    $response->addCommand(new BlueprintApplyResponseCommand($payload));
    return $response;
  }

  /**
   * Answers a submit that failed form validation: the rebuilt form, no write.
   *
   * @param string $wrapper_id
   *   The HTML id of the cached form's wrapper (the panel's form-cache entry).
   * @param array $wrapped_form
   *   The rebuilt form, wrapped for the panel, carrying its errors.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The replacement plus the validation messages.
   */
  public function rejected(string $wrapper_id, array $wrapped_form): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#' . $wrapper_id, $wrapped_form));
    $this->messages->deliver($response);
    return $response;
  }

}
