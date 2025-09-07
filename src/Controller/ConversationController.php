<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Repository\ConversationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
 
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/conversations')]
final class ConversationController extends AbstractController
{
    public function __construct(private EntityManagerInterface $entityManager, private LoggerInterface $logger, private $hub = null)
    {
    }

    #[Route('', name: 'api_conversations_list', methods: ['GET'])]
    public function list(ConversationRepository $conversationRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $profile = $user->getProfile();
        $qb = $conversationRepository->createQueryBuilder('c');
        $qb->where('c.buyer = :p OR c.seller = :p')
            ->setParameter('p', $profile)
            ->orderBy('c.updatedAt', 'DESC');
        $conversations = $qb->getQuery()->getResult();

        return $this->json(
            $conversations,
            Response::HTTP_OK,
            [],
            ['groups' => ['conversation:read', 'message:read', 'profile:read', 'service:read', 'reservation:read']]
        );
    }

    #[Route('/{id}', name: 'api_conversations_show', methods: ['GET'])]
    public function show(Conversation $conversation): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $profile = $user->getProfile();
        if ($conversation->getBuyer() !== $profile && $conversation->getSeller() !== $profile) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        return $this->json(
            $conversation,
            Response::HTTP_OK,
            [],
            ['groups' => ['conversation:read', 'message:read', 'profile:read', 'service:read', 'reservation:read']]
        );
    }

    #[Route('/{id}/messages', name: 'api_conversations_send_message', methods: ['POST'])]
    public function sendMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['error' => 'Authentication required'], Response::HTTP_UNAUTHORIZED);
        }

        $profile = $user->getProfile();
        if ($conversation->getBuyer() !== $profile && $conversation->getSeller() !== $profile) {
            return $this->json(['error' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['content']) || trim($data['content']) === '') {
            return $this->json(['error' => 'Message content is required'], Response::HTTP_BAD_REQUEST);
        }

        $message = new Message();
        $message->setConversation($conversation)
            ->setSender($profile)
            ->setContent($data['content']);

        // Touch updatedAt so the list ordering reflects recent activity
        $conversation->touchUpdatedAt();

        $this->entityManager->persist($message);
        $this->entityManager->persist($conversation);
        $this->entityManager->flush();

        // Publish via Mercure unless explicitly disabled with ?skipPublish=1 (useful for backend-only tests)
        $skip = $request->query->getBoolean('skipPublish');
        $this->logger->info('sendMessage: preparing publish', [
            'conversationId' => $conversation->getId(),
            'hub_present' => (bool) $this->hub,
            'skip' => $skip,
        ]);
        if (!$skip && $this->hub) {
            $updateClass = '\\Symfony\\Component\\Mercure\\Update';
            if (class_exists($updateClass)) {
                // Publish to conversation topic (chat updates)
                try {
                    $this->logger->info('sendMessage: publishing to conversation topic', ['topic' => "https://lumeo.app/conversations/{$conversation->getId()}"]);
                    $this->hub->publish(new $updateClass(
                        "https://lumeo.app/conversations/{$conversation->getId()}",
                        json_encode([
                            'type' => 'message.created',
                            'conversationId' => $conversation->getId(),
                            'message' => [
                                'id' => $message->getId(),
                                'content' => $message->getContent(),
                                'senderProfileId' => $profile->getId(),
                                'createdAt' => $message->getCreatedAt()?->format(DATE_ATOM),
                            ],
                        ])
                    ));
                } catch (\Throwable $e) {
                    $this->logger->error('sendMessage: Mercure publish to conversation failed', ['error' => $e->getMessage()]);
                }

                // Publish notification to the receiver
                $receiver = $conversation->getBuyer()->getId() === $profile->getId()
                    ? $conversation->getSeller()
                    : $conversation->getBuyer();

                try {
                    $this->logger->info('sendMessage: publishing notification to receiver', ['topic' => "https://lumeo.app/profiles/{$receiver->getId()}/notifications"]);
                    $this->hub->publish(new $updateClass(
                        "https://lumeo.app/profiles/{$receiver->getId()}/notifications",
                        json_encode([
                            'type' => 'notification',
                            'event' => 'message.created',
                            'conversationId' => $conversation->getId(),
                            'messageId' => $message->getId(),
                        ])
                    ));
                } catch (\Throwable $e) {
                    $this->logger->error('sendMessage: Mercure publish to notifications failed', ['error' => $e->getMessage()]);
                }
            }
        }

        return $this->json(
            $message,
            Response::HTTP_CREATED,
            [],
            ['groups' => ['message:read', 'profile:read']]
        );
    }
}
