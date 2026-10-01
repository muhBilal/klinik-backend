<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Foto profil disimpan sebagai data URI (gambar sudah diperkecil ke 256px di browser).
            // Cukup untuk jumlah akun staf klinik; bila kelak butuh foto besar/banyak, pindahkan ke disk `public` atau S3.
            $table->text('avatar')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar');
        });
    }
};
