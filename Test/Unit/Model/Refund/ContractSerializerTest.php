<?php

namespace Emipro\Apichange\Test\Unit\Model\Refund;

use Emipro\Apichange\Model\Refund\Canonicalizer;
use Emipro\Apichange\Model\Refund\Contract;
use Emipro\Apichange\Model\Refund\ContractSerializer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use PHPUnit\Framework\TestCase;

class ContractSerializerTest extends TestCase
{
    /**
     * @var ContractSerializer
     */
    private $serializer;

    protected function setUp(): void
    {
        $this->serializer = new ContractSerializer(new Canonicalizer());
    }

    /**
     * @param int $orderItemId
     * @param array $data
     * @return CreditmemoItem|\PHPUnit\Framework\MockObject\MockObject
     */
    private function item($orderItemId, array $data = [])
    {
        $item = $this->createMock(CreditmemoItem::class);
        $item->method('getOrderItemId')->willReturn($orderItemId);
        $item->method('getSku')->willReturn('SKU-' . $orderItemId);
        $item->method('getName')->willReturn('Product ' . $orderItemId);
        $item->method('getQty')->willReturn(isset($data['qty']) ? $data['qty'] : 1);
        $item->method('getData')->willReturnCallback(function ($key) use ($data) {
            return array_key_exists($key, $data) ? $data[$key] : null;
        });

        return $item;
    }

    /**
     * @param array $items
     * @param array $totals
     * @return Creditmemo|\PHPUnit\Framework\MockObject\MockObject
     */
    private function creditmemo(array $items, array $totals = [])
    {
        $creditmemo = $this->createMock(Creditmemo::class);
        $creditmemo->method('getAllItems')->willReturn($items);
        $creditmemo->method('getData')->willReturnCallback(function ($key) use ($totals) {
            return array_key_exists($key, $totals) ? $totals[$key] : null;
        });

        return $creditmemo;
    }

    /**
     * @return Order|\PHPUnit\Framework\MockObject\MockObject
     */
    private function order()
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(12);
        $order->method('getIncrementId')->willReturn('000000012');
        $order->method('getState')->willReturn('processing');
        $order->method('getStatus')->willReturn('processing');
        $order->method('getStoreId')->willReturn(1);
        $order->method('getBaseCurrencyCode')->willReturn('EUR');
        $order->method('getOrderCurrencyCode')->willReturn('USD');
        $order->method('getGlobalCurrencyCode')->willReturn('EUR');
        $order->method('getBaseToOrderRate')->willReturn(1.1);
        $order->method('getBaseTotalPaid')->willReturn(100.0);
        $order->method('getBaseTotalRefunded')->willReturn(30.0);
        $order->method('canCreditmemo')->willReturn(true);
        $order->method('getPayment')->willReturn(null);

        return $order;
    }

    public function testAmountsAreEmittedAsFixedScaleStringsNeverAsFloats()
    {
        $contract = $this->serializer->serialize(
            $this->creditmemo([], ['grand_total' => 19.989999999, 'base_grand_total' => 18.17]),
            $this->order(),
            null,
            Contract::SOURCE_ORDER,
            false
        );

        $this->assertSame('19.9900', $contract['totals']['grand_total']);
        $this->assertSame('18.1700', $contract['totals']['base_grand_total']);
        $this->assertIsString($contract['totals']['grand_total']);
    }

    /**
     * A column Magento left NULL must stay null, not become "0.0000": the
     * difference matters when Odoo reconciles.
     */
    public function testUnsetTotalsStayNull()
    {
        $contract = $this->serializer->serialize(
            $this->creditmemo([], ['grand_total' => 10]),
            $this->order(),
            null,
            Contract::SOURCE_ORDER,
            false
        );

        $this->assertNull($contract['totals']['base_adjustment_negative']);
        $this->assertSame('10.0000', $contract['totals']['grand_total']);
    }

    public function testEveryDocumentedTotalColumnIsPresent()
    {
        $contract = $this->serializer->serialize(
            $this->creditmemo([]),
            $this->order(),
            null,
            Contract::SOURCE_ORDER,
            false
        );

        foreach (['subtotal', 'base_subtotal', 'tax_amount', 'base_tax_amount', 'shipping_amount',
                     'base_shipping_amount', 'discount_amount', 'base_discount_amount',
                     'base_shipping_discount_tax_compensation_amnt', 'adjustment_positive',
                     'adjustment_negative', 'grand_total', 'base_grand_total', 'total_qty'] as $key) {
            $this->assertArrayHasKey($key, $contract['totals'], $key . ' is missing from the contract');
        }
    }

    /**
     * The credit memo item order is an implementation detail of the collectors;
     * the contract must be stable so that Odoo can diff two previews.
     */
    public function testItemsAreSortedByOrderItemId()
    {
        $contract = $this->serializer->serialize(
            $this->creditmemo([$this->item(9), $this->item(2), $this->item(5)]),
            $this->order(),
            null,
            Contract::SOURCE_ORDER,
            false
        );

        $this->assertSame([2, 5, 9], array_column($contract['items'], 'order_item_id'));
    }

    public function testZeroQuantityFactoryItemsAreExcluded()
    {
        $contract = $this->serializer->serialize(
            $this->creditmemo([$this->item(9, ['qty' => 0]), $this->item(2, ['qty' => 18])]),
            $this->order(),
            null,
            Contract::SOURCE_INVOICE,
            true
        );

        $this->assertSame([2], array_column($contract['items'], 'order_item_id'));
        $this->assertSame('18.0000', $contract['items'][0]['qty']);
    }

    public function testItemAmountsAreCanonicalised()
    {
        $contract = $this->serializer->serialize(
            $this->creditmemo([
                $this->item(3, [
                    'qty' => 2,
                    'row_total' => 33.333333,
                    'base_row_total' => 30.0,
                    'base_tax_amount' => 6.0,
                ]),
            ]),
            $this->order(),
            null,
            Contract::SOURCE_ORDER,
            false
        );

        $item = $contract['items'][0];
        $this->assertSame('2.0000', $item['qty']);
        $this->assertSame('33.3333', $item['row_total']);
        $this->assertSame('30.0000', $item['base_row_total']);
        $this->assertSame('6.0000', $item['base_tax_amount']);
        $this->assertNull($item['discount_amount']);
        $this->assertSame('SKU-3', $item['sku']);
    }

    public function testBothCurrenciesAreReported()
    {
        $contract = $this->serializer->serialize(
            $this->creditmemo([]),
            $this->order(),
            null,
            Contract::SOURCE_ORDER,
            false
        );

        $this->assertSame('EUR', $contract['currency']['base_currency_code']);
        $this->assertSame('USD', $contract['currency']['order_currency_code']);
        $this->assertSame('1.1000', $contract['currency']['base_to_order_rate']);
        $this->assertSame(Contract::SCALE, $contract['currency']['scale']);
    }

    public function testRefundHeadRoomNeverGoesNegative()
    {
        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(12);
        $order->method('getIncrementId')->willReturn('000000012');
        $order->method('getBaseTotalPaid')->willReturn(10.0);
        // Over-refunded orders exist in the wild after manual adjustments.
        $order->method('getBaseTotalRefunded')->willReturn(25.0);
        $order->method('canCreditmemo')->willReturn(false);
        $order->method('getPayment')->willReturn(null);

        $contract = $this->serializer->serialize(
            $this->creditmemo([]),
            $order,
            null,
            Contract::SOURCE_ORDER,
            false
        );

        $this->assertSame('0.0000', $contract['refundable']['base_max_refundable_online']);
        $this->assertFalse($contract['refundable']['order_can_creditmemo']);
        $this->assertNull($contract['refundable']['invoice_can_refund']);
    }
}
