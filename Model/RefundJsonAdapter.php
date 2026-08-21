<?php

namespace Emipro\Apichange\Model;

use Emipro\Apichange\Api\Data\RefundRequestInterface;
use Emipro\Apichange\Api\RefundInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Web API boundary for the guarded commit response.
 */
class RefundJsonAdapter implements RefundInterface
{
    private $refund;
    private $json;

    public function __construct(RefundCommit $refund, Json $json)
    {
        $this->refund = $refund;
        $this->json = $json;
    }

    public function execute(RefundRequestInterface $request)
    {
        return $this->json->serialize($this->refund->execute($request));
    }
}

