<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Statuts « resolved » (marquée comme traitée par le gérant, sans réponse) et
     * « expired » (en attente depuis plus de 24 h, ou remplacée par une plus récente).
     */
    public function up(): void
    {
        $this->setStatuses(['pending', 'answered', 'ignored', 'resolved', 'expired']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('escalations')->whereIn('status', ['resolved', 'expired'])->update(['status' => 'ignored']);

        $this->setStatuses(['pending', 'answered', 'ignored']);
    }

    /**
     * La colonne vient d'un enum Laravel : sous PostgreSQL, un varchar avec une contrainte CHECK.
     *
     * @param  list<string>  $statuses
     */
    private function setStatuses(array $statuses): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $values = implode(', ', array_map(fn (string $status) => "'{$status}'", $statuses));

        DB::statement('ALTER TABLE escalations DROP CONSTRAINT IF EXISTS escalations_status_check');
        DB::statement("ALTER TABLE escalations ADD CONSTRAINT escalations_status_check CHECK (status IN ({$values}))");
    }
};
