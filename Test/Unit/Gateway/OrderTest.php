<?php

namespace PayMaya\Payment\Test\Unit\Gateway;

use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order as MagentoOrder;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Status\History;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Status\History\Collection as HistoryCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PayMaya\Payment\Gateway\Order;
use PayMaya\Payment\Logger\Logger;
use PayMaya\Payment\Model\Order\Email\Sender\OrderSender;

class OrderTest extends TestCase
{
    private $collection;
    private $logger;
    private $orderRepository;
    private $paymentRepository;
    private $sender;

    private function helper(array $found = [])
    {
        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('getItems')->willReturn($found);

        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($this->collection);

        $this->logger = $this->createMock(Logger::class);
        $this->sender = $this->createMock(OrderSender::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->paymentRepository = $this->createMock(OrderPaymentRepositoryInterface::class);

        return new Order($this->sender, $factory, $this->logger, $this->orderRepository, $this->paymentRepository);
    }

    private function orderWithCheckouts($checkoutIds)
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn($checkoutIds);

        $order = $this->createStub(MagentoOrder::class);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    private function orderWithHistory(array $comments)
    {
        $histories = array_map(function ($comment) {
            $history = $this->createStub(History::class);
            $history->method('getComment')->willReturn($comment);
            return $history;
        }, $comments);

        $collection = $this->createStub(HistoryCollection::class);
        $collection->method('getIterator')->willReturn(new \ArrayIterator($histories));

        $order = $this->createMock(MagentoOrder::class);
        $order->method('getStatusHistoryCollection')->willReturn($collection);

        return $order;
    }

    public function testHasCommentFindsAnExactMatchOnly()
    {
        $order = $this->orderWithHistory(['Failed payment abc', 'Maya confirmed payment xyz']);

        $this->assertTrue($this->helper()->hasComment($order, 'Maya confirmed payment xyz'));
        $this->assertFalse($this->helper()->hasComment($order, 'Maya confirmed payment'));
        $this->assertFalse($this->helper()->hasComment($this->orderWithHistory([]), 'anything'));
    }

    public function testAddCommentSavesTheOrderThroughTheRepository()
    {
        $helper = $this->helper();
        $order = $this->createMock(MagentoOrder::class);
        $order->expects($this->once())->method('addCommentToStatusHistory')->with('a note');
        $order->expects($this->never())->method('save');
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $helper->addComment($order, 'a note');
    }

    public function testSetAsPaidMovesTheOrderToProcessingSavesOnceAndSendsTheEmail()
    {
        $helper = $this->helper();
        $order = $this->createMock(MagentoOrder::class);
        $order->expects($this->once())->method('setState')->with(MagentoOrder::STATE_PROCESSING);
        $order->expects($this->once())->method('setStatus')->with(MagentoOrder::STATE_PROCESSING);
        $order->expects($this->never())->method('save');
        $this->orderRepository->expects($this->once())->method('save')->with($order);
        $this->sender->expects($this->once())->method('sendMayaConfirmation')->with($order);

        $helper->setAsPaid($order);
    }

    public function testSetAsFailedCancelsTheOrderWithAFailedPaymentComment()
    {
        $helper = $this->helper();
        $order = $this->createMock(MagentoOrder::class);
        $order->method('getStatus')->willReturn('canceled');
        $order->expects($this->once())->method('setState')->with(MagentoOrder::STATE_CANCELED);
        $order->expects($this->once())->method('setStatus')->with(MagentoOrder::STATE_CANCELED);
        $order->expects($this->once())->method('addCommentToStatusHistory')->with('Failed payment pay-1', 'canceled', true);
        $this->orderRepository->expects($this->once())->method('save')->with($order);

        $helper->setAsFailed($order, 'pay-1');
    }

    public function testSetAsFailedWithoutAPaymentIdSaysUnknown()
    {
        $helper = $this->helper();
        $order = $this->createMock(MagentoOrder::class);
        $order->expects($this->once())->method('addCommentToStatusHistory')->with('Failed payment Unknown');

        $helper->setAsFailed($order, null);
    }

    #[DataProvider('emptyPaymentIds')]
    public function testCreateTransactionRefusesAnEmptyPaymentIdAndSavesNothing($paymentId)
    {
        $helper = $this->helper();
        $order = $this->createMock(MagentoOrder::class);
        $order->expects($this->never())->method('getPayment');
        $this->paymentRepository->expects($this->never())->method('save');
        $this->orderRepository->expects($this->never())->method('save');

        $this->expectException(\InvalidArgumentException::class);
        $helper->createTransaction($order, $paymentId);
    }

    public static function emptyPaymentIds(): array
    {
        return ['empty string' => [''], 'null' => [null], 'zero' => [0]];
    }

    public function testCreateTransactionSavesThePaymentThenTheOrderThenTheTransaction()
    {
        $helper = $this->helper();
        $calls = [];

        $transaction = $this->createMock(Transaction::class);
        $transaction->method('save')->willReturnCallback(function () use (&$calls, $transaction) {
            $calls[] = 'transaction';
            return $transaction;
        });

        $payment = $this->createMock(Payment::class);
        $payment->expects($this->once())->method('setTransactionId')->with('pay-1');
        $payment->method('addTransaction')->willReturnCallback(function () use (&$calls, $transaction) {
            $calls[] = 'addTransaction';
            return $transaction;
        });

        $order = $this->createMock(MagentoOrder::class);
        $order->method('getPayment')->willReturn($payment);

        $this->paymentRepository->method('save')->willReturnCallback(function () use (&$calls, $payment) {
            $calls[] = 'payment';
            return $payment;
        });
        $this->orderRepository->method('save')->willReturnCallback(function () use (&$calls, $order) {
            $calls[] = 'order';
            return $order;
        });

        $helper->createTransaction($order, 'pay-1');

        // The order is saved before the standalone transaction so that no orphan transaction is left.
        $this->assertSame(['payment', 'addTransaction', 'order', 'transaction'], $calls);
    }

    public function testSearchesByIncrementId()
    {
        $helper = $this->helper([]);
        $this->collection->expects($this->once())->method('addFieldToFilter')->with('increment_id', '000000102')->willReturnSelf();

        $this->assertNull($helper->loadOrderByIncrementId('000000102'));
    }

    public function testReturnsTheOnlyOrderWithThatIncrementId()
    {
        $order = $this->orderWithCheckouts(null);

        $this->assertSame($order, $this->helper([$order])->loadOrderByIncrementId('000000102'));
        $this->assertSame($order, $this->helper(['k' => $order])->loadOrderByIncrementId('000000102', 'pay-1'));
    }

    public function testTellsOrdersOfDifferentStoresApartByTheirCheckoutIds()
    {
        $storeA = $this->orderWithCheckouts(['checkout-a']);
        $storeB = $this->orderWithCheckouts(['checkout-b', 'checkout-b2']);
        $helper = $this->helper([$storeA, $storeB]);
        $this->logger->expects($this->never())->method('critical');

        $this->assertSame($storeB, $helper->loadOrderByIncrementId('000000001', 'checkout-b2'));
        $this->assertSame($storeA, $helper->loadOrderByIncrementId('000000001', 'checkout-a'));
    }

    public function testReturnsNoOrderAndLogsCriticalWhenACollisionCannotBeResolved()
    {
        $helper = $this->helper([$this->orderWithCheckouts(['checkout-a']), $this->orderWithCheckouts(['checkout-b'])]);
        $this->logger->expects($this->exactly(2))->method('critical')->with($this->stringContains('orders share increment ID 000000001'));

        $this->assertNull($helper->loadOrderByIncrementId('000000001'), 'no payment id to tell them apart');
        $this->assertNull($helper->loadOrderByIncrementId('000000001', 'unknown'), 'no order owns this payment');
    }

    public function testCollisionsWithAmbiguousOrUnboundOrdersAreNotGuessed()
    {
        $twice = $this->helper([$this->orderWithCheckouts(['same']), $this->orderWithCheckouts(['same'])]);
        $this->assertNull($twice->loadOrderByIncrementId('000000001', 'same'), 'two orders claim it');

        $unbound = $this->helper([$this->orderWithCheckouts(null), $this->orderWithCheckouts([])]);
        $this->assertNull($unbound->loadOrderByIncrementId('000000001', 'pay-1'), 'orders without stored checkouts');

        $noPayment = $this->createStub(MagentoOrder::class);
        $noPayment->method('getPayment')->willReturn(null);
        $withoutPayment = $this->helper([$noPayment, $this->orderWithCheckouts(['pay-1'])]);
        $this->assertNotNull($withoutPayment->loadOrderByIncrementId('000000001', 'pay-1'), 'the order that owns it is still found');
    }

    public function testLoggableMakesRequestValuesLogSafe()
    {
        $this->assertSame('1??2026??FAKE?LINE', Order::loggable("1\n[2026] FAKE LINE"));
        $this->assertSame(64, strlen(Order::loggable(str_repeat('a', 500))));
        $this->assertSame('', Order::loggable(null));
    }

    private function orderForCheckout($id, $method, $state)
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getMethod')->willReturn($method);

        $order = $this->createStub(MagentoOrder::class);
        $order->method('getId')->willReturn($id);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn($state);

        return $order;
    }

    #[DataProvider('statesAwaitingPayment')]
    public function testACheckoutMayBeCreatedForAMayaOrderAwaitingPayment($state)
    {
        $this->assertTrue(Order::awaitsMayaPayment($this->orderForCheckout(5, 'paymaya_payment', $state)));
    }

    public static function statesAwaitingPayment(): array
    {
        return ['new' => [MagentoOrder::STATE_NEW], 'pending payment' => [MagentoOrder::STATE_PENDING_PAYMENT]];
    }

    #[DataProvider('statesNotAwaitingPayment')]
    public function testNoCheckoutIsCreatedForASettledOrCanceledOrder($state)
    {
        $this->assertFalse(Order::awaitsMayaPayment($this->orderForCheckout(5, 'paymaya_payment', $state)));
    }

    public static function statesNotAwaitingPayment(): array
    {
        return [
            'processing' => [MagentoOrder::STATE_PROCESSING],
            'complete' => [MagentoOrder::STATE_COMPLETE],
            'closed' => [MagentoOrder::STATE_CLOSED],
            'canceled' => [MagentoOrder::STATE_CANCELED],
            'holded' => [MagentoOrder::STATE_HOLDED],
            'payment review' => [MagentoOrder::STATE_PAYMENT_REVIEW],
            'unknown' => [null],
        ];
    }

    public function testNoCheckoutIsCreatedWithoutAMayaOrderInTheSession()
    {
        $this->assertFalse(Order::awaitsMayaPayment(null));
        $this->assertFalse(Order::awaitsMayaPayment($this->orderForCheckout(null, 'paymaya_payment', 'new')), 'order not found');
        $this->assertFalse(Order::awaitsMayaPayment($this->orderForCheckout(5, 'checkmo', 'new')), 'another payment method');

        $noPayment = $this->createStub(MagentoOrder::class);
        $noPayment->method('getId')->willReturn(5);
        $noPayment->method('getState')->willReturn('new');
        $noPayment->method('getPayment')->willReturn(null);
        $this->assertFalse(Order::awaitsMayaPayment($noPayment), 'order without a payment');
    }
}
