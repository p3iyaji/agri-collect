<?php

namespace App\Services;

/**
 * Minimal stateless JWT (HS256) implementation.
 *
 * Sanctum issues opaque, database-backed tokens which cannot carry the
 * workspace / role / permission claims required for stateless tenancy, so
 * this dependency-free service signs and verifies compact JWTs instead.
 * The same token format serves both the web SPA and the mobile app.
 */
class JwtService
{
    /**
     * Issue a signed token for the given claims.
     *
     * @param  array<string, mixed>  $claims
     */
    public function issue(array $claims, int $ttlSeconds, string $type): string
    {
        $now = time();

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];

        $payload = $claims + [
            'iss' => config('app.name'),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'jti' => bin2hex(random_bytes(12)),
            'type' => $type,
        ];

        $segments = [
            $this->encode((string) json_encode($header, JSON_UNESCAPED_SLASHES)),
            $this->encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];

        $segments[] = $this->encode(
            hash_hmac('sha256', implode('.', $segments), $this->secret(), true),
        );

        return implode('.', $segments);
    }

    /**
     * Verify the signature and validity window, returning the payload claims.
     *
     * @return array<string, mixed>
     *
     * @throws JwtException
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new JwtException('Malformed token');
        }

        [$headerSegment, $payloadSegment, $signature] = $parts;

        $expected = $this->encode(
            hash_hmac('sha256', $headerSegment.'.'.$payloadSegment, $this->secret(), true),
        );

        if (! hash_equals($expected, $signature)) {
            throw new JwtException('Invalid token signature');
        }

        $header = json_decode($this->decode($headerSegment), true);
        $payload = json_decode($this->decode($payloadSegment), true);

        if (! is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            throw new JwtException('Unsupported token algorithm');
        }

        if (! is_array($payload)) {
            throw new JwtException('Invalid token payload');
        }

        if (($payload['exp'] ?? 0) < time()) {
            throw new JwtException('Token expired');
        }

        if (($payload['nbf'] ?? 0) > time() + 30) {
            throw new JwtException('Token not yet valid');
        }

        return $payload;
    }

    /**
     * The signing secret; falls back to the application key when unset.
     */
    protected function secret(): string
    {
        return (string) (config('tenancy.jwt_secret') ?: config('app.key'));
    }

    protected function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @throws JwtException
     */
    protected function decode(string $data): string
    {
        $remainder = strlen($data) % 4;
        $padded = $remainder === 0 ? $data : $data.str_repeat('=', 4 - $remainder);

        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);

        if ($decoded === false) {
            throw new JwtException('Invalid base64 segment');
        }

        return $decoded;
    }
}
