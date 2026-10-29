<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stateless JWT tenancy
    |--------------------------------------------------------------------------
    |
    | The active workspace (account_id), the user's role, and their RBAC
    | permissions are embedded in the JWT access token itself. Tenant
    | resolution therefore never hits the database, and switching
    | workspace is simply a token re-issue (POST /api/auth/switch-account).
    |
    */

    'jwt_secret' => env('TENANCY_JWT_SECRET'),

    /* Access token lifetime in seconds (short-lived). */
    'access_ttl' => (int) env('TENANCY_ACCESS_TTL', 900),

    /* Refresh token lifetime in seconds (default: 7 days). */
    'refresh_ttl' => (int) env('TENANCY_REFRESH_TTL', 604800),

    /*
    |--------------------------------------------------------------------------
    | Default roles
    |--------------------------------------------------------------------------
    |
    | Permissions belong to roles; roles are created per workspace and
    | assigned to users within the context of that workspace (a user can be
    | Owner in one workspace and Enumerator in another).
    |
    */

    /*
     * Machine-readable permission catalog (permission => human label).
     * This is the closed set that roles can be granted — the UI renders it
     * as checkboxes and validation rejects anything outside it.
     */

    'permission_catalog' => [
        'forms.manage' => 'Create, edit, and archive forms',
        'submissions.read' => 'View submissions',
        'submissions.write' => 'Submit and sync field data',
        'dashboard.read' => 'View dashboards and statistics',
        'exports.read' => 'Export submission data',
        'members.manage' => 'Invite, reassign, and remove members',
        'roles.manage' => 'Create and edit roles and permissions',
        'accounts.manage' => 'Rename, configure, and delete workspaces',
    ],

    'default_roles' => [
        'Owner' => [
            'forms.manage',
            'submissions.read',
            'submissions.write',
            'dashboard.read',
            'exports.read',
            'members.manage',
            'roles.manage',
            'accounts.manage',
        ],
        'Manager' => [
            'forms.manage',
            'submissions.read',
            'submissions.write',
            'dashboard.read',
            'exports.read',
            'members.manage',
        ],
        'Enumerator' => [
            'submissions.read',
            'submissions.write',
            'dashboard.read',
        ],
        'Viewer' => [
            'submissions.read',
            'dashboard.read',
            'exports.read',
        ],
    ],

];
