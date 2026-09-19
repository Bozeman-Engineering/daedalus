/**
 * Client-side constraint validation for one property value.
 *
 * A JS mirror of PropertyGroupBase::validate() — same value semantics, same
 * error codes, same style-guide hints — driving the code editor's lint
 * diagnostics. This is a convenience for immediate feedback; the server
 * revalidates every commit and never trusts the client. The property
 * definitions and constraints both arrive in the style guide payload
 * (guide.groups / the served node's value spaces, the roster's resolved
 * blocks), so the two validators read the same answers.
 *
 * Baked-in semantics kept in lockstep with the PHP side:
 * - "allowed" is exhaustive: verbatim match, nothing else passes.
 * - A "scale" constraint closes the value space to the scale's minted
 *   tokens as var() references plus any allowed entries beside it
 *   (StyleMapValidator::validateScaleValue()); needs the tokens context —
 *   without it the check defers to the server.
 * - var()/calc() values and CSS-wide keywords pass structurally and skip
 *   range checks (pass-through), but a var() reference that resolves
 *   to a declared scale's namespace is checked against the minted tokens:
 *   severity 'warning' on permissive sites (the server accepts; an
 *   unresolved var is inert, never unsafe), 'error' on strict ones (the
 *   server rejects). Violations carry an optional `severity` field;
 *   absent means error.
 * - A range-constrained value with an incomparable unit is a violation, not
 *   a bypass; px/pt/rem/em compare on a 16px basis (em treated as rem).
 * - A "list" definition splits at top-level commas/whitespace (function
 *   notation stays one component) and validates each component; "number"
 *   on a dimension is the dimension|number union; "ident" is custom-ident
 *   grammar with CSS-wide keywords, "default", and "--" reserved;
 *   "animation_name" is the same grammar with its own corrective.
 * - The keyframes names a motion value wears (animation-name components,
 *   the animation shorthand's name layers; keyframes-names.js splits
 *   them as KeyframesNames.php does) are checked against the published
 *   animations in the roster's animation slot, the reference posture
 *   again: a motion-{name} ident naming no published animation warns on
 *   permissive sites and errors on strict ones; a plain ident (a theme's
 *   own keyframes) passes permissive and errors strict, where the closed
 *   world curates only site animations.
 */

import {
  animationReference,
  swatchReference,
  tokenReference,
} from '../style-guide.js';
import { animationOptions, scaleTokens, styleReference } from '../materials.js';
import { keyframesNamesInValue } from '../keyframes-names.js';
import {
  resolveValue,
  KIND,
  SLOT,
} from '../../../../daedalus_ui/js/edit_mode/materials/grammar.js';

const PX_BASIS = { px: 1.0, pt: 4 / 3, rem: 16.0, em: 16.0 };

// Length and viewport units plus the time, angle, and flex units the wide
// property tier's classifier maps onto the dimension type. Mirrors
// PropertyGroupBase::UNITS.
const UNITS = [
  'px',
  'rem',
  'em',
  '%',
  'vw',
  'vh',
  'vmin',
  'vmax',
  'ch',
  'ex',
  'pt',
  'svh',
  'svw',
  'dvh',
  'dvw',
  'lvh',
  'lvw',
  's',
  'ms',
  'deg',
  'grad',
  'rad',
  'turn',
  'fr',
];

const CSS_WIDE_KEYWORDS = [
  'inherit',
  'initial',
  'unset',
  'revert',
  'revert-layer',
];

const DIMENSION_PATTERN = new RegExp(
  `^(-?(?:\\d+(?:\\.\\d+)?|\\.\\d+))(${UNITS.join('|')})?$`,
);

/**
 * Parses a single CSS dimension component.
 *
 * @return {?[number, string]}
 *   [number, unit] — unit '' for a bare number or unitless zero — or null.
 */
const parseDimension = (component, allowNegative, allowNumber) => {
  const match = component.match(DIMENSION_PATTERN);
  if (!match) {
    return null;
  }
  const number = parseFloat(match[1]);
  const unit = match[2] ?? '';
  if (number < 0 && !allowNegative) {
    return null;
  }
  if (unit === '' && number !== 0 && !allowNumber) {
    return null;
  }
  return [number, unit];
};

/**
 * Compares a parsed value to a bound; null when not comparable.
 */
const compare = (number, unit, bound) => {
  const parsed = parseDimension(String(bound), true, true);
  if (parsed === null) {
    return null;
  }
  const [boundNumber, boundUnit] = parsed;
  const a =
    unit === boundUnit
      ? number
      : PX_BASIS[unit] !== undefined && PX_BASIS[boundUnit] !== undefined
        ? number * PX_BASIS[unit]
        : null;
  const b =
    unit === boundUnit
      ? boundNumber
      : PX_BASIS[unit] !== undefined && PX_BASIS[boundUnit] !== undefined
        ? boundNumber * PX_BASIS[boundUnit]
        : null;
  if (a === null || b === null) {
    return null;
  }
  return a < b ? -1 : a > b ? 1 : 0;
};

const checkRange = (property, component, number, unit, constraints) => {
  const errors = [];
  for (const [bound, violationSign] of [
    ['min', -1],
    ['max', 1],
  ]) {
    if (constraints[bound] === undefined || constraints[bound] === null) {
      continue;
    }
    const comparison = compare(number, unit, constraints[bound]);
    if (comparison === null) {
      errors.push({
        code: 'STYLE_VALUE_NOT_COMPARABLE',
        message: `"${component}" cannot be checked against the ${bound} of ${constraints[bound]} for ${property}.`,
        hint: `Use a unit comparable to ${constraints[bound]} (px, pt, rem, and em are interchangeable).`,
      });
    } else if (comparison === violationSign) {
      errors.push({
        code:
          bound === 'max' ? 'STYLE_VALUE_ABOVE_MAX' : 'STYLE_VALUE_BELOW_MIN',
        message: `"${component}" is ${bound === 'max' ? 'above' : 'below'} the ${bound} of ${constraints[bound]} for ${property}.`,
        hint: `The style guide requires a value ${bound === 'max' ? 'of at most' : 'of at least'} ${constraints[bound]}.`,
      });
    }
  }
  return errors;
};

const validateDimension = (property, value, definition, constraints) => {
  const components = value.split(/\s+/);
  const arity = definition.repeat ?? 1;
  if (components.length > arity) {
    return [
      {
        code: 'INVALID_STYLE_VALUE',
        message: `"${value}" has too many values for ${property}.`,
        hint: `${property} accepts at most ${arity} space-separated value(s).`,
      },
    ];
  }
  const errors = [];
  for (const component of components) {
    if ((definition.keywords ?? []).includes(component.toLowerCase())) {
      continue;
    }
    const parsed = parseDimension(
      component,
      Boolean(definition.negative),
      Boolean(definition.number),
    );
    if (parsed === null) {
      errors.push({
        code: 'INVALID_STYLE_VALUE',
        message: `"${component}" is not a valid value for ${property}.`,
        hint: `Provide a CSS dimension such as "1.5rem"${definition.number ? ' or a unitless number' : ''}${definition.negative ? '' : ' (negative values are not valid here)'}.`,
      });
      continue;
    }
    const [number, unit] = parsed;
    if (
      Array.isArray(constraints.units) &&
      unit !== '' &&
      !constraints.units.includes(unit)
    ) {
      errors.push({
        code: 'STYLE_UNIT_NOT_ALLOWED',
        message: `The unit of "${component}" is not allowed for ${property}.`,
        hint: `The style guide allows only these units: ${constraints.units.join(', ')}.`,
      });
      continue;
    }
    errors.push(...checkRange(property, component, number, unit, constraints));
  }
  return errors;
};

const validateNumber = (property, value, definition, constraints) => {
  if ((definition.keywords ?? []).includes(value.toLowerCase())) {
    return [];
  }
  const number = Number(value);
  if (
    value.trim() === '' ||
    Number.isNaN(number) ||
    (!definition.negative && number < 0)
  ) {
    const keywords = definition.keywords?.length
      ? ` or one of: ${definition.keywords.join(', ')}`
      : '';
    return [
      {
        code: 'INVALID_STYLE_VALUE',
        message: `"${value}" is not a valid value for ${property}.`,
        hint: `Provide a number${keywords}.`,
      },
    ];
  }
  return checkRange(property, value, number, '', constraints);
};

/**
 * Validates a custom-ident value ('ident' type).
 *
 * Identifier grammar: letters, digits, hyphens, underscores, not starting
 * with a digit. CSS-wide keywords, "default", and "--" prefixes are
 * reserved per the <custom-ident> grammar; the definition's keywords ride
 * along as extra accepted values. Mirrors PropertyGroupBase::validateIdent.
 */
const validateIdent = (property, value, definition) => {
  if ((definition.keywords ?? []).includes(value.toLowerCase())) {
    return [];
  }
  const reserved = [...CSS_WIDE_KEYWORDS, 'default'];
  if (
    !reserved.includes(value.toLowerCase()) &&
    /^-?[A-Za-z_][A-Za-z0-9_-]*$/.test(value)
  ) {
    return [];
  }
  return [
    {
      code: 'INVALID_STYLE_VALUE',
      message: `"${value}" is not a valid identifier for ${property}.`,
      hint: 'Provide a CSS identifier: letters, digits, hyphens, and underscores, not starting with a digit ("default" and "--" prefixes are reserved).',
    },
  ];
};

/**
 * Validates a keyframes name ('animation_name' type).
 *
 * The ident grammar exactly (a keyframes name IS a custom-ident) with the
 * definition's keywords (`none`) riding along, and a corrective that
 * names the motion-{name} spelling. Which animation a name resolves to is
 * the reference check's question (checkAnimationReferences, over the
 * roster); this only says whether the spelling could be a name at all.
 * Mirrors PropertyGroupBase::validateAnimationName.
 */
const validateAnimationName = (property, value, definition) => {
  if (validateIdent(property, value, definition).length === 0) {
    return [];
  }
  return [
    {
      code: 'INVALID_STYLE_VALUE',
      message: `"${value}" is not a keyframes name for ${property}.`,
      hint: 'Provide a keyframes name: a site animation\'s motion-{name} spelling (see the materials roster), "none", or a theme keyframes identifier (letters, digits, hyphens, and underscores, not starting with a digit; "default" and "--" prefixes are reserved).',
    },
  ];
};

const validateKeyword = (property, value, definition) => {
  if ((definition.keywords ?? []).includes(value.toLowerCase())) {
    return [];
  }
  return [
    {
      code: 'INVALID_STYLE_VALUE',
      message: `"${value}" is not a valid value for ${property}.`,
      hint: `Use one of: ${(definition.keywords ?? []).join(', ')}.`,
    },
  ];
};

const validateColor = (property, value) => {
  const valid =
    /^#([0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test(value) ||
    /^(rgb|rgba|hsl|hsla|hwb|lab|lch|oklab|oklch|color|light-dark|color-mix)\(/i.test(
      value,
    ) ||
    /^[a-z]{3,25}$/i.test(value);
  if (valid) {
    return [];
  }
  return [
    {
      code: 'INVALID_STYLE_VALUE',
      message: `"${value}" is not a valid color for ${property}.`,
      hint: 'Provide a CSS color such as "#1a1a2e", "rgb(26 26 46)", or a named color.',
    },
  ];
};

/**
 * Splits a list value into components at top-level commas and whitespace.
 *
 * Parenthesized groups stay intact (function notation keeps its inner
 * commas and spaces). Returns null for a malformed list: an empty comma
 * segment (leading, trailing, or doubled comma) or unbalanced parentheses.
 * Mirrors PropertyGroupBase::splitList.
 *
 * @return {?string[]}
 */
const splitList = (value) => {
  const components = [];
  let current = '';
  let depth = 0;
  let pendingComma = false;
  for (const char of value) {
    if (char === '(') {
      depth++;
    } else if (char === ')' && --depth < 0) {
      return null;
    }
    if (depth === 0 && (char === ',' || /\s/.test(char))) {
      if (current !== '') {
        components.push(current);
        current = '';
        pendingComma = false;
      }
      if (char === ',') {
        if (pendingComma || components.length === 0) {
          return null;
        }
        pendingComma = true;
      }
      continue;
    }
    current += char;
  }
  if (depth !== 0) {
    return null;
  }
  if (current !== '') {
    components.push(current);
    pendingComma = false;
  }
  return pendingComma ? null : components;
};

const validateText = (property, value) => {
  if (/expression\s*\(|(?:javascript|vbscript)\s*:/i.test(value)) {
    return [
      {
        code: 'INVALID_STYLE_VALUE',
        message: `"${value}" is not a valid value for ${property}.`,
        hint: 'Script URL schemes and expression() are not valid in style values.',
      },
    ];
  }
  return [];
};

/**
 * Checks a value's var() references against the palette vocabulary.
 *
 * The client half of "warn permissive / error strict"
 * (StyleMapValidator::checkTokenReferences() is the server half): a var()
 * reference resolving to a declared scale's namespace must name a minted
 * token, and one inside the reserved swatch namespace must name a
 * published swatch (StyleMapValidator::checkSwatchReference()'s twin — all
 * published swatches are live, so the served list IS the legal universe).
 * Out-of-namespace variables pass everywhere — the palette spine owns
 * exactly its declared prefixes plus the reserved swatch prefix.
 *
 * @param {string} property
 *   The CSS property name (for messages).
 * @param {string} value
 *   The value holding var() references.
 * @param {?object} tokens
 *   The materials context: {guide, materials, closed: propertiesClosed(guide)}
 *   (the guide's tokenScales are the namespaces, the materials roster is
 *   what is minted and published); null skips the check.
 *
 * @return {Array<{code: string, message: string, hint: string, severity: string}>}
 */
const checkTokenReferences = (property, value, tokens) => {
  if (!tokens || !value.includes('var(')) {
    return [];
  }
  const violations = [];
  const seen = new Set();
  for (const match of value.matchAll(/var\(\s*(--[A-Za-z0-9_-]+)/g)) {
    const variable = match[1];
    if (seen.has(variable)) {
      continue;
    }
    seen.add(variable);
    const resolved = resolveValue(
      `var(${variable})`,
      SLOT.STYLE,
      tokens.materials,
    );
    const swatch = swatchReference(variable);
    if (swatch !== null) {
      if (resolved.kind !== KIND.SWATCH) {
        violations.push({
          code: 'STYLE_SWATCH_NOT_PUBLISHED',
          message: `"${variable}" is in the swatch namespace, but no published "${swatch}" swatch exists.`,
          hint: tokens.closed
            ? 'Mint the swatch first, or reference a published one; this site enforces its style guide.'
            : 'It will not resolve until the swatch is published; the reference is inert, not unsafe.',
          severity: tokens.closed ? 'error' : 'warning',
        });
      }
      continue;
    }
    const reference = tokenReference(variable, tokens.guide?.tokenScales);
    if (reference === null) {
      continue;
    }
    if (resolved.kind !== KIND.TOKEN) {
      violations.push({
        code: 'STYLE_TOKEN_NOT_MINTED',
        message: `"${variable}" is in the "${reference.scale}" scale's namespace, but no "${reference.name}" token is minted on the active skin.`,
        hint: tokens.closed
          ? 'Mint the token on the active skin, or reference a minted one; this site enforces its style guide.'
          : 'It will not resolve until the token is minted; the reference is inert, not unsafe.',
        severity: tokens.closed ? 'error' : 'warning',
      });
    }
  }
  return violations;
};

/**
 * Checks a motion value's keyframes names against the published animations.
 *
 * The client half of StyleMapValidator::checkAnimationReferences() (the
 * server half runs strict-only; the client adds the permissive warning,
 * the token and swatch checks' posture): every keyframes name the value
 * wears (keyframesNamesInValue: animation-name's components, the
 * shorthand's name layers) must resolve in the roster's animation slot.
 * A motion-{name} ident that does not (the animation is unpublished or
 * deleted) warns on permissive sites and errors on strict ones; a plain
 * ident outside the motion namespace is a theme's own keyframes name,
 * which the spine has nothing to say about on a permissive site and the
 * closed world refuses (only site animations are curated there, the
 * `font-family: Helvetica` posture).
 *
 * @param {string} property
 *   The CSS property name.
 * @param {string} value
 *   The value as written.
 * @param {?object} tokens
 *   The materials context: {guide, materials, closed}; null skips the
 *   check.
 *
 * @return {Array<{code: string, message: string, hint: string, severity: string}>}
 */
const checkAnimationReferences = (property, value, tokens) => {
  if (!tokens) {
    return [];
  }
  const violations = [];
  for (const name of keyframesNamesInValue(property, value)) {
    if (
      resolveValue(name, SLOT.ANIMATION, tokens.materials).kind ===
      KIND.ANIMATION
    ) {
      continue;
    }
    const machineName = animationReference(name);
    if (machineName === null && !tokens.closed) {
      continue;
    }
    const published = animationOptions(tokens.materials).map(
      (animation) => `${animation.ref} (${animation.label})`,
    );
    violations.push({
      code: 'STYLE_ANIMATION_NOT_PUBLISHED',
      message:
        machineName === null
          ? `"${name}" is not a site animation; this site enforces its style guide, so keyframes names must name published animations (motion-{name}).`
          : `"${name}" is in the motion namespace, but no published "${machineName}" animation exists.`,
      hint: tokens.closed
        ? published.length
          ? `Published animations, among the site's materials: ${published.join(', ')}. Mint the animation first, or wear one of these.`
          : 'No animations are published; mint the animation first, or set "none".'
        : 'It will not resolve until the animation is published; the reference is inert, not unsafe.',
      severity: tokens.closed ? 'error' : 'warning',
    });
  }
  return violations;
};

/**
 * Validates a property value against its definition and constraints.
 *
 * @param {string} property
 *   The CSS property name.
 * @param {string} value
 *   The (trimmed) value.
 * @param {?object} definition
 *   The property definition from guide.groups (type, keywords, flags).
 * @param {?object} constraints
 *   The element declaration's constraints for the property
 *   ({allowed, min, max, units, scale}), or null when unconstrained.
 * @param {?object} tokens
 *   The materials context for scale constraints and reference checks:
 *   {guide, materials, closed: propertiesClosed(guide)}, or null to skip
 *   them all (the server still enforces).
 *
 * @return {Array<{code: string, message: string, hint: string, severity?: string}>}
 *   Violations, empty when the value passes. Codes and messages mirror
 *   PropertyGroupBase so panel and server speak identically; a violation
 *   with severity 'warning' must not block a commit (the server accepts
 *   the value).
 */
export const validateValue = (
  property,
  value,
  definition,
  constraints,
  tokens = null,
) => {
  value = value.trim();
  // eslint-disable-next-line no-control-regex
  if (value === '' || /[;{}<>]|[\x00-\x1F]/.test(value)) {
    return [
      {
        code: 'INVALID_STYLE_VALUE',
        message: `"${value}" is not a valid value for ${property}.`,
        hint: 'Provide a single CSS declaration value with no braces or semicolons.',
      },
    ];
  }

  constraints = constraints ?? {};
  if (typeof constraints.scale === 'string' && tokens?.materials) {
    // The scale-constrained value space is closed: the scale's rostered
    // tokens as their style spellings plus any allowed entries beside the
    // scale, matched verbatim (StyleMapValidator::validateScaleValue()).
    const allowed = [
      ...scaleTokens(tokens.guide, tokens.materials, constraints.scale).map(
        (token) => token.ref,
      ),
      ...(Array.isArray(constraints.allowed) ? constraints.allowed : []),
    ];
    if (allowed.includes(value)) {
      return [];
    }
    return [
      {
        code: 'STYLE_VALUE_NOT_ON_SCALE',
        message: `"${value}" is not a "${constraints.scale}" scale token reference for ${property}.`,
        hint: allowed.length
          ? `The style guide constrains ${property} to the "${constraints.scale}" token scale; use one of: ${allowed.join(', ')}.`
          : `The style guide constrains ${property} to the "${constraints.scale}" token scale, but no tokens are minted on it (no active skin, or the skin defines none).`,
      },
    ];
  }
  // The reference checks run after the shape checks whatever they found
  // (StyleMapValidator::validateProperty's order: the group's validation,
  // then token references, then animation references), so a value that
  // is both malformed and unresolved reports both.
  return [
    ...validateShape(property, value, definition, constraints, tokens),
    ...checkAnimationReferences(property, value, tokens),
  ];
};

/**
 * The allowed-list, pass-through and typed checks: everything but the
 * scale closure (which returns early, a closed space) and the animation
 * reference check (which runs after every shape outcome).
 */
const validateShape = (property, value, definition, constraints, tokens) => {
  if (Array.isArray(constraints.allowed)) {
    if (constraints.allowed.includes(value)) {
      // An allowed list may hold var() palette entries; those still get
      // the minted-reference check (the server runs it after constraints).
      return checkTokenReferences(property, value, tokens);
    }
    return [
      {
        code: 'STYLE_VALUE_NOT_ALLOWED',
        message: `"${value}" is not an allowed value for ${property}.`,
        hint: `The style guide allows only: ${constraints.allowed.join(', ')}.`,
      },
    ];
  }

  if (
    CSS_WIDE_KEYWORDS.includes(value.toLowerCase()) ||
    value.includes('var(') ||
    value.startsWith('calc(')
  ) {
    return checkTokenReferences(property, value, tokens);
  }

  if (!definition) {
    return [];
  }

  // A list definition accepts comma or space separated repetition of the
  // base type; each component validates (and range-checks) on its own.
  if (definition.list) {
    const components = splitList(value);
    if (components === null || components.length === 0) {
      return [
        {
          code: 'INVALID_STYLE_VALUE',
          message: `"${value}" is not a valid value for ${property}.`,
          hint: `Provide a comma or space separated list of ${definition.type} values.`,
        },
      ];
    }
    return components.flatMap((component) =>
      validateScalar(property, component, definition, constraints),
    );
  }
  return validateScalar(property, value, definition, constraints);
};

/**
 * The definition's own min/max, as a constraints fragment.
 */
const definitionRange = (definition) =>
  Object.fromEntries(
    ['min', 'max']
      .filter(
        (bound) =>
          definition[bound] !== undefined && definition[bound] !== null,
      )
      .map((bound) => [bound, definition[bound]]),
  );

/**
 * Validates one component against the definition's type.
 *
 * A definition may carry its own range (opacity's 0..1): the property's
 * grammar bound, in force on every site whatever the enforcement mode, the
 * way `negative` already bounds the sign. A guide constraint block for the
 * property layers over it per key. Mirrors PropertyGroupBase::validateScalar.
 */
const validateScalar = (property, value, definition, constraints) => {
  constraints = { ...definitionRange(definition), ...constraints };
  switch (definition.type) {
    case 'dimension':
      return validateDimension(property, value, definition, constraints);

    case 'number':
      return validateNumber(property, value, definition, constraints);

    case 'keyword':
      return validateKeyword(property, value, definition);

    case 'ident':
      return validateIdent(property, value, definition);

    case 'animation_name':
      return validateAnimationName(property, value, definition);

    case 'color':
      return validateColor(property, value);

    default:
      return validateText(property, value);
  }
};
