<?php

namespace PayMaya\Payment\Controller\Checkout;

use GuzzleHttp\Exception\ClientException;

class Index extends \Magento\Framework\App\Action\Action
{
    protected $checkoutSession;
    protected $client;
    protected $logger;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \PayMaya\Payment\Api\PayMayaClient $client,
        \Magento\Checkout\Model\Session $checkoutSession,
        \PayMaya\Payment\Logger\Logger $logger
    ) {
        parent::__construct($context);

        $this->checkoutSession = $checkoutSession;
        $this->client = $client;
        $this->logger = $logger;
    }

    public function execute()
    {
        $orderSession = $this->checkoutSession->getLastRealOrder();
        $incrementId = $orderSession->getIncrementId();
        $order = $this->_objectManager->create(\Magento\Sales\Model\Order::class);
        $order->loadByIncrementId($incrementId);

        // This is reached by a plain GET redirect after the order is placed, so it must only ever act on
        // an order of this session that is still waiting for its Maya payment: a reload after paying, a
        // canceled order or someone else's request must not create another checkout.
        if (!\PayMaya\Payment\Gateway\Order::awaitsMayaPayment($order)) {
            $this->logger->info('[Create Checkout] No order awaiting a Maya payment in this session; no checkout created');
            $this->_redirect('checkout/cart');
            return;
        }

        try {
            $response = $this->client->createCheckout($order);
            $checkout = json_decode($response, true);

            $this->logger->debug('[Create Checkout][Response]' . $response);

            $this->rememberCheckoutId($order, $checkout["checkoutId"] ?? null);

            $this->_redirect($checkout["redirectUrl"]);
        } catch (ClientException $e) {
            $this->logger->error('[Create Checkout]' . $e->getResponse()->getBody()->__toString());

            $this->checkoutSession->restoreQuote();
            $this->messageManager->addErrorMessage('Something went wrong with the payment');
            $this->_redirect('checkout/cart');
        }
    }

    /**
     * Maya's payment ID is the ID of the checkout that was paid, so keeping every checkout created for
     * the order lets PaymentVerifier reject payments created through any other checkout.
     */
    private function rememberCheckoutId($order, $checkoutId)
    {
        if (!is_string($checkoutId) || $checkoutId === '') {
            return;
        }

        try {
            $payment = $order->getPayment();
            \PayMaya\Payment\Gateway\PaymentVerifier::rememberCheckoutId($payment, $checkoutId);
            $payment->save();
        } catch (\Exception $e) {
            // Not fatal: without it verification falls back to the reference, amount and currency checks.
            $this->logger->error('[Create Checkout] Could not store the checkout ID: ' . $e->getMessage());
        }
    }
}
