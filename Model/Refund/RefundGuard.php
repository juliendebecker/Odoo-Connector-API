<?php

namespace Emipro\Apichange\Model\Refund;

use Magento\Framework\Webapi\Exception as WebapiException;

/**
 * Pure comparison between the claims signed into a preview token and the values
 * recomputed from live data at commit time.
 *
 * Deliberately free of any Magento service dependency so that the decision rule
 * is fully unit testable and cannot silently change because of a repository or
 * a collector.
 *
 * Order of the checks matters: the most specific and most actionable rejection
 * must win, so scope is compared before payload, payload before state, and
 * state before totals. A caller who resends the wrong order id gets
 * SCOPE_MISMATCH rather than a confusing TOTAL_MISMATCH.
 */
class RefundGuard
{
    /**
     * Claim keys carried by the preview token.
     */
    const CLAIM_SOURCE = 'src';
    const CLAIM_ORDER_ID = 'oid';
    const CLAIM_INVOICE_ID = 'iid';
    const CLAIM_STORE_ID = 'sid';
    const CLAIM_CURRENCY = 'cur';
    const CLAIM_IS_ONLINE = 'online';
    const CLAIM_PAYLOAD_HASH = 'ph';
    const CLAIM_STATE_FINGERPRINT = 'sf';
    const CLAIM_BASE_GRAND_TOTAL = 'bgt';
    const CLAIM_GRAND_TOTAL = 'gt';

    /**
     * @var ConflictFactory
     */
    private $conflictFactory;

    /**
     * @param ConflictFactory $conflictFactory
     */
    public function __construct(ConflictFactory $conflictFactory)
    {
        $this->conflictFactory = $conflictFactory;
    }

    /**
     * Build the claim set of a preview. Used both when issuing a token and when
     * re-deriving the expected claims at commit time, so the two can never
     * drift apart.
     *
     * @param string $source
     * @param int|null $orderId
     * @param int|null $invoiceId
     * @param int|null $storeId
     * @param string $baseCurrencyCode
     * @param bool $isOnline
     * @param string $payloadHash
     * @param string $stateFingerprint
     * @param string $baseGrandTotal Already canonicalised decimal string.
     * @param string $grandTotal Already canonicalised decimal string.
     * @return array
     */
    public function buildClaims(
        $source,
        $orderId,
        $invoiceId,
        $storeId,
        $baseCurrencyCode,
        $isOnline,
        $payloadHash,
        $stateFingerprint,
        $baseGrandTotal,
        $grandTotal
    ) {
        return [
            self::CLAIM_SOURCE => (string) $source,
            self::CLAIM_ORDER_ID => $orderId === null ? null : (string) $orderId,
            self::CLAIM_INVOICE_ID => $invoiceId === null ? null : (string) $invoiceId,
            self::CLAIM_STORE_ID => $storeId === null ? null : (string) $storeId,
            self::CLAIM_CURRENCY => (string) $baseCurrencyCode,
            self::CLAIM_IS_ONLINE => $isOnline ? 'true' : 'false',
            self::CLAIM_PAYLOAD_HASH => (string) $payloadHash,
            self::CLAIM_STATE_FINGERPRINT => (string) $stateFingerprint,
            self::CLAIM_BASE_GRAND_TOTAL => (string) $baseGrandTotal,
            self::CLAIM_GRAND_TOTAL => (string) $grandTotal,
        ];
    }

    /**
     * Compare signed claims against freshly recomputed ones.
     *
     * @param array $signed Claims decoded from the preview token.
     * @param array $current Claims rebuilt with buildClaims() from live data.
     * @return void
     * @throws WebapiException on any divergence.
     */
    public function assertMatches(array $signed, array $current)
    {
        $this->assertScope($signed, $current);

        $this->assertHash(
            $signed,
            $current,
            self::CLAIM_PAYLOAD_HASH,
            Contract::ERR_PAYLOAD_MISMATCH,
            'The refund payload sent at commit time differs from the one that was previewed.'
        );

        $this->assertHash(
            $signed,
            $current,
            self::CLAIM_STATE_FINGERPRINT,
            Contract::ERR_STATE_MISMATCH,
            'The order changed since the preview was issued. Run a new preview.'
        );

        foreach ([self::CLAIM_BASE_GRAND_TOTAL, self::CLAIM_GRAND_TOTAL] as $claim) {
            if (!$this->same($signed, $current, $claim)) {
                throw $this->conflictFactory->create(
                    Contract::ERR_TOTAL_MISMATCH,
                    'The recomputed credit memo total differs from the previewed total.',
                    [
                        'field' => $claim,
                        'previewed' => $this->claim($signed, $claim),
                        'current' => $this->claim($current, $claim),
                    ]
                );
            }
        }
    }

    /**
     * @param array $signed
     * @param array $current
     * @return void
     * @throws WebapiException
     */
    private function assertScope(array $signed, array $current)
    {
        $scopeClaims = [
            self::CLAIM_SOURCE,
            self::CLAIM_ORDER_ID,
            self::CLAIM_INVOICE_ID,
            self::CLAIM_STORE_ID,
            self::CLAIM_CURRENCY,
            self::CLAIM_IS_ONLINE,
        ];

        foreach ($scopeClaims as $claim) {
            if (!$this->same($signed, $current, $claim)) {
                throw $this->conflictFactory->create(
                    Contract::ERR_SCOPE_MISMATCH,
                    'The preview token was issued for a different refund scope.',
                    [
                        'field' => $claim,
                        'previewed' => $this->claim($signed, $claim),
                        'current' => $this->claim($current, $claim),
                    ]
                );
            }
        }
    }

    /**
     * @param array $signed
     * @param array $current
     * @param string $claim
     * @param string $errorCode
     * @param string $message
     * @return void
     * @throws WebapiException
     */
    private function assertHash(array $signed, array $current, $claim, $errorCode, $message)
    {
        $expected = $this->claim($signed, $claim);
        $actual = $this->claim($current, $claim);

        // hash_equals() rather than === : these values are attacker influenced
        // and the comparison result is observable through the HTTP response.
        if ($expected === null || $actual === null || !hash_equals($expected, $actual)) {
            throw $this->conflictFactory->create(
                $errorCode,
                $message,
                ['field' => $claim, 'previewed' => $expected, 'current' => $actual]
            );
        }
    }

    /**
     * @param array $signed
     * @param array $current
     * @param string $claim
     * @return bool
     */
    private function same(array $signed, array $current, $claim)
    {
        $expected = $this->claim($signed, $claim);
        $actual = $this->claim($current, $claim);

        if ($expected === null || $actual === null) {
            return $expected === $actual;
        }

        return hash_equals($expected, $actual);
    }

    /**
     * @param array $claims
     * @param string $key
     * @return string|null
     */
    private function claim(array $claims, $key)
    {
        if (!array_key_exists($key, $claims) || $claims[$key] === null) {
            return null;
        }

        return (string) $claims[$key];
    }
}
