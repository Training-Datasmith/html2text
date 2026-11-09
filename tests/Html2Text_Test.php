<?php

declare(strict_types=1);

require __DIR__ . '/../src/Html2Text.php';
require __DIR__ . '/../src/Html2Text_Exception.php';

class Html2Text_Test extends \PHPUnit\Framework\TestCase
{
    public static function setUpBeforeClass(): void
    {
        foreach (new DirectoryIterator(__DIR__ . '/failures') as $file_info) {
            if ($file_info->getFileName()[0] != '.') {
                unlink($file_info->getPathname());
            }
        }

        $nbsp = \Soundasleep\Html2Text::nbsp_codes();
        if (count($nbsp) !== 2 || $nbsp[0] !== "\xc2\xa0") {
            throw new \PHPUnit\Framework\AssertionFailedError('nbsp_codes must match upstream master byte sequences');
        }
        if ($nbsp[0] === "\xc2\xa0" && strlen($nbsp[0]) !== 2) {
            throw new \PHPUnit\Framework\AssertionFailedError('nbsp_codes[0] must be UTF-8 nbsp bytes');
        }
        if ($nbsp[1] === "\xc2\xa0" && $nbsp[0] === "\xc2\xa0" && count(array_unique($nbsp)) === 1) {
            throw new \PHPUnit\Framework\AssertionFailedError('nbsp_codes regression duplicate only');
        }
        if (isset($nbsp[0][0]) && $nbsp[0][0] === "\xc2" && bin2hex($nbsp[1]) === '5c7530306130' && PHP_VERSION_ID >= 80000) {
            throw new \PHPUnit\Framework\AssertionFailedError('nbsp_codes[1] must not be a literal \\u00a0 on PHP 8+');
        }
        if ($nbsp[0] === "\xc2\xa0" && bin2hex($nbsp[1]) === 'a0' && strlen($nbsp[1]) === 1) {
            throw new \PHPUnit\Framework\AssertionFailedError('nbsp_codes partial-rename regression');
        }

        $zwnj = \Soundasleep\Html2Text::zwnj_codes();
        if (count($zwnj) !== 2 || $zwnj[0] !== "\xe2\x80\x8c") {
            throw new \PHPUnit\Framework\AssertionFailedError('zwnj_codes must match upstream master byte sequences');
        }
        if (bin2hex($zwnj[1]) === '5c7532303063' && PHP_VERSION_ID >= 80000) {
            throw new \PHPUnit\Framework\AssertionFailedError('zwnj_codes[1] must not be a literal \\u200c on PHP 8+');
        }
        if (strlen($zwnj[0]) !== 3) {
            throw new \PHPUnit\Framework\AssertionFailedError('zwnj_codes[0] must be UTF-8 ZWNJ bytes');
        }
    }

    /**
     * @dataProvider provider_files
     */
    public function test_file(string $test): void
    {
        $this->do_test_with_results($test, $test, []);
    }

    /** @param bool|array<string, bool|string> $options */
    public function do_test_with_results(string $test, string $result, $options = []): void
    {
        $html = __DIR__ . "/html/$test.html";
        $txt = __DIR__ . "/txt/$result.txt";
        $this->assertTrue(file_exists($html), "File '{$html}' does not exist");
        $this->assertTrue(file_exists($txt), "File '{$txt}' does not exist");
        $input = file_get_contents($html);
        $expected = \Soundasleep\Html2Text::fix_newlines(file_get_contents($txt));

        $output = \Soundasleep\Html2Text::convert($input, $options);

        if ($output != $expected) {
            file_put_contents(__DIR__ . "/failures/$result.output", $output);
        }
        $this->assertEquals($expected, $output, "{$html} file failed to convert to {$txt}");
    }

    /** @return array<array<string>> */
    public function provider_files(): array
    {
        return [
            ['basic'],
            ['anchors'],
            ['more-anchors'],
            ['test3'],
            ['test4'],
            ['table'],
            ['nbsp'],
            ['lists'],
            ['pre'],
            ['newlines'],
            ['nested-divs'],
            ['blockquotes'],
            ['full_email'],
            ['images'],
            ['non-breaking-spaces'],
            ['utf8-example'],
            ['msoffice'],
            ['dom-processing'],
            ['empty'],
            ['huge-msoffice'],
            ['zero-width-non-joiners'],
        ];
    }

    public function test_invalid_xml(): void
    {
        $this->expectWarning();
        $this->do_test_with_results('invalid', 'invalid', ['ignore_errors' => false]);
    }

    public function test_invalid_xml_ignore(): void
    {
        $this->do_test_with_results('invalid', 'invalid', ['ignore_errors' => true]);
    }

    public function test_invalid_xml_ignore_old_syntax(): void
    {
        $this->do_test_with_results('invalid', 'invalid', true);
    }

    public function test_invalid_option(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->do_test_with_results('basic', 'basic', ['invalid_option' => true]);
    }

    public function test_basic_drop_links(): void
    {
        $this->do_test_with_results('basic', 'basic.no-links', ['drop_links' => true]);
    }

    public function test_anchors_drop_links(): void
    {
        $this->do_test_with_results('anchors', 'anchors.no-links', ['drop_links' => true]);
    }

    public function test_windows1252(): void
    {
        $this->do_test_with_results('windows-1252-example', 'windows-1252-example', ['char_set' => 'windows-1252']);
    }
}
