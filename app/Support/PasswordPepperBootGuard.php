<?php

namespace App\Support;

class PasswordPepperBootGuard
{
    private const WEB_AND_QUEUE_COMMANDS = [
        'serve',
        'octane:start',
        'queue:work',
        'queue:listen',
        'horizon',
        'horizon:work',
    ];

    public function __construct(private readonly PasswordHasher $passwordHasher)
    {
    }

    public function assertConfiguredForRuntime(
        bool $isProduction,
        bool $runningInConsole,
        ?string $consoleCommand,
    ): void {
        if (! $isProduction) {
            return;
        }

        if ($runningInConsole && ! in_array($consoleCommand, self::WEB_AND_QUEUE_COMMANDS, true)) {
            return;
        }

        $this->passwordHasher->assertCurrentPepperConfigured();
    }
}
