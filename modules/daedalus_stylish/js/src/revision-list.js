import { h } from 'preact';

/**
 * One revision row's date face: the record's unix seconds in the viewer's
 * locale. The cards' restore confirmations and status lines speak the
 * same face, so the row and the message name one moment the same way.
 */
export const revisionDate = (created) =>
  new Date(created * 1000).toLocaleString();

/**
 * A material's revision history under its lifecycle card: one row per
 * revision as the revisions route lists them, the current one marked and
 * every other one a restore chip. The variant, swatch and animation cards
 * wear this one face; each hands in its own `onRestore`, since confirming
 * a restore is the card's gesture (its usage read, its material's wording,
 * its API), and the list only offers the row.
 *
 * A history of one is no history: null (not fetched yet) or a single
 * revision renders nothing, so a card mounts the list as soon as it can
 * restore and never repeats the rule.
 */
export const RevisionList = ({ revisions, busy = false, onRestore }) => {
  if (!revisions || revisions.length < 2) {
    return null;
  }
  return (
    <div className="daedalus-stylish-library-card__revisions">
      {revisions.map((revision) => (
        <div
          key={revision.revision_id}
          className={`daedalus-stylish-library-card__revision${revision.current ? ' daedalus-stylish-library-card__revision--current' : ''}`}
        >
          <span className="daedalus-stylish-library-card__revision-date">
            {revisionDate(revision.created)}
            {revision.user ? ` · ${revision.user}` : ''}
          </span>
          {revision.current ? (
            <span className="daedalus-stylish-library-card__revision-current">
              {Drupal.t('current')}
            </span>
          ) : (
            <button
              type="button"
              className="daedalus-ui-chip"
              disabled={busy}
              title={Drupal.t(
                'Restore this revision as a new one on top, never a rollback, so a restore is itself one restore away from undo.',
              )}
              onClick={() => onRestore(revision)}
            >
              {Drupal.t('restore')}
            </button>
          )}
        </div>
      ))}
    </div>
  );
};
