<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pengaturan klinik (key-value). Definisi & default ada di config/eklinik.php, baris hanya ada bila diubah admin.
        Schema::create('pengaturans', function (Blueprint $table) {
            $table->string('kunci', 100)->primary();
            $table->json('nilai')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pengaturans');
    }
};
