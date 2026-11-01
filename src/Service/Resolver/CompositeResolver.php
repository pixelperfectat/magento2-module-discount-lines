<?php

declare(strict_types=1);

namespace PixelPerfect\DiscountLines\Service\Resolver;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PixelPerfect\DiscountLines\Api\Service\DiscountLineResolverInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs the named resolvers injected via di.xml and concatenates their lines.
 *
 * A misconfigured entry is skipped and logged: it runs during totals collection and must never break checkout.
 */
class CompositeResolver implements DiscountLineResolverInterface
{
    /** @var DiscountLineResolverInterface[] */
    private readonly array $resolvers;

    /**
     * @param LoggerInterface $logger
     * @param array<string, mixed> $resolvers Named strategies, keyed in di.xml
     */
    public function __construct(
        LoggerInterface $logger,
        array $resolvers = []
    ) {
        $valid = [];
        foreach ($resolvers as $name => $resolver) {
            if (!$resolver instanceof DiscountLineResolverInterface) {
                $logger->error(sprintf(
                    'Discount lines: resolver "%s" does not implement %s and is skipped.',
                    $name,
                    DiscountLineResolverInterface::class
                ));
                continue;
            }
            $valid[$name] = $resolver;
        }
        $this->resolvers = $valid;
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
