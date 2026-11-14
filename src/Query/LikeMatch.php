<?php

declare(strict_types=1);

namespace Zana\Database\Query;

/**
 * Where the searched text may sit in the column, for whereLike() (2.20, FE-75).
 */
enum LikeMatch
{
    case StartsWith;
    case Contains;
    case EndsWith;

    /**
     * The LIKE pattern for $text, with "%", "_" and the escape character "!"
     * escaped, so the user's text matches only as itself.
     */
    public function pattern(string $text): string
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text);

        return match ($this) {
            self::StartsWith => $escaped . '%',
            self::Contains => '%' . $escaped . '%',
            self::EndsWith => '%' . $escaped,
        };
    }
}
