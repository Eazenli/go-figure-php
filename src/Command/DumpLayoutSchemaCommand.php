<?php

declare(strict_types=1);

namespace App\Command;

use App\Layout\LayoutSchemaGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:dump-layout-schema',
    description: 'Write the layout JSON Schema, as Symfony AI builds it, into src/Layout/LayoutSchema.php for the MCP tool',
)]
final readonly class DumpLayoutSchemaCommand
{
    public function __construct(
        private LayoutSchemaGenerator $generator,
        #[Autowire('%kernel.project_dir%/src/Layout/LayoutSchema.php')]
        private string $target,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Print the schema as JSON instead of writing the PHP file')]
        bool $json = false,
    ): int {
        if ($json) {
            $io->writeln(json_encode($this->generator->schema(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        if (false === @file_put_contents($this->target, $this->generator->phpFile())) {
            $io->error(\sprintf('Could not write %s: %s', $this->target, error_get_last()['message'] ?? 'unknown error'));

            return Command::FAILURE;
        }
        $io->success(\sprintf('Wrote %s', $this->target));

        return Command::SUCCESS;
    }
}
