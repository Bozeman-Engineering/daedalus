import { EditorPluginBase } from './plugins/editor-plugin-base.js';
import { FormEditorBase } from './plugins/form-editor-base.js';
import { BlueprintEditorBase } from './plugins/blueprint-editor-base.js';
import * as verbWidgets from './composed/verb-widgets.js';
import * as linkControl from './composed/link-control.js';
import * as elementPreview from './composed/element-preview.js';

/**
 * The editor registry.
 *
 * One registry, two lookups:
 * - get(id): direct lookup by editor id, for leaves that declare their
 *   editor in their attribution (data-daedalus-edit-editor="{id}").
 * - getPlugin(EditableElement): widget-indexed lookup for form-edited
 *   leaves — candidates for the leaf's widget are offered in descending
 *   priority and the first whose applies() accepts wins, falling back to
 *   the default editor.
 */
class EditorRegistry {
  constructor() {
    /**
     * Registered editors, keyed by editor id.
     *
     * @type {Object<string, EditorPluginBase>}
     */
    this.editors = {};

    /**
     * Editors indexed by supported widget id, each list sorted by
     * descending priority (ties keep registration order).
     *
     * @type {Object<string, EditorPluginBase[]>}
     */
    this.widgetIndex = {};
  }

  /**
   * Register an editor.
   *
   * @param editor
   *   The editor plugin instance.
   */
  register(editor) {
    if (!(editor instanceof EditorPluginBase)) {
      console.error('Failed to register editor: ', editor);
      return;
    }
    // An editor that declares no id stays addressable by its constructor
    // name.
    const id = editor.id ?? editor.constructor.name;
    this.editors[id] = editor;
    (editor.supportedWidgets ?? []).forEach((widget) => {
      this.widgetIndex[widget] ??= [];
      const candidates = this.widgetIndex[widget];
      candidates.push(editor);
      candidates.sort((a, b) => (b.priority ?? 0) - (a.priority ?? 0));
    });
    editor.init();
  }

  /**
   * Get an editor by id.
   *
   * @param id
   *   The editor id.
   *
   * @return {EditorPluginBase|undefined}
   *   The editor, if registered.
   */
  get(id) {
    return this.editors[id];
  }

  /**
   * Get the editor for a form-edited leaf, by its widget.
   *
   * @param {EditableElement} EditableElement
   *   The element being edited.
   * @return {EditorPluginBase}
   *   The highest-priority applicable editor for the element's widget, or
   *   the default editor.
   */
  getPlugin = (EditableElement) => {
    const candidates = this.widgetIndex[EditableElement.info.widget] ?? [];
    for (const editor of candidates) {
      if (editor.applies(EditableElement)) {
        return editor;
      }
    }
    return this.editors.default;
  };

  /**
   * Resolve property.
   *
   * The field property an element's rendered formatter shows — the property
   * segment of the form-side lookup ids. Editors declare exceptions in
   * their formatterProperties map; everything else resolves to the field's
   * main property. A registry consultation rather than an instance method
   * because resolution is needed before a widget (and therefore an editor)
   * is known: the form item wrapper lookup is itself keyed by property.
   *
   * @param elementInfo
   *   An EditableElement's info object.
   * @return {string}
   *   The property name.
   */
  resolveProperty = (elementInfo) => {
    for (const editor of Object.values(this.editors)) {
      const property = (editor.formatterProperties ?? {})[
        elementInfo.fieldFormatter
      ];
      if (property) {
        return property;
      }
    }
    return elementInfo.mainProperty ?? 'value';
  };
}

/**
 * Boots the registry and drains the DaedalusEditQueue registration seam.
 *
 * Same idiom as DaedalusUiQueue (daedalus_ui registration.js): a
 * registering script's only load-time act is pushing a callback onto a
 * plain array that needs no daedalus_edit code to exist:
 *
 *   (window.DaedalusEditQueue ||= []).push((daedalusEdit) => {
 *     class MyEditor extends daedalusEdit.FormEditorBase {
 *       id = 'my_editor';
 *       supportedWidgets = ['my_widget'];
 *     }
 *     daedalusEdit.editors.register(new MyEditor());
 *   });
 *
 * The queue is drained once at boot and replaced with a live object whose
 * push() runs callbacks immediately, so a callback pushed before, with, or
 * after this module executes behaves the same. A failing callback is
 * contained so one broken editor cannot take down the registrations behind
 * it.
 */
export default function () {
  const registry = new EditorRegistry();
  Drupal.Daedalus.Edit.Editors = registry;

  // The API handed to registration callbacks. Besides the base classes and
  // the registry, it carries the shared machinery composed-element editors
  // build on: the widget-verb popover grammar, the link control, and the
  // resolved-tree preview morph (see js/daedalus_edit/composed/).
  const daedalusEdit = {
    EditorPluginBase,
    FormEditorBase,
    BlueprintEditorBase,
    verbWidgets,
    linkControl,
    elementPreview,
    editors: {
      register: (editor) => registry.register(editor),
      get: (id) => registry.get(id),
    },
  };

  const queued = Array.isArray(window.DaedalusEditQueue)
    ? window.DaedalusEditQueue
    : [];
  const run = (callback) => {
    try {
      callback(daedalusEdit);
    } catch (error) {
      console.error('An DaedalusEdit registration callback failed:', error);
    }
  };
  window.DaedalusEditQueue = { push: run };
  queued.forEach(run);
}
