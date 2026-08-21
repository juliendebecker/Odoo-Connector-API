<?php

namespace Emipro\Apichange\Test\Unit\Model\Refund;

use Emipro\Apichange\Model\Refund\Canonicalizer;
use Emipro\Apichange\Model\Refund\ConflictFactory;
use Emipro\Apichange\Model\Refund\Contract;
use Emipro\Apichange\Model\Refund\PreviewTokenManager;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\Webapi\Exception as WebapiException;
use PHPUnit\Framework\TestCase;

class PreviewTokenManagerTest extends TestCase
{
    /**
     * @var DateTime|\PHPUnit\Framework\MockObject\MockObject
     */
    private $dateTime;

    /**
     * @var int
     */
    private $now = 1700000000;

    protected function setUp(): void
    {
        $this->dateTime = $this->createMock(DateTime::class);
        $this->dateTime->method('gmtTimestamp')->willReturnCallback(function () {
            return $this->now;
        });
    }

    /**
     * @param string $cryptKey
     * @return PreviewTokenManager
     */
    private function manager($cryptKey = 'unit-test-crypt-key')
    {
        $deploymentConfig = $this->createMock(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturn($cryptKey);

        return new PreviewTokenManager(
            $deploymentConfig,
            new Canonicalizer(),
            $this->dateTime,
            new ConflictFactory()
        );
    }

    public function testRoundTrip()
    {
        $manager = $this->manager();
        list($token, $issuedAt, $expiresAt) = $manager->issue(['ph' => 'abc'], 900);

        $this->assertSame($this->now, $issuedAt);
        $this->assertSame($this->now + 900, $expiresAt);
        $this->assertStringStartsWith(Contract::TOKEN_VERSION . '.', $token);

        $claims = $manager->verify($token);
        $this->assertSame('abc', $claims['ph']);
        $this->assertSame(Contract::CONTRACT_VERSION, $claims['v']);
    }

    public function testTokenIsUrlSafe()
    {
        list($token) = $this->manager()->issue(['ph' => 'a/b+c=d'], 900);

        $this->assertSame($token, rawurlencode($token));
    }

    public function testTamperedBodyIsRejected()
    {
        $manager = $this->manager();
        list($token) = $manager->issue(['ph' => 'abc', 'bgt' => '10.0000'], 900);

        $parts = explode('.', $token);
        $forgedBody = rtrim(strtr(base64_encode('{"bgt":"9999.0000","ph":"abc","v":"1.0.0"}'), '+/', '-_'), '=');
        $forged = $parts[0] . '.' . $forgedBody . '.' . $parts[2];

        $this->expectException(WebapiException::class);
        $this->expectExceptionMessageMatches('/' . Contract::ERR_PREVIEW_TOKEN_INVALID . '/');
        $manager->verify($forged);
    }

    public function testTokenSignedWithAnotherKeyIsRejected()
    {
        list($token) = $this->manager('key-a')->issue(['ph' => 'abc'], 900);

        $this->expectException(WebapiException::class);
        $this->expectExceptionMessageMatches('/' . Contract::ERR_PREVIEW_TOKEN_INVALID . '/');
        $this->manager('key-b')->verify($token);
    }

    /**
     * After a crypt key rotation env.php holds several newline separated keys.
     * The active key is the last one, so a token signed before the rotation
     * must stop verifying rather than silently keep working.
     */
    public function testOnlyTheActiveCryptKeyIsUsed()
    {
        list($token) = $this->manager("old-key")->issue(['ph' => 'abc'], 900);

        $rotated = $this->manager("old-key\nnew-key");
        $this->expectException(WebapiException::class);
        $rotated->verify($token);
    }

    public function testExpiredTokenIsRejected()
    {
        $manager = $this->manager();
        list($token) = $manager->issue(['ph' => 'abc'], 60);

        $this->now += 61;

        $this->expectException(WebapiException::class);
        $this->expectExceptionMessageMatches('/' . Contract::ERR_PREVIEW_EXPIRED . '/');
        $manager->verify($token);
    }

    public function testTokenIsStillValidOnItsLastSecond()
    {
        $manager = $this->manager();
        list($token) = $manager->issue(['ph' => 'abc'], 60);

        $this->now += 60;

        $claims = $manager->verify($token);
        $this->assertSame('abc', $claims['ph']);
    }

    public function testMissingTokenIsRejected()
    {
        $this->expectException(WebapiException::class);
        $this->expectExceptionMessageMatches('/' . Contract::ERR_PREVIEW_TOKEN_MISSING . '/');
        $this->manager()->verify(null);
    }

    /**
     * @dataProvider malformedTokenProvider
     * @param string $token
     */
    public function testMalformedTokensAreRejected($token)
    {
        $this->expectException(WebapiException::class);
        $this->manager()->verify($token);
    }

    /**
     * @return array
     */
    public function malformedTokenProvider()
    {
        return [
            'no separator' => ['garbage'],
            'two parts' => ['v1.abc'],
            'four parts' => ['v1.a.b.c'],
            'wrong version prefix' => ['v0.a.b'],
            'non base64 signature' => ['v1.YWJj.!!!!'],
        ];
    }

    public function testRejectionsAreConflictsNotServerErrors()
    {
        try {
            $this->manager()->verify('v1.a.b');
            $this->fail('Expected a rejection.');
        } catch (WebapiException $e) {
            $this->assertSame(Contract::HTTP_CONFLICT, $e->getHttpCode());
        }
    }
}
