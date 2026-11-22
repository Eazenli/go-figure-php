<?php

declare(strict_types=1);

namespace App\Import;

/**
 * What a Eurostat dataset is made of: its title, its dimensions and their codes, read from the
 * JSON-stat answer for its latest period. Geo and time are not among the dimensions: they are the
 * ones a catalog entry leaves free.
 */
final readonly class DatasetStructure
{
    /**
     * @param list<Dimension> $dimensions every dimension but geo and time
     */
    public function __construct(
        public string $code,
        public string $label,
        public string $updated,
        public array $dimensions,
    ) {
    }

    /**
     * @param array<string, mixed> $data a JSON-stat answer of the Statistics API 1.0
     *
     * @throws EurostatUnavailable when the answer is not JSON-stat
     * @throws DatasetNotByPlace   when the dataset has no place or no period
     */
    public static function fromJsonStat(string $code, array $data): self
    {
        $ids = $data['id'] ?? null;
        if (!\is_array($ids)) {
            throw new EurostatUnavailable(\sprintf('The answer for %s is not JSON-stat.', $code));
        }
        if (!\in_array('geo', $ids, true) || !\in_array('time', $ids, true)) {
            throw new DatasetNotByPlace($code);
        }

        $dimensions = [];
        foreach ($ids as $id) {
            if (!\is_string($id) || \in_array($id, ['geo', 'time'], true)) {
                continue;
            }
            $category = $data['dimension'][$id]['category'] ?? [];
            $codes = [];
            foreach (JsonStat::codes($category['index'] ?? []) as $category_code) {
                $codes[$category_code] = (string) ($category['label'][$category_code] ?? $category_code);
            }
            $dimensions[] = new Dimension($id, (string) ($data['dimension'][$id]['label'] ?? $id), $codes);
        }

        return new self($code, (string) ($data['label'] ?? $code), substr((string) ($data['updated'] ?? ''), 0, 10), $dimensions);
    }

    public function dimension(string $id): ?Dimension
    {
        foreach ($this->dimensions as $dimension) {
            if ($dimension->id === $id) {
                return $dimension;
            }
        }

        return null;
    }

    /**
     * The dataset as text, for a model or an MCP client: each dimension with its codes, up to
     * $perDimension codes each.
     */
    public function describe(int $perDimension = 300): string
    {
        $lines = [\sprintf('%s: %s (updated %s).', $this->code, $this->label, $this->updated), 'Choose one code for each dimension below; geo and time stay free.'];
        foreach ($this->dimensions as $dimension) {
            $lines[] = '';
            $lines[] = \sprintf('%s (%s), %d codes:', $dimension->id, $dimension->label, \count($dimension->codes));
            foreach (\array_slice($dimension->codes, 0, $perDimension, true) as $code => $label) {
                $lines[] = \sprintf('- %s: %s', $code, $label);
            }
            if (\count($dimension->codes) > $perDimension) {
                $lines[] = \sprintf('- … and %d more.', \count($dimension->codes) - $perDimension);
            }
        }

        return implode("\n", $lines);
    }
}
