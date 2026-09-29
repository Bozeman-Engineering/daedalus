const path = require('path');
const webpack = require('webpack');

module.exports = (env, argv) => {
  const isDev = argv.mode === 'development';

  return {
    // Source maps: 'eval-source-map' for dev (fast, readable), 'source-map' for prod (separate file).
    devtool: isDev ? 'eval-source-map' : 'source-map',
    context: __dirname,
    entry: {
      explode: './js/src/index.js',
    },
    output: {
      path: path.resolve(__dirname, 'js/dist'),
      filename: '[name].bundle.js',
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
    plugins: [
      new webpack.DefinePlugin({
        'process.env.DEV_MODE': JSON.stringify(isDev),
      }),
    ],
  };
};
