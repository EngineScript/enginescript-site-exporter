---
name: "Lint Failure"
about: "Automated issue created when the lint job fails"
title: "Lint Failure - {{ env.FAILURE_STAGE }}"
labels: ["bug", "code-quality", "automated"]
assignees: []
---

## Lint Failure

The lint job failed. A setup or installation failure does not mean a file breaks a rule.

**Failure stage:** `{{ env.FAILURE_STAGE }}`

### Details

- **Node.js Version:** {{ env.NODE_VERSION }}
- **Workflow Run:** [View detailed logs]({{ env.WORKFLOW_URL }})
- **Run ID:** {{ env.RUN_ID }}

### What the job runs

The Python tools are installed from `.github/lint-requirements.txt`, which pins their versions.

- **yamllint** on every tracked YAML file, with the rules in `.yamllint.yml`. A warning fails the job.
- **actionlint** on the workflows, without its ShellCheck and pyflakes passes.
- **zizmor** on the workflows, offline. An accepted finding carries a `zizmor: ignore` comment with its reason.
- **Ruff** (`ruff check` and `ruff format --check`) on `.github/scripts`, with the rules in `.ruff.toml`.
- **mypy** in strict mode on `.github/scripts`, configured in `.mypy.ini`.
- **markdownlint** on every Markdown file that git does not ignore (`npm run lint:md`), with the rules in `.markdownlint.json`.
- **ESLint** with the WordPress JavaScript coding standards (`npm run lint:js`), configured in `eslint.config.mjs`.
- **Stylelint** on the plugin's CSS files (`npm run lint:css`), with the rules in `.stylelintrc.json`.

### Next Steps

1. Find the failed stage above and read that step's log.
2. Fix the reported file, or run the same check locally:
   - `yamllint --strict -c .yamllint.yml <file>`
   - `actionlint -shellcheck= -pyflakes= .github/workflows/*.yml`
   - `zizmor --offline .github/workflows`
   - `ruff check .github/scripts` and `ruff format --check .github/scripts`
   - `mypy`
   - `npm run lint:md`
   - `npm run lint:js`
   - `npm run lint:css`
3. Re-run the workflow.

This issue was automatically created by the CI/CD pipeline.
