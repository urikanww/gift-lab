<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The catalogue of granular, per-action access a superadmin can grant a
 * staff_admin. Superadmin always has everything and can never be restricted;
 * buyers have none of these (their access is governed by tenancy, not this
 * list).
 *
 * Two sections are SENSITIVE - Pricing (financial config) and Users (account
 * management). They are grantable, but only ever EXPLICITLY: they are excluded
 * from the grandfather default (see defaults()), so an existing staff_admin does
 * not silently gain them on rollout, and only a superadmin may delegate them
 * (enforced in AdminUserController) to stop staff spreading sensitive access.
 *
 * Keys are "section.action". This is the single source of truth: the middleware
 * validates against it, the admin API rejects anything outside it, and the
 * frontend renders its table from the /admin/permissions/catalog endpoint so the
 * two never drift.
 */
final class Permissions
{
    /**
     * Sections that are never granted by the grandfather default and only a
     * superadmin may delegate. Everything else is operational.
     *
     * @var list<string>
     */
    public const SENSITIVE_SECTIONS = ['pricing', 'users'];

    /**
     * Grouped for display AND enforcement. Order here is the order the access
     * table renders in.
     *
     * @var array<string, array{label: string, actions: array<string, string>}>
     */
    public const CATALOG = [
        'quotes' => [
            'label' => 'Quotes',
            'actions' => [
                'view' => 'View orders',
                'edit' => 'Create, amend, send & cancel orders',
            ],
        ],
        'production' => [
            'label' => 'Production',
            'actions' => [
                'view' => 'View the production queue',
                'manage' => 'Advance jobs and create shipments',
            ],
        ],
        'procurement' => [
            'label' => 'Procurement',
            'actions' => [
                'view' => 'View the procurement desk',
                'manage' => 'Reconfirm lines',
            ],
        ],
        'reorders' => [
            'label' => 'Buy-list',
            'actions' => [
                'view' => 'View the supplier buy-list',
                'manage' => 'Mark reorders received',
            ],
        ],
        'products' => [
            'label' => 'Products',
            'actions' => [
                'view' => 'View the catalogue',
                'edit' => 'Add and edit products',
                'approve' => 'Publish and approve products',
            ],
        ],
        'notifications' => [
            'label' => 'Notifications',
            'actions' => [
                'view' => 'View notification settings',
                'manage' => 'Change notification settings',
            ],
        ],
        // Sensitive - see SENSITIVE_SECTIONS. Grantable, but never by default and
        // only by a superadmin.
        'pricing' => [
            'label' => 'Pricing',
            'actions' => [
                'view' => 'View pricing & config and cost breakdowns',
                'manage' => 'Edit pricing & config',
            ],
        ],
        'users' => [
            'label' => 'Users',
            'actions' => [
                'view' => 'View user accounts',
                'manage' => 'Create, edit, deactivate users and set access',
            ],
        ],
    ];

    /**
     * Every valid permission key, flat ("quotes.view", "quotes.edit", ...).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $keys = [];
        foreach (self::CATALOG as $section => $meta) {
            foreach (array_keys($meta['actions']) as $action) {
                $keys[] = "{$section}.{$action}";
            }
        }

        return $keys;
    }

    /**
     * The grandfather default: every OPERATIONAL permission, excluding the
     * sensitive sections. A staff_admin with no explicit allowlist resolves to
     * this, so existing staff keep their operational access but never gain
     * Pricing or Users without an explicit grant.
     *
     * @return list<string>
     */
    public static function defaults(): array
    {
        $keys = [];
        foreach (self::CATALOG as $section => $meta) {
            if (in_array($section, self::SENSITIVE_SECTIONS, true)) {
                continue;
            }
            foreach (array_keys($meta['actions']) as $action) {
                $keys[] = "{$section}.{$action}";
            }
        }

        return $keys;
    }

    /** Whether a permission key belongs to a sensitive section. */
    public static function isSensitive(string $key): bool
    {
        $section = explode('.', $key, 2)[0];

        return in_array($section, self::SENSITIVE_SECTIONS, true);
    }

    public static function isValid(string $key): bool
    {
        return in_array($key, self::all(), true);
    }
}
