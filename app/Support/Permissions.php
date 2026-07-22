<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The catalogue of granular, per-action access a superadmin can grant a
 * staff_admin. Superadmin always has everything and can never be restricted;
 * buyers have none of these (their access is governed by tenancy, not this
 * list). Pricing and Users are deliberately NOT here - they stay superadmin-only
 * and are not delegable through the access table.
 *
 * Keys are "section.action". This is the single source of truth: the middleware
 * validates against it, the admin API rejects anything outside it, and the
 * frontend renders its table from the /admin/permissions/catalog endpoint so the
 * two never drift.
 */
final class Permissions
{
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

    public static function isValid(string $key): bool
    {
        return in_array($key, self::all(), true);
    }
}
