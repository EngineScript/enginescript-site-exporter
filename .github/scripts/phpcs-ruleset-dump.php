<?php
/**
 * A PHPCS report that prints the loaded ruleset's final state as JSON instead of findings:
 * every registered sniff, and every rule-level setting (properties, severity, type, message)
 * after all includes, exclusions, and overrides.
 *
 * .github/scripts/codacy-configs.py runs it to copy the WordPress standards' settings into phpcs.xml.
 */

declare(strict_types=1);

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Reports\Report;

class RulesetDumpReport implements Report
{
    private static bool $done = false;

    public function generateFileReport($report, File $phpcsFile, $showSources = false, $width = 80)
    {
        if (self::$done) {
            return false;
        }
        self::$done = true;
        $ruleset = $phpcsFile->ruleset;
        $out = ['sniffs' => array_keys($ruleset->sniffCodes), 'rules' => []];
        foreach ($ruleset->ruleset as $code => $settings) {
            $entry = [];
            foreach ($settings['properties'] ?? [] as $name => $value) {
                $entry['properties'][$name] = is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
            }
            foreach (['severity', 'type', 'message'] as $key) {
                if (isset($settings[$key])) {
                    $entry[$key] = $settings[$key];
                }
            }
            $out['rules'][$code] = $entry;
        }
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        return true;
    }

    public function generate(
        $cachedData,
        $totalFiles,
        $totalErrors,
        $totalWarnings,
        $totalFixable,
        $showSources = false,
        $width = 80,
        $interactive = false,
        $toScreen = true
    ) {
        echo $cachedData;
    }
}
