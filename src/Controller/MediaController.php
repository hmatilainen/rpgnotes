<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Media\VaultMediaResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final class MediaController extends AbstractController
{
    private const MIME_TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
    ];

    public function __construct(private readonly VaultMediaResolver $resolver)
    {
    }

    #[Route('/media/{filename}', name: 'media_show', requirements: ['filename' => '[^/]+'], methods: ['GET'])]
    public function __invoke(string $filename): BinaryFileResponse
    {
        $path = $this->resolver->resolve($filename);
        if ($path === null) {
            throw $this->createNotFoundException('Image not found.');
        }

        $response = new BinaryFileResponse($path);
        // Set explicitly: symfony/mime isn't installed, so it can't be guessed.
        $response->headers->set('Content-Type', self::MIME_TYPES[strtolower(pathinfo($filename, PATHINFO_EXTENSION))]);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // SVGs can carry script; the sandbox CSP neutralises it if opened directly.
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}
