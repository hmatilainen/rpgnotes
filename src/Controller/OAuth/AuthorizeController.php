<?php

declare(strict_types=1);

namespace App\Controller\OAuth;

use App\Entity\User;
use App\Service\OAuth\OAuthAuthorizationCodeService;
use App\Service\OAuth\OAuthClientRegistry;
use App\Service\OAuth\OAuthServerConfig;
use App\Service\Sidebar\SidebarBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/authorize')]
final class AuthorizeController extends AbstractController
{
    public function __construct(
        private readonly OAuthClientRegistry $clients,
        private readonly OAuthAuthorizationCodeService $authorizationCodes,
        private readonly OAuthServerConfig $config,
        private readonly SidebarBuilder $sidebar,
    ) {
    }

    #[Route('', name: 'oauth_authorize', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_PLAYER')]
    public function authorize(Request $request): Response
    {
        $params = $request->isMethod('POST')
            ? array_merge($request->query->all(), $request->request->all())
            : $request->query->all();

        $error = $this->validateAuthorizeParams($params);
        if ($error !== null) {
            return new Response($error, Response::HTTP_BAD_REQUEST);
        }

        $clientId = (string) $params['client_id'];
        $redirectUri = (string) $params['redirect_uri'];
        $state = isset($params['state']) ? (string) $params['state'] : '';
        $codeChallenge = (string) $params['code_challenge'];
        $resource = isset($params['resource']) ? (string) $params['resource'] : null;
        $scope = isset($params['scope']) && $params['scope'] !== ''
            ? (string) $params['scope']
            : OAuthServerConfig::SCOPE;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('oauth_authorize', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }

            if ($request->request->has('deny')) {
                return $this->redirect($this->buildRedirect($redirectUri, [
                    'error' => 'access_denied',
                    'state' => $state,
                ]));
            }

            $user = $this->getUser();
            if (!$user instanceof User) {
                throw $this->createAccessDeniedException();
            }

            $code = $this->authorizationCodes->create(
                $user,
                $clientId,
                $redirectUri,
                $codeChallenge,
                $resource,
                $scope,
            );

            return $this->redirect($this->buildRedirect($redirectUri, [
                'code' => $code,
                'state' => $state,
                'iss' => $this->config->getIssuer(),
            ]));
        }

        return $this->render('oauth/authorize.html.twig', [
            'sidebar' => $this->sidebar->build($this->isGranted('ROLE_ADMIN')),
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => (string) $params['code_challenge_method'],
            'resource' => $resource ?? '',
            'scope' => $scope,
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function validateAuthorizeParams(array $params): ?string
    {
        if (($params['response_type'] ?? '') !== 'code') {
            return 'Unsupported response_type.';
        }

        $clientId = (string) ($params['client_id'] ?? '');
        $redirectUri = (string) ($params['redirect_uri'] ?? '');
        if ($clientId === '' || $redirectUri === '') {
            return 'Missing client_id or redirect_uri.';
        }

        if (!$this->clients->allowsAuthorizeRequest($clientId, $redirectUri)) {
            return 'Invalid client_id or redirect_uri.';
        }

        if (($params['code_challenge_method'] ?? '') !== 'S256') {
            return 'PKCE S256 is required.';
        }

        if (($params['code_challenge'] ?? '') === '') {
            return 'Missing code_challenge.';
        }

        $resource = $params['resource'] ?? null;
        if ($resource !== null && $resource !== '' && $resource !== $this->config->getResource()) {
            return 'Invalid resource.';
        }

        return null;
    }

    /**
     * @param array<string, string> $query
     */
    private function buildRedirect(string $redirectUri, array $query): string
    {
        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return $redirectUri.$separator.http_build_query($query);
    }
}
