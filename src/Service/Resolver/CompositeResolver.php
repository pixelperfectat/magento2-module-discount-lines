<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service\Resolver;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PixelPerfect\DiscountLines\Api\Service\DiscountLineResolverInterface;

class CompositeResolver implements DiscountLineResolverInterface
{
    /**
     * @param DiscountLineResolverInterface[] $resolvers Named strategies, keyed in di.xml
     * @throws LocalizedException
     */
    public function __construct(
        private readonly array $resolvers = []
    ) {
        foreach ($this->resolvers as $name => $resolver) {
            if (!$resolver instanceof DiscountLineResolverInterface) {
                throw new LocalizedException(
                    __('Discount line resolver "%1" must implement %2.', $name, DiscountLineResolverInterface::class)
                );
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function resolve(Address $address, Quote $quote): array
    {
        $lines = [];
        foreach ($this->resolvers as $resolver) {
            foreach ($resolver->resolve($address, $quote) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}
