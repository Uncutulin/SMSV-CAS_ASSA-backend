<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('coberturas_aceptadas', function (Blueprint $table) {
            $table->string('src_file')->nullable()->after('fecha_vigencia');
            $table->string('batch_id')->nullable()->after('src_file');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coberturas_aceptadas', function (Blueprint $table) {
            $table->dropColumn(['src_file', 'batch_id']);
        });
    }
};
