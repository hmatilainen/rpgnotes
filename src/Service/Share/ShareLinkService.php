<?php

declare(strict_types=1);

namespace App\Service\Share;

use App\Entity\Note;
use App\Entity\ShareToken;
use App\Repository\ShareTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ShareLinkService
{
    public function __construct(
        private readonly ShareTokenRepository $shareTokens,
        private readonly WhatsAppShareMessageBuilder $messageBuilder,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getOrCreateToken(Note $note): ShareToken
    {
        $existing = $this->shareTokens->findOneBy(['note' => $note]);
        if ($existing !== null) {
            return $existing;
        }

        $token = new ShareToken($note, bin2hex(random_bytes(32)));
        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }

    public function buildPublicShareUrl(ShareToken $shareToken): string
    {
        return $this->urlGenerator->generate(
            'share_show',
            ['token' => $shareToken->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    public function buildWhatsAppShareUrl(Note $note): string
    {
        $shareToken = $this->getOrCreateToken($note);
        $shareUrl = $this->buildPublicShareUrl($shareToken);
        $message = $this->messageBuilder->buildMessage($note, $shareUrl);

        return $this->messageBuilder->buildShareUrl($message);
    }
}
