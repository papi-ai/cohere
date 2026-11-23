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
use PapiAI\Cohere\OutputSchema;
use PapiAI\Core\Message;
use PapiAI\Core\Schema\Schema;

/**
 * Captures the request payload so the response format can be asserted without HTTP.
 */
class TestableCohereStructuredOutputProvider extends CohereProvider
{
    public array $lastPayload = [];

    protected function request(array $payload): array
    {
        $this->lastPayload = $payload;

        return ['message' => ['content' => [['text' => '{"approved": true}']]], 'finish_reason' => 'COMPLETE'];
    }
}

describe('CohereProvider structured output', function () {
    beforeEach(function () {
        $this->provider = new TestableCohereStructuredOutputProvider('test-api-key');
        $this->schema = Schema::object(['approved' => Schema::boolean()])->toJsonSchema();
    });

    it('leaves the schema to be stated in the prompt, which Cohere needs to produce JSON at all', function () {
        expect($this->provider->supportsStructuredOutput())->toBeFalse();
    });

    it('sends the schema as a JSON response format', function () {
        $this->provider->chat([Message::user('Review this.')], ['outputSchema' => $this->schema]);

        expect($this->provider->lastPayload['response_format'])->toBe(['type' => 'json_object', 'json_schema' => $this->schema]);
    });

    it('sends no response format beside tools, a combination the API refuses', function () {
        $tools = [['name' => 'get_weather', 'description' => 'Weather', 'parameters' => ['type' => 'object']]];

        $this->provider->chat([Message::user('Review this.')], ['outputSchema' => $this->schema, 'tools' => $tools]);

        expect($this->provider->lastPayload)->not->toHaveKey('response_format');
    });

    it('sends no response format for a schema Cohere cannot compile', function (array $schema) {
        $this->provider->chat([Message::user('Review this.')], ['outputSchema' => $schema]);

        expect($this->provider->lastPayload)->not->toHaveKey('response_format');
    })->with([
        'a list at the top' => [['type' => 'array', 'items' => ['type' => 'string']]],
        'an object with nothing required' => [['type' => 'object', 'properties' => ['note' => ['type' => 'string']]]],
        'a nested object with nothing required' => [[
            'type' => 'object',
            'properties' => ['author' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]],
            'required' => ['author'],
        ]],
        'a composition it does not support' => [[
            'type' => 'object',
            'properties' => ['id' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]]],
            'required' => ['id'],
        ]],
    ]);

    it('sends no response format without a schema', function () {
        $this->provider->chat([Message::user('Hello')]);

        expect($this->provider->lastPayload)->not->toHaveKey('response_format');
    });
});

describe('OutputSchema', function () {
    it('drops the limits Cohere does not compile', function () {
        $schema = Schema::object([
            'age' => Schema::integer()->min(0)->max(150),
            'tags' => Schema::array(Schema::string())->minItems(1)->maxItems(5),
            'code' => Schema::string()->pattern('^[A-Z]{3}$'),
        ])->toJsonSchema();

        $sent = (new OutputSchema())->forApi($schema);

        expect($sent['properties'])->toBe([
            'age' => ['type' => 'integer'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'code' => ['type' => 'string'],
        ]);
        expect($sent['required'])->toBe(['age', 'tags', 'code']);
    });

    it('keeps what Cohere compiles', function () {
        $schema = [
            'type' => 'object',
            'properties' => [
                'state' => ['type' => 'string', 'enum' => ['open', 'closed']],
                'code' => ['type' => 'string', 'pattern' => '[A-Z]{3}'],
                'when' => ['type' => 'string', 'format' => 'date-time'],
                'choice' => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]],
            ],
            'required' => ['state', 'code', 'when', 'choice'],
            'additionalProperties' => false,
        ];

        expect((new OutputSchema())->forApi($schema))->toBe($schema);
    });

    it('drops a string format outside the four Cohere knows', function () {
        $schema = ['type' => 'object', 'properties' => ['mail' => ['type' => 'string', 'format' => 'email']], 'required' => ['mail']];

        expect((new OutputSchema())->forApi($schema)['properties']['mail'])->toBe(['type' => 'string']);
    });
});
