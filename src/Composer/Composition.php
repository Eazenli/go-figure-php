<?php

declare(strict_types=1);

namespace App\Composer;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * What a visitor picks in the composer: one indicator, a few places, a range of periods.
 */
final class Composition
{
    public const int MAX_PLACES = 6;

    #[Assert\NotBlank]
    public ?string $dataset = null;

    /** @var list<string> */
    #[Assert\Count(min: 1, max: self::MAX_PLACES, minMessage: 'Choose at least one place.', maxMessage: 'Choose at most {{ limit }} places.')]
    #[Assert\Unique(message: 'Choose each place once.')]
    public array $places = [];

    #[Assert\NotBlank]
    public ?string $from = null;

    #[Assert\NotBlank]
    public ?string $to = null;

    #[Assert\Callback]
    public function validatePeriods(ExecutionContextInterface $context): void
    {
        if (null !== $this->from && null !== $this->to && $this->from > $this->to) {
            $context->buildViolation('The first period comes after the last one.')->atPath('from')->addViolation();
        }
    }
}
