<?php

declare(strict_types=1);

namespace App\Domain\Websites\Blocks;

/**
 * One kind of block a page can hold: what the editor calls it, the icon
 * beside it in the library, and the settings it has. Its Blade view is
 * `sites.blocks.{type}`.
 */
final readonly class BlockType
{
    /**
     * @param  list<BlockField>  $fields
     */
    public function __construct(
        public string $type,
        public string $label,
        public string $icon,
        public string $description,
        public array $fields,
    ) {}

    public function field(string $name): ?BlockField
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Each setting at its default, as a new block starts.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->fields as $field) {
            $defaults[$field->name] = $field->default;
        }

        return $defaults;
    }

    public function view(): string
    {
        return 'sites.blocks.'.$this->type;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'label' => $this->label,
            'icon' => $this->icon,
            'description' => $this->description,
            'fields' => array_map(fn (BlockField $field): array => $field->toArray(), $this->fields),
        ];
    }
}
