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

use PapiAI\Cohere\CohereModel;
use PapiAI\Cohere\CohereProvider;

describe('CohereModel', function () {
    it('is the source of truth the old constants alias', function () {
        expect(CohereProvider::MODEL_COMMAND_A_PLUS)->toBe(CohereModel::CommandAPlus->value);
    });

    it('ships unique IDs', function () {
        $ids = array_map(fn (CohereModel $m) => $m->value, CohereModel::cases());

        expect($ids)->toBe(array_unique($ids));
    });

    it('returns null for an ID it has not heard of, rather than throwing', function () {
        expect(CohereModel::tryFrom('not-a-model'))->toBeNull();
    });

    it('knows which models are retired, when, and what replaces them', function () {
        expect(CohereModel::CommandRPlus->isDeprecated())->toBeTrue();
        expect(CohereModel::CommandRPlus->retiredOn())->toBe('2025-09-15');
        expect(CohereModel::CommandRPlus->replacement())->toBe(CohereModel::CommandA);
        expect(CohereModel::CommandAPlus->isDeprecated())->toBeFalse();
    });
});
