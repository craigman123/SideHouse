<?php

namespace Tests\Feature\Concerns;

trait UsesMultiGuardEnvironment
{
    private $previousMultiGuardGetenv;

    private array $previousMultiGuardEnvironment;

    protected function setMultiGuardEnvironment(bool $enabled): void
    {
        $this->previousMultiGuardGetenv = getenv('MULTI_GUARD_ENABLED');
        $this->previousMultiGuardEnvironment = [
            '_ENV' => array_key_exists('MULTI_GUARD_ENABLED', $_ENV) ? $_ENV['MULTI_GUARD_ENABLED'] : null,
            '_SERVER' => array_key_exists('MULTI_GUARD_ENABLED', $_SERVER) ? $_SERVER['MULTI_GUARD_ENABLED'] : null,
        ];

        $value = $enabled ? 'true' : 'false';
        putenv('MULTI_GUARD_ENABLED='.$value);
        $_ENV['MULTI_GUARD_ENABLED'] = $value;
        $_SERVER['MULTI_GUARD_ENABLED'] = $value;
    }

    protected function restoreMultiGuardEnvironment(): void
    {
        if ($this->previousMultiGuardGetenv === false) {
            putenv('MULTI_GUARD_ENABLED');
        } else {
            putenv('MULTI_GUARD_ENABLED='.$this->previousMultiGuardGetenv);
        }

        foreach (['_ENV', '_SERVER'] as $superglobal) {
            if ($this->previousMultiGuardEnvironment[$superglobal] === null) {
                unset($GLOBALS[$superglobal]['MULTI_GUARD_ENABLED']);
            } else {
                $GLOBALS[$superglobal]['MULTI_GUARD_ENABLED'] = $this->previousMultiGuardEnvironment[$superglobal];
            }
        }
    }
}
