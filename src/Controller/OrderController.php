<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class OrderController extends AbstractController
{
    #[Route('/api/myorders', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->getUser();
        $profile = $user->getProfile();
        return $this->json($profile->getorders(), 200, [], ['groups' => ['order:read']]);
    }
}
