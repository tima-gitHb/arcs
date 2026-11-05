<?php

namespace Database\Seeders;

use App\Services\EntryRuleImporter;
use Illuminate\Database\Seeder;

/**
 * The read-only entry rules (SR-49), from the Passport Index dataset by ilyankou
 * (MIT licence: see database/data/passport-index-LICENSE.txt).
 *
 * In tests it loads a small fixture instead of all 39,601 rows, because B8 seeds before every
 * test. Only test_SR49_the_import_command_loads_the_csv_and_can_run_twice reads the real file.
 */
class EntryRuleSeeder extends Seeder
{
    public const TEST_FIXTURE = 'database/data/entry-rules-test-fixture.csv';

    public function run(EntryRuleImporter $importer): void
    {
        $importer->import(app()->environment('testing') ? base_path(self::TEST_FIXTURE) : null);
    }
}
