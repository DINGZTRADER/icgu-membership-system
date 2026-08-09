<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

final class TotpService
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes(max(16, min($bytes, 32))));
    }

    public function provisioningUri(User $user, string $secret): string
    {
        $issuer = 'ICGU';
        $label = rawurlencode($issuer.':'.$user->email);

        return 'otpauth://totp/'.$label.'?secret='.rawurlencode($secret)
            .'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    public function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $counter = intdiv($timestamp ?? time(), 30);
        for ($offset = -max(0, $window); $offset <= max(0, $window); $offset++) {
            if (hash_equals($this->hotp($secret, $counter + $offset), $code)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{plain:list<string>,hashes:list<string>} */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $plain = [];
        $hashes = [];

        for ($i = 0; $i < max(4, min($count, 12)); $i++) {
            $code = Str::upper(Str::random(5).'-'.Str::random(5));
            $plain[] = $code;
            $hashes[] = $this->recoveryHash($code);
        }

        return compact('plain', 'hashes');
    }

    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $target = $this->recoveryHash($code);
        $hashes = array_values(array_filter((array) ($user->mfa_recovery_codes ?? []), 'is_string'));

        foreach ($hashes as $index => $hash) {
            if (! hash_equals($hash, $target)) {
                continue;
            }

            unset($hashes[$index]);
            $user->forceFill(['mfa_recovery_codes' => array_values($hashes)])->save();
            return true;
        }

        return false;
    }

    private function hotp(string $base32Secret, int $counter): string
    {
        $key = $this->base32Decode($base32Secret);
        $high = intdiv($counter, 4294967296);
        $low = $counter % 4294967296;
        $binaryCounter = pack('N2', $high, $low);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (unpack('C*', $bytes) as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';
        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        return $encoded;
    }

    private function base32Decode(string $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Z2-7]/', '', $value) ?? '');
        $bits = '';

        foreach (str_split($value) as $character) {
            $index = strpos(self::ALPHABET, $character);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $decoded = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $decoded .= chr(bindec($chunk));
            }
        }

        return $decoded;
    }

    private function recoveryHash(string $code): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code) ?? '');
        return hash_hmac('sha256', $normalized, (string) config('app.key'));
    }
}
