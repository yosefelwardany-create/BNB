<?php

declare(strict_types=1);

namespace App\Domain\Agents\Support;

/**
 * The faces an agent can be given, as a fixed list.
 *
 * Operators who run a bot per flat think of them as people — Alex on the third
 * floor, David in the annexe — and pick the right one off a card by its face
 * before they read the name. Until now that meant typing a URL to an image
 * hosted somewhere else, which almost nobody does: the link rots, the host goes
 * away, and the card falls back to a letter.
 *
 * So these ship with the platform. The drawings live in the frontend as inline
 * SVG, which means no fetch, nothing to break, and a crisp face at any size.
 * What is stored here is only the key — `nova`, `cobalt` — so the picture can be
 * redrawn later without touching a single property's settings.
 *
 * This list is the authority on which keys are real. A stored value outside it
 * is dropped when the brief is read, so a row written by an import or an older
 * version cannot put an unknown key into a page.
 */
final class BotAvatar
{
    /**
     * Every face, as `key => the name on the picker`.
     *
     * Named after colours and materials rather than people: an operator renames
     * their bot constantly, and a face called "Alex" that somebody has renamed
     * "Reception" reads as a mistake.
     *
     * @var array<string, string>
     */
    public const CATALOGUE = [
        'nova' => 'Nova',
        'pixel' => 'Pixel',
        'echo' => 'Echo',
        'juno' => 'Juno',
        'sable' => 'Sable',
        'lumen' => 'Lumen',
        'maple' => 'Maple',
        'cobalt' => 'Cobalt',
        'ember' => 'Ember',
        'fern' => 'Fern',
        'onyx' => 'Onyx',
        'clay' => 'Clay',
        'indigo' => 'Indigo',
        'saffron' => 'Saffron',
        'birch' => 'Birch',
        'slate' => 'Slate',
        'coral' => 'Coral',
        'moss' => 'Moss',
        'dune' => 'Dune',
        'frost' => 'Frost',
    ];

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::CATALOGUE);
    }

    public static function exists(?string $key): bool
    {
        return $key !== null && array_key_exists($key, self::CATALOGUE);
    }

    /**
     * The catalogue as the picker needs it.
     *
     * Sent from here rather than listed again in the frontend so there is one
     * place that decides which faces exist. The frontend draws the ones it has
     * and falls back to the bot's initial for anything it does not recognise,
     * which keeps a newly added face from blanking a card on a stale build.
     *
     * @return list<array{key: string, name: string}>
     */
    public static function all(): array
    {
        $avatars = [];

        foreach (self::CATALOGUE as $key => $name) {
            $avatars[] = ['key' => $key, 'name' => $name];
        }

        return $avatars;
    }
}
