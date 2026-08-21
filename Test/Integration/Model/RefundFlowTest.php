<?php

namespace Emipro\Apichange\Test\Integration\Model;

use Emipro\Apichange\Api\Data\RefundRequestInterface;
use Emipro\Apichange\Api\RefundInterface;
use Emipro\Apichange\Api\RefundPreviewInterface;
use Emipro\Apichange\Model\Refund\Contract;
use Emipro\Apichange\Model\Refund\IdempotencyStore;
use Emipro\Apichange\Model\Refund\PersistedCreditmemoReader;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Sales\Api\CreditmemoRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * End to end behaviour of the preview -> guard -> refund flow.
 *
 * Run from a Magento installation:
 *   vendor/bin/phpunit -c dev/tests/integration/phpunit.xml.dist \
 *       app/code/Emipro/Apichange/Test/Integration
 *
 * The fixture creates an order plus a registered offline invoice, which is what
 * makes Order::canCreditmemo() true.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class RefundFlowTest extends TestCase
{
    /**
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @var RefundPreviewInterface
     */
    private $preview;

    /**
     * @var RefundInterface
     */
    private $refund;

    protected function setUp(): void
    {
        $this->objectManager = Bootstrap::getObjectManager();
        $this->preview = $this->objectManager->create(RefundPreviewInterface::class);
        $this->refund = $this->objectManager->create(RefundInterface::class);
    }

    /**
     * @param array $data
     * @return RefundRequestInterface
     */
    private function request(array $data)
    {
        /** @var RefundRequestInterface $request */
        $request = $this->objectManager->create(RefundRequestInterface::class);

        // Only the interface setters, so that this test exercises the same
        // surface the web API deserialiser uses.
        $setters = [
            'order_id' => 'setOrderId',
            'invoice_id' => 'setInvoiceId',
            'items' => 'setItems',
            'shipping_amount' => 'setShippingAmount',
            'adjustment_positive' => 'setAdjustmentPositive',
            'adjustment_negative' => 'setAdjustmentNegative',
            'is_online' => 'setIsOnline',
            'preview_token' => 'setPreviewToken',
            'idempotency_key' => 'setIdempotencyKey',
            'comment' => 'setComment',
        ];

        foreach ($data as $key => $value) {
            $request->{$setters[$key]}($value);
        }

        return $request;
    }

    /**
     * @return \Magento\Sales\Api\Data\OrderInterface
     */
    private function fixtureOrder()
    {
        $searchCriteria = $this->objectManager->create(SearchCriteriaBuilder::class)
            ->addFilter('increment_id', '100000001')
            ->create();

        $orders = $this->objectManager->create(OrderRepositoryInterface::class)
            ->getList($searchCriteria)
            ->getItems();

        return array_shift($orders);
    }

    /**
     * @param int $orderId
     * @return int
     */
    private function countCreditmemos($orderId)
    {
        $searchCriteria = $this->objectManager->create(SearchCriteriaBuilder::class)
            ->addFilter('order_id', $orderId)
            ->create();

        return $this->objectManager->create(CreditmemoRepositoryInterface::class)
            ->getList($searchCriteria)
            ->getTotalCount();
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testPreviewPersistsNothing()
    {
        $order = $this->fixtureOrder();
        $before = $this->countCreditmemos($order->getEntityId());

        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));

        $this->assertSame(Contract::CONTRACT_VERSION, $contract['contract_version']);
        $this->assertNotEmpty($contract['preview_token']);
        $this->assertStringStartsWith('sha256:', $contract['payload_hash']);
        $this->assertStringStartsWith('sha256:', $contract['state_fingerprint']);
        $this->assertFalse($contract['engine']['persisted']);
        $this->assertFalse($contract['engine']['registered']);
        $this->assertFalse($contract['engine']['payment_touched']);

        // The contract advertises "nothing was written"; verify it rather than
        // trusting the flag it emits about itself.
        $this->assertSame($before, $this->countCreditmemos($order->getEntityId()));

        $reloaded = $this->objectManager->create(OrderRepositoryInterface::class)->get($order->getEntityId());
        $this->assertEquals(
            (float) $order->getBaseTotalRefunded(),
            (float) $reloaded->getBaseTotalRefunded()
        );
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testPreviewIsRepeatableAndStable()
    {
        $order = $this->fixtureOrder();

        $first = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));
        $second = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));

        $this->assertSame($first['payload_hash'], $second['payload_hash']);
        $this->assertSame($first['state_fingerprint'], $second['state_fingerprint']);
        $this->assertSame($first['totals'], $second['totals']);
        // Tokens differ only by their issue timestamp.
        $this->assertNotSame('', $second['preview_token']);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testGuardedRefundCreatesExactlyOneCreditmemo()
    {
        $order = $this->fixtureOrder();
        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));

        $result = $this->refund->execute($this->request([
            'order_id' => $order->getEntityId(),
            'preview_token' => $contract['preview_token'],
            'idempotency_key' => 'it-refund-1',
        ]));

        $this->assertSame('refunded', $result['status']);
        $this->assertGreaterThan(0, $result['creditmemo_id']);
        $this->assertSame(1, $this->countCreditmemos($order->getEntityId()));
        $this->assertTrue($result['guard']['payload_verified']);
        $this->assertTrue($result['guard']['state_verified']);
        $this->assertTrue($result['guard']['total_verified_after_write']);
        $this->assertFalse($result['guard']['notification_sent']);

        $creditmemo = $this->objectManager->create(CreditmemoRepositoryInterface::class)
            ->get($result['creditmemo_id']);
        $this->assertSame(
            $contract['totals']['base_grand_total'],
            number_format((float) $creditmemo->getBaseGrandTotal(), Contract::SCALE, '.', '')
        );
    }

    /**
     * The second commit must be refused: the first one moved
     * base_total_refunded and added a credit memo, so the state fingerprint
     * recomputed under the lock no longer matches the signed one.
     *
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testReplayingATokenAfterTheStateMovedIsRefused()
    {
        $order = $this->fixtureOrder();
        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));

        $this->refund->execute($this->request([
            'order_id' => $order->getEntityId(),
            'preview_token' => $contract['preview_token'],
            'idempotency_key' => 'it-refund-2a',
        ]));

        try {
            $this->refund->execute($this->request([
                'order_id' => $order->getEntityId(),
                'preview_token' => $contract['preview_token'],
                'idempotency_key' => 'it-refund-2b',
            ]));
            $this->fail('Expected the guard to refuse a replayed preview token.');
        } catch (WebapiException $e) {
            $this->assertSame(Contract::HTTP_CONFLICT, $e->getHttpCode());
            $this->assertStringContainsString(Contract::ERR_STATE_MISMATCH, $e->getMessage());
        }

        $this->assertSame(1, $this->countCreditmemos($order->getEntityId()));
    }

    /**
     * Same key, same payload: the stored response is replayed and no second
     * credit memo is created.
     *
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testIdempotentReplayReturnsTheStoredResult()
    {
        $order = $this->fixtureOrder();
        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));

        $first = $this->refund->execute($this->request([
            'order_id' => $order->getEntityId(),
            'preview_token' => $contract['preview_token'],
            'idempotency_key' => 'it-refund-3',
        ]));

        $second = $this->refund->execute($this->request([
            'order_id' => $order->getEntityId(),
            'preview_token' => $contract['preview_token'],
            'idempotency_key' => 'it-refund-3',
        ]));

        $this->assertSame('replayed', $second['status']);
        $this->assertSame($first['creditmemo_id'], $second['creditmemo_id']);
        $this->assertSame(1, $this->countCreditmemos($order->getEntityId()));
    }

    /**
     * A payload that differs from the previewed one must be refused even though
     * the token itself is perfectly valid.
     *
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testMutatedPayloadIsRefused()
    {
        $order = $this->fixtureOrder();
        $items = [];
        foreach ($order->getAllItems() as $orderItem) {
            $items[] = ['order_item_id' => (int) $orderItem->getItemId(), 'qty' => 1];
        }

        $contract = $this->preview->previewOrder($this->request([
            'order_id' => $order->getEntityId(),
            'items' => $this->refundItems($items),
        ]));

        // Same token, one more unit requested.
        $items[0]['qty'] = 2;

        try {
            $this->refund->execute($this->request([
                'order_id' => $order->getEntityId(),
                'items' => $this->refundItems($items),
                'preview_token' => $contract['preview_token'],
            ]));
            $this->fail('Expected the guard to refuse a mutated payload.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_PAYLOAD_MISMATCH, $e->getMessage());
        }

        $this->assertSame(0, $this->countCreditmemos($order->getEntityId()));
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testCommitWithoutATokenIsRefused()
    {
        $order = $this->fixtureOrder();

        try {
            $this->refund->execute($this->request(['order_id' => $order->getEntityId()]));
            $this->fail('Expected the commit to require a preview token.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_PREVIEW_TOKEN_MISSING, $e->getMessage());
        }

        $this->assertSame(0, $this->countCreditmemos($order->getEntityId()));
    }

    // ---------------------------------------------------------------------
    // Replay of a completed refund once the preview token is gone.
    // ---------------------------------------------------------------------

    /**
     * The retry a caller actually makes after losing the response to a
     * timeout: same key, same payload, and a token that has since expired.
     * Answering PREVIEW_EXPIRED there would be useless — the money has already
     * moved and only the credit memo id is missing.
     *
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testACompletedRefundIsReplayedAfterThePreviewTokenExpired()
    {
        $order = $this->fixtureOrder();
        // Both previews are taken while the order is still refundable; only the
        // second one is then left to expire.
        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));
        $expired = $this->expiredToken($order->getEntityId());

        $first = $this->refund->execute($this->request([
            'order_id' => $order->getEntityId(),
            'preview_token' => $contract['preview_token'],
            'idempotency_key' => 'it-expired-1',
        ]));

        $second = $this->refund->execute($this->request([
            'order_id' => $order->getEntityId(),
            'preview_token' => $expired,
            'idempotency_key' => 'it-expired-1',
        ]));

        $this->assertSame('replayed', $second['status']);
        $this->assertSame($first['creditmemo_id'], $second['creditmemo_id']);
        $this->assertSame(1, $this->countCreditmemos($order->getEntityId()));
    }

    /**
     * The other half of the same rule: an expired token buys nothing for a
     * refund that has not happened yet.
     *
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testAnExpiredTokenStillRefusesANewRefund()
    {
        $order = $this->fixtureOrder();
        $token = $this->expiredToken($order->getEntityId());

        try {
            $this->refund->execute($this->request([
                'order_id' => $order->getEntityId(),
                'preview_token' => $token,
                'idempotency_key' => 'it-expired-2',
            ]));
            $this->fail('Expected an expired preview token to be refused.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_PREVIEW_EXPIRED, $e->getMessage());
        }

        $this->assertSame(0, $this->countCreditmemos($order->getEntityId()));
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testACompletedRefundIsReplayedWithNoPreviewTokenAtAll()
    {
        $order = $this->fixtureOrder();
        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));

        $first = $this->refund->execute($this->request([
            'order_id' => $order->getEntityId(),
            'preview_token' => $contract['preview_token'],
            'idempotency_key' => 'it-replay-1',
        ]));

        $second = $this->refund->execute($this->request([
            'order_id' => $order->getEntityId(),
            'idempotency_key' => 'it-replay-1',
        ]));

        $this->assertSame('replayed', $second['status']);
        $this->assertSame($first['creditmemo_id'], $second['creditmemo_id']);
        $this->assertSame(1, $this->countCreditmemos($order->getEntityId()));
    }

    /**
     * A completed key is not a free pass: the payload has to be the one that
     * was refunded, otherwise the request is an ordinary, token-checked commit.
     *
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testACompletedKeyDoesNotReplayADifferentPayload()
    {
        $order = $this->fixtureOrder();
        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));

        $this->refund->execute($this->request([
            'order_id' => $order->getEntityId(),
            'preview_token' => $contract['preview_token'],
            'idempotency_key' => 'it-replay-2',
        ]));

        $items = [];
        foreach ($order->getAllItems() as $orderItem) {
            $items[] = ['order_item_id' => (int) $orderItem->getItemId(), 'qty' => 1];
        }

        try {
            $this->refund->execute($this->request([
                'order_id' => $order->getEntityId(),
                'items' => $this->refundItems($items),
                'idempotency_key' => 'it-replay-2',
            ]));
            $this->fail('Expected a different payload to fall through to the token check.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_PREVIEW_TOKEN_MISSING, $e->getMessage());
        }

        $this->assertSame(1, $this->countCreditmemos($order->getEntityId()));
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testACompletedKeyDoesNotReplayAnotherOrder()
    {
        $order = $this->fixtureOrder();
        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));

        $this->refund->execute($this->request([
            'order_id' => $order->getEntityId(),
            'preview_token' => $contract['preview_token'],
            'idempotency_key' => 'it-replay-3',
        ]));

        try {
            $this->refund->execute($this->request([
                'order_id' => ((int) $order->getEntityId()) + 1000,
                'idempotency_key' => 'it-replay-3',
            ]));
            $this->fail('Expected a foreign order id to fall through to the token check.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_PREVIEW_TOKEN_MISSING, $e->getMessage());
        }

        $this->assertSame(1, $this->countCreditmemos($order->getEntityId()));
    }

    // ---------------------------------------------------------------------
    // Stale in_progress claims.
    // ---------------------------------------------------------------------

    /**
     * A process killed between claim() and complete() leaves an in_progress row
     * behind and would block that key forever. Once the row is older than the
     * configured TTL it is taken over — under the per-order lock, with the same
     * payload, and with the state fingerprint still standing between the retry
     * and a second credit memo.
     *
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testAStaleInProgressClaimIsTakenOverAndTheRefundProceeds()
    {
        $order = $this->fixtureOrder();
        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));
        $this->insertLedgerRow('it-stale-1', $order, $contract, IdempotencyStore::STATUS_IN_PROGRESS, 7200);

        $result = $this->refund->execute($this->request([
            'order_id' => $order->getEntityId(),
            'preview_token' => $contract['preview_token'],
            'idempotency_key' => 'it-stale-1',
        ]));

        $this->assertSame('refunded', $result['status']);
        $this->assertSame(1, $this->countCreditmemos($order->getEntityId()));
        $this->assertSame(IdempotencyStore::STATUS_COMPLETED, $this->ledgerRow('it-stale-1')['status']);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testAFreshInProgressClaimIsNotTakenOver()
    {
        $order = $this->fixtureOrder();
        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));
        $this->insertLedgerRow('it-stale-2', $order, $contract, IdempotencyStore::STATUS_IN_PROGRESS, 0);

        try {
            $this->refund->execute($this->request([
                'order_id' => $order->getEntityId(),
                'preview_token' => $contract['preview_token'],
                'idempotency_key' => 'it-stale-2',
            ]));
            $this->fail('Expected a running claim to block the refund.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_IDEMPOTENCY_IN_PROGRESS, $e->getMessage());
        }

        $this->assertSame(0, $this->countCreditmemos($order->getEntityId()));
        $this->assertSame(IdempotencyStore::STATUS_IN_PROGRESS, $this->ledgerRow('it-stale-2')['status']);
    }

    /**
     * TTL 0 is the documented "never recover automatically" setting.
     *
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testAStaleInProgressClaimStaysBlockedWhenRecoveryIsDisabled()
    {
        $order = $this->fixtureOrder();
        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));
        $this->insertLedgerRow('it-stale-3', $order, $contract, IdempotencyStore::STATUS_IN_PROGRESS, 7200);
        $this->setConfig(Contract::XML_PATH_IN_PROGRESS_TTL, 0);

        try {
            $this->refund->execute($this->request([
                'order_id' => $order->getEntityId(),
                'preview_token' => $contract['preview_token'],
                'idempotency_key' => 'it-stale-3',
            ]));
            $this->fail('Expected a stale claim to stay blocked with recovery disabled.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_IDEMPOTENCY_IN_PROGRESS, $e->getMessage());
        }

        $this->assertSame(0, $this->countCreditmemos($order->getEntityId()));
    }

    /**
     * Even stale, a claim whose payload differs is somebody else's refund.
     *
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testAStaleInProgressClaimWithAnotherPayloadIsNotTakenOver()
    {
        $order = $this->fixtureOrder();
        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));
        $contract['payload_hash'] = 'sha256:' . str_repeat('0', 64);
        $this->insertLedgerRow('it-stale-4', $order, $contract, IdempotencyStore::STATUS_IN_PROGRESS, 7200);

        try {
            $this->refund->execute($this->request([
                'order_id' => $order->getEntityId(),
                'preview_token' => $contract['preview_token'],
                'idempotency_key' => 'it-stale-4',
            ]));
            $this->fail('Expected a stale claim for another payload to block the refund.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_IDEMPOTENCY_IN_PROGRESS, $e->getMessage());
        }

        $this->assertSame(0, $this->countCreditmemos($order->getEntityId()));
    }

    /**
     * `indeterminate` means nobody knows whether money moved. Age changes
     * nothing about that, so it is never taken over and never replayed.
     *
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testAnIndeterminateClaimIsNeverRecovered()
    {
        $order = $this->fixtureOrder();
        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));
        $this->insertLedgerRow('it-indet-1', $order, $contract, IdempotencyStore::STATUS_INDETERMINATE, 7200);

        try {
            $this->refund->execute($this->request([
                'order_id' => $order->getEntityId(),
                'preview_token' => $contract['preview_token'],
                'idempotency_key' => 'it-indet-1',
            ]));
            $this->fail('Expected an indeterminate claim to refuse an automatic retry.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_IDEMPOTENCY_CONFLICT, $e->getMessage());
        }

        $this->assertSame(0, $this->countCreditmemos($order->getEntityId()));
        $this->assertSame(IdempotencyStore::STATUS_INDETERMINATE, $this->ledgerRow('it-indet-1')['status']);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testAnIndeterminateClaimIsNotReplayedWithoutAToken()
    {
        $order = $this->fixtureOrder();
        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));
        $this->insertLedgerRow('it-indet-2', $order, $contract, IdempotencyStore::STATUS_INDETERMINATE, 7200);

        try {
            $this->refund->execute($this->request([
                'order_id' => $order->getEntityId(),
                'idempotency_key' => 'it-indet-2',
            ]));
            $this->fail('Expected an indeterminate claim never to answer from the ledger.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_PREVIEW_TOKEN_MISSING, $e->getMessage());
        }

        $this->assertSame(0, $this->countCreditmemos($order->getEntityId()));
    }

    // ---------------------------------------------------------------------
    // Post-write verification.
    // ---------------------------------------------------------------------

    /**
     * The verification reads the credit memo back through a plain SELECT on the
     * sales connection. Running it against the real schema is what proves the
     * columns exist and that the row is the persisted one, not the entity the
     * repository registry handed back.
     *
     * @magentoDataFixture Magento/Sales/_files/invoice.php
     */
    public function testThePersistedCreditmemoIsReadBackFromTheDatabase()
    {
        $order = $this->fixtureOrder();
        $contract = $this->preview->previewOrder($this->request(['order_id' => $order->getEntityId()]));

        $result = $this->refund->execute($this->request([
            'order_id' => $order->getEntityId(),
            'preview_token' => $contract['preview_token'],
            'idempotency_key' => 'it-verify-1',
        ]));

        $row = $this->objectManager->create(PersistedCreditmemoReader::class)->read($result['creditmemo_id']);

        $this->assertIsArray($row);
        $this->assertSame((int) $result['creditmemo_id'], (int) $row['entity_id']);
        $this->assertSame($result['creditmemo_increment_id'], (string) $row['increment_id']);
        $this->assertSame(
            $contract['totals']['base_grand_total'],
            number_format((float) $row['base_grand_total'], Contract::SCALE, '.', '')
        );
    }

    public function testReadingAnAbsentCreditmemoReturnsNull()
    {
        $this->assertNull(
            $this->objectManager->create(PersistedCreditmemoReader::class)->read(999999999)
        );
    }

    // ---------------------------------------------------------------------
    // Helpers.
    // ---------------------------------------------------------------------

    /**
     * A token that is certainly past its expiry: issued with the shortest
     * lifetime the manager accepts, then left to age past it.
     *
     * @param int $orderId
     * @return string
     */
    private function expiredToken($orderId)
    {
        $this->setConfig(Contract::XML_PATH_PREVIEW_TTL, 1);
        $contract = $this->preview->previewOrder($this->request(['order_id' => $orderId]));
        $this->setConfig(Contract::XML_PATH_PREVIEW_TTL, Contract::DEFAULT_PREVIEW_TTL);

        // exp = iat + 1 and verification rejects on now > exp, so two seconds
        // is past it whatever the sub-second offset of the issuing call was.
        sleep(2);

        return $contract['preview_token'];
    }

    /**
     * @param string $path
     * @param mixed $value
     * @return void
     */
    private function setConfig($path, $value)
    {
        $this->objectManager->get(MutableScopeConfigInterface::class)->setValue($path, $value);
    }

    /**
     * @return \Magento\Framework\DB\Adapter\AdapterInterface
     */
    private function ledgerConnection()
    {
        return $this->objectManager->get(ResourceConnection::class)->getConnection();
    }

    /**
     * @return string
     */
    private function ledgerTable()
    {
        return $this->objectManager->get(ResourceConnection::class)->getTableName(IdempotencyStore::TABLE);
    }

    /**
     * Write a ledger row as a previous, now dead, attempt would have left it.
     *
     * @param string $key
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param array $contract Preview contract the fake attempt was made from.
     * @param string $status
     * @param int $ageSeconds
     * @return void
     */
    private function insertLedgerRow($key, $order, array $contract, $status, $ageSeconds)
    {
        $stamp = gmdate('Y-m-d H:i:s', time() - (int) $ageSeconds);

        $this->ledgerConnection()->insert($this->ledgerTable(), [
            'idempotency_key' => $key,
            'source' => Contract::SOURCE_ORDER,
            'order_id' => $order->getEntityId(),
            'invoice_id' => null,
            'payload_hash' => $contract['payload_hash'],
            'state_fingerprint' => $contract['state_fingerprint'],
            'status' => $status,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);
    }

    /**
     * @param string $key
     * @return array
     */
    private function ledgerRow($key)
    {
        $connection = $this->ledgerConnection();

        return $connection->fetchRow(
            $connection->select()->from($this->ledgerTable())->where('idempotency_key = ?', $key)
        );
    }

    /**
     * @param array $rows
     * @return \Emipro\Apichange\Api\Data\RefundItemInterface[]
     */
    private function refundItems(array $rows)
    {
        $items = [];
        foreach ($rows as $row) {
            $item = $this->objectManager->create(\Emipro\Apichange\Api\Data\RefundItemInterface::class);
            $item->setOrderItemId($row['order_item_id']);
            $item->setQty($row['qty']);
            $items[] = $item;
        }

        return $items;
    }
}
