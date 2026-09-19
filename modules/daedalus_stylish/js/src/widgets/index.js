/**
 * The basic-mode widget library: one directory per widget id under
 * js/src/widgets/<widget_id>/, each colocating its component with its
 * controlWidgets.register call. Shared primitives (Segments, useGesture,
 * alignment icons) live in widgets/lib/.
 *
 * Importing this module registers every widget on the controlWidgets
 * singleton (basic-controls.js does, so every host that can render a
 * control has them). Components implement the pipeline's widget contract
 * ({model, onPreview, onRelease, onCommit}) and render ONLY the input
 * surface — the label line and hasMore hint are pipeline chrome. Value
 * mapping (stops, custom-state honesty) stays in widget-values.js, which
 * is preact-free for the node tests.
 *
 * Adding a widget = one directory + one import line here + one PHP plugin
 * in src/Plugin/ControlWidget/.
 */
import './background/index.js';
import './corners_border/index.js';
import './elevation/index.js';
import './gap/index.js';
import './motion_trigger/index.js';
import './position/index.js';
import './spacing_scale/index.js';
import './text_alignment/index.js';
import './text_color/index.js';
import './typography/index.js';
import './width/index.js';
