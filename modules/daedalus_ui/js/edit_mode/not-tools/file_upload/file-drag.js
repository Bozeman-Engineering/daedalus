// cspell:ignore resup
import { mimeToExtensions } from './mime-to-extensions.js';
import { uploadFile } from './upload-file.js';
import { createUploadProgress } from './upload-progress.js';

(($, Drupal) => {
  /**
   * Desktop file drags — a detector plus ONE drag-layer source, two faces.
   *
   * An OS file drag never fires dragstart on an in-page draggable, so the
   * drag layer would ignore it by design. This file closes that gap in two
   * parts, neither of which owns any drop UI of its own:
   *
   * - The DETECTOR recognizes a Files drag entering the document and arms
   *   the drag layer's `media_file` source (beginExternalDrag); it disarms
   *   when the drag leaves the window. That is its whole job.
   * - The `media_file` SOURCE says what a drop means. Its gap face places
   *   the file as a NEW media block (upload, then a stored association or
   *   the chooser dialog, then an ordinary add_element op). Its element
   *   face lands on compatible [data-media-reference] media and REPLACES
   *   their file through the upload/replace pipeline: a job on the op
   *   queue (FIFO with ops, covered by drained()) whose server half
   *   answers the mutation payload, so operations() returns null and the
   *   drag layer sends no ops of its own.
   *
   * Target resolution, chrome (the caret's line for gaps, its highlight
   * ring for elements), hysteresis, and failure display are all the drag
   * layer's; per-position arbitration between "place new" and "replace"
   * is just the layer's element-vs-gap hit-testing. Pages without
   * placement keys resolve no gaps, so only replace targets remain there
   * (the old "only LB pages have dropzones" behavior, for free).
   *
   * Drag and Drop browser implementation varies greatly.
   * @see https://github.com/leonadler/drag-and-drop-across-browsers?tab=readme-ov-file#detecting-if-a-drag--drop-is-happening-anywhere-on-the-page
   */

  const editMode = () => Drupal.Daedalus.ModeManager.getPlugin('edit');

  // Detector state. `dragging` marks a Files drag session in the window
  // (one message / blank-page removal per session); `listenerAttached`
  // tracks the dragenter detector, which conflicting uploaders (the
  // FileDragDisable selectors) suspend while present.
  let enabled = false;
  let dragging = false;
  let listenerAttached = false;
  let observer = null;
  let leaveTimeoutId = null;

  /**
   * The dragged file's candidate extensions, from the drag's mime type.
   *
   * Files themselves are unreadable before the drop; the mime type is the
   * one identity dragenter offers, so it is what the element face's
   * compatibility filter gets to work with.
   *
   * @param {DataTransferItemList} items
   *
   * @return {?string[]}
   *   Extensions like '.jpg', or null when the drag carries no items.
   */
  const extensionsOf = (items) => {
    if (items.length === 0) {
      return null;
    }
    const type = items[0].type;
    return mimeToExtensions[type] ?? [`.${type.split('/')[1]}`];
  };

  const normalizeExtension = (ext) =>
    ext.startsWith('.') ? ext.toLowerCase() : `.${ext.toLowerCase()}`;

  /**
   * Whether the dragged file's type fits a media element's source field.
   *
   * A side that declares no constraint (unknown mime, or a field without
   * configured extensions) is treated as compatible; the server validates
   * for real on upload.
   *
   * @param {?string[]} fileExtensions
   *   The dragged file's candidate extensions (extensionsOf).
   * @param {Element} media
   *   The [data-media-reference] element.
   *
   * @return {boolean}
   */
  const fileIsCompatibleWithMedia = (fileExtensions, media) => {
    const accepted = media.dataset.acceptedFiles;
    if (!fileExtensions || !accepted) {
      return true;
    }
    const acceptedNormalized = accepted.split(',').map(normalizeExtension);
    return fileExtensions
      .map(normalizeExtension)
      .some((ext) => acceptedNormalized.includes(ext));
  };

  /**
   * Detects a Files drag entering the page and arms the drag layer.
   *
   * Arming is internally guarded (a no-op while a drag is active), so
   * re-arming on every dragenter is safe and self-heals a disarm from a
   * brief window exit.
   *
   * @param {DragEvent} e
   */
  const onDragEnter = (e) => {
    if (e.dataTransfer?.types?.[0] !== 'Files') {
      return;
    }
    const items = e.dataTransfer.items;
    if (!dragging) {
      dragging = true;
      if (items.length > 1) {
        editMode().message(Drupal.t('Drag one file at a time to the page.'));
        return;
      }
      // Remove the blank page instructions so they don't sit under the
      // caret while the first block is being dropped.
      document.getElementById('daedalus-layout-blank-page')?.remove();
    }
    if (items.length === 1) {
      Drupal.Daedalus.Drag.beginExternalDrag('media_file', {
        fileExtensions: extensionsOf(items),
      });
    }
  };

  /**
   * Disarms when the drag leaves the window.
   *
   * dragleave cannot distinguish "left the window" from "crossed into a
   * child", so the exit is coordinates at the viewport edge plus a grace
   * timeout that the next dragover cancels.
   *
   * @param {DragEvent} e
   */
  const onDragLeave = (e) => {
    if (
      e.clientX <= 0 ||
      e.clientY <= 0 ||
      e.clientX >= window.innerWidth ||
      e.clientY >= window.innerHeight
    ) {
      leaveTimeoutId = setTimeout(() => {
        dragging = false;
        Drupal.Daedalus.Drag.endExternalDrag();
      }, 100);
    }
  };

  const onDragOver = (e) => {
    // Cancelling dragover keeps the document a drop target even when the
    // drag layer refused to arm (multi-file drags), so the drop below can
    // stop the browser from opening the file in a tab.
    e.preventDefault();
    if (leaveTimeoutId) {
      clearTimeout(leaveTimeoutId);
      leaveTimeoutId = null;
    }
  };

  const onDrop = (e) => {
    // The drag layer's document drop listener registered at app boot, so
    // it runs first and owns committing + ending an armed drag; this one
    // keeps unhandled file drops from navigating and closes the session.
    e.preventDefault();
    dragging = false;
  };

  /**
   * Starts detecting, unless a conflicting uploader is on the page.
   *
   * Uploader widgets that handle native file drops themselves (the
   * FileDragDisable selectors — contrib may add its own via
   * drupalSettings) suspend the dragenter detector while present; a
   * MutationObserver re-evaluates as they come and go.
   */
  const enable = () => {
    if (enabled) {
      return;
    }
    enabled = true;

    drupalSettings.Daedalus ??= {};
    drupalSettings.Daedalus.FileDragDisable ??= {};
    drupalSettings.Daedalus.FileDragDisable.DropzoneJs =
      '.dropzone-enable';
    drupalSettings.Daedalus.FileDragDisable.FileResup = '.file-resup';
    const disableClasses = Object.values(
      drupalSettings.Daedalus.FileDragDisable,
    ).join(', ');

    const toggleListener = () => {
      const hasDisablingElements =
        document.querySelector(disableClasses) !== null;
      if (hasDisablingElements && listenerAttached) {
        document.removeEventListener('dragenter', onDragEnter);
        listenerAttached = false;
      } else if (!hasDisablingElements && !listenerAttached) {
        document.addEventListener('dragenter', onDragEnter);
        listenerAttached = true;
      }
    };
    observer = new MutationObserver(toggleListener);
    observer.observe(document.body, { childList: true, subtree: true });
    toggleListener();

    document.addEventListener('dragleave', onDragLeave);
    document.addEventListener('dragover', onDragOver);
    document.addEventListener('drop', onDrop);
  };

  const disable = () => {
    if (!enabled) {
      return;
    }
    enabled = false;
    observer.disconnect();
    observer = null;
    document.removeEventListener('dragenter', onDragEnter);
    document.removeEventListener('dragleave', onDragLeave);
    document.removeEventListener('dragover', onDragOver);
    document.removeEventListener('drop', onDrop);
    listenerAttached = false;
    dragging = false;
    if (leaveTimeoutId) {
      clearTimeout(leaveTimeoutId);
      leaveTimeoutId = null;
    }
    Drupal.Daedalus.Drag.endExternalDrag();
  };

  /**
   * The element face's pipeline: upload the file, replace the media.
   *
   * Runs inside the source's elements.operations, so the drag layer holds
   * its committing chrome on the landing element until this settles, and
   * its failure display owns any error thrown here (flash on the element,
   * structured notification). The round trip is a job on the op queue:
   * the upload's response is a Drupal AJAX command list carrying the
   * mutation payload (the server ran the replacement as one update_element
   * op), applied through the app's one applyResponse pipeline by the
   * daedalusBlueprintApplyResponse command handler and read back off the
   * inert Drupal.Ajax instance that played it, so the job resolves with
   * the applied payload and a failed result rejects as any op error.
   * Returns null: the drag layer has no ops of its own to send.
   *
   * @param {Element} media
   *   The [data-media-reference] element being landed on.
   * @param {?File} file
   *   The dropped file.
   *
   * @return {Promise<null>}
   */
  const replaceMedia = async (media, file) => {
    if (!file) {
      return null;
    }
    const mode = editMode();
    const entityInfo = mode.getMainEntityInfo();
    let path = `/daedalus-ui/replace-media/${entityInfo.entityType}/${entityInfo.id}/${entityInfo.viewMode}/${media.dataset.mediaReference}/${media.dataset.mediaBundle}?navigationMode=edit`;
    // The translation being edited rides the query as it does for every
    // mutate request; absent, the server takes the default translation.
    const langcode = Drupal.Daedalus.Blueprint.getRoot()?.langcode;
    if (langcode) {
      path += `&langcode=${encodeURIComponent(langcode)}`;
    }
    const sectionStorageInfo = mode.getSectionStorageInfo(media);
    if (sectionStorageInfo) {
      path += `&${new URLSearchParams(sectionStorageInfo).toString()}`;
    }
    const url = Drupal.Daedalus.ModePluginBase.url(path);

    // Use the lower of the media field's max filesize or the global
    // setting. The value is in MiB; the check converts to bytes. This
    // just fails fast with a clear message — the server validates too.
    let maxFilesize = media.dataset.maxFileSize;
    if (drupalSettings?.daedalusUi?.FileDragMaxFilesize) {
      if (
        !maxFilesize ||
        drupalSettings.daedalusUi.FileDragMaxFilesize < maxFilesize
      ) {
        maxFilesize = drupalSettings.daedalusUi.FileDragMaxFilesize;
      }
    }
    if (maxFilesize) {
      const maxBytes = parseFloat(maxFilesize) * 1024 * 1024;
      if (maxBytes && file.size > maxBytes) {
        mode.message(
          Drupal.t('File is too big. Max filesize: @maxFilesize MiB.', {
            '@maxFilesize': maxFilesize,
          }),
        );
        return null;
      }
    }

    const timeout = media.dataset.timeout
      ? parseInt(media.dataset.timeout, 10)
      : undefined;
    await Drupal.Daedalus.Blueprint.enqueueJob({
      run: async () => {
        const progress = createUploadProgress();
        try {
          const response = await uploadFile(url, file, {
            timeout,
            onProgress: progress.onProgress,
          });
          // Bytes are sent; the op and its re-render are the remaining
          // server work. Hold "Processing…" while the commands play.
          progress.processing();
          const inertAjax = new Drupal.ajax({ url });
          await Promise.resolve(
            Drupal.Ajax.prototype.success.call(inertAjax, response),
          );
          document.querySelector('.ajax-progress')?.remove();
          const payload = inertAjax.daedalusBlueprintPayload;
          if (!payload) {
            throw new Error(
              Drupal.t(
                'The media replacement answered no mutation payload; nothing was staged.',
              ),
            );
          }
          return payload;
        } finally {
          progress.remove();
        }
      },
    });
    return null;
  };

  /**
   * The block-type choice pending from the association dialog, or null.
   *
   * @type {?Function}
   */
  let pendingChoice = null;

  /**
   * Invoked by MediaBlockFileAssociationForm's ajaxSubmit with the
   * resolution. Sent BEFORE the dialog-close command, so the happy path
   * consumes the pending choice before the close-means-cancel check runs.
   */
  $.fn.daedalusUiFileAssociationChosen = function (resolution) {
    pendingChoice?.(resolution);
    pendingChoice = null;
    return this;
  };

  /**
   * Awaits the association dialog's outcome.
   *
   * @return {Promise<?Object>}
   *   The resolution, or null when the dialog closes without a choice.
   */
  const waitForAssociationChoice = () =>
    new Promise((resolve) => {
      pendingChoice = resolve;
      window.addEventListener(
        'dialog:afterclose',
        () => {
          // The submit path closes the dialog too — after its choice command.
          // Defer one tick so a same-response close never races the choice.
          setTimeout(() => {
            if (pendingChoice) {
              pendingChoice = null;
              resolve(null);
            }
          });
        },
        { once: true },
      );
    });

  /**
   * Uploads the dropped file and resolves which block type carries it.
   *
   * @param {File} file
   *   The dropped file.
   *
   * @return {Promise<?Object>}
   *   {block_type, field_name, media_target_id}, or null on cancel.
   */
  const resolveNewMedia = async (file) => {
    const entityInfo = editMode().getMainEntityInfo();
    const url = Drupal.Daedalus.ModePluginBase.url(
      `/daedalus-ui/new-media/${entityInfo.entityType}/${entityInfo.id}/${entityInfo.viewMode}`,
    );

    const progress = createUploadProgress();
    let response;
    try {
      response = await uploadFile(url, file, {
        onProgress: progress.onProgress,
      });
    } finally {
      progress.remove();
    }

    // A stored file association resolved the block type server-side.
    if (response?.resolution) {
      return response.resolution;
    }

    // No association: the response is the block-type chooser dialog as a
    // Drupal AJAX command array. Play it and await the form's choice.
    if (Array.isArray(response)) {
      const choice = waitForAssociationChoice();
      const inertAjax = new Drupal.ajax({ url });
      Drupal.Ajax.prototype.success.call(inertAjax, response);
      return choice;
    }
    return null;
  };

  /**
   * Register and attach. This file is loaded outside the app bundle, so
   * like contrib it defers its store subscription and drag-source
   * registration to the queue seam instead of racing the bundle.
   */
  (window.DaedalusUiQueue ||= []).push((app) => {
    // The desktop file as a drag source with both target kinds. The drop's
    // files ride data.files (added by the drag layer's drop handler — they
    // are unreadable before the drop); dragenter's mime type rides
    // data.fileExtensions for the element face's compatibility filter.
    app.drag.registerSource('media_file', {
      targets: 'block',
      // Gap face: place the file as a NEW media block. The drop resolves
      // the file to a block type + media entity (server upload, then a
      // stored association or the chooser dialog) and places it as an
      // ordinary add_element through the op queue.
      operations: async (target, data) => {
        const file = data.files?.[0];
        if (!file) {
          return null;
        }
        const resolution = await resolveNewMedia(file);
        if (!resolution) {
          return null;
        }
        return app.drag.builders.newBlock(target, {
          blockType: resolution.block_type,
          properties: {
            [resolution.field_name]: { target_id: resolution.media_target_id },
          },
        });
      },
      // Element face: land on compatible media already on the page and
      // replace its file. Replacing is a content write on the element
      // the media belongs to, so the face asks the one gate (the gap
      // face is judged by the drag controller at the destination).
      elements: {
        selector: '[data-media-reference]',
        accepts: (element, data) =>
          fileIsCompatibleWithMedia(data.fileExtensions, element) &&
          app.Selection.capable(
            element.closest('[data-selectable]')?.getAttribute('data-selectable') ?? null,
            'content',
          ),
        label: Drupal.t('Replace media'),
        operations: (target, data) =>
          replaceMedia(target.element, data.files?.[0]),
      },
    });

    window.listenToMultipleStates(
      [(state) => state.mode.mode, (state) => state.fileDrag.enabled],
      (mode, fileDrag) => {
        if (mode === 'edit' && fileDrag.enabled === true) {
          enable();
        } else {
          disable();
        }
      },
    );
  });
})(jQuery, Drupal);
