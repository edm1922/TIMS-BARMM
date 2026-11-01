<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Human-readable names for the actions recorded in the audit log.
 *
 * Actions are stored as technical keys ("beneficiary_batch.assigned"). The
 * audit page offers them as friendly labels grouped by the part of the system
 * they belong to. A few actions were renamed over time; each has one canonical
 * option that matches every name it has been recorded under.
 */
final class AuditActions
{
    /**
     * Group name => [canonical value => label].
     *
     * @var array<string, array<string, string>>
     */
    private const GROUPS = [
        'Sign-in activity' => [
            'auth.login' => 'Signed in',
            'auth.login_failed' => 'Failed sign-in',
            'auth.logout' => 'Signed out',
        ],
        'Beneficiaries' => [
            'beneficiary.created' => 'Registered a beneficiary',
            'beneficiary.updated' => 'Edited a beneficiary',
            'beneficiary.deleted' => 'Deleted a beneficiary',
            'beneficiary.duplicate_resolved' => 'Resolved a duplicate',
            'beneficiary.import_collision_restored' => 'Restored after an import collision',
        ],
        'Batches' => [
            'beneficiary_batch.created' => 'Created a batch',
            'beneficiary_batch.updated' => 'Updated a batch',
            'beneficiary_batch.deleted' => 'Deleted a batch',
            'beneficiary_batch.assigned' => 'Assigned a beneficiary to a batch',
            'beneficiary_batch.unassigned' => 'Unassigned beneficiaries from a batch',
        ],
        'Imports' => [
            'import.uploaded' => 'Uploaded an import file',
            'import.previewed' => 'Previewed an import',
            'import.applied' => 'Applied an import',
        ],
        'Exports and printing' => [
            'export.csv' => 'Exported a CSV',
            'export.xlsx' => 'Exported an Excel file',
            'id_card.printed' => 'Printed an ID card',
        ],
        'Accounts' => [
            'user.created' => 'Created a user',
            'user.deleted' => 'Deleted a user',
            'encoder.created' => 'Added an encoder',
            'encoder.updated' => 'Updated an encoder',
        ],
        'Offline installations' => [
            'installation.package.generated' => 'Generated an installation package',
            'installation.package.published' => 'Published an installation package',
            'installation.package.downloaded' => 'Downloaded an installation package',
            'installation.package.deleted' => 'Deleted an installation package',
            'installation.package.generation_failed' => 'Failed to generate an installation package',
            'installation.package.publish_failed' => 'Failed to publish an installation package',
            'offline_server.prepared' => 'Prepared an offline installation',
            'offline_server.reauthorized' => 'Re-authorized an offline installation',
            'sync_server.enrollment_token.created' => 'Created an enrollment token',
            'sync_server.reauthorization_token.created' => 'Created a re-authorization token',
            'sync_server.preparation_token.created' => 'Created a preparation token',
            'sync_server.token.rotated' => 'Rotated an installation token',
            'sync_server.status.updated' => 'Changed an installation status',
            'sync_server.scope.created' => 'Added a coverage area',
            'sync_server.scope.deleted' => 'Removed a coverage area',
            'sync_server.revoked' => 'Revoked an offline installation',
        ],
        'Sync requests' => [
            'sync.request_item_accepted' => 'Accepted a sync request',
            'sync.request_item_rejected' => 'Rejected a sync request',
        ],
    ];

    /**
     * Canonical value => every action key it has been recorded under.
     *
     * @var array<string, array<int, string>>
     */
    private const ALIASES = [
        'installation.package.generated' => [
            'enroll_server.package_generated',
            'offline_installation.package_generated',
        ],
        'installation.package.published' => [
            'enroll_server.package_published',
        ],
        'installation.package.downloaded' => [
            'enroll_server.package_downloaded',
            'offline_installation.package_downloaded',
        ],
        'installation.package.deleted' => [
            'enroll_server.package_deleted',
            'offline_installation.package_deleted',
        ],
        'installation.package.generation_failed' => [
            'enroll_server.package_generation_failed',
        ],
        'installation.package.publish_failed' => [
            'enroll_server.package_publish_failed',
        ],
    ];

    /**
     * Grouped filter options for the actions present in the log.
     *
     * @param  array<string, int|string>  $counts  action key => number of entries
     * @return array<string, array<int, array{value: string, label: string, count: int}>>
     */
    public static function options(array $counts): array
    {
        $totals = [];

        foreach ($counts as $action => $count) {
            $value = self::canonical((string) $action);
            $totals[$value] = ($totals[$value] ?? 0) + (int) $count;
        }

        $groups = [];

        foreach (self::GROUPS as $group => $actions) {
            foreach ($actions as $value => $label) {
                if (! isset($totals[$value])) {
                    continue;
                }

                $groups[$group][] = ['value' => $value, 'label' => $label, 'count' => $totals[$value]];

                unset($totals[$value]);
            }
        }

        // Actions added after this catalog was written still get an option.
        if ($totals !== []) {
            foreach ($totals as $value => $count) {
                $groups['Other'][] = [
                    'value' => $value,
                    'label' => Str::headline(str_replace(['.', '_'], ' ', (string) $value)),
                    'count' => $count,
                ];
            }

            usort($groups['Other'], fn (array $a, array $b): int => strcmp($a['label'], $b['label']));
        }

        return $groups;
    }

    /**
     * The action keys a filter value stands for.
     *
     * @return array<int, string>
     */
    public static function matches(string $value): array
    {
        return self::ALIASES[$value] ?? [$value];
    }

    /**
     * The single filter value an action key is grouped under.
     */
    public static function canonical(string $action): string
    {
        foreach (self::ALIASES as $value => $aliases) {
            if (in_array($action, $aliases, true)) {
                return $value;
            }
        }

        return $action;
    }

    /**
     * Canonical value => label, for naming actions in the log itself.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::GROUPS as $actions) {
            $labels += $actions;
        }

        return $labels;
    }

    /**
     * A readable name for a single recorded action.
     */
    public static function label(string $action): string
    {
        $value = self::canonical($action);

        return self::labels()[$value] ?? Str::headline(str_replace(['.', '_'], ' ', $value));
    }
}
