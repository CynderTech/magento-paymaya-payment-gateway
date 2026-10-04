<?php

namespace PayMaya\Payment\Test\Unit\Gateway;

use Magento\Sales\Model\Order as MagentoOrder;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Status\History;
use Magento\Sales\Model\ResourceModel\Order\Collection;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Status\History\Collection as HistoryCollection;
use PHPUnit\Framework\TestCase;
use PayMaya\Payment\Gateway\Order;
use PayMaya\Payment\Logger\Logger;
use PayMaya\Payment\Model\Order\Email\Sender\OrderSender;

class OrderTest extends TestCase
{
    private $collection;
    private $logger;

    private function helper(array $found = [])
    {
        $this->collection = $this->createMock(Collection::class);
        $this->collection->method('addFieldToFilter')->willReturnSelf();
        $this->collection->method('getItems')->willReturn($found);

        $factory = $this->createStub(CollectionFactory::class);
        $factory->method('create')->willReturn($this->collection);

        $this->logger = $this->createMock(Logger::class);

        return new Order($this->createStub(OrderSender::class), $factory, $this->logger);
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

    public function testAddCommentSavesOnlyTheHistoryRow()
    {
        $history = $this->createMock(History::class);
        $history->expects($this->once())->method('save');

        $order = $this->createMock(MagentoOrder::class);
        $order->expects($this->once())->method('addCommentToStatusHistory')->with('a note')->willReturn($history);
        $order->expects($this->never())->method('save');

        $this->helper()->addComment($order, 'a note');
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
}
