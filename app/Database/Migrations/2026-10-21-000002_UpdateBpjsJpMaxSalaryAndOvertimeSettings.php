<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class UpdateBpjsJpMaxSalaryAndOvertimeSettings extends Migration
{
    public function up()
    {
        // 1. Update batas upah JP di tax_schemes menjadi Rp10.547.400
        $this->db->query("
            UPDATE tax_schemes 
            SET bpjs_jp_max_salary = 10547400.00 
            WHERE bpjs_jp_max_salary = 10024600.00 
               OR bpjs_jp_max_salary IS NULL 
               OR bpjs_jp_max_salary = 0
        ");

        // 2. Update default constraint pada kolom bpjs_jp_max_salary (jika didukung)
        // 3. Update / insert di system_settings
        $settings = [
            [
                'setting_key'   => 'max_overtime_regular',
                'setting_value' => '4',
                'description'   => 'Batas maksimal jam lembur reguler per hari kerja (PP 35/2021)'
            ],
            [
                'setting_key'   => 'max_overtime_weekly',
                'setting_value' => '18',
                'description'   => 'Batas maksimal jam lembur reguler per minggu (PP 35/2021)'
            ],
            [
                'setting_key'   => 'bpjs_jp_max_salary',
                'setting_value' => '10547400',
                'description'   => 'Batas upah maksimal Jaminan Pensiun (JP) BPJS Ketenagakerjaan'
            ]
        ];

        foreach ($settings as $st) {
            $existing = $this->db->table('system_settings')->where('setting_key', $st['setting_key'])->get()->getRow();
            if ($existing) {
                $this->db->table('system_settings')->where('setting_key', $st['setting_key'])->update([
                    'setting_value' => $st['setting_value'],
                    'description'   => $st['description'],
                    'updated_at'    => date('Y-m-d H:i:s')
                ]);
            } else {
                $this->db->table('system_settings')->insert([
                    'setting_key'   => $st['setting_key'],
                    'setting_value' => $st['setting_value'],
                    'description'   => $st['description'],
                    'created_at'    => date('Y-m-d H:i:s')
                ]);
            }
        }
    }

    public function down()
    {
        $this->db->query("
            UPDATE tax_schemes 
            SET bpjs_jp_max_salary = 10024600.00 
            WHERE bpjs_jp_max_salary = 10547400.00
        ");

        $this->db->table('system_settings')->where('setting_key', 'max_overtime_regular')->update([
            'setting_value' => '3'
        ]);
        $this->db->table('system_settings')->where('setting_key', 'max_overtime_weekly')->delete();
        $this->db->table('system_settings')->where('setting_key', 'bpjs_jp_max_salary')->delete();
    }
}
