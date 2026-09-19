/**
 * The window-pairing wire: a thin envelope over BroadcastChannel.
 *
 * Shared machinery for any editor surface that pairs a secondary window
 * with the editing page over one edit session (daedalus_stylish's popped-out
 * inspector, the responsive preview). The channel name is
 * `<prefix>:<scope>`: the prefix keeps consumers off each other's wire,
 * and the scope derives deterministically from the edit session (entity
 * uuid + langcode + workspace, computed server-side by EditSessionScope).
 * Deterministic naming is what lets either window refresh and rejoin;
 * nothing about the pairing lives in memory that a reload would lose.
 * BroadcastChannel is same-origin and never echoes to the posting context,
 * so both windows share one name with no self-delivery filtering.
 *
 * Messages are `{ type, ...payload }` envelopes dispatched to the matching
 * handler; each consumer defines its own protocol on top (see daedalus_stylish's
 * popout-leader.js for the inspector's hello/snapshot/intent protocol).
 * The envelope owns the `type` key: it always names the frame, never a
 * payload field. post() writes it last so a payload key spelled `type`
 * can never retarget dispatch (a stray `{type: 'close'}` payload must not
 * close a window), and receipt destructures it away, so a payload field
 * named `type` never survives the wire — consumers pick another name.
 *
 * @param {string} prefix
 *   The consumer's channel namespace (e.g. 'daedalus_stylish').
 * @param {string} scope
 *   The edit-session scope, computed server-side.
 * @param {Object<string, Function>} handlers
 *   Message handlers keyed by message type; each receives the payload.
 */
export const openChannel = (prefix, scope, handlers) => {
  const channel = new BroadcastChannel(`${prefix}:${scope}`);
  channel.onmessage = (event) => {
    const { type, ...payload } = event.data ?? {};
    handlers[type]?.(payload);
  };
  return {
    post: (type, payload = {}) => channel.postMessage({ ...payload, type }),
    close: () => channel.close(),
  };
};
