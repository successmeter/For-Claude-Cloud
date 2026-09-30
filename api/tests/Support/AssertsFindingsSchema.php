<?php
// api/tests/Support/AssertsFindingsSchema.php

namespace Tests\Support;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/** Validates an insights document against api/schemas/findings.v1.json. */
trait AssertsFindingsSchema
{
    protected function assertMatchesFindingsSchema(array|string $document): void
    {
        $validator = new Validator;
        $validator->resolver()->registerFile('https://hub/schemas/findings.v1.json', base_path('schemas/findings.v1.json'));
        $result = $validator->validate(json_decode(is_string($document) ? $document : json_encode($document)), 'https://hub/schemas/findings.v1.json');

        $this->assertSame([], $result->isValid() ? [] : (new ErrorFormatter)->format($result->error()), 'Document does not match findings.v1.json');
    }
}
