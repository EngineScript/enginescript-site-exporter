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
		'consistent-assert',
		'consistent-existence-index-check',
		'custom-error-definition',
		'error-message',
		'explicit-length-check',
		'import-style',
		'no-accessor-recursion',
		'no-array-callback-reference',
		'no-invalid-fetch-options',
		'no-invalid-remove-event-listener',
		'no-new-buffer',
		'no-object-as-default-parameter',
		'no-thenable',
		'prefer-math-min-max',
		'require-post-message-target-origin',
	].map( ( rule ) => [ `unicorn/${ rule }`, 'error' ] ),
);

/*
 * Codacy's "WordPress" standard also turns on these rules, which this file
 * leaves out:
 * - deprecated in ESLint, with a newer rule or no longer relevant:
 *   global-require, no-buffer-constructor, no-confusing-arrow,
 *   no-negated-in-lhs, no-new-symbol, no-path-concat, no-process-env,
 *   no-process-exit, no-return-await, no-sync.
 * - against this code's style or reporting on code that is right as written:
 *   no-await-in-loop, no-continue, no-promise-executor-return, no-ternary,
 *   no-void, prefer-reflect, require-atomic-updates.
 */

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
			// Checks that Codacy's "WordPress" standard also runs; each passes on this code.
			'for-direction': 'error',
			'getter-return': 'error',
			'no-async-promise-executor': 'error',
			'no-case-declarations': 'error',
			'no-class-assign': 'error',
			'no-compare-neg-zero': 'error',
			'no-constant-binary-expression': 'error',
			'no-constant-condition': 'error',
			'no-constructor-return': 'error',
			'no-control-regex': 'error',
			'no-delete-var': 'error',
			'no-dupe-else-if': 'error',
			'no-empty': 'error',
			'no-empty-character-class': 'error',
			'no-empty-function': 'error',
			'no-empty-pattern': 'error',
			'no-empty-static-block': 'error',
			'no-ex-assign': 'error',
			'no-extra-bind': 'error',
			'no-extra-boolean-cast': 'error',
			'no-func-assign': 'error',
			'no-global-assign': 'error',
			'no-import-assign': 'error',
			'no-inner-declarations': [ 'error', 'functions' ],
			'no-invalid-regexp': 'error',
			'no-invalid-this': 'error',
			'no-iterator': 'error',
			'no-label-var': 'error',
			'no-loop-func': 'error',
			'no-loss-of-precision': 'error',
			'no-misleading-character-class': 'error',
			'no-new-native-nonconstructor': 'error',
			'no-nonoctal-decimal-escape': 'error',
			'no-obj-calls': 'error',
			'no-object-constructor': 'error',
			'no-octal': 'error',
			'no-octal-escape': 'error',
			'no-param-reassign': 'error',
			'no-proto': 'error',
			'no-prototype-builtins': 'error',
			'no-regex-spaces': 'error',
			'no-return-assign': [ 'error', 'always' ],
			'no-script-url': 'error',
			'no-self-assign': 'error',
			'no-self-compare': 'error',
			'no-setter-return': 'error',
			'no-shadow-restricted-names': 'error',
			'no-sparse-arrays': 'error',
			'no-template-curly-in-string': 'error',
			'no-this-before-super': 'error',
			'no-throw-literal': 'error',
			'no-underscore-dangle': 'error',
			'no-unexpected-multiline': 'error',
			'no-unmodified-loop-condition': 'error',
			'no-unneeded-ternary': 'error',
			'no-unreachable-loop': 'error',
			'no-unsafe-finally': 'error',
			'no-unsafe-optional-chaining': 'error',
			'no-unused-labels': 'error',
			'no-unused-private-class-members': 'error',
			'no-use-before-define': 'error',
			'no-useless-assignment': 'error',
			'no-useless-backreference': 'error',
			'no-useless-call': 'error',
			'no-useless-catch': 'error',
			'no-useless-escape': 'error',
			'no-useless-rename': 'error',
			'no-warning-comments': 'error',
			'require-yield': 'error',
			'use-isnan': 'error',
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
