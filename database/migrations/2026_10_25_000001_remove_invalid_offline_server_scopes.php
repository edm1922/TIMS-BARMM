<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Remove server scopes that point at no known BARMM location.
     *
     * Such scopes silently reject every record routed to that server, so they
     * are dropped in favour of a valid scope being assigned by an admin.
     */
    public function up(): void
    {
        $prefixes = DB::table('locations')
            ->selectRaw('DISTINCT SUBSTR(psgc_code, 1, 4) AS prefix')
            ->pluck('prefix')
            ->filter()
            ->all();

        if ($prefixes === []) {
            return;
        }

        DB::table('offline_server_scopes')
            ->whereNotNull('location_code')
            ->orderBy('id')
            ->get()
            ->each(function ($scope) use ($prefixes): void {
                $prefix = $this->prefixFor((string) $scope->scope_type, (string) $scope->location_code);

                if ($prefix === null) {
                    return;
                }

                foreach ($prefixes as $known) {
                    if (str_starts_with((string) $known, $prefix)) {
                        return;
                    }
                }

                DB::table('offline_server_scopes')->where('id', $scope->id)->delete();
            });
    }

    /**
     * The PSGC prefix a scope matches, or null when the scope type has no
     * single prefix.
     */
    private function prefixFor(string $scopeType, string $locationCode): ?string
    {
        $code = trim($locationCode);

        if ($code === '') {
            return null;
        }

        return match ($scopeType) {
            'province' => substr($code, 0, 4),
            'city', 'city_municipality' => substr($code, 0, 7),
            'barangay' => $code,
            'psgc' => str_ends_with($code, '000000')
                ? substr($code, 0, 4)
                : (str_ends_with($code, '000') ? substr($code, 0, 7) : $code),
            default => null,
        };
    }

    /**
     * Reverse the migrations. Removed scopes cannot be restored automatically.
     */
    public function down(): void
    {
        //
    }
};
