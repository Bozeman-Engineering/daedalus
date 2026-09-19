/**
 * @file
 * Drives the chat-driven async generation flow.
 *
 * When the server queues a TriggerFollowupChatCommand, we stash the batch
 * to sessionStorage so it survives the navigation hop in the create-entity
 * flow, then attempt to run it. The behavior re-checks the stash on every
 * page attach, picking it up after navigation.
 *
 * Run flow:
 *   1. Find the active deepchat element.
 *   2. Add a synthetic AI bubble ("Painting your images…") and disable
 *      submit — same processing UX as a normal user-initiated chat turn.
 *   3. Direct-POST to deepchat URL with the LLM-facing message text. Loop
 *      while should_continue (mirrors getAllMessages in deepchat-init.js).
 *
 * The page refresh needs no leg of its own: every response is fed through
 * the deep-chat responseInterceptor below, so whatever the turn's tools
 * mutated arrives as mutation-response payloads (blueprint_responses) and
 * is applied by the command processor through the app's one applyResponse
 * pipeline, exactly like a user-typed turn.
 */

(($, Drupal, drupalSettings, once) => {
  const { log } = Drupal.Daedalus.logger('daedalus_ai');

  const STORAGE_KEY = 'daedalusAiFollowupBatch';
  const STALE_THRESHOLD_MS = 5 * 60 * 1000;
  const LOADING_COPY = 'Painting your images…';

  Drupal.Daedalus.AiFollowup = {
    inProgress: false,

    /**
     * Reads the stash and runs it if a deepchat element is available.
     */
    attemptTrigger() {
      if (this.inProgress) {
        return;
      }
      const raw = sessionStorage.getItem(STORAGE_KEY);
      if (!raw) {
        return;
      }
      let payload;
      try {
        payload = JSON.parse(raw);
      } catch (e) {
        sessionStorage.removeItem(STORAGE_KEY);
        return;
      }
      if (Date.now() - (payload.timestamp || 0) > STALE_THRESHOLD_MS) {
        log('followup', 'Stale batch, removing');
        sessionStorage.removeItem(STORAGE_KEY);
        return;
      }

      const deepchatElement = Drupal.behaviors.deepChatToggle.chats[0];
      if (!deepchatElement) {
        log('followup', 'No deepchat element yet — will retry on next attach');
        return;
      }

      sessionStorage.removeItem(STORAGE_KEY);
      this.run(deepchatElement, payload);
    },

    /**
     * Runs the follow-up chat turn and refreshes the page on completion.
     *
     * @param {HTMLElement} deepchatElement
     *   The <deep-chat> element.
     * @param {object} payload
     *   The batch payload.
     */
    run(deepchatElement, payload) {
      this.inProgress = true;
      Drupal.behaviors.deepChatToggle.processing = true;
      deepchatElement.disableSubmitButton(true);

      deepchatElement.addMessage({
        role: 'ai',
        html: this.buildLoadingHtml(),
      });
      const messageIndex = deepchatElement.getMessages().length - 1;

      log('followup', 'Run started', { batchId: payload.batchId });
      this.loop(deepchatElement, payload, messageIndex, payload.messageText);
    },

    /**
     * Posts one chat-turn iteration. Recurses on should_continue.
     *
     * @param {HTMLElement} deepchatElement
     *   The <deep-chat> element.
     * @param {object} payload
     *   The batch payload.
     * @param {number} messageIndex
     *   Index of the AI message bubble this run updates.
     * @param {string} userText
     *   The user text for this turn.
     */
    loop(deepchatElement, payload, messageIndex, userText) {
      const requestBody = {
        thread_id: drupalSettings.ai_deepchat.thread_id,
        assistant_id: drupalSettings.ai_deepchat.assistant_id,
        show_copy_icon: drupalSettings.ai_deepchat.show_copy_icon,
        structured_results: drupalSettings.ai_deepchat.structured_results,
        messages: [{ role: 'user', text: userText }],
      };

      fetch(deepchatElement.connect.url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(requestBody),
      })
        .then((r) => {
          if (!r.ok) {
            throw new Error(`Followup chat request failed: ${r.status}`);
          }
          return r.json();
        })
        .then((data) => {
          if (deepchatElement.responseInterceptor) {
            data = deepchatElement.responseInterceptor(data);
          }
          if (data && data.should_continue) {
            const stepHtml = this.buildLoadingHtml(data.html || '');
            deepchatElement.updateMessage(
              { role: 'ai', html: stepHtml },
              messageIndex,
            );
            // Continuation: send a dummy user message to advance the loop —
            // matches the convention used by getAllMessages in deepchat-init.js.
            this.loop(deepchatElement, payload, messageIndex, 'dummy_loading');
          } else {
            let finalHtml = '';
            if (data && data.error) {
              finalHtml = `<p>${data.error}</p>`;
            } else if (data && data.html) {
              finalHtml = data.html;
            }
            deepchatElement.updateMessage(
              { role: 'ai', html: finalHtml },
              messageIndex,
            );
            this.complete(deepchatElement, payload);
          }
        })
        .catch((err) => {
          log('followup', 'Error', err);
          deepchatElement.updateMessage(
            {
              role: 'assistant',
              html: `<p>${Drupal.t(
                'Image generation could not complete. Refresh and try again.',
              )}</p>`,
            },
            messageIndex,
          );
          this.complete(deepchatElement, payload);
        });
    },

    /**
     * Cleans up chat state. The turn's mutations already applied themselves:
     * each loop response rode through the responseInterceptor, whose command
     * processor applies the injected mutation-response payloads.
     *
     * @param {HTMLElement} deepchatElement
     *   The <deep-chat> element.
     * @param {object} payload
     *   The batch payload.
     */
    complete(deepchatElement, payload) {
      this.inProgress = false;
      Drupal.behaviors.deepChatToggle.processing = false;
      deepchatElement.disableSubmitButton(false);
      log('followup', 'Run complete', { batchId: payload.batchId });
    },

    buildLoadingHtml(stepDetailsHtml = '') {
      let html = `<div class="loading-wrapper"><span class="loading-span">${Drupal.t(
        LOADING_COPY,
      )}</span>`;
      if (stepDetailsHtml) {
        html += `<details class="step-messages loading-text"><summary class="step-messages-summary">${Drupal.t(
          'Details',
        )}</summary>${stepDetailsHtml}</details>`;
      }
      html += '</div>';
      return html;
    },
  };

  /**
   * AJAX command from the server: stash the batch and attempt to trigger.
   *
   * The stash survives the navigation hop in the create-entity flow; the
   * behavior below picks it up on the destination page.
   *
   * @param {Drupal.Ajax} ajax
   *   The Drupal Ajax object.
   * @param {object} response
   *   The AJAX command response.
   */
  Drupal.AjaxCommands.prototype.daedalusAiTriggerFollowup =
    function daedalusAiTriggerFollowup(ajax, response) {
      const payload = {
        batchId: response.batchId,
        messageText: response.messageText,
        entityType: response.entityType,
        entityId: response.entityId,
        timestamp: Date.now(),
      };
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
      log('followup', 'Batch stashed', { batchId: payload.batchId });
      Drupal.Daedalus.AiFollowup.attemptTrigger();
    };

  /**
   * On every page attach, check the stash and attempt to run.
   *
   * Covers the create flow: the server-queued command stashes on the source
   * page, the browser navigates, and this behavior fires on the destination.
   */
  Drupal.behaviors.daedalusAiFollowupCheck = {
    attach() {
      once('daedalus-ai-followup-check', document.body).forEach(() => {
        // deepChatToggle.chats is populated before deep-chat has rendered
        // its view (its methods are no-op stubs until then), so wait for
        // DrupalDeepchatInitialized, which fires after all chats render.
        document.addEventListener(
          'DrupalDeepchatInitialized',
          () => {
            Drupal.Daedalus.AiFollowup.attemptTrigger();
          },
          { once: true },
        );
      });
    },
  };
})(jQuery, Drupal, drupalSettings, once);
