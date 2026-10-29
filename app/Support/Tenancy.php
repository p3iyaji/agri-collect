<?php

namespace App\Support;

/**
 * Per-request tenancy context.
 *
 * The active workspace is resolved exactly once per request from the JWT
 * claims (see ResolveActiveAccount middleware) and stored here, so the
 * BelongsToAccount global scope can read it without any database lookup.
 */
class Tenancy
{
    public static ?int $activeAccountId = null;

    public static function set(?int $accountId): void
    {
        self::$activeAccountId = $accountId;
    }

    public static function activeId(): ?int
    {
        return self::$activeAccountId;
    }

    public static function forget(): void
    {
        self::$activeAccountId = null;
    }
}
