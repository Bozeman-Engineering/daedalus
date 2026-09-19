/**
 * The client mirror of the media token grammar (MediaToken.php) and the
 * preview-side resolution transform.
 *
 * PHP is authoritative: the pattern and the substitution semantics must
 * match \Drupal\daedalus_stylish\MediaToken exactly, and MediaClientMirrorTest locks
 * the two together by running a token battery through both sides. The
 * printers stay out of this entirely (they are the equivalence-locked dumb
 * pair): resolution is a pre-print map transform on the preview path, the
 * client half of the server's MediaValueResolver::resolveMap().
 *
 * The uuid -> URL table arrives as drupalSettings.daedalusStylish.mediaUrls, seeded
 * server-side from the host's style map (MediaValueResolver::clientUrlTable,
 * the same url() seam server printing resolves through) and extended
 * client-side when a pick's response carries the resolved URL. A uuid the
 * table cannot answer substitutes to "none", mirroring the server's
 * emit-gate degradation. The code editor never sees resolved values: it
 * reads the semantic map, and only the preview path transforms.
 */

/**
 * One token: url() wrapping the media scheme, quotes optional.
 *
 * MediaToken::PATTERN mirror, payload capture equally loose: whatever sits
 * in the uuid slot is handed to the resolver, and an unknown payload
 * degrades to "none" instead of leaking to the browser as a literal.
 */
const PATTERN = /url\(\s*(["']?)media:([^"'()\s]*)\s*\1\s*\)/gi;

/**
 * Whether a value mentions the media scheme at all (MediaToken::mentions).
 */
export const mentionsMedia = (value) =>
  typeof value === 'string' && value.toLowerCase().includes('media:');

/**
 * Replaces every token via a callback receiving the token's payload
 * (MediaToken::substitute mirror).
 */
export const substituteMediaTokens = (value, replacement) =>
  value.replace(PATTERN, (match, quote, uuid) => replacement(uuid));

/**
 * The first token's payload, lowercased for table lookup, or null.
 *
 * The media row's thumbnail read: a layered value ("gradient, token") shows
 * its first media layer's image. matchAll clones the regex, so the shared
 * PATTERN's lastIndex stays untouched.
 */
export const firstMediaUuid = (value) => {
  if (typeof value !== 'string' || !mentionsMedia(value)) {
    return null;
  }
  const first = value.matchAll(PATTERN).next().value;
  return first ? first[2].toLowerCase() : null;
};

/**
 * The payload when the value IS exactly one media token, or null.
 *
 * The representability read behind the basic-mode media posture: a
 * background-image that is a single url('media:…') layer and nothing
 * else is a state the Background widget can wear honestly (the well's
 * thumbnail face, the grid's lit unpick chip). Anchored on purpose,
 * unlike firstMediaUuid's first-layer read: a layered value or trailing
 * content is occupancy the pair state must keep reading as custom.
 * Lowercased for table lookup, like every client uuid key.
 */
export const mediaTokenUuid = (value) => {
  if (typeof value !== 'string' || !mentionsMedia(value)) {
    return null;
  }
  const match = value
    .trim()
    .match(/^url\(\s*(["']?)media:([^"'()\s]*)\s*\1\s*\)$/i);
  return match ? match[2].toLowerCase() : null;
};

/**
 * Resolves one value's media tokens from a uuid -> URL table.
 *
 * The per-value half of resolveMapMedia, shared with the preview's inline
 * delivery (which substitutes each staged value on its own, outside any
 * map). Table keys are lowercased uuids (the server seeds them that way),
 * so payload lookup lowercases; a missing or empty entry substitutes
 * "none", sound in every <image> slot. A non-string or token-free value is
 * returned as is.
 */
export const resolveMediaValue = (value, urls) => {
  if (typeof value !== 'string' || !mentionsMedia(value)) {
    return value;
  }
  return substituteMediaTokens(value, (uuid) => {
    const url = urls?.[String(uuid).toLowerCase()];
    return typeof url === 'string' && url !== '' ? `url("${url}")` : 'none';
  });
};

/**
 * Resolves a style map's media tokens from a uuid -> URL table.
 *
 * The client counterpart of MediaValueResolver::resolveMap(), walking the
 * same positions: condition-keyed property values only. The "variants"
 * reference list holds no CSS, so it stays untouched. Each value resolves
 * through resolveMediaValue (the table semantics live there).
 *
 * Copy-on-write: the input map is never mutated (the preview merges its
 * overlay over the live base map by reference), and a token-free map is
 * returned as the same reference.
 *
 * @param {object} map
 *   The style map (the preview's merged map).
 * @param {object} urls
 *   Lowercased media uuid -> root-relative URL.
 *
 * @return {object}
 *   The map with every token substituted; the input reference when no value
 *   holds a token.
 */
export const resolveMapMedia = (map, urls) => {
  let result = map ?? {};
  Object.entries(map ?? {}).forEach(([key, entry]) => {
    if (entry === null || typeof entry !== 'object') {
      return;
    }
    Object.entries(entry).forEach(([condition, properties]) => {
      if (
        condition === 'variants' ||
        properties === null ||
        typeof properties !== 'object'
      ) {
        return;
      }
      Object.entries(properties).forEach(([property, value]) => {
        if (typeof value !== 'string' || !mentionsMedia(value)) {
          return;
        }
        const substituted = resolveMediaValue(value, urls);
        if (result === map) {
          result = { ...map };
        }
        if (result[key] === map[key]) {
          result[key] = { ...map[key] };
        }
        if (result[key][condition] === map[key][condition]) {
          result[key][condition] = { ...map[key][condition] };
        }
        result[key][condition][property] = substituted;
      });
    });
  });
  return result;
};
