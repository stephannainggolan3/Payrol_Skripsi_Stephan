<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AlterUsersPasswordNvarchar extends Migration
{
    public function up()
    {
        // Ubah tipe kolom password menjadi NVARCHAR(255) pada SQL Server
        $this->db->query("ALTER TABLE users ALTER COLUMN password NVARCHAR(255) NOT NULL");
    }

    public function down()
    {
        $this->db->query("ALTER TABLE users ALTER COLUMN password VARCHAR(255) NOT NULL");
    }
}
