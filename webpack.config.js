/**
 * Webpack configuration for WP-DBAL
 *
 * Extends @wordpress/scripts default webpack config.
 */

const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        admin: path.resolve(process.cwd(), 'src/admin', 'index.js'),
        migration: path.resolve(process.cwd(), 'src/migration', 'index.js'),
    },
    output: {
        path: path.resolve(process.cwd(), 'build'),
        filename: '[name].js',
    },
};

