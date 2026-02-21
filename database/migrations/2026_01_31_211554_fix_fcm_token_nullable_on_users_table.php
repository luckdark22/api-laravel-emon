<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Ensure fcm_token is nullable and exists
            if (Schema::hasColumn('users', 'fcm_token')) {
                $table->text('fcm_token')->nullable()->change();
            } else {
                $table->text('fcm_token')->nullable()->after('password_hash');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Ideally we don't want to revert this to NOT NULL as it breaks things, 
        // but for strict reversibility:
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'fcm_token')) {
                // $table->text('fcm_token')->nullable(false)->change(); 
                // We leave it nullable in down too to avoid data loss issues during rollback
            }
        });
    }
};
