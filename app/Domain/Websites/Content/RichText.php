<?php

declare(strict_types=1);

namespace App\Domain\Websites\Content;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use Dom\Text;

/**
 * The formatted text a site's editor saves, cut down to a few safe tags.
 *
 * Whatever arrives — typed, or pasted from Word or a web page — leaves as
 * paragraphs and lists with bold, italic, line breaks and links, and nothing
 * else: no attributes but a checked link address, no styles, no scripts.
 * Unknown elements are unwrapped so their words survive; headings, divs and
 * other blocks become paragraphs.
 *
 * Parsed with PHP's HTML5 parser, which reads broken markup the way a
 * browser does, so nothing hides from the cleaner in a way a browser would
 * later find.
 */
final class RichText
{
    /** Dropped with everything inside them. */
    private const DROPPED = [
        'script', 'style', 'template', 'iframe', 'object', 'embed', 'noscript', 'head', 'title',
        'meta', 'link', 'svg', 'math', 'img', 'video', 'audio', 'source', 'picture', 'canvas',
        'input', 'textarea', 'select', 'button', 'form', 'frame', 'frameset', 'base',
    ];

    /** A paragraph's edge: what is inside becomes paragraphs of its own. */
    private const BLOCKS = [
        'div', 'section', 'article', 'header', 'footer', 'main', 'nav', 'aside', 'blockquote', 'pre',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
        'figure', 'figcaption', 'address', 'dl', 'dt', 'dd', 'hr', 'center',
    ];

    /** @var list<string> */
    private array $blocks = [];

    private string $inline = '';

    private int $remaining;

    private function __construct(?int $max)
    {
        $this->remaining = $max ?? PHP_INT_MAX;
    }

    public static function clean(string $html, ?int $max = null): string
    {
        if (trim($html) === '') {
            return '';
        }

        $document = HTMLDocument::createFromString('<!doctype html><body>'.$html, LIBXML_NOERROR);
        $cleaner = new self($max);

        if ($document->body !== null) {
            $cleaner->walk($document->body);
        }

        $cleaner->flush();

        return implode('', $cleaner->blocks);
    }

    /**
     * Block context: text gathers into the current paragraph; paragraphs,
     * lists and other blocks close it.
     */
    private function walk(Node $parent): void
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof Text) {
                $this->inline .= $this->text($node->data);

                continue;
            }

            if (! $node instanceof Element) {
                continue;
            }

            $name = strtolower($node->localName);

            if (in_array($name, self::DROPPED, true)) {
                continue;
            }

            if ($name === 'p' || $name === 'li') {
                $this->flush();
                $this->inline = $this->inline($node);
                $this->flush();
            } elseif ($name === 'ul' || $name === 'ol') {
                $this->flush();
                $items = $this->items($node);

                if ($items !== '') {
                    $this->blocks[] = '<'.$name.'>'.$items.'</'.$name.'>';
                }
            } elseif (in_array($name, self::BLOCKS, true)) {
                $this->flush();
                $this->walk($node);
                $this->flush();
            } else {
                $this->inline .= $this->element($node, $name);
            }
        }
    }

    /**
     * Inline context: what a paragraph, a list item or a link may hold.
     */
    private function inline(Node $parent): string
    {
        $out = '';
        $break = false;

        foreach ($parent->childNodes as $node) {
            $this->append($out, $break, $node);
        }

        return $out;
    }

    /**
     * One node of a line. A paragraph, a list or another block met inside a
     * line starts a line of its own, so its words never run into the next.
     */
    private function append(string &$out, bool &$break, Node $node): void
    {
        if ($node instanceof Text) {
            $text = $this->text($node->data);

            if (trim($text) === '') {
                // Space between two blocks is not a line of its own.
                $out .= $break ? '' : $text;

                return;
            }

            $this->newLine($out, $break);
            $out .= $text;

            return;
        }

        if (! $node instanceof Element) {
            return;
        }

        $name = strtolower($node->localName);

        if (in_array($name, self::DROPPED, true)) {
            return;
        }

        if ($name === 'br') {
            $out .= '<br>';
            $break = false;

            return;
        }

        $block = in_array($name, ['p', 'li', 'ul', 'ol', ...self::BLOCKS], true);
        $content = $block ? trim($this->inline($node)) : $this->element($node, $name);

        if (! self::visible($content)) {
            $out .= $block ? '' : $content;

            return;
        }

        if ($block) {
            $out = rtrim($out);
            $break = $out !== '';
        }

        $this->newLine($out, $break);
        $out .= $content;
        $break = $block;
    }

    private function newLine(string &$out, bool &$break): void
    {
        if ($break && self::visible($out)) {
            $out .= '<br>';
        }

        $break = false;
    }

    private function element(Element $node, string $name): string
    {
        return match ($name) {
            'br' => '<br>',
            'strong', 'b' => $this->wrap('strong', $this->inline($node)),
            'em', 'i' => $this->wrap('em', $this->inline($node)),
            'a' => $this->link($node),
            default => $this->inline($node),
        };
    }

    /**
     * A list's items. Words loose in the list are an item of their own, and a
     * list inside an item is flattened into the items after it: a site's
     * lists are one level deep. An item is read the same way, as a list of
     * its own, so its own words come first and its sub-list after.
     */
    private function items(Element $list): string
    {
        $items = '';
        $line = '';
        $break = false;

        foreach ($list->childNodes as $node) {
            $name = $node instanceof Element ? strtolower($node->localName) : null;

            if ($name === 'li' || $name === 'ul' || $name === 'ol') {
                $items .= self::item($line).$this->items($node);
                $line = '';
                $break = false;
            } else {
                $this->append($line, $break, $node);
            }
        }

        return $items.self::item($line);
    }

    private static function item(string $content): string
    {
        $content = trim($content);

        return self::visible($content) ? '<li>'.$content.'</li>' : '';
    }

    private static function visible(string $html): bool
    {
        return trim(strip_tags($html)) !== '';
    }

    private function link(Element $node): string
    {
        $content = $this->inline($node);
        $href = self::safeHref((string) $node->getAttribute('href'));

        if ($href === null || trim(strip_tags($content)) === '') {
            return $content;
        }

        return '<a href="'.htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'" rel="noopener">'.$content.'</a>';
    }

    /**
     * A link address a site may carry: a web address, an email, a phone, or
     * a path on the site itself. Never a script, a data address, or a
     * protocol-relative address that leaves the site.
     */
    public static function safeHref(string $href): ?string
    {
        $href = trim($href);

        if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            return $href;
        }

        if (preg_match('/^https:\/\/[^\s\/]+/i', $href) === 1 && filter_var($href, FILTER_VALIDATE_URL) !== false) {
            return $href;
        }

        if (preg_match('/^(mailto|tel):[^\s]+$/i', $href) === 1) {
            return $href;
        }

        return null;
    }

    private function wrap(string $tag, string $content): string
    {
        return trim(strip_tags($content)) === '' && ! str_contains($content, '<br>') ? $content : '<'.$tag.'>'.$content.'</'.$tag.'>';
    }

    /**
     * Words, escaped, within what is left of the field's length.
     */
    private function text(string $text): string
    {
        if ($this->remaining <= 0) {
            return '';
        }

        // Whitespace between tags is not the guest's words.
        if (trim($text) !== '') {
            $text = mb_substr($text, 0, $this->remaining);
            $this->remaining -= mb_strlen($text);
        }

        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function flush(): void
    {
        $paragraph = trim($this->inline);
        $this->inline = '';

        if (trim(strip_tags($paragraph)) !== '') {
            $this->blocks[] = '<p>'.$paragraph.'</p>';
        }
    }
}
