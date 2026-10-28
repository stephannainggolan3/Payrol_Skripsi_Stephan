<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;
use Config\Database;
use Config\Services;

class Ai extends ResourceController
{
    protected $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /**
     * Helper to send request to Google Gemini API
     */
    private function callGemini($prompt, $systemInstruction = '', $enableSearch = false, $tools = [])
    {
        $apiKeysString = env('GEMINI_API_KEY') ?: getenv('GEMINI_API_KEY');
        
        // Fallback jika API Key tidak diset
        if (empty($apiKeysString)) {
            return [
                'type' => 'text',
                'content' => $this->getMockResponse($prompt)
            ];
        }

        // Split keys by comma if multiple are provided
        $apiKeys = array_map('trim', explode(',', $apiKeysString));
        $apiKeys = array_filter($apiKeys); // remove empty keys

        if (empty($apiKeys)) {
            return [
                'type' => 'text',
                'content' => $this->getMockResponse($prompt)
            ];
        }

        // Tipe model: gunakan gemini-2.5-flash sebagai default tercepat dan termurah
        $model = 'gemini-2.5-flash';
        $client = Services::curlrequest();

        $contents = [];
        $contents[] = [
            'parts' => [
                ['text' => $prompt]
            ]
        ];

        $payload = [
            'contents' => $contents
        ];

        // Tambahkan tools (Google Search grounding / Function declarations) jika diaktifkan
        $toolsArray = [];
        if (!empty($tools)) {
            $toolsArray[] = [
                'functionDeclarations' => $tools
            ];
        }
        if ($enableSearch) {
            $toolsArray[] = [
                'google_search' => (object)[]
            ];
        }
        if (!empty($toolsArray)) {
            $payload['tools'] = $toolsArray;
        }

        // Tambahkan systemInstruction jika diset
        if (!empty($systemInstruction)) {
            $payload['systemInstruction'] = [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ];
        }

        $lastError = "Maaf, tidak ada API Key yang successfully merespon.";
        
        // Coba setiap API Key secara berurutan jika ada error / rate limit (429)
        foreach ($apiKeys as $index => $apiKey) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
            
            try {
                $response = $client->request('POST', $url, [
                    'headers' => [
                        'Content-Type' => 'application/json'
                    ],
                    'json' => $payload,
                    'http_errors' => false
                ]);

                $statusCode = $response->getStatusCode();
                $body = $response->getBody();

                if ($statusCode === 200) {
                    $result = json_decode($body, true);
                    $firstPart = $result['candidates'][0]['content']['parts'][0] ?? null;
                    
                    if ($firstPart) {
                        if (isset($firstPart['functionCall'])) {
                            return [
                                'type' => 'function_call',
                                'function_call' => [
                                    'name' => $firstPart['functionCall']['name'],
                                    'args' => $firstPart['functionCall']['args'] ?? []
                                ]
                            ];
                        } elseif (isset($firstPart['text'])) {
                            return [
                                'type' => 'text',
                                'content' => $firstPart['text']
                            ];
                        }
                    }
                    $lastError = "Maaf, AI tidak mengembalikan respon yang valid.";
                } else {
                    log_message('error', "Gemini API Error with Key #" . ($index + 1) . " (status {$statusCode}): " . $body);
                    $lastError = "Maaf, an error occurred saat menghubungi server AI (HTTP Status {$statusCode}). Silakan periksa configuration API Key Anda.";
                }
            } catch (\Exception $e) {
                log_message('error', "Gemini Connection Exception with Key #" . ($index + 1) . ": " . $e->getMessage());
                $lastError = "Koneksi ke AI terputus. Please try again in a few moments. Detail error: " . $e->getMessage();
            }
        }

        return [
            'type' => 'text',
            'content' => $lastError
        ];
    }

    /**
     * Fallback dummy responses if GEMINI_API_KEY is not defined
     */
    private function getMockResponse($prompt)
    {
        $promptLower = strtolower($prompt);
        
        if (strpos($promptLower, 'dashboard') !== false || strpos($promptLower, 'ringkas sistem') !== false) {
            return "### 📊 Rangkuman Eksekutif Sistem (Mode Simulasi Offline)\n\n" .
                   "Saat ini **Sistem Payroll** berjalan dengan baik. Berikut adalah rangkuman dari data master:\n\n" .
                   "1. **Karyawan Active**: Teremployee list aktif di all cabang.\n" .
                   "2. **Mitra Klien**: Company klien aktif yang dikelola payroll-nya.\n" .
                   "3. **Organization Structure**: Division, department, dan position telah diconfiguration secara lengkap.\n" .
                   "4. **Status Operasional**: All configuration cut-off absensi dan calculation UMP/UMK telah siap digunakan.\n\n" .
                   "> 💡 *Notes: Atur `GEMINI_API_KEY` di file `.env` untuk mendapatkan analisis data real-time bertenaga AI.*";
        }
        
        if (strpos($promptLower, 'payroll') !== false || strpos($promptLower, 'gaji') !== false) {
            return "### 💸 Analisis Report Payroll Klien (Mode Simulasi Offline)\n\n" .
                   "Berdasarkan data calculation payroll terbaru:\n\n" .
                   "- **Total Pembayaran**: Estimasi expenditure payroll monthan tercatat stabil.\n" .
                   "- **Denda & Overtime**: Overtime terdistribusi normal. Denda keterlambatan absensi otomatis dihitung berdasarkan scheme aktif.\n" .
                   "- **Tax & BPJS**: PPh21 (Gross/Net) dan persentase BPJS karyawan telah dipotong sesuai aturan pemerintah.\n\n" .
                   "All data payroll siap ditransfer ke rekening bank masing-masing karyawan.";
        }

        return "### 🤖 Asisten AI Payroll (Offline)\n\n" .
               "Halo! Saya adalah Asisten AI Payroll Anda. \n\n" .
               "Saat ini saya berjalan dalam **Mode Simulasi Offline** karena kunci API (`GEMINI_API_KEY`) belum terpasang di `.env`.\n\n" .
               "Hubungi admin untuk menyetel kunci API agar saya dapat menganalisis data keuangan, absensi, dan performa karyawan Anda secara langsung.";
    }

    /**
     * POST /api/ai/summarize-dashboard
     */
    public function summarizeDashboard()
    {
        try {
            // Fetch stats
            $totalClients = $this->db->table('clients')->countAllResults();
            $totalEmployees = $this->db->table('employees')->where('status', 'Active')->countAllResults();
            $totalDivisions = $this->db->table('divisions')->countAllResults();
            $totalDepartments = $this->db->table('departments')->countAllResults();
            $totalPositions = $this->db->table('positions')->countAllResults();

            // Recent system logs
            $recentLogs = $this->db->table('system_logs')
                                   ->select('action, description, user_name, created_at')
                                   ->orderBy('created_at', 'DESC')
                                   ->limit(5)
                                   ->get()
                                   ->getResultArray();

            $logsText = "";
            foreach ($recentLogs as $log) {
                $logsText .= "- [{$log['created_at']}] {$log['user_name']}: {$log['action']} ({$log['description']})\n";
            }

            // Construct prompt
            $prompt = "Berikut adalah data statistik utama Sistem Payroll hari ini:\n" .
                      "- Total Mitra Klien: {$totalClients}\n" .
                      "- Total Employees Active: {$totalEmployees}\n" .
                      "- Amount Division: {$totalDivisions}, Department: {$totalDepartments}, Position: {$totalPositions}\n\n" .
                      "Aktivitas Terkini di Sistem:\n{$logsText}\n" .
                      "Buatkan rangkuman eksekutif singkat dan profesional tentang status operasional company ini dalam 2-3 paragraf. Sebutkan jika ada aktivitas terbaru yang menonjol.";

            $systemInstruction = "Anda adalah Chief HR Officer virtual. Berikan rangkuman analisis yang profesional, formal, padat, dan optimis dalam Bahasa Indonesia. Gunakan Markdown formatting.";

            $geminiRes = $this->callGemini($prompt, $systemInstruction);
            $summary = $geminiRes['content'] ?? 'Failed membuat rangkuman dashboard.';

            return $this->respond([
                'status' => 200,
                'summary' => $summary
            ]);
        } catch (\Exception $e) {
            return $this->fail("Failed membuat rangkuman dashboard: " . $e->getMessage());
        }
    }

    /**
     * POST /api/ai/summarize-payroll
     */
    public function summarizePayroll()
    {
        try {
            $json = $this->request->getJSON();
            $clientId = $json->client_id ?? null;
            $periodId = $json->period_id ?? null;

            if (empty($clientId)) {
                return $this->fail("client_id harus diisi");
            }

            // Get Client name
            $client = $this->db->table('clients')->where('id', $clientId)->get()->getRowArray();
            if (!$client) {
                return $this->fail("Klien tidak ditemukan");
            }

            // Get Period
            $periodQuery = $this->db->table('payroll_periods')
                                    ->where('client_id', $clientId);
            if (!empty($periodId)) {
                $periodQuery->where('id', $periodId);
            } else {
                $periodQuery->orderBy('year', 'DESC')->orderBy('month', 'DESC');
            }
            $period = $periodQuery->get()->getRowArray();

            if (!$period) {
                return $this->respond([
                    'status' => 404,
                    'summary' => "Report payroll untuk klien ini belum tersedia karena period payroll aktif belum dibuat atau belum diproses."
                ]);
            }

            // Get Payroll Summary
            $payrolls = $this->db->table('payroll_final')
                                 ->select('
                                     SUM(payroll_final.gaji_pokok) as total_gp, 
                                     SUM(payroll_final.total_revenue - payroll_final.gaji_pokok - payroll_final.overtime_pay - payroll_final.bonus_tambahan) as total_tunj, 
                                     SUM(payroll_final.total_deduction) as total_pot, 
                                     SUM(payroll_final.take_home_pay) as total_thp, 
                                     COUNT(payroll_final.id) as emp_count,
                                     SUM(payroll_final.deduction_absen) as total_denda_alfa,
                                     SUM(payroll_final.bpjs_kes_karyawan) as total_bpjs_kes,
                                     SUM(payroll_final.bpjs_jht_karyawan) as total_bpjs_jht,
                                     SUM(payroll_final.bpjs_jp_karyawan) as total_bpjs_jp,
                                     SUM(CASE WHEN payroll_final.tax_method = \'Net\' OR payroll_final.tax_method = \'Nett\' THEN 0 ELSE payroll_final.pph21 END) as total_pph,
                                     SUM(payroll_final.jam_overtime) as total_ot_hours
                                 ')
                                 ->join('pkwt', 'pkwt.id = payroll_final.pkwt_id')
                                 ->where('payroll_final.period_id', $period['id'])
                                 ->where('pkwt.client_id', intval($clientId))
                                 ->get()
                                 ->getRowArray();

            if (empty($payrolls['emp_count'])) {
                return $this->respond([
                    'status' => 200,
                    'summary' => "Process payroll untuk period " . $period['month'] . "/" . $period['year'] . " belum dijalankan. Silakan jalankan 'Generate Salary' terlebih dahulu untuk menganalisis data."
                ]);
            }

            $totalGpVal = $payrolls['total_gp'] ?? 0;
            $totalTunjVal = $payrolls['total_tunj'] ?? 0;
            $totalPotVal = $payrolls['total_pot'] ?? 0;
            $totalThpVal = $payrolls['total_thp'] ?? 0;
            $totalDendaAlfaVal = $payrolls['total_denda_alfa'] ?? 0;
            $bpjsKaryawan = ($payrolls['total_bpjs_kes'] ?? 0) + ($payrolls['total_bpjs_jht'] ?? 0) + ($payrolls['total_bpjs_jp'] ?? 0);
            $pphKaryawan = $payrolls['total_pph'] ?? 0;

            $totalDendaTelatVal = max(0, $totalPotVal - $bpjsKaryawan - $pphKaryawan - $totalDendaAlfaVal);

            $totalGp = number_format($totalGpVal, 0, ',', '.');
            $totalTunj = number_format($totalTunjVal, 0, ',', '.');
            $totalPot = number_format($totalPotVal, 0, ',', '.');
            $totalThp = number_format($totalThpVal, 0, ',', '.');
            $dendaTelat = number_format($totalDendaTelatVal, 0, ',', '.');
            $dendaAlfa = number_format($totalDendaAlfaVal, 0, ',', '.');
            $otHours = number_format($payrolls['total_ot_hours'] ?? 0, 1, ',', '.');

            // Construct Prompt
            $prompt = "Berikut adalah summary data payroll klien {$client['nama']} untuk Period {$period['month']}/{$period['year']}:\n" .
                      "- Number of Employees Diproses: {$payrolls['emp_count']} orang\n" .
                      "- Total Base Salary: Rp {$totalGp}\n" .
                      "- Total Allowance: Rp {$totalTunj}\n" .
                      "- Total Deduction: Rp {$totalPot}\n" .
                      "- Total Take Home Pay (Dibayarkan): Rp {$totalThp}\n\n" .
                      "Detail Kehadiran & Denda:\n" .
                      "- Total Jam Overtime Karyawan: {$otHours} jam\n" .
                      "- Total Denda Terlambat Masuk: Rp {$dendaTelat}\n" .
                      "- Total Denda Absen Alfa: Rp {$dendaAlfa}\n\n" .
                      "Buatkan report analisis payroll eksekutif yang informatif. Berikan breakdown presentase allowance/deduction terhadap total pembayaran, sorot denda absensi jika valuenya tinggi, dan buat rekomendasi optimasi biaya HR.";

            $systemInstruction = "Anda adalah auditor keuangan dan analis kompensasi senior. Berikan analisis report payroll yang logis, tajam, profesional, dengan saran optimasi yang taktis. Gunakan format Markdown yang sangat rapi.";

            $geminiRes = $this->callGemini($prompt, $systemInstruction);
            $summary = $geminiRes['content'] ?? 'Failed merangkum payroll.';

            return $this->respond([
                'status' => 200,
                'summary' => $summary
            ]);
        } catch (\Exception $e) {
            return $this->fail("Failed merangkum payroll: " . $e->getMessage());
        }
    }

    /**
     * POST /api/ai/chat
     */
    public function chat()
    {
        try {
            $json = $this->request->getJSON(true);
            $userMessage = $json['message'] ?? '';
            $history = $json['history'] ?? [];
            $context = $json['context'] ?? [];

            if (empty($userMessage)) {
                return $this->fail("Pesan user cannot be empty");
            }

            // Build General Database Stats for AI Intelligence
            $totalClients = $this->db->table('clients')->countAllResults();
            $totalEmployees = $this->db->table('employees')->where('status', 'Active')->countAllResults();
            $totalDivisions = $this->db->table('divisions')->countAllResults();
            
            // Get Client Names
            $clients = $this->db->table('clients')->select('id, nama, sector')->limit(20)->get()->getResultArray();
            $clientsStr = "";
            foreach ($clients as $c) {
                $clientsStr .= "- [ID: {$c['id']}] {$c['nama']} ({$c['sector']})\n";
            }

            // Get Active periods
            $periods = $this->db->table('payroll_periods')
                                ->select('id, nama, month, year, status')
                                ->where('status', 'Open')
                                ->get()
                                ->getResultArray();
            $periodsStr = "";
            foreach ($periods as $p) {
                $periodsStr .= "- {$p['nama']} (Month: {$p['month']}/{$p['year']}, Status: {$p['status']})\n";
            }

            // Current context details from UI
            $currentView = $context['current_view'] ?? 'Dashboard';
            $selectedClientName = 'None';
            if (!empty($context['selected_client_id'])) {
                $selClient = $this->db->table('clients')->where('id', $context['selected_client_id'])->get()->getRow();
                if ($selClient) {
                    $selectedClientName = $selClient->nama;
                }
            }

            // Prepare prompt instructions
            $systemInstruction = "Anda adalah 'AI Assistant', asisten AI internal Sistem Payroll yang ramah, profesional, cerdas, dan dapat bertindak mengeksekusi perintah.\n" .
                                 "Tugas Anda adalah membantu user (HR Manager/Admin) memahami data, fitur, serta menjalankan aksi/perintah di aplikasi.\n" .
                                 "Jika user meminta Anda untuk membuka menu, melihat klien, pindah tab, atau menjalankan payroll, pilih dan panggil tool/fungsi yang sesuai secara langsung.\n" .
                                 "Anda memiliki akses ke Google Search. Jika user menanyakan information luar/eksternal (seperti holiday nasional, peraturan hukum ketenagakerjaan pemerintah Indonesia, pertaxan PPh, atau info UMP/UMK daerah terbaru, dll) yang tidak tersedia di dalam konteks sistem, gunakan fitur Google Search untuk mencari information terbaru di internet agar jawaban Anda akurat.\n" .
                                 "Gunakan data konteks sistem di bawah ini untuk menjawab hal-hal yang berkaitan dengan sistem payroll internal:\n\n" .
                                 "INFORMASI SISTEM AKTIF:\n" .
                                 "- Total Company Mitra (Klien): {$totalClients}\n" .
                                 "- Total Employees Active: {$totalEmployees}\n" .
                                 "- Total Divisions Organization Structure: {$totalDivisions}\n" .
                                 "DAFTAR KLIEN SISTEM:\n{$clientsStr}\n" .
                                 "PERIODE PAYROLL AKTIF (OPEN):\n{$periodsStr}\n" .
                                 "POSISI USER SAAT INI:\n" .
                                 "- Menu Active: {$currentView}\n" .
                                 "- Klien yang Sedang Dibuka: {$selectedClientName}\n";

            // Format chat history for Gemini
            $chatPrompt = "";
            if (!empty($history)) {
                $chatPrompt .= "History Percakapan Sebelumnya:\n";
                foreach ($history as $msg) {
                    $role = ($msg['role'] === 'user') ? 'User' : 'Asisten';
                    $chatPrompt .= "{$role}: {$msg['text']}\n";
                }
                $chatPrompt .= "\n";
            }

            $chatPrompt .= "Pertanyaan User: {$userMessage}\nAsisten:";

            // Definisikan tools yang bisa dieksekusi oleh AI di frontend/aplikasi
            $tools = [
                [
                    'name' => 'navigasi_menu',
                    'description' => 'Berpindah halaman ke menu utama tertentu di dalam aplikasi payroll.',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'menu' => [
                                'type' => 'STRING',
                                'description' => 'Nama menu tujuan: dashboard, klien, managementKaryawan, globalLokasiKerja, payroll, masterKompensasi, sto, tax, atau schedule.'
                            ]
                        ],
                        'required' => ['menu']
                    ]
                ],
                [
                    'name' => 'buka_workspace_klien',
                    'description' => 'Membuka workspace atau detail dashboard data mitra klien tertentu berdasarkan ID klien.',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'client_id' => [
                                'type' => 'INTEGER',
                                'description' => 'ID unik dari company klien.'
                            ]
                        ],
                        'required' => ['client_id']
                    ]
                ],
                [
                    'name' => 'pindah_tab_workspace',
                    'description' => 'Berpindah ke tab panel tertentu di dalam workspace klien yang aktif (tab: karyawan, structure, kompensasi, pkwt, proses).',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'tab' => [
                                'type' => 'STRING',
                                'description' => 'Nama tab tujuan: karyawan, structure, kompensasi, pkwt, atau proses.'
                            ]
                        ],
                        'required' => ['tab']
                    ]
                ],
                [
                    'name' => 'jalankan_generate_payroll',
                    'description' => 'Menjalankan proses generate gaji/kalkulasi payroll untuk company klien yang aktif.',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => (object)[]
                    ]
                ],
                [
                    'name' => 'buka_modal_tambah_klien',
                    'description' => 'Membuka modal pop-up form untuk menambahkan data mitra klien baru.',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => (object)[]
                    ]
                ],
                [
                    'name' => 'buka_modal_tambah_karyawan',
                    'description' => 'Membuka modal pop-up form untuk menambahkan data karyawan baru.',
                    'parameters' => [
                        'type' => 'OBJECT',
                        'properties' => (object)[]
                    ]
                ]
            ];

            $chatResult = $this->callGemini($chatPrompt, $systemInstruction, true, $tools);

            if (isset($chatResult['type']) && $chatResult['type'] === 'function_call') {
                return $this->respond([
                    'status' => 200,
                    'type' => 'action',
                    'action' => $chatResult['function_call']['name'],
                    'args' => $chatResult['function_call']['args'],
                    'message' => 'AI sedang menjalankan perintah: ' . str_replace('_', ' ', $chatResult['function_call']['name'])
                ]);
            }

            return $this->respond([
                'status' => 200,
                'type' => 'text',
                'reply' => $chatResult['content'] ?? 'Maaf, saya tidak mengerti maksud Anda.'
            ]);
        } catch (\Exception $e) {
            return $this->fail("Failed memproses percakapan AI: " . $e->getMessage());
        }
    }
}
