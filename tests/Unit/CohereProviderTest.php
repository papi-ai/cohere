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
use PapiAI\Core\Contracts\EmbeddingProviderInterface;
use PapiAI\Core\Contracts\ProviderInterface;
use PapiAI\Core\EmbeddingResponse;
use PapiAI\Core\Exception\AuthenticationException;
use PapiAI\Core\Exception\ProviderException;
use PapiAI\Core\Exception\RateLimitException;
use PapiAI\Core\Message;
use PapiAI\Core\Response;
use PapiAI\Core\StreamChunk;
use PapiAI\Core\ToolCall;

/**
 * Test subclass that stubs HTTP methods for unit testing.
 */
class TestableCohereProvider extends CohereProvider
{
    public array $lastPayload = [];
    public array $fakeResponse = [];
    public array $fakeStreamEvents = [];
    public array $fakeEmbeddingResponse = [];
    public ?int $fakeHttpCode = null;
    public ?int $fakeEmbeddingHttpCode = null;

    protected function request(array $payload): array
    {
        $this->lastPayload = $payload;

        if ($this->fakeHttpCode !== null && $this->fakeHttpCode >= 400) {
            $this->simulateError($this->fakeHttpCode, $this->fakeResponse);
        }

        return $this->fakeResponse;
    }

    protected function streamRequest(array $payload): Generator
    {
        $this->lastPayload = $payload;

        foreach ($this->fakeStreamEvents as $event) {
            yield $event;
        }
    }

    protected function embeddingRequest(array $payload): array
    {
        $this->lastPayload = $payload;

        if ($this->fakeEmbeddingHttpCode !== null && $this->fakeEmbeddingHttpCode >= 400) {
            $this->simulateError($this->fakeEmbeddingHttpCode, $this->fakeEmbeddingResponse);
        }

        return $this->fakeEmbeddingResponse;
    }

    private function simulateError(int $httpCode, array $data): void
    {
        $errorMessage = $data['message'] ?? 'Unknown error';

        if ($httpCode === 401) {
            throw new AuthenticationException('cohere');
        }

        if ($httpCode === 429) {
            throw new RateLimitException('cohere');
        }

        throw new ProviderException(
            "Cohere API error ({$httpCode}): {$errorMessage}",
            'cohere',
            $httpCode,
            $data,
        );
    }
}

describe('CohereProvider', function () {
    beforeEach(function () {
        $this->provider = new TestableCohereProvider('test-api-key');
    });

    describe('construction', function () {
        it('implements ProviderInterface', function () {
            expect($this->provider)->toBeInstanceOf(ProviderInterface::class);
        });

        it('implements EmbeddingProviderInterface', function () {
            expect($this->provider)->toBeInstanceOf(EmbeddingProviderInterface::class);
        });

        it('returns cohere as name', function () {
            expect($this->provider->getName())->toBe('cohere');
        });
    });

    describe('capabilities', function () {
        it('supports tools', function () {
            expect($this->provider->supportsTool())->toBeTrue();
        });

        it('does not support vision', function () {
            expect($this->provider->supportsVision())->toBeFalse();
        });

        it('does not support structured output', function () {
            expect($this->provider->supportsStructuredOutput())->toBeFalse();
        });
    });

    describe('chat', function () {
        it('sends messages and returns a Response', function () {
            $this->provider->fakeResponse = [
                'message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Hello back!']],
                ],
                'finish_reason' => 'COMPLETE',
                'usage' => [
                    'billed_units' => ['input_tokens' => 10, 'output_tokens' => 5],
                ],
            ];

            $response = $this->provider->chat([Message::user('Hello')]);

            expect($response)->toBeInstanceOf(Response::class);
            expect($response->text)->toBe('Hello back!');
        });

        it('includes system message in messages array', function () {
            $this->provider->fakeResponse = [
                'message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'OK']],
                ],
                'finish_reason' => 'COMPLETE',
                'usage' => [
                    'billed_units' => ['input_tokens' => 10, 'output_tokens' => 5],
                ],
            ];

            $this->provider->chat([
                Message::system('Be helpful'),
                Message::user('Hello'),
            ]);

            expect($this->provider->lastPayload['messages'])->toHaveCount(2);
            expect($this->provider->lastPayload['messages'][0]['role'])->toBe('system');
            expect($this->provider->lastPayload['messages'][0]['content'])->toBe('Be helpful');
            expect($this->provider->lastPayload['messages'][1]['role'])->toBe('user');
        });

        it('uses default model', function () {
            $this->provider->fakeResponse = [
                'message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'OK']],
                ],
                'finish_reason' => 'COMPLETE',
                'usage' => [
                    'billed_units' => ['input_tokens' => 10, 'output_tokens' => 5],
                ],
            ];

            $this->provider->chat([Message::user('Hello')]);

            expect($this->provider->lastPayload['model'])->toBe('command-r-plus');
        });

        it('overrides model and options from parameters', function () {
            $this->provider->fakeResponse = [
                'message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'OK']],
                ],
                'finish_reason' => 'COMPLETE',
                'usage' => [
                    'billed_units' => ['input_tokens' => 10, 'output_tokens' => 5],
                ],
            ];

            $this->provider->chat([Message::user('Hello')], [
                'model' => 'command-r',
                'maxTokens' => 8192,
                'temperature' => 0.5,
                'stopSequences' => ['END'],
            ]);

            expect($this->provider->lastPayload['model'])->toBe('command-r');
            expect($this->provider->lastPayload['max_tokens'])->toBe(8192);
            expect($this->provider->lastPayload['temperature'])->toBe(0.5);
            expect($this->provider->lastPayload['stop_sequences'])->toBe(['END']);
        });

        it('includes tools in payload converted to OpenAI format', function () {
            $this->provider->fakeResponse = [
                'message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'OK']],
                ],
                'finish_reason' => 'COMPLETE',
                'usage' => [
                    'billed_units' => ['input_tokens' => 10, 'output_tokens' => 5],
                ],
            ];

            $tools = [
                [
                    'name' => 'get_weather',
                    'description' => 'Get weather',
                    'input_schema' => ['type' => 'object', 'properties' => []],
                ],
            ];

            $this->provider->chat([Message::user('Hello')], ['tools' => $tools]);

            $expected = [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_weather',
                        'description' => 'Get weather',
                        'parameters' => ['type' => 'object', 'properties' => []],
                    ],
                ],
            ];
            expect($this->provider->lastPayload['tools'])->toBe($expected);
        });

        it('converts tool result messages', function () {
            $this->provider->fakeResponse = [
                'message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'The weather is sunny']],
                ],
                'finish_reason' => 'COMPLETE',
                'usage' => [
                    'billed_units' => ['input_tokens' => 10, 'output_tokens' => 5],
                ],
            ];

            $this->provider->chat([
                Message::user('What is the weather?'),
                Message::assistant('Let me check', [
                    new ToolCall('tc_1', 'get_weather', ['city' => 'London']),
                ]),
                Message::toolResult('tc_1', ['temp' => 20]),
            ]);

            $messages = $this->provider->lastPayload['messages'];
            expect($messages)->toHaveCount(3);

            // Tool result message
            $toolMsg = $messages[2];
            expect($toolMsg['role'])->toBe('tool');
            expect($toolMsg['tool_call_id'])->toBe('tc_1');
        });

        it('converts assistant messages with tool calls', function () {
            $this->provider->fakeResponse = [
                'message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Done']],
                ],
                'finish_reason' => 'COMPLETE',
                'usage' => [
                    'billed_units' => ['input_tokens' => 10, 'output_tokens' => 5],
                ],
            ];

            $this->provider->chat([
                Message::user('Hello'),
                Message::assistant('Let me help', [
                    new ToolCall('tc_1', 'search', ['q' => 'test']),
                ]),
                Message::toolResult('tc_1', 'result'),
            ]);

            $messages = $this->provider->lastPayload['messages'];
            $assistantMsg = $messages[1];
            expect($assistantMsg['role'])->toBe('assistant');
            expect($assistantMsg['content'])->toBe('Let me help');
            expect($assistantMsg['tool_calls'][0]['id'])->toBe('tc_1');
            expect($assistantMsg['tool_calls'][0]['type'])->toBe('function');
            expect($assistantMsg['tool_calls'][0]['function']['name'])->toBe('search');
            expect($assistantMsg['tool_calls'][0]['function']['arguments'])->toBe('{"q":"test"}');
        });

        it('handles response with tool calls', function () {
            $this->provider->fakeResponse = [
                'message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Let me check']],
                    'tool_calls' => [
                        [
                            'id' => 'call_123',
                            'function' => [
                                'name' => 'get_weather',
                                'arguments' => '{"city":"London"}',
                            ],
                        ],
                    ],
                ],
                'finish_reason' => 'TOOL_CALL',
                'usage' => [
                    'billed_units' => ['input_tokens' => 10, 'output_tokens' => 20],
                ],
            ];

            $response = $this->provider->chat([Message::user('Weather?')]);

            expect($response->hasToolCalls())->toBeTrue();
            expect($response->toolCalls)->toHaveCount(1);
            expect($response->toolCalls[0]->name)->toBe('get_weather');
            expect($response->toolCalls[0]->arguments)->toBe(['city' => 'London']);
        });

        it('extracts usage from response', function () {
            $this->provider->fakeResponse = [
                'message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Hi']],
                ],
                'finish_reason' => 'COMPLETE',
                'usage' => [
                    'billed_units' => ['input_tokens' => 15, 'output_tokens' => 8],
                ],
            ];

            $response = $this->provider->chat([Message::user('Hello')]);

            expect($response->usage['prompt_tokens'])->toBe(15);
            expect($response->usage['completion_tokens'])->toBe(8);
        });
    });

    describe('stream', function () {
        it('yields StreamChunk for text deltas', function () {
            $this->provider->fakeStreamEvents = [
                ['choices' => [['delta' => ['content' => 'Hello'], 'finish_reason' => null]]],
                ['choices' => [['delta' => ['content' => ' world'], 'finish_reason' => null]]],
                ['choices' => [['delta' => [], 'finish_reason' => 'stop']]],
            ];

            $chunks = [];
            foreach ($this->provider->stream([Message::user('Hi')]) as $chunk) {
                $chunks[] = $chunk;
            }

            expect($chunks)->toHaveCount(3);
            expect($chunks[0])->toBeInstanceOf(StreamChunk::class);
            expect($chunks[0]->text)->toBe('Hello');
            expect($chunks[1]->text)->toBe(' world');
            expect($chunks[2]->isComplete)->toBeTrue();
        });

        it('sets stream flag in payload', function () {
            $this->provider->fakeStreamEvents = [
                ['choices' => [['delta' => [], 'finish_reason' => 'stop']]],
            ];

            iterator_to_array($this->provider->stream([Message::user('Hi')]));

            expect($this->provider->lastPayload['stream'])->toBeTrue();
        });
    });

    describe('embed', function () {
        it('returns an EmbeddingResponse for single input', function () {
            $this->provider->fakeEmbeddingResponse = [
                'embeddings' => ['float' => [[0.1, 0.2, 0.3]]],
                'meta' => [
                    'api_version' => ['version' => '1'],
                    'billed_units' => ['input_tokens' => 5],
                ],
            ];

            $response = $this->provider->embed('Hello world');

            expect($response)->toBeInstanceOf(EmbeddingResponse::class);
            expect($response->embeddings)->toBe([[0.1, 0.2, 0.3]]);
        });

        it('sends texts array in payload', function () {
            $this->provider->fakeEmbeddingResponse = [
                'embeddings' => ['float' => [[0.1], [0.2]]],
                'meta' => [
                    'api_version' => ['version' => '1'],
                    'billed_units' => ['input_tokens' => 10],
                ],
            ];

            $this->provider->embed(['Hello', 'World']);

            expect($this->provider->lastPayload['texts'])->toBe(['Hello', 'World']);
            expect($this->provider->lastPayload['input_type'])->toBe('search_document');
            expect($this->provider->lastPayload['model'])->toBe('embed-english-v3.0');
        });

        it('uses custom model for embeddings', function () {
            $this->provider->fakeEmbeddingResponse = [
                'embeddings' => ['float' => [[0.1]]],
                'meta' => [
                    'api_version' => ['version' => '1'],
                    'billed_units' => ['input_tokens' => 5],
                ],
            ];

            $this->provider->embed('Hello', ['model' => 'embed-multilingual-v3.0']);

            expect($this->provider->lastPayload['model'])->toBe('embed-multilingual-v3.0');
        });

        it('wraps single string input in array', function () {
            $this->provider->fakeEmbeddingResponse = [
                'embeddings' => ['float' => [[0.1]]],
                'meta' => [
                    'api_version' => ['version' => '1'],
                    'billed_units' => ['input_tokens' => 5],
                ],
            ];

            $this->provider->embed('Single text');

            expect($this->provider->lastPayload['texts'])->toBe(['Single text']);
        });
    });

    describe('error handling', function () {
        it('throws AuthenticationException on 401', function () {
            $this->provider->fakeHttpCode = 401;
            $this->provider->fakeResponse = ['message' => 'Invalid API key'];

            expect(fn () => $this->provider->chat([Message::user('Hello')]))
                ->toThrow(AuthenticationException::class);
        });

        it('throws RateLimitException on 429', function () {
            $this->provider->fakeHttpCode = 429;
            $this->provider->fakeResponse = ['message' => 'Rate limit exceeded'];

            expect(fn () => $this->provider->chat([Message::user('Hello')]))
                ->toThrow(RateLimitException::class);
        });

        it('throws ProviderException on other errors', function () {
            $this->provider->fakeHttpCode = 500;
            $this->provider->fakeResponse = ['message' => 'Internal server error'];

            expect(fn () => $this->provider->chat([Message::user('Hello')]))
                ->toThrow(ProviderException::class);
        });

        it('throws AuthenticationException on embedding 401', function () {
            $this->provider->fakeEmbeddingHttpCode = 401;
            $this->provider->fakeEmbeddingResponse = ['message' => 'Invalid API key'];

            expect(fn () => $this->provider->embed('Hello'))
                ->toThrow(AuthenticationException::class);
        });
    });
});
