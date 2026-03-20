# Architecture: html2text

## Purpose
Converts HTML to plain text. Designed primarily for email display, preserving links and basic structure while stripping all HTML markup.

## Directory Structure
```
src/
  Html2Text.php           # Static utility class — primary public API
  Html2Text_Exception.php # Exception thrown when HTML cannot be parsed
html2text.php             # Legacy procedural wrapper (kept for backward compatibility)
convert.php               # CLI script: accepts HTML on stdin, outputs plain text
tests/
```

## Key Design Decisions
- **Static methods** — `Html2Text::convert()` is a pure static method with no required instantiation. State is passed as options.
- **DOMDocument-based parsing** — HTML is loaded into a `DOMDocument` and recursively traversed to produce plain text; this handles malformed HTML more robustly than regex approaches.
- **Office document awareness** — detects and strips `<o:p>` Office namespace tags before parsing.
- **UTF-8 handling** — uses `mb_convert_encoding` on PHP < 8.1 and relies on the native XML encoding declaration on PHP 8.1+.

## Extension Points
- Pass `['drop_links' => true]` to omit link URLs from the output.
- Pass `['ignore_errors' => true]` to suppress `DOMDocument` parse warnings for malformed HTML.
- Pass `['char_set' => 'ISO-8859-1']` to control input encoding detection.

## Dependency Flow
```
Html2Text::convert($html, $options)
  ├─ fix_newlines()           — normalise line endings
  ├─ mb_convert_encoding()    — handle UTF-8 (PHP < 8.1)
  ├─ get_document()           — load into DOMDocument
  └─ iterate_over_node()      — depth-first traversal → plain text
       └─ process_whitespace_newlines()  — clean up excess blank lines
```
