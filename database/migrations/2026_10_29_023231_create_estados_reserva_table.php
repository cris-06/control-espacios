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
        Schema::create('estados_reserva', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('nombre', 50)->unique('nombre');
            $table->string('descripcion', 150)->nullable();
            $table->unsignedTinyInteger('orden')->unique('orden');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estados_reserva');
    }
};
