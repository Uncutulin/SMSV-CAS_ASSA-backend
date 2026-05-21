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
        Schema::create('coberturas_aceptadas', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('templateid')->nullable();
            $table->string('attach1')->nullable();
            $table->string('attach2')->nullable();
            $table->string('attach3')->nullable();
            $table->string('attach4')->nullable();
            $table->string('nombre')->nullable();
            $table->string('dni')->nullable();
            $table->string('numero_poliza')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('coberturas_aceptadas');
    }
};
