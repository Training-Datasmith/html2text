#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);

$oldNames = [
    'defaultOptions', 'fixNewlines', 'nbspCodes', 'zwnjCodes', 'processWhitespaceNewlines',
    'isOfficeDocument', 'isWhitespace', 'getDocument', 'renderText', 'nextChildName',
    'iterateOverNode', 'Html2TextException', 'Html2text_Exception', 'Html2TextTest',
    'testFile', 'doTestWithResults', 'providerFiles', 'testInvalidXML',
    'testInvalidXMLIgnore', 'testInvalidXMLIgnoreOldSyntax', 'testInvalidOption',
    'testBasicDropLinks', 'testAnchorsDropLinks', 'testWindows1252',
    'prevName', 'nextName', 'nextNode', 'fileInfo',
];

/** @var array<string, array<string, list<string>>> $siteAllow */
$siteAllow = [
    'src/Html2Text.php' => [
        'convert' => ['InvalidArgumentException'],
        'get_document' => [
            'DOMDocument', 'loadHTML', 'strictErrorChecking', 'xmlStandalone', 'LIBXML',
        ],
        'next_child_name' => [
            'DOMNode', 'DOMText', 'DOMElement', 'nextSibling', 'wholeText', 'nodeName',
        ],
        'iterate_over_node' => [
            'DOMNode', 'DOMText', 'DOMElement', 'DOMDocumentType', 'DOMProcessingInstruction',
            'wholeText', 'nodeName', 'childNodes', 'removeChild', 'getAttribute', 'LIBXML',
            'MsoNormal',
        ],
    ],
    'tests/Html2Text_Test.php' => [
        '' => ['PHPUnit', 'Framework', 'TestCase', 'setUpBeforeClass'],
        'setUpBeforeClass' => [
            'DirectoryIterator', 'getFileName', 'getPathname', 'AssertionFailedError', 'setUpBeforeClass',
            'PHPUnit', 'Framework',
        ],
        'test_file' => ['dataProvider'],
        'do_test_with_results' => ['assertTrue', 'assertEquals'],
        'test_invalid_xml' => ['expectWarning'],
        'test_invalid_option' => ['expectException', 'InvalidArgumentException'],
    ],
    'html2text.php' => [
        '' => ['InvalidArgumentException'],
    ],
    'convert.php' => [
        '' => ['InvalidArgumentException'],
    ],
];

$scanPaths = [
    $root . '/src',
    $root . '/tests/Html2Text_Test.php',
    $root . '/README.md',
    $root . '/ARCHITECTURE.md',
    $root . '/CHANGELOG.md',
    $root . '/html2text.php',
    $root . '/convert.php',
    $root . '/examples',
];

/**
 * @return list<string>
 */
function collect_files(array $scanPaths): array
{
    $files = [];
    foreach ($scanPaths as $path) {
        if (is_file($path)) {
            $files[] = $path;
            continue;
        }
        if (!is_dir($path)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo) {
                continue;
            }
            if ($fileInfo->isFile()) {
                $files[] = $fileInfo->getPathname();
            }
        }
    }

    return $files;
}

function rel_path(string $root, string $path): string
{
    $root = rtrim($root, '/') . '/';
    if (strpos($path, $root) === 0) {
        return substr($path, strlen($root));
    }

    return $path;
}

function code_without_comments(string $content): string
{
    $content = preg_replace('~/\*.*?\*/~s', ' ', $content) ?? $content;
    $content = preg_replace('~//[^\n]*~', ' ', $content) ?? $content;

    return $content;
}

/**
 * @param list<array{0: string, 1: int}> $functions
 */
function function_at_offset(array $functions, int $offset): string
{
    if ($functions === [] || $offset < $functions[0][1]) {
        return '';
    }

    $current = '';
    foreach ($functions as $fn) {
        if ($fn[1] <= $offset) {
            $current = $fn[0];
        }
    }

    return $current;
}

/**
 * @param array<string, array<string, list<string>>> $siteAllow
 * @return list<string>
 */
function scan_content(string $rel, string $content, array $siteAllow, array $oldNames): array
{
    $errors = [];
    foreach ($oldNames as $old) {
        if (preg_match('/\b' . preg_quote($old, '/') . '\b/', $content)) {
            $errors[] = "{$rel}: old repo-owned name '{$old}'";
        }
    }

    $code = code_without_comments($content);
    if (preg_match_all('/\bfunction\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $code, $fnMatches, PREG_OFFSET_CAPTURE)) {
        $functions = $fnMatches[1];
    } else {
        $functions = [];
    }

    $camelPattern = '/\b([a-z][a-z0-9_]*[A-Z][A-Za-z0-9_]*)\b/';
    if (!preg_match_all($camelPattern, $code, $camelMatches, PREG_OFFSET_CAPTURE)) {
        return $errors;
    }

    $exemptStudly = ['Html2Text', 'Html2Text_Exception', 'Soundasleep', 'AssertionFailedError'];

    foreach ($camelMatches[1] as $match) {
        $token = $match[0];
        if (in_array($token, $exemptStudly, true)) {
            continue;
        }
        $offset = $match[1];
        $currentFunction = function_at_offset($functions, $offset);

        if (!is_symbol_allowed_at_site($rel, $currentFunction, $token, $siteAllow)) {
            $errors[] = "{$rel}: camelCase repo-owned identifier '{$token}' (near function {$currentFunction})";
        }
    }

    if (isset($siteAllow[$rel])) {
        $symbols = site_allowed_symbols($siteAllow[$rel]);
        foreach ($symbols as $symbol) {
            $pattern = '/(?<![a-zA-Z0-9_])' . preg_quote($symbol, '/') . '(?![a-zA-Z0-9_])/';
            if (!preg_match_all($pattern, $code, $symbolMatches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($symbolMatches[0] as $symbolMatch) {
                $offset = $symbolMatch[1];
                $currentFunction = function_at_offset($functions, $offset);
                if (!is_symbol_allowed_at_site($rel, $currentFunction, $symbol, $siteAllow)) {
                    $errors[] = "{$rel}: third-party identifier '{$symbol}' outside allowed site (near function {$currentFunction})";
                }
            }
        }
    }

    return $errors;
}

/**
 * @param array<string, list<string>> $fileAllow
 * @return list<string>
 */
function site_allowed_symbols(array $fileAllow): array
{
    $symbols = [];
    foreach ($fileAllow as $scopeSymbols) {
        foreach ($scopeSymbols as $symbol) {
            $symbols[$symbol] = true;
        }
    }

    return array_keys($symbols);
}

/**
 * @param array<string, array<string, list<string>>> $siteAllow
 */
function is_symbol_allowed_at_site(string $rel, string $currentFunction, string $symbol, array $siteAllow): bool
{
    if (!isset($siteAllow[$rel])) {
        return false;
    }
    if ($currentFunction !== '' && isset($siteAllow[$rel][$currentFunction])) {
        if (in_array($symbol, $siteAllow[$rel][$currentFunction], true)) {
            return true;
        }
    }
    if ($currentFunction === '' && isset($siteAllow[$rel][''])) {
        if (in_array($symbol, $siteAllow[$rel][''], true)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function run_in_memory_demos(array $siteAllow): array
{
    $errors = [];

    $oldSnippet = 'function fixNewlines() {}';
    if (scan_content('demo/old.php', $oldSnippet, [], ['fixNewlines']) === []) {
        $errors[] = 'demo old name should fail';
    }

    $camelSnippet = "function demo_scope() { \$myValue = 1; }";
    if (scan_content('demo/camel.php', $camelSnippet, [], []) === []) {
        $errors[] = 'demo camelCase should fail';
    }

    $outsideSnippet = "function wrong_scope() { \$badLocalName = 1; }";
    if (scan_content('src/Html2Text.php', $outsideSnippet, $siteAllow, []) === []) {
        $errors[] = 'demo camelCase outside allowed site should fail';
    }

    $insideSnippet = "function get_document() { \$char_set = 'auto'; }";
    if (scan_content('src/Html2Text.php', $insideSnippet, $siteAllow, []) !== []) {
        $errors[] = 'demo snake_case inside get_document should pass';
    }

    $domOutsideSnippet = "function fix_newlines() { \\DOMDocument::loadHTML(''); }";
    if (scan_content('src/Html2Text.php', $domOutsideSnippet, $siteAllow, []) === []) {
        $errors[] = 'demo third-party DOMDocument outside get_document should fail';
    }

    $domInsideSnippet = "function get_document() { \\DOMDocument::loadHTML(''); }";
    if (scan_content('src/Html2Text.php', $domInsideSnippet, $siteAllow, []) !== []) {
        $errors[] = 'demo third-party DOMDocument inside get_document should pass';
    }

    return $errors;
}

$demoErrors = run_in_memory_demos($siteAllow);
if ($demoErrors !== []) {
    foreach ($demoErrors as $demoError) {
        fwrite(STDERR, 'check-rename: in-memory demo failure: ' . $demoError . "\n");
    }
    exit(1);
}

$allErrors = [];
foreach (collect_files($scanPaths) as $file) {
    $content = file_get_contents($file);
    if ($content === false) {
        $allErrors[] = $file . ': unreadable';
        continue;
    }
    $rel = rel_path($root, $file);
    $allErrors = array_merge($allErrors, scan_content($rel, $content, $siteAllow, $oldNames));
}

if ($allErrors !== []) {
    foreach ($allErrors as $error) {
        fwrite(STDERR, 'check-rename: ' . $error . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "check-rename: OK (no old or camelCase repo-owned names)\n");
exit(0);
