<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class PasswordHasher
{
    /**
     * @return array{hash: string, version: string}
     */
    public function make(#[\SensitiveParameter] string $password): array
    {
        $version = $this->currentVersion();
        $pepper = $this->pepperForVersion($version);

        return [
            'hash' => Hash::make($this->prehash($password, $pepper)),
            'version' => $version,
        ];
    }

    public function check(Authenticatable $user, #[\SensitiveParameter] string $password): bool
    {
        $storedHash = $user->getAuthPassword();
        if (! is_string($storedHash) || $storedHash === '') {
            return false;
        }

        $version = $user->password_pepper_version ?? null;
        if ($version === null) {
            return Hash::check($password, $storedHash);
        }

        $pepper = $this->pepperForVersion((string) $version);

        return Hash::check($this->prehash($password, $pepper), $storedHash);
    }

    public function needsRehash(Authenticatable $user): bool
    {
        $storedHash = $user->getAuthPassword();
        if (! is_string($storedHash) || $storedHash === '') {
            return false;
        }

        return ($user->password_pepper_version ?? null) !== $this->currentVersion()
            || Hash::needsRehash($storedHash);
    }

    public function rehash(User $user, #[\SensitiveParameter] string $password): void
    {
        $newPassword = $this->make($password);

        $attributes = $user->getAttributes();
        $attributes['password'] = $newPassword['hash'];
        $attributes['password_pepper_version'] = $newPassword['version'];
        $user->setRawAttributes($attributes, false);
        $user->save();
    }

    public function assertCurrentPepperConfigured(): void
    {
        $version = $this->currentVersion();
        $pepper = $this->pepperForVersion($version);

        if (strlen($pepper) < 32) {
            throw new RuntimeException(
                "The password pepper for version {$version} must be at least 32 bytes."
            );
        }
    }

    private function currentVersion(): string
    {
        $version = config('password_pepper.current_version');
        if (! is_string($version) || $version === '') {
            throw new RuntimeException('The current password pepper version is not configured.');
        }

        return $version;
    }

    private function pepperForVersion(string $version): string
    {
        $pepper = config("password_pepper.peppers.{$version}");
        if (! is_string($pepper) || $pepper === '') {
            throw new RuntimeException("The password pepper for version {$version} is not configured.");
        }

        return $pepper;
    }

    private function prehash(string $password, string $pepper): string
    {
        return base64_encode(hash_hmac('sha256', $password, $pepper, true));
    }
}
