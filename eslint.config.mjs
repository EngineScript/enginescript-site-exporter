/**
 * ESLint configuration, without the WordPress JavaScript coding standards:
 * rules that keep the admin script and its tests on modern JavaScript.
 *
 * Codacy reads this file, but it does not install the WordPress ESLint plugin
 * (`@wordpress/eslint-plugin`) or the WordPress browser list that the
 * browser-support check reads from package.json. eslint.wordpress.config.mjs
 * adds both to these settings; npm run lint:js, and so CI, runs that file.
 */
import unicorn from 'eslint-plugin-unicorn';
import globals from 'globals';

/*
 * Unicorn rules that move code to modern built-ins and patterns. Rules that
 * conflict with WordPress practice stay off: prefer-module (admin scripts are
 * classic scripts), no-null, prevent-abbreviations, filename-case,
 * no-array-for-each, and prefer-query-selector.
 */
const modernizationRules = Object.fromEntries(
	[
		'consistent-date-clone',
		'new-for-builtins',
		'no-array-push-push',
		'no-document-cookie',
		'no-for-loop',
		'no-instanceof-builtins',
		'no-negation-in-equality-check',
		'no-new-array',
		'no-typeof-undefined',
		'no-unnecessary-polyfills',
		'no-useless-spread',
		'prefer-add-event-listener',
		'prefer-array-find',
		'prefer-array-flat',
		'prefer-array-flat-map',
		'prefer-array-index-of',
		'prefer-array-some',
		'prefer-at',
		'prefer-blob-reading-methods',
		'prefer-date-now',
		'prefer-dom-node-append',
		'prefer-dom-node-dataset',
		'prefer-dom-node-remove',
		'prefer-dom-node-text-content',
		'prefer-event-target',
		'prefer-global-this',
		'prefer-includes',
		'prefer-keyboard-event-key',
		'prefer-logical-operator-over-ternary',
		'prefer-modern-dom-apis',
		'prefer-modern-math-apis',
		'prefer-negative-index',
		'prefer-node-protocol',
		'prefer-number-properties',
		'prefer-object-from-entries',
		'prefer-optional-catch-binding',
		'prefer-regexp-test',
		'prefer-set-has',
		'prefer-spread',
		'prefer-string-replace-all',
		'prefer-string-slice',
		'prefer-string-starts-ends-with',
		'prefer-structured-clone',
		'prefer-type-error',
		'text-encoding-identifier-case',
		'throw-new-error',
	].map( ( rule ) => [ `unicorn/${ rule }`, 'error' ] ),
);

export const ignores = {
	ignores: [ '.private/**', 'coverage/**', 'node_modules/**', 'vendor/**' ],
};

// The settings that follow the WordPress standards in eslint.wordpress.config.mjs.
export const projectConfigs = [
	{
		languageOptions: {
			globals: { ...globals.browser },
		},
		linterOptions: {
			reportUnusedDisableDirectives: 'error',
			reportUnusedInlineConfigs: 'error',
		},
		plugins: { unicorn },
		rules: {
			...modernizationRules,
			'logical-assignment-operators': [ 'error', 'always' ],
			'no-useless-concat': 'error',
			'no-var': 'error',
			'object-shorthand': 'error',
			'prefer-arrow-callback': 'error',
			'prefer-const': 'error',
			'prefer-exponentiation-operator': 'error',
			'prefer-numeric-literals': 'error',
			'prefer-object-has-own': 'error',
			'prefer-object-spread': 'error',
			'prefer-rest-params': 'error',
			'prefer-spread': 'error',
			'prefer-template': 'error',
		},
	},
	{
		files: [ 'tests/js/**' ],
		languageOptions: {
			globals: { ...globals.node },
		},
	},
];

/*
 * Without the WordPress rules, the code's disable comments for them (no-console,
 * no-alert) would be reported as unused; the full configuration reports them.
 */
export default [
	ignores,
	...projectConfigs,
	{
		linterOptions: {
			reportUnusedDisableDirectives: 'off',
			reportUnusedInlineConfigs: 'off',
		},
	},
];
