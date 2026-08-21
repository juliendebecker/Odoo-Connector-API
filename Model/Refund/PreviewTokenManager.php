<?php

namespace Emipro\Apichange\Model\Refund;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Config\ConfigOptionsListConstants;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Webapi\Exception as WebapiException;

/**
 * Issues and verifies the stateless preview token.
 *
 * The token is a signed statement, not a capability: it proves that this
 * installation produced a given (payload_hash, state_fingerprint, total) tuple
 * at a given time. It grants nothing on its own - the commit endpoint still
 * recomputes every claim against live data before refunding anything.
 *
 * Signing key: HMAC-SHA256 keyed on a domain separated derivation of the
 * Magento crypt key. Rotating crypt/key therefore invalidates in flight
 * previews, which is the desired behaviour.
 */
class PreviewTokenManager
{
    /**
     * Domain separation string, so that this HMAC key can never be confused
     * with another use of the same crypt key.
     */
    const KEY_DOMAIN = 'Emipro\Apichange\Refund\PreviewToken\v1';

    /**
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * @var ConflictFactory
     */
    private $conflictFactory;

    /**
     * @var Canonicalizer
     */
    private $canonicalizer;

    /**
     * @var DateTime
     */
    private $dateTime;

    /**
     * @var string|null
     */
    private $signingKey;

    /**
     * @param DeploymentConfig $deploymentConfig
     * @param Canonicalizer $canonicalizer
     * @param DateTime $dateTime
     * @param ConflictFactory $conflictFactory
     */
    public function __construct(
        DeploymentConfig $deploymentConfig,
        Canonicalizer $canonicalizer,
        DateTime $dateTime,
        ConflictFactory $conflictFactory
    ) {
        $this->deploymentConfig = $deploymentConfig;
        $this->canonicalizer = $canonicalizer;
        $this->dateTime = $dateTime;
        $this->conflictFactory = $conflictFactory;
    }

    /**
     * Build a signed token out of the preview claims.
     *
     * @param array $claims
     * @param int $ttl
     * @return array [token, issuedAt, expiresAt]
     * @throws LocalizedException
     */
    public function issue(array $claims, $ttl)
    {
        $issuedAt = (int) $this->dateTime->gmtTimestamp();
        $expiresAt = $issuedAt + max(1, (int) $ttl);

        // Stored as strings: the canonicaliser normalises every number to a
        // fixed scale decimal, which is right for money and wrong for a clock.
        $claims['iat'] = (string) $issuedAt;
        $claims['exp'] = (string) $expiresAt;
        $claims['v'] = Contract::CONTRACT_VERSION;

        $body = $this->canonicalizer->encode($claims);
        $encodedBody = $this->base64UrlEncode($body);
        $signature = $this->base64UrlEncode($this->sign($encodedBody));

        return [
            Contract::TOKEN_VERSION . '.' . $encodedBody . '.' . $signature,
            $issuedAt,
            $expiresAt,
        ];
    }

    /**
     * Verify signature and expiry, and return the claims.
     *
     * @param string|null $token
     * @return array
     * @throws WebapiException
     * @throws LocalizedException
     */
    public function verify($token)
    {
        if ($token === null || $token === '') {
            throw $this->reject(Contract::ERR_PREVIEW_TOKEN_MISSING, 'preview_token is required.');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== Contract::TOKEN_VERSION) {
            throw $this->reject(Contract::ERR_PREVIEW_TOKEN_INVALID, 'preview_token is malformed.');
        }

        $expected = $this->sign($parts[1]);
        $provided = $this->base64UrlDecode($parts[2]);
        if ($provided === false || !hash_equals($expected, $provided)) {
            throw $this->reject(Contract::ERR_PREVIEW_TOKEN_INVALID, 'preview_token signature is invalid.');
        }

        $body = $this->base64UrlDecode($parts[1]);
        $claims = $body === false ? null : json_decode($body, true);
        if (!is_array($claims)) {
            throw $this->reject(Contract::ERR_PREVIEW_TOKEN_INVALID, 'preview_token payload is unreadable.');
        }

        if (!isset($claims['v']) || $claims['v'] !== Contract::CONTRACT_VERSION) {
            throw $this->reject(
                Contract::ERR_CONTRACT_VERSION_MISMATCH,
                'preview_token was issued for another contract version.'
            );
        }

        $now = (int) $this->dateTime->gmtTimestamp();
        if (!isset($claims['exp']) || $now > (int) $claims['exp']) {
            throw $this->reject(Contract::ERR_PREVIEW_EXPIRED, 'preview_token has expired, run a new preview.');
        }

        return $claims;
    }

    /**
     * @param string $data
     * @return string Raw binary HMAC.
     * @throws LocalizedException
     */
    private function sign($data)
    {
        return hash_hmac('sha256', $data, $this->getSigningKey(), true);
    }

    /**
     * @return string
     * @throws LocalizedException
     */
    private function getSigningKey()
    {
        if ($this->signingKey !== null) {
            return $this->signingKey;
        }

        $cryptKey = (string) $this->deploymentConfig->get(
            ConfigOptionsListConstants::CONFIG_PATH_CRYPT_KEY
        );

        // env.php may hold several newline separated keys after a rotation;
        // the last one is the active key.
        $keys = array_values(array_filter(array_map('trim', explode("\n", $cryptKey)), 'strlen'));
        if (empty($keys)) {
            throw new LocalizedException(
                __('Cannot sign refund previews: the Magento encryption key is not configured.')
            );
        }

        $this->signingKey = hash_hmac('sha256', self::KEY_DOMAIN, end($keys), true);

        return $this->signingKey;
    }

    /**
     * @param string $code
     * @param string $message
     * @return WebapiException
     */
    private function reject($code, $message)
    {
        return $this->conflictFactory->create($code, $message);
    }

    /**
     * @param string $data
     * @return string
     */
    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @param string $data
     * @return string|false
     */
    private function base64UrlDecode($data)
    {
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
