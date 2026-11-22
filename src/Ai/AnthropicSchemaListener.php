<?php

declare(strict_types=1);

namespace App\Ai;

use Symfony\AI\Platform\Bridge\Anthropic\Claude;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The layout schema as Anthropic's structured output accepts it, for a Claude model only:
 * - a node holding both "anyOf" and "type" is refused ("For 'anyOf', 'type' is not supported"),
 *   which is how Symfony AI's schema factory writes a union of objects: the "type" next to an
 *   "anyOf" is dropped, each branch still says its own;
 * - the schema written out in full is refused too ("The compiled grammar is too large"): a figure
 *   (a measured cell, a calculation) is the same object wherever a component holds one, so it is
 *   defined once in "$defs" and referenced;
 * - a keyword without a value ("pattern": null) is refused as an invalid schema: it is dropped.
 * The recent Claude models also refuse the "temperature" option: it is sent to the older ones only.
 *
 * The page a model may write is the same. Runs after the structured-output subscriber of
 * Symfony AI, which writes the schema.
 */
#[AsEventListener(event: InvocationEvent::class, priority: -16)]
final readonly class AnthropicSchemaListener
{
    public function __invoke(InvocationEvent $event): void
    {
        $model = $event->getModel();
        if (!$model instanceof Claude) {
            return;
        }
        $options = $event->getOptions();
        // The recent Claude models refuse the option ("`temperature` is deprecated for this model"):
        // it is sent only to the older ones known to take it.
        if (1 !== preg_match('/^claude-(3-|haiku-4-|sonnet-4-|opus-4-[0-6](-|$))/', $model->getName())) {
            unset($options['temperature']);
        }
        $schema = $options['response_format']['json_schema']['schema'] ?? null;
        if (\is_array($schema)) {
            $options['response_format']['json_schema']['schema'] = self::forAnthropic($schema);
        }
        $event->setOptions($options);
    }

    /**
     * @param array<mixed> $schema
     *
     * @return array<mixed>
     */
    public static function forAnthropic(array $schema): array
    {
        $definitions = [];
        $schema = self::referenced(self::withoutTypeNextToAnyOf($schema), $definitions);
        if ([] !== $definitions) {
            $schema['$defs'] = $definitions;
        }

        return $schema;
    }

    /**
     * Replaces every figure by a reference to its one definition, deepest first.
     *
     * @param array<mixed>                $node
     * @param array<string, array<mixed>> $definitions
     *
     * @return array<mixed>
     */
    private static function referenced(array $node, array &$definitions): array
    {
        foreach ($node as $key => $child) {
            if (\is_array($child)) {
                $node[$key] = self::referenced($child, $definitions);
            }
        }
        // The discriminant of a figure: "type" is "measured" or "computed", and nothing else.
        $kind = $node['properties']['type']['enum'] ?? null;
        if (['measured'] === $kind || ['computed'] === $kind) {
            $definitions[$kind[0]] ??= $node;

            return ['$ref' => '#/$defs/'.$kind[0]];
        }
        if ([['$ref' => '#/$defs/measured'], ['$ref' => '#/$defs/computed']] === ($node['anyOf'] ?? null)) {
            $definitions['figure'] ??= ['anyOf' => $node['anyOf']];

            return ['$ref' => '#/$defs/figure'];
        }

        return $node;
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    public static function withoutTypeNextToAnyOf(array $node): array
    {
        // A keyword without a value ("pattern": null, which the factory writes for an
        // Assert\Regex whose pattern has no HTML form) is not JSON Schema.
        $node = array_filter($node, static fn (mixed $value): bool => null !== $value);
        if (isset($node['anyOf'], $node['type'])) {
            unset($node['type']);
        }
        foreach ($node as $key => $child) {
            if (\is_array($child)) {
                $node[$key] = self::withoutTypeNextToAnyOf($child);
            }
        }

        return $node;
    }
}
