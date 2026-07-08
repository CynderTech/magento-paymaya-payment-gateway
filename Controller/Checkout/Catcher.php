<?php

namespace PayMaya\Payment\Controller\Checkout;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * Class Catcher
 * Handles PayMaya payment redirects (Success, Fail, Cancel)
 */
class Catcher extends \Magento\Framework\App\Action\Action
{
    public const CATCH_TYPE_SUCCESS = 'success';
    public const CATCH_TYPE_FAIL = 'fail';
    public const CATCH_TYPE_CANCEL = 'cancel';

    /**
     * @var \Magento\Checkout\Helper\Data
     */
    protected $checkoutHelper;

    /**
     * @var \PayMaya\Payment\Api\PayMayaClient
     */
    protected $client;

    /**
     * @var \Magento\Framework\App\Request\Http
     */
    protected $request;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $orderFactory;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    protected $quoteRepository;

    /**
     * Catcher constructor.
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param \PayMaya\Payment\Api\PayMayaClient $client
     * @param \Magento\Framework\App\Request\Http $request
     * @param \Magento\Checkout\Helper\Data $checkoutHelper
     * @param \Magento\Sales\Model\OrderFactory $orderFactory
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \PayMaya\Payment\Api\PayMayaClient $client,
        \Magento\Framework\App\Request\Http $request,
        \Magento\Checkout\Helper\Data $checkoutHelper,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
    ) {
        parent::__construct($context);

        $this->checkoutHelper = $checkoutHelper;
        $this->client = $client;
        $this->request = $request;
        $this->orderFactory = $orderFactory;
        $this->logger = $logger;
        $this->quoteRepository = $quoteRepository;
    }

    /**
     * Execute action based on webhook/redirect type
     *
     * @return ResultInterface|ResponseInterface
     */
    public function execute()
    {
        $catchType = $this->request->getParam('type');
        
        $resultRedirect = $this->resultRedirectFactory->create();

        switch ($catchType) {
            case self::CATCH_TYPE_SUCCESS:
                $session = $this->checkoutHelper->getCheckout();
                $incrementId = $session->getLastRealOrderId();
                
                $order = $this->orderFactory->create()->loadByIncrementId($incrementId);

                if (!$order->getId()) {
                    return $this->backToCart('No order for processing found');
                }
                
                $quote = $this->checkoutHelper->getCheckout()->getQuote();
                
                $quote->setIsActive(false);
                $this->quoteRepository->save($quote);
                
                $resultRedirect->setPath('checkout/onepage/success');
                return $resultRedirect;

            case self::CATCH_TYPE_FAIL:
                return $this->backToCart('Something has gone wrong with your payment. Please contact merchant.');

            case self::CATCH_TYPE_CANCEL:
            default:
                return $this->backToCart();
        }
    }

    /**
     * Restore quote and redirect to cart
     *
     * @param string|null $errorMessage
     * @return \Magento\Framework\Controller\Result\Redirect
     */
    public function backToCart($errorMessage = null)
    {
        $this->checkoutHelper->getCheckout()->restoreQuote();

        if ($errorMessage) {
            $this->messageManager->addErrorMessage(__($errorMessage));
        }

        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('checkout/cart');
        
        return $resultRedirect;
    }
}
