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
            $table->string('fecha_vigencia')->nullable()->after('numero_poliza');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('coberturas_aceptadas', function (Blueprint $table) {
            $table->dropColumn('fecha_vigencia');
        });
    }
};
