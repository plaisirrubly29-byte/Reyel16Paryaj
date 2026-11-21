<?php

namespace App\Modules\Payments\Exceptions;

use RuntimeException;

/** Webhook signé mais illisible ou incomplet. */
class InvalidWebhookException extends RuntimeException {}
