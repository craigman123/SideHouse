<?php

namespace App\Auth;

use App\Models\User;
use App\Support\PasswordHasher;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;

class PepperedEloquentUserProvider extends EloquentUserProvider
{
    public function __construct(
        Hasher $hasher,
        string $model,
        private readonly PasswordHasher $passwordHasher,
    ) {
        parent::__construct($hasher, $model);
    }

    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        $password = $credentials['password'] ?? null;

        return $password !== null
            && is_string($password)
            && $this->passwordHasher->check($user, $password);
    }

    public function rehashPasswordIfRequired(
        Authenticatable $user,
        #[\SensitiveParameter] array $credentials,
        bool $force = false,
    ): void {
        $password = $credentials['password'] ?? null;
        if (! ($user instanceof User) || ! is_string($password)) {
            return;
        }

        if ($force || $this->passwordHasher->needsRehash($user)) {
            $this->passwordHasher->rehash($user, $password);
        }
    }
}
