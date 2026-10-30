<?php

declare(strict_types=1);

namespace App\Domain\Websites\Blocks;

/**
 * Every block a page can be built from, defined once.
 *
 * The server checks a saved page against these and renders each with its
 * view; the editor reads them to build its library and settings panel, so
 * adding a block here is the whole of adding it.
 */
final class BlockRegistry
{
    /** The icons a highlight may carry, as the guest's page draws them. */
    public const HIGHLIGHT_ICONS = [
        'wifi' => 'Wi-Fi',
        'parking' => 'Parking',
        'pool' => 'Pool',
        'sea' => 'Sea or beach',
        'mountain' => 'Mountains',
        'pets' => 'Pets welcome',
        'family' => 'Families',
        'kitchen' => 'Kitchen',
        'workspace' => 'Workspace',
        'air' => 'Air conditioning',
        'heating' => 'Heating',
        'star' => 'Star',
    ];

    /** @var array<string, BlockType>|null */
    private static ?array $types = null;

    /**
     * @return array<string, BlockType>
     */
    public static function all(): array
    {
        return self::$types ??= self::define();
    }

    public static function get(string $type): ?BlockType
    {
        return self::all()[$type] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function toArray(): array
    {
        return array_values(array_map(fn (BlockType $type): array => $type->toArray(), self::all()));
    }

    /**
     * @return array<string, BlockType>
     */
    private static function define(): array
    {
        $types = [
            new BlockType('hero', 'Big photo + search', 'image', 'A large photo with your heading and the search bar.', [
                BlockField::text('heading', 'Heading', 120, 'Find your place to stay'),
                BlockField::text('tagline', 'Tagline', 240),
                BlockField::image('image', 'Photo'),
                BlockField::choice('text_tone', 'Text colour', ['light' => 'Light', 'dark' => 'Dark'], 'light', 'Light text reads best on a dark photo.'),
                BlockField::toggle('show_search', 'Show the search bar', true),
            ]),
            new BlockType('homes', 'Our homes', 'house', 'Cards for your homes, with their photo and price.', [
                BlockField::text('title', 'Title', 80, 'Our stays'),
                BlockField::choice('which', 'Which homes', ['featured' => 'Featured', 'all' => 'All', 'chosen' => 'Chosen'], 'featured'),
                BlockField::listings('chosen', 'Homes to show', 'Used when "Chosen" is picked above.'),
                BlockField::choice('count', 'How many', ['3' => '3', '6' => '6', 'all' => 'All'], '6'),
                BlockField::choice('layout', 'Layout', ['grid' => 'Grid', 'carousel' => 'Carousel'], 'grid'),
            ]),
            new BlockType('text', 'Text', 'type', 'A heading and a few paragraphs.', [
                BlockField::text('heading', 'Heading', 120),
                BlockField::richText('body', 'Text', 5000),
            ]),
            new BlockType('text_image', 'Text + photo', 'layout-panel-left', 'Text beside a photo, with an optional button.', [
                BlockField::text('heading', 'Heading', 120),
                BlockField::richText('body', 'Text', 3000),
                BlockField::image('image', 'Photo'),
                BlockField::choice('image_side', 'Photo on the', ['left' => 'Left', 'right' => 'Right'], 'right'),
                BlockField::link('button', 'Button'),
            ]),
            new BlockType('gallery', 'Photo gallery', 'images', 'Up to twelve photos, as a grid or a slider.', [
                BlockField::text('title', 'Title', 80),
                BlockField::images('images', 'Photos', 12),
                BlockField::choice('layout', 'Layout', ['grid' => 'Grid', 'slider' => 'Slider'], 'grid'),
            ]),
            new BlockType('reviews', 'Guest reviews', 'star', 'Your best published guest reviews, picked for you.', [
                BlockField::text('title', 'Title', 80, 'What guests say'),
                BlockField::choice('min_rating', 'Show reviews of', ['any' => 'Any rating', '80' => '4 stars and up', '90' => '4.5 stars and up'], 'any'),
                BlockField::choice('count', 'How many', ['3' => '3', '6' => '6'], '3'),
            ]),
            new BlockType('highlights', 'Highlights', 'sparkles', 'A few short points, each with an icon.', [
                BlockField::text('title', 'Title', 80),
                BlockField::items('items', 'Points', [
                    BlockField::choice('icon', 'Icon', self::HIGHLIGHT_ICONS, 'star'),
                    BlockField::text('text', 'Text', 80),
                ], 6),
            ]),
            new BlockType('map', 'Map', 'map-pin', 'An area map around your homes, never the exact address.', [
                BlockField::text('title', 'Title', 80, 'Where we are'),
                BlockField::text('body', 'Getting here', 600),
            ]),
            new BlockType('faq', 'FAQ', 'circle-help', 'Questions and answers that open on tap.', [
                BlockField::text('title', 'Title', 80, 'Questions'),
                BlockField::items('items', 'Questions', [
                    BlockField::text('question', 'Question', 160),
                    BlockField::text('answer', 'Answer', 1200),
                ], 20),
            ]),
            new BlockType('cta', 'Call to action', 'megaphone', 'A coloured strip with a sentence and a button.', [
                BlockField::text('text', 'Sentence', 160, 'Book direct for the best price.'),
                BlockField::link('button', 'Button'),
            ]),
            new BlockType('contact', 'Contact', 'phone', 'Your phone, email and address from Site details.', [
                BlockField::text('title', 'Title', 80, 'Get in touch'),
                BlockField::text('intro', 'Introduction', 400),
            ]),
            new BlockType('spacer', 'Spacer', 'separator-horizontal', 'A gap, or a line, between blocks.', [
                BlockField::choice('size', 'Size', ['small' => 'Small', 'medium' => 'Medium', 'large' => 'Large'], 'medium'),
                BlockField::toggle('line', 'Show a line', false),
            ]),
        ];

        $byType = [];

        foreach ($types as $type) {
            $byType[$type->type] = $type;
        }

        return $byType;
    }
}
