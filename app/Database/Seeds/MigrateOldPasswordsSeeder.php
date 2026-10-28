<?php

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;

class MigrateOldPasswordsSeeder extends Seeder
{
    public function run()
    {
        $db = \Config\Database::connect();
        $users = $db->table('users')->get()->getResultArray();

        $updatedCount = 0;
        $alreadyHashedCount = 0;

        foreach ($users as $user) {
            $currentPass = $user['password'];
            $info = password_get_info($currentPass);

            // Jika belum di-hash dengan bcrypt / standar password_hash
            if (empty($info['algo']) || $info['algoName'] === 'unknown' || !str_starts_with($currentPass, '$2y$')) {
                $hashedPass = password_hash($currentPass, PASSWORD_BCRYPT);
                $db->table('users')
                   ->where('id', $user['id'])
                   ->update(['password' => $hashedPass]);
                $updatedCount++;
            } else {
                $alreadyHashedCount++;
            }
        }

        echo "Selesai migrasi password: {$updatedCount} password di-hash ulang, {$alreadyHashedCount} sudah dalam format hash.\n";
    }
}
