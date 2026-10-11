/**
 * The document notice's pure model: the host-level verdict as one line.
 *
 * The spine publishes what the actor may do at the root and why not
 * (the envelope's `verdict`, BlueprintElementAnnotator::hostVerdict); the
 * notice at the top of the page elements panel is the ONE place a
 * host-level refusal is explained, and the one place a portal is
 * offered: no chip, menu or inspector carries a door of its own. This
 * file words the verdict; document-notice.js renders it from the store.
 *
 * The notice explains the SCENARIOS that freeze a page: the layout
 * belongs to a template (FOREIGN_DOCUMENT, the portal "Edit the
 * template" when the verdict carries one with a url), a non-default
 * translation (LAYOUT_NOT_TRANSLATABLE) and a required workspace
 * (WORKSPACE_REQUIRED). A permission refusal is not a scenario: what a
 * role may not do is explained by the surfaces that do not mount for it
 * (the sidebars' applies gates), so the notice says nothing for it.
 */

/**
 * The refusal codes the notice words, in display order.
 */
const WORDED = ['FOREIGN_DOCUMENT', 'LAYOUT_NOT_TRANSLATABLE', 'WORKSPACE_REQUIRED'];

/**
 * The notice's lines for a host-level verdict.
 *
 * @param {?{can: Object<string, boolean>, refused: Array<{code: string, context?: object}>}} verdict
 *   The envelope's verdict, or null before the mirror boots.
 *
 * @return {Array<{code: string, text: string, portal: ?{label: string, url: string}}>}
 *   One line per worded refusal: its words, and the portal (label, url)
 *   when the verdict carries one that leads somewhere.
 */
export const noticeLines = (verdict) =>
  (verdict?.refused ?? [])
    .filter((refusal) => WORDED.includes(refusal.code))
    .sort((a, b) => WORDED.indexOf(a.code) - WORDED.indexOf(b.code))
    .map((refusal) => ({
      code: refusal.code,
      text: wordsFor(refusal),
      portal: portalOf(refusal),
    }));

/**
 * The words for one refusal.
 */
const wordsFor = (refusal) => {
  switch (refusal.code) {
    case 'FOREIGN_DOCUMENT':
      return Drupal.t('Layout from the template @label.', {
        '@label': refusal.context?.owner?.label ?? '',
      });
    case 'LAYOUT_NOT_TRANSLATABLE':
      return Drupal.t(
        'Layout and styles are shared across translations: edit them on the original.',
      );
    case 'WORKSPACE_REQUIRED':
      return Drupal.t('Changes require an active workspace.');
    default:
      return '';
  }
};

/**
 * The portal a refusal carries, when it leads somewhere.
 *
 * The spine names a portal only for an actor who may act there; the url
 * is NULL where the site has no such route, and then there is no door.
 */
const portalOf = (refusal) => {
  const url = refusal.context?.portal?.url;
  if (typeof url !== 'string' || url === '') {
    return null;
  }
  return { label: Drupal.t('Edit the template'), url };
};
