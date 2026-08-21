<?php

namespace Emipro\Apichange\Test\Unit\Model\Refund;

use Emipro\Apichange\Model\Refund\Canonicalizer;
use PHPUnit\Framework\TestCase;

class CanonicalizerTest extends TestCase
{
    /**
     * @var Canonicalizer
     */
    private $canonicalizer;

    protected function setUp(): void
    {
        $this->canonicalizer = new Canonicalizer();
    }

    public function testMapKeyOrderDoesNotChangeTheEncoding()
    {
        $a = ['b' => 1, 'a' => 2, 'c' => 3];
        $b = ['c' => 3, 'a' => 2, 'b' => 1];

        $this->assertSame($this->canonicalizer->encode($a), $this->canonicalizer->encode($b));
    }

    public function testListOrderIsSignificant()
    {
        $this->assertNotSame(
            $this->canonicalizer->encode([1, 2]),
            $this->canonicalizer->encode([2, 1])
        );
    }

    public function testNumbersAreNormalisedToFixedScaleStrings()
    {
        $this->assertSame('10.0000', $this->canonicalizer->scalar(10));
        $this->assertSame('10.0000', $this->canonicalizer->scalar(10.0));
        $this->assertSame('10.0000', $this->canonicalizer->scalar('10'));
        $this->assertSame('10.5000', $this->canonicalizer->scalar(10.5));
    }

    /**
     * The float that famously does not round-trip through printf("%.17g").
     * Two nodes must still agree on its canonical form.
     */
    public function testFloatingPointNoiseIsAbsorbed()
    {
        $this->assertSame('0.3000', $this->canonicalizer->scalar(0.1 + 0.2));
        $this->assertSame('0.3000', $this->canonicalizer->scalar(0.3));
    }

    public function testSignedZeroIsCollapsed()
    {
        $this->assertSame('0.0000', $this->canonicalizer->scalar(-0.0));
        $this->assertSame('0.0000', $this->canonicalizer->scalar(-0.00001));
        $this->assertSame('0.0000', $this->canonicalizer->scalar(0));
    }

    public function testNegativeValuesKeepTheirSign()
    {
        $this->assertSame('-1.2500', $this->canonicalizer->scalar(-1.25));
    }

    public function testNonFiniteValuesDegradeToZeroInsteadOfBreakingJson()
    {
        $this->assertSame('0.0000', $this->canonicalizer->scalar(INF));
        $this->assertSame('0.0000', $this->canonicalizer->scalar(NAN));
    }

    public function testNullIsPreservedAndNeverConfusedWithZero()
    {
        $this->assertNull($this->canonicalizer->nullableScalar(null));
        $this->assertNull($this->canonicalizer->nullableScalar(''));
        $this->assertSame('0.0000', $this->canonicalizer->nullableScalar(0));

        $this->assertNotSame(
            $this->canonicalizer->encode(['a' => null]),
            $this->canonicalizer->encode(['a' => 0])
        );
    }

    public function testBooleansAreStable()
    {
        $this->assertSame('"true"', $this->canonicalizer->encode(true));
        $this->assertSame('"false"', $this->canonicalizer->encode(false));
    }

    /**
     * A sparse integer keyed array is a map, not a list, and must be encoded as
     * a JSON object so that it can never collide with a same-valued list.
     *
     * Note the boundary this test documents: PHP silently casts the string key
     * "0" to the integer 0, so a caller cannot express {"0": "a"} with a PHP
     * array in the first place. Only sparse or unordered integer keys are
     * distinguishable.
     */
    public function testSparseIntegerKeysAreEncodedAsAnObject()
    {
        $this->assertSame('{"3":"b","5":"a"}', $this->canonicalizer->encode([5 => 'a', 3 => 'b']));
        $this->assertSame('["a","b"]', $this->canonicalizer->encode(['a', 'b']));
    }

    public function testHashIsPrefixedAndStable()
    {
        $hash = $this->canonicalizer->hash(['a' => 1]);

        $this->assertStringStartsWith('sha256:', $hash);
        $this->assertSame(7 + 64, strlen($hash));
        $this->assertSame($hash, $this->canonicalizer->hash(['a' => 1.0]));
    }

    public function testNestedStructuresAreNormalisedRecursively()
    {
        $left = ['items' => [['qty' => 1, 'id' => 'a'], ['id' => 'b', 'qty' => 2]]];
        $right = ['items' => [['id' => 'a', 'qty' => 1.0], ['qty' => 2.0, 'id' => 'b']]];

        $this->assertSame($this->canonicalizer->hash($left), $this->canonicalizer->hash($right));
    }
}
