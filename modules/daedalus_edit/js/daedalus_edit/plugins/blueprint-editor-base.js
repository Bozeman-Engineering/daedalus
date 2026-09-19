import { EditorPluginBase } from './editor-plugin-base.js';
import { equals } from '../mirror-value.js';

/**
 * Finds a block component in a blueprint by its component uuid.
 *
 * The implementation moved to the daedalus_ui Blueprint facade when
 * daedalus_layout's verbs became the second consumer; this export delegates for
 * existing importers. Safe to call any time an edit can run — the facade
 * boots with the edit-mode app.
 *
 * @param {?Object} blueprint
 *   A blueprint (the mirror's shape), or null before the mirror boots.
 * @param {string} uuid
 *   The component uuid (the `block:{uuid}` selectable key's uuid segment).
 *
 * @return {?Object}
 *   The component node, or null.
 */
export const componentForUuid = (blueprint, uuid) =>
  Drupal.Daedalus.Blueprint.componentForUuid(blueprint, uuid);

/**
 * Blueprint editor base.
 *
 * The blueprint mutation commit path: the leaf IS the editing surface (the
 * template's composed element, edited in place) and blur commits by queueing
 * ONE `update_element` op on the blueprint mutation spine
 * (Drupal.Daedalus.Blueprint.mutate). This is the base class for
 * editors of blueprint-edited leaves ([data-daedalus-edit-editor]) — no form is
 * fetched, no widget swapped; tempstore coherence and validation live behind
 * the endpoint.
 *
 * The gesture's value contract:
 * - edit() snapshots the component's edited properties from the blueprint
 *   MIRROR (never parsed from the DOM) onto the leaf.
 * - blur diffs the editor's current values (readProperties(), the one
 *   subclass responsibility besides writing them back) against that
 *   snapshot and sends only the changed properties — an empty diff sends
 *   nothing, so one history entry exists per edit gesture that changed
 *   something.
 * - The optimistic apply is a no-op (the DOM already shows the truth via
 *   contenteditable and preview wraps); rollback and Escape both restore the
 *   snapshot through writeProperties(). Escape never sends an op.
 * - Failures surface through the one mutation-failure display
 *   (Blueprint.reportFailure), flashing the component's block wrapper.
 * - The open edit is a DRAFT. A foreign mutation (a style scrub from the
 *   Daedalus Stylish inspector, an undo, an AI turn) swaps the leaf's markup out
 *   from under the editing surface; the engine captures the draft
 *   (captureDraft = readProperties) as the swap detaches the subtree and
 *   hands it to resume() once the response is fully applied. resume()
 *   re-projects by a three-way rule per property: base = the snapshot at
 *   edit open, draft = the captured value, remote = the mirror after the
 *   response. remote != base means the remote changed the very thing being
 *   edited and wins; otherwise a changed draft is written back and the
 *   user keeps typing. The snapshot becomes the remote, so Escape after a
 *   resume restores server truth. No flag decides; the data does.
 *
 * @see ../blueprint-element.js (the leaf state object)
 * @see form-editor-base.js (the entity-form commit path)
 */
export class BlueprintEditorBase extends EditorPluginBase {
  /**
   * Edit.
   *
   * Snapshots the mirror's current values before the user changes anything,
   * and marks the leaf as the active blueprint edit (the class keeps clicks
   * inside the composed element from ending the edit — the blur grammar's
   * getFocusedSelectors contract).
   *
   * @param {EditableElement} EditableElement
   *   The BlueprintEditableElement being edited.
   */
  edit(EditableElement) {
    EditableElement.snapshot = this.snapshot(EditableElement);
    EditableElement.getPageElement()?.classList.add(
      'daedalus-edit-blueprint-editing',
    );
    super.edit(EditableElement);
  }

  /**
   * Defocus.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   */
  defocus(EditableElement) {
    EditableElement.getPageElement()?.classList.remove(
      'daedalus-edit-blueprint-editing',
    );
  }

  /**
   * Read properties.
   *
   * The editor's current values as a flat property bag — the same names the
   * blueprint's `update_element` op sorts to storage (block fields, label).
   * Subclasses MUST implement this: it defines both which properties this
   * editor edits (the snapshot picks these names from the mirror) and what
   * their current values are on the editing surface.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   *
   * @return {Object<string, *>}
   *   The current property values, keyed by property name.
   */
  readProperties(EditableElement) {
    return {};
  }

  /**
   * Write properties.
   *
   * Writes a property bag back onto the editing surface — the restore half
   * of the contract, used by Escape (cancel) and by the optimistic rollback
   * when the server rejects the commit. Subclasses MUST implement this for
   * the properties they read.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   * @param {Object<string, *>} properties
   *   The property values to restore.
   */
  writeProperties(EditableElement, properties) {}

  /**
   * Snapshot.
   *
   * The edited properties' pre-edit values, read from the blueprint mirror
   * (the model of record — never parsed from the DOM) and cloned so later
   * mirror replacements can't reach into it.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   *
   * @return {Object<string, *>}
   *   The snapshot property bag.
   */
  snapshot(EditableElement) {
    const component = this.mirrorComponent(EditableElement);
    if (!component) {
      throw new Error(
        `No blueprint component "${EditableElement.componentUuid}" in the mirror; cannot edit "${EditableElement.editorId}".`,
      );
    }
    const snapshot = {};
    Object.keys(this.readProperties(EditableElement)).forEach((name) => {
      // Inline-block fields live in the component's block_fields; placement
      // properties (label) on the component itself — the same sorting the
      // server applies to the flat bag, read in reverse.
      let value =
        component.block_fields?.[name] !== undefined
          ? component.block_fields[name]
          : component[name];
      // An EMPTY field serializes as a form-only stub, not a value; to the
      // editing surface it is "no value". A stub kept in the snapshot
      // poisons the gesture: spread into an outgoing value (a link's
      // preserved title/options), its marker key makes the server treat the
      // whole write as "leave this field alone" — a silent no-op.
      if (Drupal.Daedalus.Blueprint.isFormOnlyStub(value)) {
        value = null;
      }
      snapshot[name] = structuredClone(value);
    });
    return snapshot;
  }

  /**
   * Changed properties.
   *
   * Diffs the editing surface's current values against the edit's mirror
   * snapshot.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   *
   * @return {Object<string, *>}
   *   The properties whose values changed during this edit gesture.
   */
  changedProperties(EditableElement) {
    const snapshot = EditableElement.snapshot ?? {};
    const changed = {};
    Object.entries(this.readProperties(EditableElement)).forEach(
      ([name, value]) => {
        if (!equals(value, snapshot[name])) {
          changed[name] = value;
        }
      },
    );
    return changed;
  }

  /**
   * Blur element.
   *
   * Commits the edit gesture: an empty diff sends nothing; a change queues
   * one `update_element` op. The gesture is over the moment the op is
   * queued (the DOM already shows the change), so blur resolves immediately
   * instead of holding Tab traversal hostage to the round trip — the FIFO
   * queue keeps ordering and a failure reports through reportFailure, not
   * through this promise.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   *
   * @return {Promise<void>}
   */
  blurElement(EditableElement) {
    const changed = this.changedProperties(EditableElement);
    if (Object.keys(changed).length > 0) {
      this.commit(EditableElement, changed);
    }
    return Promise.resolve();
  }

  /**
   * Cancel element.
   *
   * Escape's half of the contract: restore the mirror snapshot onto the
   * editing surface and send nothing.
   *
   * @param {EditableElement} EditableElement
   *   The element being canceled.
   *
   * @return {Promise<void>}
   */
  cancelElement(EditableElement) {
    this.writeProperties(EditableElement, EditableElement.snapshot ?? {});
    return Promise.resolve();
  }

  /**
   * Capture draft.
   *
   * The editing surface's current values, read while the surface still
   * stands (the swap's detach): the same bag blur would diff.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   *
   * @return {Object<string, *>}
   *   The draft property bag.
   */
  captureDraft(EditableElement) {
    return this.readProperties(EditableElement);
  }

  /**
   * Reset gesture.
   *
   * Per-gesture state an editor keeps beside the snapshot (a link
   * control's preview) belongs to the surface the swap just replaced;
   * resume() calls this before re-snapshotting so nothing stale shadows
   * the remote. The base keeps no such state.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   */
  resetGesture(EditableElement) {}

  /**
   * Resume.
   *
   * The three-way re-projection (see the class docblock): the snapshot is
   * re-taken from the replaced mirror (the remote), the draft's changed
   * properties survive where the remote left the base alone, and the
   * surface is marked and focused again on the swapped-in element. The
   * fresh entry (edit()) is not re-run: the edit never closed.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   * @param {Object<string, *>} draft
   *   The bag captureDraft() returned.
   *
   * @return {Promise<void>}
   */
  resume(EditableElement, draft) {
    const base = EditableElement.snapshot ?? {};
    this.resetGesture(EditableElement);
    const remote = this.snapshot(EditableElement);
    EditableElement.snapshot = remote;
    const survivors = this.survivingProperties(draft, base, remote);
    EditableElement.getPageElement()?.classList.add(
      'daedalus-edit-blueprint-editing',
    );
    if (Object.keys(survivors).length > 0) {
      this.writeProperties(EditableElement, survivors);
    }
    this.focus(EditableElement);
    return Promise.resolve();
  }

  /**
   * Surviving properties.
   *
   * The three-way rule, per property: a draft value survives when it
   * differs from the base (the user changed it) and the remote left the
   * base alone (the response did not change it). The remote is compared
   * against `mirrorBase`, the snapshot by default; an editor whose
   * snapshot re-baselines away from the mirror's own bytes (the formatted
   * text editor keeps CKEditor's normalization there) passes the mirror's
   * value at edit open instead, so a response that merely echoed the
   * stored markup does not read as a remote change.
   *
   * @param {?Object<string, *>} draft
   *   The captured draft bag.
   * @param {Object<string, *>} base
   *   The snapshot the draft is compared against.
   * @param {Object<string, *>} remote
   *   The mirror's values after the response.
   * @param {Object<string, *>} mirrorBase
   *   The values the remote is compared against; the base unless given.
   *
   * @return {Object<string, *>}
   *   The draft properties to re-project.
   */
  survivingProperties(draft, base, remote, mirrorBase = base) {
    const survivors = {};
    Object.entries(draft ?? {}).forEach(([name, value]) => {
      if (
        !equals(value, base[name]) &&
        equals(mirrorBase[name], remote[name])
      ) {
        survivors[name] = value;
      }
    });
    return survivors;
  }

  /**
   * Commit.
   *
   * Queues the one `update_element` op for this gesture on the mutation
   * spine, targeting the ROOT entity (rootContext()) and addressing the
   * block component by uuid inside it. Rollback restores the snapshot;
   * failures go to the one mutation-failure display keyed by the
   * component's selectable key.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   * @param {Object<string, *>} properties
   *   The changed properties to send.
   *
   * @return {Promise<Object>}
   *   The op-queue promise. Rejections are already reported; the return
   *   value exists for callers that want to sequence on the round trip.
   */
  commit(EditableElement, properties) {
    const context = Drupal.Daedalus.Blueprint.rootContext();
    if (!context) {
      throw new Error('No root entity mutation context on this page.');
    }
    const snapshot = EditableElement.snapshot ?? {};
    const request = Drupal.Daedalus.Blueprint.mutate({
      ...context,
      operations: [
        {
          op: 'update_element',
          element: EditableElement.componentUuid,
          properties,
        },
      ],
      label: this.commitLabel(EditableElement, properties),
      optimistic: {
        // The DOM already shows the edit; there is nothing to apply.
        apply: () => {},
        rollback: () => this.writeProperties(EditableElement, snapshot),
      },
    });
    request.catch((error) => {
      Drupal.Daedalus.Blueprint.reportFailure(
        error,
        EditableElement.selectableKey,
      );
    });
    return request;
  }

  /**
   * Commit label.
   *
   * The gesture's history-entry label. The default names the component;
   * editors with a better word for the gesture override this.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   * @param {Object<string, *>} properties
   *   The changed properties being sent.
   *
   * @return {string}
   *   The history label.
   */
  commitLabel(EditableElement, properties) {
    const component = this.mirrorComponent(EditableElement);
    return Drupal.t('Edited @label', {
      '@label': component?.label ?? component?.block_type ?? Drupal.t('block'),
    });
  }

  /**
   * Mirror component.
   *
   * The leaf's component node in the blueprint mirror.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   *
   * @return {?Object}
   *   The component, or null.
   */
  mirrorComponent(EditableElement) {
    return componentForUuid(
      Drupal.Daedalus.Blueprint.getBlueprint(),
      EditableElement.componentUuid,
    );
  }

  /**
   * Get remain focused selectors.
   *
   * Clicks inside the active blueprint edit (moving the caret, plus any
   * surface a subclass adds its own selectors for) must not end the edit;
   * the edit's chip widgets live in .daedalus-ui-frame chrome, which the session
   * keeps by its own rule.
   * Neither must an edit's floating chrome: CKEditor renders its toolbar and
   * balloons into .ck-body-wrapper on <body>, and editor-launched dialogs
   * (image upload, media) are jQuery UI dialogs. daedalus_ui already
   * ignores clicks outside [data-main-entity], so keeping the edit open is
   * all that is needed for dialogs not to disturb the session.
   *
   * @return {string[]}
   *   Selectors whose clicks keep the edit open.
   */
  getFocusedSelectors() {
    return [
      ...super.getFocusedSelectors(),
      '.daedalus-edit-blueprint-editing',
      '.ck-body-wrapper',
      '#drupal-modal',
      '.ui-widget',
      '.ui-dialog-buttonpane',
    ];
  }
}
