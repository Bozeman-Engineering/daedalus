const path = require('path');
const webpack = require('webpack');
const TerserPlugin = require('terser-webpack-plugin');

module.exports = (env, argv) => {
  const isDev = argv.mode === 'development';

  return {
    // Source maps: 'eval-source-map' for dev (fast, readable), 'source-map' for prod (separate file).
    devtool: isDev ? 'eval-source-map' : 'source-map',
    context: __dirname,
    entry: './js/edit_mode/edit-mode-app.js',
    output: {
      path: path.resolve(__dirname, 'js/edit_mode/dist'),
      filename: 'edit-mode-app.bundle.js',
    },
    module: {
      rules: [
        {
          test: /\.js$/,
          exclude: /node_modules/,
          use: {
            loader: 'babel-loader',
            options: {
              presets: [
                '@babel/preset-env',
                ['@babel/preset-react', { pragma: 'h' }],
              ],
            },
          },
        },
      ],
    },
    optimization: {
      minimizer: [
        // Keep the bundled libraries' license comments inside the bundle
        // instead of a separate .LICENSE.txt beside it.
        new TerserPlugin({ extractComments: false }),
      ],
    },
    resolve: {
      alias: {
        react: 'preact/compat',
        'react-dom': 'preact/compat',
      },
    },
    plugins: [
      // Define a custom variable to toggle development mode.
      new webpack.DefinePlugin({
        'process.env.DEV_MODE': JSON.stringify(isDev),
      }),
    ],
  };
};
