<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FieldController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SubmissionController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| DigitCapture API
|--------------------------------------------------------------------------
|
| Stateless JWT authentication (see App\Services\JwtService). The access
| token embeds the active workspace (account_id), the user's role, and
| the role's permissions, so tenant + RBAC resolution never hits the
| database. Switching workspace is a token re-issue:
| POST /api/auth/switch-account.
|
*/

// -- Public ------------------------------------------------------------------

Route::post('auth/login', [AuthController::class, 'login']);
Route::post('auth/refresh', [AuthController::class, 'refresh']);

// Workspace-branded login pages: public profile by slug (logo, organization
// and field details only — never user or submission data).
Route::get('workspaces/{slug}/public-profile', [WorkspaceController::class, 'publicProfile']);

// -- Authenticated -----------------------------------------------------------

Route::middleware('jwt.auth')->group(function (): void {
    Route::post('auth/switch-account', [AuthController::class, 'switchAccount']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);

    // Workspace (account) self-service: list my workspaces, create new ones.
    Route::get('workspaces', [WorkspaceController::class, 'index']);
    Route::post('workspaces', [WorkspaceController::class, 'store']);

    // Everything below is scoped to the workspace carried by the JWT.
    Route::middleware(['account.resolve', 'account.required'])->group(function (): void {
        // Form builder
        Route::get('forms', [FormController::class, 'index']);
        Route::get('forms/{form}', [FormController::class, 'show']);

        Route::middleware('permission:forms.manage')->group(function (): void {
            Route::post('forms', [FormController::class, 'store']);
            Route::put('forms/{form}', [FormController::class, 'update']);
            Route::delete('forms/{form}', [FormController::class, 'destroy']);
        });

        // Submissions (read)
        Route::middleware('permission:submissions.read')->group(function (): void {
            Route::get('submissions', [SubmissionController::class, 'index']);
            Route::get('submissions/{submission}', [SubmissionController::class, 'show']);
        });

        // Idempotent offline sync (web outbox + mobile field app)
        Route::middleware('permission:submissions.write')->group(function (): void {
            Route::post('sync/submissions', [SyncController::class, 'submissions']);
            Route::post('sync/attachments', [SyncController::class, 'attachments']);
        });

        // Dashboards
        Route::middleware('permission:dashboard.read')->group(function (): void {
            Route::get('dashboard/summary', [DashboardController::class, 'summary']);
            Route::get('dashboard/field-stats', [DashboardController::class, 'fieldStats']);
        });

        // Workspace rename / delete — explicit URIs; the workspace in the
        // URL must equal the token's workspace (account.match), and the
        // user needs accounts.manage IN THAT WORKSPACE (permission check
        // reads the JWT claims of the active workspace).
        Route::patch('workspaces/{account}', [WorkspaceController::class, 'update'])
            ->middleware(['account.match', 'permission:accounts.manage']);
        Route::delete('workspaces/{account}', [WorkspaceController::class, 'destroy'])
            ->middleware(['account.match', 'permission:accounts.manage']);
        Route::post('workspaces/{account}/logo', [WorkspaceController::class, 'storeLogo'])
            ->middleware(['account.match', 'permission:accounts.manage']);
        Route::delete('workspaces/{account}/logo', [WorkspaceController::class, 'destroyLogo'])
            ->middleware(['account.match', 'permission:accounts.manage']);

        // Administration — members, roles, fields. The workspace in the URL
        // must match the token's workspace (EnsureWorkspaceMatches), and the
        // permission is the one granted in THAT workspace's JWT claims.
        Route::prefix('workspaces/{account}')->middleware('account.match')->group(function (): void {
            Route::middleware('permission:dashboard.read')->group(function (): void {
                Route::get('dashboard/summary', [DashboardController::class, 'summary']);
                Route::get('dashboard/field-stats', [DashboardController::class, 'fieldStats']);
            });

            Route::middleware('permission:exports.read')->group(function (): void {
                Route::get('exports/submissions', [ExportController::class, 'submissions']);
            });

            Route::middleware('permission:members.manage')->group(function (): void {
                Route::get('members', [MemberController::class, 'index']);
                Route::post('members', [MemberController::class, 'store']);
                Route::patch('members/{user}', [MemberController::class, 'update']);
                Route::delete('members/{user}', [MemberController::class, 'destroy']);
            });

            Route::middleware('permission:roles.manage')->group(function (): void {
                Route::get('roles', [RoleController::class, 'index']);
                Route::post('roles', [RoleController::class, 'store']);
                Route::patch('roles/{role}', [RoleController::class, 'update']);
                Route::delete('roles/{role}', [RoleController::class, 'destroy']);
            });

            Route::middleware('permission:forms.manage')->group(function (): void {
                Route::get('fields', [FieldController::class, 'index']);
            });

            // CSV import (idempotent per row, like the sync path).
            Route::middleware('permission:submissions.write')->group(function (): void {
                Route::post('imports/submissions', [ImportController::class, 'submissions']);
            });
        });
    });
});
