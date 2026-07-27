# Cohere

Cohere provider for PapiAI.

## Installation

```bash
composer require papi-ai/cohere
```

## Usage

```php
use PapiAI\Core\Agent;
use PapiAI\Cohere\CohereProvider;

$provider = new CohereProvider(
    apiKey: $_ENV['COHERE_API_KEY'],
);

$agent = new Agent(
    provider: $provider,
    instructions: 'You are a helpful assistant.',
);

$response = $agent->run('Hello!');
echo $response->text;
```

## Chat Models

```php
CohereProvider::MODEL_COMMAND_R_PLUS  // 'command-r-plus' (default)
CohereProvider::MODEL_COMMAND_R       // 'command-r'
CohereProvider::MODEL_COMMAND         // 'command'
```

## Embedding Models

```php
CohereProvider::MODEL_EMBED_ENGLISH       // 'embed-english-v3.0'
CohereProvider::MODEL_EMBED_MULTILINGUAL  // 'embed-multilingual-v3.0'
```

## Capabilities

| Capability | Supported |
|---|---|
| Chat | Yes |
| Streaming | Yes |
| Tool calling | Yes |
| Embeddings | Yes |

Cohere uses its own v2 Chat API format, which is not OpenAI-compatible. The PapiAI provider handles all format conversion transparently, so you use the same unified API as with any other provider.

## Requirements

- PHP 8.2+
- `ext-curl`
- `papi-ai/papi-core` ^0.14
