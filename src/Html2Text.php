<?php

declare (strict_types=1);
namespace Soundasleep;

class Html2Text
{
    /**
     * Returns the default option values for convert().
     *
     * Options:
     * - `ignore_errors` (bool)   — suppress libxml parsing errors; default false.
     * - `drop_links`    (bool)   — omit link hrefs from text output; default false.
     * - `char_set`      (string) — character set passed to DOMDocument::loadHTML; 'auto' detects from HTML.
     *
     * @return array<string, bool|string>
     */
    public static function default_options(): array
    {
        return ['ignore_errors' => false, 'drop_links' => false, 'char_set' => 'auto'];
    }
    /**
     * Tries to convert the given HTML into a plain text format - best suited for
     * e-mail display, etc.
     *
     * <p>In particular, it tries to maintain the following features:
     * <ul>
     *   <li>Links are maintained, with the 'href' copied over
     *   <li>Information in the &lt;head&gt; is lost
     * </ul>
     *
     * @param string $html the input HTML
     * @param boolean|array<string, bool | string> $options if boolean, Ignore xml parsing errors, else ['ignore_errors' => false, 'drop_links' => false, 'char_set' => 'auto']
     * @return string the HTML converted, as best as possible, to text
     * @throws Html2Text_Exception if the HTML could not be loaded as a {@link \DOMDocument}
     */
    public static function convert(string $html, $options = []): string
    {
        if ($options === false || $options === true) {
            // Using old style (< 1.0) of passing in options
            $options = ['ignore_errors' => $options];
        }
        $options = array_merge(static::default_options(), $options);
        // check all options are valid
        foreach ($options as $key => $value) {
            if (!in_array($key, array_keys(static::default_options()))) {
                throw new \InvalidArgumentException("Unknown html2text option '{$key}'. Valid options are " . implode(',', static::default_options()));
            }
        }
        $is_office_document = self::is_office_document($html);
        if ($is_office_document) {
            // remove office namespace
            $html = str_replace(['<o:p>', '</o:p>'], '', $html);
        }
        $html = self::fix_newlines($html);
        // use mb_convert_encoding for legacy versions of php
        if (PHP_MAJOR_VERSION * 10 + PHP_MINOR_VERSION < 81 && mb_detect_encoding($html, 'UTF-8', true)) {
            $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        }
        $doc = self::get_document($html, $options);
        $output = self::iterate_over_node($doc, null, false, $is_office_document, $options);
        // process output for whitespace/newlines
        $output = self::process_whitespace_newlines($output);
        return $output;
    }
    /**
     * Unify newlines; in particular, \r\n becomes \n, and
     * then \r becomes \n. This means that all newlines (Unix, Windows, Mac)
     * all become \ns.
     *
     * @param string $text text with any number of \r, \r\n and \n combinations
     * @return string the fixed text
     */
    public static function fix_newlines(string $text): string
    {
        // replace \r\n to \n
        $text = str_replace("\r\n", "\n", $text);
        // remove \rs
        $text = str_replace("\r", "\n", $text);
        return $text;
    }
    /** @return array<string> */
    public static function nbsp_codes(): array
    {
        return [
            "\xc2\xa0",
            "\u00a0",
        ];
    }
    /** @return array<string> */
    public static function zwnj_codes(): array
    {
        return [
            "\xe2\x80\x8c",
            "\u200c",
        ];
    }
    /**
     * Remove leading or trailing spaces and excess empty lines from provided multiline text
     *
     * @param string $text multiline text any number of leading or trailing spaces or excess lines
     * @return string the fixed text
     */
    public static function process_whitespace_newlines(string $text): string
    {
        // remove excess spaces around tabs
        $text = preg_replace("/ *\t */im", "\t", $text);
        // remove leading whitespace
        $text = ltrim($text);
        // remove leading spaces on each line
        $text = preg_replace("/\n[ \t]*/im", "\n", $text);
        // convert non-breaking spaces to regular spaces to prevent output issues,
        // do it here so they do NOT get removed with other leading spaces, as they
        // are sometimes used for indentation
        $text = self::render_text($text);
        // remove trailing whitespace
        $text = rtrim($text);
        // remove trailing spaces on each line
        $text = preg_replace("/[ \t]*\n/im", "\n", $text);
        // unarmor pre blocks
        $text = self::fix_newlines($text);
        // remove unnecessary empty lines
        $text = preg_replace("/\n\n\n*/im", "\n\n", $text);
        return $text;
    }
    /**
     * Can we guess that this HTML is generated by Microsoft Office?
     */
    public static function is_office_document(string $html): bool
    {
        return strpos($html, 'urn:schemas-microsoft-com:office') !== false;
    }
    public static function is_whitespace(string $text): bool
    {
        return strlen(trim(self::render_text($text), "\n\r\t ")) === 0;
    }
    /**
     * Parse HTML into a DOMDocument
     *
     * @param string $html the input HTML
     * @param array<string, bool | string> $options
     * @return \DOMDocument the parsed document tree
     */
    private static function get_document(string $html, array $options): \DOMDocument
    {
        $doc = new \DOMDocument();
        $html = trim($html);
        if (!$html) {
            // DOMDocument doesn't support empty value and throws an error
            // Return empty document instead
            return $doc;
        }
        if ($html[0] !== '<') {
            // If HTML does not begin with a tag, we put a body tag around it.
            // If we do not do this, PHP will insert a paragraph tag around
            // the first block of text for some reason which can mess up
            // the newlines. See pre.html test for an example.
            $html = '<body>' . $html . '</body>';
        }
        $header = '';
        // use char sets for modern versions of php
        if (PHP_MAJOR_VERSION * 10 + PHP_MINOR_VERSION >= 81) {
            // use specified char_set, or auto detect if not set
            $char_set = !empty($options['char_set']) ? $options['char_set'] : 'auto';
            if ('auto' === $char_set) {
                $char_set = mb_detect_encoding($html);
            } elseif (strpos($char_set, ',')) {
                mb_detect_order($char_set);
                $char_set = mb_detect_encoding($html);
            }
            // turn off error detection for Windows-1252 legacy html
            if (strpos($char_set, '1252')) {
                $options['ignore_errors'] = true;
            }
            $header = '<?xml version="1.0" encoding="' . $char_set . '">';
        }
        if (!empty($options['ignore_errors'])) {
            $doc->strictErrorChecking = false;
            $doc->recover = true;
            $doc->xmlStandalone = true;
            $old_internal_errors = libxml_use_internal_errors(true);
            $load_result = $doc->loadHTML($header . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_PARSEHUGE);
            libxml_use_internal_errors($old_internal_errors);
        } else {
            $load_result = $doc->loadHTML($header . $html, LIBXML_NONET);
        }
        if (!$load_result) {
            throw new Html2Text_Exception('Could not load HTML - badly formed?', $html);
        }
        return $doc;
    }
    /**
     * Replace any special characters with simple text versions, to prevent output issues:
     * - Convert non-breaking spaces to regular spaces; and
     * - Convert zero-width non-joiners to '' (nothing).
     *
     * This is to match our goal of rendering documents as they would be rendered
     * by a browser.
     */
    private static function render_text(string $text): string
    {
        $text = str_replace(self::nbsp_codes(), ' ', $text);
        return str_replace(self::zwnj_codes(), '', $text);
    }
    private static function next_child_name(?\DOMNode $node): ?string
    {
        // get the next child
        $next_node = $node->nextSibling;
        while ($next_node != null) {
            if ($next_node instanceof \DOMText) {
                if (!self::is_whitespace($next_node->wholeText)) {
                    break;
                }
            }
            if ($next_node instanceof \DOMElement) {
                break;
            }
            $next_node = $next_node->nextSibling;
        }
        $next_name = null;
        if (($next_node instanceof \DOMElement || $next_node instanceof \DOMText) && $next_node != null) {
            return strtolower($next_node->nodeName);
        }
        return $next_name;
    }
    /** @param array<string, bool | string> $options */
    private static function iterate_over_node(\DOMNode $node, ?string $prev_name, bool $in_pre, bool $is_office_document, array $options): string
    {
        if ($node instanceof \DOMText) {
            // Replace whitespace characters with a space (equivilant to \s)
            if ($in_pre) {
                $text = "\n" . trim(self::render_text($node->wholeText), "\n\r\t ") . "\n";
                // Remove trailing whitespace only
                $text = preg_replace("/[ \t]*\n/im", "\n", $text);
                // armor newlines with \r.
                return str_replace("\n", "\r", $text);
            }
            $text = self::render_text($node->wholeText);
            $text = preg_replace('/[\t\n\f\r ]+/im', ' ', $text);
            if (!self::is_whitespace($text) && ($prev_name == 'p' || $prev_name == 'div')) {
                return "\n" . $text;
            }
            return $text;
        }
        if ($node instanceof \DOMDocumentType || $node instanceof \DOMProcessingInstruction) {
            // ignore
            return '';
        }
        $name = strtolower($node->nodeName);
        $next_name = self::next_child_name($node);
        // start whitespace
        switch ($name) {
            case 'hr':
                $prefix = '';
                if ($prev_name != null) {
                    $prefix = "\n";
                }
                return $prefix . "---------------------------------------------------------------\n";
            case 'style':
            case 'head':
            case 'title':
            case 'meta':
            case 'script':
                // ignore these tags
                return '';
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
            case 'ol':
            case 'ul':
            case 'pre':
                // add two newlines
                $output = "\n\n";
                break;
            case 'td':
            case 'th':
                // add tab char to separate table fields
                $output = "\t";
                break;
            case 'p':
                // Microsoft exchange emails often include HTML which, when passed through
                // html2text, results in lots of double line returns everywhere.
                //
                // To fix this, for any p element with a class of `MsoNormal` (the standard
                // classname in any Microsoft export or outlook for a paragraph that behaves
                // like a line return) we skip the first line returns and set the name to br.
                // @phpstan-ignore-next-line
                if ($is_office_document && $node->getAttribute('class') == 'MsoNormal') {
                    $output = '';
                    $name = 'br';
                    break;
                }
                // add two lines
                $output = "\n\n";
                break;
            case 'tr':
                // add one line
                $output = "\n";
                break;
            case 'div':
                $output = '';
                if ($prev_name !== null) {
                    // add one line
                    $output .= "\n";
                }
                break;
            case 'li':
                $output = '- ';
                break;
            default:
                // print out contents of unknown tags
                $output = '';
                break;
        }
        // debug
        //$output .= "[$name,$next_name]";
        if (isset($node->childNodes)) {
            $n = $node->childNodes->item(0);
            $previous_sibling_names = [];
            $previous_sibling_name = null;
            $parts = [];
            $trailing_whitespace = 0;
            while ($n != null) {
                $text = self::iterate_over_node($n, $previous_sibling_name, $in_pre || $name == 'pre', $is_office_document, $options);
                // Pass current node name to next child, as previous sibling does not appear to get populated
                if ($n instanceof \DOMDocumentType || $n instanceof \DOMProcessingInstruction || $n instanceof \DOMText && self::is_whitespace($text)) {
                    // Keep current previous sibling name, these are invisible
                    $trailing_whitespace++;
                } else {
                    $previous_sibling_name = strtolower($n->nodeName);
                    $previous_sibling_names[] = $previous_sibling_name;
                    $trailing_whitespace = 0;
                }
                $node->removeChild($n);
                $n = $node->childNodes->item(0);
                $parts[] = $text;
            }
            // Remove trailing whitespace, important for the br check below
            while ($trailing_whitespace-- > 0) {
                array_pop($parts);
            }
            // suppress last br tag inside a node list if follows text
            $last_name = array_pop($previous_sibling_names);
            if ($last_name === 'br') {
                $last_name = array_pop($previous_sibling_names);
                if ($last_name === '#text') {
                    array_pop($parts);
                }
            }
            $output .= implode('', $parts);
        }
        // end whitespace
        switch ($name) {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
            case 'pre':
            case 'p':
                // add two lines
                $output .= "\n\n";
                break;
            case 'br':
            case 'li':
                // add one line
                $output .= "\n";
                break;
            case 'div':
                break;
            case 'a':
                // links are returned in [text](link) format
                // @phpstan-ignore-next-line
                $href = $node->getAttribute('href');
                $output = trim($output);
                // remove double [[ ]] s from linking images
                if (substr($output, 0, 1) == '[' && substr($output, -1) == ']') {
                    $output = substr($output, 1, strlen($output) - 2);
                    // for linking images, the title of the <a> overrides the title of the <img>
                    // @phpstan-ignore-next-line
                    if ($node->getAttribute('title')) {
                        // @phpstan-ignore-next-line
                        $output = $node->getAttribute('title');
                    }
                }
                // if there is no link text, but a title attr
                // @phpstan-ignore-next-line
                if (!$output && $node->getAttribute('title')) {
                    // @phpstan-ignore-next-line
                    $output = $node->getAttribute('title');
                }
                if ($href == null) {
                    // it doesn't link anywhere
                    // @phpstan-ignore-next-line
                    if ($node->getAttribute('name') != null) {
                        if ($options['drop_links']) {
                            $output = "{$output}";
                        } else {
                            $output = "[{$output}]";
                        }
                    }
                } else if ($href == $output || $href == "mailto:{$output}" || $href == "http://{$output}" || $href == "https://{$output}") {
                    // link to the same address: just use link
                    $output = "{$output}";
                } else if ($output) {
                    if ($options['drop_links']) {
                        $output = "{$output}";
                    } else {
                        $output = "[{$output}]({$href})";
                    }
                } else {
                    // empty string
                    $output = "{$href}";
                }
                // does the next node require additional whitespace?
                switch ($next_name) {
                    case 'h1':
                    case 'h2':
                    case 'h3':
                    case 'h4':
                    case 'h5':
                    case 'h6':
                        $output .= "\n";
                        break;
                }
                break;
            case 'img':
                // @phpstan-ignore-next-line
                if ($node->getAttribute('title')) {
                    // @phpstan-ignore-next-line
                    $output = '[' . $node->getAttribute('title') . ']';
                    // @phpstan-ignore-next-line
                } elseif ($node->getAttribute('alt')) {
                    // @phpstan-ignore-next-line
                    $output = '[' . $node->getAttribute('alt') . ']';
                } else {
                    $output = '';
                }
                break;
            case 'blockquote':
                // process quoted text for whitespace/newlines
                $output = self::process_whitespace_newlines($output);
                // add leading newline
                $output = "\n" . $output;
                // prepend '> ' at the beginning of all lines
                $output = preg_replace("/\n/im", "\n> ", $output);
                // replace leading '> >' with '>>'
                $output = preg_replace("/\n> >/im", "\n>>", $output);
                // add another leading newline and trailing newlines
                $output = "\n" . $output . "\n\n";
                break;
            default:
        }
        return $output;
    }
}