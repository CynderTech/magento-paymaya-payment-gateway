<?php

namespace PayMaya\Payment\Controller\Checkout;

use GuzzleHttp\Exception\ClientException;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;

class Index extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \PayMaya\Payment\Api\PayMayaClient
     */
    protected $client;

    /**
     * @var \PayMaya\Payment\Logger\Logger
     */
    protected $logger;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * Index constructor.
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param \PayMaya\Payment\Api\PayMayaClient $client
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \PayMaya\Payment\Logger\Logger $logger
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \PayMaya\Payment\Api\PayMayaClient $client,
        \Magento\Checkout\Model\Session $checkoutSession,
        \PayMaya\Payment\Logger\Logger $logger,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
    ) {
        parent::__construct($context);

        $this->checkoutSession = $checkoutSession;
        $this->client = $client;
        $this->logger = $logger;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Execute action
     *
     * @return ResultInterface|ResponseInterface
     */
    public function execute()
    {
        $orderSession = $this->checkoutSession->getLastRealOrder();
        $orderId = $orderSession->getId();
        
        $resultRedirect = $this->resultRedirectFactory->create();

        // Guard against null/empty order IDs to prevent TypeErrors
        if (!$orderId) {
            $this->logger->error('[Create Checkout] Execution halted: No active order ID found in session.');
            $resultRedirect->setPath('checkout/cart');
            return $resultRedirect;
        }
        
        // Service Contract adjustment: Use the repository to load the concrete order entity
        try {
            $order = $this->orderRepository->get($orderId);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $this->logger->error(sprintf('[Create Checkout] Order entity not found for ID %s', (string)$orderId));
            $this->checkoutSession->restoreQuote();
            $this->messageManager->addErrorMessage(__('Something went wrong with the payment'));
            $resultRedirect->setPath('checkout/cart');
            return $resultRedirect;
        }

        try {
            $response = $this->client->createCheckout($order);
            $checkout = json_decode($response, true);

            $this->logger->debug('[Create Checkout][Response]' . $response);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($checkout) || empty($checkout['redirectUrl'])) {
                $this->logger->error('[Create Checkout] Invalid API response or missing redirectUrl from PayMaya.');
                
                $this->checkoutSession->restoreQuote();
                $this->messageManager->addErrorMessage(__('Invalid response from payment gateway. Please try again.'));
                
                $resultRedirect->setPath('checkout/cart');
                return $resultRedirect;
            }

            $resultRedirect->setUrl($checkout["redirectUrl"]);
            return $resultRedirect;
            
        } catch (ClientException $e) {
            $this->logger->error('[Create Checkout] ' . $e->getResponse()->getBody()->__toString());

            $this->checkoutSession->restoreQuote();
            $this->messageManager->addErrorMessage(__('Something went wrong with the payment'));
            
            $resultRedirect->setPath('checkout/cart');
            return $resultRedirect;
        }
    }
}
