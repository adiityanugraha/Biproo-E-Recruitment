<?php

namespace App\Libraries;

use RuntimeException;

/** Semua kegagalan komunikasi dengan Zoom API diseragamkan ke sini. */
class ZoomException extends RuntimeException {}
