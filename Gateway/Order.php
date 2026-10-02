<?php

namespace PayMaya\Payment\Gateway;

use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order as MagentoOrder;

class Order
{
    protected $orderFactory;
    protected $orderSender;

    public function __construct(
        \PayMaya\Payment\Model\Order\Email\Sender\OrderSender $orderSender,
        \Magento\Sales\Model\OrderFactory $orderFactory
    ) {
        $this->orderSender = $orderSender;
        $this->orderFactory = $orderFactory;
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
     *
     * @param  string $orderId
     * @return MagentoOrder|null
     */
    public function loadOrderByIncrementId($orderId)
    {
        $order = $this->orderFactory->create()->loadByIncrementId($orderId);

        return $order && $order->getId() ? $order : null;
    }
}
