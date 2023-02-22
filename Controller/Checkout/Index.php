<?php

namespace PayMaya\Payment\Controller\Checkout;

use Exception;

class Index extends \Magento\Framework\App\Action\Action
{
    protected $checkoutSession;
    protected $client;
    protected $logger;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \PayMaya\Payment\Api\PayMayaClient $client,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Psr\Log\LoggerInterface $logger
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

        try {
            $response = $this->client->createCheckout($order);
            $checkout = json_decode($response, true);

            $this->logger->debug('Checkout response ' . $response);

            $this->_redirect($checkout["redirectUrl"]);
        } catch (Exception $e) {
            $this->checkoutSession->restoreQuote();
            $this->messageManager->addErrorMessage('Something went wrong with the payment');
            $this->_redirect('checkout/cart');
        }
    }
}
