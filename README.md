# PapiAI Cohere Provider

[![Tests](https://github.com/papi-ai/cohere/workflows/CI/badge.svg)](https://github.com/papi-ai/cohere/actions?query=workflow%3ACI)

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
CohereProvider::MODEL_COMMAND_R_PLUS  // 'command-r-plus' (default)
CohereProvider::MODEL_COMMAND_R       // 'command-r'
CohereProvider::MODEL_COMMAND         // 'command'
```

### Embedding Models

```php
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
