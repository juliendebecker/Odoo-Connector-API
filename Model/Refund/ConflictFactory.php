<?php

namespace Emipro\Apichange\Model\Refund;

use Magento\Framework\Webapi\Exception as WebapiException;

/**
 * Single place where guard rejections are turned into HTTP responses.
 *
 * \Magento\Framework\Webapi\Exception is used because it is the only framework
 * exception that lets a service pick its own HTTP status; every rejection is a
 * 409 Conflict, never a 500, so Odoo can distinguish "refused, retry after a
 * new preview" from "Magento is broken".
 *
 * The machine readable code is repeated in the message body on purpose: the
 * `parameters` payload of the REST error envelope is not rendered identically
 * across Magento minor versions, the message always is.
 */
class ConflictFactory
{
    /**
     * @param string $code One of the Contract::ERR_* constants.
     * @param string $message Human readable, English, already interpolated.
     * @param array $details Extra diagnostic values, echoed back to the caller.
     * @return WebapiException
     */
    public function create($code, $message, array $details = [])
    {
        $details['error_code'] = $code;

        return new WebapiException(
            __('[%1] %2', $code, $message),
            0,
            Contract::HTTP_CONFLICT,
            $details
        );
    }
}
