/**
 * Pure model for the swatch kind registry (node-testable, no JSX).
 *
 * The color kind's mint seed: always the neutral wheel-ownable hex.
 * Strict governs consumers, never authors — the palette card is the
 * sanctioned extension point strictness funnels people toward, so the
 * seed never redirects to a palette reference on strict sites. The
 * `guide` argument of the seed contract is deliberately ignored.
 *
 * @return {string}
 *   The neutral starting hex for a fresh color mint.
 */
export const colorSeed = () => '#808080';
