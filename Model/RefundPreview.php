<?php

namespace Emipro\Apichange\Model;

use Emipro\Apichange\Api\Data\RefundRequestInterface;
use Emipro\Apichange\Api\RefundPreviewInterface;
use Emipro\Apichange\Model\Refund\Config;
use Emipro\Apichange\Model\Refund\Contract;
use Emipro\Apichange\Model\Refund\CreditmemoPreviewBuilder;
use Emipro\Apichange\Model\Refund\PreviewTokenManager;
use Emipro\Apichange\Model\Refund\SnapshotBuilder;

/**
 * Read-only refund simulation endpoint.
 *
 * @see \Emipro\Apichange\Api\RefundPreviewInterface
 */
class RefundPreview implements RefundPreviewInterface
{
    /**
     * @var CreditmemoPreviewBuilder
     */
    private $previewBuilder;

    /**
     * @var SnapshotBuilder
     */
    private $snapshotBuilder;

    /**
     * @var PreviewTokenManager
     */
    private $tokenManager;

    /**
     * @var Config
     */
    private $config;

    /**
     * @param CreditmemoPreviewBuilder $previewBuilder
     * @param SnapshotBuilder $snapshotBuilder
     * @param PreviewTokenManager $tokenManager
     * @param Config $config
     */
    public function __construct(
        CreditmemoPreviewBuilder $previewBuilder,
        SnapshotBuilder $snapshotBuilder,
        PreviewTokenManager $tokenManager,
        Config $config
    ) {
        $this->previewBuilder = $previewBuilder;
        $this->snapshotBuilder = $snapshotBuilder;
        $this->tokenManager = $tokenManager;
        $this->config = $config;
    }

    /**
     * @param RefundRequestInterface $request
     * @return mixed[]
     */
    public function previewOrder(RefundRequestInterface $request)
    {
        return $this->preview($request, Contract::SOURCE_ORDER);
    }

    /**
     * @param RefundRequestInterface $request
     * @return mixed[]
     */
    public function previewInvoice(RefundRequestInterface $request)
    {
        return $this->preview($request, Contract::SOURCE_INVOICE);
    }

    /**
     * @param RefundRequestInterface $request
     * @param string $source
     * @return array
     */
    private function preview(RefundRequestInterface $request, $source)
    {
        $built = $source === Contract::SOURCE_INVOICE
            ? $this->previewBuilder->buildFromInvoice($request)
            : $this->previewBuilder->buildFromOrder($request);

        $snapshot = $this->snapshotBuilder->build($built, $request, $source);
        $ttl = $this->config->getPreviewTtl($built['order']->getStoreId());
        list($token, $issuedAt, $expiresAt) = $this->tokenManager->issue($snapshot['claims'], $ttl);

        $contract = $snapshot['contract'];
        $contract['payload_hash'] = $snapshot['payload_hash'];
        $contract['state_fingerprint'] = $snapshot['state_fingerprint'];
        $contract['preview_token'] = $token;
        $contract['preview_issued_at'] = gmdate('c', $issuedAt);
        $contract['preview_expires_at'] = gmdate('c', $expiresAt);
        $contract['warnings'] = array_values($built['warnings']);
        $contract['engine'] = $this->describeEngine($source);

        return $contract;
    }

    /**
     * Explicit statement of what the preview did and did not do.
     *
     * Shipped inside the contract on purpose: an integrator must be able to
     * assert these properties from the response itself, not from documentation
     * that may drift.
     *
     * @param string $source
     * @return array
     */
    private function describeEngine($source)
    {
        return [
            'builder' => $source === Contract::SOURCE_INVOICE
                ? 'Magento\Sales\Model\Order\CreditmemoFactory::createByInvoice'
                : 'Magento\Sales\Model\Order\CreditmemoFactory::createByOrder',
            'totals_collected' => true,
            'registered' => false,
            'persisted' => false,
            'payment_touched' => false,
            'notification_sent' => false,
        ];
    }
}
