<?php

namespace App\Exceptions;

use RuntimeException;

/** Operasi dari perangkat lapangan ditolak secara permanen; mengirim ulang tidak akan mengubah hasilnya. */
class SyncRejectedException extends RuntimeException {}
