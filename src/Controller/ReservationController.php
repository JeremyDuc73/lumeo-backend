<?php

namespace App\Controller;

use App\Entity\Reservation;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Annotation\Groups;

#[Route('/api/reservations')]
final class ReservationController extends AbstractController
{
    public function __construct(private EntityManagerInterface $entityManager, private $hub = null)
    {
    }

    #[Route('/{id}/complete', name: 'api_reservations_complete', methods: ['POST'])]
    public function complete(Reservation $reservation): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $profile = $user->getProfile();
        if ($reservation->getSeller()?->getId() !== $profile->getId()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        if ($reservation->getStatus() !== Reservation::STATUS_RESERVED) {
            return $this->json(['error' => 'Reservation is not active'], Response::HTTP_BAD_REQUEST);
        }

        // Mark as completed and make service available again
        $reservation->setStatus(Reservation::STATUS_COMPLETED);
        $service = $reservation->getService();
        if ($service) {
            $service->setIsAvailable(true);
            $this->entityManager->persist($service);
        }

        $this->entityManager->persist($reservation);
        $this->entityManager->flush();

        // Optional: publish Mercure notifications to buyer and seller
        $updateClass = '\\Symfony\\Component\\Mercure\\Update';
        if ($this->hub && class_exists($updateClass)) {
            try {
                $sellerId = $reservation->getSeller()?->getId();
                $buyerId = $reservation->getBuyer()?->getId();
                if ($sellerId) {
                    $this->hub->publish(new $updateClass(
                        "https://lumeo.app/profiles/{$sellerId}/notifications",
                        json_encode([
                            'type' => 'notification',
                            'event' => 'reservation.completed',
                            'reservationId' => $reservation->getId(),
                        ])
                    ));
                }
                if ($buyerId) {
                    $this->hub->publish(new $updateClass(
                        "https://lumeo.app/profiles/{$buyerId}/notifications",
                        json_encode([
                            'type' => 'notification',
                            'event' => 'reservation.completed',
                            'reservationId' => $reservation->getId(),
                        ])
                    ));
                }
            } catch (\Throwable $e) {
                // ignore publish errors
            }
        }

        return $this->json($reservation, Response::HTTP_OK, [], ['groups' => ['reservation:read']]);
    }
}
