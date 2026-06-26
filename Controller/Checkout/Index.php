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
        
        // Service Contract adjustment: Use the repository to load the concrete order entity
        try {
            $order = $this->orderRepository->get($orderSession->getId());
        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            $resultRedirect = $this->resultRedirectFactory->create();
            $resultRedirect->setPath('checkout/cart');
            return $resultRedirect;
        }

        $resultRedirect = $this->resultRedirectFactory->create();

        try {
            $response = $this->client->createCheckout($order);
            $checkout = json_decode($response, true);

            $this->logger->debug('[Create Checkout][Response]' . $response);

            $resultRedirect->setUrl($checkout["redirectUrl"]);
            return $resultRedirect;
            
        } catch (ClientException $e) {
            $this->logger->error('[Create Checkout]' . $e->getResponse()->getBody()->__toString());

            $this->checkoutSession->restoreQuote();
            $this->messageManager->addErrorMessage('Something went wrong with the payment');
            
            $resultRedirect->setPath('checkout/cart');
            return $resultRedirect;
        }
    }
}
