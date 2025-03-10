<?php

namespace App\Controller;

use App\Entity\Profile;
use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;

class RegistrationController extends AbstractController
{
    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager): Response
    {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var string $plainPassword */
            $plainPassword = $form->get('plainPassword')->getData();

            // encode the plain password
            $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));

            $profile = new Profile();
            $currentDate = new \DateTimeImmutable();
            $profile->setCreatedAt($currentDate);
            $user->setProfile($profile);

            $entityManager->persist($user);
            $entityManager->flush();

            // do anything else you need here, like send an email

            return $this->redirectToRoute('app_home');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

     #[Route('/register_check', methods: ['POST'])]
     public function registerApi(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, SerializerInterface $serializer, UserRepository $repo): Response
     {
         $user = $serializer->deserialize($request->getContent(), User::class, 'json');

         $user->setPassword($userPasswordHasher->hashPassword($user, $user->getPassword()));

         $alreadyExists = $repo->findOneBy(['email' => $user->getEmail()]);

         if (!$alreadyExists) {
             $profile = new Profile();
             $currentDate = new \DateTimeImmutable();
             $profile->setCreatedAt($currentDate);
             $user->setProfile($profile);
             $entityManager->persist($user);
             $entityManager->flush();
             return $this->json($user, 200, [], ['groups' => 'user:read']);
         } else {
             return $this->json('email already used', 401);
         }
     }
}
