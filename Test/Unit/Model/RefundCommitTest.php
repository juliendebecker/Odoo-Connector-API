<?php

namespace Emipro\Apichange\Test\Unit\Model;

use Emipro\Apichange\Api\Data\RefundRequestInterface;
use Emipro\Apichange\Model\Refund\Canonicalizer;
use Emipro\Apichange\Model\Refund\Config;
use Emipro\Apichange\Model\Refund\ConflictFactory;
use Emipro\Apichange\Model\Refund\Contract;
use Emipro\Apichange\Model\Refund\CreditmemoPreviewBuilder;
use Emipro\Apichange\Model\Refund\Data\RefundItem;
use Emipro\Apichange\Model\Refund\Data\RefundRequest;
use Emipro\Apichange\Model\Refund\IdempotencyStore;
use Emipro\Apichange\Model\Refund\PayloadHasher;
use Emipro\Apichange\Model\Refund\PersistedCreditmemoReader;
use Emipro\Apichange\Model\Refund\PreviewTokenManager;
use Emipro\Apichange\Model\Refund\RefundGuard;
use Emipro\Apichange\Model\Refund\RefundLock;
use Emipro\Apichange\Model\Refund\SnapshotBuilder;
use Emipro\Apichange\Model\RefundCommit;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\InputException;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory;
use Magento\Sales\Api\RefundInvoiceInterface;
use Magento\Sales\Api\RefundOrderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Replay of an already completed refund, resolved before the preview token is
 * verified.
 *
 * A caller that lost the HTTP response to a timeout comes back with the same
 * idempotency_key long after the preview token has expired. Refusing that retry
 * with PREVIEW_EXPIRED is useless to them: the money has already moved and the
 * only thing they need is the credit memo id.
 *
 * The safety property this whole class exercises: the early path can ONLY
 * return an already stored response. Every ledger state other than `completed`,
 * and every request that does not match the stored one bit for bit, falls
 * through to the ordinary flow, which still demands a valid token.
 */
class RefundCommitTest extends TestCase
{
    /**
     * Marker thrown by the token manager: seeing it means the early replay path
     * declined and the request was handed to the ordinary, token-checked flow.
     */
    const TOKEN_WAS_VERIFIED = 'token verification was reached';

    /**
     * @var PreviewTokenManager|\PHPUnit\Framework\MockObject\MockObject
     */
    private $tokenManager;

    /**
     * @var RefundLock|\PHPUnit\Framework\MockObject\MockObject
     */
    private $refundLock;

    /**
     * @var IdempotencyStore|\PHPUnit\Framework\MockObject\MockObject
     */
    private $idempotencyStore;

    /**
     * @var RefundOrderInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $refundOrder;

    /**
     * @var RefundInvoiceInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $refundInvoice;

    protected function setUp(): void
    {
        $this->tokenManager = $this->createMock(PreviewTokenManager::class);
        $this->refundLock = $this->createMock(RefundLock::class);
        $this->idempotencyStore = $this->createMock(IdempotencyStore::class);
        $this->refundOrder = $this->createMock(RefundOrderInterface::class);
        $this->refundInvoice = $this->createMock(RefundInvoiceInterface::class);

        // Mirrors the real store closely enough for this test: keys are trimmed,
        // and anything that is not printable ASCII without spaces is rejected
        // outright rather than repaired.
        $this->idempotencyStore->method('normalizeKey')->willReturnCallback(function ($key) {
            $key = trim((string) $key);
            if ($key === '' || preg_match('/[^\x21-\x7E]/', $key)) {
                throw new InputException(__('idempotency_key is not usable.'));
            }

            return $key;
        });
        $this->idempotencyStore->method('decodeResponse')->willReturnCallback(function (array $row) {
            if (empty($row['response_json'])) {
                return null;
            }
            $decoded = json_decode($row['response_json'], true);

            return is_array($decoded) ? $decoded : null;
        });
    }

    /**
     * @return RefundCommit
     */
    private function commit()
    {
        return new RefundCommit(
            $this->tokenManager,
            $this->refundLock,
            $this->idempotencyStore,
            $this->createMock(CreditmemoPreviewBuilder::class),
            $this->createMock(SnapshotBuilder::class),
            new PayloadHasher(new Canonicalizer()),
            $this->createMock(RefundGuard::class),
            new ConflictFactory(),
            $this->createMock(Config::class),
            new Canonicalizer(),
            $this->createMock(ResourceConnection::class),
            $this->createMock(PersistedCreditmemoReader::class),
            $this->refundOrder,
            $this->refundInvoice,
            $this->createMock(CreditmemoItemCreationInterfaceFactory::class),
            $this->createMock(CreditmemoCreationArgumentsInterfaceFactory::class),
            $this->createMock(CreditmemoCommentCreationInterfaceFactory::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param array $data
     * @return RefundRequestInterface
     */
    private function request(array $data = [])
    {
        $request = new RefundRequest();
        $request->setOrderId(isset($data['order_id']) ? $data['order_id'] : null);
        $request->setInvoiceId(isset($data['invoice_id']) ? $data['invoice_id'] : null);
        $request->setIdempotencyKey(isset($data['idempotency_key']) ? $data['idempotency_key'] : null);
        $request->setPreviewToken(isset($data['preview_token']) ? $data['preview_token'] : null);

        if (isset($data['items'])) {
            $items = [];
            foreach ($data['items'] as $itemId => $qty) {
                $item = new RefundItem();
                $item->setOrderItemId($itemId);
                $item->setQty($qty);
                $items[] = $item;
            }
            $request->setItems($items);
        }

        return $request;
    }

    /**
     * @param RefundRequestInterface $request
     * @param string $source
     * @return string
     */
    private function hashOf(RefundRequestInterface $request, $source = Contract::SOURCE_ORDER)
    {
        return (new PayloadHasher(new Canonicalizer()))->hash($request, $source);
    }

    /**
     * A ledger row as the store would have written it for $request.
     *
     * @param RefundRequestInterface $request
     * @param array $overrides
     * @return array
     */
    private function ledgerRow(RefundRequestInterface $request, array $overrides = [])
    {
        $source = isset($overrides['source']) ? $overrides['source'] : Contract::SOURCE_ORDER;

        return $overrides + [
            'idempotency_key' => 'odoo-refund-1',
            'source' => $source,
            'order_id' => '12',
            'invoice_id' => null,
            'payload_hash' => $this->hashOf($request, $source),
            'state_fingerprint' => 'sha256:whatever',
            'status' => IdempotencyStore::STATUS_COMPLETED,
            'creditmemo_id' => '77',
            'creditmemo_increment_id' => '000000077',
            'response_json' => json_encode([
                'contract_version' => Contract::CONTRACT_VERSION,
                'status' => 'refunded',
                'creditmemo_id' => 77,
                'creditmemo_increment_id' => '000000077',
                'warnings' => [],
            ]),
            'error_message' => null,
            'created_at' => '2026-08-21 09:00:00',
            'updated_at' => '2026-08-21 09:00:01',
        ];
    }

    /**
     * Nothing may be refunded and no lock may be taken on the replay path.
     *
     * @return void
     */
    private function expectNoRefundAttempt()
    {
        $this->refundOrder->expects($this->never())->method('execute');
        $this->refundInvoice->expects($this->never())->method('execute');
        $this->refundLock->expects($this->never())->method('acquire');
    }

    /**
     * The token manager is the boundary: reaching it means the early path
     * declined and the ordinary, token-checked flow took over.
     *
     * @return void
     */
    private function expectTheOrdinaryFlowToTakeOver()
    {
        $this->tokenManager->expects($this->once())
            ->method('verify')
            ->willThrowException(new \RuntimeException(self::TOKEN_WAS_VERIFIED));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(self::TOKEN_WAS_VERIFIED);
    }

    public function testACompletedRefundIsReplayedWithoutVerifyingThePreviewToken()
    {
        $request = $this->request(['order_id' => 12, 'idempotency_key' => 'odoo-refund-1']);
        $this->idempotencyStore->expects($this->once())
            ->method('find')
            ->with('odoo-refund-1')
            ->willReturn($this->ledgerRow($request));
        $this->tokenManager->expects($this->never())->method('verify');
        $this->expectNoRefundAttempt();

        $result = $this->commit()->execute($request);

        $this->assertSame('replayed', $result['status']);
        $this->assertSame(77, $result['creditmemo_id']);
        $this->assertSame('000000077', $result['creditmemo_increment_id']);
        $this->assertSame('odoo-refund-1', $result['idempotency_key']);
        $this->assertTrue($result['idempotent']);
    }

    /**
     * Same shape as the in-token replay: the stored response verbatim, with the
     * three fields the contract adds on a replay.
     */
    public function testTheStoredResponseIsReturnedVerbatim()
    {
        $request = $this->request(['order_id' => 12, 'idempotency_key' => 'odoo-refund-1']);
        $this->idempotencyStore->method('find')->willReturn($this->ledgerRow($request, [
            'response_json' => json_encode([
                'contract_version' => Contract::CONTRACT_VERSION,
                'status' => 'refunded',
                'source' => Contract::SOURCE_ORDER,
                'creditmemo_id' => 77,
                'totals' => ['base_grand_total' => '24.0000'],
            ]),
        ]));

        $result = $this->commit()->execute($request);

        $this->assertSame(['base_grand_total' => '24.0000'], $result['totals']);
        $this->assertSame(Contract::SOURCE_ORDER, $result['source']);
        $this->assertSame('replayed', $result['status']);
    }

    public function testAnUnreadableStoredResponseFallsBackToTheLedgerColumns()
    {
        $request = $this->request(['order_id' => 12, 'idempotency_key' => 'odoo-refund-1']);
        $this->idempotencyStore->method('find')
            ->willReturn($this->ledgerRow($request, ['response_json' => 'not json']));

        $result = $this->commit()->execute($request);

        $this->assertSame('replayed', $result['status']);
        $this->assertSame(77, $result['creditmemo_id']);
        $this->assertSame('000000077', $result['creditmemo_increment_id']);
        $this->assertSame(Contract::CONTRACT_VERSION, $result['contract_version']);
    }

    public function testAnInvoiceRefundIsReplayedOnItsInvoiceId()
    {
        $request = $this->request(['invoice_id' => 5, 'idempotency_key' => 'odoo-refund-1']);
        $this->idempotencyStore->method('find')->willReturn($this->ledgerRow($request, [
            'source' => Contract::SOURCE_INVOICE,
            'order_id' => '12',
            'invoice_id' => '5',
        ]));
        $this->tokenManager->expects($this->never())->method('verify');

        $this->assertSame('replayed', $this->commit()->execute($request)['status']);
    }

    /**
     * The same key with a different basket is not a retry, it is a conflict.
     * The early path must not answer it; the ordinary flow rejects it.
     */
    public function testADifferentPayloadUnderTheSameKeyIsNotReplayed()
    {
        $stored = $this->request(['order_id' => 12, 'idempotency_key' => 'odoo-refund-1', 'items' => [34 => 1]]);
        $sent = $this->request(['order_id' => 12, 'idempotency_key' => 'odoo-refund-1', 'items' => [34 => 2]]);

        $this->idempotencyStore->method('find')->willReturn($this->ledgerRow($stored));
        $this->expectNoRefundAttempt();
        $this->expectTheOrdinaryFlowToTakeOver();

        $this->commit()->execute($sent);
    }

    public function testARequestNamingAnotherOrderIsNotReplayed()
    {
        $request = $this->request(['order_id' => 12, 'idempotency_key' => 'odoo-refund-1']);
        $this->idempotencyStore->method('find')
            ->willReturn($this->ledgerRow($request, ['order_id' => '99']));

        $this->expectNoRefundAttempt();
        $this->expectTheOrdinaryFlowToTakeOver();

        $this->commit()->execute($request);
    }

    public function testAnInvoiceRowIsNotReplayedForARequestThatNamesNoInvoice()
    {
        $request = $this->request(['order_id' => 12, 'idempotency_key' => 'odoo-refund-1']);
        $this->idempotencyStore->method('find')->willReturn($this->ledgerRow($request, [
            'source' => Contract::SOURCE_INVOICE,
            'invoice_id' => '5',
            // Hash recomputed for the invoice source so that only the missing
            // invoice_id can be what refuses the replay.
            'payload_hash' => $this->hashOf($request, Contract::SOURCE_INVOICE),
        ]));

        $this->expectNoRefundAttempt();
        $this->expectTheOrdinaryFlowToTakeOver();

        $this->commit()->execute($request);
    }

    /**
     * `indeterminate` is the one status that must never be answered from the
     * ledger: nobody knows whether that refund moved money.
     *
     * @dataProvider nonCompletedStatusProvider
     * @param string $status
     */
    public function testOnlyCompletedRowsAreReplayed($status)
    {
        $request = $this->request(['order_id' => 12, 'idempotency_key' => 'odoo-refund-1']);
        $this->idempotencyStore->method('find')
            ->willReturn($this->ledgerRow($request, ['status' => $status]));

        $this->expectNoRefundAttempt();
        $this->expectTheOrdinaryFlowToTakeOver();

        $this->commit()->execute($request);
    }

    /**
     * @return array
     */
    public function nonCompletedStatusProvider()
    {
        return [
            'in progress' => [IdempotencyStore::STATUS_IN_PROGRESS],
            'failed' => [IdempotencyStore::STATUS_FAILED],
            'indeterminate' => [IdempotencyStore::STATUS_INDETERMINATE],
            'unknown' => ['something-else'],
        ];
    }

    public function testAnUnknownKeyIsNotReplayed()
    {
        $this->idempotencyStore->method('find')->willReturn(null);

        $this->expectNoRefundAttempt();
        $this->expectTheOrdinaryFlowToTakeOver();

        $this->commit()->execute($this->request(['order_id' => 12, 'idempotency_key' => 'odoo-refund-1']));
    }

    public function testARequestWithoutAnIdempotencyKeyNeverTouchesTheLedgerEarly()
    {
        $this->idempotencyStore->expects($this->never())->method('find');

        $this->expectNoRefundAttempt();
        $this->expectTheOrdinaryFlowToTakeOver();

        $this->commit()->execute($this->request(['order_id' => 12]));
    }

    /**
     * A malformed key must be reported by the ordinary flow, exactly as before,
     * rather than turning into a different error because the early path ran
     * first.
     */
    public function testAMalformedKeyIsLeftToTheOrdinaryFlow()
    {
        $this->idempotencyStore->expects($this->never())->method('find');

        $this->expectNoRefundAttempt();
        $this->expectTheOrdinaryFlowToTakeOver();

        $this->commit()->execute($this->request(['order_id' => 12, 'idempotency_key' => 'bad key']));
    }
}
