<?php

declare(strict_types=1);

namespace App\Import;

use App\Catalog\Theme;
use App\Layout\Constraint\NoDigits;
use App\Layout\Constraint\Render;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A catalog entry proposed for a Eurostat dataset (the extended mode): by the visitor on /import,
 * by an agent, or by an MCP client. It names codes and writes words, never a value: the server
 * checks the codes against the dataset, then reads every value from Eurostat itself.
 *
 * The texts are shown on pages and proofs: no digits and no numbers in words, like any text a model
 * writes. Every rule is in the "render" group, checked by the server once the proposal is complete:
 * Symfony AI validates the default group of a structured output and would throw on the first
 * violation, where the visitor should see them all.
 */
final class Proposal
{
    /**
     * @param list<DimensionChoice> $dimensions
     * @param list<string>          $pitfalls
     */
    public function __construct(
        #[Schema(description: 'The Eurostat code of the dataset, e.g. tour_occ_arnat.')]
        #[Assert\Regex(EurostatApi::CODE, message: 'This is not a Eurostat dataset code, such as tour_occ_arnat.', groups: [Render::GROUP])]
        public string $code = '',
        #[Schema(description: 'One code for every dimension of the dataset except geo and time.')]
        public array $dimensions = [],
        #[Schema(description: 'A short title, in plain words: what one figure counts. No digits and no numbers in words.')]
        #[Assert\NotBlank(groups: [Render::GROUP]), Assert\Length(max: 80, groups: [Render::GROUP]), NoDigits]
        public string $title = '',
        #[Schema(description: 'One or two sentences: what the figures count and what they leave out. No digits and no numbers in words.')]
        #[Assert\NotBlank(groups: [Render::GROUP]), Assert\Length(max: 400, groups: [Render::GROUP]), NoDigits]
        public string $scope = '',
        #[Schema(description: 'What one figure counts, after the number: "nights", "passengers", "of the EU average". Without its scale ("thousand", "million"): the server adds it from the unit code. No digits.')]
        #[Assert\NotBlank(groups: [Render::GROUP]), Assert\Length(max: 60, groups: [Render::GROUP]), NoDigits]
        public string $unitLabel = '',
        #[Schema(description: 'The question of the traveller the figures answer.')]
        public Theme $theme = Theme::Crowds,
        #[Schema(description: 'At most three traps for a reader of these figures, each a short sentence. No digits and no numbers in words.')]
        #[Assert\Count(max: 3, groups: [Render::GROUP])]
        #[Assert\All(constraints: [new Assert\NotBlank(groups: [Render::GROUP]), new Assert\Length(max: 200, groups: [Render::GROUP]), new NoDigits()], groups: [Render::GROUP])]
        public array $pitfalls = [],
    ) {
    }

    /**
     * @return array<string, string> dimension => code
     */
    public function frozen(): array
    {
        $frozen = [];
        foreach ($this->dimensions as $choice) {
            $frozen[$choice->dimension] = $choice->code;
        }

        return $frozen;
    }
}
