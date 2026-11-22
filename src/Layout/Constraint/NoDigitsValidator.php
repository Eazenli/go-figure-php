<?php

declare(strict_types=1);

namespace App\Layout\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class NoDigitsValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoDigits) {
            throw new UnexpectedTypeException($constraint, NoDigits::class);
        }
        if (!\is_string($value)) {
            return;
        }

        $message = match (FreeText::problem($value)) {
            null => null,
            FreeTextProblem::Number => $constraint->message,
            FreeTextProblem::Sign => $constraint->signMessage,
            FreeTextProblem::Invisible => $constraint->formatMessage,
            FreeTextProblem::Disguise => $constraint->alphabetMessage,
            FreeTextProblem::Link => $constraint->linkMessage,
        };
        if (null !== $message) {
            $this->context->buildViolation($message)->addViolation();
        }
    }
}
