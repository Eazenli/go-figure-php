<?php

declare(strict_types=1);

namespace App\Mcp;

use Mcp\Event\ErrorEvent;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\Request\CallToolRequest;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Logs a tool call the SDK refused before reaching the tool (invalid params): above all, arguments
 * that do not follow the input schema, such as a page layout a model got wrong. The SDK answers the
 * client with the validation errors, but logs only the arguments it received, not the refusal.
 */
#[WithMonologChannel('go_figure')]
final readonly class RefusedCallLog
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function __invoke(ErrorEvent $event): void
    {
        $request = $event->getRequest();
        $error = $event->getError();
        if (!$request instanceof CallToolRequest || Error::INVALID_PARAMS !== $error->code) {
            return;
        }

        $this->logger->warning('Call to {tool} refused: {error}', [
            'tool' => $request->name,
            'error' => $error->message,
            'details' => $error->data,
        ]);
    }
}
