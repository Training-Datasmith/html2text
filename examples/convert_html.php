<?php

declare(strict_types=1);

/**
 * Example: convert HTML to plain text.
 *
 * Run from the html2text project root:
 *   php examples/convert_html.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Soundasleep\Html2Text;

// --- Basic conversion ---
$html = '<h1>Welcome</h1><p>This is a <strong>simple</strong> paragraph with a <a href="https://example.com">link</a>.</p>';
echo Html2Text::convert($html);
echo "\n\n---\n\n";

// --- Drop links (useful for short notification emails) ---
$html = '<p>Click <a href="https://example.com/reset">here</a> to reset your password.</p>';
echo Html2Text::convert($html, ['drop_links' => true]);
echo "\n\n---\n\n";

// --- Ignore malformed HTML ---
$html = '<p>Unclosed paragraph<br>with a line break';
echo Html2Text::convert($html, ['ignore_errors' => true]);
