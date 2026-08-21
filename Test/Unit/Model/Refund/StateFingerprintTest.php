<?php

namespace Emipro\Apichange\Test\Unit\Model\Refund;

use Emipro\Apichange\Model\Refund\Canonicalizer;
use Emipro\Apichange\Model\Refund\StateFingerprint;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\Data\InvoiceInterface;
use PHPUnit\Framework\TestCase;

class StateFingerprintTest extends TestCase
{
    public function testInvoiceFingerprintUsesDomainItemsInsteadOfRestArray()
    {
        $item = new class {
            public function getOrderItemId() { return 3734400; }
            public function getQty() { return 20; }
            public function getBaseRowTotal() { return 145.83; }
        };

        $invoice = $this->getMockBuilder(InvoiceInterface::class)
            ->addMethods(['getAllItems'])
            ->getMock();
        $invoice->method('getItems')->willReturn([
            ['order_item_id' => 3734400, 'qty' => 20],
        ]);
        $invoice->method('getAllItems')->willReturn([$item]);

        $fingerprint = new StateFingerprint(
            new Canonicalizer(),
            $this->createMock(CreditmemoRepositoryInterface::class),
            $this->createMock(SearchCriteriaBuilder::class),
            $this->createMock(SortOrderBuilder::class)
        );

        $method = new \ReflectionMethod(StateFingerprint::class, 'describeInvoice');
        $method->setAccessible(true);
        $description = $method->invoke($fingerprint, $invoice);

        $this->assertSame('3734400', $description['items'][0]['order_item_id']);
        $this->assertSame('20.0000', $description['items'][0]['qty']);
        $this->assertSame('145.8300', $description['items'][0]['base_row_total']);
    }
}

