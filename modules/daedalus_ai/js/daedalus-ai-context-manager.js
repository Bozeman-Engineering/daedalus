/**
 * @file
 * Centralized request context manager for Daedalus AI.
 *
 * Replaces per-feature interceptor chaining with a provider-based pattern.
 * Features register named providers that return context values; the manager
 * collects all providers on every DeepChat request and injects them into
 * the request body.
 *
 * Wraps deepchat-init.js's requestInterceptor by deferring install
 * until after all Drupal behaviors have attached. The same interceptor
 * turns a turn with attachments (deep-chat's multipart body) into the JSON
 * turn every text turn sends, the files uploaded to the attach route first
 * and their names riding as contexts.attachments; see install().
 */

(($, Drupal, drupalSettings, once) => {
  /**
   * Daedalus AI context manager.
   *
   * @namespace Drupal.Daedalus.AiContext
   */
  Drupal.Daedalus.AiContext = {
    providers: {},
    installed: false,

    /**
     * Registers a named context provider.
     *
     * @param {string} name
     *   The context key (e.g. 'selected_elements', 'view_mode').
     * @param {Function} callback
     *   A function returning the context value, or null/undefined to skip.
     */
    register(name, callback) {
      this.providers[name] = callback;
    },

    /**
     * Unregisters a named context provider.
     *
     * @param {string} name
     *   The context key to remove.
     */
    unregister(name) {
      delete this.providers[name];
    },

    /**
     * Collects context values from all registered providers.
     *
     * @return {Object}
     *   Map of context key to value, excluding null/undefined results.
     */
    collect() {
      const contexts = {};
      Object.entries(this.providers).forEach(([name, callback]) => {
        try {
          const value = callback();
          if (value !== undefined && value !== null) {
            contexts[name] = value;
          }
        } catch (e) {
          // Provider not available (e.g. Daedalus not loaded), skip.
        }
      });
      return contexts;
    },

    /**
     * Installs the request interceptor on DeepChat.
     *
     * Wraps any existing interceptor so other modules' interceptors continue
     * to work (ai_chatbot's runs first: it mints the session token on the
     * first turn and returns nothing). Only installs once.
     *
     * A text turn's body is a plain object deep-chat stringifies after the
     * interceptor, so the collected contexts are merged into it in place.
     * A turn with attachments arrives as deep-chat's multipart body, which
     * is never sent: the files are uploaded to daedalus_ai's attach route
     * (the one file lane, one request each, the answered name being the
     * handle the model addresses the file by) and the turn is re-encoded
     * as the same JSON body a text turn sends, its `contexts.attachments`
     * carrying the names (daedalus-ai-attachment-request.js). An upload
     * refused, or a file over the lane's ceiling, cancels the send with
     * the reason as deep-chat's error message; the user's bubble already
     * shows the attachment, so nothing is hidden by the re-encoding.
     */
    install() {
      const deepchat = document.querySelector('deep-chat');
      if (!deepchat || this.installed) {
        return;
      }

      const existingInterceptor = deepchat.requestInterceptor;
      deepchat.requestInterceptor = async (request) => {
        const collected = Drupal.Daedalus.AiContext.collect();
        if (request.body instanceof FormData) {
          const attachments = Drupal.Daedalus.AiAttachments;
          const settings = drupalSettings.daedalus_ai.attachments;
          const { messages, files } = attachments.readMultipart(request.body);
          const oversized = attachments.oversized(files, settings.size_limit);
          if (oversized.length > 0) {
            return {
              error: Drupal.t('@names: over the @limit MB limit.', {
                '@names': oversized.join(', '),
                '@limit': Math.floor(settings.size_limit / (1024 * 1024)),
              }),
            };
          }
          if (existingInterceptor) {
            await existingInterceptor(request);
          }
          let names;
          try {
            names = await attachments.upload(
              files,
              drupalSettings.ai_deepchat.thread_id,
            );
          } catch (error) {
            // The refusal is the user's to see: deep-chat renders the
            // interceptor's error and does not send.
            return { error: error.message };
          }
          return attachments.encode({
            messages,
            bodyProps: deepchat.connect.additionalBodyProps || {},
            contexts: collected,
            attachments: names,
            headers: request.headers,
          });
        }
        if (Object.keys(collected).length > 0) {
          if (!request.body.contexts) {
            request.body.contexts = {};
          }
          Object.assign(request.body.contexts, collected);
        }
        return existingInterceptor ? existingInterceptor(request) : request;
      };
      this.installed = true;
    },
  };

  Drupal.behaviors.daedalusAiContextManager = {
    attach(context) {
      once('daedalus-ai-context', 'deep-chat', context).forEach(() => {
        Drupal.Daedalus.AiContext.install();
      });
    },
  };

  // INTERIM SHIM until ai_chatbot runs requestInterceptor on continuation
  // requests (unfixed on ai 1.4.x HEAD; delete this wrapper when the
  // upstream fix lands). deepchat-init.js's getAllMessages
  // loop runs responseInterceptor on every response but hand-builds its
  // dummy_loading request with bare fetch, bypassing requestInterceptor,
  // and the function is private to its IIFE, so there is no seam to hook.
  // daedalus-ai-followup-chat.js shares the same gap. A turn reaching the
  // server with no contexts loses view_mode and the AI context providers,
  // and a mutation flushing on it rendered its payload against an empty
  // ajax_page_state, re-shipping the entire script stack (re-executing
  // drupal.js resets window.Drupal). This fetch-level guard restores the
  // invariant: any POST to the deepchat API whose JSON body lacks
  // `contexts` gets the collected providers, whichever script built the
  // request. The server-side half of the defense (a stateless request
  // ships no asset commands) lives in daedalus_blueprint_api's
  // SubtreeRenderer.
  const originalFetch = window.fetch;
  window.fetch = function daedalusAiContextFetch(resource, options) {
    try {
      const connectUrl = document.querySelector('deep-chat')?.connect?.url;
      if (connectUrl && options?.body && typeof options.body === 'string') {
        const url = typeof resource === 'string' ? resource : resource?.url;
        if (
          url &&
          new URL(url, window.location.origin).pathname ===
            new URL(connectUrl, window.location.origin).pathname
        ) {
          const body = JSON.parse(options.body);
          if (body && typeof body === 'object' && !body.contexts) {
            const collected = Drupal.Daedalus.AiContext.collect();
            if (Object.keys(collected).length > 0) {
              body.contexts = collected;
              options = { ...options, body: JSON.stringify(body) };
            }
          }
        }
      }
    } catch (e) {
      // Never let context injection break the send itself.
    }
    return originalFetch.call(this, resource, options);
  };
})(jQuery, Drupal, drupalSettings, once);
