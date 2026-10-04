<?php

namespace PayMaya\Payment\Gateway;

use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order as MagentoOrder;

class Order
{
    protected $logger;
    protected $orderCollectionFactory;
    protected $orderSender;

    public function __construct(
        \PayMaya\Payment\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \PayMaya\Payment\Logger\Logger $logger
    ) {
        $this->orderSender = $orderSender;
        $this->orderCollectionFactory = $orderCollectionFactory;
        $this->logger = $logger;
    }

    /**
     * Make a value taken from an unauthenticated request safe to log
     */
    public static function loggable($value)
    {
        return substr(preg_replace('/[^A-Za-z0-9._-]/', '?', (string) $value), 0, 64);
    }

    /**
     * Set order as paid
     */
    public function setAsPaid($order)
    {
        /** Set order state and status to processing */
        $order->setState(MagentoOrder::STATE_PROCESSING, true)->save();
        $order->setStatus(MagentoOrder::STATE_PROCESSING)->save();

        /** Send order confirmation e-mail */
        $this->orderSender->sendMayaConfirmation($order);
    }

    public function setAsFailed($order, $paymentId)
    {
        $order->setState(MagentoOrder::STATE_CANCELED, true)->save();
        $order->setStatus(MagentoOrder::STATE_CANCELED)->save();
        $order->addCommentToStatusHistory("Failed payment {$paymentId}", MagentoOrder::STATE_HOLDED, true)->save();
    }

    /**
     * Whether the order history already holds exactly this comment
     */
    public function hasComment($order, $comment)
    {
        foreach ($order->getStatusHistoryCollection() as $history) {
            if ($history->getComment() === $comment) {
                return true;
            }
        }

        return false;
    }

    /**
     * Leave a merchant-only note on the order. Only the history row is saved, not the order.
     */
    public function addComment($order, $comment)
    {
        $order->addCommentToStatusHistory($comment)->save();
    }

    /**
     * Create transaction records for the order with a Maya payment ID
     */
    public function createTransaction($order, $paymentId)
    {
        /** Get associated payment model */
        $payment = $order->getPayment();

        /** Set the transaction ID using Maya ID */
        $payment->setTransactionId($paymentId);

        /**
         * Since there is no manual captures, set the last transaction ID to the
         * Paymongo Payment ID
         */
        $payment->setLastTransId($paymentId);

        /**
         * Don't settle transactions in case of manual refunds since refunds are not
         * yet available through the extension
         */
        $payment->setIsTransactionClosed(0);

        /** Save the payment changes above */
        $payment->save();

        /** Add a transaction record */
        $transaction = $payment->addTransaction(Transaction::TYPE_ORDER, null, false);

        /** Save the transaction record */
        $transaction->save();
    }

    /**
     * Load a fresh order by increment ID. There is deliberately no retry/sleep: the order is
     * committed before the customer is sent to Maya, and unknown IDs can be sent by anyone.
     * A plain collection keeps this to one query, as anyone can name an existing increment ID.
     *
     * Increment IDs are only unique per store, and Maya sends nothing but this ID. If several
     * orders share it, the one whose stored checkout IDs contain $paymentId is the right one.
     * When that is not exactly one order (for example both predate the stored checkout IDs) the
     * webhook is ignored rather than guessed, and that is logged as critical: Maya is answered
     * with 200 and will not retry, so such a payment has to be reconciled by hand.
     *
     * @param  string $orderId
     * @param  string|null $paymentId
     * @return MagentoOrder|null
     */
    public function loadOrderByIncrementId($orderId, $paymentId = null)
    {
        $orders = array_values($this->orderCollectionFactory->create()
            ->addFieldToFilter('increment_id', $orderId)
            ->getItems());

        if (count($orders) <= 1) {
            return $orders ? $orders[0] : null;
        }

        $matches = array_values(array_filter($orders, function ($order) use ($paymentId) {
            $payment = $order->getPayment();

            return $paymentId !== null && $payment && in_array($paymentId, PaymentVerifier::checkoutIds($payment), true);
        }));

        if (count($matches) !== 1) {
            $this->logger->critical(sprintf(
                '[Load Order] %d orders share increment ID %s and %d of them own payment %s; ignoring the webhook, reconcile by hand.',
                count($orders),
                self::loggable($orderId),
                count($matches),
                self::loggable($paymentId)
            ));

            return null;
        }

        return $matches[0];
    }
}
