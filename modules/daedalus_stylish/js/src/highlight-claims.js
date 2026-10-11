/**
 * The canvas-highlight claim stack: many claimants, one spec.
 *
 * Everything that can drive the canvas highlight — an advanced property
 * row, a basic chips row, the box-model diagram — is a CLAIMANT: it
 * claims a {key, selector, properties} spec while the user is touching it
 * (hover, a focus-held editor, a scrub) and releases when they stop.
 * The most recent claim wins (the diagram's own hover-over-open-editor
 * precedence generalized across rows), and a release falls back to the
 * NEXT claim instead of clearing, so leaving a hovered row while
 * another row's editor holds focus restores that row's highlight
 * rather than killing it. An empty stack clears the highlight.
 *
 * Without this arbitration every consumer would write
 * source.setCanvasHighlight directly, and the first row to clear on
 * pointer-leave would wipe a neighbor's held highlight — the classic
 * bespoke-wire drift the property-keyed spine exists to prevent.
 */

/**
 * Creates a claim stack over an apply callback.
 *
 * Pure and node-tested; the per-source wiring below binds apply to
 * source.setCanvasHighlight (which dedups repeated identical specs, so
 * re-claiming per hover event is safe).
 *
 * @param {function(?Object): void} apply
 *   Receives the winning spec after every change; null when no claims
 *   remain.
 *
 * @return {{claim: Function, release: Function, clear: Function}}
 */
export const createHighlightClaims = (apply) => {
  const stack = [];
  const applyTop = () =>
    apply(stack.length ? stack[stack.length - 1].spec : null);
  const remove = (owner) => {
    const index = stack.findIndex((claim) => claim.owner === owner);
    if (index === -1) {
      return false;
    }
    stack.splice(index, 1);
    return true;
  };

  /**
   * Claims the highlight for an owner (any stable identity). A repeat
   * claim updates the owner's spec and moves it to the top; a null
   * spec is a release.
   */
  const claim = (owner, spec) => {
    if (!spec) {
      release(owner);
      return;
    }
    remove(owner);
    stack.push({ owner, spec });
    applyTop();
  };

  /**
   * Releases an owner's claim; the next claim (or null) applies.
   * Unknown owners are a silent no-op, so unmount cleanups are safe.
   */
  const release = (owner) => {
    if (remove(owner)) {
      applyTop();
    }
  };

  /**
   * Drops every claim and clears the highlight.
   */
  const clear = () => {
    if (stack.length) {
      stack.length = 0;
      applyTop();
    }
  };

  return { claim, release, clear };
};

const registries = new WeakMap();

/**
 * The claim stack for a state source (leader or satellite), created on
 * first use. Any move of the styling facts wipes the claims: a
 * selection move, a subject facet move under the same selection (a part
 * row, a code-mode row header) AND a write address move under the same
 * subject (the switcher or "Edit at" seating a rule), all heard on the
 * source's one target-aware subscription (onTargetChange fires for any
 * of the three, the selection facade's rule). Why: every claim is keyed
 * at a write key (a row's `model.keys[property]`, the ring's family
 * write key), so a claim a focused row holds is a claim at the OLD key
 * once a fact moves; the source clears its highlight state on the same
 * move (transient edit chrome never outlives its address), and a
 * surviving claim would let a later release resurrect the stale key's
 * spec.
 *
 * @param {object} source
 *   A state source exposing setCanvasHighlight and onTargetChange.
 *
 * @return {{claim: Function, release: Function, clear: Function}}
 */
export const highlightClaimsFor = (source) => {
  let claims = registries.get(source);
  if (!claims) {
    claims = createHighlightClaims((spec) => source.setCanvasHighlight(spec));
    source.onTargetChange(() => claims.clear());
    registries.set(source, claims);
  }
  return claims;
};
