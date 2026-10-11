import { h } from 'preact';
import { useSelector } from 'react-redux';
import { pencilIcon } from '../../verbs/rename-verb';
import { noticeLines } from './document-notice-model';

/**
 * The document notice: one line at the top of the page elements panel
 * explaining a host-level refusal, with the portal when the verdict
 * carries one (document-notice-model.js words it). Reads the store's
 * host-level verdict, which the envelope boots and every mutation
 * response refreshes, so it follows the page without a reload.
 */
export const DocumentNotice = () => {
  const verdict = useSelector((state) => state.blueprint.verdict);
  const lines = noticeLines(verdict);
  if (lines.length === 0) {
    return null;
  }
  return (
    <div className="daedalus-ui-document-notice" role="status">
      {lines.map((line) => (
        <p
          key={line.code}
          className="daedalus-ui-document-notice__line"
          data-refusal-code={line.code}
        >
          <span className="daedalus-ui-document-notice__text">{line.text}</span>
          {line.portal && (
            /* A real anchor, so Cmd-click opens the template in a new
               tab natively. */
            <a className="daedalus-ui-document-notice__portal" href={line.portal.url}>
              <span
                className="daedalus-ui-document-notice__icon"
                aria-hidden="true"
                dangerouslySetInnerHTML={{ __html: pencilIcon }}
              />
              {line.portal.label}
            </a>
          )}
        </p>
      ))}
    </div>
  );
};
