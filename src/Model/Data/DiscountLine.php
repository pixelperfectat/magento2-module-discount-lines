<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Model\Data;

use PixelPerfect\DiscountLines\Api\Data\DiscountLineInterface;

/**
 * JSON-serialisable so the cart page's checkout config (which json-encodes the total segments) carries the lines.
 */
readonly class DiscountLine implements DiscountLineInterface, \JsonSerializable
{
    /**
     * @param string $kind
     * @param int|null $ruleId
     * @param string|null $code
     * @param string $label
     * @param float $amount
     * @param float $baseAmount
     * @param int $sortOrder
     */
    public function __construct(
        private string $kind,
        private ?int $ruleId,
        private ?string $code,
        private string $label,
        private float $amount,
        private float $baseAmount,
        private int $sortOrder = 0
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getKind(): string
    {
        return $this->kind;
    }

    /**
     * @inheritDoc
     */
    public function getRuleId(): ?int
    {
        return $this->ruleId;
    }

    /**
     * @inheritDoc
     */
    public function getCode(): ?string
    {
        return $this->code;
    }

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return $this->label;
    }

    /**
     * @inheritDoc
     */
    public function getAmount(): float
    {
        return $this->amount;
    }

    /**
     * @inheritDoc
     */
    public function getBaseAmount(): float
    {
        return $this->baseAmount;
    }

    /**
     * @inheritDoc
     */
    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    /**
     * @inheritDoc
     */
    public function withAmounts(float $amount, float $baseAmount): DiscountLineInterface
    {
        return new self(
            $this->kind,
            $this->ruleId,
            $this->code,
            $this->label,
            $amount,
            $baseAmount,
            $this->sortOrder
        );
    }

    /**
     * @inheritDoc
     */
    public function withSortOrder(int $sortOrder): DiscountLineInterface
    {
        return new self(
            $this->kind,
            $this->ruleId,
            $this->code,
            $this->label,
            $this->amount,
            $this->baseAmount,
            $sortOrder
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'rule_id' => $this->ruleId,
            'code' => $this->code,
            'label' => $this->label,
            'amount' => $this->amount,
            'base_amount' => $this->baseAmount,
        ];
    }

    /**
     * Serialise as the contract array.
     *
     * @return array{
     *     kind: string,
     *     rule_id: int|null,
     *     code: string|null,
     *     label: string,
     *     amount: float,
     *     base_amount: float
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
