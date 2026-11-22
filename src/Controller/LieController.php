<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\NoSnapshot;
use App\Http\SameOrigin;
use App\Layout\Constraint\FreeText;
use App\Layout\LayoutSchema;
use App\Layout\Page;
use App\Lie\Attacks;
use App\Ui\PageLink;
use App\Ui\PageLog;
use App\Ui\PageRenderer;
use App\Ui\View\PageView;
use Mcp\Capability\Discovery\SchemaValidator;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerException;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * "Try to make it lie": the visitor edits the layout a model would send, and sees what the server
 * proves, refuses or leaves out. The layout goes through the checks of an MCP call to render_page:
 * decoded the way the SDK decodes tool arguments, checked by the SDK's own JSON Schema validator,
 * then by the page rules.
 */
#[WithMonologChannel('go_figure')]
final class LieController extends AbstractController
{
    /** The prepared attacks: a file in app.lie_dir, its button, and what it changes in the layout. */
    public const array ATTACKS = [
        'digits' => ['Slip a number into the text', 'The title now says "three countries", and the note "up 12%".'],
        'cell' => ['Ask for a cell the data does not have', 'The tile asks for the United Kingdom, and a new bar for Ireland in 2017, a year Eurostat did not publish.'],
        'value' => ['Write the value yourself', 'The tile carries its own value, next to the cell it names.'],
        'compute' => ['Ask for a calculation that means nothing', 'The second tile asks for the growth from Portugal, then, to Spain, now.'],
        'source' => ['Invent a source', 'The note cites a ministry and a web address of its own.'],
        'swap' => ['Pick a flattering cell', 'Portugal\'s bar names January, under a title about September.'],
    ];

    /** Far above any page a model writes (a few kilobytes). */
    private const int MAX_BYTES = 64 * 1024;
    private const int MAX_ERRORS = 6;

    private readonly SchemaValidator $schema;

    public function __construct(
        private readonly DenormalizerInterface $denormalizer,
        private readonly PageRenderer $pages,
        private readonly LoggerInterface $logger,
        private readonly PageLog $log,
        private readonly TranslatorInterface $translator,
        private readonly Attacks $attacks,
        #[Autowire('%app.lie_dir%')]
        private readonly string $dir,
    ) {
        $this->schema = new SchemaValidator();
    }

    /**
     * With ?page=…, the layout is the one of an answer the visitor was reading ("Attack this
     * answer"): the token of its /p link. The prepared attacks then change that layout.
     */
    #[Route('/lie', name: 'lie', methods: ['GET', 'POST'])]
    public function lie(Request $request): Response
    {
        $attack = null;
        $token = $request->isMethod('POST') ? $request->request->getString('page') : $request->query->getString('page');
        $answer = '' === $token ? null : PageLink::decode($token);
        if ('' !== $token && null === $answer) {
            throw $this->createNotFoundException('This is not the link of a page.');
        }
        if ($request->isMethod('POST')) {
            // Another site could post a layout of its own choosing here, and have it shown under
            // this address. Browsers say where a form comes from; tools that do not are let in.
            if (!SameOrigin::allows($request)) {
                throw new AccessDeniedHttpException('A layout can only be sent from this page.');
            }
            $layout = $request->request->getString('layout');
        } else {
            $attack = $request->query->getString('attack');
            if ('' === $attack) {
                $attack = null;
            } elseif (!isset(self::ATTACKS[$attack])) {
                throw $this->createNotFoundException('There is no such attack.');
            }
            if (null !== $answer) {
                $attacked = null === $attack ? $answer : $this->attacks->apply($attack, $answer);
                if (null === $attacked) {
                    throw $this->createNotFoundException('This answer has nothing this attack changes.');
                }
                $layout = json_encode($attacked, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            } else {
                // As a model asked in the page's language would write it, when there is such a layout.
                $base = $this->dir.'/'.($attack ?? 'start');
                $layout = new Filesystem()->readFile(is_file($base.'.'.$request->getLocale().'.json') ? $base.'.'.$request->getLocale().'.json' : $base.'.json');
            }
        }

        $outcome = $this->attempt($layout);
        // The attacks of an answer: the ones its layout can take, each with what it changes.
        $attacks = self::ATTACKS;
        if (null !== $answer) {
            $attacks = [];
            foreach ($this->attacks->available($answer) as $kind) {
                $attacks[$kind] = [Attacks::LABELS[$kind], Attacks::SAID[$kind]];
            }
        }

        return $this->render('lie.html.twig', [
            'attacks' => $attacks,
            'attack' => $attack,
            'answer' => '' === $token ? null : $token,
            'layout' => $layout,
            'page' => $outcome['page'],
            'problem' => $outcome['problem'],
            'errors' => $outcome['errors'],
        ], new Response(status: $outcome['refused'] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * @return array{page: ?PageView, problem: ?string, errors: list<string>, refused: bool}
     */
    private function attempt(string $layout): array
    {
        if (\strlen($layout) > self::MAX_BYTES) {
            $this->logger->info('lie refused a layout of {bytes} bytes', ['bytes' => \strlen($layout)]);

            return self::refused($this->translator->trans('This layout is longer than any page needs (over %kb% KB).', ['%kb%' => self::MAX_BYTES / 1024]));
        }

        try {
            // As the SDK decodes tool arguments: arrays, so {} and [] are both an empty list.
            $decoded = json_decode($layout, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->info('lie received a layout that is not JSON: {error}', ['error' => $e->getMessage()]);

            return self::refused($this->translator->trans('This is not valid JSON: %error%.', ['%error%' => 'en' === $this->translator->getLocale() ? $e->getMessage() : $this->translator->trans(match ($e->getCode()) {
                \JSON_ERROR_SYNTAX => 'a syntax error',
                \JSON_ERROR_DEPTH => 'nested too deep',
                \JSON_ERROR_UTF8, \JSON_ERROR_UTF16 => 'characters that are not valid UTF-8',
                \JSON_ERROR_CTRL_CHAR => 'a control character',
                default => 'an error the parser cannot place',
            })]));
        }

        $errors = $this->schemaErrors($decoded);
        if ([] !== $errors) {
            $this->logger->info('lie refused a layout outside the page schema: {errors}', ['errors' => implode(' ', array_keys($errors))]);

            return self::refused($this->translator->trans('Refused before any figure is read: this layout does not follow the page schema. An MCP call to render_page goes through the same check.'), array_values($errors));
        }

        try {
            $page = $this->denormalizer->denormalize($decoded, Page::class);
            $view = $this->pages->view($page);
        } catch (SerializerException $e) {
            $this->logger->warning('lie refused a layout that passed the schema: {error}', ['error' => $e->getMessage()]);

            return self::refused($this->translator->trans('This layout does not follow the page schema: %error%', ['%error%' => $e->getMessage()]));
        } catch (NoSnapshot $e) {
            $this->logger->error('Try to make it lie cannot prove any figure: {error}', ['error' => $e->getMessage()]);

            return ['page' => null, 'problem' => $this->translator->trans('The server has no data snapshot yet, so no figure can be proven.'), 'errors' => [], 'refused' => false];
        }

        $this->log->shown('lie', $view);

        return ['page' => $view, 'problem' => null, 'errors' => [], 'refused' => false];
    }

    /**
     * One error of the schema check, in the page's language. The names it quotes come from the
     * visitor's layout: they are echoed only if they pass the free-text rule.
     *
     * @param array{keyword?: string, message: string} $error
     */
    private function schemaError(array $error): string
    {
        preg_match_all('/`([^`]*)`/', $error['message'], $quoted);
        $names = implode(', ', $quoted[1]);
        $shown = '' !== $names && null === FreeText::problem($names);

        return match (true) {
            'required' === ($error['keyword'] ?? null) && $shown => $this->translator->trans('Missing required properties: %names%.', ['%names%' => $names]),
            'additionalProperties' === ($error['keyword'] ?? null) && $shown => $this->translator->trans('Properties the page schema does not have: %names%.', ['%names%' => $names]),
            'additionalProperties' === ($error['keyword'] ?? null) => $this->translator->trans('Object contains properties the page schema does not have.'),
            'type' === ($error['keyword'] ?? null) && 2 === \count($quoted[1]) => $this->translator->trans('Invalid type: %expected% expected, %received% received.', ['%expected%' => $quoted[1][0], '%received%' => $quoted[1][1]]),
            default => $this->translator->trans('This does not follow the page schema.'),
        };
    }

    /**
     * @param list<string> $errors
     *
     * @return array{page: null, problem: string, errors: list<string>, refused: true}
     */
    private static function refused(string $problem, array $errors = []): array
    {
        return ['page' => null, 'problem' => $problem, 'errors' => $errors, 'refused' => true];
    }

    /**
     * What the MCP SDK's schema check finds, deepest first. A component or a figure may take one of
     * several shapes, so the check lists what each shape misses: the deepest is the one that matters.
     *
     * The SDK's messages quote the property names it did not expect, which the visitor wrote: a
     * message that breaks the free-text rule (a digit, a number in words…) is not shown as is. The pointer only holds
     * names from the schema and positions in a list.
     *
     * @return array<string, string> each error in English (the key, logged) and in the page's language
     */
    private function schemaErrors(mixed $decoded): array
    {
        $errors = $this->schema->validateAgainstJsonSchema($decoded, LayoutSchema::DEFINITION);
        usort($errors, static fn (array $a, array $b) => substr_count($b['pointer'], '/') <=> substr_count($a['pointer'], '/'));
        // Each error in English (logged) and in the page's language (shown).
        $lines = [];
        foreach ($errors as $e) {
            $english = $e['pointer'].': '.(null !== FreeText::problem($e['message'])
                ? ('additionalProperties' === $e['keyword'] ? 'Object contains properties the page schema does not have.' : 'This does not follow the page schema.')
                : $e['message']);
            $lines[$english] ??= 'en' === $this->translator->getLocale() ? $english : $e['pointer'].': '.$this->schemaError($e);
        }

        return \array_slice($lines, 0, self::MAX_ERRORS, true);
    }
}
