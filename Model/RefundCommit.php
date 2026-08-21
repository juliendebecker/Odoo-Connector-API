<?php

namespace Emipro\Apichange\Model;

use Emipro\Apichange\Api\Data\RefundRequestInterface;
use Emipro\Apichange\Api\RefundInterface;
use Emipro\Apichange\Model\Refund\Canonicalizer;
use Emipro\Apichange\Model\Refund\Config;
use Emipro\Apichange\Model\Refund\ConflictFactory;
use Emipro\Apichange\Model\Refund\Contract;
use Emipro\Apichange\Model\Refund\CreditmemoPreviewBuilder;
use Emipro\Apichange\Model\Refund\IdempotencyStore;
use Emipro\Apichange\Model\Refund\IndeterminateRefundException;
use Emipro\Apichange\Model\Refund\PayloadHasher;
use Emipro\Apichange\Model\Refund\PersistedCreditmemoReader;
use Emipro\Apichange\Model\Refund\PreviewTokenManager;
use Emipro\Apichange\Model\Refund\RefundGuard;
use Emipro\Apichange\Model\Refund\RefundLock;
use Emipro\Apichange\Model\Refund\SnapshotBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterfaceFactory;
use Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory;
use Magento\Sales\Api\RefundInvoiceInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Psr\Log\LoggerInterface;

/**
 * Guarded refund commit endpoint.
 *
 * Sequence, in this exact order:
 *   0. if this idempotency_key already carries a COMPLETED refund for exactly
 *      this request, return the stored response - a pure read, resolved before
 *      the token is looked at, because a caller retrying after a timeout has
 *      no use for PREVIEW_EXPIRED;
 *   1. verify the preview token signature and expiry (no database access);
 *   2. acquire the per order advisory lock;
 *   3. resolve the durable idempotency ledger (replay, conflict, or claim);
 *   4. rebuild the credit memo with the native engine and re-derive the claims;
 *   5. refuse unless payload, state and totals are bit identical to the preview;
 *   6. refund through the native RefundOrderInterface / RefundInvoiceInterface;
 *   7. re-read the created credit memo from the database and, for offline
 *      refunds, roll the whole thing back if the persisted total is not the
 *      previewed total.
 *
 * Step 0 can only ever return something that was already refunded. Creating a
 * credit memo still requires a valid, unexpired token, without exception.
 *
 * @see \Emipro\Apichange\Api\RefundInterface
 */
class RefundCommit implements RefundInterface
{
    /**
     * @see Contract::SALES_CONNECTION
     */
    const SALES_CONNECTION = Contract::SALES_CONNECTION;

    /**
     * @var PreviewTokenManager
     */
    private $tokenManager;

    /**
     * @var RefundLock
     */
    private $refundLock;

    /**
     * @var IdempotencyStore
     */
    private $idempotencyStore;

    /**
     * @var CreditmemoPreviewBuilder
     */
    private $previewBuilder;

    /**
     * @var SnapshotBuilder
     */
    private $snapshotBuilder;

    /**
     * @var PayloadHasher
     */
    private $payloadHasher;

    /**
     * @var RefundGuard
     */
    private $refundGuard;

    /**
     * @var ConflictFactory
     */
    private $conflictFactory;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Canonicalizer
     */
    private $canonicalizer;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var RefundOrderInterface
     */
    private $refundOrder;

    /**
     * @var RefundInvoiceInterface
     */
    private $refundInvoice;

    /**
     * @var PersistedCreditmemoReader
     */
    private $creditmemoReader;

    /**
     * @var CreditmemoItemCreationInterfaceFactory
     */
    private $itemCreationFactory;

    /**
     * @var CreditmemoCreationArgumentsInterfaceFactory
     */
    private $argumentsFactory;

    /**
     * @var CreditmemoCommentCreationInterfaceFactory
     */
    private $commentFactory;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param PreviewTokenManager $tokenManager
     * @param RefundLock $refundLock
     * @param IdempotencyStore $idempotencyStore
     * @param CreditmemoPreviewBuilder $previewBuilder
     * @param SnapshotBuilder $snapshotBuilder
     * @param PayloadHasher $payloadHasher
     * @param RefundGuard $refundGuard
     * @param ConflictFactory $conflictFactory
     * @param Config $config
     * @param Canonicalizer $canonicalizer
     * @param ResourceConnection $resourceConnection
     * @param PersistedCreditmemoReader $creditmemoReader
     * @param RefundOrderInterface $refundOrder
     * @param RefundInvoiceInterface $refundInvoice
     * @param CreditmemoItemCreationInterfaceFactory $itemCreationFactory
     * @param CreditmemoCreationArgumentsInterfaceFactory $argumentsFactory
     * @param CreditmemoCommentCreationInterfaceFactory $commentFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        PreviewTokenManager $tokenManager,
        RefundLock $refundLock,
        IdempotencyStore $idempotencyStore,
        CreditmemoPreviewBuilder $previewBuilder,
        SnapshotBuilder $snapshotBuilder,
        PayloadHasher $payloadHasher,
        RefundGuard $refundGuard,
        ConflictFactory $conflictFactory,
        Config $config,
        Canonicalizer $canonicalizer,
        ResourceConnection $resourceConnection,
        PersistedCreditmemoReader $creditmemoReader,
        RefundOrderInterface $refundOrder,
        RefundInvoiceInterface $refundInvoice,
        CreditmemoItemCreationInterfaceFactory $itemCreationFactory,
        CreditmemoCreationArgumentsInterfaceFactory $argumentsFactory,
        CreditmemoCommentCreationInterfaceFactory $commentFactory,
        LoggerInterface $logger
    ) {
        $this->tokenManager = $tokenManager;
        $this->refundLock = $refundLock;
        $this->idempotencyStore = $idempotencyStore;
        $this->previewBuilder = $previewBuilder;
        $this->snapshotBuilder = $snapshotBuilder;
        $this->payloadHasher = $payloadHasher;
        $this->refundGuard = $refundGuard;
        $this->conflictFactory = $conflictFactory;
        $this->config = $config;
        $this->canonicalizer = $canonicalizer;
        $this->resourceConnection = $resourceConnection;
        $this->creditmemoReader = $creditmemoReader;
        $this->refundOrder = $refundOrder;
        $this->refundInvoice = $refundInvoice;
        $this->itemCreationFactory = $itemCreationFactory;
        $this->argumentsFactory = $argumentsFactory;
        $this->commentFactory = $commentFactory;
        $this->logger = $logger;
    }

    /**
     * @param RefundRequestInterface $request
     * @return mixed[]
     */
    public function execute(RefundRequestInterface $request)
    {
        // Resolved before anything else, because it is the one answer that does
        // not depend on the token being fresh: this exact refund is already
        // done and the caller only lost the response.
        $replay = $this->replayCompleted($request);
        if ($replay !== null) {
            return $replay;
        }

        $signedClaims = $this->tokenManager->verify($request->getPreviewToken());
        $source = isset($signedClaims[RefundGuard::CLAIM_SOURCE])
            ? (string) $signedClaims[RefundGuard::CLAIM_SOURCE]
            : '';

        if ($source !== Contract::SOURCE_ORDER && $source !== Contract::SOURCE_INVOICE) {
            throw $this->conflictFactory->create(
                Contract::ERR_PREVIEW_TOKEN_INVALID,
                'preview_token carries an unknown refund source.'
            );
        }

        // The lock target comes from the SIGNED claims, never from the raw
        // request body: a caller must not be able to make us serialise on a
        // different order than the one the preview was issued for.
        if (empty($signedClaims[RefundGuard::CLAIM_ORDER_ID])) {
            throw $this->conflictFactory->create(
                Contract::ERR_PREVIEW_TOKEN_INVALID,
                'preview_token carries no order id.'
            );
        }

        $lockOrderId = (int) $signedClaims[RefundGuard::CLAIM_ORDER_ID];
        $lockName = $this->refundLock->nameFor($lockOrderId);
        $lockTimeout = $this->config->getLockTimeout(
            isset($signedClaims[RefundGuard::CLAIM_STORE_ID]) ? (int) $signedClaims[RefundGuard::CLAIM_STORE_ID] : null
        );

        if (!$this->refundLock->acquire($lockName, $lockTimeout)) {
            throw $this->conflictFactory->create(
                Contract::ERR_LOCK_TIMEOUT,
                'Another refund is already running for this order. Retry later.',
                ['lock' => $lockName, 'timeout' => $lockTimeout]
            );
        }

        try {
            return $this->executeLocked($request, $signedClaims, $source, $lockName);
        } finally {
            $this->refundLock->release($lockName);
        }
    }

    /**
     * @param RefundRequestInterface $request
     * @param array $signedClaims
     * @param string $source
     * @param string $lockName
     * @return array
     */
    private function executeLocked(
        RefundRequestInterface $request,
        array $signedClaims,
        $source,
        $lockName
    ) {
        $payloadHash = $this->payloadHasher->hash($request, $source);
        $idempotencyKey = $request->getIdempotencyKey();
        $recovered = false;

        if ($idempotencyKey !== null) {
            $idempotencyKey = $this->idempotencyStore->normalizeKey($idempotencyKey);
            $decision = $this->resolveIdempotency($idempotencyKey, $signedClaims, $source, $payloadHash);
            if ($decision['response'] !== null) {
                return $decision['response'];
            }
            $recovered = $decision['recovered'];
        }

        try {
            $result = $this->guardAndRefund($request, $signedClaims, $source, $lockName);
        } catch (\Exception $e) {
            if ($idempotencyKey !== null) {
                // FAILED means "provably nothing was committed, retrying under
                // the same key is safe". guardAndRefund() only lets an ordinary
                // exception escape once it has rolled back or before it ever
                // opened a transaction. The one case where that cannot be
                // proven raises IndeterminateRefundException, and that key is
                // then never auto-retried.
                //
                // A recovered claim is the other such case: the attempt that
                // abandoned the row may have committed something we cannot see,
                // so its key must not be downgraded to `failed` - that status
                // means "provably nothing happened", which nobody can assert
                // here.
                $this->idempotencyStore->fail(
                    $idempotencyKey,
                    $recovered || $e instanceof IndeterminateRefundException
                        ? IdempotencyStore::STATUS_INDETERMINATE
                        : IdempotencyStore::STATUS_FAILED,
                    $recovered
                        ? 'Retry of a recovered stale claim; the outcome of the abandoned attempt is unknown. '
                          . $e->getMessage()
                        : $e->getMessage()
                );
            }
            throw $e;
        }

        if ($idempotencyKey !== null) {
            $result['idempotency_key'] = $idempotencyKey;
            $result['idempotent'] = true;
            $this->idempotencyStore->complete(
                $idempotencyKey,
                $result['creditmemo_id'],
                $result['creditmemo_increment_id'],
                $result
            );
        } else {
            $result['idempotency_key'] = null;
            $result['idempotent'] = false;
        }

        return $result;
    }

    /**
     * Replay, conflict, or claim.
     *
     * @param string $key
     * @param array $signedClaims
     * @param string $source
     * @param string $payloadHash
     * @return array{response: array|null, recovered: bool} A non null `response`
     *         means "return this to the caller, do nothing else". `recovered`
     *         reports that the claim was taken from an abandoned attempt whose
     *         outcome nobody can vouch for.
     */
    private function resolveIdempotency($key, array $signedClaims, $source, $payloadHash)
    {
        $storeId = isset($signedClaims[RefundGuard::CLAIM_STORE_ID])
            ? (int) $signedClaims[RefundGuard::CLAIM_STORE_ID]
            : null;
        $inProgressTtl = $this->config->getInProgressTtl($storeId);

        $claim = $this->idempotencyStore->claim(
            $key,
            $source,
            isset($signedClaims[RefundGuard::CLAIM_ORDER_ID]) ? (int) $signedClaims[RefundGuard::CLAIM_ORDER_ID] : null,
            isset($signedClaims[RefundGuard::CLAIM_INVOICE_ID]) && $signedClaims[RefundGuard::CLAIM_INVOICE_ID] !== null
                ? (int) $signedClaims[RefundGuard::CLAIM_INVOICE_ID]
                : null,
            $payloadHash,
            isset($signedClaims[RefundGuard::CLAIM_STATE_FINGERPRINT])
                ? (string) $signedClaims[RefundGuard::CLAIM_STATE_FINGERPRINT]
                : '',
            $inProgressTtl
        );

        if ($claim['claimed']) {
            $recovered = !empty($claim['recovered']);
            if ($recovered) {
                // Worth an operator's attention even when the retry succeeds:
                // it means a refund process died mid-flight at some point.
                $this->logger->warning(
                    'Emipro_Apichange: took over an idempotency claim abandoned for more than '
                    . $inProgressTtl . 's, key ' . $key . '. The guard still has to accept the retry.'
                );
            }

            return ['response' => null, 'recovered' => $recovered];
        }

        $row = $claim['row'];
        if (!is_array($row)) {
            // The key exists but vanished between the failed INSERT and the
            // re-read: another process is racing us on the same key.
            throw $this->conflictFactory->create(
                Contract::ERR_IDEMPOTENCY_IN_PROGRESS,
                'This idempotency_key is being processed concurrently.',
                ['idempotency_key' => $key]
            );
        }

        $status = isset($row['status']) ? $row['status'] : '';

        if ($status === IdempotencyStore::STATUS_COMPLETED) {
            if (!hash_equals((string) $row['payload_hash'], (string) $payloadHash)) {
                throw $this->conflictFactory->create(
                    Contract::ERR_IDEMPOTENCY_CONFLICT,
                    'This idempotency_key was already used for a different refund payload.',
                    ['idempotency_key' => $key, 'creditmemo_id' => $row['creditmemo_id']]
                );
            }

            return ['response' => $this->buildReplayResponse($key, $row), 'recovered' => false];
        }

        if ($status === IdempotencyStore::STATUS_IN_PROGRESS) {
            throw $this->conflictFactory->create(
                Contract::ERR_IDEMPOTENCY_IN_PROGRESS,
                $inProgressTtl > 0
                    ? 'A refund with this idempotency_key is already running. A claim abandoned by a dead '
                      . 'process is taken over automatically once it has been untouched for '
                      . $inProgressTtl . ' seconds; retry after that.'
                    : 'A refund with this idempotency_key is already running. Automatic takeover of abandoned '
                      . 'claims is disabled on this store, so this key stays blocked until it is resolved by hand.',
                ['idempotency_key' => $key, 'created_at' => $row['created_at']]
            );
        }

        // STATUS_INDETERMINATE, or a payload mismatch on a failed row: never
        // retried automatically, because we cannot prove no money moved.
        throw $this->conflictFactory->create(
            Contract::ERR_IDEMPOTENCY_CONFLICT,
            'This idempotency_key is in a state that forbids an automatic retry. '
            . 'Inspect the refund manually, then use a new key.',
            ['idempotency_key' => $key, 'ledger_status' => $status]
        );
    }

    /**
     * Answer an already completed refund from the ledger alone, without looking
     * at the preview token.
     *
     * This is the retry a caller makes after losing the response to a timeout:
     * same key, same payload, but the preview token has expired in the
     * meantime. Refusing it with PREVIEW_EXPIRED helps nobody - the credit memo
     * exists, and its id is the only thing missing on the other side.
     *
     * What makes it safe is that it can only ever return a stored response.
     * Every condition below has to hold, and the payload hash is recomputed
     * from the request that has just arrived, so a caller cannot obtain
     * somebody else's refund, or a different refund of their own, by guessing a
     * key. Anything that does not match falls through to the ordinary flow,
     * which still demands a valid token; no credit memo is ever created here.
     *
     * @param RefundRequestInterface $request
     * @return array|null
     */
    private function replayCompleted(RefundRequestInterface $request)
    {
        $key = $request->getIdempotencyKey();
        if ($key === null) {
            return null;
        }

        try {
            $key = $this->idempotencyStore->normalizeKey($key);
        } catch (InputException $e) {
            // Reported by the ordinary flow, in the order it always was: how a
            // malformed request is rejected must not depend on the ledger.
            return null;
        }

        $row = $this->idempotencyStore->find($key);
        if (!is_array($row)
            || !isset($row['status'])
            || $row['status'] !== IdempotencyStore::STATUS_COMPLETED
        ) {
            // in_progress, failed and above all indeterminate are decided under
            // the lock, with a verified token, by resolveIdempotency().
            return null;
        }

        $source = isset($row['source']) ? (string) $row['source'] : '';
        if ($source !== Contract::SOURCE_ORDER && $source !== Contract::SOURCE_INVOICE) {
            return null;
        }

        if (!$this->requestMatchesLedgerRow($request, $row, $source)) {
            return null;
        }

        // Recomputed from the request that has just arrived and compared to the
        // hash stored when the credit memo was created. It covers the source,
        // both identifiers, every item line, the shipping amount, both
        // adjustments and is_online.
        if (!hash_equals(
            (string) $row['payload_hash'],
            (string) $this->payloadHasher->hash($request, $source)
        )) {
            return null;
        }

        return $this->buildReplayResponse($key, $row);
    }

    /**
     * Whether the request names the very document the ledger row was written
     * for.
     *
     * Largely implied by the payload hash, which already covers both
     * identifiers; kept explicit because "the same key must mean the same
     * document" is a rule that should be readable, not inferred from a hash.
     *
     * @param RefundRequestInterface $request
     * @param array $row
     * @param string $source
     * @return bool
     */
    private function requestMatchesLedgerRow(RefundRequestInterface $request, array $row, $source)
    {
        $rowOrderId = isset($row['order_id']) && $row['order_id'] !== null ? (int) $row['order_id'] : null;
        $rowInvoiceId = isset($row['invoice_id']) && $row['invoice_id'] !== null ? (int) $row['invoice_id'] : null;
        $orderId = $request->getOrderId();
        $invoiceId = $request->getInvoiceId();

        // The document the refund was made from has to be named, and named
        // identically.
        if ($source === Contract::SOURCE_INVOICE) {
            if ($invoiceId === null || $rowInvoiceId === null || $invoiceId !== $rowInvoiceId) {
                return false;
            }
        } elseif ($orderId === null || $rowOrderId === null || $orderId !== $rowOrderId) {
            return false;
        }

        // The other identifier must not contradict the row when the request
        // carries one. On the invoice path order_id is optional in the request
        // while the ledger always stores the order behind the invoice, hence
        // the "only when both are present".
        if ($orderId !== null && $rowOrderId !== null && $orderId !== $rowOrderId) {
            return false;
        }

        return !($invoiceId !== null && $rowInvoiceId !== null && $invoiceId !== $rowInvoiceId);
    }

    /**
     * Shape a completed ledger row into the replay response.
     *
     * Single implementation on purpose: the answer must be identical whether
     * the replay was resolved before the token check or under the lock.
     *
     * @param string $key
     * @param array $row
     * @return array
     */
    private function buildReplayResponse($key, array $row)
    {
        $stored = $this->idempotencyStore->decodeResponse($row);
        if ($stored === null) {
            // Written by a version that stored no response, or stored one that
            // no longer decodes: the ledger columns still carry what the caller
            // actually needs.
            $stored = [
                'contract_version' => Contract::CONTRACT_VERSION,
                'creditmemo_id' => !isset($row['creditmemo_id']) || $row['creditmemo_id'] === null
                    ? null
                    : (int) $row['creditmemo_id'],
                'creditmemo_increment_id' => isset($row['creditmemo_increment_id'])
                    ? $row['creditmemo_increment_id']
                    : null,
            ];
        }

        $stored['status'] = 'replayed';
        $stored['idempotency_key'] = $key;
        $stored['idempotent'] = true;

        return $stored;
    }

    /**
     * Rebuild, guard, refund, verify.
     *
     * @param RefundRequestInterface $request
     * @param array $signedClaims
     * @param string $source
     * @param string $lockName
     * @return array
     */
    private function guardAndRefund(
        RefundRequestInterface $request,
        array $signedClaims,
        $source,
        $lockName
    ) {
        $built = $source === Contract::SOURCE_INVOICE
            ? $this->previewBuilder->buildFromInvoice($request)
            : $this->previewBuilder->buildFromOrder($request);

        $snapshot = $this->snapshotBuilder->build($built, $request, $source);
        $this->refundGuard->assertMatches($signedClaims, $snapshot['claims']);

        $isOnline = $source === Contract::SOURCE_INVOICE && $request->getIsOnline();
        $atomicRollback = !$isOnline && $this->config->isAtomicVerifyEnabled($built['order']->getStoreId());

        $connection = $this->resourceConnection->getConnection(self::SALES_CONNECTION);
        $transactionOpen = false;
        if ($atomicRollback) {
            // Magento's PDO adapter counts nesting levels: the BEGIN issued
            // here is the real one, the BEGIN/COMMIT pair that RefundOrder or
            // RefundInvoice performs internally only moves the counter. That is
            // what allows the verification below to still undo everything.
            $connection->beginTransaction();
            $transactionOpen = true;
        }

        // --- Phase 1: nothing is committed yet, every failure is recoverable.
        try {
            $creditmemoId = $source === Contract::SOURCE_INVOICE
                ? $this->refundInvoice->execute(
                    (int) $request->getInvoiceId(),
                    $this->buildCreationItems($request),
                    (bool) $isOnline,
                    false,
                    $request->getComment() !== null,
                    $this->buildComment($request),
                    $this->buildArguments($request)
                )
                : $this->refundOrder->execute(
                    (int) $request->getOrderId(),
                    $this->buildCreationItems($request),
                    false,
                    $request->getComment() !== null,
                    $this->buildComment($request),
                    $this->buildArguments($request)
                );
        } catch (\Exception $e) {
            // Rolling back is safe even when the native service already rolled
            // back internally: the adapter only issues one real ROLLBACK.
            $this->rollBackQuietly($connection, $transactionOpen);
            throw $e;
        }

        // --- Phase 2: on the online path the gateway has already been asked to
        // move money and the sales rows are committed. Nothing here can undo
        // that, so a failure is reported as indeterminate rather than failed.
        try {
            $verification = $this->verifyPersistedTotals($creditmemoId, $signedClaims);
        } catch (\Exception $e) {
            if ($transactionOpen) {
                $this->rollBackQuietly($connection, true);
                throw $e;
            }

            throw new IndeterminateRefundException(
                __(
                    'The refund was submitted but its result could not be verified. '
                    . 'Credit memo id %1 may or may not exist. Reconcile manually.',
                    $creditmemoId
                ),
                ['creditmemo_id' => (int) $creditmemoId],
                $e
            );
        }

        if (!$verification['matches'] && $transactionOpen) {
            $this->rollBackQuietly($connection, true);

            throw $this->conflictFactory->create(
                Contract::ERR_POST_COMMIT_MISMATCH,
                'The persisted credit memo total differs from the previewed total; '
                . 'the refund was rolled back and nothing was saved.',
                $verification['details']
            );
        }

        if ($transactionOpen) {
            try {
                $connection->commit();
            } catch (\Exception $e) {
                throw new IndeterminateRefundException(
                    __(
                        'The refund transaction failed to commit and its outcome is unknown. '
                        . 'Credit memo id %1 may or may not exist. Reconcile manually.',
                        $creditmemoId
                    ),
                    ['creditmemo_id' => (int) $creditmemoId],
                    $e
                );
            }
        }

        $warnings = array_values($built['warnings']);
        if (!$verification['matches']) {
            // Online path only: the gateway was already called, rolling the
            // database back would leave refunded money with no credit memo.
            $this->logger->critical(
                'Emipro_Apichange: online refund total mismatch on creditmemo '
                . $creditmemoId . ' ' . json_encode($verification['details'])
            );
            $warnings[] = 'POST_COMMIT_MISMATCH: the persisted total differs from the previewed total. '
                . 'The refund was NOT rolled back because it was an online refund. Reconcile manually.';
        }

        return $this->buildResult(
            $creditmemoId,
            $request,
            $snapshot,
            $source,
            $isOnline,
            $atomicRollback,
            $lockName,
            $warnings,
            $verification
        );
    }

    /**
     * Roll back without ever masking the exception that is already travelling.
     *
     * @param \Magento\Framework\DB\Adapter\AdapterInterface $connection
     * @param bool $transactionOpen
     * @return void
     */
    private function rollBackQuietly($connection, $transactionOpen)
    {
        if (!$transactionOpen) {
            return;
        }

        try {
            $connection->rollBack();
        } catch (\Exception $rollbackError) {
            $this->logger->critical(
                'Emipro_Apichange: refund rollback failed: ' . $rollbackError->getMessage()
            );
        }
    }

    /**
     * Read the credit memo back from the database and compare it to the signed
     * preview totals.
     *
     * The read goes through PersistedCreditmemoReader, i.e. a plain SELECT on
     * the sales connection, and not through CreditmemoRepositoryInterface: the
     * repository would answer from the registry the refund service just filled,
     * and this check would then be comparing our own computation with itself.
     *
     * @param int $creditmemoId
     * @param array $signedClaims
     * @return array{matches: bool, row: array, details: array}
     * @throws NoSuchEntityException
     */
    private function verifyPersistedTotals($creditmemoId, array $signedClaims)
    {
        $row = $this->creditmemoReader->read((int) $creditmemoId);
        if ($row === null) {
            // The native service returned an id for a row that cannot be read
            // back. Treated exactly like any other verification failure: rolled
            // back offline, reported as indeterminate online.
            throw new NoSuchEntityException(
                __('Credit memo %1 could not be read back from the database.', (int) $creditmemoId)
            );
        }

        $baseGrandTotal = $this->canonicalizer->scalar((float) $row['base_grand_total']);
        $grandTotal = $this->canonicalizer->scalar((float) $row['grand_total']);

        $expectedBase = isset($signedClaims[RefundGuard::CLAIM_BASE_GRAND_TOTAL])
            ? (string) $signedClaims[RefundGuard::CLAIM_BASE_GRAND_TOTAL]
            : '';
        $expectedOrder = isset($signedClaims[RefundGuard::CLAIM_GRAND_TOTAL])
            ? (string) $signedClaims[RefundGuard::CLAIM_GRAND_TOTAL]
            : '';

        $matches = hash_equals($expectedBase, $baseGrandTotal) && hash_equals($expectedOrder, $grandTotal);

        return [
            'matches' => $matches,
            'row' => $row,
            'details' => [
                'creditmemo_id' => (int) $creditmemoId,
                'previewed_base_grand_total' => $expectedBase,
                'persisted_base_grand_total' => $baseGrandTotal,
                'previewed_grand_total' => $expectedOrder,
                'persisted_grand_total' => $grandTotal,
            ],
        ];
    }

    /**
     * @param int $creditmemoId
     * @param RefundRequestInterface $request
     * @param array $snapshot
     * @param string $source
     * @param bool $isOnline
     * @param bool $atomicRollback
     * @param string $lockName
     * @param string[] $warnings
     * @param array $verification
     * @return array
     */
    private function buildResult(
        $creditmemoId,
        RefundRequestInterface $request,
        array $snapshot,
        $source,
        $isOnline,
        $atomicRollback,
        $lockName,
        array $warnings,
        array $verification
    ) {
        $row = $verification['row'];

        return [
            'contract_version' => Contract::CONTRACT_VERSION,
            'status' => 'refunded',
            'source' => $source,
            'is_online' => (bool) $isOnline,
            'order_id' => $request->getOrderId(),
            'invoice_id' => $request->getInvoiceId(),
            'creditmemo_id' => (int) $creditmemoId,
            'creditmemo_increment_id' => !isset($row['increment_id']) || $row['increment_id'] === null
                ? null
                : (string) $row['increment_id'],
            'payload_hash' => $snapshot['payload_hash'],
            'state_fingerprint' => $snapshot['state_fingerprint'],
            'totals' => $snapshot['contract']['totals'],
            'items' => $snapshot['contract']['items'],
            'currency' => $snapshot['contract']['currency'],
            'guard' => [
                'payload_verified' => true,
                'state_verified' => true,
                'total_verified_before_write' => true,
                'total_verified_after_write' => (bool) $verification['matches'],
                'lock_name' => $lockName,
                'rolled_back_on_mismatch' => (bool) $atomicRollback,
                'notification_sent' => false,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @param RefundRequestInterface $request
     * @return \Magento\Sales\Api\Data\CreditmemoItemCreationInterface[]
     */
    private function buildCreationItems(RefundRequestInterface $request)
    {
        $qtys = [];
        foreach ((array) $request->getItems() as $item) {
            $itemId = (int) $item->getOrderItemId();
            $qty = (float) $item->getQty();
            $qtys[$itemId] = isset($qtys[$itemId]) ? $qtys[$itemId] + $qty : $qty;
        }
        ksort($qtys, SORT_NUMERIC);

        $creationItems = [];
        foreach ($qtys as $itemId => $qty) {
            $creationItem = $this->itemCreationFactory->create();
            $creationItem->setOrderItemId($itemId);
            $creationItem->setQty($qty);
            $creationItems[] = $creationItem;
        }

        return $creationItems;
    }

    /**
     * @param RefundRequestInterface $request
     * @return \Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface|null
     */
    private function buildArguments(RefundRequestInterface $request)
    {
        if ($request->getShippingAmount() === null
            && $request->getAdjustmentPositive() === null
            && $request->getAdjustmentNegative() === null
        ) {
            return null;
        }

        $arguments = $this->argumentsFactory->create();
        if ($request->getShippingAmount() !== null) {
            $arguments->setShippingAmount($request->getShippingAmount());
        }
        if ($request->getAdjustmentPositive() !== null) {
            $arguments->setAdjustmentPositive($request->getAdjustmentPositive());
        }
        if ($request->getAdjustmentNegative() !== null) {
            $arguments->setAdjustmentNegative($request->getAdjustmentNegative());
        }

        return $arguments;
    }

    /**
     * @param RefundRequestInterface $request
     * @return \Magento\Sales\Api\Data\CreditmemoCommentCreationInterface|null
     * @throws InputException
     */
    private function buildComment(RefundRequestInterface $request)
    {
        $text = $request->getComment();
        if ($text === null) {
            return null;
        }
        if (strlen($text) > 60000) {
            throw new InputException(__('comment is too long.'));
        }

        $comment = $this->commentFactory->create();
        $comment->setComment($text);
        // Never surfaced to the customer: this endpoint is a back office
        // integration, not a customer facing action. Customer notification is
        // separately suppressed by passing $notify = false to the native
        // refund services; CreditmemoCommentCreationInterface itself exposes no
        // notification flag.
        $comment->setIsVisibleOnFront(0);

        return $comment;
    }
}
