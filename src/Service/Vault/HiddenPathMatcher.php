<?php

declare(strict_types=1);

namespace App\Service\Vault;

final class HiddenPathMatcher
{
    /**
     * @param string[] $hiddenPaths
     */
    public function isHidden(string $vaultPath, array $hiddenPaths): bool
    {
        $normalizedHidden = array_map(
            static fn (string $path) => mb_strtolower(trim($path, '/')),
            $hiddenPaths
        );

        $segments = explode('/', $vaultPath);
        $candidate = '';

        foreach ($segments as $segment) {
            $candidate = $candidate === '' ? $segment : $candidate . '/' . $segment;

            if (\in_array(mb_strtolower($candidate), $normalizedHidden, true)) {
                return true;
            }
        }

        return false;
    }
}
