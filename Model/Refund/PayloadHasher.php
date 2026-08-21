<?php

namespace Emipro\Apichange\Model\Refund;

use Emipro\Apichange\Api\Data\RefundRequestInterface;

/**
 * Hashes the caller controlled part of a refund request.
 *
 * Deliberately excluded from the hash, because they cannot change a single
 * cent of the resulting credit memo:
 *  - preview_token (it is derived from this very hash)
 *  - idempotency_key (the same payload must be replayable under a new key)
 *  - comment (free text, never affects totals)
 */
class PayloadHasher
{
    /**
     * @var Canonicalizer
     */
    private $canonicalizer;

    /**
     * @param Canonicalizer $canonicalizer
     */
    public function __construct(Canonicalizer $canonicalizer)
    {
        $this->canonicalizer = $canonicalizer;
    }

    /**
     * @param RefundRequestInterface $request
     * @param string $source Contract::SOURCE_ORDER|Contract::SOURCE_INVOICE
     * @return string
     */
    public function hash(RefundRequestInterface $request, $source)
    {
        return $this->canonicalizer->hash($this->normalize($request, $source));
    }

    /**
     * Normalised, order independent view of the request.
     *
     * @param RefundRequestInterface $request
     * @param string $source
     * @return array
     */
    public function normalize(RefundRequestInterface $request, $source)
    {
        return [
            'contract_version' => Contract::CONTRACT_VERSION,
            'source' => $source,
            // Identifiers are hashed as strings, never as fixed scale numbers:
            // they are opaque keys, not amounts.
            'order_id' => $request->getOrderId() === null ? null : (string) $request->getOrderId(),
            'invoice_id' => $request->getInvoiceId() === null ? null : (string) $request->getInvoiceId(),
            'items' => $this->normalizeItems($request),
            'shipping_amount' => $this->canonicalizer->nullableScalar($request->getShippingAmount()),
            'adjustment_positive' => $this->canonicalizer->nullableScalar($request->getAdjustmentPositive()),
            'adjustment_negative' => $this->canonicalizer->nullableScalar($request->getAdjustmentNegative()),
            'is_online' => $request->getIsOnline(),
        ];
    }

    /**
     * Requested lines, deduplicated and sorted by order item id.
     *
     * Two lines targeting the same order item are summed, so that
     * [{1, 1}, {1, 1}] and [{1, 2}] hash identically - they also produce the
     * same credit memo, because Magento's qtys map is keyed by item id.
     *
     * @param RefundRequestInterface $request
     * @return array
     */
    private function normalizeItems(RefundRequestInterface $request)
    {
        $qtys = [];
        foreach ((array) $request->getItems() as $item) {
            $itemId = (int) $item->getOrderItemId();
            $qty = (float) $item->getQty();
            $qtys[$itemId] = isset($qtys[$itemId]) ? $qtys[$itemId] + $qty : $qty;
        }

        ksort($qtys, SORT_NUMERIC);

        $normalized = [];
        foreach ($qtys as $itemId => $qty) {
            $normalized[] = [
                'order_item_id' => (string) $itemId,
                'qty' => $this->canonicalizer->scalar($qty),
            ];
        }

        return $normalized;
    }
}
