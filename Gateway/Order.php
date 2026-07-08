<?php

namespace PayMaya\Payment\Gateway;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order as MagentoOrder;

/**
 * Class Order
 * Handles order and transaction updates for PayMaya gateway.
 */
class Order
{
    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $orderFactory;

    /**
     * @var \PayMaya\Payment\Model\Order\Email\Sender\OrderSender
     */
    protected $orderSender;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var \Magento\Sales\Api\OrderPaymentRepositoryInterface
     */
    protected $paymentRepository;

    /**
     * Order constructor.
     *
     * @param \PayMaya\Payment\Model\Order\Email\Sender\OrderSender $orderSender
     * @param \Magento\Sales\Model\OrderFactory $orderFactory
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Sales\Api\OrderPaymentRepositoryInterface $paymentRepository
     */
    public function __construct(
        \PayMaya\Payment\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Api\OrderPaymentRepositoryInterface $paymentRepository
    ) {
        $this->orderSender = $orderSender;
        $this->orderFactory = $orderFactory;
        $this->orderRepository = $orderRepository;
        $this->paymentRepository = $paymentRepository;
    }

    /**
     * Set order as paid
     *
     * @param MagentoOrder $order
     * @return void
     */
    public function setAsPaid($order)
    {
        /** Set order state and status to processing, then save once */
        $order->setState(MagentoOrder::STATE_PROCESSING);
        $order->setStatus(MagentoOrder::STATE_PROCESSING);
        
        $this->orderRepository->save($order);

        /** Send order confirmation e-mail */
        $this->orderSender->sendMayaConfirmation($order);
    }

    /**
     * Set order as failed
     *
     * @param MagentoOrder $order
     * @param string|null $paymentId
     * @return void
     */
    public function setAsFailed($order, $paymentId)
    {
        $safePaymentId = $paymentId ?? 'Unknown';

        $order->setState(MagentoOrder::STATE_CANCELED);
        $order->setStatus(MagentoOrder::STATE_CANCELED);
        $order->addCommentToStatusHistory("Failed payment {$safePaymentId}", $order->getStatus(), true);

        $this->orderRepository->save($order);
    }

    /**
     * Create transaction records for the order with a Maya payment ID
     *
     * @param MagentoOrder $order
     * @param string $paymentId
     * @return void
     * @throws \InvalidArgumentException
     */
    public function createTransaction($order, $paymentId)
    {
        if (empty($paymentId)) {
            throw new \InvalidArgumentException('A valid Maya payment ID is required to create a transaction.');
        }

        /** Get associated payment model */
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $order->getPayment();

        /** Set the transaction ID using Maya ID */
        $payment->setTransactionId($paymentId);

        /**
         * Since there are no manual captures, set the last transaction ID to the
         * Maya Payment ID
         */
        $payment->setLastTransId($paymentId);

        /**
         * Don't settle transactions in case of manual refunds since refunds are not
         * yet available through the extension
         */
        $payment->setIsTransactionClosed(0);

        // Use the repository to safely persist the payment entity and avoid 500 errors
        $this->paymentRepository->save($payment);

        /** Add a transaction record */
        $transaction = $payment->addTransaction(Transaction::TYPE_ORDER, null, false);

        // Reordered so Order saves BEFORE the standalone transaction to prevent orphans
        $this->orderRepository->save($order);

        /** Save the transaction record */
        $transaction->save();
    }

    /**
     * Load order by increment ID
     *
     * @param  string $orderId
     * @param  integer $count
     * @return OrderInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function loadOrderByIncrementId($orderId, $count = 7)
    {
        $order = $this->orderFactory->create()->loadByIncrementId($orderId);

        if (empty($order->getId()) && $count >= 0) {
            // Webhooks Race Condition: Sometimes we may receive the webhook before Magento commits
            // the order to the database, so we give it a few seconds and try again.
            // Can happen when multiple subscriptions are purchased together.
            
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            sleep(4);
            return $this->loadOrderByIncrementId($orderId, $count - 1);
        }

        if (empty($order->getId())) {
            throw new \Magento\Framework\Exception\LocalizedException(
                __("Received webhook with Order #%1 but could not find the order in Magento; ignoring", $orderId)
            );
        }

        return $order;
    }
}
