/**
 * The alignment stops' icon geometry: Figma-style edge line + two bars,
 * per axis (the bars hug, center on, or flush against the edge the stop
 * aligns to). Plain rect tuples ([x, y, width, height] in a 16-unit box)
 * kept out of widget-values.js on purpose — icons are rendering, and the
 * value module stays preact-free for the node tests.
 */
export const ALIGNMENT_ICONS = {
  'justify-content': {
    start: [
      [1.5, 2, 1.5, 12],
      [4, 3.5, 9, 3.5],
      [4, 9, 6, 3.5],
    ],
    center: [
      [7.25, 2, 1.5, 12],
      [3.5, 3.5, 9, 3.5],
      [5, 9, 6, 3.5],
    ],
    end: [
      [13, 2, 1.5, 12],
      [3, 3.5, 9, 3.5],
      [6, 9, 6, 3.5],
    ],
  },
  'align-items': {
    start: [
      [2, 1.5, 12, 1.5],
      [3.5, 4, 3.5, 9],
      [9, 4, 3.5, 6],
    ],
    center: [
      [2, 7.25, 12, 1.5],
      [3.5, 3.5, 3.5, 9],
      [9, 5, 3.5, 6],
    ],
    end: [
      [2, 13, 12, 1.5],
      [3.5, 3, 3.5, 9],
      [9, 6, 3.5, 6],
    ],
  },
  // Text lines rather than edge + bars: four rows of "text", the short
  // rows flush to (or centered on) the side the stop aligns to; justify
  // is all rows full measure.
  'text-align': {
    start: [
      [2, 2.5, 12, 1.5],
      [2, 6, 8, 1.5],
      [2, 9.5, 12, 1.5],
      [2, 13, 8, 1.5],
    ],
    center: [
      [2, 2.5, 12, 1.5],
      [4, 6, 8, 1.5],
      [2, 9.5, 12, 1.5],
      [4, 13, 8, 1.5],
    ],
    end: [
      [2, 2.5, 12, 1.5],
      [6, 6, 8, 1.5],
      [2, 9.5, 12, 1.5],
      [6, 13, 8, 1.5],
    ],
    justify: [
      [2, 2.5, 12, 1.5],
      [2, 6, 12, 1.5],
      [2, 9.5, 12, 1.5],
      [2, 13, 12, 1.5],
    ],
  },
};
