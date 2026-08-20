<?php

declare(strict_types=1);

namespace App\Service\OAuth;

final class OAuthTokenException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $message,
    ) {
        parent::__construct($message);
    }
}
