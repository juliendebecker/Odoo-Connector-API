<?php

namespace Emipro\Apichange\Model;

use Emipro\Apichange\Api\Data\RefundRequestInterface;
use Emipro\Apichange\Api\RefundPreviewInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Web API boundary: preserve the associative refund contract as JSON.
 *
 * Magento flattens a service return annotated as mixed[] into a positional
 * array. Returning a JSON string is unambiguous and keeps every contract key.
 */
class RefundPreviewJsonAdapter implements RefundPreviewInterface
{
    private $preview;
    private $json;

    public function __construct(RefundPreview $preview, Json $json)
    {
        $this->preview = $preview;
        $this->json = $json;
    }

    public function previewOrder(RefundRequestInterface $request)
    {
        return $this->json->serialize($this->preview->previewOrder($request));
    }

    public function previewInvoice(RefundRequestInterface $request)
    {
        return $this->json->serialize($this->preview->previewInvoice($request));
    }
}

