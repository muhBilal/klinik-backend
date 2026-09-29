<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pasiens', function (Blueprint $table) {
            $table->id();
            $table->string('no_rm', 10)->unique();
            $table->string('nik', 16)->nullable()->unique();
            $table->string('no_bpjs', 13)->nullable();
            $table->string('nama');
            $table->char('jenis_kelamin', 1); // L / P
            $table->string('tempat_lahir')->nullable();
            $table->date('tanggal_lahir');
            $table->string('golongan_darah', 3)->nullable();
            $table->text('alamat')->nullable();
            $table->string('no_hp', 20)->nullable();
            $table->string('pekerjaan')->nullable();
            $table->text('alergi')->nullable();
            $table->timestamps();

            $table->index('nama');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pasiens');
    }
};
