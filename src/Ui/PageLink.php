<?php

declare(strict_types=1);

namespace App\Ui;

use App\Layout\Page;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * The stateless link to a whole page: /p/{token}, the layout compressed into the address. Like a
 * proof link, it needs no storage: opening it builds the page again, and every figure is read and
 * proven anew from the data snapshot. A link someone edits by hand is just another layout, checked
 * like the ones a model sends.
 */
final readonly class PageLink
{
    /** Far above a real page (a few hundred bytes compressed); keeps a forged link small. */
    public const int MAX_TOKEN = 16384;
    /** The largest layout a link may unpack to, as for /lie. */
    private const int MAX_LAYOUT = 65536;

    public function __construct(
        private NormalizerInterface $normalizer,
        private UrlGeneratorInterface $urls,
    ) {
    }

    public function url(Page $page): string
    {
        return $this->urls->generate('page', ['token' => self::encode($this->layout($page))], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * The page as the layout a model sends for it.
     *
     * @return array<mixed>
     */
    public function layout(Page $page): array
    {
        return (array) $this->normalizer->normalize($page);
    }

    /**
     * @param array<mixed> $layout
     */
    public static function encode(array $layout): string
    {
        $deflated = gzdeflate(json_encode($layout, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), 9);
        if (false === $deflated) {
            throw new \RuntimeException('The page could not be compressed.');
        }

        return rtrim(strtr(base64_encode($deflated), '+/', '-_'), '=');
    }

    /**
     * @return array<mixed>|null the layout, as decoded JSON; null when the token holds none
     */
    public static function decode(string $token): ?array
    {
        if (\strlen($token) > self::MAX_TOKEN || 1 !== preg_match('/^[A-Za-z0-9_-]+$/D', $token)) {
            return null;
        }
        $deflated = base64_decode(strtr($token, '-_', '+/'), true);
        $json = false === $deflated ? false : @gzinflate($deflated, self::MAX_LAYOUT);
        if (false === $json) {
            return null;
        }
        try {
            $layout = json_decode($json, true, 64, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($layout) ? $layout : null;
    }
}
