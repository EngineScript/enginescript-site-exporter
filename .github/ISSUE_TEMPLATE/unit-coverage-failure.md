---
name: "Unit Test Coverage Failure"
about: "Automated issue created when the unit test coverage job fails"
title: "Unit Test Coverage Failure - {{ env.FAILURE_STAGE }}"
labels: ["bug", "testing", "automated"]
assignees: []
---

## Unit Test Coverage Failure

The unit test coverage job failed. A setup or installation failure does not mean that coverage dropped.

**Failure stage:** `{{ env.FAILURE_STAGE }}`

### Details

- **PHP Version:** {{ env.PHP_VERSION }}
- **Workflow Run:** [View detailed logs]({{ env.WORKFLOW_URL }})
- **Run ID:** {{ env.RUN_ID }}

### What the job runs

The job runs the unit suite of `phpunit.xml` with PCOV and writes a coverage report. The step "Check line
coverage" fails when the suite covers less than 15% of the production lines. The minimum is
`MINIMUM_LINE_COVERAGE` in the job. The generated WordPress integration tests are not part of this measurement.

### Next Steps

1. Find the failed stage above and read that step's log.
2. If the stage is `run_unit_tests_with_coverage`, a unit test failed; fix the code or the test.
3. If the stage is `check_line_coverage`, add unit tests for the new or changed code. The coverage report is in
   the job log and in the `wp-compat-unit-coverage` artifact.
4. Re-run the workflow.

This issue was automatically created by the CI/CD pipeline.
