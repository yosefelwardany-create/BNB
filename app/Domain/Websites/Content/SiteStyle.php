<?php

declare(strict_types=1);

namespace App\Domain\Websites\Content;

/**
 * A site's look: one of three themes, and the few style choices on top.
 *
 * Themes share every block and its markup and differ only in their
 * stylesheet, so switching one never loses or moves anything. The style
 * reaches the page as CSS variables the stylesheets read.
 */
final class SiteStyle
{
    /** @var array<string, string> key => label */
    public const THEMES = [
        'coastal' => 'Coastal',
        'classic' => 'Classic',
        'bold' => 'Bold',
    ];

    /**
     * Pairs tested together: headings, then body text.
     *
     * @var array<string, array{0: string, 1: string, 2: string}> key => [label, heading font, body font]
     */
    public const FONT_PAIRS = [
        'fraunces-inter' => ['Fraunces and Inter', 'Fraunces', 'Inter'],
        'playfair-source' => ['Playfair Display and Source Sans', 'Playfair Display', 'Source Sans 3'],
        'lora-nunito' => ['Lora and Nunito Sans', 'Lora', 'Nunito Sans'],
        'dmserif-dmsans' => ['DM Serif and DM Sans', 'DM Serif Display', 'DM Sans'],
        'jakarta' => ['Plus Jakarta Sans', 'Plus Jakarta Sans', 'Plus Jakarta Sans'],
        'outfit-inter' => ['Outfit and Inter', 'Outfit', 'Inter'],
    ];

    /** @var array<string, string> key => label */
    public const BUTTONS = [
        'rounded' => 'Rounded',
        'pill' => 'Pill',
        'square' => 'Square',
    ];

    private const RADII = ['rounded' => '10px', 'pill' => '999px', 'square' => '0'];

    /** Fonts drawn from their family's own fallbacks while they load. */
    private const SERIF = ['Fraunces', 'Playfair Display', 'Lora', 'DM Serif Display'];

    public const DEFAULT_THEME = 'coastal';

    /**
     * @return array{primary: string, accent: string, fonts: string, buttons: string}
     */
    public static function defaults(): array
    {
        return ['primary' => '#1f4e5f', 'accent' => '#e07a5f', 'fonts' => 'fraunces-inter', 'buttons' => 'rounded'];
    }

    /**
     * The style, with anything missing or unknown at its default. For a
     * document that has been through the validator this changes nothing.
     *
     * @param  array<string, mixed>  $style
     * @return array{primary: string, accent: string, fonts: string, buttons: string}
     */
    public static function resolve(array $style): array
    {
        $defaults = self::defaults();

        return [
            'primary' => self::isColour($style['primary'] ?? null) ? strtolower($style['primary']) : $defaults['primary'],
            'accent' => self::isColour($style['accent'] ?? null) ? strtolower($style['accent']) : $defaults['accent'],
            'fonts' => isset(self::FONT_PAIRS[$style['fonts'] ?? '']) ? $style['fonts'] : $defaults['fonts'],
            'buttons' => isset(self::BUTTONS[$style['buttons'] ?? '']) ? $style['buttons'] : $defaults['buttons'],
        ];
    }

    public static function theme(mixed $theme): string
    {
        return is_string($theme) && isset(self::THEMES[$theme]) ? $theme : self::DEFAULT_THEME;
    }

    public static function isColour(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1;
    }

    /**
     * The `:root` declarations a page prints. Every value is checked here,
     * so nothing a person typed reaches the stylesheet as written.
     *
     * @param  array<string, mixed>  $style
     */
    public static function cssVariables(array $style): string
    {
        $style = self::resolve($style);
        [, $heading, $body] = self::FONT_PAIRS[$style['fonts']];

        return sprintf(
            '--brand:%s;--accent:%s;--accent-ink:%s;--font-head:%s;--font-body:%s;--button-radius:%s',
            $style['primary'],
            $style['accent'],
            self::readableOn($style['accent']),
            self::stack($heading),
            self::stack($body),
            self::RADII[$style['buttons']],
        );
    }

    /**
     * Google Fonts' address for the pair's two families.
     */
    public static function fontsHref(string $pair): string
    {
        [, $heading, $body] = self::FONT_PAIRS[$pair] ?? self::FONT_PAIRS['fraunces-inter'];
        $families = array_unique([$heading, $body]);

        $query = implode('&', array_map(
            fn (string $family): string => 'family='.str_replace(' ', '+', $family).':wght@400;500;600;700',
            $families,
        ));

        return 'https://fonts.googleapis.com/css2?'.$query.'&display=swap';
    }

    /**
     * Black or white, whichever reads better on the colour.
     */
    public static function readableOn(string $colour): string
    {
        return self::contrast($colour, '#ffffff') >= self::contrast($colour, '#1d2327') ? '#ffffff' : '#1d2327';
    }

    /**
     * The WCAG contrast ratio of two colours.
     */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $colour): float
    {
        $channels = array_map(
            function (string $hex): float {
                $c = hexdec($hex) / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            },
            str_split(substr($colour, 1), 2),
        );

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private static function stack(string $family): string
    {
        $fallback = in_array($family, self::SERIF, true) ? 'Georgia, "Times New Roman", serif' : 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';

        return '"'.$family.'", '.$fallback;
    }
}
