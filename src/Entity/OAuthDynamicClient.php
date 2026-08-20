<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OAuthDynamicClientRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OAuthDynamicClientRepository::class)]
#[ORM\Table(name: 'oauth_dynamic_clients')]
class OAuthDynamicClient
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $clientId = '';

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $redirectUris = [];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param list<string> $redirectUris
     */
    public function __construct(string $clientId, array $redirectUris)
    {
        $this->clientId = $clientId;
        $this->redirectUris = $redirectUris;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    /** @return list<string> */
    public function getRedirectUris(): array
    {
        return $this->redirectUris;
    }
}
