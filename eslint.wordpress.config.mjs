/**
 * The full ESLint configuration: the WordPress JavaScript coding standards,
 * followed by this project's settings from eslint.config.mjs (modern
 * JavaScript rules and the browser-support check). npm run lint:js, and so
 * CI, runs this file.
 *
 * The "recommended-with-formatting" set checks formatting with ESLint's own
 * rules. The plain "recommended" set hands formatting to Prettier, whose
 * output does not match the WordPress style without the WordPress fork.
 */
import wordpress from '@wordpress/eslint-plugin';
import { ignores, projectConfigs } from './eslint.config.mjs';

export default [
	ignores,
	...wordpress.configs[ 'recommended-with-formatting' ],
	...projectConfigs,
];
