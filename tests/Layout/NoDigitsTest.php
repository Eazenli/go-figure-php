<?php

declare(strict_types=1);

namespace App\Tests\Layout;

use App\Layout\Constraint\NoDigits;
use App\Layout\Constraint\Render;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * The rule on text written by the model: what it refuses, and the ordinary English it
 * must let through, place names included.
 */
final class NoDigitsTest extends TestCase
{
    private const string NUMBER = 'Unsupported: the model wrote this number itself.';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideRefusedTexts(): iterable
    {
        foreach ([
            'ninety-nine', 'Ninety Nine nights', 'NINETY-NINE', 'one hundred and five', 'two million', 'zero',
            'the second busiest', 'its twenty-third year', 'the nineties', 'two thirds of it', 'tenth',
            'hundreds of hotels', 'a thousand nights', 'millions of visitors', 'a dozen countries',
            'twice as many', 'thrice', 'up ten percent', 'five per cent', 'a ten-day stay', "nine\u{2019}s",
            'ｔｗｅｌｖｅ', '𝐭𝐰𝐞𝐥𝐯𝐞 nights', 'ﬁve', 'up 12%', '½', '三',
            // Roman numerals, which NFKC turns into letters.
            'Ⅻ', 'Up Ⅹ points',
            // Accents, marks and other letter shapes.
            'twélve', "twe\u{0301}lve", "t\u{0335}w\u{0335}elve", 'NİNETY', 'MİLLİON', 'ᴛᴡᴇʟᴠᴇ', 'tweɪve',
            // Marks that ICU's Latin-ASCII leaves in place: an enclosing circle, a Thai vowel sign.
            "tw\u{20DD}elve", "tw\u{0489}elve", "tw\u{0E31}elve",
            // Look-alikes listed by Unicode (TR39): a capital I for an l, "rn" for "m", Cyrillic letters only.
            'tweIve', 'eIeven', 'miIIion', 'rnillion', "\u{0455}\u{0456}\u{0445}",
            // Joined, multiplied, plural.
            'twentyfive', 'ninetynine', 'a tenfold rise', 'rose threefold', 'hundredfold', 'sixes', 'zeroes',
            // Short forms of scales and rates, and scales above a trillion.
            'CDLVII mn nights', 'up XII pc', 'a bn euro', 'eight lakh', 'a quadrillion', 'nought', 'nil', 'per mille',
            // French, with and without accents, joined, Belgian and Swiss, approximate and multiplied.
            'en hausse de douze pour cent', 'la deuxième destination', 'la deuxieme', 'quatre-vingt-dix nuitées',
            'zéro', 'des milliers de visiteurs', 'une dizaine de pays', 'vingtetun', 'deuxcents', 'troismillions',
            'septante', 'nonante', 'un chiffre au centuple', 'décuplé', 'mille nuitées',
            'la seconde ville', 'un tiers des nuitées', 'des prix quadruplés', 'une hausse décuplée', 'it quadrupled',
            'une huitaine de jours', 'une nonantaine',
        ] as $text) {
            yield $text => [$text, self::NUMBER];
        }
        foreach (["twe\u{FE0F}lve", "nin\u{034F}ety", "tw\u{17B4}elve", "tw\u{180B}elve", "twelve\u{FE0F}", "soft\u{00AD}ly"] as $text) {
            yield 'invisible: '.json_encode($text) => [$text, 'Refused: invisible formatting characters are not allowed.'];
        }
        foreach (['https://evil.example', 'HTTPS://EVIL.EXAMPLE/deal', 'book at www.evil.example', 'write to me@evil.example', 'mailto:me', 'ftp://files'] as $text) {
            yield 'address: '.$text => [$text, 'Refused: web addresses are not allowed.'];
        }
        yield 'emoji codes' => [':one::one: :heavy_plus_sign:', 'Refused: disguised letters are not allowed.'];
        yield 'letters drawn as symbols' => ["\u{1F163}\u{1F166}\u{1F154}\u{1F15B}\u{1F165}\u{1F154}", 'Refused: disguised letters are not allowed.'];
        yield 'a Cyrillic letter in a word' => ["tw\u{0435}lve", 'Refused: disguised letters are not allowed.'];
        yield 'a Greek letter in a word' => ["Nin\u{03B5}ty", 'Refused: disguised letters are not allowed.'];
        yield 'a fullwidth percent sign' => ['up ％', 'Unsupported: the model wrote this number sign itself.'];
        yield 'a right-to-left override' => ["France \u{202E}counted", 'Refused: invisible formatting characters are not allowed.'];
    }

    #[DataProvider('provideRefusedTexts')]
    public function testANumberInAnyFormIsRefused(string $text, string $message): void
    {
        self::assertSame([$message], self::violations($text));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideOrdinaryTexts(): iterable
    {
        foreach ([
            'France counted more hotel nights than Spain',
            'one of the busiest destinations, for the first time',
            'often, someone, oneself, tension, attention, Tenerife, Seville, Toulouse',
            'half board and double rooms near the Latin Quarter',
            'a few, several, many, none, a single night, a couple of weeks',
            'Hotel nights in France, Spain and Italy',
            'Austria, Belgium, Bulgaria, Croatia, Cyprus, Czechia, Denmark, Estonia, Finland, Germany, Greece',
            'Hungary, Ireland, Latvia, Lithuania, Luxembourg, Malta, Netherlands, Poland, Portugal, Romania',
            'Slovakia, Slovenia, Sweden, Iceland, Liechtenstein, Norway, Switzerland, Montenegro, Albania',
            'North Macedonia, Serbia, Türkiye, Bosnia and Herzegovina, Kosovo, the United Kingdom',
            'Zürich, Kraków, São Paulo, Αθήνα, Москва',
            'Over time',
            'First and last period',
            'Paris → Rome, Rome | Florence',
            'manifold, nonetheless, often, tennis, anyone, Sixt, I visited',
            'a quarter of all nights, a decade, a fortnight, a pair, a score of hotels',
            'Hotel nights spent by residents and non-residents',
            'Note: hotels only. The source: Eurostat, see the proof of each figure',
            // French that holds no number: "un", "une", "premier" and "et" are let through.
            "Les nuitées d'hôtel au Portugal depuis la pandémie",
            'une destination, un séjour, le premier hôtel et la première nuit',
            'Hôtels et hébergements similaires seulement : les campings ne sont pas comptés.',
            'the season unfolds, as it unfolded last year: unfold',
        ] as $text) {
            yield $text => [$text];
        }
    }

    #[DataProvider('provideOrdinaryTexts')]
    public function testOrdinaryEnglishIsLetThrough(string $text): void
    {
        self::assertSame([], self::violations($text));
    }

    /**
     * @return list<string>
     */
    private static function violations(string $text): array
    {
        $violations = Validation::createValidator()->validate($text, new NoDigits(), [Render::GROUP]);

        $messages = [];
        foreach ($violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }
}
