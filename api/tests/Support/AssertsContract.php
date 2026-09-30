<?php
// api/tests/Support/AssertsContract.php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Validates payloads against the hub contract's JSON Schemas (api/contract/v1). The schemas are
 * the contract's source of truth; every /hub/v1 response test runs its body through here.
 */
trait AssertsContract
{
    protected function assertMatchesContract(TestResponse|array $payload, string $schema): void
    {
        $errors = $this->contractErrors($payload, $schema);

        $this->assertSame([], $errors, "Payload does not match contract/v1/{$schema}.schema.json");
    }

    /** @return array<string, mixed> validation errors keyed by JSON pointer; empty when valid */
    protected function contractErrors(TestResponse|array $payload, string $schema): array
    {
        $json = $payload instanceof TestResponse ? $payload->getContent() : json_encode($payload);

        $validator = new Validator;
        $validator->resolver()->registerPrefix('https://hub/contract/v1/', base_path('contract/v1'));

        $result = $validator->validate(json_decode($json), "https://hub/contract/v1/{$schema}.schema.json");

        return $result->isValid() ? [] : (new ErrorFormatter)->format($result->error());
    }
}
