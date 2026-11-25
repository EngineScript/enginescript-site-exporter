/**
 * The full ESLint configuration: the WordPress JavaScript coding standards,
 * this project's settings from eslint.config.mjs (modern JavaScript rules),
 * and a check that the admin script uses only browser features that WordPress
 * supports. npm run lint:js, and so CI, runs this file.
 *
 * The "recommended-with-formatting" set checks formatting with ESLint's own
 * rules. The plain "recommended" set hands formatting to Prettier, whose
 * output does not match the WordPress style without the WordPress fork.
 */
import wordpress from '@wordpress/eslint-plugin';
import compat from 'eslint-plugin-compat';
import { ignores, projectConfigs } from './eslint.config.mjs';

export default [
	ignores,
	...wordpress.configs[ 'recommended-with-formatting' ],
	...projectConfigs,
	{
		// The browsers come from the "browserslist" entry in package.json.
		...compat.configs[ 'flat/recommended' ],
		files: [ 'js/**/*.js' ],
	},
];
