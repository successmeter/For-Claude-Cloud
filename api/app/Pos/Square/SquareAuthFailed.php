<?php
// api/app/Pos/Square/SquareAuthFailed.php
namespace App\Pos\Square;

/** Square refused our credentials or a token: the connection needs the owner to reconnect. */
class SquareAuthFailed extends \RuntimeException {}
