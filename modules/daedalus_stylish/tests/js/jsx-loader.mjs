/**
 * A node module-loader hook that compiles daedalus_stylish's JSX sources so a
 * node:test suite can import a preact component and render its vnode tree.
 *
 * The sources under js/src are JSX in .js files that only webpack compiles
 * (babel-loader, preact's `h` pragma); node refuses them as they are. This
 * hook compiles the same way, with the same pragma, for any js/src module a
 * registering suite imports. A suite opts in with `registerJsx()` before a
 * dynamic import of the component (a static import would be hoisted above
 * the registration); the pure-model suites never load it, so they stay a
 * plain `node` run.
 *
 * The hook is synchronous and reads the source itself, which lets it serve
 * both registration APIs: `module.registerHooks()` (node 22.15+, in-thread,
 * hooks must be sync) where it exists, else the older `module.register()`
 * (a hooks thread, sync returns accepted), which node 26 deprecates.
 */
import { readFileSync } from 'node:fs';
import nodeModule from 'node:module';
import { fileURLToPath } from 'node:url';
import { transformSync } from '@babel/core';

const SOURCES = /\/js\/src\/.*\.js$/;

export function load(url, context, nextLoad) {
  if (!SOURCES.test(url) || url.includes('/node_modules/')) {
    return nextLoad(url, context);
  }
  const filename = fileURLToPath(url);
  const { code } = transformSync(readFileSync(filename, 'utf8'), {
    filename,
    babelrc: false,
    configFile: false,
    presets: [['@babel/preset-react', { pragma: 'h' }]],
  });
  return { format: 'module', shortCircuit: true, source: code };
}

/**
 * Registers the hook with the API this node offers.
 */
export const registerJsx = () => {
  if (typeof nodeModule.registerHooks === 'function') {
    nodeModule.registerHooks({ load });
    return;
  }
  nodeModule.register(import.meta.url);
};
