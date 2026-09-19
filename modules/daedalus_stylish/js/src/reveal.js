import { useEffect, useRef } from 'preact/hooks';

/**
 * Registers preact-rendered concealed chrome with the reveal engine.
 *
 * A [data-daedalus-ui-conceal] element is secondary chrome: hidden at its tier
 * until the pointer nears (daedalus_ui/reveal). Server markup is
 * auto-registered by a behavior at attach time; preact-rendered chrome
 * mounts after that, so it registers by hand through this hook. Guarded:
 * the satellite window renders without the engine (and without the html
 * density stamp, so the shared hiding CSS is inert there anyway).
 *
 * @param {number} [near]
 *   The reveal radius in px.
 *
 * @return {object}
 *   The ref to put on the concealed element.
 */
export const useConcealed = (near = 100) => {
  const ref = useRef(null);
  useEffect(() => {
    const el = ref.current;
    const reveal = Drupal.Daedalus?.reveal;
    if (!el || !reveal) {
      return undefined;
    }
    reveal.register(el, { near });
    return () => reveal.unregister(el);
  }, [near]);
  return ref;
};
