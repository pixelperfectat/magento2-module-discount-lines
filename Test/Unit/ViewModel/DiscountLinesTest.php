<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Test\Unit\ViewModel;

use Magento\Framework\View\Asset\Repository;
use Magento\Quote\Api\Data\TotalSegmentExtension;
use Magento\Quote\Api\Data\TotalSegmentExtensionInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PixelPerfect\DiscountLines\Api\ConfigInterface;
use PixelPerfect\DiscountLines\Model\Data\DiscountLine;
use PixelPerfect\DiscountLines\ViewModel\DiscountLines;

if (!interface_exists(TotalSegmentExtensionInterface::class)) {
    require_once __DIR__ . '/../../Stub/TotalSegmentExtensionInterface.php';
}
if (!class_exists(TotalSegmentExtension::class)) {
    require_once __DIR__ . '/../../Stub/TotalSegmentExtension.php';
}

class DiscountLinesTest extends TestCase
{
    private ConfigInterface&MockObject $config;
    private Repository&MockObject $assets;
    private DiscountLines $viewModel;

    protected function setUp(): void
    {
        $this->config = $this->createMock(ConfigInterface::class);
        $this->assets = $this->createMock(Repository::class);
        $this->viewModel = new DiscountLines($this->config, $this->assets);
    }

    public function testVariantComesFromConfig(): void
    {
        $this->config->method('getVariant')->willReturn('C');

        $this->assertSame('C', $this->viewModel->getVariant());
    }

    public function testIsEnabledComesFromConfig(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $this->assertTrue($this->viewModel->isEnabled());
    }

    public function testReadsLinesFromObjectExtensionAttributes(): void
    {
        $extension = (new TotalSegmentExtension())->setDiscountLines([
            new DiscountLine('coupon', 1, 'CODE', 'CODE', 3.33, 3.3),
            new DiscountLine('automatic', 2, null, 'Sale', 2.23, 2.2),
        ]);

        $lines = $this->viewModel->getLinesFromSegment(['code' => 'discount', 'extension_attributes' => $extension]);

        $this->assertSame([
            [
                'kind' => 'coupon', 'rule_id' => 1, 'code' => 'CODE', 'label' => 'CODE',
                'amount' => 3.33, 'base_amount' => 3.3, 'removable' => true,
            ],
            [
                'kind' => 'automatic', 'rule_id' => 2, 'code' => null, 'label' => 'Sale',
                'amount' => 2.23, 'base_amount' => 2.2, 'removable' => false,
            ],
        ], $lines);
    }

    public function testReadsLinesFromPlainArrays(): void
    {
        $segment = ['extension_attributes' => ['discount_lines' => [
            ['kind' => 'gift', 'rule_id' => '4', 'code' => 'GIFT', 'label' => 'Gift', 'amount' => '0', 'base_amount' => 0],
            ['kind' => 'coupon', 'rule_id' => 5, 'code' => 'C', 'label' => 'C', 'amount' => 1.5, 'base_amount' => 1.5],
        ]]];

        $lines = $this->viewModel->getLinesFromSegment($segment);

        $this->assertSame('gift', $lines[0]['kind']);
        $this->assertSame(4, $lines[0]['rule_id']);
        $this->assertSame(0.0, $lines[0]['amount']);
        $this->assertFalse($lines[0]['removable']);
        $this->assertTrue($lines[1]['removable']);
    }

    public function testReturnsEmptyWithoutExtensionAttributes(): void
    {
        $this->assertSame([], $this->viewModel->getLinesFromSegment(['code' => 'discount']));
        $this->assertSame([], $this->viewModel->getLinesFromSegment(['extension_attributes' => null]));
        $this->assertSame([], $this->viewModel->getLinesFromSegment(['extension_attributes' => []]));
        $this->assertSame([], $this->viewModel->getLinesFromSegment(
            ['extension_attributes' => new TotalSegmentExtension()]
        ));
    }

    public function testIconUrlResolvesKindToAsset(): void
    {
        $this->assets->method('getUrl')->willReturnCallback(static fn(string $id) => 'https://cdn.example.com/' . $id);

        $this->assertSame(
            'https://cdn.example.com/PixelPerfect_DiscountLines::images/tag.svg',
            $this->viewModel->getIconUrl('coupon')
        );
        $this->assertStringEndsWith('images/percent.svg', $this->viewModel->getIconUrl('automatic'));
        $this->assertStringEndsWith('images/gift.svg', $this->viewModel->getIconUrl('gift'));
    }

    public function testUnknownKindFallsBackToTagIcon(): void
    {
        $this->assets->method('getUrl')->willReturnArgument(0);

        $this->assertStringEndsWith('images/tag.svg', $this->viewModel->getIconUrl('something_new'));
    }

    public function testSavedTotalIsRoundedSum(): void
    {
        $lines = [['amount' => 1.005], ['amount' => 2.1], ['amount' => 0.0]];

        $this->assertSame(3.11, $this->viewModel->getSavedTotal($lines));
        $this->assertSame(0.0, $this->viewModel->getSavedTotal([]));
    }

    public function testHasCouponDetectsRemovableLine(): void
    {
        $this->assertTrue($this->viewModel->hasCoupon([['removable' => false], ['removable' => true]]));
        $this->assertFalse($this->viewModel->hasCoupon([['removable' => false]]));
        $this->assertFalse($this->viewModel->hasCoupon([]));
    }
}
