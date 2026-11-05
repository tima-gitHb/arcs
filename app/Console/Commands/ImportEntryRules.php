<?php

namespace App\Console\Commands;

use App\Services\EntryRuleImporter;
use Illuminate\Console\Command;

class ImportEntryRules extends Command
{
    protected $signature = 'arcs:import-entry-rules';

    protected $description = 'Load the Passport Index CSV into entry_rules (safe to run again)';

    public function handle(EntryRuleImporter $importer): int
    {
        $this->info($importer->import().' entry rules loaded.');

        return self::SUCCESS;
    }
}
