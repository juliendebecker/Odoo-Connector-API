<?php

namespace Emipro\Apichange\Test\Unit\Model;

use Emipro\Apichange\Api\Data\RefundRequestInterface;
use Emipro\Apichange\Model\RefundCommit;
use Emipro\Apichange\Model\RefundJsonAdapter;
use Emipro\Apichange\Model\RefundPreview;
use Emipro\Apichange\Model\RefundPreviewJsonAdapter;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

class RefundJsonAdapterTest extends TestCase
{
    public function testPreviewKeepsAssociativeContractKeys()
    {
        $request = $this->createMock(RefundRequestInterface::class);
        $preview = $this->createMock(RefundPreview::class);
        $preview->method('previewInvoice')->with($request)->willReturn([
            'contract_version' => '1.0.0',
            'totals' => ['grand_total' => '118.1300'],
        ]);

        $adapter = new RefundPreviewJsonAdapter($preview, new Json());
        $result = json_decode($adapter->previewInvoice($request), true);

        $this->assertSame('1.0.0', $result['contract_version']);
        $this->assertSame('118.1300', $result['totals']['grand_total']);
    }

    public function testCommitKeepsCreditmemoIdentifiers()
    {
        $request = $this->createMock(RefundRequestInterface::class);
        $refund = $this->createMock(RefundCommit::class);
        $refund->method('execute')->with($request)->willReturn([
            'status' => 'refunded',
            'creditmemo_id' => 42,
            'creditmemo_increment_id' => '100000042',
        ]);

        $adapter = new RefundJsonAdapter($refund, new Json());
        $result = json_decode($adapter->execute($request), true);

        $this->assertSame('refunded', $result['status']);
        $this->assertSame(42, $result['creditmemo_id']);
        $this->assertSame('100000042', $result['creditmemo_increment_id']);
    }
}

