---
name: "Rector Failure"
about: "Automated issue created when the Rector dry run finds dated code"
title: "Rector Dry Run Failed - PHP {{ env.PHP_VERSION }}"
labels: ["bug", "rector", "static-analysis", "automated"]
assignees: []
---

## Rector Dry Run Failure

The Rector job failed. Inspect the failure stage before attributing the failure to the code.

**Failure stage:** `{{ env.FAILURE_STAGE }}`

**PHP Version:** {{ env.PHP_VERSION }}
**Workflow Run:** [{{ env.RUN_ID }}]({{ env.WORKFLOW_URL }})
**Date:** {{ date | date('YYYY-MM-DD') }}

### What the job runs

`composer rector` runs `rector process --dry-run` with `rector.php`. It changes nothing, and it fails when any configured rule would change the plugin's PHP: the PHP 8.3 sets, and the dead-code, code-quality, type-declaration, and early-return sets, without the rules that `rector.php` skips for WordPress.

### Next Steps

1. Read the diff in the job log: it shows each change Rector would make, and the rule that makes it.
2. Run `composer rector` locally to see the same diff, then `vendor/bin/rector process` to apply it, and `composer phpcs:fix` to restore the WordPress formatting.
3. Run `composer check-all`. If a change breaks a test or a coding-standard rule, add that rule to the skip list in `rector.php`, with a comment that says why, instead of keeping the change.
4. Re-run the workflow.

---
*This issue was automatically created by the Rector workflow job.*
