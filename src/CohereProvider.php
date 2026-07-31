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

use Generator;
use PapiAI\Core\Contracts\EmbeddingProviderInterface;
use PapiAI\Core\Contracts\ProviderInterface;
use PapiAI\Core\EmbeddingResponse;
use PapiAI\Core\Exception\AuthenticationException;
use PapiAI\Core\Exception\ProviderException;
use PapiAI\Core\Exception\RateLimitException;
use PapiAI\Core\Message;
use PapiAI\Core\Response;
use PapiAI\Core\Role;
use PapiAI\Core\StreamChunk;
use PapiAI\Core\ToolCall;
use PapiAI\Core\ToolChoice;

/**
 * Cohere API provider for PapiAI.
 *
 * Bridges PapiAI's core types with Cohere's v2 Chat API, handling format conversion.
 * Supports chat completions, streaming, tool calling, and embeddings.
 * Authentication via Bearer token. All HTTP via ext-curl.
 *
 * @see https://docs.cohere.com/reference/chat *
 * The neutral `effort` option is accepted and ignored here. Cohere exposes no reasoning-effort parameter on the v2 chat API. Ignoring it
 * degrades nothing the caller was promised, which is why it is silent where an unhonourable
 * `toolChoice` throws.
 */
class CohereProvider implements ProviderInterface, EmbeddingProviderInterface
{
    private const CHAT_API_URL = 'https://api.cohere.com/v2/chat';
    private const EMBED_API_URL = 'https://api.cohere.com/v1/embed';

    public const MODEL_COMMAND_A_PLUS = 'command-a-plus-05-2026';
    public const MODEL_COMMAND_A = 'command-a-03-2025';
    public const MODEL_COMMAND_A_REASONING = 'command-a-reasoning-08-2025';
    public const MODEL_COMMAND_R7B = 'command-r7b-12-2024';

    /** @deprecated Deprecated 15 September 2025, and predates command-r7b so it rejects tool_choice. */
    public const MODEL_COMMAND_R_PLUS = 'command-r-plus';
    /** @deprecated Deprecated 15 September 2025, and predates command-r7b so it rejects tool_choice. */
    public const MODEL_COMMAND_R = 'command-r';
    /** @deprecated Deprecated 15 September 2025. */
    public const MODEL_COMMAND = 'command';

    public const MODEL_EMBED_ENGLISH = 'embed-english-v3.0';
    public const MODEL_EMBED_MULTILINGUAL = 'embed-multilingual-v3.0';

    /**
     * @param string $apiKey      Cohere API key used as Bearer token
     * @param string $defaultModel Default model for chat requests
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly string $defaultModel = self::MODEL_COMMAND_A_PLUS,
    ) {
    }

    /**
     * Send a chat completion request to the Cohere v2 API.
     *
     * @param array<Message> $messages Conversation messages
     * @param array<string, mixed> $options Options including model, maxTokens, temperature, stopSequences,
     *   tools, and toolChoice ("auto", "none" or "required"; naming a specific tool is not supported here)
     *
     * @return Response Parsed response with text, tool calls, and usage
     *
     * @throws AuthenticationException When the API key is invalid
     * @throws RateLimitException      When rate limits are exceeded
     * @throws ProviderException       When the API returns an error, or toolChoice names a specific tool
     */
    public function chat(array $messages, array $options = []): Response
    {
        $payload = $this->buildPayload($messages, $options);
        $response = $this->request($payload);

        return $this->parseResponse($response, $messages);
    }

    /**
     * Stream a chat completion response from the Cohere v2 API via SSE.
     *
     * @param array<Message> $messages Conversation messages
     * @param array<array-key, mixed> $options Options including model, maxTokens, temperature, stopSequences, and tools
     *
     * @return iterable<StreamChunk> Yields stream chunks as they arrive
     *
     * @throws ProviderException When the API request fails
     */
    public function stream(array $messages, array $options = []): iterable
    {
        $payload = $this->buildPayload($messages, $options);
        $payload['stream'] = true;

        foreach ($this->streamRequest($payload) as $event) {
            $delta = $event['choices'][0]['delta'] ?? [];
            if (isset($delta['content'])) {
                yield new StreamChunk($delta['content']);
            }
            if (($event['choices'][0]['finish_reason'] ?? null) !== null) {
                yield new StreamChunk('', isComplete: true);
            }
        }
    }

    /**
     * Generate embeddings for the given input text(s) via Cohere's Embed API.
     *
     * @param string|array<string> $input  Single text or array of texts to embed
     * @param array<string, mixed> $options Options including model (defaults to embed-english-v3.0)
     *
     * @return EmbeddingResponse Embeddings with usage metadata
     *
     * @throws AuthenticationException When the API key is invalid
     * @throws RateLimitException      When rate limits are exceeded
     * @throws ProviderException       When the API returns an error
     */
    public function embed(string|array $input, array $options = []): EmbeddingResponse
    {
        $model = $options['model'] ?? self::MODEL_EMBED_ENGLISH;
        $texts = is_array($input) ? $input : [$input];

        $payload = [
            'texts' => $texts,
            'model' => $model,
            'input_type' => 'search_document',
        ];

        $response = $this->embeddingRequest($payload);

        $embeddings = $response['embeddings']['float'] ?? $response['embeddings'] ?? [];

        return new EmbeddingResponse(
            embeddings: $embeddings,
            model: $response['meta']['api_version']['version'] ?? $model,
            usage: [
                'prompt_tokens' => $response['meta']['billed_units']['input_tokens'] ?? 0,
                'total_tokens' => $response['meta']['billed_units']['input_tokens'] ?? 0,
            ],
        );
    }

    /**
     * Whether this provider supports tool calling.
     */
    public function supportsTool(): bool
    {
        return true;
    }

    /**
     * Whether this provider supports vision/image inputs.
     */
    public function supportsVision(): bool
    {
        return false;
    }

    /**
     * Whether this provider supports structured output (JSON mode).
     */
    public function supportsStructuredOutput(): bool
    {
        return false;
    }

    /**
     * Get the provider identifier.
     */
    public function getName(): string
    {
        return 'cohere';
    }

    /**
     * Parse a Cohere v2 chat response into a Response object.
     *
     * @param array<Message> $messages
     */
    private function parseResponse(array $response, array $messages): Response
    {
        $text = '';
        $toolCalls = [];

        $content = $response['message']['content'] ?? [];
        if (!empty($content)) {
            $text = $content[0]['text'] ?? '';
        }

        foreach ($response['message']['tool_calls'] ?? [] as $tc) {
            $toolCalls[] = new ToolCall(
                id: $tc['id'],
                name: $tc['function']['name'],
                arguments: json_decode($tc['function']['arguments'], true) ?? [],
            );
        }

        $usage = [];
        if (isset($response['usage'])) {
            $usage = [
                'prompt_tokens' => $response['usage']['billed_units']['input_tokens'] ?? $response['usage']['tokens']['input_tokens'] ?? 0,
                'completion_tokens' => $response['usage']['billed_units']['output_tokens'] ?? $response['usage']['tokens']['output_tokens'] ?? 0,
            ];
        }

        return new Response(
            text: $text,
            toolCalls: $toolCalls,
            messages: $messages,
            usage: $usage,
            stopReason: $response['finish_reason'] ?? null,
        );
    }

    /**
     * Build the API request payload.
     */
    private function buildPayload(array $messages, array $options): array
    {
        $apiMessages = [];

        foreach ($messages as $message) {
            if ($message instanceof Message) {
                $apiMessages[] = $this->convertMessage($message);
            }
        }

        $payload = [
            'model' => $options['model'] ?? $this->defaultModel,
            'messages' => $apiMessages,
        ];

        if (isset($options['maxTokens'])) {
            $payload['max_tokens'] = $options['maxTokens'];
        }

        if (isset($options['temperature'])) {
            $payload['temperature'] = $options['temperature'];
        }

        if (isset($options['stopSequences'])) {
            $payload['stop_sequences'] = $options['stopSequences'];
        }

        // Handle tools
        if (isset($options['tools']) && !empty($options['tools'])) {
            $payload['tools'] = $this->convertTools($options['tools']);
        }

        // Forced tool choice. Cohere v2 takes uppercase REQUIRED or NONE, and has no mechanism for
        // forcing one *named* tool, so that case fails loudly rather than quietly downgrading to
        // "some tool". Omitting the field is how Cohere spells auto: the documented default is that
        // the model chooses freely, and there is no AUTO value to send.
        // Note: tool_choice needs command-r7b or newer, so an older model will reject it upstream.
        if (isset($options['toolChoice'])) {
            $choice = ToolChoice::fromOption($options['toolChoice'], $options['tools'] ?? []);

            if ($choice->forcesSpecificTool()) {
                throw new ProviderException(
                    sprintf(
                        'Cohere cannot force a specific tool; tool_choice accepts only REQUIRED or NONE. Use "required" to insist on a tool call, or drop to a provider that supports naming "%s".',
                        (string) $choice->toolName,
                    ),
                    $this->getName(),
                );
            }

            if (!empty($options['tools']) && !$choice->isAuto()) {
                $payload['tool_choice'] = $choice->mode === ToolChoice::NONE ? 'NONE' : 'REQUIRED';
            }
        }

        return $payload;
    }

    /**
     * Convert a Message to Cohere v2 API format (OpenAI-compatible).
     */
    private function convertMessage(Message $message): array
    {
        $apiMessage = [
            'role' => $this->convertRole($message->role),
        ];

        if ($message->isTool()) {
            $apiMessage['role'] = 'tool';
            $apiMessage['content'] = $message->content;
            $apiMessage['tool_call_id'] = $message->toolCallId;
        } elseif ($message->hasToolCalls()) {
            $apiMessage['content'] = $message->getText() ?: null;
            $apiMessage['tool_calls'] = array_map(function (ToolCall $tc) {
                return [
                    'id' => $tc->id,
                    'type' => 'function',
                    'function' => [
                        'name' => $tc->name,
                        'arguments' => json_encode($tc->arguments),
                    ],
                ];
            }, $message->toolCalls);
        } else {
            $apiMessage['content'] = $message->content;
        }

        return $apiMessage;
    }

    /**
     * Convert tools from PapiAI format to OpenAI-compatible format.
     */
    private function convertTools(array $tools): array
    {
        $openaiTools = [];

        foreach ($tools as $tool) {
            if (is_array($tool)) {
                $openaiTools[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                        'parameters' => $tool['input_schema'] ?? $tool['parameters'] ?? ['type' => 'object', 'properties' => []],
                    ],
                ];
            }
        }

        return $openaiTools;
    }

    /**
     * Convert Role to API role string.
     */
    private function convertRole(Role $role): string
    {
        return match ($role) {
            Role::System => 'system',
            Role::User => 'user',
            Role::Assistant => 'assistant',
            Role::Tool => 'tool',
        };
    }

    /**
     * Handle error responses from the Cohere API.
     *
     * @param int        $httpCode HTTP status code
     * @param array<string, mixed>|null $data     Decoded response body
     *
     * @throws AuthenticationException When status is 401
     * @throws RateLimitException      When status is 429
     * @throws ProviderException       For all other error statuses
     */
    protected function handleError(int $httpCode, ?array $data): void
    {
        $errorMessage = $data['message'] ?? $data['error']['message'] ?? 'Unknown error';

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

    /**
     * Make a synchronous chat API request via cURL.
     *
     * @param array<string, mixed> $payload JSON-encodable request body
     *
     * @return array<string, mixed> Decoded JSON response
     *
     * @throws ProviderException When the cURL request fails or API returns an error
     */
    protected function request(array $payload): array
    {
        $ch = curl_init(self::CHAT_API_URL);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error !== '') {
            throw new ProviderException(
                "Cohere API request failed: {$error}",
                'cohere',
            );
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            $this->handleError($httpCode, $data);
        }

        return $data;
    }

    /**
     * Make a streaming chat API request and parse SSE events.
     *
     * Buffers the full response then parses SSE data lines into decoded events.
     *
     * @param array<string, mixed> $payload JSON-encodable request body with stream=true
     *
     * @return Generator<int, array<string, mixed>> Yields decoded SSE event arrays
     */
    protected function streamRequest(array $payload): Generator
    {
        $ch = curl_init(self::CHAT_API_URL);

        $buffer = '';
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer) {
                $buffer .= $data;

                return strlen($data);
            },
        ]);

        curl_exec($ch);
        curl_close($ch);

        // Parse SSE events
        $lines = explode("\n", $buffer);
        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'data: ')) {
                $json = substr($line, 6);
                if ($json === '[DONE]') {
                    break;
                }
                $event = json_decode($json, true);
                if ($event !== null) {
                    yield $event;
                }
            }
        }
    }

    /**
     * Make an embeddings API request via cURL.
     *
     * @param array<string, mixed> $payload JSON-encodable request body
     *
     * @return array<string, mixed> Decoded JSON response
     *
     * @throws ProviderException When the cURL request fails or API returns an error
     */
    protected function embeddingRequest(array $payload): array
    {
        $ch = curl_init(self::EMBED_API_URL);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error !== '') {
            throw new ProviderException(
                "Cohere Embeddings API request failed: {$error}",
                'cohere',
            );
        }

        $data = json_decode($response, true);

        if ($httpCode >= 400) {
            $this->handleError($httpCode, $data);
        }

        return $data;
    }
}
