<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileController extends AbstractController
{
    #[Route('/api/myprofile', methods: ['GET'])]
    public function myProfile(): Response
    {
        return $this->json($this->getUser()->getProfile(), 200, [], ['groups'=>'profile:read']);
    }

}
