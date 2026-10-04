<?php
// api/app/Pos/Square/SquareRequestFailed.php
namespace App\Pos\Square;

/** Square rejected a request as malformed or not found (4xx other than auth and rate limits): not retried. */
class SquareRequestFailed extends \RuntimeException {}
