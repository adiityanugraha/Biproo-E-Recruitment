<?php

namespace App\Libraries;

use RuntimeException;

/** Semua kegagalan komunikasi dengan microservice AI diseragamkan ke sini. */
class AiServiceException extends RuntimeException {}
