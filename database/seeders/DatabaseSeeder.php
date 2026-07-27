<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Optionnel: créer un user de test
        // User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);

        // Import depuis JSON si DB vide (déjà en place)
        $this->call(JsonImportSeeder::class);
        // Injecter des données d'exemple
        $this->call(SampleDataSeeder::class);
    }
}
