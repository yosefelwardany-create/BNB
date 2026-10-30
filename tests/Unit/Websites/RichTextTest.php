<?php

declare(strict_types=1);

namespace Tests\Unit\Websites;

use App\Domain\Websites\Content\RichText;
use PHPUnit\Framework\TestCase;

/**
 * The text a site's editor formats: kept to a few safe tags, whatever is
 * pasted into it.
 */
class RichTextTest extends TestCase
{
    public function test_keeps_the_allowed_formatting(): void
    {
        $html = '<p>Hi <strong>there</strong>, <em>welcome</em>.</p><ul><li>Sea</li><li>Sun</li></ul><p>A<br>B</p>';

        $this->assertSame($html, RichText::clean($html));
    }

    public function test_unwraps_unknown_elements_and_keeps_their_words(): void
    {
        $this->assertSame('<p>Sea</p>', RichText::clean('<span style="color:red" class="x">Sea</span>'));
        $this->assertSame('<p>Big view</p>', RichText::clean('<div><h1>Big <u>view</u></h1></div>'));
    }

    public function test_removes_scripts_and_event_handlers(): void
    {
        $this->assertSame(
            '<p>A</p>',
            RichText::clean('<p onclick="x()">A</p><script>alert(1)</script><img src=x onerror=alert(1)><style>p{}</style>'),
        );
    }

    public function test_drops_unsafe_links_but_keeps_their_text(): void
    {
        $this->assertSame('<p>x</p>', RichText::clean('<a href="javascript:alert(1)">x</a>'));
        $this->assertSame('<p>x</p>', RichText::clean('<p><a href=" JavaScript:alert(1)">x</a></p>'));
        $this->assertSame('<p>x</p>', RichText::clean('<p><a href="data:text/html,hi">x</a></p>'));
        $this->assertSame(
            '<p><a href="https://a.test" rel="noopener">x</a></p>',
            RichText::clean('<a href="https://a.test" target="_blank" style="a">x</a>'),
        );
        $this->assertSame('<p><a href="mailto:hi@a.test" rel="noopener">mail</a> <a href="tel:+351210000000" rel="noopener">call</a> <a href="/sites/brand/about" rel="noopener">about</a></p>',
            RichText::clean('<p><a href="mailto:hi@a.test">mail</a> <a href="tel:+351210000000">call</a> <a href="/sites/brand/about">about</a></p>'));
        // Protocol-relative addresses leave the site.
        $this->assertSame('<p>x</p>', RichText::clean('<p><a href="//evil.test">x</a></p>'));
    }

    public function test_pasted_word_markup_is_reduced_to_paragraphs(): void
    {
        $this->assertSame(
            '<p>Cosy <strong>flat</strong> by the <em>sea</em></p>',
            RichText::clean("<p class=MsoNormal><span lang=EN-GB style='font-size:11pt'>Cosy <b>flat</b> by the <i>sea</i></span></p><!-- comment -->"),
        );
    }

    public function test_escapes_text_that_looks_like_markup(): void
    {
        $this->assertSame('<p>1 &lt; 2 &amp; 3</p>', RichText::clean('1 &lt; 2 &amp; 3'));
        $this->assertSame('', RichText::clean('   '));
    }

    public function test_is_cut_to_its_length_in_text(): void
    {
        $cleaned = RichText::clean('<p>'.str_repeat('a', 50).'</p><p>'.str_repeat('b', 50).'</p>', 60);

        $this->assertSame(60, mb_strlen(strip_tags($cleaned)));
        $this->assertStringStartsWith('<p>'.str_repeat('a', 50).'</p><p>', $cleaned);
    }
}
