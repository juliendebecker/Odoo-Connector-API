<?php

namespace Emipro\Apichange\Test\Unit\Model\Refund;

use Emipro\Apichange\Model\Refund\ConflictFactory;
use Emipro\Apichange\Model\Refund\Contract;
use Emipro\Apichange\Model\Refund\RefundGuard;
use Magento\Framework\Webapi\Exception as WebapiException;
use PHPUnit\Framework\TestCase;

class RefundGuardTest extends TestCase
{
    /**
     * @var RefundGuard
     */
    private $guard;

    protected function setUp(): void
    {
        $this->guard = new RefundGuard(new ConflictFactory());
    }

    /**
     * @param array $overrides
     * @return array
     */
    private function claims(array $overrides = [])
    {
        $claims = $this->guard->buildClaims(
            Contract::SOURCE_ORDER,
            12,
            null,
            1,
            'EUR',
            false,
            'sha256:' . str_repeat('a', 64),
            'sha256:' . str_repeat('b', 64),
            '19.9900',
            '19.9900'
        );

        return array_merge($claims, $overrides);
    }

    public function testIdenticalClaimsPass()
    {
        $this->guard->assertMatches($this->claims(), $this->claims());

        // assertMatches() communicates only by throwing.
        $this->addToAssertionCount(1);
    }

    public function testPayloadDivergenceIsRefused()
    {
        try {
            $this->guard->assertMatches(
                $this->claims(),
                $this->claims([RefundGuard::CLAIM_PAYLOAD_HASH => 'sha256:' . str_repeat('c', 64)])
            );
            $this->fail('Expected the guard to refuse a payload divergence.');
        } catch (WebapiException $e) {
            $this->assertSame(Contract::HTTP_CONFLICT, $e->getHttpCode());
            $this->assertStringContainsString(Contract::ERR_PAYLOAD_MISMATCH, $e->getMessage());
        }
    }

    public function testStateDivergenceIsRefused()
    {
        try {
            $this->guard->assertMatches(
                $this->claims(),
                $this->claims([RefundGuard::CLAIM_STATE_FINGERPRINT => 'sha256:' . str_repeat('d', 64)])
            );
            $this->fail('Expected the guard to refuse a state divergence.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_STATE_MISMATCH, $e->getMessage());
        }
    }

    public function testTotalDivergenceIsRefused()
    {
        try {
            $this->guard->assertMatches(
                $this->claims(),
                $this->claims([RefundGuard::CLAIM_BASE_GRAND_TOTAL => '19.9800'])
            );
            $this->fail('Expected the guard to refuse a total divergence.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_TOTAL_MISMATCH, $e->getMessage());
        }
    }

    /**
     * One cent is a divergence. This is the whole point of comparing fixed
     * scale strings rather than floats with an epsilon.
     */
    public function testOneCentIsEnoughToRefuse()
    {
        try {
            $this->guard->assertMatches(
                $this->claims(),
                $this->claims([RefundGuard::CLAIM_GRAND_TOTAL => '20.0000'])
            );
            $this->fail('Expected the guard to refuse a one cent divergence.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_TOTAL_MISMATCH, $e->getMessage());
        }
    }

    /**
     * @dataProvider scopeClaimProvider
     * @param string $claim
     * @param string|null $value
     */
    public function testScopeDivergenceIsRefusedBeforeAnythingElse($claim, $value)
    {
        $current = $this->claims([
            $claim => $value,
            // Poison the other claims too: scope must still win, so that the
            // caller is told what actually went wrong.
            RefundGuard::CLAIM_PAYLOAD_HASH => 'sha256:' . str_repeat('e', 64),
            RefundGuard::CLAIM_BASE_GRAND_TOTAL => '1.0000',
        ]);

        try {
            $this->guard->assertMatches($this->claims(), $current);
            $this->fail('Expected the guard to refuse a scope divergence.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_SCOPE_MISMATCH, $e->getMessage());
        }
    }

    /**
     * @return array
     */
    public function scopeClaimProvider()
    {
        return [
            'another order' => [RefundGuard::CLAIM_ORDER_ID, '13'],
            'another invoice' => [RefundGuard::CLAIM_INVOICE_ID, '99'],
            'another store' => [RefundGuard::CLAIM_STORE_ID, '2'],
            'another currency' => [RefundGuard::CLAIM_CURRENCY, 'USD'],
            'another source' => [RefundGuard::CLAIM_SOURCE, Contract::SOURCE_INVOICE],
            'offline turned online' => [RefundGuard::CLAIM_IS_ONLINE, 'true'],
        ];
    }

    /**
     * A missing claim must never be treated as "equal to anything".
     */
    public function testMissingClaimIsRefused()
    {
        $current = $this->claims();
        unset($current[RefundGuard::CLAIM_PAYLOAD_HASH]);

        try {
            $this->guard->assertMatches($this->claims(), $current);
            $this->fail('Expected the guard to refuse a missing claim.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_PAYLOAD_MISMATCH, $e->getMessage());
        }
    }

    /**
     * A null invoice id on both sides is a legitimate order level refund.
     */
    public function testNullClaimsMatchEachOther()
    {
        $this->guard->assertMatches(
            $this->claims([RefundGuard::CLAIM_INVOICE_ID => null]),
            $this->claims([RefundGuard::CLAIM_INVOICE_ID => null])
        );

        $this->addToAssertionCount(1);
    }

    public function testNullDoesNotMatchAValue()
    {
        try {
            $this->guard->assertMatches(
                $this->claims([RefundGuard::CLAIM_INVOICE_ID => null]),
                $this->claims([RefundGuard::CLAIM_INVOICE_ID => '5'])
            );
            $this->fail('Expected the guard to refuse null against a value.');
        } catch (WebapiException $e) {
            $this->assertStringContainsString(Contract::ERR_SCOPE_MISMATCH, $e->getMessage());
        }
    }

    public function testBuildClaimsNormalisesIdentifiersToStrings()
    {
        $claims = $this->guard->buildClaims(
            Contract::SOURCE_INVOICE,
            12,
            34,
            1,
            'EUR',
            true,
            'ph',
            'sf',
            '1.0000',
            '1.0000'
        );

        $this->assertSame('12', $claims[RefundGuard::CLAIM_ORDER_ID]);
        $this->assertSame('34', $claims[RefundGuard::CLAIM_INVOICE_ID]);
        $this->assertSame('true', $claims[RefundGuard::CLAIM_IS_ONLINE]);
    }
}
