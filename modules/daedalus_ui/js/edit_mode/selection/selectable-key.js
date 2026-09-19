/**
 * Selectable-key parsing — the JS mirror of \Drupal\daedalus_blueprint_api\Render\SelectableKey.
 *
 * Keys are opaque strings everywhere else in the app (equality and DOM
 * lookup); this is the one place that reads their structure. Kinds are
 * SEMANTIC, not just the prefix: a block: key with a third segment is a
 * field OF an inline block, so its kind is 'field' — verbs and policies
 * care about what the user selected, not which entity family renders it.
 */

/**
 * Parses a selectable key.
 *
 * @param {?string} key
 *   The selectable key.
 *
 * @return {?{kind: string, uuid: string, name: ?string}}
 *   - kind: entity | field | container | block
 *   - uuid: the qualifying UUID (entity, section, or component).
 *   - name: the third segment when present (field/property name),
 *     otherwise null.
 */
export const parseKey = (key) => {
  if (!key) {
    return null;
  }
  const [prefix, uuid, ...rest] = key.split(':');
  const name = rest.length ? rest.join(':') : null;
  if (!uuid) {
    return null;
  }
  switch (prefix) {
    case 'entity':
      return { kind: 'entity', uuid, name: null };

    case 'field':
      return { kind: 'field', uuid, name };

    case 'container':
      return { kind: 'container', uuid, name: null };

    case 'block':
      // block:{component_uuid} is a placement; a third segment addresses a
      // named property of the inline block's content — semantically a field.
      return { kind: name ? 'field' : 'block', uuid, name };

    default:
      return null;
  }
};

/**
 * The semantic kind of a selectable key, or null.
 */
export const kindOf = (key) => parseKey(key)?.kind ?? null;
