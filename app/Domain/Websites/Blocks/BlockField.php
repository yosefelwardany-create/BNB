<?php

declare(strict_types=1);

namespace App\Domain\Websites\Blocks;

/**
 * One setting of a block, as the editor shows it and the server checks it.
 *
 * Kinds:
 * - `text` — one plain line or paragraph, escaped when printed
 * - `rich_text` — a few safe tags, cleaned on save
 * - `image` — a reference to one photo; `images` a list of them
 * - `choice` — one of `options`; `toggle` — on or off
 * - `items` — a list of small records, each with `itemFields`
 * - `link` — a button's label and where it goes
 * - `listings` — homes of the site, by listing id
 */
final readonly class BlockField
{
    /**
     * @param  list<array{value: string, label: string}>  $options
     * @param  list<BlockField>  $itemFields
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $label,
        public mixed $default = null,
        public ?int $max = null,
        public array $options = [],
        public array $itemFields = [],
        public int $maxItems = 0,
        public ?string $hint = null,
    ) {}

    public static function text(string $name, string $label, int $max, string $default = '', ?string $hint = null): self
    {
        return new self($name, 'text', $label, $default, max: $max, hint: $hint);
    }

    public static function richText(string $name, string $label, int $max, string $default = ''): self
    {
        return new self($name, 'rich_text', $label, $default, max: $max);
    }

    public static function image(string $name, string $label): self
    {
        return new self($name, 'image', $label, null);
    }

    public static function images(string $name, string $label, int $maxItems): self
    {
        return new self($name, 'images', $label, [], maxItems: $maxItems);
    }

    /**
     * @param  array<string, string>  $options  value => label
     */
    public static function choice(string $name, string $label, array $options, string $default, ?string $hint = null): self
    {
        $list = [];

        foreach ($options as $value => $optionLabel) {
            $list[] = ['value' => (string) $value, 'label' => $optionLabel];
        }

        return new self($name, 'choice', $label, $default, options: $list, hint: $hint);
    }

    public static function toggle(string $name, string $label, bool $default): self
    {
        return new self($name, 'toggle', $label, $default);
    }

    /**
     * @param  list<BlockField>  $fields
     */
    public static function items(string $name, string $label, array $fields, int $maxItems): self
    {
        return new self($name, 'items', $label, [], itemFields: $fields, maxItems: $maxItems);
    }

    public static function link(string $name, string $label): self
    {
        return new self($name, 'link', $label, null);
    }

    public static function listings(string $name, string $label, ?string $hint = null): self
    {
        return new self($name, 'listings', $label, [], hint: $hint);
    }

    /**
     * @return list<string>
     */
    public function optionValues(): array
    {
        return array_column($this->options, 'value');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'kind' => $this->kind,
            'label' => $this->label,
            'default' => $this->default,
            'max' => $this->max,
            'options' => $this->options === [] ? null : $this->options,
            'item_fields' => $this->itemFields === [] ? null : array_map(fn (BlockField $field): array => $field->toArray(), $this->itemFields),
            'max_items' => $this->maxItems === 0 ? null : $this->maxItems,
            'hint' => $this->hint,
        ], fn (mixed $value, string $key): bool => $value !== null || $key === 'default', ARRAY_FILTER_USE_BOTH);
    }
}
