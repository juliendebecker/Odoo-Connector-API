<?php

namespace Emipro\Apichange\Model\Refund;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Durable idempotency ledger.
 *
 * The atomic primitive is the UNIQUE index on idempotency_key, not the
 * application lock: two commits racing on the same key both attempt the INSERT
 * and MySQL lets exactly one through. That guarantee holds across processes,
 * across PHP-FPM pools and across web nodes.
 *
 * Ordering rule, which the caller must respect: claim() has to run BEFORE any
 * refund transaction is opened, and complete()/fail() AFTER it is closed.
 * Otherwise the ledger row shares the fate of the refund transaction and stops
 * being a ledger.
 *
 * Status transitions out of the caller's control:
 *  - `failed` means a previous attempt provably committed nothing, so the same
 *    key with the same payload takes it over immediately;
 *  - `in_progress` normally blocks, because the process holding it may still be
 *    working. It is taken over only once it has been untouched for longer than
 *    the TTL the caller passes to claim(), and the caller is then told the
 *    claim was `recovered` so it never records a plain `failed` for it;
 *  - `completed` and `indeterminate` are terminal here. Nothing in this class
 *    ever moves a refund out of `indeterminate`: nobody knows whether it moved
 *    money, and age does not change that.
 *
 * Known limitation: if another component already holds an open transaction on
 * the default connection when claim() runs, the INSERT is swallowed by that
 * transaction and durability degrades to that transaction's outcome. Magento
 * does not expose a second connection to the same database, so this cannot be
 * fixed from inside a module.
 */
class IdempotencyStore
{
    const TABLE = 'emipro_apichange_refund_idempotency';

    /**#@+
     * Ledger statuses.
     */
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_INDETERMINATE = 'indeterminate';
    /**#@-*/

    /**
     * Idempotency keys are opaque to Magento but must stay index friendly.
     */
    const MAX_KEY_LENGTH = 128;

    /**
     * @var ResourceConnection
     */
    private $resourceConnection;

    /**
     * @var SerializerInterface
     */
    private $json;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @param ResourceConnection $resourceConnection
     * @param SerializerInterface $json
     * @param DateTime $dateTime
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        SerializerInterface $json,
        DateTime $dateTime
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->json = $json;
        $this->dateTime = $dateTime;
    }

    /**
     * Reject keys that cannot be stored losslessly rather than truncating them,
     * which would silently merge two distinct refunds into one.
     *
     * @param string $key
     * @return string
     * @throws InputException
     */
    public function normalizeKey($key)
    {
        $key = trim((string) $key);
        if ($key === '') {
            throw new InputException(__('idempotency_key must not be empty.'));
        }
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new InputException(
                __('idempotency_key must be at most %1 bytes.', self::MAX_KEY_LENGTH)
            );
        }
        if (preg_match('/[^\x21-\x7E]/', $key)) {
            throw new InputException(
                __('idempotency_key must only contain printable ASCII characters without spaces.')
            );
        }

        return $key;
    }

    /**
     * Try to take ownership of a key.
     *
     * @param string $key
     * @param string $source
     * @param int|null $orderId
     * @param int|null $invoiceId
     * @param string $payloadHash
     * @param string $stateFingerprint
     * @param int $inProgressTtl Age, in seconds, at which an `in_progress` row
     *        left behind by a dead process may be taken over. 0 disables that
     *        takeover entirely.
     * @return array{claimed: bool, row: array|null, recovered: bool} `claimed`
     *         is true when this process owns the key and must proceed with the
     *         refund. `recovered` is true only when ownership was taken from an
     *         abandoned `in_progress` row, whose outcome is by definition
     *         unknown - the caller must not record a plain `failed` afterwards.
     */
    public function claim($key, $source, $orderId, $invoiceId, $payloadHash, $stateFingerprint, $inProgressTtl = 0)
    {
        $connection = $this->resourceConnection->getConnection();
        $table = $this->resourceConnection->getTableName(self::TABLE);
        $now = $this->now();

        try {
            $connection->insert($table, [
                'idempotency_key' => $key,
                'source' => $source,
                'order_id' => $orderId,
                'invoice_id' => $invoiceId,
                'payload_hash' => $payloadHash,
                'state_fingerprint' => $stateFingerprint,
                'status' => self::STATUS_IN_PROGRESS,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['claimed' => true, 'row' => null, 'recovered' => false];
        } catch (\Exception $insertError) {
            // Any driver, any Magento version: if the row is now visible the
            // failure was the unique index doing its job, otherwise rethrow.
            $row = $this->find($key);
            if ($row === null) {
                throw $insertError;
            }

            // A takeover is only ever considered for the exact same refund.
            // The payload hash already covers the source and the identifiers,
            // the explicit source comparison only makes that intent readable.
            $sameRefund = hash_equals((string) $row['payload_hash'], (string) $payloadHash)
                && (string) $row['source'] === (string) $source;

            if ($sameRefund && $row['status'] === self::STATUS_FAILED) {
                // A previous attempt is known not to have committed anything,
                // so retrying under the same key is safe.
                if ($this->takeOver($table, $key, $stateFingerprint, $now, self::STATUS_FAILED, null)) {
                    return ['claimed' => true, 'row' => null, 'recovered' => false];
                }

                $row = $this->find($key);
            } elseif ($sameRefund
                && $row['status'] === self::STATUS_IN_PROGRESS
                && (int) $inProgressTtl > 0
            ) {
                // Nothing here proves the abandoned attempt committed nothing;
                // it only proves nobody has touched the row for longer than any
                // refund can plausibly take. The retry is therefore allowed to
                // run - under the per order lock, and with the state
                // fingerprint still standing between it and a second credit
                // memo - but the caller is told it was a recovery so that a
                // failure is never recorded as a plain `failed`.
                if ($this->takeOver(
                    $table,
                    $key,
                    $stateFingerprint,
                    $now,
                    self::STATUS_IN_PROGRESS,
                    $this->stamp(-(int) $inProgressTtl)
                )) {
                    return ['claimed' => true, 'row' => null, 'recovered' => true];
                }

                $row = $this->find($key);
            }

            return ['claimed' => false, 'row' => $row, 'recovered' => false];
        }
    }

    /**
     * Compare and swap a ledger row into `in_progress`.
     *
     * The UPDATE, not the SELECT that preceded it, is what decides: two
     * processes reaching the same conclusion about the same row both issue it
     * and MySQL lets exactly one match.
     *
     * @param string $table
     * @param string $key
     * @param string $stateFingerprint
     * @param string $now
     * @param string $fromStatus Status the row must still be in.
     * @param string|null $staleBefore When set, the row must also not have been
     *        touched since that instant.
     * @return bool
     */
    private function takeOver($table, $key, $stateFingerprint, $now, $fromStatus, $staleBefore)
    {
        $where = [
            'idempotency_key = ?' => $key,
            'status = ?' => $fromStatus,
        ];

        if ($staleBefore !== null) {
            // Age is part of the swap, not just of the decision: a row
            // refreshed between the read and this UPDATE belongs to a process
            // that is demonstrably alive and must not be robbed.
            $where['updated_at <= ?'] = $staleBefore;
        }

        $updated = $this->resourceConnection->getConnection()->update(
            $table,
            [
                'status' => self::STATUS_IN_PROGRESS,
                'state_fingerprint' => $stateFingerprint,
                'error_message' => null,
                'updated_at' => $now,
            ],
            $where
        );

        return (int) $updated === 1;
    }

    /**
     * @param string $key
     * @param int|null $creditmemoId
     * @param string|null $creditmemoIncrementId
     * @param array $response
     * @return void
     */
    public function complete($key, $creditmemoId, $creditmemoIncrementId, array $response)
    {
        $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName(self::TABLE),
            [
                'status' => self::STATUS_COMPLETED,
                'creditmemo_id' => $creditmemoId,
                'creditmemo_increment_id' => $creditmemoIncrementId,
                'response_json' => $this->json->serialize($response),
                'error_message' => null,
                'updated_at' => $this->now(),
            ],
            ['idempotency_key = ?' => $key]
        );
    }

    /**
     * @param string $key
     * @param string $status self::STATUS_FAILED when nothing was committed,
     *        self::STATUS_INDETERMINATE when that cannot be established.
     * @param string $message
     * @return void
     */
    public function fail($key, $status, $message)
    {
        $this->resourceConnection->getConnection()->update(
            $this->resourceConnection->getTableName(self::TABLE),
            [
                'status' => $status,
                'error_message' => mb_substr((string) $message, 0, 2000),
                'updated_at' => $this->now(),
            ],
            ['idempotency_key = ?' => $key]
        );
    }

    /**
     * @param string $key
     * @return array|null
     */
    public function find($key)
    {
        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from($this->resourceConnection->getTableName(self::TABLE))
            ->where('idempotency_key = ?', $key)
            ->limit(1);

        $row = $connection->fetchRow($select);

        return $row === false || $row === null ? null : $row;
    }

    /**
     * Stored response of a completed refund, ready to be replayed.
     *
     * @param array $row
     * @return array|null
     */
    public function decodeResponse(array $row)
    {
        if (empty($row['response_json'])) {
            return null;
        }

        try {
            $decoded = $this->json->unserialize($row['response_json']);
        } catch (\InvalidArgumentException $e) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return string
     */
    private function now()
    {
        return $this->stamp(0);
    }

    /**
     * Every timestamp this class writes or compares is produced here, so that a
     * cutoff is expressed on exactly the same clock and in exactly the same
     * format as the values it is compared against.
     *
     * @param int $offsetSeconds Negative for a point in the past.
     * @return string
     */
    private function stamp($offsetSeconds)
    {
        return gmdate('Y-m-d H:i:s', (int) $this->dateTime->gmtTimestamp() + (int) $offsetSeconds);
    }
}
