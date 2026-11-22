<?php

declare(strict_types=1);

namespace App\Tests\Ai;

use App\Ai\AiSettings;
use PHPUnit\Framework\TestCase;

/**
 * The settings of .env.local: a mistake turns free questions off, and never breaks the app.
 */
final class AiSettingsTest extends TestCase
{
    public function testEmptyValuesOrPastedCommentsGiveTheDefaults(): void
    {
        $settings = AiSettings::fromEnv('', '# Leave empty for the default model', ' hf_key ');

        self::assertNull($settings->problem);
        self::assertSame('huggingface', $settings->provider);
        self::assertSame('openai/gpt-oss-120b', $settings->model);
        self::assertSame('cerebras', $settings->route);
        self::assertTrue($settings->hasKey());
        self::assertSame('hf_key', $settings->apiKey());
    }

    public function testAnUnknownProviderTurnsFreeQuestionsOff(): void
    {
        $settings = AiSettings::fromEnv('chatgpt', '', 'sk-key');

        self::assertFalse($settings->hasKey());
        self::assertSame('AI_PROVIDER "chatgpt" is not supported: use huggingface, anthropic, openai.', $settings->problem);
    }

    public function testTheKeyIsNeverDumped(): void
    {
        self::assertStringNotContainsString('sk-secret', print_r(AiSettings::fromEnv('openai', '', 'sk-secret'), true));
    }
}
