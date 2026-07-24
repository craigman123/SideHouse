<?php

namespace App\Providers;

use Native\Laravel\Facades\Window;
use Native\Laravel\Facades\Menu;
use Native\Laravel\Contracts\ProvidesPhpIni;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    public function boot(): void
    {
        Menu::create();

        Window::open()
            ->title('Side House')
            ->maximized();
    }

    public function phpIni(): array
    {
        return [
        ];
    }
}