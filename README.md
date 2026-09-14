# PapiAI Cohere Provider

[![CI](https://github.com/papi-ai/cohere/workflows/CI/badge.svg)](https://github.com/papi-ai/cohere/actions?query=workflow%3ACI) [![Latest Version](https://img.shields.io/packagist/v/papi-ai/cohere.svg)](https://packagist.org/packages/papi-ai/cohere) [![Total Downloads](https://img.shields.io/packagist/dt/papi-ai/cohere.svg)](https://packagist.org/packages/papi-ai/cohere) [![PHP Version](https://img.shields.io/packagist/php-v/papi-ai/cohere.svg)](https://packagist.org/packages/papi-ai/cohere) [![License](https://img.shields.io/packagist/l/papi-ai/cohere.svg)](https://packagist.org/packages/papi-ai/cohere)

Cohere provider for [PapiAI](https://github.com/papi-ai/papi-core) - A simple but powerful PHP library for building AI agents.

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

## Available Models

### Chat Models

```php
CohereProvider::MODEL_COMMAND_A_PLUS      // 'command-a-plus-05-2026' (default)
CohereProvider::MODEL_COMMAND_A           // 'command-a-03-2025'
CohereProvider::MODEL_COMMAND_A_REASONING // 'command-a-reasoning-08-2025'
CohereProvider::MODEL_COMMAND_R7B         // 'command-r7b-12-2024'
```

The `MODEL_COMMAND`, `MODEL_COMMAND_R` and `MODEL_COMMAND_R_PLUS` constants are still shipped but deprecated: all three were deprecated on 15 September 2025, and the two oldest predate `command-r7b`, so they reject forced tool choice.


### Embedding Models

```php
CohereProvider::MODEL_EMBED_V4            // 'embed-v4.0'
CohereProvider::MODEL_EMBED_ENGLISH       // 'embed-english-v3.0'
CohereProvider::MODEL_EMBED_MULTILINGUAL  // 'embed-multilingual-v3.0'
```

## Features

- Chat completions via Cohere v2 API
- Tool/function calling
- Streaming support
- Text embeddings

## License

MIT
