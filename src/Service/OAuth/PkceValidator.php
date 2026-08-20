<?php

declare(strict_types=1);

namespace App\Service\OAuth;

final class PkceValidator
{
    public function verify(string $codeVerifier, string $codeChallenge): bool
    {
        $hash = hash('sha256', $codeVerifier, true);
        $computed = rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');

        return hash_equals($codeChallenge, $computed);
    }
}
