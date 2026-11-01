<?php declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Service\Config;

class ConfigTest extends TestCase
{
    private ScopeConfigInterface&MockObject $scopeConfig;
    private Config $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->config = new Config($this->scopeConfig);
    }

    public function testVariantDefaultsToB(): void
    {
        $this->scopeConfig->method('getValue')->willReturn(null);

        $this->assertSame('B', $this->config->getVariant());
    }

    public function testInvalidVariantFallsBackToB(): void
    {
        $this->scopeConfig->method('getValue')->willReturn('Z');

        $this->assertSame('B', $this->config->getVariant());
    }

    public function testValidVariantIsReturned(): void
    {
        $this->scopeConfig->method('getValue')
            ->with(ConfigInterface::XML_PATH_VARIANT, ScopeInterface::SCOPE_STORE, 3)
            ->willReturn('D');

        $this->assertSame('D', $this->config->getVariant(3));
    }

    public function testIsEnabledReadsStoreScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(ConfigInterface::XML_PATH_ENABLED, ScopeInterface::SCOPE_STORE, 7)
            ->willReturn(true);

        $this->assertTrue($this->config->isEnabled(7));
    }

    public function testIsEmailIconEnabledReadsStoreScope(): void
    {
        $this->scopeConfig->expects($this->once())
            ->method('isSetFlag')
            ->with(ConfigInterface::XML_PATH_EMAIL_ICON, ScopeInterface::SCOPE_STORE, 7)
            ->willReturn(false);

        $this->assertFalse($this->config->isEmailIconEnabled(7));
    }
}
