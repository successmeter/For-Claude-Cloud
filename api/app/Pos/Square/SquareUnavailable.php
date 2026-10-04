<?php
// api/app/Pos/Square/SquareUnavailable.php
namespace App\Pos\Square;

/** Square was unreachable, rate limited us or failed: worth retrying later. */
class SquareUnavailable extends \RuntimeException {}
