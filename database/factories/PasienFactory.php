<?php

namespace Database\Factories;

use App\Models\Pasien;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Pasien>
 */
class PasienFactory extends Factory
{
    public function definition(): array
    {
        $jk = fake()->randomElement(['L', 'P']);

        return [
            'nik' => fake()->unique()->numerify('35##############'),
            'no_bpjs' => fake()->optional(0.6)->numerify('000#########'),
            'nama' => fake()->name($jk === 'L' ? 'male' : 'female'),
            'jenis_kelamin' => $jk,
            'tempat_lahir' => fake()->city(),
            'tanggal_lahir' => fake()->dateTimeBetween('-80 years', '-1 year')->format('Y-m-d'),
            'golongan_darah' => fake()->randomElement(['A', 'B', 'AB', 'O']),
            'alamat' => fake()->address(),
            'no_hp' => fake()->numerify('08##########'),
            'pekerjaan' => fake()->randomElement(['Wiraswasta', 'PNS', 'Pelajar', 'Ibu Rumah Tangga', 'Karyawan Swasta', 'Petani']),
        ];
    }
}
