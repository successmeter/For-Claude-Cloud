<?php
// api/app/Ingest/Scanning/ScanResult.php
namespace App\Ingest\Scanning;

final class ScanResult
{
    public const CLEAN = 'clean';

    public const INFECTED = 'infected';

    /** The scanner could not give an answer. Callers refuse the file (fail closed). */
    public const ERROR = 'error';

    private function __construct(public readonly string $status, public readonly ?string $signature = null) {}

    public static function clean(): self
    {
        return new self(self::CLEAN);
    }

    public static function infected(string $signature): self
    {
        return new self(self::INFECTED, $signature);
    }

    public static function error(): self
    {
        return new self(self::ERROR);
    }
}
