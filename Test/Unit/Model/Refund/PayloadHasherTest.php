<?php

namespace Emipro\Apichange\Test\Unit\Model\Refund;

use Emipro\Apichange\Model\Refund\Canonicalizer;
use Emipro\Apichange\Model\Refund\Contract;
use Emipro\Apichange\Model\Refund\Data\RefundItem;
use Emipro\Apichange\Model\Refund\Data\RefundRequest;
use Emipro\Apichange\Model\Refund\PayloadHasher;
use PHPUnit\Framework\TestCase;

class PayloadHasherTest extends TestCase
{
    /**
     * @var PayloadHasher
     */
    private $hasher;

    protected function setUp(): void
    {
        $this->hasher = new PayloadHasher(new Canonicalizer());
    }

    /**
     * @param array $data
     * @return RefundRequest
     */
    private function request(array $data = [])
    {
        $items = [];
        foreach (isset($data['items']) ? $data['items'] : [] as $item) {
            $refundItem = new RefundItem();
            $refundItem->setOrderItemId($item[0]);
            $refundItem->setQty($item[1]);
            $items[] = $refundItem;
        }
        $data['items'] = $items;

        return new RefundRequest($data);
    }

    public function testItemOrderDoesNotChangeTheHash()
    {
        $left = $this->request(['order_id' => 7, 'items' => [[1, 2], [2, 3]]]);
        $right = $this->request(['order_id' => 7, 'items' => [[2, 3], [1, 2]]]);

        $this->assertSame(
            $this->hasher->hash($left, Contract::SOURCE_ORDER),
            $this->hasher->hash($right, Contract::SOURCE_ORDER)
        );
    }

    public function testDuplicateLinesAreSummedLikeMagentoQtysMap()
    {
        $split = $this->request(['order_id' => 7, 'items' => [[1, 1], [1, 1]]]);
        $merged = $this->request(['order_id' => 7, 'items' => [[1, 2]]]);

        $this->assertSame(
            $this->hasher->hash($split, Contract::SOURCE_ORDER),
            $this->hasher->hash($merged, Contract::SOURCE_ORDER)
        );
    }

    public function testQuantityChangeChangesTheHash()
    {
        $one = $this->request(['order_id' => 7, 'items' => [[1, 1]]]);
        $two = $this->request(['order_id' => 7, 'items' => [[1, 2]]]);

        $this->assertNotSame(
            $this->hasher->hash($one, Contract::SOURCE_ORDER),
            $this->hasher->hash($two, Contract::SOURCE_ORDER)
        );
    }

    public function testSourceIsPartOfTheHash()
    {
        $request = $this->request(['order_id' => 7, 'invoice_id' => 9, 'items' => [[1, 1]]]);

        $this->assertNotSame(
            $this->hasher->hash($request, Contract::SOURCE_ORDER),
            $this->hasher->hash($request, Contract::SOURCE_INVOICE)
        );
    }

    public function testOnlineFlagIsPartOfTheHash()
    {
        $offline = $this->request(['order_id' => 7, 'invoice_id' => 9, 'is_online' => false]);
        $online = $this->request(['order_id' => 7, 'invoice_id' => 9, 'is_online' => true]);

        $this->assertNotSame(
            $this->hasher->hash($offline, Contract::SOURCE_INVOICE),
            $this->hasher->hash($online, Contract::SOURCE_INVOICE)
        );
    }

    /**
     * "let Magento decide" and "refund exactly zero shipping" are different
     * instructions and must never share a hash.
     */
    public function testAbsentShippingDiffersFromZeroShipping()
    {
        $absent = $this->request(['order_id' => 7]);
        $zero = $this->request(['order_id' => 7, 'shipping_amount' => 0]);

        $this->assertNotSame(
            $this->hasher->hash($absent, Contract::SOURCE_ORDER),
            $this->hasher->hash($zero, Contract::SOURCE_ORDER)
        );
    }

    public function testAdjustmentsAreCoveredByTheHash()
    {
        $base = $this->request(['order_id' => 7]);
        $withFee = $this->request(['order_id' => 7, 'adjustment_negative' => 5]);

        $this->assertNotSame(
            $this->hasher->hash($base, Contract::SOURCE_ORDER),
            $this->hasher->hash($withFee, Contract::SOURCE_ORDER)
        );
    }

    /**
     * The three fields that carry no monetary meaning must be replayable
     * freely: an integration retrying with a fresh idempotency key or a
     * different comment must not be forced through a new preview.
     */
    public function testCommentTokenAndIdempotencyKeyAreOutsideTheHash()
    {
        $bare = $this->request(['order_id' => 7, 'items' => [[1, 1]]]);
        $decorated = $this->request([
            'order_id' => 7,
            'items' => [[1, 1]],
            'comment' => 'refund from Odoo SO0042',
            'idempotency_key' => 'odoo-42',
            'preview_token' => 'v1.aaa.bbb',
        ]);

        $this->assertSame(
            $this->hasher->hash($bare, Contract::SOURCE_ORDER),
            $this->hasher->hash($decorated, Contract::SOURCE_ORDER)
        );
    }

    public function testDifferentOrdersNeverShareAHash()
    {
        $this->assertNotSame(
            $this->hasher->hash($this->request(['order_id' => 7]), Contract::SOURCE_ORDER),
            $this->hasher->hash($this->request(['order_id' => 8]), Contract::SOURCE_ORDER)
        );
    }

    /**
     * Odoo sends JSON; a qty may arrive as the string "2" or the number 2.
     */
    public function testStringAndNumericQuantitiesAgree()
    {
        $asString = $this->request(['order_id' => 7, 'items' => [[1, '2']]]);
        $asNumber = $this->request(['order_id' => 7, 'items' => [[1, 2]]]);

        $this->assertSame(
            $this->hasher->hash($asString, Contract::SOURCE_ORDER),
            $this->hasher->hash($asNumber, Contract::SOURCE_ORDER)
        );
    }
}
