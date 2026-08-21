<?php

namespace Emipro\Apichange\Test\Unit\Model\Refund;

use Emipro\Apichange\Model\Refund\Config;
use Emipro\Apichange\Model\Refund\Contract;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    /**
     * @var array
     */
    private $scopes = [];

    /**
     * @param array $values Config path => stored value.
     * @return Config
     */
    private function config(array $values)
    {
        $this->scopes = [];

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            function ($path, $scope = null, $scopeCode = null) use ($values) {
                $this->scopes[$path] = [$scope, $scopeCode];

                return array_key_exists($path, $values) ? $values[$path] : null;
            }
        );

        return new Config($scopeConfig);
    }

    public function testUnsetValuesFallBackToTheDocumentedDefaults()
    {
        $config = $this->config([]);

        $this->assertSame(Contract::DEFAULT_PREVIEW_TTL, $config->getPreviewTtl());
        $this->assertSame(Contract::DEFAULT_LOCK_TIMEOUT, $config->getLockTimeout());
        $this->assertSame(Contract::DEFAULT_IN_PROGRESS_TTL, $config->getInProgressTtl());
        $this->assertTrue($config->isAtomicVerifyEnabled());
    }

    public function testConfiguredValuesAreUsed()
    {
        $config = $this->config([
            Contract::XML_PATH_PREVIEW_TTL => '60',
            Contract::XML_PATH_LOCK_TIMEOUT => '3',
            Contract::XML_PATH_IN_PROGRESS_TTL => '120',
            Contract::XML_PATH_ATOMIC_VERIFY => '0',
        ]);

        $this->assertSame(60, $config->getPreviewTtl());
        $this->assertSame(3, $config->getLockTimeout());
        $this->assertSame(120, $config->getInProgressTtl());
        $this->assertFalse($config->isAtomicVerifyEnabled());
    }

    /**
     * Zero is a meaningful setting for this one, not "unset": it turns the
     * automatic takeover of stale in_progress claims off, and such a claim then
     * stays blocked until an operator resolves it. The other two TTLs keep
     * their "0 means unset" semantics.
     */
    public function testZeroDisablesTheRecoveryOfStaleInProgressClaims()
    {
        $config = $this->config([Contract::XML_PATH_IN_PROGRESS_TTL => '0']);

        $this->assertSame(0, $config->getInProgressTtl());
    }

    public function testANegativeInProgressTtlDisablesRecoveryRatherThanRecoveringEverything()
    {
        $config = $this->config([Contract::XML_PATH_IN_PROGRESS_TTL => '-1']);

        $this->assertSame(0, $config->getInProgressTtl());
    }

    public function testAnEmptyInProgressTtlIsTreatedAsUnset()
    {
        $config = $this->config([Contract::XML_PATH_IN_PROGRESS_TTL => '']);

        $this->assertSame(Contract::DEFAULT_IN_PROGRESS_TTL, $config->getInProgressTtl());
    }

    public function testZeroOrLessOnTheOtherTtlsMeansUnset()
    {
        $config = $this->config([
            Contract::XML_PATH_PREVIEW_TTL => '0',
            Contract::XML_PATH_LOCK_TIMEOUT => '-5',
        ]);

        $this->assertSame(Contract::DEFAULT_PREVIEW_TTL, $config->getPreviewTtl());
        $this->assertSame(Contract::DEFAULT_LOCK_TIMEOUT, $config->getLockTimeout());
    }

    public function testEveryValueIsReadInTheStoreScope()
    {
        $config = $this->config([]);
        $config->getPreviewTtl(7);
        $config->getLockTimeout(7);
        $config->getInProgressTtl(7);
        $config->isAtomicVerifyEnabled(7);

        foreach ([
            Contract::XML_PATH_PREVIEW_TTL,
            Contract::XML_PATH_LOCK_TIMEOUT,
            Contract::XML_PATH_IN_PROGRESS_TTL,
            Contract::XML_PATH_ATOMIC_VERIFY,
        ] as $path) {
            $this->assertSame([ScopeInterface::SCOPE_STORE, 7], $this->scopes[$path], $path);
        }
    }
}
