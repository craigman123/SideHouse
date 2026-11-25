<?php

namespace App\Rules;

use App\Models\User;
use App\Support\PasswordHasher;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class CurrentPassword implements ValidationRule
{
    public function __construct(private readonly User $user) {}

    public function validate(string $attribute, #[\SensitiveParameter] mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(PasswordHasher::class)->check($this->user, $value)) {
            $fail('The current password is incorrect.');
        }
    }
}
