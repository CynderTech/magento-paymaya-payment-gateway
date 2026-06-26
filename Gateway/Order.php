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
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $order;

    /**
     * @var \PayMaya\Payment\Model\Order\Email\Sender\OrderSender
     */
    protected $orderSender;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * Order constructor.
     *
     * @param \PayMaya\Payment\Model\Order\Email\Sender\OrderSender $orderSender
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     */
    public function __construct(
        \PayMaya\Payment\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
    ) {
        $this->orderSender = $orderSender;
        $this->order = $order;
        $this->orderRepository = $orderRepository;
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
        
        // Service Contract Fix: Use repository instead of direct save()
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
        $order->addCommentToStatusHistory("Failed payment {$safePaymentId}", MagentoOrder::STATE_HOLDED, true);
        
        // Service Contract Fix: Use repository instead of direct save()
        $this->orderRepository->save($order);
    }

    /**
     * Create transaction records for the order with a Maya payment ID
     *
     * @param MagentoOrder $order
     * @param string|null $paymentId
     * @return void
     */
    public function createTransaction($order, $paymentId)
    {
        $safePaymentId = $paymentId ?? '';

        /** Get associated payment model */
        /** @var \Magento\Sales\Model\Order\Payment $payment */
        $payment = $order->getPayment();

        /** Set the transaction ID using Maya ID */
        $payment->setTransactionId($safePaymentId);

        /**
         * Since there are no manual captures, set the last transaction ID to the
         * Paymongo Payment ID
         */
        $payment->setLastTransId($safePaymentId);

        /**
         * Don't settle transactions in case of manual refunds since refunds are not
         * yet available through the extension
         */
        $payment->setIsTransactionClosed(0);

        /** Add a transaction record */
        $transaction = $payment->addTransaction(Transaction::TYPE_ORDER, null, false);

        /** Save the transaction record */
        $transaction->save();
        
        // Service Contract Fix: Saving the parent Order entity via its repository 
        // automatically handles cascading updates cleanly down to its associated elements.
        $this->orderRepository->save($order);
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
        /** @var \Magento\Sales\Model\Order $orderModel */
        $orderModel = $this->order;
        $order = $orderModel->loadByIncrementId($orderId);

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
