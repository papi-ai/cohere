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

use PapiAI\Cohere\CohereProvider;
use PapiAI\Core\Contracts\NamedToolSelectableInterface;
use PapiAI\Core\Contracts\ToolSelectableInterface;
use PapiAI\Core\Exception\ProviderException;
use PapiAI\Core\Message;

/**
 * Captures the request payload so tool-choice mapping can be asserted without HTTP.
 */
class TestableCohereToolChoiceProvider extends CohereProvider
{
    public array $lastPayload = [];

    protected function request(array $payload): array
    {
        $this->lastPayload = $payload;

        return ['message' => ['content' => [['text' => 'ok']]], 'finish_reason' => 'COMPLETE'];
    }
}

describe('CohereProvider tool choice', function () {
    beforeEach(function () {
        $this->provider = new TestableCohereToolChoiceProvider('test-api-key');
        $this->tools = [
            ['name' => 'get_weather', 'description' => 'Weather', 'parameters' => ['type' => 'object']],
        ];
        $this->chat = fn (array $options) => $this->provider->chat([Message::user('hi')], $options);
    });

    it('uses Cohere\'s uppercase spellings', function () {
        ($this->chat)(['tools' => $this->tools, 'toolChoice' => 'required']);
        expect($this->provider->lastPayload['tool_choice'])->toBe('REQUIRED');

        ($this->chat)(['tools' => $this->tools, 'toolChoice' => 'none']);
        expect($this->provider->lastPayload['tool_choice'])->toBe('NONE');
    });

    it('sends nothing for auto, which is how Cohere spells the default', function () {
        // Cohere has no AUTO value: the documented behaviour is that omitting the field lets the
        // model choose. Sending an invented value would be rejected upstream.
        ($this->chat)(['tools' => $this->tools, 'toolChoice' => 'auto']);

        expect($this->provider->lastPayload)->not->toHaveKey('tool_choice');
    });

    it('emits nothing when toolChoice is absent (backward compatible)', function () {
        ($this->chat)(['tools' => $this->tools]);

        expect($this->provider->lastPayload)->not->toHaveKey('tool_choice');
    });

    it('refuses to fake forcing a specific tool', function () {
        // Cohere's tool_choice cannot name a tool. Downgrading to REQUIRED would satisfy the request
        // shape while breaking the caller's guarantee, so it fails loudly instead.
        expect(fn () => ($this->chat)(['tools' => $this->tools, 'toolChoice' => ['name' => 'get_weather']]))
            ->toThrow(ProviderException::class, 'cannot force a specific tool');
    });

    it('names the tool it could not force, so the message is actionable', function () {
        expect(fn () => ($this->chat)(['tools' => $this->tools, 'toolChoice' => ['name' => 'get_weather']]))
            ->toThrow(ProviderException::class, 'get_weather');
    });

    it('fails before any HTTP call', function () {
        try {
            ($this->chat)(['tools' => $this->tools, 'toolChoice' => ['name' => 'get_weather']]);
        } catch (ProviderException) {
            // expected
        }

        expect($this->provider->lastPayload)->toBe([]);
    });

    it('throws for an unknown toolChoice value', function () {
        expect(fn () => ($this->chat)(['tools' => $this->tools, 'toolChoice' => 'always']))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws when required is asked for with no tools declared', function () {
        expect(fn () => ($this->chat)(['toolChoice' => 'required']))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('CohereProvider tool-selection capability', function () {
    it('declares what it can force, so callers can ask instead of catching', function () {
        // Cohere can force "required" or "none", but its API cannot name a tool.
        expect(is_subclass_of(CohereProvider::class, ToolSelectableInterface::class))->toBeTrue();
        expect(is_subclass_of(CohereProvider::class, NamedToolSelectableInterface::class))->toBeFalse();
    });
});
