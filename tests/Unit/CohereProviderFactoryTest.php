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
use PapiAI\Cohere\CohereProviderFactory;
use PapiAI\Core\Exception\ConfigurationException;
use PapiAI\Core\Factory\ComposerDiscovery;

describe('CohereProviderFactory', function () {
    beforeEach(function () {
        $this->factory = new CohereProviderFactory();
        $this->property = fn (object $provider, string $name): mixed => (fn () => $this->{$name})->call($provider);
    });

    it('is named after its package', function () {
        expect($this->factory->name())->toBe('cohere');
        expect($this->factory->package())->toBe('papi-ai/cohere');
    });

    it('is declared in composer.json, where discovery looks for it', function () {
        $manifest = json_decode((string) file_get_contents(__DIR__ . '/../../composer.json'), true);

        expect($manifest['name'])->toBe($this->factory->package());
        expect($manifest['type'])->toBe(ComposerDiscovery::PACKAGE_TYPE);
        expect($manifest['extra']['papi']['factory'])->toBe(CohereProviderFactory::class);
    });

    it('takes what the constructor takes', function () {
        expect(array_map(fn ($setting) => $setting->name, $this->factory->settings()))->toBe(['api_key', 'model']);
    });

    it('builds the provider from its settings', function () {
        $provider = $this->factory->create([
            'api_key' => 'k',
            'model' => 'custom-model',
        ]);

        expect($provider)->toBeInstanceOf(CohereProvider::class);
        expect(($this->property)($provider, 'apiKey'))->toBe('k');
        expect(($this->property)($provider, 'defaultModel'))->toBe('custom-model');
    });

    it('leaves what it is not given to the provider\'s own defaults', function () {
        $provider = $this->factory->create(['api_key' => 'k']);

        expect(($this->property)($provider, 'defaultModel'))->toBe(CohereProvider::MODEL_COMMAND_A_PLUS);
    });

    it('refuses to build without its required settings', function () {
        expect(fn () => $this->factory->create([]))
            ->toThrow(ConfigurationException::class, 'Provider "cohere" needs the "api_key" setting');
    });
});
