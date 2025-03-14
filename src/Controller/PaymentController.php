<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Profile;
use App\Repository\ProfileRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Checkout\Session;
use Stripe\Stripe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PaymentController extends AbstractController
{
    #[Route('/api/payment/create', methods: ['POST'])]
    public function createPayment(Request $request): Response
    {
        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!isset($data['amount']) || !isset($data['coins'])) {
            return $this->json(['error' => 'Missing amount or coins'], 400);
        }

        $amount = $data['amount'];
        $coins = $data['coins'];

        $checkoutSession = Session::create([
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => [
                        'name' => "Achat de $coins LumCoins",
                    ],
                    'unit_amount' => $amount * 100,
                ],
                'quantity' => 1,
            ]],
            'metadata' => [
                'profile_id' => $user->getProfile()->getId(),
                'coins' => $coins,
            ],
            'mode' => 'payment',
            'success_url' => 'http://localhost:3000/payment-success?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'http://localhost:3000/profile',
        ]);

        return $this->json(['checkoutUrl' => $checkoutSession->url]);
    }
    #[Route('/api/payment/confirm-checkout', methods: ['POST'])]
    public function confirmCheckoutPayment(Request $request,  EntityManagerInterface $entityManager): Response
    {
        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

        $data = json_decode($request->getContent(), true);
        if (!isset($data['session_id'])) {
            return $this->json(['error' => 'Missing session_id'], 400);
        }

        $session = Session::retrieve($data['session_id']);
        if (!$session || $session->payment_status !== 'paid') {
            return $this->json(['error' => 'Payment not successful'], 400);
        }

        $metadata = $session->metadata;
        $profile = $entityManager->getRepository(Profile::class)->find(intval($metadata['profile_id']));
        if (!$profile) {
            return $this->json(['error' => 'Profile not found'], 404);
        }

        $coins = (int) $metadata['coins'];

        $existingOrder = $entityManager->getRepository(Order::class)->findOneBy(['stripeSessionId' => $session->id]);

        if ($existingOrder) {
            return $this->json(['error' => 'Order already confirmed'], 400);
        }

        $profile->setCredits($profile->getCredits() + $coins);

        $order = new Order();
        $order->setProfile($profile);
        $order->setStripeSessionId($session->id);
        $order->setAmount($session->amount_total / 100);
        $order->setCoins($coins);

        $entityManager->persist($profile);
        $entityManager->persist($order);
        $entityManager->flush();

        return $this->json(['success' => true], 200);
    }
}
