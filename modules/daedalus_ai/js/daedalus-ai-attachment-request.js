/**
 * @file
 * The chat's attachment request: deep-chat's multipart in, one JSON turn out.
 *
 * The chat request stays JSON. When the user attaches files, deep-chat
 * builds a multipart body (the files under `files`, the connect's body
 * props stringified, the messages as `message<n>` JSON strings) and hands
 * it to the requestInterceptor with body stringification off. The
 * interceptor (daedalus-ai-context-manager.js) does not send that body:
 * it uploads each file to daedalus_ai's attach route, which saves it
 * through the one file lane and answers the name the model addresses it
 * by, then re-encodes the turn as the JSON body every text turn already
 * sends, with `contexts.attachments` carrying the names. The bytes are
 * delivered once, by the server's ATTACHMENTS block; the names ride.
 *
 * Everything that reads or builds a body is pure and lives here so a node
 * spec can prove the encoding without a browser (tests/js/
 * attachment-request.test.mjs); only upload() touches the network.
 */

((Drupal) => {
  /**
   * The attachment request helpers.
   *
   * @namespace Drupal.Daedalus.AiAttachments
   */
  Drupal.Daedalus.AiAttachments = {
    /**
     * Reads the messages and files out of deep-chat's multipart body.
     *
     * deep-chat appends every attached file under `files` and every
     * message as `message<n>` (1-based, in order), and only appends the
     * last message when it has text: a files-only send carries no last
     * message. encode() restores the user's turn in that case.
     *
     * @param {FormData} formData
     *   deep-chat's multipart body.
     *
     * @return {{messages: Object[], files: File[]}}
     *   The messages in order and the attached files.
     */
    readMultipart(formData) {
      const messages = [];
      const files = [];
      Array.from(formData.entries()).forEach(([key, value]) => {
        if (key === 'files') {
          files.push(value);
          return;
        }
        const match = /^message(\d+)$/.exec(key);
        if (match) {
          messages[Number(match[1]) - 1] = JSON.parse(value);
        }
      });
      return { messages: messages.filter(Boolean), files };
    },

    /**
     * The names of the files over the lane's size ceiling.
     *
     * deep-chat has no size gate of its own; the lane refuses an oversize
     * upload, but refusing before the first byte leaves the server alone
     * and names every offender at once.
     *
     * @param {File[]} files
     *   The attached files.
     * @param {number} limit
     *   The ceiling in bytes.
     *
     * @return {string[]}
     *   The offending filenames.
     */
    oversized(files, limit) {
      return files.filter((file) => file.size > limit).map((file) => file.name);
    },

    /**
     * Encodes the turn as the JSON body a text turn sends.
     *
     * @param {Object} turn
     *   The turn.
     * @param {Object[]} turn.messages
     *   deep-chat's messages; each loses its `files` (the bytes never ride
     *   the JSON). When the last message is not the user's (deep-chat drops
     *   a text-less last message from its multipart body), an empty user
     *   message is appended so the turn is still the user's and the
     *   server's ATTACHMENTS block has a message to join.
     * @param {Object} turn.bodyProps
     *   The connect's additionalBodyProps (assistant_id, thread_id, stream,
     *   the current_route context, ...), taken from the element rather
     *   than the multipart body, which stringified them.
     * @param {Object} turn.contexts
     *   The context providers' collected values.
     * @param {string[]} turn.attachments
     *   The attached files' names, as the attach route answered them.
     * @param {Object} [turn.headers]
     *   The request's headers, if any.
     *
     * @return {{body: string, headers: Object}}
     *   The JSON string body and its headers, the shape deep-chat sends
     *   as returned when body stringification is off.
     */
    encode({ messages, bodyProps, contexts, attachments, headers = {} }) {
      const textOnly = messages.map(({ files, ...message }) => message);
      if (
        textOnly.length === 0 ||
        textOnly[textOnly.length - 1].role !== 'user'
      ) {
        textOnly.push({ role: 'user', text: '' });
      }
      const body = {
        messages: textOnly,
        ...bodyProps,
        contexts: {
          ...(bodyProps.contexts || {}),
          ...contexts,
          attachments,
        },
      };
      return {
        body: JSON.stringify(body),
        headers: { ...headers, 'Content-Type': 'application/json' },
      };
    },

    /**
     * Uploads the files to the attach route, one request each.
     *
     * The token rides the X-CSRF-Token header from session/token, the way
     * every page-builder post does. `_format=json` makes a refusal (a 400
     * naming the lane's reasons) answer JSON, so the reason reaches the
     * user instead of an HTML error page.
     *
     * @param {File[]} files
     *   The files to attach.
     * @param {string} threadId
     *   The conversation's thread id.
     *
     * @return {Promise<string[]>}
     *   The names the files were saved under (renamed on collision), in
     *   order.
     *
     * @throws {Error}
     *   When an upload is refused; the message names the file and the
     *   reason.
     */
    async upload(files, threadId) {
      const tokenResponse = await fetch(Drupal.url('session/token'), {
        credentials: 'same-origin',
      });
      const token = await tokenResponse.text();
      const names = [];
      for (const file of files) {
        const form = new FormData();
        form.append('file', file, file.name);
        form.append('thread_id', threadId);
        // eslint-disable-next-line no-await-in-loop
        const response = await fetch(Drupal.url('daedalus-ai/attach?_format=json'), {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-CSRF-Token': token },
          body: form,
        });
        const isJson = (response.headers.get('content-type') || '').includes(
          'application/json',
        );
        // eslint-disable-next-line no-await-in-loop
        const answer = isJson ? await response.json() : {};
        if (!response.ok) {
          throw new Error(
            Drupal.t('Attaching @name failed: @reason', {
              '@name': file.name,
              '@reason': answer.message || response.status,
            }),
          );
        }
        names.push(answer.name);
      }
      return names;
    },
  };
})(Drupal);
