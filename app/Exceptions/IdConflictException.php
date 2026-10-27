<?php

namespace App\Exceptions;

use RuntimeException;

/** ID buatan perangkat sudah dipakai untuk hal lain (posko lain, atau pengguna lain). */
class IdConflictException extends RuntimeException {}
