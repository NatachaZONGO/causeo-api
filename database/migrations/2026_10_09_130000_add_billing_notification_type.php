<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->setNotificationTypes(['escalation', 'order', 'appointment', 'whatsapp', 'billing']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('notifications')->where('type', 'billing')->delete();
        $this->setNotificationTypes(['escalation', 'order', 'appointment', 'whatsapp']);
    }

    /**
     * @param  list<string>  $types
     */
    private function setNotificationTypes(array $types): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $values = implode(', ', array_map(fn (string $type) => "'{$type}'", $types));

            DB::statement('ALTER TABLE notifications DROP CONSTRAINT IF EXISTS notifications_type_check');
            DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_type_check CHECK (type IN ({$values}))");

            return;
        }

        Schema::table('notifications', function (Blueprint $table) use ($types) {
            $table->enum('type', $types)->change();
        });
    }
};
