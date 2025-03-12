<?php

namespace App\Controller;

use App\Entity\Image;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Vich\UploaderBundle\Handler\UploadHandler;

final class ProfileController extends AbstractController
{
    #[Route('/api/myprofile', methods: ['GET'])]
    public function myProfile(): Response
    {
        return $this->json($this->getUser()->getProfile(), 200, [], ['groups'=>'profile:read']);
    }

    #[Route('/api/profile/edit', methods: ['POST'])]
    public function edit(Request $request, EntityManagerInterface $manager): JsonResponse
    {
        $user = $this->getUser();
        $profile = $user->getProfile();

        $data = json_decode($request->getContent(), true);
        if (isset($data['username'])) {
            $profile->setUsername($data['username']);
        }
        if (isset($data['description'])) {
            $profile->setDescription($data['description']);
        }

        $manager->persist($profile);
        $manager->flush();
        return $this->json(['message'=> 'Profil mis à jour'],200);
    }

    #[Route('/api/profile/edit-image', methods: ['POST'])]
    public function editImage(
        Request $request,
        UploadHandler $uploadHandler,
        EntityManagerInterface $manager,
    ): JsonResponse
    {
        $user = $this->getUser();
        $profile = $user->getProfile();

        $uploadedFile = $request->files->get('imageFile');

        $oldImage = $profile->getImage();

        if ($oldImage) {
            $profile->setImage(null);
            $uploadHandler->remove($oldImage, 'imageFile');
            $manager->remove($oldImage);
            $manager->flush();
        }

        if (!$uploadedFile) {
            if ($oldImage) {
                $profile->setImage(null);
                $uploadHandler->remove($oldImage, 'imageFile');
                $manager->remove($oldImage);
                $manager->flush();
            }
            return $this->json('Image deleted', 200);
        } else {
            $newImage = new Image();
            $newImage->setImageFile($uploadedFile);
            $uploadHandler->upload($newImage, 'imageFile');

            $profile->setImage($newImage);

            $manager->persist($newImage);
            $manager->persist($profile);
            $manager->flush();

            return $this->json(['message'=>'Image du profil mise à jour'],200);
        }
    }

}
