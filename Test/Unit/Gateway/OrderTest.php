<?php

namespace PayMaya\Payment\Test\Unit\Gateway;

use Magento\Sales\Model\Order as MagentoOrder;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\Status\History;
use Magento\Sales\Model\ResourceModel\Order\Status\History\Collection;
use PHPUnit\Framework\TestCase;
use PayMaya\Payment\Gateway\Order;
use PayMaya\Payment\Model\Order\Email\Sender\OrderSender;

class OrderTest extends TestCase
{
    private function helper()
    {
        return new Order($this->createStub(OrderSender::class), $this->createStub(OrderFactory::class));
    }

    private function orderWithHistory(array $comments)
    {
        $histories = array_map(function ($comment) {
            $history = $this->createStub(History::class);
            $history->method('getComment')->willReturn($comment);
            return $history;
        }, $comments);

        $collection = $this->createStub(Collection::class);
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
}
