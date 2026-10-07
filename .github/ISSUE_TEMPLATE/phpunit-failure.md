---
name: "PHPUnit Test Failure"
about: "Automated issue created when PHPUnit tests fail"
title: "PHPUnit Test Failure on PHP {{ env.PHP_VERSION }}"
labels: bug, testing, phpunit, automated
assignees: []
---

## PHPUnit Test Failure

The automated PHPUnit test suite has failed in the EngineScript Site Exporter plugin.

### Details

- **PHP Version:** {{ env.PHP_VERSION }}
- **Test Date:** {{ date | date('YYYY-MM-DD HH:mm:ss') }}
- **Workflow Run:** [View detailed logs]({{ env.WORKFLOW_URL }})

### Matrix Configuration

This test suite runs on multiple PHP versions:

- **PHP 8.2** - PHPUnit 9.6 for WordPress test library compatibility
- **PHP 8.3** - PHPUnit 9.6 for WordPress test library compatibility
- **PHP 8.4** - PHPUnit 9.6 for WordPress test library compatibility
- **PHP 8.5** - PHPUnit 9.6 for WordPress test library compatibility

### Next Steps

This issue has been automatically created because one or more PHPUnit test cases failed.

#### Possible Causes

1. **PHP Version Incompatibility**: Code may not be compatible with the PHP version being tested
2. **Test Coverage Gap**: New features may lack corresponding test cases
3. **Environment Issues**: Database or service connectivity issues
4. **Assertion Failures**: Test expectations no longer match implementation
5. **Dependency Conflicts**: Package versions may have changed

#### Recommended Actions

1. **Review Logs**: Check the workflow logs for specific test failure details
2. **Local Reproduction**: Run tests locally with the same PHP version
3. **Debug Failures**: Use verbose output to understand assertion failures
4. **Fix Issues**: Update either the code or tests as needed
5. **Validate**: Re-run PHPUnit to confirm all tests pass

#### Local Testing Commands

```bash
# Install dependencies for the PHP 8.2+ baseline
composer install

# Run all tests
composer test

# Run specific test file
vendor/bin/phpunit tests/EngineScriptSiteExporterTest.php

# Run with verbose output
vendor/bin/phpunit --verbose
```

#### PHPUnit Version Notes

The WordPress compatibility workflow pins PHPUnit 9.6 because the WordPress test library still calls APIs removed in PHPUnit 10+.

```bash
composer test
```

### Support

For more information about PHPUnit:

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [WordPress Testing Documentation](https://developer.wordpress.org/plugins/testing/)
