<?php

declare(strict_types=1);

namespace App\Tests\Layout;

use App\Layout\BarChart;
use App\Layout\Computed;
use App\Layout\FigureRef;
use App\Layout\KpiTile;
use App\Layout\LayoutSchema;
use App\Layout\LayoutSchemaGenerator;
use App\Layout\Measured;
use App\Layout\Note;
use App\Layout\Op;
use App\Layout\Page;
use App\Layout\PeriodRef;
use App\Layout\Text;
use PHPUnit\Framework\Attributes\Depends;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Attribute\DiscriminatorMap;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\WrappingTypeInterface;
use Symfony\Component\TypeInfo\Type\CompositeTypeInterface;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

/**
 * The layout contract (see DEVELOP.md): the schema a model receives, and the one the MCP tool declares.
 */
final class LayoutSchemaTest extends KernelTestCase
{
    // Limits of the strictest structured-output provider we target.
    private const int MAX_ANY_OF = 16;
    private const int MAX_OPTIONAL_FIELDS = 24;
    private const int MAX_NESTING = 10;

    /**
     * A recursive type makes the Symfony AI schema factory loop forever instead of failing,
     * so the class graph is checked first, and every test that builds the schema depends on it.
     */
    public function testTheLayoutClassesAreNotRecursive(): void
    {
        $this->assertNoCycle(Page::class, []);
    }

    #[Depends('testTheLayoutClassesAreNotRecursive')]
    public function testTheMcpConstantMatchesTheClasses(): void
    {
        self::assertSame(
            $this->schema(),
            LayoutSchema::DEFINITION,
            'The layout classes changed: run `bin/console app:dump-layout-schema`.',
        );
    }

    #[Depends('testTheLayoutClassesAreNotRecursive')]
    public function testTheSchemaIsBuiltInAFewSeconds(): void
    {
        $generator = self::getContainer()->get(LayoutSchemaGenerator::class);

        $start = hrtime(true);
        $generator->schema();

        // A guard against a recursive type, which never ends (symfony/ai, bug 21), not a speed test:
        // the schema takes a fraction of a second, a busy machine can triple that.
        self::assertLessThan(3.0, (hrtime(true) - $start) / 1e9);
    }

    #[Depends('testTheLayoutClassesAreNotRecursive')]
    public function testEveryDiscriminatorIsTheFirstPropertyWithASingleValue(): void
    {
        $objects = 0;
        foreach ($this->nodes($this->schema()) as $path => $node) {
            if (!isset($node['anyOf'])) {
                continue;
            }
            foreach ($node['anyOf'] as $i => $variant) {
                $first = array_key_first($variant['properties']);
                self::assertContains($first, ['kind', 'type'], "$path.anyOf[$i]");
                self::assertSame('string', $variant['properties'][$first]['type'], "$path.anyOf[$i]");
                self::assertCount(1, $variant['properties'][$first]['enum'], "$path.anyOf[$i]");
                ++$objects;
            }
        }

        self::assertGreaterThan(0, $objects);
    }

    #[Depends('testTheLayoutClassesAreNotRecursive')]
    public function testNothingIsNullableAndTheModelNeverWritesANumber(): void
    {
        foreach ($this->nodes($this->schema()) as $path => $node) {
            $types = (array) ($node['type'] ?? []);
            self::assertNotContains('null', $types, $path);
            self::assertNotContains('number', $types, $path);
            self::assertNotContains('integer', $types, $path);
        }
    }

    #[Depends('testTheLayoutClassesAreNotRecursive')]
    public function testTheSchemaStaysWithinProviderLimits(): void
    {
        $anyOf = $optional = 0;
        foreach ($this->nodes($this->schema()) as $node) {
            $anyOf += isset($node['anyOf']) ? 1 : 0;
            $anyOf += \is_array($node['type'] ?? null) ? 1 : 0;
            if (isset($node['properties'])) {
                $optional += \count(array_diff(array_keys($node['properties']), $node['required'] ?? []));
            }
        }

        self::assertLessThanOrEqual(self::MAX_ANY_OF, $anyOf);
        self::assertLessThanOrEqual(self::MAX_OPTIONAL_FIELDS, $optional);
        self::assertLessThanOrEqual(self::MAX_NESTING, $this->nesting($this->schema()));
    }

    public function testASampleLayoutDeserializesIntoTheClasses(): void
    {
        $page = self::getContainer()->get(SerializerInterface::class)->deserialize(
            file_get_contents(__DIR__.'/../fixtures/layout/page.json'),
            Page::class,
            'json',
        );

        self::assertCount(5, $page->components);

        [$kpi, $bar, , , $note] = $page->components;
        self::assertInstanceOf(KpiTile::class, $kpi);
        self::assertInstanceOf(Measured::class, $kpi->figure);
        self::assertSame('FR', $kpi->figure->geo);

        self::assertInstanceOf(BarChart::class, $bar);
        self::assertInstanceOf(Computed::class, $bar->bars[0]);
        self::assertSame(Op::ChangePct, $bar->bars[0]->op);
        self::assertContainsOnlyInstancesOf(Measured::class, $bar->bars[0]->operands);

        self::assertInstanceOf(Note::class, $note);
        self::assertInstanceOf(Text::class, $note->segments[0]);
        self::assertInstanceOf(FigureRef::class, $note->segments[1]);
        self::assertInstanceOf(PeriodRef::class, $note->segments[3]);
    }

    /**
     * @return array<string, mixed>
     */
    private function schema(): array
    {
        return self::getContainer()->get(LayoutSchemaGenerator::class)->schema();
    }

    /**
     * Every schema node, keyed by its path.
     *
     * @param array<string, mixed> $node
     *
     * @return iterable<string, array<string, mixed>>
     */
    private function nodes(array $node, string $path = '$'): iterable
    {
        yield $path => $node;
        foreach ($node['properties'] ?? [] as $name => $child) {
            yield from $this->nodes($child, "$path.$name");
        }
        if (isset($node['items'])) {
            yield from $this->nodes($node['items'], "$path"."[]");
        }
        foreach ($node['anyOf'] ?? [] as $i => $child) {
            yield from $this->nodes($child, "$path|$i");
        }
    }

    /**
     * Object nesting levels, the way providers count them (a union does not add a level).
     *
     * @param array<string, mixed> $node
     */
    private function nesting(array $node): int
    {
        $deepest = 0;
        foreach ($node['properties'] ?? [] as $child) {
            $deepest = max($deepest, 1 + $this->nesting($child));
        }
        if (isset($node['items'])) {
            $deepest = max($deepest, $this->nesting($node['items']));
        }
        foreach ($node['anyOf'] ?? [] as $child) {
            $deepest = max($deepest, $this->nesting($child));
        }

        return $deepest;
    }

    /**
     * @param class-string       $class
     * @param list<class-string> $stack
     */
    private function assertNoCycle(string $class, array $stack): void
    {
        self::assertNotContains($class, $stack, 'Recursive layout type: '.implode(' > ', [...$stack, $class]));
        $stack[] = $class;

        $reflection = new \ReflectionClass($class);
        $children = [];
        foreach ($reflection->getAttributes(DiscriminatorMap::class) as $attribute) {
            $children = [...$children, ...array_values($attribute->newInstance()->mapping)];
        }
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $children = [...$children, ...$this->classesIn(TypeResolver::create()->resolve($parameter))];
        }

        foreach (array_unique($children) as $child) {
            $this->assertNoCycle($child, $stack);
        }
    }

    /**
     * @return list<class-string> the class or interface, not an enum (an enum holds no other type)
     */
    private static function unlessEnum(string $class): array
    {
        if (!class_exists($class) && !interface_exists($class)) {
            self::fail(\sprintf('The layout names a type that does not exist: %s.', $class));
        }

        return enum_exists($class) ? [] : [$class];
    }

    /**
     * @return list<class-string>
     */
    private function classesIn(Type $type): array
    {
        return match (true) {
            $type instanceof ObjectType => self::unlessEnum($type->getClassName()),
            $type instanceof CollectionType => $this->classesIn($type->getCollectionValueType()),
            $type instanceof CompositeTypeInterface => array_merge(...array_map($this->classesIn(...), $type->getTypes())),
            $type instanceof WrappingTypeInterface => $this->classesIn($type->getWrappedType()),
            default => [],
        };
    }
}
