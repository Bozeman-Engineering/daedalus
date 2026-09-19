/**
 * @file
 * Triggers deferred processing of generation jobs via the server-side
 * controller.
 *
 * When the server queues a TriggerDeferredCommand, we append the batch to a
 * localStorage stash list (survives navigation in the creation flow) then
 * POST to the deferred processing controller. One turn can release several
 * commands (e.g. a create minting images followed by an edit minting more),
 * so the stash is a list keyed by batchId: batches for the current edit-mode
 * entity drain in sequence through the full UI flow, batches for other
 * entities fire headless and stay stashed for the page that owns them, and
 * completion removes exactly the completed batch. A completed batch answers
 * the mutation-response contract plus a failures list: the payload is
 * applied through the app's ONE applyResponse pipeline (mirror replacement,
 * subtree swaps, attachments, piggybacked highlight/commands); failures are
 * surfaced in the chat panel.
 */

(($, Drupal, once) => {
  const { log } = Drupal.Daedalus.logger('daedalus_ai');

  const STORAGE_KEY = 'daedalusAiDeferredBatch';
  const STALE_THRESHOLD_MS = 5 * 60 * 1000;
  const MAX_RETRIES = 2;

  const FALLBACK_MESSAGES = {
    loading: 'Processing…',
    retry: 'Retrying…',
    success: 'Processing completed successfully.',
    partial_success: 'Partially completed. @count failed.',
    failure: 'Processing failed.',
    error: 'Processing could not complete. Refresh and try again.',
  };

  Drupal.Daedalus.AiDeferred = {
    inProgress: false,

    /**
     * Batch ids already fired headless on this page load.
     *
     * The controller's per-batch lock makes a duplicate POST harmless, but
     * draining re-enters attemptTrigger after every completed batch; this
     * keeps that recursion from re-POSTing the batches that are not ours.
     */
    headlessFired: new Set(),

    /**
     * Reads the stash list, dropping stale or malformed batches.
     *
     * Staleness is judged per batch on its own timestamp. Pruning writes
     * back immediately so a dead batch never outlives the read that judged
     * it.
     *
     * @return {Object[]}
     *   The live batch payloads, oldest first.
     */
    readStash() {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (!raw) {
        return [];
      }
      let list;
      try {
        list = JSON.parse(raw);
      } catch (e) {
        localStorage.removeItem(STORAGE_KEY);
        return [];
      }
      if (!Array.isArray(list)) {
        localStorage.removeItem(STORAGE_KEY);
        return [];
      }
      const live = list.filter((payload) => {
        if (!payload || typeof payload.batchId !== 'string') {
          return false;
        }
        if (Date.now() - (payload.timestamp || 0) > STALE_THRESHOLD_MS) {
          log('deferred', 'Stale batch, removing', {
            batchId: payload.batchId,
          });
          return false;
        }
        return true;
      });
      if (live.length !== list.length) {
        this.writeStash(live);
      }
      return live;
    },

    /**
     * Writes the stash list, removing the slot when the list is empty.
     *
     * @param {Object[]} list
     *   The batch payloads to persist.
     */
    writeStash(list) {
      if (list.length === 0) {
        localStorage.removeItem(STORAGE_KEY);
        return;
      }
      localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
    },

    /**
     * Appends a batch to the stash list.
     *
     * The list is keyed by batchId: re-stashing an id replaces its entry in
     * place, so a re-delivered command cannot queue the same batch twice.
     *
     * @param {Object} payload
     *   The batch payload to stash.
     */
    appendToStash(payload) {
      const list = this.readStash();
      const existing = list.findIndex(
        (entry) => entry.batchId === payload.batchId,
      );
      if (existing === -1) {
        list.push(payload);
      } else {
        list[existing] = payload;
      }
      this.writeStash(list);
    },

    /**
     * Removes one batch from the stash list.
     *
     * @param {string} batchId
     *   The batch to remove.
     */
    removeFromStash(batchId) {
      this.writeStash(
        this.readStash().filter((entry) => entry.batchId !== batchId),
      );
    },

    /**
     * Drains the stash if a deepchat element is available.
     *
     * Batches for the current edit-mode entity run the full UI flow, one at
     * a time: the first runs now, and its completion re-enters here to run
     * the next. Batches for other entities (or any batch when we are not on
     * an edit-mode page) fire a headless POST to get server-side processing
     * started and stay stashed so the page that owns them picks them up
     * with full UI after navigation.
     */
    attemptTrigger() {
      if (this.inProgress) {
        return;
      }
      const list = this.readStash();
      if (list.length === 0) {
        return;
      }

      const deepchatElement = Drupal.behaviors.deepChatToggle?.chats?.[0];
      if (!deepchatElement) {
        log('deferred', 'No deepchat element yet — will retry on next attach');
        return;
      }

      const wrapper = document.querySelector(
        '.daedalus-ui-entity-wrapper[data-main-entity]',
      );
      const current = wrapper
        ? wrapper.dataset.daedalusUiEntityWrapper.split('::')
        : null;
      const isCurrent = (payload) =>
        current !== null &&
        current[0] === payload.entityType &&
        String(current[1]) === String(payload.entityId);

      list
        .filter((payload) => !isCurrent(payload))
        .forEach((payload) => {
          if (this.headlessFired.has(payload.batchId)) {
            return;
          }
          this.headlessFired.add(payload.batchId);
          log(
            'deferred',
            current === null
              ? 'Not on an edit-mode page — firing headless POST'
              : 'Different entity — firing headless POST',
            { batchId: payload.batchId },
          );
          this.fireAndForget(payload);
        });

      const next = list.find(isCurrent);
      if (next) {
        this.run(deepchatElement, next);
      }
    },

    /**
     * Runs deferred processing: shows loading bubble, POSTs to controller.
     *
     * @param {HTMLElement} deepchatElement
     *   The <deep-chat> element.
     * @param {object} payload
     *   The batch payload.
     */
    run(deepchatElement, payload) {
      this.inProgress = true;
      this.retryCount = 0;
      this.messages = { ...FALLBACK_MESSAGES, ...payload.messages };
      Drupal.behaviors.deepChatToggle.processing = true;
      deepchatElement.disableSubmitButton(true);

      this.applyPendingEffect(payload.targetKeys || []);

      deepchatElement.addMessage({
        role: 'ai',
        html: this.buildLoadingHtml(this.messages.loading),
      });
      const messageIndex = deepchatElement.getMessages().length - 1;

      log('deferred', 'Processing started', { batchId: payload.batchId });
      this.postProcess(deepchatElement, messageIndex, payload);
    },

    /**
     * POSTs to the deferred processing endpoint.
     *
     * @param {HTMLElement} deepchatElement
     *   The <deep-chat> element.
     * @param {number} messageIndex
     *   Index of the AI message bubble this run updates.
     * @param {object} payload
     *   The batch payload.
     */
    postProcess(deepchatElement, messageIndex, payload) {
      const url = Drupal.Daedalus.ModePluginBase.url(
        `/daedalus-ai/deferred/${payload.entityType}/${payload.entityId}/${payload.viewMode}`,
      );

      fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          batch_id: payload.batchId,
          // The payload's asset attachments are diffed against this; without
          // it every library re-ships and re-executing drupal.js would reset
          // window.Drupal.
          ajax_page_state: drupalSettings.ajaxPageState,
        }),
      })
        .then((r) => {
          if (!r.ok) {
            throw new Error(`Deferred processing request failed: ${r.status}`);
          }
          return r.json();
        })
        .then((data) => {
          this.handleResponse(deepchatElement, messageIndex, data, payload);
        })
        .catch((err) => {
          log('deferred', 'Error', err);
          deepchatElement.updateMessage(
            {
              role: 'ai',
              html: `<p>${Drupal.t(this.messages.error)}</p>`,
            },
            messageIndex,
          );
          this.complete(deepchatElement);
        });
    },

    /**
     * Processes the controller response: executes AJAX commands, retries failures.
     *
     * @param {HTMLElement} deepchatElement
     *   The <deep-chat> element.
     * @param {number} messageIndex
     *   Index of the AI message bubble this run updates.
     * @param {object} data
     *   The controller JSON response.
     * @param {object} payload
     *   The batch payload.
     */
    handleResponse(deepchatElement, messageIndex, data, payload) {
      // Another request is already processing this batch — poll until done.
      if (data.processing) {
        log('deferred', 'Batch being processed by another request, polling...');
        setTimeout(() => {
          this.postProcess(deepchatElement, messageIndex, payload);
        }, 2000);
        return;
      }

      const failures = data.failures || [];
      const hasUpdate = !!(data.markup && Object.keys(data.markup).length);

      // Apply the mutation-response payload (mirror replacement, subtree
      // swaps, assets, piggybacked highlight) through the app's one
      // pipeline. The facade is present whenever the edit-mode app booted;
      // without it there is nothing to swap and the change appears on the
      // next edit-mode load.
      if (hasUpdate && Drupal.Daedalus?.Blueprint?.applyResponse) {
        Drupal.Daedalus.Blueprint.applyResponse(data);
      }

      // Check for retryable failures.
      const retryable = failures.filter((f) => f.retryable);
      if (retryable.length > 0 && this.retryCount < MAX_RETRIES) {
        this.retryCount += 1;
        log(
          'deferred',
          `Retrying ${retryable.length} failures (attempt ${this.retryCount})`,
        );
        deepchatElement.updateMessage(
          {
            role: 'ai',
            html: this.buildLoadingHtml(this.messages.retry),
          },
          messageIndex,
        );
        this.postRetry(deepchatElement, messageIndex, retryable, payload);
        return;
      }

      // This batch is complete — remove ITS stash entry so it's not
      // re-triggered; other batches stay stashed for the drain below.
      this.removeFromStash(payload.batchId);

      // Build final message based on results.
      let html;
      if (failures.length === 0) {
        html = `<p>${Drupal.t(this.messages.success)}</p>`;
      } else if (hasUpdate) {
        html = `<p>${Drupal.t(this.messages.partial_success, {
          '@count': failures.length,
        })}</p>${this.buildFailureDetails(failures)}`;
      } else {
        html = `<p>${Drupal.t(
          this.messages.failure,
        )}</p>${this.buildFailureDetails(failures)}`;
      }

      deepchatElement.updateMessage({ role: 'ai', html }, messageIndex);
      this.complete(deepchatElement);

      // Drain: run the next stashed batch for this entity, if any. The
      // error paths above deliberately do NOT drain — their batch stays
      // stashed, and re-running it (or its successors) immediately would
      // loop on the same failure; a refresh retries instead.
      this.attemptTrigger();
    },

    /**
     * POSTs retryable failures to the retry endpoint.
     *
     * @param {HTMLElement} deepchatElement
     *   The <deep-chat> element.
     * @param {number} messageIndex
     *   Index of the AI message bubble this run updates.
     * @param {Array} failures
     *   The failure records.
     * @param {object} payload
     *   The batch payload.
     */
    postRetry(deepchatElement, messageIndex, failures, payload) {
      const url = Drupal.Daedalus.ModePluginBase.url(
        `/daedalus-ai/deferred-retry/${payload.entityType}/${payload.entityId}/${payload.viewMode}`,
      );

      fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          failures,
          ajax_page_state: drupalSettings.ajaxPageState,
        }),
      })
        .then((r) => {
          if (!r.ok) {
            throw new Error(`Retry request failed: ${r.status}`);
          }
          return r.json();
        })
        .then((data) => {
          this.handleResponse(deepchatElement, messageIndex, data, payload);
        })
        .catch((err) => {
          log('deferred', 'Retry error', err);
          deepchatElement.updateMessage(
            {
              role: 'ai',
              html: `<p>${Drupal.t(this.messages.error)}</p>`,
            },
            messageIndex,
          );
          this.complete(deepchatElement);
        });
    },

    /**
     * Fires the deferred POST without any UI.
     *
     * Used for a batch whose entity we are not viewing (e.g. create flow).
     * Kicks off server-side processing early; the batch stays stashed so
     * the correct page runs the full UI flow after navigation.
     *
     * @param {object} payload
     *   The batch payload.
     */
    fireAndForget(payload) {
      const url = Drupal.url(
        `daedalus-ai/deferred/${payload.entityType}/${payload.entityId}/${payload.viewMode}`,
      );
      fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ batch_id: payload.batchId }),
        keepalive: true,
      }).catch(() => {});
    },

    /**
     * Cleans up processing state and removes pending visual effects.
     *
     * @param {HTMLElement} deepchatElement
     *   The <deep-chat> element.
     */
    complete(deepchatElement) {
      this.inProgress = false;
      this.clearPendingEffect();
      Drupal.behaviors.deepChatToggle.processing = false;
      deepchatElement.disableSubmitButton(false);
      log('deferred', 'Processing complete');
    },

    /**
     * Applies a visual pending effect to the elements being processed.
     *
     * Targeting rides spine keys — the selectable keys stamped on every
     * controlled wrapper (the same identity the mutation response's markup
     * swaps use), so the effect lands on exactly the element the swap will
     * later replace.
     *
     * @param {string[]} targetKeys
     *   Selectable keys of the elements to mark as generating.
     */
    applyPendingEffect(targetKeys) {
      log('deferred', 'Applying pending effect', { targetKeys });
      targetKeys.forEach((key) => {
        const el = this.findPendingTarget(key);
        if (el) {
          el.classList.add('daedalus-ai-generating');
        } else {
          log('deferred', 'Element not found for pending effect', { key });
        }
      });
    },

    /**
     * Resolves a target key to the element the pending effect lands on.
     *
     * Whether a key is stamped is a render-time fact: a composed block hides
     * its field wrappers (the image block renders field_image itself), so a
     * block-field key may have no element. Degrade the same way the markup
     * swap does (SubtreeRenderer's all-or-fallback), but to the nearest
     * stamped ancestor rather than the root: the block root for a block
     * field, the entity wrapper last — a pending effect only needs to
     * contain the change, not fully reflect it.
     *
     * @param {string} key
     *   The selectable key of the element being processed.
     *
     * @return {Element|null}
     *   The element to mark, or null when nothing on the page contains it.
     */
    findPendingTarget(key) {
      const bySelectable = (k) =>
        document.querySelector(`[data-selectable="${CSS.escape(k)}"]`);
      const el = bySelectable(key);
      if (el) {
        return el;
      }
      const parts = key.split(':');
      if (parts[0] === 'block' && parts.length === 3) {
        const blockRoot = bySelectable(`block:${parts[1]}`);
        if (blockRoot) {
          return blockRoot;
        }
      }
      return document.querySelector(
        '.daedalus-ui-entity-wrapper[data-main-entity]',
      );
    },

    /**
     * Removes the pending visual effect from all components.
     */
    clearPendingEffect() {
      document.querySelectorAll('.daedalus-ai-generating').forEach((el) => {
        el.classList.remove('daedalus-ai-generating');
      });
    },

    /**
     * Builds an HTML details element summarizing failures.
     *
     * @param {Array} failures
     *   The failure records.
     */
    buildFailureDetails(failures) {
      const items = failures.map((f) => {
        const reason = Drupal.checkPlain(f.reason || 'Unknown error');
        return `<li>${reason}</li>`;
      });
      return `<details><summary>${Drupal.t(
        'Details',
      )}</summary><ul>${items.join('')}</ul></details>`;
    },

    buildLoadingHtml(message) {
      return `<div class="loading-wrapper"><span class="loading-span">${Drupal.t(
        message,
      )}</span></div>`;
    },
  };

  /**
   * AJAX command: append the batch to the stash list and attempt to trigger.
   *
   * @param {Drupal.Ajax} ajax
   *   The Drupal Ajax object.
   * @param {object} response
   *   The AJAX command response.
   */
  Drupal.AjaxCommands.prototype.daedalusAiTriggerDeferred =
    function daedalusAiTriggerDeferred(ajax, response) {
      const payload = {
        batchId: response.batchId,
        entityType: response.entityType,
        entityId: response.entityId,
        viewMode: response.viewMode,
        targetKeys: response.targetKeys || [],
        messages: response.messages || {},
        timestamp: Date.now(),
      };
      Drupal.Daedalus.AiDeferred.appendToStash(payload);
      log('deferred', 'Batch stashed', { batchId: payload.batchId });
      Drupal.Daedalus.AiDeferred.attemptTrigger();
    };

  /**
   * On page attach, check for stashed batches (covers the create flow).
   *
   * deepChatToggle.chats is populated at attach time, before deep-chat has
   * rendered its view; addMessage/getMessages are no-op stubs until then,
   * so the trigger must wait for the element's own 'render' event. The
   * upstream DrupalDeepchatInitialized event is NOT a reliable signal here:
   * deepchat-init only dispatches it from a 'render' listener it adds at
   * attach time, and on a freshly navigated page (exactly the create flow
   * this pickup exists for) the custom element can finish rendering before
   * behaviors attach, so that event never fires. Listen on the element
   * directly, and when it is already rendered kick on a macrotask so
   * deepchat-init's attach (which populates deepChatToggle.chats) has run
   * regardless of behavior order. attemptTrigger guards re-entry (stash
   * presence, inProgress), so a duplicate kick is harmless.
   */
  Drupal.behaviors.daedalusAiDeferredCheck = {
    attach(context) {
      once('daedalus-ai-deferred-check', 'deep-chat', context).forEach((el) => {
        const kick = () => Drupal.Daedalus.AiDeferred.attemptTrigger();
        el.addEventListener('render', kick, { once: true });
        if (el.shadowRoot?.childElementCount) {
          setTimeout(kick, 0);
        }
      });
    },
  };
})(jQuery, Drupal, once);
