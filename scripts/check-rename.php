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
        'setUpBeforeClass' => ['DirectoryIterator', 'getFileName', 'getPathname', 'AssertionFailedError', 'setUpBeforeClass'],
        'test_file' => ['dataProvider', 'provider_files'],
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

    $currentFunction = '';
    if (preg_match_all('/\bfunction\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $content, $fnMatches, PREG_OFFSET_CAPTURE)) {
        $functions = $fnMatches[1];
    } else {
        $functions = [];
    }

    $camelPattern = '/\b([a-z][a-z0-9_]*[A-Z][A-Za-z0-9_]*)\b/';
    if (!preg_match_all($camelPattern, $content, $camelMatches, PREG_OFFSET_CAPTURE)) {
        return $errors;
    }

    $exemptStudly = ['Html2Text', 'Html2Text_Exception', 'Soundasleep', 'AssertionFailedError'];

    foreach ($camelMatches[1] as $match) {
        $token = $match[0];
        if (in_array($token, $exemptStudly, true)) {
            continue;
        }
        $offset = $match[1];
        $currentFunction = '';
        foreach ($functions as $fn) {
            if ($fn[1] <= $offset) {
                $currentFunction = $fn[0];
            }
        }

        $allowed = false;
        if (isset($siteAllow[$rel])) {
            $scopes = [$currentFunction, ''];
            foreach ($scopes as $scope) {
                if (!isset($siteAllow[$rel][$scope])) {
                    continue;
                }
                foreach ($siteAllow[$rel][$scope] as $permitted) {
                    if ($token === $permitted || strpos($token, $permitted) !== false) {
                        $allowed = true;
                        break 2;
                    }
                }
            }
            // Allow DOM* / assert* prefixes when whole token is third-party symbol in allow list
            if (!$allowed) {
                foreach ($siteAllow[$rel] as $scope => $symbols) {
                    if (in_array($token, $symbols, true)) {
                        $allowed = true;
                        break;
                    }
                }
            }
        }

        if (!$allowed) {
            $errors[] = "{$rel}: camelCase repo-owned identifier '{$token}' (near function {$currentFunction})";
        }
    }

    return $errors;
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
