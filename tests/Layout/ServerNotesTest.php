<?php

declare(strict_types=1);

namespace App\Tests\Layout;

use App\Layout\Constraint\FreeText;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The sentences the server writes in a page follow the rule of a model's text: no number, in
 * digits or in words. A translation that breaks it ("les deux") is shown as a number the model
 * wrote itself, on a page no model wrote.
 */
final class ServerNotesTest extends TestCase
{
    public function testAFrenchSentenceHoldsNoNumberWhenItsEnglishHoldsNone(): void
    {
        $refused = [];
        foreach (Yaml::parseFile(\dirname(__DIR__, 2).'/translations/messages.fr.yaml') as $english => $french) {
            if (\is_string($english) && \is_string($french) && str_contains($english, ' ') && null === FreeText::problem($english) && null !== FreeText::problem($french)) {
                $refused[] = $french;
            }
        }

        self::assertSame([], $refused);
    }
}
