<?php

namespace App\Bots\Support;

/**
 * Centralised prompt formatter for bot flows.
 *
 * Every prompt that waits for user input should be wrapped with `ask()` so
 * the user is constantly reminded they can bail out of the current step.
 *
 * The matching escape commands are handled globally in the
 * {@see \App\Bots\Engine\ConversationEngine}.
 */
final class Prompt
{
    /**
     * Appended to every input prompt. Keep it short — both WhatsApp and
     * Telegram truncate long messages in some clients and we want the
     * hint to stay visible.
     */
    public const HINT = "\n\n💡 أرسل *00* للقائمة الرئيسية • *0* للإلغاء والخروج";

    /**
     * Wrap a prompt body with the standard escape-hint footer.
     */
    public static function ask(string $body): string
    {
        return rtrim($body) . self::HINT;
    }
}
