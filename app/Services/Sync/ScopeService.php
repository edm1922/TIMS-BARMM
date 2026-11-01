<?php

namespace App\Services\Sync;

use App\Models\Location;
use App\Models\OfflineServer;
use App\Models\OfflineServerScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ScopeService
{
    public const SCOPE_TYPE_REGION = 'region';

    public const REGION_SENTINEL = 'BARMM';

    /**
     * Province prefixes covered by the BARMM region. Used as a fallback when
     * the reference locations table has not been seeded yet.
     *
     * @var array<int, string>
     */
    private const BARMM_PROVINCE_PREFIXES = ['1900', '1903', '1906', '1907', '1908', '1999'];

    /**
     * @return array<int, string>
     */
    public function codes(OfflineServer $server): array
    {
        $scopes = $server->scopes()->whereNotNull('location_code')->get();

        $codes = [];
        $coversRegion = false;

        foreach ($scopes as $scope) {
            if ($scope->scope_type === self::SCOPE_TYPE_REGION) {
                $coversRegion = true;

                continue;
            }

            $codes[] = $scope->location_code;
        }

        if ($coversRegion) {
            $codes = array_merge($codes, $this->regionPrefixes());
        }

        return array_values(array_unique(array_filter($codes)));
    }

    public function allowsLocationCode(OfflineServer $server, ?string $locationCode): bool
    {
        $locationCode = trim((string) $locationCode);

        if ($locationCode === '') {
            return false;
        }

        foreach ($this->prefixes($server) as $prefix) {
            if (str_starts_with($locationCode, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Apply the server's PSGC scope to a beneficiary query.
     * Includes records matching the scope prefixes, plus unassigned records
     * (NULL location_code) so they are available for offline assignment.
     */
    public function applyToBeneficiaryQuery(Builder $query, OfflineServer $server): Builder
    {
        $prefixes = $this->prefixes($server);

        if ($prefixes === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scopeQuery) use ($prefixes): void {
            // Records matching scope prefixes
            foreach ($prefixes as $prefix) {
                $scopeQuery->orWhere('location_code', 'like', $prefix.'%');
            }
            // Also include unassigned records (NULL location_code)
            $scopeQuery->orWhereNull('location_code');
        });
    }

    /**
     * PSGC hierarchy prefixes: province (4 digits), city/municipality (7),
     * barangay (10). A raw PSGC code can be normalised by its trailing zeroes.
     *
     * @return array<int, string>
     */
    public function prefixes(OfflineServer $server): array
    {
        $scopes = $server->scopes()->whereNotNull('location_code')->get();

        $prefixes = [];
        $coversRegion = false;

        foreach ($scopes as $scope) {
            if ($scope->scope_type === self::SCOPE_TYPE_REGION) {
                $coversRegion = true;

                continue;
            }

            $prefixes[] = $this->prefixFor($scope);
        }

        if ($coversRegion) {
            $prefixes = array_merge($prefixes, $this->regionPrefixes());
        }

        return array_values(array_unique(array_filter($prefixes)));
    }

    /**
     * Province prefixes covered by a whole-region scope.
     *
     * Derived from the reference locations table so the scope follows any
     * change to the seeded BARMM dataset, falling back to the known province
     * prefixes when the table is empty.
     *
     * @return array<int, string>
     */
    public function regionPrefixes(): array
    {
        if (! Location::query()->exists()) {
            return self::BARMM_PROVINCE_PREFIXES;
        }

        return Location::query()
            ->selectRaw('DISTINCT SUBSTR(psgc_code, 1, 4) AS prefix')
            ->pluck('prefix')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function prefixFor(OfflineServerScope $scope): string
    {
        return $this->normalizeScopePrefix($scope->scope_type, $scope->location_code);
    }

    public function applyToLocationQuery(Builder $query, OfflineServer $server, string $column = 'psgc_code'): Builder
    {
        $prefixes = $this->prefixes($server);

        if ($prefixes === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $scopeQuery) use ($prefixes, $column): void {
            foreach ($prefixes as $prefix) {
                $scopeQuery->orWhere($column, 'like', $prefix.'%');
            }
        });
    }

    /**
     * Normalize a scope into the PSGC prefix used to match locations.
     *
     * Province codes are truncated to 4 digits, city/municipality to 7, and
     * barangay codes are used verbatim. A bare PSGC code is normalized by its
     * trailing zeroes when its type is ambiguous. A region scope has no single
     * prefix; it expands to every BARMM province prefix instead.
     */
    public function normalizeScopePrefix(string $scopeType, string $scopeCode): string
    {
        $code = trim((string) $scopeCode);

        if ($code === '') {
            return '';
        }

        return match ($scopeType) {
            'province' => substr($code, 0, 4),
            'city', 'city_municipality' => substr($code, 0, 7),
            'barangay' => $code,
            self::SCOPE_TYPE_REGION => '',
            default => str_ends_with($code, '000000')
                ? substr($code, 0, 4)
                : (str_ends_with($code, '000') ? substr($code, 0, 7) : $code),
        };
    }

    /**
     * Valid BARMM province codes mapped to their province names, used as
     * reference data for scope inputs.
     *
     * @return array<string, string>
     */
    public function provinceSuggestions(): array
    {
        return Location::query()
            ->distinct()
            ->pluck('province', DB::raw('substr(psgc_code, 1, 4) AS code'))
            ->sortKeys()
            ->all();
    }

    /**
     * A short reference of valid province codes, for use as helper text next
     * to scope inputs.
     */
    public function provinceExampleText(): string
    {
        return collect(array_slice($this->provinceSuggestions(), 0, 5, true))
            ->map(fn (string $province, string $code): string => $code.' ('.$province.')')
            ->join(', ');
    }

    /**
     * Whether a scope matches at least one location in the BARMM reference
     * data. Used to reject enrollment and scope changes whose code points at
     * nothing (which would leave the server with no beneficiaries or areas).
     *
     * Returns true (allow) when the locations table is empty, so that seeding
     * and test environments can proceed without pre-existing reference data.
     */
    public function scopeMatchesLocations(string $scopeType, string $scopeCode): bool
    {
        if ($scopeType === self::SCOPE_TYPE_REGION) {
            return true;
        }

        $prefix = $this->normalizeScopePrefix($scopeType, $scopeCode);

        if ($prefix === '') {
            return false;
        }

        if (! Location::query()->exists()) {
            return true;
        }

        return Location::query()->where('psgc_code', 'like', $prefix.'%')->exists();
    }

    /**
     * @param  array<int, array{scope_type?: string, location_code: string, province?: ?string, city_municipality?: ?string, barangay?: ?string}>  $scopes
     */
    public function replace(OfflineServer $server, array $scopes): void
    {
        foreach ($scopes as $scope) {
            $scopeType = (string) ($scope['scope_type'] ?? 'psgc');
            $locationCode = trim((string) ($scope['location_code'] ?? ''));

            if ($locationCode === '') {
                continue;
            }

            if (! $this->scopeMatchesLocations($scopeType, $locationCode)) {
                throw new InvalidArgumentException(
                    'Scope '.$locationCode.' ('.$scopeType.') does not match any BARMM location.'
                );
            }
        }

        DB::transaction(function () use ($server, $scopes): void {
            $server->scopes()->delete();

            foreach ($scopes as $scope) {
                $locationCode = trim((string) ($scope['location_code'] ?? ''));

                if ($locationCode === '') {
                    continue;
                }

                $server->scopes()->create([
                    'scope_type' => $scope['scope_type'] ?? 'psgc',
                    'location_code' => $locationCode,
                    'province' => $scope['province'] ?? null,
                    'city_municipality' => $scope['city_municipality'] ?? null,
                    'barangay' => $scope['barangay'] ?? null,
                ]);
            }
        });
    }

    public function hasScope(OfflineServer $server): bool
    {
        return $server->scopes()->whereNotNull('location_code')->exists();
    }
}
