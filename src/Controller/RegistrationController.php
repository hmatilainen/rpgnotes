<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/register/{token}', name: 'register', methods: ['GET', 'POST'])]
    public function __invoke(string $token, Request $request): Response
    {
        $user = $this->users->findOneByInviteToken($token);

        if ($user === null || !$user->isInviteValid()) {
            return $this->render('registration/invalid_invite.html.twig', [], new Response('', 404));
        }

        $error = null;

        if ($request->isMethod('POST')) {
            $username = trim((string) $request->request->get('username'));
            $password = (string) $request->request->get('password');
            $existing = $username !== '' ? $this->users->findOneByUsername($username) : null;

            if ($username === '' || $password === '') {
                $error = 'Username and password are required.';
            } elseif ($existing !== null && $existing !== $user) {
                $error = 'That username is already taken.';
            } else {
                $user->setUsername($username);
                $user->setPasswordHash($this->passwordHasher->hashPassword($user, $password));
                $user->setInviteToken(null);
                $user->setInviteTokenExpiresAt(null);
                $this->em->flush();

                return $this->redirectToRoute('login');
            }
        }

        return $this->render('registration/register.html.twig', [
            'invitedLabel' => $user->getLabel(),
            'error' => $error,
        ]);
    }
}
