<?php

namespace App\Controller;

use App\Entity\Service;
use App\Entity\Reservation;
use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\Category;
use App\Repository\ServiceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
 
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Repository\CategoryRepository;

#[Route('/api/services')]
final class ServiceController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SerializerInterface $serializer,
        private ValidatorInterface $validator,
        private $hub = null
    ) {}

    #[Route('', name: 'api_services_list', methods: ['GET'])]
    public function list(ServiceRepository $serviceRepository): JsonResponse
    {
        $services = $serviceRepository->findBy(['status' => Service::STATUS_PUBLISHED]);
        
        return $this->json(
            $services,
            Response::HTTP_OK,
            [],
            ['groups' => ['service:read']]
        );
    }

    #[Route('/{id}', name: 'api_services_show', methods: ['GET'])]
    public function show(Service $service): JsonResponse
    {
        return $this->json(
            $service,
            Response::HTTP_OK,
            [],
            ['groups' => ['service:read']]
        );
    }

    #[Route('', name: 'api_services_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        $categoryIds = $data['tags'] ?? [];
        unset($data['tags']); // On enlève les tags pour la désérialisation

        /** @var Service $service */
        $service = $this->serializer->deserialize(
            json_encode($data),
            Service::class,
            'json'
        );

        // Gestion des catégories
        if (!empty($categoryIds)) {
            $categoryRepository = $this->entityManager->getRepository(Category::class);
            foreach ($categoryIds as $categoryId) {
                $category = $categoryRepository->find($categoryId);
                if ($category) {
                    $service->addTag($category);
                }
            }
        }

        $service->setByProfile($user->getProfile());
        $service->setStatus(Service::STATUS_PENDING_REVIEW);

        $errors = $this->validator->validate($service);
        if (count($errors) > 0) {
            return $this->json($errors, Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->persist($service);
        $this->entityManager->flush();

        return $this->json(
            $service,
            Response::HTTP_CREATED,
            [],
            ['groups' => ['service:read']]
        );
    }

    #[Route('/{id}', name: 'api_services_update', methods: ['PUT'])]
    public function update(Request $request, Service $service, CategoryRepository $categoryRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user || $service->getByProfile() !== $user->getProfile()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        
        // Ne pas permettre de modifier le statut directement via l'API publique
        if (isset($data['status'])) {
            unset($data['status']);
        }

        // Gérer les catégories séparément pour éviter le cascade persist
        $categoryIds = $data['tags'] ?? [];
        unset($data['tags']); // Retirer les tags avant la désérialisation

        $service = $this->serializer->deserialize(
            json_encode($data),
            Service::class,
            'json',
            ['object_to_populate' => $service]
        );

        // Vider les tags existants et ajouter les nouveaux
        $service->getTags()->clear();
        foreach ($categoryIds as $categoryId) {
            $category = $categoryRepository->find($categoryId);
            if ($category) {
                $service->addTag($category);
            }
        }

        $errors = $this->validator->validate($service);
        if (count($errors) > 0) {
            return $this->json($errors, Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        return $this->json(
            $service,
            Response::HTTP_OK,
            [],
            ['groups' => ['service:read']]
        );
    }

    #[Route('/{id}', name: 'api_services_delete', methods: ['DELETE'])]
    public function delete(Service $service): JsonResponse
    {
        $user = $this->getUser();
        if (!$user || $service->getByProfile() !== $user->getProfile()) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($service);
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/purchase', name: 'api_services_purchase', methods: ['POST'])]
    public function purchase(Request $request, Service $service): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $buyer = $user->getProfile();
        $seller = $service->getByProfile();

        if ($seller === null) {
            return $this->json(['error' => 'Service owner not found'], Response::HTTP_BAD_REQUEST);
        }

        if ($seller->getId() === $buyer->getId()) {
            return $this->json(['error' => 'You cannot purchase your own service'], Response::HTTP_BAD_REQUEST);
        }

        if ($service->getStatus() !== Service::STATUS_PUBLISHED) {
            return $this->json(['error' => 'Service is not published'], Response::HTTP_BAD_REQUEST);
        }

        if (!$service->isAvailable()) {
            return $this->json(['error' => 'Service is not available'], Response::HTTP_CONFLICT);
        }

        $cost = (int) ($service->getCost() ?? 0);
        $buyerCredits = (int) ($buyer->getCredits() ?? 0);
        if ($buyerCredits < $cost) {
            return $this->json(['error' => 'Insufficient credits'], Response::HTTP_PAYMENT_REQUIRED);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $initialMessage = isset($data['message']) && is_string($data['message']) ? trim($data['message']) : '';

        $conn = $this->entityManager->getConnection();
        $conn->beginTransaction();
        try {
            // Acquire a pessimistic write lock to prevent concurrent purchases
            $this->entityManager->lock($service, LockMode::PESSIMISTIC_WRITE);
            $this->entityManager->refresh($service);

            // Re-check availability under transaction scope
            if (!$service->isAvailable()) {
                throw new \RuntimeException('Service is not available');
            }
            $service->setIsAvailable(false);

            // Deduct credits from buyer (we do not credit seller yet — business rule dependent)
            $buyer->setCredits($buyerCredits - $cost);

            // Create reservation
            $reservation = new Reservation();
            $reservation->setBuyer($buyer)
                ->setSeller($seller)
                ->setService($service)
                ->setPriceTokens($cost);

            // Create conversation
            $conversation = new Conversation();
            $conversation->setBuyer($buyer)
                ->setSeller($seller)
                ->setService($service)
                ->setReservation($reservation);

            // Optional initial message from buyer
            if ($initialMessage !== '') {
                $message = new Message();
                $message->setConversation($conversation)
                    ->setSender($buyer)
                    ->setContent($initialMessage);
                $this->entityManager->persist($message);
            }

            $this->entityManager->persist($reservation);
            $this->entityManager->persist($conversation);
            $this->entityManager->persist($buyer);
            $this->entityManager->persist($service);
            $this->entityManager->flush();

            $conn->commit();

            // Publish notifications via Mercure (can be skipped with ?skipPublish=1 for debugging)
            if (!$request->query->getBoolean('skipPublish') && $this->hub) {
                $updateClass = '\\Symfony\\Component\\Mercure\\Update';
                if (class_exists($updateClass)) {
                    // Notify seller that a reservation has been created
                    $this->hub->publish(new $updateClass(
                        "https://lumeo.app/profiles/{$seller->getId()}/notifications",
                        json_encode([
                            'type' => 'notification',
                            'event' => 'reservation.created',
                            'reservationId' => $reservation->getId(),
                            'serviceId' => $service->getId(),
                            'buyerProfileId' => $buyer->getId(),
                        ])
                    ));

                    // Notify conversation topic (conversation created)
                    $this->hub->publish(new $updateClass(
                        "https://lumeo.app/conversations/{$conversation->getId()}",
                        json_encode([
                            'type' => 'conversation.created',
                            'conversationId' => $conversation->getId(),
                        ])
                    ));

                    // If there is an initial message, also publish it to conversation and a notification to the seller
                    if (!empty($initialMessage) && isset($message)) {
                        $this->hub->publish(new $updateClass(
                            "https://lumeo.app/conversations/{$conversation->getId()}",
                            json_encode([
                                'type' => 'message.created',
                                'conversationId' => $conversation->getId(),
                                'message' => [
                                    'id' => $message->getId(),
                                    'content' => $message->getContent(),
                                    'senderProfileId' => $buyer->getId(),
                                    'createdAt' => $message->getCreatedAt()?->format(DATE_ATOM),
                                ],
                            ])
                        ));

                        $this->hub->publish(new $updateClass(
                            "https://lumeo.app/profiles/{$seller->getId()}/notifications",
                            json_encode([
                                'type' => 'notification',
                                'event' => 'message.created',
                                'conversationId' => $conversation->getId(),
                                'messageId' => $message->getId(),
                            ])
                        ));
                    }
                }
            }

            return $this->json(
                [
                    'reservation' => $reservation,
                    'conversation' => $conversation,
                ],
                Response::HTTP_CREATED,
                [],
                ['groups' => ['reservation:read', 'conversation:read', 'message:read', 'profile:read', 'service:read']]
            );
        } catch (\Throwable $e) {
            $conn->rollBack();
            return $this->json(['error' => 'Purchase failed', 'details' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
