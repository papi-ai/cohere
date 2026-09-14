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
 * Every Cohere model this package knows, chat and embedding.
 *
 * The enum is the source of truth: the `MODEL_*` constants on CohereProvider alias its values, so both
 * spell the same string. It lets a watchdog enumerate what we ship instead of parsing source, and
 * each case knows whether it has been retired and what replaces it.
 *
 * An ID we have not heard of is not an error: `tryFrom()` returns null and callers may pass it
 * straight through, since next month's model is far likelier than last year's.
 *
 * @see https://docs.cohere.com/docs/models
 */
enum CohereModel: string
{
    case CommandAPlus = 'command-a-plus-05-2026';
    case CommandA = 'command-a-03-2025';
    case CommandAReasoning = 'command-a-reasoning-08-2025';
    case CommandR7b = 'command-r7b-12-2024';
    /** @deprecated Deprecated 15 September 2025, and predates command-r7b so it rejects tool_choice. Use CommandA. */
    case CommandRPlus = 'command-r-plus';
    /** @deprecated Deprecated 15 September 2025, and predates command-r7b so it rejects tool_choice. Use CommandA. */
    case CommandR = 'command-r';
    /** @deprecated Deprecated 15 September 2025. Use CommandA. */
    case Command = 'command';
    case EmbedV4 = 'embed-v4.0';
    case EmbedEnglish = 'embed-english-v3.0';
    case EmbedMultilingual = 'embed-multilingual-v3.0';

    /**
     * Whether the provider has retired this model.
     */
    public function isDeprecated(): bool
    {
        return match ($this) {
            self::CommandRPlus => true,
            self::CommandR => true,
            self::Command => true,
            default => false,
        };
    }

    /**
     * The published retirement date, ISO formatted, where the provider gave one.
     */
    public function retiredOn(): ?string
    {
        return match ($this) {
            self::CommandRPlus => '2025-09-15',
            self::CommandR => '2025-09-15',
            self::Command => '2025-09-15',
            default => null,
        };
    }

    /**
     * What to use instead, for retired models that have a successor here.
     */
    public function replacement(): ?self
    {
        return match ($this) {
            self::CommandRPlus => self::CommandA,
            self::CommandR => self::CommandA,
            self::Command => self::CommandA,
            default => null,
        };
    }
}
