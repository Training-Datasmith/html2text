<?php

declare(strict_types=1);
/**
 * This file is available if you still want to use functions rather than
 * autoloading classes.
 */

require_once __DIR__ . '/src/Html2Text.php';
require_once __DIR__ . '/src/Html2Text_Exception.php';

function convert_html_to_text($html, $ignore_error = false)
{
    return Soundasleep\Html2Text::convert($html, $ignore_error);
}

function fix_newlines($text)
{
    return Soundasleep\Html2Text::fix_newlines($text);
}
