<?php

declare(strict_types=1);

namespace App\Layout\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class PeriodCodeValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PeriodCode) {
            throw new UnexpectedTypeException($constraint, PeriodCode::class);
        }

        if (!\is_string($value) || 1 !== preg_match(PeriodCode::PATTERN, $value)) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
