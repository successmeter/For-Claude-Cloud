<?php
// api/app/Hub/Exceptions/HubProblem.php
namespace App\Hub\Exceptions;

use App\Hub\Http\Problem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A refusal raised from inside a Hub write (version conflict, composition lock, ...). Thrown rather
 * than returned so it unwinds the request's tenant transaction: nothing the write already did
 * (e.g. the version bump) survives. Laravel renders it through render().
 */
class HubProblem extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $type,
        string $title,
        public readonly array $extra = [],
    ) {
        parent::__construct($title);
    }

    public static function versionMismatch(): self
    {
        return new self(412, 'version_mismatch', 'The set changed since you read it. Fetch it again.');
    }

    public static function preconditionRequired(): self
    {
        return new self(428, 'precondition_required', 'If-Match with the set version is required.');
    }

    public static function forbidden(string $title = 'Not allowed.'): self
    {
        return new self(403, 'forbidden', $title);
    }

    public static function notFound(): self
    {
        return new self(404, 'not_found', 'Not found.');
    }

    public function render(): JsonResponse
    {
        return Problem::response($this->status, $this->type, $this->getMessage(), $this->extra);
    }
}
