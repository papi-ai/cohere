<?php

/*
 * This file is part of PapiAI,
 * A simple but powerful PHP library for building AI agents.
 *
 * (c) Marcello Duarte <marcello.duarte@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace PapiAI\Cohere;

/**
 * A JSON Schema narrowed to what Cohere's JSON mode compiles.
 *
 * Numeric ranges, lengths, array sizes, anchored patterns and most string formats are left out;
 * the caller's own schema keeps them, so a reply that breaks one is still caught on return. A
 * schema Cohere cannot take at all has no narrowed form: one without an object at the top, with
 * an object that requires nothing, or built on `allOf`, `oneOf` or `not`.
 *
 * @see https://docs.cohere.com/docs/structured-outputs
 */
final class OutputSchema
{
    private const NOT_COMPILED = ['minimum', 'maximum', 'minItems', 'maxItems', 'minLength', 'maxLength', 'uniqueItems'];

    private const REFUSED = ['allOf', 'oneOf', 'not'];

    private const FORMATS = ['date-time', 'uuid', 'date', 'time'];

    private const PATTERN_TOKENS = ['^', '$', '?=', '?!'];

    private const SCHEMA_MAPS = ['properties', '$defs'];

    /**
     * @param array<string, mixed> $schema A JSON Schema
     *
     * @return array<string, mixed>|null The schema as Cohere accepts it, or null when it cannot
     */
    public function forApi(array $schema): ?array
    {
        if (($schema['type'] ?? null) !== 'object') {
            return null;
        }

        return $this->narrowed($schema);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>|null
     */
    private function narrowed(array $schema): ?array
    {
        if (array_intersect(self::REFUSED, array_keys($schema)) !== [] || $this->requiresNothing($schema)) {
            return null;
        }

        $schema = array_diff_key($schema, array_flip(self::NOT_COMPILED));

        if (!in_array($schema['format'] ?? null, self::FORMATS, true)) {
            unset($schema['format']);
        }

        if (is_string($schema['pattern'] ?? null) && $this->usesRefusedTokens($schema['pattern'])) {
            unset($schema['pattern']);
        }

        return $this->withNarrowedChildren($schema);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>|null
     */
    private function withNarrowedChildren(array $schema): ?array
    {
        foreach ([...self::SCHEMA_MAPS, 'anyOf'] as $keyword) {
            if (!is_array($schema[$keyword] ?? null)) {
                continue;
            }

            $children = array_map($this->child(...), $schema[$keyword]);

            if (in_array(null, $children, true)) {
                return null;
            }

            $schema[$keyword] = $children;
        }

        if (!is_array($schema['items'] ?? null)) {
            return $schema;
        }

        $items = $this->narrowed($schema['items']);

        if ($items === null) {
            return null;
        }

        $schema['items'] = $items;

        return $schema;
    }

    private function child(mixed $schema): mixed
    {
        return is_array($schema) ? $this->narrowed($schema) : $schema;
    }

    /**
     * @param array<string, mixed> $schema
     */
    private function requiresNothing(array $schema): bool
    {
        return ($schema['type'] ?? null) === 'object' && empty($schema['required']);
    }

    private function usesRefusedTokens(string $pattern): bool
    {
        foreach (self::PATTERN_TOKENS as $token) {
            if (str_contains($pattern, $token)) {
                return true;
            }
        }

        return false;
    }
}
