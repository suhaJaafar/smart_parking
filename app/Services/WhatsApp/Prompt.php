<?php

namespace App\Services\WhatsApp;

/**
 * Centralised prompt formatter for WhatsApp flows.
 *
 * Every prompt that waits for user input should be wrapped with `ask()` so
 * the user is constantly reminded they can bail out of the current step.
 *
 * The matching escape commands are handled globally in
 * {@see \App\Http\Controllers\WhatsAppController::handleMessage()}.
 */
final class Prompt
{
    /**
     * Appended to every input prompt. Keep it short — WhatsApp truncates long
     * messages and we want the hint to stay visible.
     */
    public const HINT = "\n\n💡 أرسل *رجوع* للقائمة الرئيسية • *الغاء* للخروج";

    /**
     * Wrap a prompt body with the standard escape-hint footer.
     */
    public static function ask(string $body): string
    {
        return rtrim($body) . self::HINT;
    }
}
