<?php

declare(strict_types=1);

namespace App\Service\Vault;

use App\Repository\NoteRepository;

/**
 * Turns admin input (vault path, slug, or /notes/… URL) into a vault-relative path
 * understood by {@see HiddenPathMatcher}.
 */
final class HiddenPathNormalizer
{
    public function __construct(
        private readonly NoteRepository $notes,
    ) {
    }

    public function normalize(string $input): string
    {
        $path = trim($input, " \t\n\r\0\x0B/");

        if ($path === '') {
            return '';
        }

        if (str_starts_with(mb_strtolower($path), 'notes/')) {
            $path = substr($path, \strlen('notes/'));
        }

        $bySlug = $this->notes->findOneBySlug($path);
        if ($bySlug !== null) {
            return $bySlug->getVaultPath();
        }

        if (!str_ends_with(mb_strtolower($path), '.md')) {
            $byVaultPath = $this->notes->findOneByVaultPath($path.'.md');
            if ($byVaultPath !== null) {
                return $byVaultPath->getVaultPath();
            }
        }

        return $path;
    }
}
