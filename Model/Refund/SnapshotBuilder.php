<?php

namespace Emipro\Apichange\Model\Refund;

use Emipro\Apichange\Api\Data\RefundRequestInterface;

/**
 * Turns a built (but unsaved) credit memo into the contract, the two hashes and
 * the claim set.
 *
 * Both the preview endpoint and the commit endpoint go through this single
 * class. That is the whole point: if the preview derived its claims here and
 * the commit derived them somewhere else, the guard would be comparing two
 * subtly different definitions and would eventually pass something it should
 * have refused.
 */
class SnapshotBuilder
{
    /**
     * @var ContractSerializer
     */
    private $contractSerializer;

    /**
     * @var PayloadHasher
     */
    private $payloadHasher;

    /**
     * @var StateFingerprint
     */
    private $stateFingerprint;

    /**
     * @var RefundGuard
     */
    private $refundGuard;

    /**
     * @var Canonicalizer
     */
    private $canonicalizer;

    /**
     * @param ContractSerializer $contractSerializer
     * @param PayloadHasher $payloadHasher
     * @param StateFingerprint $stateFingerprint
     * @param RefundGuard $refundGuard
     * @param Canonicalizer $canonicalizer
     */
    public function __construct(
        ContractSerializer $contractSerializer,
        PayloadHasher $payloadHasher,
        StateFingerprint $stateFingerprint,
        RefundGuard $refundGuard,
        Canonicalizer $canonicalizer
    ) {
        $this->contractSerializer = $contractSerializer;
        $this->payloadHasher = $payloadHasher;
        $this->stateFingerprint = $stateFingerprint;
        $this->refundGuard = $refundGuard;
        $this->canonicalizer = $canonicalizer;
    }

    /**
     * @param array $built Output of CreditmemoPreviewBuilder.
     * @param RefundRequestInterface $request
     * @param string $source
     * @return array{contract: array, payload_hash: string, state_fingerprint: string, claims: array}
     */
    public function build(array $built, RefundRequestInterface $request, $source)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $built['order'];
        /** @var \Magento\Sales\Model\Order\Invoice|null $invoice */
        $invoice = $built['invoice'];
        /** @var \Magento\Sales\Model\Order\Creditmemo $creditmemo */
        $creditmemo = $built['creditmemo'];

        // is_online is only meaningful on the invoice path: Magento has no
        // online refund entry point that takes an order.
        $isOnline = $source === Contract::SOURCE_INVOICE && (bool) $request->getIsOnline();

        $contract = $this->contractSerializer->serialize($creditmemo, $order, $invoice, $source, $isOnline);
        $payloadHash = $this->payloadHasher->hash($request, $source);
        $stateFingerprint = $this->stateFingerprint->fingerprint($order, $invoice);

        $claims = $this->refundGuard->buildClaims(
            $source,
            $order->getEntityId(),
            $invoice === null ? null : $invoice->getEntityId(),
            $order->getStoreId(),
            (string) $order->getBaseCurrencyCode(),
            $isOnline,
            $payloadHash,
            $stateFingerprint,
            $this->canonicalizer->scalar((float) $creditmemo->getBaseGrandTotal()),
            $this->canonicalizer->scalar((float) $creditmemo->getGrandTotal())
        );

        return [
            'contract' => $contract,
            'payload_hash' => $payloadHash,
            'state_fingerprint' => $stateFingerprint,
            'claims' => $claims,
        ];
    }
}
