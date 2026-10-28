<?php

namespace App\Controllers;

use CodeIgniter\RESTful\ResourceController;

class Api extends ResourceController
{
    use \App\Traits\AuthTrait;

    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function getNotifications()
    {
        $notifications = [];

        // 1. Check if there are active clients
        $clients = $this->db->table('clients')->get()->getResultArray();
        
        // Get current month and year
        $currentMonth = intval(date('n'));
        $currentYear = intval(date('Y'));
        
        $monthsWord = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];
        $monthName = $monthsWord[$currentMonth] ?? date('F');

        foreach ($clients as $client) {
            // Check if there is an active payroll period for this client, month, year
            $period = $this->db->table('payroll_periods')
                               ->where('client_id', $client['id'])
                               ->where('month', $currentMonth)
                               ->where('year', $currentYear)
                               ->get()
                               ->getRow();
            if (!$period) {
                $notifications[] = [
                    'id' => 'cutoff_' . $client['id'],
                    'type' => 'warning',
                    'title' => 'Cut-off Period Belum Dibuat',
                    'message' => "Klien <strong>" . esc($client['nama']) . "</strong> belum memiliki tanggal cut-off / period aktif untuk month " . esc($monthName) . " " . esc($currentYear) . "!",
                    'link' => 'klien',
                    'client_id' => intval($client['id']),
                    'client_name' => $client['nama'],
                    'client_sector' => $client['sector'] ?? $client['sektor'] ?? ''
                ];
            } else {
                // Period exists, check if attendance is uploaded H-3 before cutoff
                $config = $this->db->table('client_payroll_configs')
                                   ->where('client_id', $client['id'])
                                   ->get()
                                   ->getRow();
                if ($config) {
                    $daysInMonth = date('t', mktime(0, 0, 0, $currentMonth, 1, $currentYear));
                    $cutoffStart = $config->cutoff_start ? intval($config->cutoff_start) : 21;
                    $cutoffEnd = $config->cutoff_end ? intval($config->cutoff_end) : 20;
                    if ($cutoffStart <= 1) {
                        $cutoffDay = $daysInMonth;
                    } else {
                        $cutoffDay = min($cutoffEnd, $daysInMonth);
                    }
                    $cutoffEndDateStr = sprintf('%d-%02d-%02d', $currentYear, $currentMonth, $cutoffDay);
                    
                    $todayStr = date('Y-m-d');
                    $todayTime = strtotime($todayStr);
                    $cutoffTime = strtotime($cutoffEndDateStr);
                    $diffDays = intval(round(($cutoffTime - $todayTime) / 86400));

                    if ($diffDays <= 3) {
                        // Check if client has uploaded attendance
                        $employees = $this->db->table('employees')->where('client_id', $client['id'])->get()->getResultArray();
                        $employeeIds = array_column($employees, 'id');
                        
                        $hasUploaded = false;
                        if (!empty($employeeIds)) {
                            $payoutPeriodStr = $currentMonth . '-' . $currentYear;
                            $attendanceCount = $this->db->table('attendance_logs')
                                                        ->whereIn('employee_id', $employeeIds)
                                                        ->where('payout_period', $payoutPeriodStr)
                                                        ->countAllResults();
                            if ($attendanceCount > 0) {
                                    $hasUploaded = true;
                            }
                        }
                        
                        if (!$hasUploaded && !empty($employeeIds)) {
                            if ($diffDays >= 0) {
                                $daysText = $diffDays == 0 ? "hari ini adalah hari terakhir" : "tinggal " . $diffDays . " hari lagi";
                                $notifications[] = [
                                    'id' => 'attendance_cutoff_warning_' . $client['id'],
                                    'type' => 'warning',
                                    'title' => 'Absensi Belum Diunggah (H-' . $diffDays . ')',
                                    'message' => "Klien <strong>" . esc($client['nama']) . "</strong> belum mengunggah data absensi. Batas akhir cut-off adalah tanggal " . esc($cutoffDay) . " " . esc($monthName) . " " . esc($currentYear) . " (" . $daysText . ")!",
                                    'link' => 'attendance',
                                    'client_id' => intval($client['id']),
                                    'client_name' => $client['nama'],
                                    'client_sector' => $client['sector']
                                ];
                            } else {
                                $lateDays = abs($diffDays);
                                $notifications[] = [
                                    'id' => 'attendance_cutoff_late_' . $client['id'],
                                    'type' => 'error',
                                    'title' => 'Terlambat Mengunggah Absensi (Telat ' . $lateDays . ' Day)',
                                    'message' => "Klien <strong>" . esc($client['nama']) . "</strong> terlambat mengunggah data absensi. Batas akhir cut-off adalah tanggal " . esc($cutoffDay) . " " . esc($monthName) . " " . esc($currentYear) . " (terlewat " . $lateDays . " hari)!",
                                    'link' => 'attendance',
                                    'client_id' => intval($client['id']),
                                    'client_name' => $client['nama'],
                                    'client_sector' => $client['sector']
                                ];
                            }
                        }
                    }
                }
            }
        }

        // 2. Check if there are any payrolls on hold (PKWT)
        $holdPayrolls = $this->db->table('payroll_final')
                                 ->select('payroll_final.*, pkwt.employee_name, clients.nama as client_name, clients.sector as client_sector, payroll_periods.month, payroll_periods.year, clients.id as client_id')
                                 ->join('pkwt', 'pkwt.id = payroll_final.pkwt_id')
                                 ->join('clients', 'clients.id = pkwt.client_id')
                                 ->join('payroll_periods', 'payroll_periods.id = payroll_final.period_id')
                                 ->where('payroll_final.status_approval', 'Hold')
                                 ->get()
                                 ->getResultArray();
                                 
        $months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
        foreach ($holdPayrolls as $hp) {
            $periodName = ($months[intval($hp['month']) - 1] ?? '') . " " . $hp['year'];
            $isRapel = (floatval($hp['take_home_pay'] ?? 0) == 0);
            
            $notifications[] = [
                'id' => 'payroll_hold_' . $hp['id'],
                'type' => 'error',
                'title' => $isRapel ? 'Gaji Dirapel (Hold)' : 'Gaji Ditunda (Hold)',
                'message' => $isRapel 
                    ? "Gaji karyawan <strong>" . esc($hp['employee_name']) . "</strong> (Klien: " . esc($hp['client_name']) . ") pada period " . esc($periodName) . " ditunda karena karyawan baru bergabung setelah tanggal cut-off (gaji akan dirapel month depan)."
                    : "Gaji karyawan <strong>" . esc($hp['employee_name']) . "</strong> (Klien: " . esc($hp['client_name']) . ") pada period " . esc($periodName) . " ditunda karena terdapat ketidakhadiran (absen) di working days setelah cut-off (gaji akan dirapel digabungkan dengan month depan).",
                'link' => 'process',
                'client_id' => intval($hp['client_id']),
                'client_name' => $hp['client_name'],
                'client_sector' => $hp['client_sector']
            ];
        }

        // 3. Check if there are any payrolls on hold (Legacy / Regular)
        $holdPayrollsLegacy = $this->db->table('payrolls')
                                       ->select('payrolls.*, employees.nama as employee_name, clients.nama as client_name, clients.sector as client_sector, clients.id as client_id')
                                       ->join('employees', 'employees.id = payrolls.employee_id')
                                       ->join('clients', 'clients.id = employees.client_id')
                                       ->where('payrolls.status_pembayaran', 'Hold')
                                       ->get()
                                       ->getResultArray();
                                       
        foreach ($holdPayrollsLegacy as $hp) {
            $monthsWord = [
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
                7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
            ];
            $pName = ($monthsWord[intval($hp['month'])] ?? '') . ' ' . $hp['year'];
            $isRapel = (floatval($hp['take_home_pay'] ?? 0) == 0);
            
            $notifications[] = [
                'id' => 'payroll_hold_legacy_' . $hp['id'],
                'type' => 'error',
                'title' => $isRapel ? 'Gaji Dirapel (Hold)' : 'Gaji Ditunda (Hold)',
                'message' => $isRapel
                    ? "Gaji karyawan <strong>" . esc($hp['employee_name']) . "</strong> (Klien: " . esc($hp['client_name']) . ") pada period " . esc($pName) . " ditunda karena karyawan baru bergabung setelah tanggal cut-off (gaji akan dirapel month depan)."
                    : "Gaji karyawan <strong>" . esc($hp['employee_name']) . "</strong> (Klien: " . esc($hp['client_name']) . ") pada period " . esc($pName) . " ditunda karena terdapat ketidakhadiran (absen) di working days setelah cut-off (gaji akan dirapel digabungkan dengan month depan).",
                'link' => 'process',
                'client_id' => intval($hp['client_id']),
                'client_name' => $hp['client_name'],
                'client_sector' => $hp['client_sector']
            ];
        }

        // Filter out dismissed notifications
        $dismissed = $this->db->table('dismissed_notifications')->get()->getResultArray();
        $dismissedIds = array_column($dismissed, 'notification_id');
        if (!empty($dismissedIds)) {
            $notifications = array_values(array_filter($notifications, function($n) use ($dismissedIds) {
                return !in_array($n['id'], $dismissedIds);
            }));
        }

        return $this->respond([
            'status' => 200,
            'data' => $notifications,
            'count' => count($notifications)
        ]);
    }

    public function dismissNotification()
    {
        $json = $this->request->getJSON();
        $notificationId = $json->notification_id ?? '';

        if (empty($notificationId)) {
            return $this->fail('Notification ID is required');
        }

        $exists = $this->db->table('dismissed_notifications')->where('notification_id', $notificationId)->countAllResults();
        if ($exists === 0) {
            $this->db->table('dismissed_notifications')->insert([
                'notification_id' => $notificationId,
                'dismissed_at' => date('Y-m-d H:i:s')
            ]);
        }

        return $this->respond([
            'status' => 200,
            'message' => 'Notification dismissed successfully'
        ]);
    }

    // --- AUTH ---
    public function login()
    {
        $json = $this->request->getJSON();
        $usernameOrEmail = trim($json->username ?? '');
        $password = $json->password ?? '';

        if (empty($usernameOrEmail) || empty($password)) {
            return $this->fail('Username/Email dan password required');
        }

        // Cek apakah input adalah email dengan memindai karakter '@'
        $isEmail = strpos($usernameOrEmail, '@') !== false;

        $query = $this->db->table('users');
        if ($isEmail) {
            // Validasi email
            if (!filter_var($usernameOrEmail, FILTER_VALIDATE_EMAIL)) {
                return $this->fail('Format email is invalid');
            }
            $query->where('email', $usernameOrEmail);
        } else {
            $query->where('username', $usernameOrEmail);
        }

        $user = $query->get()->getRow();
        $isPasswordValid = false;
        if ($user) {
            $isPasswordValid = password_verify($password, $user->password);
            if (!$isPasswordValid && $password === $user->password) {
                $isPasswordValid = true;
                $this->db->table('users')->where('id', $user->id)->update([
                    'password' => password_hash($password, PASSWORD_BCRYPT)
                ]);
            }
        }

        if ($user && $isPasswordValid) {
            // Cek apakah user aktif
            if (isset($user->is_active) && !$user->is_active) {
                return $this->failUnauthorized('Akun Anda telah dinonaktifkan. Hubungi administrator.');
            }

            // Cek apakah role masih pending
            if (($user->role ?? '') === 'pending') {
                return $this->failUnauthorized('Akun Anda belum disetujui atau belum diberi role oleh Administrator.');
            }

            $userClientModel = new \App\Models\UserClientModel();
            $allowedClientIds = [];
            $role = $user->role ?? 'admin';

            if ($role === 'admin') {
                $allowedClientIds = ['all'];
            } else {
                $allowedClientIds = $userClientModel->getUserClientIds($user->id);
            }

            $userArray = [
                'id'       => $user->id,
                'username' => $user->username,
                'email'    => $user->email ?? '',
                'role'     => $role,
            ];
            $token = \App\Libraries\JwtHelper::generateToken($userArray, $allowedClientIds);

            $this->logActivity("User login successfully", $user->username);
            return $this->respond([
                'message'    => 'Login successful',
                'token'      => $token,
                'expires_in' => 7200,
                'user' => [
                    'id'                 => $user->id,
                    'username'           => $user->username,
                    'email'              => $user->email ?? '',
                    'role'               => $role,
                    'full_name'          => $user->full_name ?? $user->username,
                    'allowed_client_ids' => $allowedClientIds
                ]
            ]);
        }

        return $this->failUnauthorized('Username/Email atau password salah');
    }

    /**
     * Helper to get allowed client_id array for current logged in user.
     * Returns null if admin (unrestricted).
     * Returns array of allowed client_ids for restricted roles.
     */
    private function getAllowedClientIdsForCurrentUser()
    {
        $sessRole = $this->getAuthRole();
        $userId = $this->getAuthUserId();

        // Admin has full access to all clients
        if ($sessRole === 'admin' || !$sessRole) {
            return null;
        }

        // For non-admin roles (recruiter, hc_ops, payroll, business_development, client_superior, etc.)
        $userClientModel = new \App\Models\UserClientModel();
        $clientIds = $userClientModel->getUserClientIds($userId);
        return !empty($clientIds) ? $clientIds : [-1];
    }

    public function getUserClients($userId)
    {
        $userClientModel = new \App\Models\UserClientModel();
        $clientIds = $userClientModel->getUserClientIds($userId);
        return $this->respond(['user_id' => intval($userId), 'client_ids' => $clientIds]);
    }

    public function assignUserClients($userId)
    {
        $data = $this->request->getJSON(true);
        $clientIds = $data['client_ids'] ?? [];
        if (!is_array($clientIds)) {
            $clientIds = [];
        }

        $userClientModel = new \App\Models\UserClientModel();
        $userClientModel->syncUserClients($userId, $clientIds);

        $this->logActivity("Memperbarui penugasan Klien untuk User ID: " . $userId);
        return $this->respond(['message' => 'User clients updated successfully', 'client_ids' => $clientIds]);
    }

    public function getMinimumWages()
    {
        $tipe = $this->request->getGet('tipe') ?: 'UMP';
        $year = $this->request->getGet('year');
        
        $query = $this->db->table('minimum_wages');
        if ($tipe !== 'all') {
            $query->where('tipe', $tipe);
        }
        if ($year) {
            $query->where('tahun', intval($year));
        }
        $data = $query->get()->getResult();
        return $this->respond($data);
    }

    public function saveMinimumWages()
    {
        $data = $this->request->getJSON(true);
        
        $items = isset($data['items']) ? $data['items'] : [$data];
        if (empty($items)) {
            return $this->respondCreated(['message' => 'No data available to save']);
        }
        
        // Extract the tipe and year from the first item to define our upload scope
        $firstItem = $items[0];
        $tipe = $firstItem['tipe'] ?? 'UMP';
        $year = $firstItem['tahun'] ?? $firstItem['year'] ?? 2026;
        
        // Strictly delete ONLY the existing records of this specific tipe and year!
        // This guarantees that UMK is NEVER touched when uploading UMP, and vice versa!
        $this->db->table('minimum_wages')
            ->where('tipe', $tipe)
            ->where('tahun', $year)
            ->delete();
            
        // Insert the uploaded list for this specific type and year
        foreach ($items as $item) {
            // Force strict consistency
            $item['tipe'] = $tipe;
            $item['tahun'] = $year;
            unset($item['year']);
            $this->db->table('minimum_wages')->insert($item);
        }
        
        return $this->respondCreated(['message' => 'Minimum wage data saved successfully']);
    }

    // --- CLIENTS ---
    public function getClients()
    {
        $query = $this->db->table('clients');
        $allowedClientIds = $this->getAllowedClientIdsForCurrentUser();
        if ($allowedClientIds !== null) {
            $query->whereIn('id', $allowedClientIds);
        }
        $clients = $query->get()->getResult();
        foreach ($clients as &$client) {
            if (isset($client->npwp)) {
                $client->npwp = (string)$client->npwp;
            }
        }
        return $this->respond($clients);
    }

    public function createClient()
    {
        $data = $this->request->getJSON(true);
        if (isset($data['npwp'])) {
            $data['npwp'] = (string)$data['npwp'];
        }
        $this->db->table('clients')->insert($data);
        $this->logActivity("Membuat klien baru: " . ($data['nama'] ?? ''));
        return $this->respondCreated(['message' => 'Client added successfully']);
    }

    public function createClientBulk()
    {
        $json = $this->request->getJSON(true);
        if (empty($json) || !is_array($json)) {
            return $this->fail('Data tidak valid atau kosong.');
        }

        // Start transaction
        $this->db->transStart();

        $insertedCount = 0;
        $errors = [];

        foreach ($json as $index => $row) {
            $rowNum = $index + 2; // Assuming row 1 is header

            $nama = trim($row['nama'] ?? '');
            $sector = trim($row['sector'] ?? '');
            $email = trim($row['email'] ?? '');
            $telepon = trim($row['telepon'] ?? '');
            $tgl_gabung = trim($row['tgl_gabung'] ?? '');
            $alamat = trim($row['alamat'] ?? '');
            $nib = trim($row['nib'] ?? '');
            $npwp = trim($row['npwp'] ?? '');

            // Basic validation
            if (empty($nama)) {
                $errors[] = "Baris {$rowNum}: Nama klien required.";
                continue;
            }
            if (empty($sector)) {
                $errors[] = "Baris {$rowNum}: Sector klien required.";
                continue;
            }
            if (empty($email)) {
                $errors[] = "Baris {$rowNum}: Email klien required.";
                continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Baris {$rowNum}: Format email '{$email}' tidak valid.";
                continue;
            }
            if (empty($tgl_gabung)) {
                $errors[] = "Baris {$rowNum}: Tanggal bergabung required.";
                continue;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl_gabung)) {
                $errors[] = "Baris {$rowNum}: Format tanggal '{$tgl_gabung}' harus YYYY-MM-DD.";
                continue;
            }

            // Check if email already exists in DB
            $existing = $this->db->table('clients')->where('email', $email)->get()->getRow();
            if ($existing) {
                $errors[] = "Baris {$rowNum}: Email '{$email}' sudah terdaftar.";
                continue;
            }

            // Generate No Klien (Format: CLI-001)
            $count = $this->db->table('clients')->countAllResults();
            $no_klien = 'CLI-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);

            $data = [
                'no_klien' => $no_klien,
                'nama' => $nama,
                'sector' => $sector,
                'email' => $email,
                'telepon' => $telepon,
                'tgl_gabung' => $tgl_gabung,
                'alamat' => $alamat,
                'nib' => $nib,
                'npwp' => $npwp,
                'status' => 'Active'
            ];

            $this->db->table('clients')->insert($data);
            $insertedCount++;
        }

        if (!empty($errors)) {
            $this->db->transRollback();
            return $this->respond([
                'status' => 'error',
                'errors' => $errors
            ], 400);
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return $this->fail('Failed melakukan penyimpanan massal data klien.');
        }

        $this->logActivity("Meng-upload massal {$insertedCount} klien baru dari Excel.");
        return $this->respondCreated([
            'status' => 'success',
            'message' => "Successfully menambahkan {$insertedCount} klien baru."
        ]);
    }

    public function updateClient($id)
    {
        $data = $this->request->getJSON(true);
        if (isset($data['npwp'])) {
            $data['npwp'] = (string)$data['npwp'];
        }
        $this->db->table('clients')->where('id', $id)->update($data);
        $this->logActivity("Mengupdate klien ID: " . $id . " (" . ($data['nama'] ?? '') . ")");
        return $this->respond(['message' => 'Client updated successfully']);
    }

    public function deleteClient($id)
    {
        $client = $this->db->table('clients')->where('id', $id)->get()->getRow();
        $clientName = $client ? $client->nama : 'Unknown';
        $this->db->table('clients')->where('id', $id)->delete();
        $this->logActivity("Menghapus klien ID: " . $id . " (" . $clientName . ")");
        return $this->respondDeleted(['message' => 'Client deleted successfully']);
    }

    // --- ORGANISASI ---
    public function getOrg()
    {
        $clientId = $this->request->getGet('client_id');
        $query = $this->db->table('divisions');
        if ($clientId) $query->where('client_id', $clientId);
        
        $divisions = $query->get()->getResultArray();

        foreach ($divisions as &$div) {
            $div['departments'] = $this->db->table('departments')
                                           ->where('division_id', $div['id'])
                                           ->get()
                                           ->getResultArray();
            
            foreach ($div['departments'] as &$dept) {
                $dept['positions'] = $this->db->table('positions')
                                              ->where('department_id', $dept['id'])
                                              ->get()
                                              ->getResultArray();
            }
        }

        return $this->respond($divisions);
    }

    public function createDivision()
    {
        $data = $this->request->getJSON(true);
        $this->db->table('divisions')->insert($data);
        return $this->respondCreated(['message' => 'Division added successfully']);
    }

    public function createDepartment()
    {
        $data = $this->request->getJSON(true);
        $this->db->table('departments')->insert($data);
        return $this->respondCreated(['message' => 'Department added successfully']);
    }

    public function createPosition()
    {
        $data = $this->request->getJSON(true);
        $this->db->table('positions')->insert($data);
        return $this->respondCreated(['message' => 'Position added successfully']);
    }

    public function updateOrg($type, $id)
    {
        $data = $this->request->getJSON(true);
        $table = '';
        if ($type === 'division') $table = 'divisions';
        elseif ($type === 'department') $table = 'departments';
        elseif ($type === 'position') $table = 'positions';

        if ($table) {
            $this->db->table($table)->where('id', $id)->update($data);
            return $this->respond(['message' => 'Data updated successfully']);
        }
        return $this->fail('Tipe organisasi is invalid');
    }

    public function deleteOrg($type, $id)
    {
        $table = '';
        if ($type === 'division') $table = 'divisions';
        elseif ($type === 'department') $table = 'departments';
        elseif ($type === 'position') $table = 'positions';

        if ($table) {
            $this->db->table($table)->where('id', $id)->delete();
            return $this->respondDeleted(['message' => 'Data deleted successfully']);
        }
        return $this->fail('Tipe organisasi is invalid');
    }

    // --- PAYROLL ---
    public function getPayrollSchemes()
    {
        $schemes = $this->db->table('payroll_schemes')->get()->getResultArray();
        foreach ($schemes as &$scheme) {
            $scheme['components'] = $this->db->table('payroll_components')
                                             ->where('scheme_id', $scheme['id'])
                                             ->get()
                                             ->getResultArray();
        }
        return $this->respond($schemes);
    }

    public function uploadPayrollSchemeExcel()
    {
        $requestData = $this->request->getJSON(true);
        $schemes = $requestData['schemes'] ?? [];

        if (empty($schemes) || !is_array($schemes)) {
            return $this->fail('Data scheme payroll tidak ditemukan atau kosong');
        }

        $count = 0;
        foreach ($schemes as $s) {
            $nama = trim($s['nama'] ?? '');
            if (empty($nama)) continue;

            $schemeData = [
                'nama'                      => $nama,
                'deskripsi'                 => $s['deskripsi'] ?? '',
                'tipe'                      => !empty($s['tipe']) ? strtolower($s['tipe']) : 'monthan',
                'prorate'                   => isset($s['prorate']) ? intval($s['prorate']) : 0,
                'absen_tidak_potong'        => isset($s['absen_tidak_potong']) ? intval($s['absen_tidak_potong']) : 0,
                'nominal_deduction'          => isset($s['nominal_deduction']) ? floatval($s['nominal_deduction']) : 0,
                'grace_period_late'         => isset($s['grace_period_late']) ? intval($s['grace_period_late']) : 0,
                'grace_period_early'        => isset($s['grace_period_early']) ? intval($s['grace_period_early']) : 0,
                'min_overtime'              => isset($s['min_overtime']) ? intval($s['min_overtime']) : 30,
                'max_early_arrival_minutes' => isset($s['max_early_arrival_minutes']) ? intval($s['max_early_arrival_minutes']) : 180,
                'overtime_type'             => $s['overtime_type'] ?? 'standard',
                'lumpsum_nominal'           => isset($s['lumpsum_nominal']) ? floatval($s['lumpsum_nominal']) : 0,
            ];

            $this->db->table('payroll_schemes')->insert($schemeData);
            $schemeId = $this->db->insertID();

            // Insert Basic Salary component
            $basicSalaryVal = isset($s['gaji_pokok']) ? floatval($s['gaji_pokok']) : 0;
            $sumberGaji = strtolower($s['sumber_gaji'] ?? 'nominal');

            $basicComp = [
                'scheme_id'        => $schemeId,
                'nama'             => 'Base Salary',
                'nama_component'    => 'Base Salary',
                'tipe'             => 'revenue',
                'value'            => $basicSalaryVal,
                'is_persentase'    => 0,
                'jenis_component'   => 'basic_salary',
                'sumber_value'     => $sumberGaji,
                'period'          => 'month',
                'sifat_kompensasi' => 'tetap',
                'is_bpjs'          => 1,
                'is_pph21'         => 1,
                'is_active'        => 1
            ];
            $this->db->table('payroll_components')->insert($basicComp);

            // Insert custom components if provided
            if (!empty($s['components']) && is_array($s['components'])) {
                foreach ($s['components'] as $comp) {
                    $compNama = trim($comp['nama'] ?? '');
                    if (empty($compNama)) continue;

                    $compData = [
                        'scheme_id'        => $schemeId,
                        'nama'             => $compNama,
                        'nama_component'    => $compNama,
                        'tipe'             => !empty($comp['tipe']) ? strtolower($comp['tipe']) : 'revenue',
                        'value'            => isset($comp['value']) ? floatval($comp['value']) : 0,
                        'is_persentase'    => isset($comp['is_persentase']) ? intval($comp['is_persentase']) : 0,
                        'jenis_component'   => 'kompensasi',
                        'sumber_value'     => $comp['sumber_value'] ?? 'nominal',
                        'period'          => $comp['period'] ?? 'month',
                        'sifat_kompensasi' => $comp['sifat_kompensasi'] ?? 'tetap',
                        'is_bpjs'          => isset($comp['is_bpjs']) ? intval($comp['is_bpjs']) : 1,
                        'is_pph21'         => isset($comp['is_pph21']) ? intval($comp['is_pph21']) : 1,
                        'is_active'        => 1
                    ];
                    $this->db->table('payroll_components')->insert($compData);
                }
            }

            $count++;
        }

        $this->logActivity("Mengunggah $count scheme payroll baru via Excel");
        return $this->respondCreated([
            'status'  => 200,
            'message' => "Successfully mengimpor $count scheme payroll baru dari Excel!",
            'count'   => $count
        ]);
    }

    public function createPayrollScheme()
    {
        $requestData = $this->request->getJSON(true);
        
        $schemeData = [
            'nama' => $requestData['nama'] ?? '',
            'deskripsi' => $requestData['deskripsi'] ?? '',
            'tipe' => $requestData['tipe'] ?? 'monthan',
            'compensation_scheme_id' => !empty($requestData['compensation_scheme_id']) ? intval($requestData['compensation_scheme_id']) : null,
            'prorate' => isset($requestData['prorate']) ? intval($requestData['prorate']) : 0,
            'absen_tidak_potong' => isset($requestData['absen_tidak_potong']) ? intval($requestData['absen_tidak_potong']) : 0,
            'nominal_deduction' => isset($requestData['nominal_deduction']) ? floatval($requestData['nominal_deduction']) : 0,
            'grace_period_late' => isset($requestData['grace_period_late']) ? intval($requestData['grace_period_late']) : 0,
            'grace_period_early' => isset($requestData['grace_period_early']) ? intval($requestData['grace_period_early']) : 0,
            'min_overtime' => isset($requestData['min_overtime']) ? intval($requestData['min_overtime']) : 30,
            'max_early_arrival_minutes' => isset($requestData['max_early_arrival_minutes']) ? intval($requestData['max_early_arrival_minutes']) : 180,
            'denda_terlambat_per_jam' => isset($requestData['denda_terlambat_per_jam']) ? floatval($requestData['denda_terlambat_per_jam']) : 0,
            'denda_alfa_per_hari' => isset($requestData['denda_alfa_per_hari']) ? floatval($requestData['denda_alfa_per_hari']) : 0,
            'early_leave_threshold' => isset($requestData['early_leave_threshold']) ? intval($requestData['early_leave_threshold']) : 0,
            'overtime_type' => $requestData['overtime_type'] ?? 'standard',
            'lumpsum_subtype' => $requestData['lumpsum_subtype'] ?? null,
            'lumpsum_nominal' => isset($requestData['lumpsum_nominal']) ? floatval($requestData['lumpsum_nominal']) : 0,
        ];
        
        $this->db->table('payroll_schemes')->insert($schemeData);
        $schemeId = $this->db->insertID();
        
        $componentData = [
            'scheme_id' => $schemeId,
            'nama' => 'Base Salary',
            'tipe' => 'revenue',
            'value' => $requestData['value'] ?? 0,
            'is_persentase' => isset($requestData['is_persentase']) ? intval($requestData['is_persentase']) : 0,
            'jenis_component' => 'basic_salary',
            'sumber_value' => $requestData['sumber_value'] ?? 'nominal',
            'period' => $requestData['period'] ?? 'month',
            'sifat_kompensasi' => 'tetap',
            'is_bpjs' => 1,
            'is_pph21' => 1
        ];
        
        $this->db->table('payroll_components')->insert($componentData);

        // Save selected compensation components
        if (!empty($requestData['components'])) {
            foreach ($requestData['components'] as $comp) {
                $compData = [
                    'scheme_id' => $schemeId,
                    'nama' => $comp['nama'] ?? '',
                    'tipe' => $comp['tipe'] ?? 'revenue',
                    'value' => isset($comp['value']) ? floatval($comp['value']) : 0,
                    'is_persentase' => isset($comp['is_persentase']) ? intval($comp['is_persentase']) : 0,
                    'jenis_component' => 'kompensasi',
                    'sumber_value' => $comp['sumber_value'] ?? 'nominal',
                    'period' => $comp['period'] ?? 'month',
                    'sifat_kompensasi' => $comp['sifat_kompensasi'] ?? 'tetap',
                    'is_bpjs' => isset($comp['is_bpjs']) ? intval($comp['is_bpjs']) : 0,
                    'is_pph21' => isset($comp['is_pph21']) ? intval($comp['is_pph21']) : 1
                ];
                $this->db->table('payroll_components')->insert($compData);
            }
        }
        
        $this->logActivity("Membuat scheme payroll baru: " . ($schemeData['nama'] ?? ''));
        return $this->respondCreated(['message' => 'Scheme added successfully']);
    }

    public function updatePayrollScheme($id)
    {
        $requestData = $this->request->getJSON(true);
        
        $schemeData = [
            'nama' => $requestData['nama'] ?? '',
            'deskripsi' => $requestData['deskripsi'] ?? '',
            'tipe' => $requestData['tipe'] ?? 'monthan',
            'compensation_scheme_id' => !empty($requestData['compensation_scheme_id']) ? intval($requestData['compensation_scheme_id']) : null,
            'prorate' => isset($requestData['prorate']) ? intval($requestData['prorate']) : 0,
            'absen_tidak_potong' => isset($requestData['absen_tidak_potong']) ? intval($requestData['absen_tidak_potong']) : 0,
            'nominal_deduction' => isset($requestData['nominal_deduction']) ? floatval($requestData['nominal_deduction']) : 0,
            'grace_period_late' => isset($requestData['grace_period_late']) ? intval($requestData['grace_period_late']) : 0,
            'grace_period_early' => isset($requestData['grace_period_early']) ? intval($requestData['grace_period_early']) : 0,
            'min_overtime' => isset($requestData['min_overtime']) ? intval($requestData['min_overtime']) : 30,
            'max_early_arrival_minutes' => isset($requestData['max_early_arrival_minutes']) ? intval($requestData['max_early_arrival_minutes']) : 180,
            'denda_terlambat_per_jam' => isset($requestData['denda_terlambat_per_jam']) ? floatval($requestData['denda_terlambat_per_jam']) : 0,
            'denda_alfa_per_hari' => isset($requestData['denda_alfa_per_hari']) ? floatval($requestData['denda_alfa_per_hari']) : 0,
            'early_leave_threshold' => isset($requestData['early_leave_threshold']) ? intval($requestData['early_leave_threshold']) : 0,
            'overtime_type' => $requestData['overtime_type'] ?? 'standard',
            'lumpsum_subtype' => $requestData['lumpsum_subtype'] ?? null,
            'lumpsum_nominal' => isset($requestData['lumpsum_nominal']) ? floatval($requestData['lumpsum_nominal']) : 0,
        ];
        
        $this->db->table('payroll_schemes')->where('id', $id)->update($schemeData);
        
        $componentData = [
            'nama' => 'Base Salary',
            'tipe' => 'revenue',
            'value' => $requestData['value'] ?? 0,
            'is_persentase' => isset($requestData['is_persentase']) ? intval($requestData['is_persentase']) : 0,
            'jenis_component' => 'basic_salary',
            'sumber_value' => $requestData['sumber_value'] ?? 'nominal',
            'period' => $requestData['period'] ?? 'month',
            'sifat_kompensasi' => 'tetap',
            'is_bpjs' => 1,
            'is_pph21' => 1
        ];
        
        $existing = $this->db->table('payroll_components')->where('scheme_id', $id)->where('jenis_component', 'basic_salary')->get()->getRow();
        if ($existing) {
            $this->db->table('payroll_components')->where('id', $existing->id)->update($componentData);
        } else {
            $componentData['scheme_id'] = $id;
            $this->db->table('payroll_components')->insert($componentData);
        }

        // Delete existing non-basic components
        $this->db->table('payroll_components')
                 ->where('scheme_id', $id)
                 ->where('jenis_component !=', 'basic_salary')
                 ->delete();

        // Save selected compensation components
        if (!empty($requestData['components'])) {
            foreach ($requestData['components'] as $comp) {
                $compData = [
                    'scheme_id' => $id,
                    'nama' => $comp['nama'] ?? '',
                    'tipe' => $comp['tipe'] ?? 'revenue',
                    'value' => isset($comp['value']) ? floatval($comp['value']) : 0,
                    'is_persentase' => isset($comp['is_persentase']) ? intval($comp['is_persentase']) : 0,
                    'jenis_component' => 'kompensasi',
                    'sumber_value' => $comp['sumber_value'] ?? 'nominal',
                    'period' => $comp['period'] ?? 'month',
                    'sifat_kompensasi' => $comp['sifat_kompensasi'] ?? 'tetap',
                    'is_bpjs' => isset($comp['is_bpjs']) ? intval($comp['is_bpjs']) : 0,
                    'is_pph21' => isset($comp['is_pph21']) ? intval($comp['is_pph21']) : 1
                ];
                $this->db->table('payroll_components')->insert($compData);
            }
        }
        
        $this->logActivity("Mengupdate scheme payroll ID: " . $id . " (" . ($schemeData['nama'] ?? '') . ")");
        return $this->respond(['message' => 'Scheme updated successfully']);
    }

    public function deletePayrollScheme($id)
    {
        $scheme = $this->db->table('payroll_schemes')->where('id', $id)->get()->getRow();
        $schemeName = $scheme ? $scheme->nama : 'Unknown';
        $this->db->table('payroll_components')->where('scheme_id', $id)->delete();
        $this->db->table('payroll_schemes')->where('id', $id)->delete();
        $this->logActivity("Menghapus scheme payroll ID: " . $id . " (" . $schemeName . ")");
        return $this->respondDeleted(['message' => 'Scheme deleted successfully']);
    }

    public function createPayrollComponent()
    {
        $data = $this->request->getJSON(true);
        $this->db->table('payroll_components')->insert($data);
        return $this->respondCreated(['message' => 'Component added successfully']);
    }

    public function updatePayrollComponent($id)
    {
        $data = $this->request->getJSON(true);
        $this->db->table('payroll_components')->where('id', $id)->update($data);
        return $this->respond(['message' => 'Component updated successfully']);
    }

    public function deletePayrollComponent($id)
    {
        $this->db->table('payroll_components')->where('id', $id)->delete();
        return $this->respondDeleted(['message' => 'Component deleted successfully']);
    }

    // --- TAX SCHEMES ---
    public function getTaxSchemes()
    {
        $schemes = $this->db->table('tax_schemes')->get()->getResult();
        return $this->respond($schemes);
    }

    public function createTaxScheme()
    {
        $data = $this->request->getJSON(true);
        $this->db->table('tax_schemes')->insert($data);
        $id = $this->db->insertID();
        return $this->respondCreated([
            'status' => 'success',
            'message' => 'Tax scheme added successfully',
            'id' => $id
        ]);
    }

    public function updateTaxScheme($id)
    {
        $data = $this->request->getJSON(true);
        $this->db->table('tax_schemes')->where('id', $id)->update($data);
        return $this->respond(['message' => 'Tax scheme updated successfully']);
    }

    public function deleteTaxScheme($id)
    {
        $this->db->table('tax_schemes')->where('id', $id)->delete();
        return $this->respondDeleted(['message' => 'Tax scheme deleted successfully']);
    }

    // --- COMPENSATION SCHEMES ---
    public function getCompensationSchemes()
    {
        $schemes = $this->db->table('compensation_schemes')->get()->getResultArray();
        foreach ($schemes as &$scheme) {
            $scheme['components'] = $this->db->table('compensation_components')
                                             ->where('scheme_id', $scheme['id'])
                                             ->get()
                                             ->getResultArray();
        }
        return $this->respond($schemes);
    }

    public function createCompensationScheme()
    {
        $requestData = $this->request->getJSON(true);
        
        $schemeData = [
            'nama' => $requestData['nama'] ?? '',
            'deskripsi' => $requestData['deskripsi'] ?? ''
        ];
        
        $this->db->table('compensation_schemes')->insert($schemeData);
        $schemeId = $this->db->insertID();
        $namaKompensasi = $requestData['nama'] ?? '';
        $sifatKompensasi = ($requestData['sifat_kompensasi'] ?? '') === 'tidak_tetap' ? 'tidak_tetap' : 'tetap';
        
        $componentData = [
            'scheme_id' => $schemeId,
            'nama' => $namaKompensasi,
            'tipe' => $requestData['tipe'] ?? 'revenue',
            'value' => $requestData['value'] ?? 0,
            'is_persentase' => isset($requestData['is_persentase']) ? intval($requestData['is_persentase']) : 0,
            'jenis_component' => 'kompensasi',
            'sumber_value' => $requestData['sumber_value'] ?? 'nominal',
            'period' => $requestData['period'] ?? 'month',
            'sifat_kompensasi' => $sifatKompensasi
        ];
        
        $this->db->table('compensation_components')->insert($componentData);
        
        $this->logActivity("Membuat scheme kompensasi baru: " . ($schemeData['nama'] ?? ''));
        return $this->respondCreated(['message' => 'Compensation scheme added successfully']);
    }

    public function createCompensationSchemesBulk()
    {
        $json = $this->request->getJSON(true);
        if (empty($json) || !is_array($json)) {
            return $this->fail('Data tidak valid atau kosong.');
        }

        $this->db->transStart();
        $insertedCount = 0;
        $errors = [];

        foreach ($json as $index => $row) {
            $rowNum = $index + 2; // Assuming row 1 is header

            $nama = trim($row['nama'] ?? '');
            $deskripsi = trim($row['deskripsi'] ?? '');
            $tipe = strtolower(trim($row['tipe'] ?? 'revenue'));
            if (!in_array($tipe, ['revenue', 'deduction'])) {
                $tipe = 'revenue';
            }

            $sifatRaw = strtolower(trim($row['sifat_kompensasi'] ?? 'tetap'));
            if (strpos($sifatRaw, 'variable') !== false || strpos($sifatRaw, 'tidak') !== false) {
                $sifatKompensasi = 'tidak_tetap';
            } else {
                $sifatKompensasi = 'tetap';
            }

            $sumberRaw = strtolower(trim($row['sumber_value'] ?? 'nominal'));
            if (strpos($sumberRaw, 'ump_umk') !== false || strpos($sumberRaw, 'ump/umk') !== false) {
                $sumberValue = 'ump_umk';
            } elseif (strpos($sumberRaw, 'ump') !== false) {
                $sumberValue = 'ump';
            } elseif (strpos($sumberRaw, 'umk') !== false) {
                $sumberValue = 'umk';
            } else {
                $sumberValue = 'nominal';
            }

            $value = floatval($row['value'] ?? 0);
            $isPersentase = intval($row['is_persentase'] ?? 0);

            $periodRaw = strtolower(trim($row['period'] ?? 'month'));
            if (strpos($periodRaw, 'work') !== false || strpos($periodRaw, 'hari') !== false) {
                $period = 'hari_kerja';
            } elseif (strpos($periodRaw, 'week') !== false || strpos($periodRaw, 'minggu') !== false) {
                $period = 'minggu';
            } elseif (strpos($periodRaw, 'year') !== false || strpos($periodRaw, 'year') !== false) {
                $period = 'year';
            } else {
                $period = 'month';
            }

            if (empty($nama)) {
                $errors[] = "Baris {$rowNum}: Scheme Name (Scheme Name Allowance) required.";
                continue;
            }

            // Insert scheme
            $schemeData = [
                'nama' => $nama,
                'deskripsi' => $deskripsi
            ];
            $this->db->table('compensation_schemes')->insert($schemeData);
            $schemeId = $this->db->insertID();

            // Insert component
            $componentData = [
                'scheme_id' => $schemeId,
                'nama' => $nama,
                'tipe' => $tipe,
                'value' => $value,
                'is_persentase' => $isPersentase,
                'jenis_component' => 'kompensasi',
                'sumber_value' => $sumberValue,
                'period' => $period,
                'sifat_kompensasi' => $sifatKompensasi
            ];
            $this->db->table('compensation_components')->insert($componentData);

            $insertedCount++;
        }

        if (!empty($errors) && $insertedCount === 0) {
            $this->db->transRollback();
            return $this->respond([
                'status' => 400,
                'message' => 'All data failed diimport.',
                'errors' => $errors
            ], 400);
        }

        $this->db->transComplete();

        $userAction = $this->request->getHeaderLine('X-User-Action') ?: 'Admin';
        $this->logActivity("Bulk upload Master Scheme Allowance ({$insertedCount} scheme successfully dibuat)", $userAction);

        $responseMsg = "Successfully mengimport {$insertedCount} Master Scheme Allowance.";
        if (!empty($errors)) {
            $responseMsg .= " Terdapat " . count($errors) . " baris yang diabaikan/failed.";
        }

        return $this->respondCreated([
            'message' => $responseMsg,
            'inserted_count' => $insertedCount,
            'errors' => $errors
        ]);
    }

    public function updateCompensationScheme($id)
    {
        $requestData = $this->request->getJSON(true);
        
        $schemeData = [
            'nama' => $requestData['nama'] ?? '',
            'deskripsi' => $requestData['deskripsi'] ?? ''
        ];
        
        $this->db->table('compensation_schemes')->where('id', $id)->update($schemeData);
        
        $namaKompensasi = $requestData['nama'] ?? '';
        $sifatKompensasi = ($requestData['sifat_kompensasi'] ?? '') === 'tidak_tetap' ? 'tidak_tetap' : 'tetap';

        $componentData = [
            'nama' => $namaKompensasi,
            'tipe' => $requestData['tipe'] ?? 'revenue',
            'value' => $requestData['value'] ?? 0,
            'is_persentase' => isset($requestData['is_persentase']) ? intval($requestData['is_persentase']) : 0,
            'jenis_component' => 'kompensasi',
            'sumber_value' => $requestData['sumber_value'] ?? 'nominal',
            'period' => $requestData['period'] ?? 'month',
            'sifat_kompensasi' => $sifatKompensasi
        ];
        
        $existing = $this->db->table('compensation_components')->where('scheme_id', $id)->get()->getRow();
        if ($existing) {
            $this->db->table('compensation_components')->where('id', $existing->id)->update($componentData);
        } else {
            $componentData['scheme_id'] = $id;
            $this->db->table('compensation_components')->insert($componentData);
        }
        
        $this->logActivity("Mengupdate scheme kompensasi ID: " . $id . " (" . ($schemeData['nama'] ?? '') . ")");
        return $this->respond(['message' => 'Compensation scheme updated successfully']);
    }

    public function deleteCompensationScheme($id)
    {
        $scheme = $this->db->table('compensation_schemes')->where('id', $id)->get()->getRow();
        $schemeName = $scheme ? $scheme->nama : 'Unknown';
        $this->db->table('compensation_schemes')->where('id', $id)->delete();
        $this->db->table('compensation_components')->where('scheme_id', $id)->delete(); // Cascade
        $this->logActivity("Menghapus scheme kompensasi ID: " . $id . " (" . $schemeName . ")");
        return $this->respondDeleted(['message' => 'Compensation scheme deleted successfully']);
    }

    // --- SCHEDULE TEMPLATES ---
    public function getScheduleTemplates()
    {
        $year = $this->request->getGet('year');
        $builder = $this->db->table('payroll_schedules');
        if ($year) {
            $builder->where('year', intval($year));
        }
        $schedules = $builder->get()->getResultArray();
        return $this->respond($schedules);
    }

    public function createScheduleTemplate()
    {
        $data = $this->request->getJSON(true);
        $insertData = [
            'nama' => $data['nama'] ?? '',
            'pay_date' => isset($data['pay_date']) ? intval($data['pay_date']) : 25,
            'cutoff_start' => isset($data['cutoff_start']) ? intval($data['cutoff_start']) : 21,
            'cutoff_end' => isset($data['cutoff_end']) ? intval($data['cutoff_end']) : 20,
            'deskripsi' => $data['deskripsi'] ?? null,
            'year' => isset($data['year']) ? intval($data['year']) : intval(date('Y'))
        ];

        $this->db->table('payroll_schedules')->insert($insertData);
        $this->logActivity("Membuat master schedule baru: " . ($insertData['nama'] ?? ''));
        return $this->respondCreated(['message' => 'Schedule template added successfully']);
    }

    public function updateScheduleTemplate($id)
    {
        $data = $this->request->getJSON(true);
        $updateData = [
            'nama' => $data['nama'] ?? '',
            'pay_date' => isset($data['pay_date']) ? intval($data['pay_date']) : 25,
            'cutoff_start' => isset($data['cutoff_start']) ? intval($data['cutoff_start']) : 21,
            'cutoff_end' => isset($data['cutoff_end']) ? intval($data['cutoff_end']) : 20,
            'deskripsi' => $data['deskripsi'] ?? null,
            'year' => isset($data['year']) ? intval($data['year']) : intval(date('Y'))
        ];

        $this->db->table('payroll_schedules')->where('id', $id)->update($updateData);
        $this->logActivity("Mengupdate master schedule ID: " . $id . " (" . ($updateData['nama'] ?? '') . ")");
        return $this->respond(['message' => 'Schedule template updated successfully']);
    }

    public function deleteScheduleTemplate($id)
    {
        $schedule = $this->db->table('payroll_schedules')->where('id', $id)->get()->getRow();
        $name = $schedule ? $schedule->nama : 'Unknown';
        $this->db->table('payroll_schedules')->where('id', $id)->delete();
        $this->logActivity("Menghapus master schedule ID: " . $id . " (" . $name . ")");
        return $this->respondDeleted(['message' => 'Schedule template deleted successfully']);
    }

    private function resolveClientConfigForEmployee($employeeId)
    {
        $db = \Config\Database::connect();
        $emp = $db->table('employees')->where('id', intval($employeeId))->get()->getRow();
        if (!$emp) {
            return null;
        }
        $clientId = $emp->client_id;
        $divId = $emp->division_id ?? null;
        $deptId = $emp->department_id ?? null;
        $posId = $emp->position_id ?? null;

        $config = null;
        if ($posId) {
            $config = $db->table('client_payroll_configs')
                ->where('client_id', $clientId)
                ->where('position_id', $posId)
                ->get()->getRow();
        }
        if (!$config && $deptId) {
            $config = $db->table('client_payroll_configs')
                ->where('client_id', $clientId)
                ->where('department_id', $deptId)
                ->where('position_id IS NULL')
                ->get()->getRow();
        }
        if (!$config && $divId) {
            $config = $db->table('client_payroll_configs')
                ->where('client_id', $clientId)
                ->where('division_id', $divId)
                ->where('department_id IS NULL')
                ->where('position_id IS NULL')
                ->get()->getRow();
        }
        if (!$config) {
            $config = $db->table('client_payroll_configs')
                ->where('client_id', $clientId)
                ->where('division_id IS NULL')
                ->where('department_id IS NULL')
                ->where('position_id IS NULL')
                ->get()->getRow();
        }
        return $config;
    }

    // --- ATTENDANCE LOGS ---
    private function calculateShiftAttendance($employeeId, $tanggal, $jamMasuk, $jamKeluar, $status, $providedShiftSchemeId = null, $payoutPeriod = null)
    {
        $db = \Config\Database::connect();
        
        // 1. Determine Shift Scheme
        $shiftSchemeId = null;
        if (!empty($providedShiftSchemeId)) {
            $shiftSchemeId = intval($providedShiftSchemeId);
        } else {
            // Find active shift assignment for this employee
            $empShift = $db->table('employee_shifts')
                ->where('employee_id', intval($employeeId))
                ->where('start_date <=', $tanggal)
                ->groupStart()
                    ->where('end_date IS NULL')
                    ->orWhere('end_date >=', $tanggal)
                ->groupEnd()
                ->orderBy('start_date', 'DESC')
                ->get()->getRow();
            if ($empShift) {
                $shiftSchemeId = intval($empShift->shift_scheme_id);
            }
        }

        $result = [
            'shift_scheme_id'            => $shiftSchemeId,
            'calculated_work_hours'      => 0.0,
            'calculated_overtime_hours'  => 0.0,
            'late_hours'                 => 0.0,
            'late_minutes'               => 0,
            'late_penalty_hours'         => 0,
            'denda_terlambat'            => 0.0,
            'early_leave_hours'          => 0.0,
            'is_early_leave_alfa'        => 0,
            'denda_alfa'                 => 0.0,
            'absent_penalty'             => 0.0,
            'is_incomplete'              => 0,
            'is_rapel'                   => 0,
            'payout_period'              => null,
        ];

        // 2. Determine rapel status based on client's cut-off
        $emp = $db->table('employees')->where('id', intval($employeeId))->get()->getRow();
        $clientId = $emp ? $emp->client_id : null;

        $cutoffGajiPokokStart = 21;
        $cutoffGajiPokokEnd = 20;
        $cutoffOvertimeStart = 21;
        $cutoffOvertimeEnd = 20;

        $clientConfig = null;
        if ($clientId && $emp) {
            $clientConfig = $this->resolveClientConfigForEmployee($employeeId);
        }

        // Helper function to resolve dynamic cutoff dates from schedule or direct configs
        $resolveCutoffDates = function($config, $component) use ($db) {
            $startField = "cutoff_{$component}_start";
            $endField = "cutoff_{$component}_end";
            $refField = "cutoff_{$component}_schedule_ref";
            
            $start = ($config && isset($config->$startField)) ? intval($config->$startField) : null;
            $end = ($config && isset($config->$endField)) ? intval($config->$endField) : null;
            $refId = ($config && isset($config->$refField)) ? intval($config->$refField) : null;
            
            if ($refId) {
                $sched = $db->table('payroll_schedules')->where('id', $refId)->get()->getRow();
                if ($sched) {
                    $start = intval($sched->cutoff_start);
                    $end = intval($sched->cutoff_end);
                }
            }
            
            if ($start === null) {
                if ($component === 'gaji_pokok' && $config && isset($config->cutoff_start)) {
                    $start = intval($config->cutoff_start);
                } else {
                    $start = 21;
                }
            }
            if ($end === null) {
                if ($component === 'gaji_pokok' && $config && isset($config->cutoff_end)) {
                    $end = intval($config->cutoff_end);
                } else {
                    $end = $start - 1;
                    if ($end < 1) $end = 31;
                }
            }
            return [$start, $end];
        };

        if ($clientConfig) {
            list($cutoffGajiPokokStart, $cutoffGajiPokokEnd) = $resolveCutoffDates($clientConfig, 'gaji_pokok');
            list($cutoffOvertimeStart, $cutoffOvertimeEnd) = $resolveCutoffDates($clientConfig, 'overtime');
        }

        $ts = strtotime($tanggal);
        $tYear = intval(date('Y', $ts));
        $tMonth = intval(date('n', $ts));
        $tDay = intval(date('j', $ts));

        $resolvePeriodAndRapel = function($startDay, $endDay) use ($tanggal, $tYear, $tMonth, $tDay, $payoutPeriod) {
            $actualEndDay = $endDay;
            if ($actualEndDay <= 0) {
                $actualEndDay = date('t', strtotime($tanggal));
            }
            if ($tDay > $actualEndDay) {
                $natMonth = $tMonth + 1;
                $natYear = $tYear;
                if ($natMonth > 12) {
                    $natMonth = 1;
                    $natYear++;
                }
            } else {
                $natMonth = $tMonth;
                $natYear = $tYear;
            }
            $naturalPeriod = $natMonth . '-' . $natYear;
            $isRapel = 0;
            $finalPayoutPeriod = $naturalPeriod;

            if (!empty($payoutPeriod)) {
                $naturalVal = $natYear * 12 + $natMonth;
                $parts = explode('-', $payoutPeriod);
                if (count($parts) === 2) {
                    $payoutMonth = intval($parts[0]);
                    $payoutYear = intval($parts[1]);
                    $payoutVal = $payoutYear * 12 + $payoutMonth;
                    if ($naturalVal > $payoutVal) {
                        $isRapel = 0;
                        $finalPayoutPeriod = $naturalPeriod;
                    } elseif ($naturalVal < $payoutVal) {
                        $isRapel = 1;
                        $finalPayoutPeriod = $payoutPeriod;
                    } else {
                        $isRapel = 0;
                        $finalPayoutPeriod = $naturalPeriod;
                    }
                }
            }
            return [$isRapel, $finalPayoutPeriod];
        };

        list($gpIsRapel, $gpPayoutPeriod) = $resolvePeriodAndRapel($cutoffGajiPokokStart, $cutoffGajiPokokEnd);
        $result['is_rapel'] = $gpIsRapel;
        $result['payout_period'] = $gpPayoutPeriod;
        
        // Also pre-resolve Overtime period and rapel for later sync
        list($otIsRapel, $otPayoutPeriod) = $resolvePeriodAndRapel($cutoffOvertimeStart, $cutoffOvertimeEnd);

        // Auto-detect shift jika tidak ada assignment di employee_shifts
        if (!$shiftSchemeId && !empty($jamMasuk)) {
            $jamMasukInt = intval(explode(':', $jamMasuk)[0]);
            $namaShiftGuess = $jamMasukInt < 12 ? 'Pagi' : 'Siang';
            $matchedShift = $db->table('shift_schemes')
                ->where('name', $namaShiftGuess)
                ->get()->getRow();
            if ($matchedShift) {
                $shiftSchemeId = intval($matchedShift->id);
                $result['shift_scheme_id'] = $shiftSchemeId;
            }
        }

        // Fallback: ambil shift pertama yang tersedia
        if (!$shiftSchemeId) {
            $firstShift = $db->table('shift_schemes')->orderBy('id', 'ASC')->get()->getRow();
            if ($firstShift) {
                $shiftSchemeId = intval($firstShift->id);
                $result['shift_scheme_id'] = $shiftSchemeId;
            }
        }

        if (!$shiftSchemeId) {
            return $result;
        }

        $shift = $db->table('shift_schemes')->where('id', $shiftSchemeId)->get()->getRow();
        if (!$shift) {
            return $result;
        }

        if ($status !== 'Hadir') {
            return $result;
        }

        if (empty($jamMasuk)) {
            // Check if this is a rest day (weekend or public holiday) — if so, do NOT mark as incomplete
            $dayOfWeek = intval(date('w', strtotime($tanggal))); // 0=Sunday, 6=Saturday
            $workDaysConfig = 5; // default
            if ($emp) {
                $workDaysConfig = intval($emp->hari_kerja ?? 5);
                if ($workDaysConfig < 1) {
                    // Try position hari_kerja
                    $posId = $emp->position_id ?? null;
                    if ($posId) {
                        $pos = $db->table('positions')->where('id', $posId)->get()->getRow();
                        if ($pos && isset($pos->hari_kerja) && intval($pos->hari_kerja) > 0) {
                            $workDaysConfig = intval($pos->hari_kerja);
                        } else {
                            $workDaysConfig = 5;
                        }
                    } else {
                        $workDaysConfig = 5;
                    }
                }
            }
            $isRestDay = false;
            if ($workDaysConfig === 5) {
                $isRestDay = ($dayOfWeek === 0 || $dayOfWeek === 6);
            } elseif ($workDaysConfig === 6) {
                $isRestDay = ($dayOfWeek === 0);
            }
            // Also check if it's a public holiday from holiday_calendar
            if (!$isRestDay) {
                $publicHoliday = $db->table('holiday_calendar')->where('tanggal', $tanggal)->get()->getRow();
                if ($publicHoliday) {
                    $isRestDay = true;
                }
            }
            if (!$isRestDay) {
                $result['is_incomplete'] = 1;
            }
            return $result;
        }

        // 3. Perform calculations
        $inTime = strtotime($tanggal . ' ' . $jamMasuk);
        $shiftIn = strtotime($tanggal . ' ' . $shift->start_time);
        $shiftOut = strtotime($tanggal . ' ' . $shift->end_time);

        $graceLate  = 0;
        $graceEarly = 0;
        $minOvertime          = 30;
        $dendaTerlambatPerJam = 0.0;

        // Scheme absensi (untuk early leave / alfa) — dari payroll_scheme
        $absenTidakPotong    = 0;    // 1 = tidak potong gaji
        $absenNominalPerDay = 0.0;  // nominal deduction per hari (Attendance Deducts Nominal)
        $absenProrate        = 1;    // default prorate (gaji/working days)

        if ($clientId) {
            $schemeTemplateModel = new \App\Models\PayrollSchemeTemplateModel();
            $stoScheme = $schemeTemplateModel->getSchemeForEmployee(
                $clientId,
                $emp->division_id ?? null,
                $emp->department_id ?? null,
                $emp->position_id ?? null
            );

            if ($stoScheme) {
                $graceLate            = intval($stoScheme['grace_period_late'] ?? 0);
                $graceEarly           = intval($stoScheme['grace_period_early'] ?? 0);
                $minOvertime          = intval($stoScheme['min_overtime'] ?? 30);
                $dendaTerlambatPerJam = floatval($stoScheme['denda_terlambat_per_jam'] ?? 0);
            } else {
                $clientConfig = $db->table('client_payroll_configs')
                                   ->where('client_id', $clientId)
                                   ->get()->getRow();
                if ($clientConfig && !empty($clientConfig->payroll_scheme_id)) {
                    $payrollScheme = $db->table('payroll_schemes')
                                        ->where('id', $clientConfig->payroll_scheme_id)
                                        ->get()->getRow();
                    if ($payrollScheme) {
                        $graceLate            = intval($payrollScheme->grace_period_late ?? 0);
                        $graceEarly           = intval($payrollScheme->grace_period_early ?? 0);
                        $minOvertime          = intval($payrollScheme->min_overtime ?? 30);
                        $dendaTerlambatPerJam = floatval($payrollScheme->denda_terlambat_per_jam ?? 0);

                        // Baca scheme absensi dari payroll_scheme
                        $absenTidakPotong    = intval($payrollScheme->absen_tidak_potong ?? 0);
                        $absenNominalPerDay = floatval($payrollScheme->nominal_deduction ?? 0);
                        // prorate = 1 jika bukan "tidak potong" dan bukan "nominal"
                        $absenProrate = ($absenTidakPotong == 0 && $absenNominalPerDay == 0) ? 1 : 0;
                    }
                }
            }
        }

        // ── ALFA / ABSEN ──────────────────────────────────────────────────────
        if ($status !== 'Hadir') {
            // Tidak hitung denda di sini — deduction alfa dihitung di Payroll.php
            // berdasarkan scheme absensi (prorate / nominal / tidak potong)
            return $result;
        }

        // ── HADIR: hitung keterlambatan ───────────────────────────────────────
        if (empty($jamMasuk)) {
            // Reuse weekend/holiday rest-day check — do NOT mark as incomplete on rest days or public holidays
            $dayOfWeek2 = intval(date('w', strtotime($tanggal)));
            $wdc2 = 5;
            if ($emp) {
                $wdc2 = intval($emp->hari_kerja ?? 5);
                if ($wdc2 < 1) {
                    $posId2 = $emp->position_id ?? null;
                    if ($posId2) {
                        $pos2 = $db->table('positions')->where('id', $posId2)->get()->getRow();
                        $wdc2 = ($pos2 && isset($pos2->hari_kerja) && intval($pos2->hari_kerja) > 0) ? intval($pos2->hari_kerja) : 5;
                    } else {
                        $wdc2 = 5;
                    }
                }
            }
            $isRestDay2 = false;
            if ($wdc2 === 5) { $isRestDay2 = ($dayOfWeek2 === 0 || $dayOfWeek2 === 6); }
            elseif ($wdc2 === 6) { $isRestDay2 = ($dayOfWeek2 === 0); }
            // Also check holiday_calendar for public holidays
            if (!$isRestDay2) {
                $pubHol2 = $db->table('holiday_calendar')->where('tanggal', $tanggal)->get()->getRow();
                if ($pubHol2) { $isRestDay2 = true; }
            }
            if (!$isRestDay2) {
                $result['is_incomplete'] = 1;
            }
            return $result;
        }

        $inTime   = strtotime($tanggal . ' ' . $jamMasuk);
        $shiftIn  = strtotime($tanggal . ' ' . $shift->start_time);
        $shiftOut = strtotime($tanggal . ' ' . $shift->end_time);

        // Keterlambatan
        $lateMinutes      = 0;
        $latePenaltyHours = 0;
        $dendaTerlambat   = 0.0;

        if ($inTime > ($shiftIn + ($graceLate * 60))) {
            $lateMinutes      = intval(ceil(($inTime - $shiftIn) / 60));
            $latePenaltyHours = intval(ceil($lateMinutes / 60.0));
            $dendaTerlambat   = $latePenaltyHours * $dendaTerlambatPerJam;
        }

        $result['late_hours']         = round($lateMinutes / 60.0, 2);
        $result['late_minutes']       = $lateMinutes;
        $result['late_penalty_hours'] = $latePenaltyHours;
        $result['denda_terlambat']    = $dendaTerlambat;

        // ── Jam keluar ────────────────────────────────────────────────────────
        if (empty($jamKeluar)) {
            $result['is_incomplete'] = 1;
            return $result;
        }

        $outTime = strtotime($tanggal . ' ' . $jamKeluar);
        if ($outTime <= $inTime) {
            $result['is_incomplete'] = 1;
            return $result;
        }

        // ── Early Leave ───────────────────────────────────────────────────────
        $earlyLeaveMinutes = 0;
        $isEarlyLeaveAlfa  = 0;
        $earlyLeaveHours   = 0.0;

        if ($outTime < $shiftOut) {
            $earlyLeaveMinutes = intval(ceil(($shiftOut - $outTime) / 60));
            $earlyLeaveHours   = round($earlyLeaveMinutes / 60.0, 2);

            // Jika early leave melebihi toleransi grace → dihitung sebagai Alfa
            if ($earlyLeaveMinutes > $graceEarly) {
                $result['is_incomplete'] = 1;
                $isEarlyLeaveAlfa = 1;
                // Deduction untuk early leave dihitung di Payroll.php (ikut scheme absensi)
            }
        }

        $result['early_leave_hours']   = $earlyLeaveHours;
        $result['is_early_leave_alfa'] = $isEarlyLeaveAlfa;
        $result['denda_alfa']          = 0.0;   // Tidak dipakai, dihitung di Payroll.php
        $result['absent_penalty']      = 0.0;   // Tidak dipakai, dihitung di Payroll.php

        // ── Work Hours Aktual ──────────────────────────────────────────────────
        $breakDuration       = isset($shift->break_duration) ? floatval($shift->break_duration) : 0.0;
        $actualDurationHours = max(0, (($outTime - $inTime) / 3600) - $breakDuration);
        $standardDuration    = floatval($shift->duration);

        $workHours = round(min($standardDuration, $actualDurationHours), 2);
        $result['calculated_work_hours'] = min(9999.99, max(0, $workHours));

        // ── Overtime ──────────────────────────────────────────────────────────
        $otHours   = 0.0;
        $otMinutes = 0;

        if ($outTime > $shiftOut) {
            $otMinutes = ($outTime - $shiftOut) / 60;
            $otHours   = $otMinutes / 60.0;
        }

        if ($otMinutes >= $minOvertime) {
            $result['calculated_overtime_hours'] = min(9999.99, round(max(0, $otHours), 2));
        } else {
            $result['calculated_overtime_hours'] = 0.0;
        }

        // ── Sync ke overtime_logs ─────────────────────────────────────────────
        if ($result['calculated_overtime_hours'] > 0) {
            $existingOt = $db->table('overtime_logs')
                ->where('employee_id', intval($employeeId))
                ->where('tanggal', $tanggal)
                ->get()->getRow();

            $isHoliday = 0;
            $dayOfWeek = date('w', strtotime($tanggal));
            if ($dayOfWeek == 0) {
                $isHoliday = 1;
            } else {
                $holiday = $db->table('holiday_calendar')->where('tanggal', $tanggal)->get()->getRow();
                if ($holiday) {
                    $isHoliday = 1;
                } elseif ($dayOfWeek == 6) {
                    // Check employee's working days config
                    $emp = $db->table('employees')
                        ->select('employees.hari_kerja, positions.hari_kerja as position_hari_kerja')
                        ->join('positions', 'positions.id = employees.position_id', 'left')
                        ->where('employees.id', intval($employeeId))
                        ->get()->getRow();
                    $workDaysPerWeek = 5;
                    if ($emp) {
                        $workDaysPerWeek = intval($emp->hari_kerja ?: ($emp->position_hari_kerja ?: 5));
                    }
                    $isHoliday = ($workDaysPerWeek < 6) ? 1 : 0;
                }
            }

            $jamOvertimeVal = floatval($result['calculated_overtime_hours']);
            if (!$isHoliday && $jamOvertimeVal > 3.0) {
                $jamOvertimeVal = 3.0;
            }

            $otData = [
                'jam_overtime'    => $jamOvertimeVal,
                'is_holiday'    => $isHoliday,
                'description'    => 'Auto: shift ' . $shift->name,
                'status'        => 'Pending',
                'approved_by'   => null,
                'approved_at'   => null,
                'is_rapel'      => $otIsRapel,
                'payout_period' => $otPayoutPeriod
            ];

            if ($existingOt) {
                $db->table('overtime_logs')->where('id', $existingOt->id)->update($otData);
            } else {
                $otData['employee_id'] = intval($employeeId);
                $otData['tanggal']     = $tanggal;
                $db->table('overtime_logs')->insert($otData);
            }
        } else {
            $db->table('overtime_logs')
                ->where('employee_id', intval($employeeId))
                ->where('tanggal', $tanggal)
                ->delete();
        }

        return $result;
    }

    public function getAttendanceLogs()
    {
        $employeeId = $this->request->getGet('employee_id');
        $month = $this->request->getGet('month');
        $year = $this->request->getGet('year');
        $clientId = $this->request->getGet('client_id');
        $tanggal = $this->request->getGet('tanggal');
        if ($deny = $this->guardClientParam($clientId)) return $deny;
        if ($employeeId && ($deny = $this->guardEntity('employees', $employeeId))) return $deny;

        $builder = $this->db->table('attendance_logs');
        $builder->select('attendance_logs.id, attendance_logs.employee_id, attendance_logs.log_date as tanggal, 
                          attendance_logs.status, attendance_logs.check_in as jam_masuk, 
                          attendance_logs.check_out as jam_keluar, attendance_logs.notes as description, 
                          attendance_logs.created_at, attendance_logs.shift_scheme_id, attendance_logs.is_rapel, 
                          attendance_logs.payout_period, attendance_logs.calculated_work_hours, 
                          attendance_logs.calculated_overtime_hours, attendance_logs.is_incomplete,
                          employees.nama as employee_name, shift_schemes.name as shift_name,
                          employees.hari_kerja as employee_hari_kerja,
                          positions.hari_kerja as position_hari_kerja,
                          holiday_calendar.deskripsi as holiday_deskripsi,
                          early_arrival.eligible_minutes as ea_eligible_minutes,
                          early_arrival.status as ea_status');
        $builder->join('employees', 'employees.id = attendance_logs.employee_id', 'left');
        $builder->join('shift_schemes', 'shift_schemes.id = attendance_logs.shift_scheme_id', 'left');
        $builder->join('positions', 'positions.id = employees.position_id', 'left');
        $builder->join('holiday_calendar', 'holiday_calendar.tanggal = attendance_logs.log_date', 'left');
        $builder->join('early_arrival', 'early_arrival.attendance_id = attendance_logs.id', 'left');

        if ($employeeId) {
            $builder->where('attendance_logs.employee_id', intval($employeeId));
        }
        if ($clientId) {
            $builder->where('employees.client_id', intval($clientId));
        } else {
            $this->scopeToAllowedClients($builder, 'employees.client_id');
        }
        if ($tanggal) {
            $builder->where('attendance_logs.log_date', $tanggal);
        } elseif ($month && $year) {
            $builder->where('MONTH(attendance_logs.log_date)', intval($month));
            $builder->where('YEAR(attendance_logs.log_date)', intval($year));
        }

        $builder->orderBy('attendance_logs.log_date', 'ASC');
        $logs = $builder->get()->getResultArray();

        // Enrich each log with is_holiday flag based on holiday_calendar + day of week + work schedule
        foreach ($logs as &$log) {
            $isHoliday = 0;
            $holidayName = $log['holiday_deskripsi'] ?? null;
            
            if (!empty($holidayName)) {
                // It's in the holiday_calendar
                $isHoliday = 1;
            } else {
                $dayOfWeek = date('w', strtotime($log['tanggal']));
                if ($dayOfWeek == 0) {
                    // Sunday is always a holiday
                    $isHoliday = 1;
                    $holidayName = 'Day Week';
                } elseif ($dayOfWeek == 6) {
                    // Saturday is holiday only for 5-day work week
                    $workDays = intval($log['employee_hari_kerja'] ?: ($log['position_hari_kerja'] ?: 5));
                    if ($workDays < 6) {
                        $isHoliday = 1;
                        $holidayName = 'Day Sabtu (5 working days)';
                    }
                }
            }
            
            $log['is_holiday'] = $isHoliday;
            $log['holiday_name'] = $holidayName;
        }
        unset($log); // break reference

        $cutoffDay = 21;
        if ($clientId) {
            $config = $this->db->table('client_payroll_configs')
                ->where('client_id', intval($clientId))
                ->get()->getRow();
            if ($config && isset($config->cutoff_start)) {
                $cutoffDay = intval($config->cutoff_start);
            }
        }

        $isLateUpload = false;
        $cutoffDateStr = null;
        // Fetch holidays for the requested period
        $holidays = [];
        if ($month && $year) {
            $daysInMonth = intval(date('t', mktime(0, 0, 0, intval($month), 1, intval($year))));
            // Cutoff is for next month's payroll since this month's attendance is for next month's payroll
            $nextMonth = intval($month) + 1;
            $nextYear = intval($year);
            if ($nextMonth > 12) {
                $nextMonth = 1;
                $nextYear++;
            }
            $daysInNextMonth = intval(date('t', mktime(0, 0, 0, $nextMonth, 1, $nextYear)));
            $dayVal = min($cutoffDay, $daysInNextMonth);
            $cutoffDateStr = sprintf('%04d-%02d-%02d', $nextYear, $nextMonth, $dayVal);
            if (date('Y-m-d') > $cutoffDateStr) {
                $isLateUpload = true;
            }

            // Get all holidays for the month/year
            $startDate = sprintf('%04d-%02d-01', intval($year), intval($month));
            $endDate = sprintf('%04d-%02d-%02d', intval($year), intval($month), $daysInMonth);
            $holidayRows = $this->db->table('holiday_calendar')
                ->where('tanggal >=', $startDate)
                ->where('tanggal <=', $endDate)
                ->orderBy('tanggal', 'ASC')
                ->get()->getResultArray();
            foreach ($holidayRows as $h) {
                $holidays[$h['tanggal']] = $h['deskripsi'];
            }
        }

        // Calculate summary metrics
        $totalActualHours = 0.0;
        foreach ($logs as $l) {
            $totalActualHours += floatval($l['calculated_work_hours'] ?? 0);
        }
        $totalActualHours = round($totalActualHours, 2);

        // Calculate expected hours for the month (excluding weekends & holidays)
        $expectedHours = 0;
        if ($month && $year) {
            $numDaysInMonth = intval(date('t', mktime(0, 0, 0, intval($month), 1, intval($year))));
            $workDaysCount = 0;
            for ($d = 1; $d <= $numDaysInMonth; $d++) {
                $dateStr = sprintf('%04d-%02d-%02d', intval($year), intval($month), $d);
                $dayOfWeek = date('w', strtotime($dateStr));
                if ($dayOfWeek != 0 && $dayOfWeek != 6 && !isset($holidays[$dateStr])) {
                    $workDaysCount++;
                }
            }
            $expectedHours = $workDaysCount * 8; // Standard 8 hours per workday
        }

        // Calculate tenure in months for employee(s)
        $tenureDetails = [];
        $tenureMonthsList = [];
        $employeeIds = array_unique(array_column($logs, 'employee_id'));
        if (empty($employeeIds) && $employeeId) {
            $employeeIds = [intval($employeeId)];
        }
        if (empty($employeeIds) && $clientId) {
            $empRows = $this->db->table('employees')
                ->select('employees.id, employees.nik, employees.nama, employees.tgl_masuk, employees.start_contract, employees.tipe_perjanjian, positions.nama as position_name')
                ->join('positions', 'positions.id = employees.position_id', 'left')
                ->where('employees.client_id', intval($clientId))
                ->get()->getResultArray();
        } else if (!empty($employeeIds)) {
            $empRows = $this->db->table('employees')
                ->select('employees.id, employees.nik, employees.nama, employees.tgl_masuk, employees.start_contract, employees.tipe_perjanjian, positions.nama as position_name')
                ->join('positions', 'positions.id = employees.position_id', 'left')
                ->whereIn('employees.id', $employeeIds)
                ->get()->getResultArray();
        } else {
            $empRows = [];
        }

        $targetYear = $year ? intval($year) : intval(date('Y'));
        $targetMonth = $month ? intval($month) : intval(date('n'));

        foreach ($empRows as $er) {
            $jDate = !empty($er['tgl_masuk']) ? $er['tgl_masuk'] : ($er['start_contract'] ?? null);
            $mWorked = 0;
            if ($jDate) {
                $jYear = intval(date('Y', strtotime($jDate)));
                $jMonth = intval(date('n', strtotime($jDate)));
                $mWorked = max(1, ($targetYear - $jYear) * 12 + ($targetMonth - $jMonth) + 1);
                $tenureMonthsList[] = $mWorked;
            }
            $tenureDetails[] = [
                'id'              => $er['id'],
                'nik'             => $er['nik'] ?? '-',
                'nama'            => $er['nama'],
                'position_name'   => $er['position_name'] ?? '-',
                'tgl_masuk'       => $jDate ? date('Y-m-d', strtotime($jDate)) : '-',
                'tipe_perjanjian' => $er['tipe_perjanjian'] ?? 'PKWT',
                'tenure_months'   => $mWorked
            ];
        }

        $avgTenureMonths = !empty($tenureMonthsList) ? round(array_sum($tenureMonthsList) / count($tenureMonthsList), 1) : 0;
        $maxTenureMonths = !empty($tenureMonthsList) ? max($tenureMonthsList) : 0;

        return $this->respond([
            'data' => $logs,
            'is_late_upload' => $isLateUpload,
            'cutoff_date' => $cutoffDateStr,
            'holidays' => $holidays,
            'summary' => [
                'total_actual_hours' => $totalActualHours,
                'expected_hours'     => $expectedHours,
                'work_days_count'    => $workDaysCount ?? 19,
                'avg_tenure_months'  => $avgTenureMonths,
                'max_tenure_months'  => $maxTenureMonths,
                'tenure_details'     => $tenureDetails
            ]
        ]);
    }

    public function createAttendanceLog()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['employee_id']) || empty($data['tanggal'])) {
            return $this->failValidationErrors('employee_id dan tanggal required');
        }

        $empInfo = $this->db->table('employees')
            ->select('tgl_masuk, start_contract, nama')
            ->where('id', intval($data['employee_id']))
            ->get()->getRow();

        if ($empInfo) {
            $joinDate = !empty($empInfo->tgl_masuk) ? $empInfo->tgl_masuk : ($empInfo->start_contract ?? null);
            if (!empty($joinDate) && strtotime($data['tanggal']) < strtotime($joinDate)) {
                return $this->failValidationErrors("Karyawan '{$empInfo->nama}' belum bergabung pada tanggal tersebut (Tanggal bergabung: " . date('d-m-Y', strtotime($joinDate)) . ").");
            }
        }

        $calc = $this->calculateShiftAttendance(
            $data['employee_id'],
            $data['tanggal'],
            $data['jam_masuk'] ?? null,
            $data['jam_keluar'] ?? null,
            $data['status'] ?? 'Hadir',
            $data['shift_scheme_id'] ?? null,
            $data['payout_period'] ?? null
        );

        // Cek duplikat hanya berdasarkan employee_id + log_date (abaikan shift_scheme_id)
        $existing = $this->db->table('attendance_logs')
            ->where('employee_id', intval($data['employee_id']))
            ->where('log_date', $data['tanggal'])
            ->get()->getRow();

        if ($existing) {
            $this->db->table('attendance_logs')->where('id', $existing->id)->update([
                'status' => $data['status'] ?? 'Hadir',
                'check_in' => $data['jam_masuk'] ?? null,
                'check_out' => $data['jam_keluar'] ?? null,
                'notes' => $data['description'] ?? null,
                'shift_scheme_id'            => $calc['shift_scheme_id'],
                'is_rapel'                   => $calc['is_rapel'],
                'payout_period'              => $calc['payout_period'],
                'calculated_work_hours'      => $calc['calculated_work_hours'],
                'calculated_overtime_hours'  => $calc['calculated_overtime_hours'],
                'late_hours'                 => $calc['late_hours'],
                'late_minutes'               => $calc['late_minutes'],
                'late_penalty_hours'         => $calc['late_penalty_hours'],
                'denda_terlambat'            => $calc['denda_terlambat'],
                'early_leave_hours'          => $calc['early_leave_hours'],
                'early_leave_minutes'        => $calc['early_leave_minutes'] ?? 0,
                'is_early_leave_alfa'        => $calc['is_early_leave_alfa'],
                'denda_alfa'                 => $calc['denda_alfa'],
                'absent_penalty'             => $calc['absent_penalty'],
                'is_incomplete'              => $calc['is_incomplete'],
            ]);
            $this->syncEarlyArrival(
                $existing->id,
                intval($data['employee_id']),
                $data['tanggal'],
                $data['jam_masuk'] ?? null,
                $calc['shift_scheme_id'],
                $calc['payout_period']
            );
            return $this->respond([
                'message' => 'Attendance log updated successfully',
                'payout_period' => $calc['payout_period']
            ]);
        }

        $insertData = [
            'employee_id'                => intval($data['employee_id']),
            'log_date'                   => $data['tanggal'],
            'status'                     => $data['status'] ?? 'Hadir',
            'check_in'                   => $data['jam_masuk'] ?? null,
            'check_out'                  => $data['jam_keluar'] ?? null,
            'notes'                      => $data['description'] ?? null,
            'shift_scheme_id'            => $calc['shift_scheme_id'],
            'is_rapel'                   => $calc['is_rapel'],
            'payout_period'              => $calc['payout_period'],
            'calculated_work_hours'      => $calc['calculated_work_hours'],
            'calculated_overtime_hours'  => $calc['calculated_overtime_hours'],
            'late_hours'                 => $calc['late_hours'],
            'late_minutes'               => $calc['late_minutes'],
            'late_penalty_hours'         => $calc['late_penalty_hours'],
            'denda_terlambat'            => $calc['denda_terlambat'],
            'early_leave_hours'          => $calc['early_leave_hours'],
            'early_leave_minutes'        => $calc['early_leave_minutes'] ?? 0,
            'is_early_leave_alfa'        => $calc['is_early_leave_alfa'],
            'denda_alfa'                 => $calc['denda_alfa'],
            'absent_penalty'             => $calc['absent_penalty'],
            'is_incomplete'              => $calc['is_incomplete'],
        ];

        $this->db->table('attendance_logs')->insert($insertData);
        $attendanceId = $this->db->insertID();
        $this->syncEarlyArrival(
            $attendanceId,
            intval($data['employee_id']),
            $data['tanggal'],
            $data['jam_masuk'] ?? null,
            $calc['shift_scheme_id'],
            $calc['payout_period']
        );
        return $this->respondCreated([
            'message' => 'Attendance log added successfully',
            'payout_period' => $calc['payout_period']
        ]);
    }

    public function createAttendanceBulk()
    {
        $data = $this->request->getJSON(true);
        $logs = $data['logs'] ?? [];
        log_message('error', 'BULK LOGS RECEIVED: ' . json_encode($logs));
        $count = 0;
        $skippedCount = 0;
        $employeesById = [];
        $clientId = null;
        $payoutPeriod = null;

        if (!empty($logs)) {
            $firstLog = $logs[0];
            $empId = intval($firstLog['employee_id']);
            $firstLogDate = $firstLog['tanggal'] ?? null;
            
            if ($empId && $firstLogDate) {
                $ts = strtotime($firstLogDate);
                $uploadMonth = intval(date('n', $ts));
                $uploadYear = intval(date('Y', $ts));
                
                $emp = $this->db->table('employees')->where('id', $empId)->get()->getRow();
                if ($emp) {
                    $clientId = $emp->client_id;
                    $employees = $this->db->table('employees')->where('client_id', $clientId)->get()->getResultArray();
                    foreach ($employees as $e) {
                        $employeesById[intval($e['id'])] = $e;
                    }
                    $employeeIds = array_column($employees, 'id');
                    
                    if (!empty($employeeIds)) {
                        // Delete existing attendance logs for this client and calendar month/year
                        $this->db->table('attendance_logs')
                            ->whereIn('employee_id', $employeeIds)
                            ->where('MONTH(log_date)', $uploadMonth)
                            ->where('YEAR(log_date)', $uploadYear)
                            ->delete();

                        // Delete existing early arrival logs for this client and calendar month/year
                        $this->db->table('early_arrival')
                            ->whereIn('employee_id', $employeeIds)
                            ->where('MONTH(date)', $uploadMonth)
                            ->where('YEAR(date)', $uploadYear)
                            ->delete();
                            
                        // Delete ALL auto-generated pending overtime logs for these employees in this calendar month/year
                        $this->db->table('overtime_logs')
                            ->whereIn('employee_id', $employeeIds)
                            ->where('status', 'Pending')
                            ->like('description', 'Auto: shift', 'after')
                            ->where('MONTH(tanggal)', $uploadMonth)
                            ->where('YEAR(tanggal)', $uploadYear)
                            ->delete();
                    }
                }
            }
        }

        foreach ($logs as $log) {
            if (empty($log['employee_id']) || empty($log['tanggal'])) {
                log_message('error', 'SKIPPED LOG DUE TO EMPTY FIELD: ' . json_encode($log));
                continue;
            }

            $currentEmpId = intval($log['employee_id']);
            $empInfo = null;
            if (isset($employeesById[$currentEmpId])) {
                $empInfo = $employeesById[$currentEmpId];
            } else {
                $empRow = $this->db->table('employees')->where('id', $currentEmpId)->get()->getRowArray();
                if ($empRow) {
                    $employeesById[$currentEmpId] = $empRow;
                    $empInfo = $empRow;
                }
            }

            if ($empInfo) {
                $joinDate = !empty($empInfo['tgl_masuk']) ? $empInfo['tgl_masuk'] : ($empInfo['start_contract'] ?? null);
                if (!empty($joinDate) && strtotime($log['tanggal']) < strtotime($joinDate)) {
                    log_message('info', "SKIPPED LOG: Employee {$empInfo['nama']} has not joined yet on {$log['tanggal']} (joined {$joinDate})");
                    $skippedCount++;
                    continue;
                }
            }

            $shiftSchemeId = $log['shift_scheme_id'] ?? null;

            // Jika shift_scheme_id kosong, coba resolve dari shift_name
            if (empty($shiftSchemeId) && !empty($log['shift_name'])) {
                $matchedShift = $this->db->table('shift_schemes')
                    ->where('LOWER(name)', strtolower(trim($log['shift_name'])))
                    ->get()->getRow();
                if (!$matchedShift) {
                    // Coba partial match
                    $matchedShift = $this->db->table('shift_schemes')
                        ->like('name', trim($log['shift_name']), 'none')
                        ->get()->getRow();
                }
                if ($matchedShift) {
                    $shiftSchemeId = $matchedShift->id;
                }
            }

            // Jika masih kosong, coba auto-detect shift dari jam masuk
            if (empty($shiftSchemeId) && !empty($log['jam_masuk'])) {
                $jamMasukInt = intval(explode(':', $log['jam_masuk'])[0]);
                // Shift pagi: masuk sebelum jam 12
                // Shift siang: masuk jam 12 ke atas
                $namaShiftGuess = $jamMasukInt < 12 ? 'Pagi' : 'Siang';
                $matchedShift = $this->db->table('shift_schemes')
                    ->where('name', $namaShiftGuess)
                    ->get()->getRow();
                if ($matchedShift) {
                    $shiftSchemeId = $matchedShift->id;
                }
            }

            $calc = $this->calculateShiftAttendance(
                $log['employee_id'],
                $log['tanggal'],
                $log['jam_masuk'] ?? null,
                $log['jam_keluar'] ?? null,
                $log['status'] ?? 'Hadir',
                $shiftSchemeId,
                $log['payout_period'] ?? null
            );

            // Cek duplikat hanya berdasarkan employee_id + log_date (abaikan shift_scheme_id)
            $existing = $this->db->table('attendance_logs')
                ->where('employee_id', intval($log['employee_id']))
                ->where('log_date', $log['tanggal'])
                ->get()->getRow();

            $logData = [
                'status'                     => $log['status'] ?? 'Hadir',
                'check_in'                   => $log['jam_masuk'] ?? null,
                'check_out'                  => $log['jam_keluar'] ?? null,
                'notes'                      => $log['description'] ?? null,
                'shift_scheme_id'            => $calc['shift_scheme_id'],
                'is_rapel'                   => $calc['is_rapel'],
                'payout_period'              => $calc['payout_period'],
                'calculated_work_hours'      => min(9999.99, max(0, floatval($calc['calculated_work_hours']))),
                'calculated_overtime_hours'  => min(9999.99, max(0, floatval($calc['calculated_overtime_hours']))),
                'late_hours'                 => min(9999.99, max(0, floatval($calc['late_hours']))),
                'late_minutes'               => intval($calc['late_minutes']),
                'late_penalty_hours'         => intval($calc['late_penalty_hours']),
                'denda_terlambat'            => floatval($calc['denda_terlambat']),
                'early_leave_hours'          => min(9999.99, max(0, floatval($calc['early_leave_hours']))),
                'early_leave_minutes'        => intval($calc['early_leave_minutes'] ?? 0),
                'is_early_leave_alfa'        => intval($calc['is_early_leave_alfa']),
                'denda_alfa'                 => floatval($calc['denda_alfa']),
                'absent_penalty'             => floatval($calc['absent_penalty']),
                'is_incomplete'              => $calc['is_incomplete'],
            ];

            if ($existing) {
                $this->db->table('attendance_logs')->where('id', $existing->id)->update($logData);
                $this->syncEarlyArrival(
                    $existing->id,
                    intval($log['employee_id']),
                    $log['tanggal'],
                    $log['jam_masuk'] ?? null,
                    $calc['shift_scheme_id'],
                    $calc['payout_period']
                );
            } else {
                $logData['employee_id'] = intval($log['employee_id']);
                $logData['log_date'] = $log['tanggal'];
                $this->db->table('attendance_logs')->insert($logData);
                $attendanceId = $this->db->insertID();
                $this->syncEarlyArrival(
                    $attendanceId,
                    intval($log['employee_id']),
                    $log['tanggal'],
                    $log['jam_masuk'] ?? null,
                    $calc['shift_scheme_id'],
                    $calc['payout_period']
                );
            }
            if (empty($payoutPeriod) && !empty($calc['payout_period'])) {
                $payoutPeriod = $calc['payout_period'];
            }
            $count++;
        }

        // Trigger automatic recalculation of payroll summaries
        if ($payoutPeriod && $clientId) {
            $parts = explode('-', $payoutPeriod);
            if (count($parts) === 2) {
                $pMonth = intval($parts[0]);
                $pYear = intval($parts[1]);
                $period = $this->db->table('payroll_periods')
                                   ->where('client_id', $clientId)
                                   ->where('month', $pMonth)
                                   ->where('year', $pYear)
                                   ->get()->getRow();
                if ($period) {
                    // Reset is_manual = 0 for client employees in this period so that recalculation is allowed
                    $pkwtIds = $this->db->table('pkwt')
                                        ->select('id')
                                        ->where('client_id', intval($clientId))
                                        ->get()->getResultArray();
                    $pkwtIdsList = array_column($pkwtIds, 'id');
                    if (!empty($pkwtIdsList)) {
                        $this->db->table('payroll_attendance')
                                 ->where('period_id', $period->id)
                                 ->whereIn('pkwt_id', $pkwtIdsList)
                                 ->update(['is_manual' => 0]);
                    }
                    // Run the sync methods immediately to update payroll_attendance summaries
                    $this->syncEmployeesToPKWT($clientId);
                    $this->syncOvertimeToPayrollAttendance($period->id, $clientId);
                    $this->syncEarlyArrivalToPayrollAttendance($period->id, $clientId);
                }
            }
        }

        $msg = "Successfully menyimpan {$count} attendance logs.";
        if ($skippedCount > 0) {
            $msg .= " {$skippedCount} logs dilewati karena tanggal absensi mendahului tanggal bergabung karyawan.";
        }
        return $this->respondCreated(['message' => $msg]);
    }

    public function updateAttendanceLog($id)
    {
        $data = $this->request->getJSON(true);
        $old = $this->db->table('attendance_logs')->where('id', $id)->get()->getRow();
        if (!$old) {
            return $this->fail('Attendance log not found');
        }

        $empInfo = $this->db->table('employees')
            ->select('tgl_masuk, start_contract, nama')
            ->where('id', intval($old->employee_id))
            ->get()->getRow();

        if ($empInfo) {
            $joinDate = !empty($empInfo->tgl_masuk) ? $empInfo->tgl_masuk : ($empInfo->start_contract ?? null);
            if (!empty($joinDate)) {
                $tanggal = $data['tanggal'] ?? $old->log_date;
                if (strtotime($tanggal) < strtotime($joinDate)) {
                    return $this->failValidationErrors("Karyawan '{$empInfo->nama}' belum bergabung pada tanggal tersebut (Tanggal bergabung: " . date('d-m-Y', strtotime($joinDate)) . ").");
                }
            }
        }

        $calc = $this->calculateShiftAttendance(
            $old->employee_id,
            $old->log_date,
            $data['jam_masuk'] ?? $old->check_in,
            $data['jam_keluar'] ?? $old->check_out,
            $data['status'] ?? $old->status,
            $data['shift_scheme_id'] ?? $old->shift_scheme_id,
            $data['payout_period'] ?? $old->payout_period
        );

        $updateData = [
            'status' => $data['status'] ?? $old->status,
            'check_in' => $data['jam_masuk'] ?? $old->check_in,
            'check_out' => $data['jam_keluar'] ?? $old->check_out,
            'notes' => $data['description'] ?? $old->notes,
            'shift_scheme_id' => $calc['shift_scheme_id'],
            'is_rapel' => $calc['is_rapel'],
            'payout_period' => $calc['payout_period'],
            'calculated_work_hours' => $calc['calculated_work_hours'],
            'calculated_overtime_hours' => $calc['calculated_overtime_hours'],
            'late_hours' => $calc['late_hours'],
            'late_minutes' => $calc['late_minutes'] ?? 0,
            'late_penalty_hours' => $calc['late_penalty_hours'] ?? 0,
            'denda_terlambat' => $calc['denda_terlambat'] ?? 0,
            'early_leave_hours' => $calc['early_leave_hours'],
            'early_leave_minutes' => $calc['early_leave_minutes'] ?? 0,
            'is_early_leave_alfa' => $calc['is_early_leave_alfa'] ?? 0,
            'denda_alfa' => $calc['denda_alfa'] ?? 0,
            'absent_penalty' => $calc['absent_penalty'] ?? 0,
            'is_incomplete' => $calc['is_incomplete']
        ];

        $this->db->table('attendance_logs')->where('id', $id)->update($updateData);
        $this->syncEarlyArrival(
            $id,
            $old->employee_id,
            $old->log_date,
            $data['jam_masuk'] ?? $old->check_in,
            $calc['shift_scheme_id'],
            $calc['payout_period']
        );
        return $this->respond([
            'message' => 'Attendance log updated successfully',
            'payout_period' => $calc['payout_period']
        ]);
    }

    public function deleteAttendanceLog($id)
    {
        if ($deny = $this->guardEntity('attendance_logs', $id)) return $deny;
        $old = $this->db->table('attendance_logs')->where('id', $id)->get()->getRow();
        if ($old) {
            // Also clean up automatic overtime log
            $this->db->table('overtime_logs')
                ->where('employee_id', $old->employee_id)
                ->where('tanggal', $old->log_date)
                ->delete();
        }
        // Clean up early arrival log
        $this->db->table('early_arrival')->where('attendance_id', $id)->delete();
        $this->db->table('attendance_logs')->where('id', $id)->delete();
        return $this->respondDeleted(['message' => 'Attendance log deleted successfully']);
    }

    // --- OVERTIME LOGS ---
    private function autoSyncHolidayAttendanceToOvertime($clientId, $month, $year)
    {
        if (!$month || !$year) return;

        $startDate = sprintf('%04d-%02d-01', intval($year), intval($month));
        $endDate = date('Y-m-t', strtotime($startDate));

        $holidayRows = $this->db->table('holiday_calendar')
            ->where('tanggal >=', $startDate)
            ->where('tanggal <=', $endDate)
            ->get()->getResultArray();
        $holidays = [];
        foreach ($holidayRows as $h) {
            $holidays[$h['tanggal']] = true;
        }

        $builder = $this->db->table('attendance_logs')
            ->select('attendance_logs.*, employees.hari_kerja as employee_hari_kerja, positions.hari_kerja as position_hari_kerja')
            ->join('employees', 'employees.id = attendance_logs.employee_id')
            ->join('positions', 'positions.id = employees.position_id', 'left')
            ->where('attendance_logs.log_date >=', $startDate)
            ->where('attendance_logs.log_date <=', $endDate)
            ->where('LOWER(attendance_logs.status)', 'hadir');

        if ($clientId) {
            $builder->where('employees.client_id', intval($clientId));
        }

        $attLogs = $builder->get()->getResultArray();

        foreach ($attLogs as $att) {
            $empId = intval($att['employee_id']);
            $tgl = $att['log_date'];
            $dow = intval(date('w', strtotime($tgl)));

            $wConfig = intval($att['employee_hari_kerja'] ?: ($att['position_hari_kerja'] ?: 5));
            $isHoliday = 0;

            if (isset($holidays[$tgl])) {
                $isHoliday = 1;
            } elseif ($dow === 0) {
                $isHoliday = 1;
            } elseif ($dow === 6 && $wConfig < 6) {
                $isHoliday = 1;
            }

            if ($isHoliday === 1) {
                $existing = $this->db->table('overtime_logs')
                    ->where('employee_id', $empId)
                    ->where('tanggal', $tgl)
                    ->get()->getRow();

                if (!$existing) {
                    $workHrs = floatval($att['calculated_work_hours']);
                    if ($workHrs <= 0) $workHrs = 8.0;

                    $this->db->table('overtime_logs')->insert([
                        'employee_id'   => $empId,
                        'tanggal'       => $tgl,
                        'jam_overtime'    => $workHrs,
                        'is_holiday'    => 1,
                        'description'    => 'Auto: Overtime Holiday',
                        'status'        => 'Pending',
                        'approved_by'   => null,
                        'approved_at'   => null,
                        'is_rapel'      => intval($att['is_rapel'] ?? 0),
                        'payout_period' => $att['payout_period'] ?? null
                    ]);
                }
            }
        }
    }

    public function getOvertimeLogs()
    {
        $employeeId = $this->request->getGet('employee_id');
        $month = $this->request->getGet('month');
        $year = $this->request->getGet('year');
        $clientId = $this->request->getGet('client_id');
        if ($deny = $this->guardClientParam($clientId)) return $deny;
        if ($employeeId && ($deny = $this->guardEntity('employees', $employeeId))) return $deny;

        $this->autoSyncHolidayAttendanceToOvertime($clientId, $month, $year);

        $builder = $this->db->table('overtime_logs');
        $builder->select('overtime_logs.*, employees.nama as employee_name, employees.nik as employee_nik, attendance_logs.check_in as jam_masuk, attendance_logs.check_out as jam_keluar');
        $builder->join('employees', 'employees.id = overtime_logs.employee_id', 'left');
        $builder->join('attendance_logs', 'attendance_logs.employee_id = overtime_logs.employee_id AND attendance_logs.log_date = overtime_logs.tanggal', 'left');

        if ($employeeId) {
            $builder->where('overtime_logs.employee_id', intval($employeeId));
        }
        if ($clientId) {
            $builder->where('employees.client_id', intval($clientId));
        } else {
            $this->scopeToAllowedClients($builder, 'employees.client_id');
        }
        if ($month && $year) {
            $builder->where('MONTH(overtime_logs.tanggal)', intval($month));
            $builder->where('YEAR(overtime_logs.tanggal)', intval($year));
        }

        $builder->orderBy('overtime_logs.tanggal', 'ASC');
        $logs = $builder->get()->getResultArray();
        return $this->respond($logs);
    }

    public function createOvertimeLog()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['employee_id']) || empty($data['tanggal']) || !isset($data['jam_overtime'])) {
            return $this->failValidationErrors('employee_id, tanggal, dan jam_overtime required');
        }

        $jamOvertime = floatval($data['jam_overtime']);
        $isHoliday = intval($data['is_holiday'] ?? 0);

        if ($jamOvertime <= 0) {
            return $this->failValidationErrors('Jam overtime harus lebih dari 0!');
        }

        $empInfo = $this->db->table('employees')
            ->select('tgl_masuk, start_contract, nama')
            ->where('id', intval($data['employee_id']))
            ->get()->getRow();

        if ($empInfo) {
            $joinDate = !empty($empInfo->tgl_masuk) ? $empInfo->tgl_masuk : ($empInfo->start_contract ?? null);
            if (!empty($joinDate) && strtotime($data['tanggal']) < strtotime($joinDate)) {
                return $this->failValidationErrors("Karyawan '{$empInfo->nama}' belum bergabung pada tanggal tersebut (Tanggal bergabung: " . date('d-m-Y', strtotime($joinDate)) . ").");
            }
        }

        // Auto-detect holiday from holiday_calendar and employee's work schedule
        if (!$isHoliday) {
            $holiday = $this->db->table('holiday_calendar')
                ->where('tanggal', $data['tanggal'])
                ->get()->getRow();
            if ($holiday) {
                $isHoliday = 1;
            } else {
                $dayOfWeek = date('w', strtotime($data['tanggal']));
                if ($dayOfWeek == 0) {
                    $isHoliday = 1;
                } elseif ($dayOfWeek == 6) {
                    // Check employee's working days config
                    $emp = $this->db->table('employees')
                        ->select('employees.hari_kerja, positions.hari_kerja as position_hari_kerja')
                        ->join('positions', 'positions.id = employees.position_id', 'left')
                        ->where('employees.id', intval($data['employee_id']))
                        ->get()->getRow();
                    $workDaysPerWeek = 5;
                    if ($emp) {
                        $workDaysPerWeek = intval($emp->hari_kerja ?: ($emp->position_hari_kerja ?: 5));
                    }
                    // Saturday is weekend/holiday only for 5-day work weeks
                    $isHoliday = ($workDaysPerWeek < 6) ? 1 : 0;
                }
            }
        }

        // Cap regular working day overtime to 3 hours
        if (!$isHoliday && $jamOvertime > 3.0) {
            $jamOvertime = 3.0;
        }

        // Check duplicate
        $existing = $this->db->table('overtime_logs')
            ->where('employee_id', intval($data['employee_id']))
            ->where('tanggal', $data['tanggal'])
            ->get()->getRow();
        if ($existing) {
            $this->db->table('overtime_logs')->where('id', $existing->id)->update([
                'jam_overtime' => $jamOvertime,
                'is_holiday' => $isHoliday,
                'description' => $data['description'] ?? null,
                'status' => 'Pending',
                'approved_by' => null,
                'approved_at' => null
            ]);
            $this->syncPayrollAttendanceOvertimeForLog($existing->id);
            return $this->respond(['message' => 'Overtime log updated successfully']);
        }

        $insertData = [
            'employee_id' => intval($data['employee_id']),
            'tanggal' => $data['tanggal'],
            'jam_overtime' => $jamOvertime,
            'is_holiday' => $isHoliday,
            'description' => $data['description'] ?? null,
            'status' => 'Pending',
            'approved_by' => null,
            'approved_at' => null
        ];

        $this->db->table('overtime_logs')->insert($insertData);
        $this->syncPayrollAttendanceOvertime($insertData['employee_id'], $insertData['tanggal']);
        return $this->respondCreated(['message' => 'Overtime log added successfully']);
    }

    public function importOvertimeLogs()
    {
        $db = \Config\Database::connect();
        $json = $this->request->getJSON(true);
        $logs = $json['logs'] ?? [];
        $payoutPeriodStr = $json['payout_period'] ?? null;
        
        if (empty($logs)) {
            return $this->failValidationErrors('No data available overtime yang diunggah.');
        }

        if (!empty($logs) && !empty($payoutPeriodStr)) {
            $firstLog = $logs[0];
            $nik = trim($firstLog['nik'] ?? '');
            $nama = trim($firstLog['nama'] ?? '');
            
            $employee = null;
            if (!empty($nik)) {
                $employee = $db->table('employees')->where('nik', $nik)->get()->getRowArray();
            }
            if (!$employee && !empty($nama)) {
                $employee = $db->table('employees')->where('LOWER(nama)', strtolower($nama))->get()->getRowArray();
            }
            if (!$employee && !empty($nama)) {
                $employee = $db->table('employees')->like('nama', $nama)->get()->getRowArray();
            }
            
            if ($employee) {
                $clientId = $employee['client_id'];
                $employees = $db->table('employees')->where('client_id', $clientId)->get()->getResultArray();
                $employeeIds = array_column($employees, 'id');
                
                if (!empty($employeeIds)) {
                    // Delete existing imported overtime logs for this client and period
                    // Only delete records that were clearly from a previous Excel import
                    $db->table('overtime_logs')
                        ->whereIn('employee_id', $employeeIds)
                        ->where('payout_period', $payoutPeriodStr)
                        ->like('description', 'Imported from Excel')
                        ->delete();
                }
            }
        }

        $successCount = 0;
        $errorLogs = [];

        foreach ($logs as $index => $row) {
            $nik = trim($row['nik'] ?? '');
            $nama = trim($row['nama'] ?? '');
            $tanggal = trim($row['tanggal'] ?? '');
            $nominal = floatval($row['nominal'] ?? 0);

            if (empty($tanggal) || $nominal <= 0 || (empty($nik) && empty($nama))) {
                $errorLogs[] = "Baris " . ($index + 1) . ": Incomplete data atau nominal <= 0.";
                continue;
            }

            // 1. Lookup Employee
            $employee = null;
            if (!empty($nik)) {
                $employee = $db->table('employees')->where('nik', $nik)->get()->getRowArray();
            }
            if (!$employee && !empty($nama)) {
                $employee = $db->table('employees')->where('LOWER(nama)', strtolower($nama))->get()->getRowArray();
            }
            if (!$employee && !empty($nama)) {
                $employee = $db->table('employees')->like('nama', $nama)->get()->getRowArray();
            }

            if (!$employee) {
                $errorLogs[] = "Baris " . ($index + 1) . ": Karyawan '" . ($nik ?: $nama) . "' tidak ditemukan.";
                continue;
            }

            // Check join date validation
            $joinDate = !empty($employee['tgl_masuk']) ? $employee['tgl_masuk'] : ($employee['start_contract'] ?? null);
            if (!empty($joinDate)) {
                if (strtotime($tanggal) < strtotime($joinDate)) {
                    $errorLogs[] = "Baris " . ($index + 1) . ": Karyawan '" . $employee['nama'] . "' belum bergabung pada tanggal tersebut (Tanggal bergabung: " . date('d-m-Y', strtotime($joinDate)) . ").";
                    continue;
                }
            }

            $empId = $employee['id'];
            $clientId = $employee['client_id'];

            // 2. Resolve Base Salary
            $baseSalary = floatval($employee['gaji_pokok'] ?? 0);
            $activeContract = $db->table('contracts')
                ->where('employee_id', $empId)
                ->where('status_pkwt', 'Active')
                ->orderBy('tgl_mulai', 'DESC')
                ->get()->getRow();
            if ($activeContract && floatval($activeContract->gaji_pokok) > 0) {
                $baseSalary = floatval($activeContract->gaji_pokok);
            } else {
                $payrollConfig = $db->table('client_payroll_configs')->where('client_id', $clientId)->get()->getRow();
                if ($payrollConfig) {
                    if ($payrollConfig->payroll_type === 'UMP' || $payrollConfig->payroll_type === 'UMK') {
                        if ($payrollConfig->minimum_wage_nominal > 0) {
                            $baseSalary = floatval($payrollConfig->minimum_wage_nominal);
                        }
                    } elseif ($payrollConfig->payroll_type === 'Nominal') {
                        if ($payrollConfig->custom_nominal > 0) {
                            $baseSalary = floatval($payrollConfig->custom_nominal);
                        }
                    } elseif (!empty($payrollConfig->payroll_scheme_id)) {
                        $schemeComp = $db->table('payroll_components')
                            ->where('scheme_id', $payrollConfig->payroll_scheme_id)
                            ->groupStart()
                                ->where('jenis_component', 'basic_salary')
                                ->orLike('nama', 'Base Salary')
                            ->groupEnd()
                            ->get()->getRow();
                        if ($schemeComp && floatval($schemeComp->value) > 0) {
                            $baseSalary = floatval($schemeComp->value);
                        }
                    }
                }
            }

            // 3. Resolve PKWT components
            $pkwt = $db->table('pkwt')
                ->where('client_id', $clientId)
                ->where('employee_name', $employee['nama'])
                ->where('status', 'Active')
                ->get()->getRow();

            $empComponents = [];
            if ($pkwt) {
                $dbComponents = $db->table('pkwt_components')
                    ->where('pkwt_id', $pkwt->id)
                    ->get()->getResultArray();
                foreach ($dbComponents as $comp) {
                    $isBasic = (isset($comp['jenis_component']) && $comp['jenis_component'] === 'basic_salary') || (stripos($comp['nama'], 'Base Salary') !== false);
                    if (!$isBasic) {
                        $empComponents[] = [
                            'nama_component' => $comp['nama'],
                            'value' => floatval($comp['value']),
                            'sumber_value' => $comp['sumber_value'] ?? '',
                        ];
                    }
                }
            }

            // Resolve UMP/UMK values for UMP/UMK fallback in components
            $empMinimumWage = 0.0;
            if (!empty($employee['minimum_wage_id'])) {
                $mw = $db->table('minimum_wages')->where('id', $employee['minimum_wage_id'])->get()->getRow();
                if ($mw) {
                    $empMinimumWage = floatval($mw->nominal);
                }
            }
            if ($empMinimumWage <= 0) {
                $searchCity = $employee['kota_kabupaten'] ?? '';
                $searchProv = $employee['provinsi'] ?? '';
                if (!empty($searchCity)) {
                    $cleanCity = trim(str_ireplace(['KOTA ADM.', 'KOTA', 'KABUPATEN', 'KAB.'], '', $searchCity));
                    $mw = $db->table('minimum_wages')
                        ->groupStart()
                            ->where('nama_daerah', $searchCity)
                            ->orWhere('nama_daerah LIKE', '%' . $cleanCity . '%')
                        ->groupEnd()
                        ->orderBy('tahun', 'DESC')
                        ->get()->getRow();
                }
                if (empty($mw) && !empty($searchProv)) {
                    $cleanProv = trim(str_ireplace(['PROVINSI', 'DKI'], '', $searchProv));
                    $mw = $db->table('minimum_wages')
                        ->groupStart()
                            ->where('nama_daerah', $searchProv)
                            ->orWhere('provinsi', $searchProv)
                            ->orWhere('nama_daerah LIKE', '%' . $cleanProv . '%')
                        ->groupEnd()
                        ->orderBy('tahun', 'DESC')
                        ->get()->getRow();
                }
                if (!empty($mw)) {
                    $empMinimumWage = floatval($mw->nominal);
                }
            }
            if ($baseSalary <= 0 && $empMinimumWage > 0) {
                $baseSalary = $empMinimumWage;
            }
            $umpWageValue = $empMinimumWage;
            $umkWageValue = $empMinimumWage;

            // 4. Resolve Nominal Overtime Monthan
            $nominalOvertimeMonthan = 0.0;
            foreach ($empComponents as $comp) {
                $compName = $comp['nama_component'] ?? '';
                if (stripos($compName, 'Overtime') !== false || stripos($compName, 'Overtime') !== false) {
                    $baseVal = floatval($comp['value']);
                    $sumberVal = $comp['sumber_value'] ?? 'nominal';
                    if ($sumberVal === 'ump') {
                        $baseVal = $umpWageValue * ($baseVal / 100);
                    } else if ($sumberVal === 'umk') {
                        $baseVal = $umkWageValue * ($baseVal / 100);
                    } else if ($sumberVal === 'ump_umk') {
                        $baseVal = $empMinimumWage * ($baseVal / 100);
                    }
                    $nominalOvertimeMonthan = $baseVal;
                    break;
                }
            }
            if ($nominalOvertimeMonthan <= 0.0) {
                $nominalOvertimeMonthan = $baseSalary;
            }

            // 5. Resolve hourly rate
            $workDaysConfig = isset($employee['hari_kerja']) ? intval($employee['hari_kerja']) : 5;
            $standardHours = ($workDaysConfig === 6) ? 48.0 : 40.0;
            $upahPerJam = $nominalOvertimeMonthan / $standardHours;

            $description = trim($row['description'] ?? '');
            $jamOvertime = 0;

            if ($upahPerJam > 0) {
                // Normal path: reverse-calculate hours from nominal and hourly rate
                $jamOvertime = round($nominal / $upahPerJam, 1);
            } else {
                // Fallback: try to extract hours from description (e.g., "Overtime 2 jam", "3 hours", "5 jam")
                if (!empty($description) && preg_match('/(\d+(?:[.,]\d+)?)\s*(?:jam|hour|hours|hr|hrs)/i', $description, $matches)) {
                    $jamOvertime = floatval(str_replace(',', '.', $matches[1]));
                }

                // If still no hours found, try extracting any number from description
                if ($jamOvertime <= 0 && !empty($description) && preg_match('/(\d+(?:[.,]\d+)?)/', $description, $matches)) {
                    $jamOvertime = floatval(str_replace(',', '.', $matches[1]));
                }

                // Last resort: use nominal directly as hours (assume nominal = hours if small number)
                if ($jamOvertime <= 0) {
                    if ($nominal <= 24) {
                        // If nominal is small enough to be hours directly
                        $jamOvertime = $nominal;
                    } else {
                        // Skip this row - can't determine hours
                        $errorLogs[] = "Row " . ($index + 1) . ": Cannot calculate overtime hours for '" . $employee['nama'] . "' (no salary config and no hours in description).";
                        continue;
                    }
                }
            }

            if (empty($description)) {
                $description = 'Imported from Excel';
            }

            // 6. Detect holiday
            $isHoliday = 0;
            $holiday = $db->table('holiday_calendar')->where('tanggal', $tanggal)->get()->getRow();
            if ($holiday) {
                $isHoliday = 1;
            } else {
                $dayOfWeek = date('w', strtotime($tanggal));
                if ($dayOfWeek == 0) {
                    $isHoliday = 1;
                } elseif ($dayOfWeek == 6) {
                    $isHoliday = ($workDaysConfig < 6) ? 1 : 0;
                }
            }

            // Cap regular working day overtime to 3 hours
            if (!$isHoliday && $jamOvertime > 3.0) {
                $jamOvertime = 3.0;
            }

            // 7. Detect rapel status based on client's cut-off
            $cutoffStart = 21;
            $cutoffEnd = 20;
            $clientConfig = $this->resolveClientConfigForEmployee($empId);
            if ($clientConfig) {
                if (isset($clientConfig->cutoff_start) && $clientConfig->cutoff_start !== null && $clientConfig->cutoff_start !== '') {
                    $cutoffStart = intval($clientConfig->cutoff_start);
                }
                if (isset($clientConfig->cutoff_end) && $clientConfig->cutoff_end !== null && $clientConfig->cutoff_end !== '') {
                    $cutoffEnd = intval($clientConfig->cutoff_end);
                } else {
                    $cutoffEnd = $cutoffStart - 1;
                    if ($cutoffEnd < 1) {
                        $cutoffEnd = 31;
                    }
                }
            }

            $ts = strtotime($tanggal);
            $tYear = intval(date('Y', $ts));
            $tMonth = intval(date('n', $ts));
            $tDay = intval(date('j', $ts));

            if ($tDay > $cutoffEnd) {
                $natMonth = $tMonth + 1;
                $natYear = $tYear;
                if ($natMonth > 12) {
                    $natMonth = 1;
                    $natYear++;
                }
            } else {
                $natMonth = $tMonth;
                $natYear = $tYear;
            }
            $naturalPeriod = $natMonth . '-' . $natYear;

            $isRapel = 0;
            $finalPayoutPeriod = $naturalPeriod;

            if (!empty($payoutPeriodStr)) {
                $naturalVal = $natYear * 12 + $natMonth;
                
                $payoutMonth = null;
                $payoutYear = null;
                $parts = explode('-', $payoutPeriodStr);
                if (count($parts) === 2) {
                    $payoutMonth = intval($parts[0]);
                    $payoutYear = intval($parts[1]);
                }
                
                if ($payoutMonth && $payoutYear) {
                    $payoutVal = $payoutYear * 12 + $payoutMonth;
                    if ($naturalVal > $payoutVal) {
                        // Future natural period relative to upload period
                        $isRapel = 0;
                        $finalPayoutPeriod = $naturalPeriod;
                    } elseif ($naturalVal < $payoutVal) {
                        // Past natural period (rapel)
                        $isRapel = 1;
                        $finalPayoutPeriod = $payoutPeriodStr;
                    } else {
                        $isRapel = 0;
                        $finalPayoutPeriod = $naturalPeriod;
                    }
                }
            }

            // 8. Insert or Update Log
            $existing = $db->table('overtime_logs')
                ->where('employee_id', $empId)
                ->where('tanggal', $tanggal)
                ->get()->getRow();

            $approvedBy = $this->getAuthUsername();

            $logData = [
                'employee_id'   => $empId,
                'tanggal'       => $tanggal,
                'jam_overtime'    => $jamOvertime,
                'is_holiday'    => $isHoliday,
                'description'    => $description,
                'status'        => 'Pending',
                'approved_by'   => null,
                'approved_at'   => null,
                'is_rapel'      => $isRapel,
                'payout_period' => $finalPayoutPeriod
            ];

            if ($existing) {
                $db->table('overtime_logs')->where('id', $existing->id)->update($logData);
            } else {
                $db->table('overtime_logs')->insert($logData);
            }
            $this->syncPayrollAttendanceOvertime($empId, $tanggal);

            $successCount++;
        }

        return $this->respond([
            'success' => true,
            'imported_count' => $successCount,
            'errors' => $errorLogs
        ]);
    }

    public function updateOvertimeLog($id)
    {
        $data = $this->request->getJSON(true);
        $jamOvertime = floatval($data['jam_overtime'] ?? 0);
        $isHoliday = intval($data['is_holiday'] ?? 0);

        if (!$isHoliday && $jamOvertime > 3) {
            return $this->failValidationErrors('Workday overtime is maximum 3 hours per day!');
        }

        $updateData = [
            'jam_overtime' => $jamOvertime,
            'is_holiday' => $isHoliday,
            'description' => $data['description'] ?? null,
            'status' => 'Pending',
            'approved_by' => null,
            'approved_at' => null
        ];

        $this->db->table('overtime_logs')->where('id', $id)->update($updateData);
        $this->syncPayrollAttendanceOvertimeForLog($id);
        return $this->respond(['message' => 'Overtime log updated successfully']);
    }

    public function deleteOvertimeLog($id)
    {
        if ($deny = $this->guardEntity('overtime_logs', $id)) return $deny;
        $log = $this->db->table('overtime_logs')->where('id', intval($id))->get()->getRow();
        if ($log) {
            $employeeId = $log->employee_id;
            $tanggal = $log->tanggal;
            $this->db->table('overtime_logs')->where('id', $id)->delete();
            $this->syncPayrollAttendanceOvertime($employeeId, $tanggal);
        } else {
            $this->db->table('overtime_logs')->where('id', $id)->delete();
        }
        return $this->respondDeleted(['message' => 'Overtime log deleted successfully']);
    }

    public function approveOvertimeLog($id)
    {
        if ($deny = $this->guardEntity('overtime_logs', $id)) return $deny;
        $approvedBy = $this->getAuthUsername();
        $this->db->table('overtime_logs')->where('id', $id)->update([
            'status' => 'Approved',
            'approved_by' => $approvedBy,
            'approved_at' => date('Y-m-d H:i:s')
        ]);
        $this->syncPayrollAttendanceOvertimeForLog($id);
        return $this->respond(['message' => 'Overtime log approved successfully.']);
    }

    public function rejectOvertimeLog($id)
    {
        if ($deny = $this->guardEntity('overtime_logs', $id)) return $deny;
        $approvedBy = $this->getAuthUsername();
        $this->db->table('overtime_logs')->where('id', $id)->update([
            'status' => 'Rejected',
            'approved_by' => $approvedBy,
            'approved_at' => date('Y-m-d H:i:s')
        ]);
        $this->syncPayrollAttendanceOvertimeForLog($id);
        return $this->respond(['message' => 'Overtime log rejected successfully.']);
    }

    public function resetOvertimeLog($id)
    {
        if ($deny = $this->guardEntity('overtime_logs', $id)) return $deny;
        $this->db->table('overtime_logs')->where('id', $id)->update([
            'status' => 'Pending',
            'approved_by' => null,
            'approved_at' => null
        ]);
        $this->syncPayrollAttendanceOvertimeForLog($id);
        return $this->respond(['message' => 'Overtime log reset to pending successfully.']);
    }

    public function bulkApproveOvertimeLogs()
    {
        $json = $this->request->getJSON(true);
        $ids = $json['ids'] ?? [];
        if (empty($ids)) {
            return $this->failValidationErrors('Tidak ada ID overtime yang dipilih.');
        }
        if ($deny = $this->guardEntityIds('overtime_logs', $ids)) return $deny;
        $approvedBy = $this->getAuthUsername();
        
        $logs = $this->db->table('overtime_logs')->whereIn('id', $ids)->get()->getResult();
        
        $this->db->table('overtime_logs')->whereIn('id', $ids)->update([
            'status' => 'Approved',
            'approved_by' => $approvedBy,
            'approved_at' => date('Y-m-d H:i:s')
        ]);

        foreach ($logs as $log) {
            $this->syncPayrollAttendanceOvertime($log->employee_id, $log->tanggal);
        }
        
        return $this->respond(['message' => count($ids) . ' data overtime successfully disetujui.']);
    }

    public function bulkRejectOvertimeLogs()
    {
        $json = $this->request->getJSON(true);
        $ids = $json['ids'] ?? [];
        if (empty($ids)) {
            return $this->failValidationErrors('Tidak ada ID overtime yang dipilih.');
        }
        if ($deny = $this->guardEntityIds('overtime_logs', $ids)) return $deny;
        $approvedBy = $this->getAuthUsername();
        
        $logs = $this->db->table('overtime_logs')->whereIn('id', $ids)->get()->getResult();
        
        $this->db->table('overtime_logs')->whereIn('id', $ids)->update([
            'status' => 'Rejected',
            'approved_by' => $approvedBy,
            'approved_at' => date('Y-m-d H:i:s')
        ]);

        foreach ($logs as $log) {
            $this->syncPayrollAttendanceOvertime($log->employee_id, $log->tanggal);
        }
        
        return $this->respond(['message' => count($ids) . ' data overtime successfully ditolak.']);
    }

    // --- EARLY ARRIVAL LOGS ---
    public function getEarlyArrivalLogs()
    {
        $employeeId = $this->request->getGet('employee_id');
        $clientId = $this->request->getGet('client_id');
        $departmentId = $this->request->getGet('department_id');
        $status = $this->request->getGet('status');
        $month = $this->request->getGet('month');
        $year = $this->request->getGet('year');
        if ($deny = $this->guardClientParam($clientId)) return $deny;
        if ($employeeId && ($deny = $this->guardEntity('employees', $employeeId))) return $deny;

        $builder = $this->db->table('early_arrival');
        $builder->select('early_arrival.*, employees.nama as employee_name, employees.nik as employee_nik, shift_schemes.name as shift_name');
        $builder->join('employees', 'employees.id = early_arrival.employee_id', 'left');
        $builder->join('shift_schemes', 'shift_schemes.id = early_arrival.shift_id', 'left');

        if ($employeeId) {
            $builder->where('early_arrival.employee_id', intval($employeeId));
        }
        if ($clientId) {
            $builder->where('employees.client_id', intval($clientId));
        } else {
            $this->scopeToAllowedClients($builder, 'employees.client_id');
        }
        if ($departmentId) {
            $builder->where('employees.department_id', intval($departmentId));
        }
        if ($status) {
            $builder->where('early_arrival.status', $status);
        }
        if ($month && $year) {
            $builder->where('MONTH(early_arrival.date)', intval($month));
            $builder->where('YEAR(early_arrival.date)', intval($year));
        }

        $builder->orderBy('early_arrival.date', 'ASC');
        $logs = $builder->get()->getResultArray();
        return $this->respond($logs);
    }

    public function approveEarlyArrivalLog($id)
    {
        if ($deny = $this->guardEntity('early_arrival', $id)) return $deny;
        $approvedBy = $this->getAuthUsername();
        $this->db->table('early_arrival')->where('id', $id)->update([
            'status' => 'APPROVED',
            'approved_by' => $approvedBy,
            'approved_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        return $this->respond(['message' => 'Early arrival log approved successfully.']);
    }

    public function rejectEarlyArrivalLog($id)
    {
        if ($deny = $this->guardEntity('early_arrival', $id)) return $deny;
        $approvedBy = $this->getAuthUsername();
        $this->db->table('early_arrival')->where('id', $id)->update([
            'status' => 'REJECTED',
            'approved_by' => $approvedBy,
            'approved_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        return $this->respond(['message' => 'Early arrival log rejected successfully.']);
    }

    public function bulkApproveEarlyArrivalLogs()
    {
        $json = $this->request->getJSON(true);
        $ids = $json['ids'] ?? [];
        if (empty($ids)) {
            return $this->failValidationErrors('Tidak ada ID early arrival yang dipilih.');
        }
        if ($deny = $this->guardEntityIds('early_arrival', $ids)) return $deny;
        $approvedBy = $this->getAuthUsername();
        
        $this->db->table('early_arrival')->whereIn('id', $ids)->update([
            'status' => 'APPROVED',
            'approved_by' => $approvedBy,
            'approved_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        return $this->respond(['message' => count($ids) . ' data early arrival successfully disetujui.']);
    }

    public function bulkRejectEarlyArrivalLogs()
    {
        $json = $this->request->getJSON(true);
        $ids = $json['ids'] ?? [];
        if (empty($ids)) {
            return $this->failValidationErrors('Tidak ada ID early arrival yang dipilih.');
        }
        if ($deny = $this->guardEntityIds('early_arrival', $ids)) return $deny;
        $approvedBy = $this->getAuthUsername();
        
        $this->db->table('early_arrival')->whereIn('id', $ids)->update([
            'status' => 'REJECTED',
            'approved_by' => $approvedBy,
            'approved_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        
        return $this->respond(['message' => count($ids) . ' data early arrival successfully ditolak.']);
    }

    public function resetEarlyArrivalLog($id)
    {
        if ($deny = $this->guardEntity('early_arrival', $id)) return $deny;
        $this->db->table('early_arrival')->where('id', $id)->update([
            'status' => 'PENDING',
            'approved_by' => null,
            'approved_at' => null,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        return $this->respond(['message' => 'Early arrival log reset to pending successfully.']);
    }

    public function importEarlyArrivalLogs()
    {
        $db = \Config\Database::connect();
        $json = $this->request->getJSON(true);
        $logs = $json['logs'] ?? [];
        $payoutPeriodStr = $json['payout_period'] ?? null;
        $clientId = $json['client_id'] ?? null;
        
        if (empty($logs)) {
            return $this->failValidationErrors('No data available Early Arrival yang diunggah.');
        }

        $successCount = 0;
        $errorLogs = [];

        foreach ($logs as $index => $row) {
            $nik = trim($row['nik'] ?? '');
            $nama = trim($row['nama'] ?? '');
            $tanggal = trim($row['tanggal'] ?? '');
            $shiftStart = trim($row['shift_start_time'] ?? '08:00');
            $checkIn = trim($row['check_in_time'] ?? '');
            $earlyMinutes = intval($row['early_minutes'] ?? 0);
            $description = trim($row['description'] ?? 'Imported from Excel');

            if (empty($tanggal) || (empty($nik) && empty($nama))) {
                $errorLogs[] = "Baris " . ($index + 1) . ": Incomplete data.";
                continue;
            }

            // 1. Lookup Employee
            $employee = null;
            if (!empty($nik)) {
                $builder = $db->table('employees')->where('nik', $nik);
                if ($clientId) $builder->where('client_id', intval($clientId));
                $employee = $builder->get()->getRowArray();
            }
            if (!$employee && !empty($nama)) {
                $builder = $db->table('employees')->where('LOWER(nama)', strtolower($nama));
                if ($clientId) $builder->where('client_id', intval($clientId));
                $employee = $builder->get()->getRowArray();
            }
            if (!$employee && !empty($nama)) {
                $builder = $db->table('employees')->like('nama', $nama);
                if ($clientId) $builder->where('client_id', intval($clientId));
                $employee = $builder->get()->getRowArray();
            }

            if (!$employee) {
                $errorLogs[] = "Baris " . ($index + 1) . ": Karyawan '" . ($nik ?: $nama) . "' tidak ditemukan.";
                continue;
            }

            // Calculate minutes if not directly provided
            if ($earlyMinutes <= 0 && !empty($checkIn) && !empty($shiftStart)) {
                $checkInTs = strtotime($checkIn);
                $shiftStartTs = strtotime($shiftStart);
                if ($checkInTs && $shiftStartTs && $checkInTs < $shiftStartTs) {
                    $earlyMinutes = intval(($shiftStartTs - $checkInTs) / 60);
                }
            }

            if ($earlyMinutes <= 0) {
                $earlyMinutes = 30; // Default sensible early arrival
            }

            $eligibleMinutes = $earlyMinutes;

            // Check existing record
            $existing = $db->table('early_arrival')
                ->where('employee_id', intval($employee['id']))
                ->where('date', $tanggal)
                ->get()->getRowArray();

            if ($existing) {
                $db->table('early_arrival')->where('id', $existing['id'])->update([
                    'shift_start_time' => $shiftStart,
                    'check_in_time'    => $checkIn ?: $shiftStart,
                    'early_minutes'    => $earlyMinutes,
                    'eligible_minutes' => $eligibleMinutes,
                    'status'           => 'PENDING',
                    'updated_at'       => date('Y-m-d H:i:s')
                ]);
            } else {
                $db->table('early_arrival')->insert([
                    'attendance_id'    => 0,
                    'employee_id'      => intval($employee['id']),
                    'date'             => $tanggal,
                    'shift_id'         => null,
                    'shift_start_time' => $shiftStart,
                    'check_in_time'    => $checkIn ?: $shiftStart,
                    'early_minutes'    => $earlyMinutes,
                    'eligible_minutes' => $eligibleMinutes,
                    'status'           => 'PENDING',
                    'created_at'       => date('Y-m-d H:i:s'),
                    'updated_at'       => date('Y-m-d H:i:s')
                ]);
            }
            $successCount++;
        }

        return $this->respond([
            'success' => true,
            'imported_count' => $successCount,
            'errors' => $errorLogs,
            'message' => "Successfully mengimpor $successCount log Early Arrival."
        ]);
    }

    private function syncEarlyArrival($attendanceId, $employeeId, $tanggal, $checkIn, $shiftSchemeId = null, $payoutPeriod = null)
    {
        $db = \Config\Database::connect();

        // 1. Resolve employee's payroll scheme and get max early arrival limit
        $clientConfig = $this->resolveClientConfigForEmployee($employeeId);
        $maxMinutes = 180; // Default fallback
        if ($clientConfig && !empty($clientConfig->payroll_scheme_id)) {
            $payrollScheme = $db->table('payroll_schemes')->where('id', $clientConfig->payroll_scheme_id)->get()->getRow();
            if ($payrollScheme && isset($payrollScheme->max_early_arrival_minutes)) {
                $maxMinutes = intval($payrollScheme->max_early_arrival_minutes);
            }
        }

        // If early arrival is disabled (maxMinutes <= 0), delete any existing early arrival log for this attendance
        if ($maxMinutes <= 0) {
            $db->table('early_arrival')->where('attendance_id', $attendanceId)->delete();
            return;
        }

        // 2. Resolve shift scheme and get shift start time
        if (!$shiftSchemeId) {
            $att = $db->table('attendance_logs')->where('id', $attendanceId)->get()->getRow();
            if ($att) {
                $shiftSchemeId = $att->shift_scheme_id;
            }
        }

        if (!$shiftSchemeId) {
            $db->table('early_arrival')->where('attendance_id', $attendanceId)->delete();
            return;
        }

        $shift = $db->table('shift_schemes')->where('id', $shiftSchemeId)->get()->getRow();
        if (!$shift || empty($shift->start_time) || empty($checkIn)) {
            $db->table('early_arrival')->where('attendance_id', $attendanceId)->delete();
            return;
        }

        // 3. Compare check-in and shift start
        $shiftStart = $shift->start_time; // e.g. "08:00"
        
        $shiftStartSecs = strtotime($tanggal . ' ' . $shiftStart);
        $checkInSecs = strtotime($tanggal . ' ' . $checkIn);

        if ($checkInSecs === false || $shiftStartSecs === false) {
            $db->table('early_arrival')->where('attendance_id', $attendanceId)->delete();
            return;
        }

        // Check if Check-in < Shift Start
        if ($checkInSecs < $shiftStartSecs) {
            $earlyMinutes = intval(($shiftStartSecs - $checkInSecs) / 60);
            
            $eligibleMinutes = $earlyMinutes;
            if ($eligibleMinutes > $maxMinutes) {
                $eligibleMinutes = $maxMinutes;
            }

            $existing = $db->table('early_arrival')->where('attendance_id', $attendanceId)->get()->getRow();

            $data = [
                'attendance_id' => $attendanceId,
                'employee_id' => $employeeId,
                'date' => $tanggal,
                'shift_id' => $shiftSchemeId,
                'shift_start_time' => $shiftStart,
                'check_in_time' => $checkIn,
                'early_minutes' => $earlyMinutes,
                'eligible_minutes' => $eligibleMinutes,
                'updated_at' => date('Y-m-d H:i:s')
            ];

            if ($existing) {
                if ($existing->payroll_status !== 'PROCESSED') {
                    if ($existing->check_in_time !== $checkIn || $existing->early_minutes !== $earlyMinutes) {
                        $data['status'] = 'PENDING';
                    }
                    $db->table('early_arrival')->where('id', $existing->id)->update($data);
                }
            } else {
                $data['status'] = 'PENDING';
                $data['payroll_status'] = 'NOT_PROCESSED';
                $data['payroll_period'] = $payoutPeriod;
                $data['created_at'] = date('Y-m-d H:i:s');
                $db->table('early_arrival')->insert($data);
            }
        } else {
            $db->table('early_arrival')->where('attendance_id', $attendanceId)->delete();
        }
    }

    // --- HOLIDAY CALENDAR ---
    public function getHolidays()
    {
        $year = $this->request->getGet('year');
        $builder = $this->db->table('holiday_calendar');
        if ($year) {
            $builder->groupStart()
                ->where('tahun', intval($year))
                ->orWhere('YEAR(tanggal)', intval($year))
            ->groupEnd();
        }
        $builder->orderBy('tanggal', 'ASC');
        $holidays = $builder->get()->getResultArray();
        return $this->respond($holidays);
    }

    public function createHoliday()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['tanggal']) || empty($data['deskripsi'])) {
            return $this->failValidationErrors('Date and description are required');
        }

        // Check duplicate
        $existing = $this->db->table('holiday_calendar')
            ->where('tanggal', $data['tanggal'])
            ->get()->getRow();
        if ($existing) {
            return $this->failValidationErrors('Holiday date is already registered!');
        }

        $year = intval(date('Y', strtotime($data['tanggal'])));
        $insertData = [
            'tanggal' => $data['tanggal'],
            'deskripsi' => $data['deskripsi'],
            'tahun' => $year,
        ];

        $this->db->table('holiday_calendar')->insert($insertData);
        $this->logActivity("Added holiday: " . $data['deskripsi'] . " (" . $data['tanggal'] . ")");
        return $this->respondCreated(['message' => 'Holiday added successfully']);
    }

    public function syncGoogleHolidays()
    {
        $url = 'https://calendar.google.com/calendar/ical/id.indonesian%23holiday%40group.v.calendar.google.com/public/basic.ics';
        
        // Fetch ICS using cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $icsContent = curl_exec($ch);
        curl_close($ch);

        if (!$icsContent) {
            return $this->fail('Failed to download data dari Google Calendar.');
        }

        // Parse ICS file
        $events = [];
        $lines = explode("\n", $icsContent);
        $currentEvent = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            if ($line === 'BEGIN:VEVENT') {
                $currentEvent = [];
            } elseif ($line === 'END:VEVENT') {
                if ($currentEvent && !empty($currentEvent['start']) && !empty($currentEvent['summary'])) {
                    $events[] = $currentEvent;
                }
                $currentEvent = null;
            } elseif ($currentEvent !== null) {
                if (strpos($line, ':') !== false) {
                    list($key, $val) = explode(':', $line, 2);
                    $keyClean = explode(';', $key)[0]; // strip parameters like VALUE=DATE

                    if ($keyClean === 'DTSTART') {
                        $dateStr = trim($val);
                        if (strlen($dateStr) >= 8) {
                            $yyyy = substr($dateStr, 0, 4);
                            $mm = substr($dateStr, 4, 2);
                            $dd = substr($dateStr, 6, 2);
                            $currentEvent['start'] = "$yyyy-$mm-$dd";
                        }
                    } elseif ($keyClean === 'SUMMARY') {
                        $currentEvent['summary'] = str_replace(['\\,', '\\;'], [',', ';'], trim($val));
                    }
                }
            }
        }

        if (empty($events)) {
            return $this->fail('Tidak ada holiday yang ditemukan dalam Google Calendar.');
        }

        $insertedCount = 0;
        $updatedCount = 0;

        foreach ($events as $evt) {
            $tanggal = $evt['start'];
            $deskripsi = $evt['summary'];
            $year = intval(date('Y', strtotime($tanggal)));

            // Check if already exists
            $existing = $this->db->table('holiday_calendar')
                ->where('tanggal', $tanggal)
                ->get()->getRow();

            if ($existing) {
                if ($existing->deskripsi !== $deskripsi) {
                    $this->db->table('holiday_calendar')
                        ->where('id', $existing->id)
                        ->update(['deskripsi' => $deskripsi]);
                    $updatedCount++;
                }
            } else {
                $this->db->table('holiday_calendar')->insert([
                    'tanggal' => $tanggal,
                    'deskripsi' => $deskripsi,
                    'tahun' => $year
                ]);
                $insertedCount++;
            }
        }

        $this->logActivity("Google Calendar holiday sync: Successfully added {$insertedCount} and updated {$updatedCount} holidays.");

        return $this->respond([
            'message' => "Sync successful! Added {$insertedCount} new holidays, updated {$updatedCount} holidays."
        ]);
    }

    public function updateHoliday($id)
    {
        $data = $this->request->getJSON(true);
        $updateData = [
            'tanggal' => $data['tanggal'] ?? null,
            'deskripsi' => $data['deskripsi'] ?? null,
        ];
        if (!empty($updateData['tanggal'])) {
            $updateData['tahun'] = intval(date('Y', strtotime($updateData['tanggal'])));
        }

        $this->db->table('holiday_calendar')->where('id', $id)->update($updateData);
        return $this->respond(['message' => 'Holiday updated successfully']);
    }

    public function deleteHoliday($id)
    {
        $holiday = $this->db->table('holiday_calendar')->where('id', $id)->get()->getRow();
        $desc = $holiday ? $holiday->deskripsi : 'Unknown';
        $this->db->table('holiday_calendar')->where('id', $id)->delete();
        $this->logActivity("Deleted holiday: " . $desc);
        return $this->respondDeleted(['message' => 'Holiday deleted successfully']);
    }

    public function createCompensationComponent()
    {
        $data = $this->request->getJSON(true);
        $this->db->table('compensation_components')->insert($data);
        return $this->respondCreated(['message' => 'Component kompensasi added successfully']);
    }

    public function updateCompensationComponent($id)
    {
        $data = $this->request->getJSON(true);
        $this->db->table('compensation_components')->where('id', $id)->update($data);
        return $this->respond(['message' => 'Component kompensasi updated successfully']);
    }

    public function deleteCompensationComponent($id)
    {
        $this->db->table('compensation_components')->where('id', $id)->delete();
        return $this->respondDeleted(['message' => 'Component kompensasi deleted successfully']);
    }

    // --- CLIENT PAYROLL CONFIGS ---
    public function getClientConfigs()
    {
        $configs = $this->db->table('clients')
                            ->select('clients.id as client_id, clients.nama as client_name, client_payroll_configs.id as setup_id, payroll_schemes.nama as payroll_scheme_name, tax_schemes.nama as tax_scheme_name, bpjs_schemes.nama as bpjs_scheme_name, compensation_schemes.nama as compensation_scheme_name, client_payroll_configs.pay_date, client_payroll_configs.cutoff_start, client_payroll_configs.cutoff_end, client_payroll_configs.payroll_scheme_id, client_payroll_configs.tax_scheme_id, client_payroll_configs.bpjs_scheme_id, client_payroll_configs.compensation_scheme_id, client_payroll_configs.payroll_type, client_payroll_configs.minimum_wage_id, client_payroll_configs.custom_nominal, minimum_wages.nama_daerah as minimum_wage_region, minimum_wages.nominal as minimum_wage_nominal, client_payroll_configs.division_id, client_payroll_configs.department_id, client_payroll_configs.position_id, divisions.nama as division_name, departments.nama as department_name, positions.nama as position_name')
                            ->join('client_payroll_configs', 'client_payroll_configs.client_id = clients.id', 'left')
                            ->join('payroll_schemes', 'payroll_schemes.id = client_payroll_configs.payroll_scheme_id', 'left')
                            ->join('tax_schemes', 'tax_schemes.id = client_payroll_configs.tax_scheme_id', 'left')
                            ->join('tax_schemes as bpjs_schemes', 'bpjs_schemes.id = client_payroll_configs.bpjs_scheme_id', 'left')
                            ->join('compensation_schemes', 'compensation_schemes.id = client_payroll_configs.compensation_scheme_id', 'left')
                            ->join('minimum_wages', 'minimum_wages.id = client_payroll_configs.minimum_wage_id', 'left')
                            ->join('divisions', 'divisions.id = client_payroll_configs.division_id', 'left')
                            ->join('departments', 'departments.id = client_payroll_configs.department_id', 'left')
                            ->join('positions', 'positions.id = client_payroll_configs.position_id', 'left');
        $this->scopeToAllowedClients($configs, 'clients.id');
        $configs = $configs->get()->getResult();
        return $this->respond($configs);
    }

    public function saveClientConfig()
    {
        $data = $this->request->getJSON(true);
        $clientId = $data['client_id'];
        
        $divId = $data['division_id'] ?? null;
        $deptId = $data['department_id'] ?? null;
        $posId = $data['position_id'] ?? null;
        
        $divisionId = !empty($data['division_id']) ? intval($data['division_id']) : null;
        $departmentId = !empty($data['department_id']) ? intval($data['department_id']) : null;
        $positionId = !empty($data['position_id']) ? intval($data['position_id']) : null;

        $data['division_id'] = $divisionId;
        $data['department_id'] = $departmentId;
        $data['position_id'] = $positionId;
        
        // Handle hari_kerja for positions
        if ($positionId && isset($data['hari_kerja']) && $data['hari_kerja'] !== '') {
            $this->db->table('positions')->where('id', $positionId)->update(['hari_kerja' => intval($data['hari_kerja'])]);
        }
        unset($data['hari_kerja']);
        
        // Handle multi cut-off dates and legacy fallback
        if (isset($data['cutoff_gaji_pokok_start'])) {
            $data['cutoff_gaji_pokok_start'] = intval($data['cutoff_gaji_pokok_start']);
            $cutoffEnd = $data['cutoff_gaji_pokok_start'] - 1;
            $data['cutoff_gaji_pokok_end'] = $cutoffEnd < 1 ? 0 : $cutoffEnd;
            
            // Sync legacy fields for backward compatibility
            $data['cutoff_start'] = $data['cutoff_gaji_pokok_start'];
            $data['cutoff_end'] = $data['cutoff_gaji_pokok_end'];
        }

        if (isset($data['cutoff_overtime_start'])) {
            $data['cutoff_overtime_start'] = intval($data['cutoff_overtime_start']);
            $cutoffEnd = $data['cutoff_overtime_start'] - 1;
            $data['cutoff_overtime_end'] = $cutoffEnd < 1 ? 0 : $cutoffEnd;
        }

        if (isset($data['cutoff_insentif_start'])) {
            $data['cutoff_insentif_start'] = intval($data['cutoff_insentif_start']);
            $cutoffEnd = $data['cutoff_insentif_start'] - 1;
            $data['cutoff_insentif_end'] = $cutoffEnd < 1 ? 0 : $cutoffEnd;
        }

        // Explicitly cast rapel flags
        if (isset($data['is_rapel_gaji_pokok'])) {
            $data['is_rapel_gaji_pokok'] = $data['is_rapel_gaji_pokok'] ? 1 : 0;
        }
        if (isset($data['is_rapel_overtime'])) {
            $data['is_rapel_overtime'] = $data['is_rapel_overtime'] ? 1 : 0;
        }
        if (isset($data['is_rapel_insentif'])) {
            $data['is_rapel_insentif'] = $data['is_rapel_insentif'] ? 1 : 0;
        }

        
        // Cek jika sudah ada scheme untuk level organisasi ini yang SPESIFIK
        $query = $this->db->table('client_payroll_configs')->where('client_id', $clientId);
        if ($divisionId) $query->where('division_id', $divisionId); else $query->where('division_id IS NULL');
        if ($departmentId) $query->where('department_id', $departmentId); else $query->where('department_id IS NULL');
        if ($positionId) $query->where('position_id', $positionId); else $query->where('position_id IS NULL');
        
        $existing = $query->get()->getRow();
        
        // JIKA SUDAH ADA, LANGSUNG UPDATE (UPSERT)
        $isUpdateAction = isset($data['id']) && $data['id'] > 0;
        
        if ($existing && !$isUpdateAction) {
            $data['id'] = $existing->id;
        }
        
        if (isset($data['id'])) {
            $idToUpdate = $data['id'];
            unset($data['id']); // Mencegah update kolom identity
            $this->db->table('client_payroll_configs')->where('id', $idToUpdate)->update($data);
        } else {
            $this->db->table('client_payroll_configs')->insert($data);
        }

        return $this->respond(['status' => 'success', 'message' => 'Pilihan scheme saved successfully']);
    }

    public function getClientConfigMappings($clientId)
    {
        if (!$this->checkClientAccess((int) $clientId)) return $this->failClientForbidden();
        $configs = $this->db->table('client_payroll_configs')
            ->select('
                client_payroll_configs.*,
                divisions.nama as division_name,
                departments.nama as department_name,
                positions.nama as position_name,
                payroll_schemes.nama as payroll_scheme_name,
                tax_schemes.nama as tax_scheme_name,
                bpjs_schemes.nama as bpjs_scheme_name,
                compensation_schemes.nama as compensation_scheme_name,
                minimum_wages.nama_daerah as minimum_wage_region,
                minimum_wages.nominal as minimum_wage_nominal
            ')
            ->join('divisions', 'divisions.id = client_payroll_configs.division_id', 'left')
            ->join('departments', 'departments.id = client_payroll_configs.department_id', 'left')
            ->join('positions', 'positions.id = client_payroll_configs.position_id', 'left')
            ->join('payroll_schemes', 'payroll_schemes.id = client_payroll_configs.payroll_scheme_id', 'left')
            ->join('tax_schemes', 'tax_schemes.id = client_payroll_configs.tax_scheme_id', 'left')
            ->join('tax_schemes as bpjs_schemes', 'bpjs_schemes.id = client_payroll_configs.bpjs_scheme_id', 'left')
            ->join('compensation_schemes', 'compensation_schemes.id = client_payroll_configs.compensation_scheme_id', 'left')
            ->join('minimum_wages', 'minimum_wages.id = client_payroll_configs.minimum_wage_id', 'left')
            ->where('client_payroll_configs.client_id', $clientId)
            ->get()->getResultArray();
            
        return $this->respond($configs);
    }
    
    public function deleteClientConfig($id)
    {
        if ($deny = $this->guardEntity('client_payroll_configs', $id)) return $deny;
        $this->db->table('client_payroll_configs')->where('id', $id)->delete();
        return $this->respondDeleted(['id' => $id, 'message' => 'Mapping scheme deleted successfully']);
    }

    // --- PKWT ---
    public function getPKWT()
    {
        $clientId = $this->request->getGet('client_id');
        if ($deny = $this->guardClientParam($clientId)) return $deny;
        $query = $this->db->table('pkwt')
                         ->select('pkwt.*, clients.nama as client_name')
                         ->join('clients', 'clients.id = pkwt.client_id');
        if ($clientId) {
            $query->where('pkwt.client_id', $clientId);
        } else {
            $this->scopeToAllowedClients($query, 'pkwt.client_id');
        }
        $data = $query->get()->getResultArray();
        
        foreach ($data as &$row) {
            $row['components'] = $this->db->table('pkwt_components')
                                          ->where('pkwt_id', $row['id'])
                                          ->get()
                                          ->getResultArray();

            // Try to find the employee to resolve UMP/UMK nominal
            $emp = $this->db->table('employees')
                            ->select('employees.*, minimum_wages.nominal as wage_nominal, minimum_wages.tipe as wage_tipe')
                            ->join('minimum_wages', 'minimum_wages.id = employees.minimum_wage_id', 'left')
                            ->where('employees.client_id', $row['client_id'])
                            ->where('employees.nama', $row['employee_name'])
                            ->get()
                            ->getRow();

            foreach ($row['components'] as &$comp) {
                $comp['value_nominal'] = floatval($comp['value'] ?? $comp['nominal'] ?? $comp['value_nominal'] ?? 0);
                $isBasic = (stripos($comp['nama'], 'Base Salary') !== false || ($comp['jenis_component'] ?? '') === 'basic_salary');
                if ($isBasic) {
                    if (isset($comp['sumber_value']) && ($comp['sumber_value'] === 'ump' || $comp['sumber_value'] === 'umk')) {
                        // Resolve proper UMP/UMK values using the enhanced resolver
                        $mwId = ($emp && isset($emp->minimum_wage_id)) ? $emp->minimum_wage_id : null;
                        $empProvince = null;
                        if ($emp && !empty($emp->work_location_id)) {
                            $wl = $this->db->table('work_locations')->where('id', $emp->work_location_id)->get()->getRow();
                            if ($wl && !empty($wl->provinsi)) {
                                $empProvince = $wl->provinsi;
                            }
                        }
                        $resolved = $this->resolveUmpUmk($mwId, null, $empProvince);
                        $wageValue = ($comp['sumber_value'] === 'ump') ? $resolved['ump'] : $resolved['umk'];
                        if ($wageValue > 0) {
                            $comp['value_nominal'] = $wageValue * (floatval($comp['value']) / 100);
                        }
                    }
                }
            }
        }
        
        return $this->respond($data);
    }

    public function createPKWT()
    {
        $data = $this->request->getJSON(true);
        $basicSalary = $data['basic_salary'] ?? 0;
        unset($data['basic_salary']);

        // 1. Insert PKWT
        $this->db->table('pkwt')->insert($data);
        $pkwtId = $this->db->insertID();

        $this->syncPKWTComponents($pkwtId, $basicSalary);

        $this->logActivity("Membuat PKWT baru untuk karyawan: " . ($data['employee_name'] ?? ''));
        return $this->respondCreated(['message' => 'PKWT dibuat dan gaji telah tergenerate']);
    }

    public function deletePKWT($id)
    {
        if ($deny = $this->guardEntity('pkwt', $id)) return $deny;
        $pkwt = $this->db->table('pkwt')->where('id', $id)->get()->getRow();
        $employeeName = $pkwt ? $pkwt->employee_name : 'Unknown';
        $this->db->table('pkwt')->where('id', $id)->delete();
        $this->logActivity("Menghapus PKWT karyawan: " . $employeeName . " (ID: " . $id . ")");
        return $this->respondDeleted(['message' => 'PKWT deleted successfully']);
    }

    public function syncEmployeesPKWTEndpoint()
    {
        $clientId = $this->request->getGet('client_id');
        if (empty($clientId)) {
            return $this->fail('Client ID required');
        }
        if ($deny = $this->guardClientParam($clientId)) return $deny;
        
        $this->syncEmployeesToPKWT($clientId);
        return $this->respond(['message' => 'Employees synchronized to PKWT successfully']);
    }

    /**
     * Fase 3: Validasi akses ke periode payroll + parameter client_id.
     * - client_id (jika dikirim) harus termasuk allowed_client_ids.
     * - Periode milik klien lain ditolak (403).
     * - User non-global wajib mengirim client_id agar data tidak bocor lintas klien.
     */
    private function guardPeriodAccess($periodId, $clientId)
    {
        if ($this->request === null) return null; // dipanggil internal / CLI
        if ($deny = $this->guardClientParam($clientId)) return $deny;
        if ($this->hasGlobalClientAccess()) return null;

        if ($clientId === null || $clientId === '') {
            return $this->failClientForbidden('Parameter client_id wajib diisi untuk akun dengan akses klien terbatas.');
        }

        $period = $this->db->table('payroll_periods')->select('client_id')->where('id', intval($periodId))->get()->getRow();
        if ($period && !empty($period->client_id) && !$this->checkClientAccess((int) $period->client_id)) {
            return $this->failClientForbidden('Akses ditolak. Periode ini bukan milik klien yang Anda kelola.');
        }
        return null;
    }

    // --- PERIODS ---
    public function getPeriods()
    {
        $clientId = $this->request->getGet('client_id');
        if ($deny = $this->guardClientParam($clientId)) return $deny;
        $query = $this->db->table('payroll_periods')->orderBy('year', 'DESC')->orderBy('month', 'DESC');
        if ($clientId) {
            $query->where('client_id', $clientId);
        } elseif (!$this->hasGlobalClientAccess()) {
            $ids = $this->getAllowedClientIdsInt();
            $query->groupStart()->where('client_id', null);
            if (!empty($ids)) $query->orWhereIn('client_id', $ids);
            $query->groupEnd();
        }
        $data = $query->get()->getResult();
        foreach ($data as &$row) {
            $row = $this->formatPeriodRow($row);
        }
        return $this->respond($data);
    }

    public function createPeriod()
    {
        $data = $this->request->getJSON(true);
        $clientId = $data['client_id'] ?? null;
        if ($deny = $this->guardClientParam($clientId)) return $deny;
        if (empty($clientId) && !$this->hasGlobalClientAccess()) {
            return $this->failClientForbidden('Parameter client_id wajib diisi untuk akun dengan akses klien terbatas.');
        }
        $month = intval($data['month']);
        $year = intval($data['year']);

        $exists = $this->db->table('payroll_periods')
                           ->where('client_id', $clientId)
                           ->where('month', $month)
                           ->where('year', $year)
                           ->get()->getRow();

        if ($exists) {
            return $this->respond(['message' => 'Period sudah ada'], 200);
        }
        
        $insertData = [
            'client_id' => $clientId,
            'month' => $month,
            'year' => $year,
            'status_cutoff' => 'Open',
            'pay_date' => null
        ];
        
        $this->db->table('payroll_periods')->insert($insertData);
        return $this->respondCreated(['message' => 'Period baru successfully dibuka']);
    }

    // --- ATTENDANCE / CUT-OFF ---
    public function getAttendance($periodId)
    {
        $clientId = $this->request->getGet('client_id');
        if ($deny = $this->guardPeriodAccess($periodId, $clientId)) return $deny;
        $this->syncEmployeesToPKWT($clientId);
        $this->syncOvertimeToPayrollAttendance($periodId, $clientId);
        $this->syncEarlyArrivalToPayrollAttendance($periodId, $clientId);

        $period = $this->db->table('payroll_periods')->where('id', $periodId)->get()->getRow();
        if (!$period) {
            return $this->respond([]);
        }

        $clientConfig = $this->db->table('client_payroll_configs')->where('client_id', $clientId)->get()->getRow();
        $daysInMonth = date('t', mktime(0, 0, 0, intval($period->month), 1, intval($period->year)));
        $cutoffStart = $clientConfig ? intval($clientConfig->cutoff_start) : 21;
        $cutoffEnd = $clientConfig ? intval($clientConfig->cutoff_end) : 20;
        if ($cutoffStart <= 0) $cutoffStart = 21;
        if ($cutoffEnd <= 0) $cutoffEnd = $daysInMonth;

        $month_start = intval($period->month);
        $year_start = intval($period->year);
        $month_end = $month_start;
        $year_end = $year_start;
        if ($cutoffStart > $cutoffEnd && $cutoffStart > 1) {
            $month_start -= 1;
            if ($month_start < 1) {
                $month_start = 12;
                $year_start -= 1;
            }
        }
        $daysInStartMonth = intval(date('t', mktime(0, 0, 0, $month_start, 1, $year_start)));
        $daysInEndMonth = intval(date('t', mktime(0, 0, 0, $month_end, 1, $year_end)));
        $safeStart = min($cutoffStart, $daysInStartMonth);
        $safeEnd = min($cutoffEnd, $daysInEndMonth);

        $startDateStr = sprintf('%04d-%02d-%02d', $year_start, $month_start, $safeStart);
        $endDateStr = sprintf('%04d-%02d-%02d', $year_end, $month_end, $safeEnd);

        // Pre-fetch holiday_calendar for the period month to compute accurate payable_days
        $periodMonthStart = sprintf('%04d-%02d-01', intval($period->year), intval($period->month));
        $periodMonthEnd = date('Y-m-t', strtotime($periodMonthStart));
        $holidayRows = $this->db->table('holiday_calendar')
                                ->where('tanggal >=', $periodMonthStart)
                                ->where('tanggal <=', $periodMonthEnd)
                                ->get()->getResultArray();
        $holidaysInPeriod = [];
        foreach ($holidayRows as $h) {
            $holidaysInPeriod[$h['tanggal']] = true;
        }

        // Get all PKWT and their attendance for this period
        $query = $this->db->table('pkwt')
                          ->select('pkwt.id as pkwt_id, pkwt.employee_name, pkwt.tipe_perjanjian, 
                                    payroll_attendance.hari_kerja, payroll_attendance.jam_overtime, 
                                    payroll_attendance.jam_overtime_hari_biasa, payroll_attendance.jam_overtime_hari_libur,
                                    payroll_attendance.deduction_absensi, payroll_attendance.bonus_tambahan,
                                    payroll_attendance.early_arrival_minutes,
                                    employees.id as employee_id, employees.employ_id, employees.nik, employees.gaji_pokok,
                                    employees.hari_kerja as employee_hari_kerja, positions.hari_kerja as position_hari_kerja,
                                    employees.tgl_masuk, employees.start_contract')
                          ->join('payroll_attendance', "payroll_attendance.pkwt_id = pkwt.id AND payroll_attendance.period_id = $periodId", 'left')
                          ->join('employees', "employees.client_id = pkwt.client_id AND employees.nama = pkwt.employee_name", 'left')
                          ->join('positions', 'positions.id = employees.position_id', 'left');
        if ($clientId) {
            $query->where('pkwt.client_id', $clientId);
        }
        $pkwts = $query->get()->getResult();

        foreach ($pkwts as $row) {
            $row->rapel_hari_kerja = 0;
            $row->rapel_jam_overtime = 0.0;
            $row->rapel_payout_period = '';

            // Compute Payable Days
            $workDaysConfig = intval($row->employee_hari_kerja ?? 5);
            if ($workDaysConfig <= 0) {
                $workDaysConfig = intval($row->position_hari_kerja ?? 5);
            }
            if ($workDaysConfig <= 0) {
                $workDaysConfig = 5;
            }

            $payableDaysCount = 0;
            $startTs = strtotime($periodMonthStart);
            $endTs = strtotime($periodMonthEnd);
            for ($curr = $startTs; $curr <= $endTs; $curr = strtotime('+1 day', $curr)) {
                $currDate = date('Y-m-d', $curr);
                if (isset($holidaysInPeriod[$currDate])) {
                    continue;
                }
                $dayOfWeek = intval(date('w', $curr));
                if ($workDaysConfig === 5) {
                    if ($dayOfWeek !== 0 && $dayOfWeek !== 6) $payableDaysCount++;
                } elseif ($workDaysConfig === 6) {
                    if ($dayOfWeek !== 0) $payableDaysCount++;
                } else {
                    $payableDaysCount++;
                }
            }
            $row->payable_days = $payableDaysCount;
            if (isset($row->hari_kerja) && floatval($row->hari_kerja) > floatval($payableDaysCount)) {
                $row->hari_kerja = $payableDaysCount;
            }

            // Override normal values to 0 if the employee joined after the cutoff date of the period
            $joinDate = !empty($row->tgl_masuk) ? $row->tgl_masuk : (!empty($row->start_contract) ? $row->start_contract : null);
            if ($joinDate && $joinDate > $endDateStr) {
                $row->hari_kerja = 0;
                $row->jam_overtime = 0.0;
                $row->jam_overtime_hari_biasa = 0.0;
                $row->jam_overtime_hari_libur = 0.0;
                $row->early_arrival_minutes = 0;
                $row->deduction_absensi = 0.0;
                $row->bonus_tambahan = 0.0;
            }

            if (!empty($row->employee_id)) {
                $payoutPeriodStr = intval($period->month) . '-' . intval($period->year);
                
                // Outgoing rapel search range (current calendar month)
                $monthStart = sprintf('%04d-%02d-01', $period->year, $period->month);
                $monthEnd = date('Y-m-t', strtotime($monthStart));

                // Query attendance logs with is_rapel = 1 that are either:
                // 1. Paid in this period (payout_period = $payoutPeriodStr) - Incoming rapel
                // 2. Logged in this period's calendar month - Outgoing rapel
                $rapelDays = $this->db->table('attendance_logs')
                                      ->where('employee_id', $row->employee_id)
                                      ->where('status', 'Hadir')
                                      ->where('is_rapel', 1)
                                      ->groupStart()
                                          ->where('payout_period', $payoutPeriodStr)
                                          ->orGroupStart()
                                              ->where('log_date >=', $monthStart)
                                              ->where('log_date <=', $monthEnd)
                                          ->groupEnd()
                                      ->groupEnd()
                                      ->get()->getResultArray();
                
                $row->rapel_hari_kerja = count($rapelDays);
                
                // Fallback for new hire rapel case before salary is generated (is_rapel = 0)
                $isNewHireRapelCase = ($joinDate && $joinDate > $endDateStr);
                if ($row->rapel_hari_kerja === 0 && $isNewHireRapelCase) {
                    $rawRapelDays = $this->db->table('attendance_logs')
                                          ->where('employee_id', $row->employee_id)
                                          ->where('status', 'Hadir')
                                          ->where('log_date >=', $joinDate)
                                          ->where('log_date <=', $monthEnd)
                                          ->get()->getResultArray();
                    $row->rapel_hari_kerja = count($rawRapelDays);

                    // If still 0, check if no logs exist at all for this employee in that range
                    if ($row->rapel_hari_kerja === 0) {
                        $totalLogsInRange = $this->db->table('attendance_logs')
                                                     ->where('employee_id', $row->employee_id)
                                                     ->where('log_date >=', $joinDate)
                                                     ->where('log_date <=', $monthEnd)
                                                     ->countAllResults();
                        if ($totalLogsInRange === 0) {
                            // Fallback to calendar working days based on work schedule config
                            $workDaysConfig = 5;
                            if (isset($row->employee_hari_kerja) && intval($row->employee_hari_kerja) > 0) {
                                $workDaysConfig = intval($row->employee_hari_kerja);
                            } elseif (isset($row->position_hari_kerja) && intval($row->position_hari_kerja) > 0) {
                                $workDaysConfig = intval($row->position_hari_kerja);
                            }
                            $row->rapel_hari_kerja = $this->getStandardWorkingDaysInRange($joinDate, $monthEnd, $workDaysConfig);
                        }
                    }
                }

                if ($row->rapel_hari_kerja > 0) {
                    $row->rapel_payout_period = !empty($rapelDays) ? $rapelDays[0]['payout_period'] : '';
                }

                // Query overtime logs with is_rapel = 1 and status = Approved
                $rapelOtSum = $this->db->table('overtime_logs')
                                      ->selectSum('jam_overtime')
                                      ->where('employee_id', $row->employee_id)
                                      ->where('status', 'Approved')
                                      ->where('is_rapel', 1)
                                      ->groupStart()
                                          ->where('payout_period', $payoutPeriodStr)
                                          ->orGroupStart()
                                              ->where('tanggal >=', $monthStart)
                                              ->where('tanggal <=', $monthEnd)
                                          ->groupEnd()
                                      ->groupEnd()
                                      ->get()->getRow();
                $row->rapel_jam_overtime = $rapelOtSum ? floatval($rapelOtSum->jam_overtime) : 0.0;

                if ($row->rapel_jam_overtime > 0 && empty($row->rapel_payout_period)) {
                    $firstOt = $this->db->table('overtime_logs')
                                        ->where('employee_id', $row->employee_id)
                                        ->where('status', 'Approved')
                                        ->where('is_rapel', 1)
                                        ->groupStart()
                                            ->where('payout_period', $payoutPeriodStr)
                                            ->orGroupStart()
                                                ->where('tanggal >=', $monthStart)
                                                ->where('tanggal <=', $monthEnd)
                                            ->groupEnd()
                                        ->groupEnd()
                                        ->get()->getRow();
                    if ($firstOt) {
                        $row->rapel_payout_period = $firstOt->payout_period;
                    }
                }
            }
        }

        return $this->respond($pkwts);
    }

    public function saveAttendance()
    {
        $data = $this->request->getJSON(true);
        $periodId = $data['period_id'];
        $pkwtId = $data['pkwt_id'];

        $existing = $this->db->table('payroll_attendance')
                             ->where('period_id', $periodId)
                             ->where('pkwt_id', $pkwtId)
                             ->get()->getRow();
        
        $data['is_manual'] = 1;

        if ($existing) {
            $this->db->table('payroll_attendance')->where('id', $existing->id)->update($data);
        } else {
            $this->db->table('payroll_attendance')->insert($data);
        }
        return $this->respond(['message' => 'Data cut-off saved successfully']);
    }

    public function saveAttendanceBulk()
    {
        $records = $this->request->getJSON(true);
        if (empty($records) || !is_array($records)) {
            return $this->failValidationErrors('Data absensi kosong.');
        }

        $count = 0;
        foreach ($records as $record) {
            $periodId = $record['period_id'] ?? null;
            $pkwtId = $record['pkwt_id'] ?? null;
            if (!$periodId || !$pkwtId) {
                continue;
            }

            $pkwt = $this->db->table('pkwt')->where('id', $pkwtId)->get()->getRow();
            $period = $this->db->table('payroll_periods')->where('id', $periodId)->get()->getRow();
            $employee = null;
            if ($pkwt) {
                $employee = $this->db->table('employees')
                                     ->where('client_id', $pkwt->client_id)
                                     ->where('nama', $pkwt->employee_name)
                                     ->get()->getRow();
            }

            $hariKerja = $record['hari_kerja'] ?? 0;
            $jamOvertime = $record['jam_overtime'] ?? 0;
            $deductionAbsensi = $record['deduction_absensi'] ?? 0;

            if ($employee && $period) {
                $clientId = $pkwt->client_id;
                $clientConfig = $this->resolveClientConfig($clientId, $pkwt->position_name);

                // Resolve cut-off date range using client config (same logic as getAttendance)
                $payrollConfig = $this->db->table('client_payroll_configs')->where('client_id', $clientId)->get()->getRow();
                $cutoffStart = $payrollConfig ? intval($payrollConfig->cutoff_start) : 21;
                $cutoffEnd = $payrollConfig ? intval($payrollConfig->cutoff_end) : 20;
                if ($cutoffStart <= 0) $cutoffStart = 21;
                if ($cutoffEnd <= 0) $cutoffEnd = 20;

                $month_start = intval($period->month);
                $year_start = intval($period->year);
                $month_end = $month_start;
                $year_end = $year_start;

                if ($cutoffStart > $cutoffEnd && $cutoffStart > 1) {
                    // Cross-month cutoff (e.g., 5-4): start is in the previous month
                    $month_start -= 1;
                    if ($month_start < 1) {
                        $month_start = 12;
                        $year_start -= 1;
                    }
                }

                $daysInStartMonth = intval(date('t', mktime(0, 0, 0, $month_start, 1, $year_start)));
                $daysInEndMonth = intval(date('t', mktime(0, 0, 0, $month_end, 1, $year_end)));
                $safeStart = min($cutoffStart, $daysInStartMonth);
                $safeEnd = min($cutoffEnd, $daysInEndMonth);

                $startDateStr = sprintf('%04d-%02d-%02d', $year_start, $month_start, $safeStart);
                $endDateStr = sprintf('%04d-%02d-%02d', $year_end, $month_end, $safeEnd);

                // Overtime and Early Arrival are paid in the month after the calendar month of the log
                $prevMonth = intval($period->month) - 1;
                $prevYear = intval($period->year);
                if ($prevMonth == 0) {
                    $prevMonth = 12;
                    $prevYear--;
                }
                $otStartDateStr = sprintf('%04d-%02d-01', $prevYear, $prevMonth);
                $otEndDateStr = date('Y-m-t', strtotime($otStartDateStr));

                $effectiveStartDateStr = !empty($employee->tgl_masuk) ? max($startDateStr, date('Y-m-d', strtotime($employee->tgl_masuk))) : $startDateStr;
                $effectiveOtStartDateStr = !empty($employee->tgl_masuk) ? max($otStartDateStr, date('Y-m-d', strtotime($employee->tgl_masuk))) : $otStartDateStr;

                // 1. Query total hari_kerja (Hadir)
                $hadirCount = $this->db->table('attendance_logs')
                                       ->where('employee_id', $employee->id)
                                       ->where('log_date >=', $effectiveStartDateStr)
                                       ->where('log_date <=', $endDateStr)
                                       ->where('status', 'Hadir')
                                       ->where('(is_rapel = 0 OR is_rapel IS NULL)')
                                       ->countAllResults();
                
                // 2. Query total jam_overtime (ONLY APPROVED) dari overtime_logs — SPLIT by is_holiday
                // 2a. Overtime Day Biasa (is_holiday = 0)
                $overtimeBiasaObj = $this->db->table('overtime_logs')
                                         ->selectSum('jam_overtime')
                                         ->where('employee_id', $employee->id)
                                         ->where('tanggal >=', $effectiveOtStartDateStr)
                                         ->where('tanggal <=', $otEndDateStr)
                                         ->where('status', 'Approved')
                                         ->where('(is_rapel = 0 OR is_rapel IS NULL)')
                                         ->where('(is_holiday = 0 OR is_holiday IS NULL)')
                                         ->get()->getRow();
                $overtimeBiasa = $overtimeBiasaObj ? floatval($overtimeBiasaObj->jam_overtime) : 0.0;

                // 2b. Overtime Holiday (is_holiday = 1)
                $overtimeLiburObj = $this->db->table('overtime_logs')
                                         ->selectSum('jam_overtime')
                                         ->where('employee_id', $employee->id)
                                         ->where('tanggal >=', $effectiveOtStartDateStr)
                                         ->where('tanggal <=', $otEndDateStr)
                                         ->where('status', 'Approved')
                                         ->where('(is_rapel = 0 OR is_rapel IS NULL)')
                                         ->where('is_holiday', 1)
                                         ->get()->getRow();
                $overtimeLibur = $overtimeLiburObj ? floatval($overtimeLiburObj->jam_overtime) : 0.0;

                $overtimeSum = $overtimeBiasa + $overtimeLibur;

                // 3. Loop daily logs to determine alpaCount automatically
                $currTime = strtotime($startDateStr);
                $endTime = strtotime($endDateStr);
                $alpaCount = 0;
                
                // Fetch all attendance logs for this employee in the period to optimize
                $attLogs = $this->db->table('attendance_logs')
                                    ->where('employee_id', $employee->id)
                                    ->where('log_date >=', $startDateStr)
                                    ->where('log_date <=', $endDateStr)
                                    ->where('(is_rapel = 0 OR is_rapel IS NULL)')
                                    ->get()->getResultArray();
                                    
                $logsByDate = [];
                foreach ($attLogs as $log) {
                    $logsByDate[$log['log_date']] = $log;
                }

                // Determine workDaysConfig
                $workDaysConfig = 5; // default
                if ($employee) {
                    $workDaysConfig = intval($employee->hari_kerja ?? 5);
                    if ($workDaysConfig < 1) {
                        $posId = $employee->position_id ?? null;
                        if ($posId) {
                            $pos = $this->db->table('positions')->where('id', $posId)->get()->getRow();
                            if ($pos && isset($pos->hari_kerja) && intval($pos->hari_kerja) > 0) {
                                $workDaysConfig = intval($pos->hari_kerja);
                            }
                        }
                    }
                }

                // Fetch holidays in range for absence counting
                $holidaysList = [];
                $holidayRowsForAlpa = $this->db->table('holiday_calendar')
                                  ->where('tanggal >=', $startDateStr)
                                  ->where('tanggal <=', $endDateStr)
                                  ->get()->getResultArray();
                foreach ($holidayRowsForAlpa as $h) {
                    $holidaysList[$h['tanggal']] = true;
                }

                while ($currTime <= $endTime) {
                    $currDateStr = date('Y-m-d', $currTime);
                    $dayOfWeek = intval(date('w', $currTime)); // 0 = Sunday, 6 = Saturday
                    
                    // Skip dates before employee's join date
                    $joinDate = !empty($employee->tgl_masuk) ? $employee->tgl_masuk : ($employee->start_contract ?? null);
                    if (!empty($joinDate) && strtotime($currDateStr) < strtotime($joinDate)) {
                        $currTime = strtotime('+1 day', $currTime);
                        continue;
                    }

                    // Determine if it is a working day (exclude holidays)
                    $isWorkingDay = false;
                    if (!isset($holidaysList[$currDateStr])) {
                        if ($workDaysConfig === 5) {
                            $isWorkingDay = ($dayOfWeek !== 0 && $dayOfWeek !== 6);
                        } elseif ($workDaysConfig === 6) {
                            $isWorkingDay = ($dayOfWeek !== 0);
                        } else {
                            $isWorkingDay = true;
                        }
                    }
                    
                    if ($isWorkingDay) {
                        if (!isset($logsByDate[$currDateStr])) {
                            $alpaCount++;
                        } else {
                            $logStatus = $logsByDate[$currDateStr]['status'] ?? 'Hadir';
                            $logStatusNorm = strtolower(trim($logStatus));
                            if ($logStatusNorm === 'absen' || $logStatusNorm === 'alpa' || $logStatusNorm === 'absent' || $logStatusNorm === 'missing') {
                                $alpaCount++;
                            }
                        }
                    }
                    
                    $currTime = strtotime('+1 day', $currTime);
                }

                // 4. Hitung deduction_absensi berdasarkan gaji pokok & hari_kerja config
                $workDaysConfig = isset($employee->hari_kerja) ? intval($employee->hari_kerja) : 5;
                $gajiPokok = floatval($employee->gaji_pokok);
                
                // Check if employee has an active contract that sets a different basic salary
                $activeContract = $this->db->table('contracts')
                                            ->where('employee_id', $employee->id)
                                            ->where('status_pkwt', 'Active')
                                            ->orderBy('tgl_mulai', 'DESC')
                                            ->get()->getRow();
                if ($activeContract && floatval($activeContract->gaji_pokok) > 0) {
                    $gajiPokok = floatval($activeContract->gaji_pokok);
                }

                if ($gajiPokok <= 0 && $pkwtId) {
                    $basicComp = $this->db->table('pkwt_components')
                                          ->where('pkwt_id', $pkwtId)
                                          ->groupStart()
                                              ->where('jenis_component', 'basic_salary')
                                              ->orLike('nama', 'Base Salary')
                                          ->groupEnd()
                                          ->get()->getRow();
                    if ($basicComp) {
                        $base_value = floatval($basicComp->value);
                        $sumber_value = $basicComp->sumber_value ?? 'nominal';
                        
                        if ($sumber_value === 'ump' || $sumber_value === 'umk' || $sumber_value === 'ump_umk') {
                            $mwId = $employee->minimum_wage_id ?? null;
                            $minimumWage = 0;
                            if ($employee && isset($employee->minimum_wage_id)) {
                                $mw = $this->db->table('minimum_wages')->where('id', $employee->minimum_wage_id)->get()->getRow();
                                if ($mw) {
                                    $minimumWage = floatval($mw->nominal);
                                    $mwId = $mw->id;
                                }
                            } else if ($clientConfig && isset($clientConfig->minimum_wage_id)) {
                                $mw = $this->db->table('minimum_wages')->where('id', $clientConfig->minimum_wage_id)->get()->getRow();
                                if ($mw) {
                                    $minimumWage = floatval($mw->nominal);
                                    $mwId = $mw->id;
                                }
                            }

                            $empProvince = null;
                            if ($employee && !empty($employee->work_location_id)) {
                                $wl = $this->db->table('work_locations')->where('id', $employee->work_location_id)->get()->getRow();
                                if ($wl && !empty($wl->provinsi)) {
                                    $empProvince = $wl->provinsi;
                                }
                            }

                            $resolvedWages = $this->resolveUmpUmk($mwId, null, $empProvince);
                            $umpWageValue = $resolvedWages['ump'];
                            $umkWageValue = $resolvedWages['umk'];

                            if ($sumber_value === 'ump') {
                                $base_value = $umpWageValue * ($base_value / 100);
                            } else if ($sumber_value === 'umk') {
                                $base_value = $umkWageValue * ($base_value / 100);
                            } else if ($sumber_value === 'ump_umk') {
                                $base_value = $minimumWage * ($base_value / 100);
                            }
                        }
                        
                        $gajiPokok = $base_value;
                    }
                }

                $divider = $this->getStandardWorkingDaysInRange($startDateStr, $endDateStr, $workDaysConfig);
                if ($divider <= 0) {
                    $divider = ($workDaysConfig === 5) ? 22 : (($workDaysConfig === 6) ? 26 : 30);
                }
                $dendaAbsenPerDay = $gajiPokok / $divider;
                $calculatedDeduction = $alpaCount * $dendaAbsenPerDay;

                // Override values with DB calculated ones if we found daily logs
                $hasAnyLogs = $this->db->table('attendance_logs')
                                       ->where('employee_id', $employee->id)
                                       ->where('log_date >=', $effectiveStartDateStr)
                                       ->where('log_date <=', $endDateStr)
                                       ->countAllResults();
                
                $earlyArrivalMinutes = 0;
                if ($hasAnyLogs > 0) {
                    $hariKerja = $hadirCount;
                    $jamOvertime = $overtimeSum;
                    $deductionAbsensi = $calculatedDeduction;
                    
                    // Hitung total APPROVED early arrival minutes dari tabel logs
                    $eaSumObj = $this->db->table('early_arrival')
                                         ->selectSum('eligible_minutes')
                                         ->where('employee_id', $employee->id)
                                         ->where('date >=', $effectiveOtStartDateStr)
                                         ->where('date <=', $otEndDateStr)
                                         ->where('status', 'APPROVED')
                                         ->get()->getRow();
                    $earlyArrivalMinutes = $eaSumObj ? intval($eaSumObj->eligible_minutes) : 0;
                } else {
                    $earlyArrivalMinutes = intval($record['early_arrival_minutes'] ?? 0);
                }

                $isJoinedPrevMonth = false;
                $isActiveRegularPrevMonth = false;
                if ($employee && !empty($employee->tgl_masuk)) {
                    $joinTs = strtotime($employee->tgl_masuk);
                    $joinYear = intval(date('Y', $joinTs));
                    $joinMonth = intval(date('n', $joinTs));
                    
                    $prevMonth = intval($period->month) - 1;
                    $prevYear = intval($period->year);
                    if ($prevMonth == 0) {
                        $prevMonth = 12;
                        $prevYear--;
                    }
                    if ($joinYear === $prevYear && $joinMonth === $prevMonth) {
                        $cutoffStartEnd = $this->resolveCutoffStartEnd($clientConfig);
                        $joinDateStr = date('Y-m-d', $joinTs);
                        $prevDaysInMonth = intval(date('t', mktime(0, 0, 0, $prevMonth, 1, $prevYear)));
                        $prevCutoffEndDate = sprintf('%04d-%02d-%02d', $prevYear, $prevMonth, min($cutoffStartEnd['end'], $prevDaysInMonth));
                        if ($joinDateStr > $prevCutoffEndDate) {
                            $isJoinedPrevMonth = true;
                        } else {
                            // Joined in previous month before cutoff. Check if active in current month (dates 1 to cutoffEnd)
                            $currentMonthStartStr = sprintf('%04d-%02d-01', $period->year, $period->month);
                            $currDaysInMonth = intval(date('t', mktime(0, 0, 0, intval($period->month), 1, intval($period->year))));
                            $currentCutoffEndStr = sprintf('%04d-%02d-%02d', $period->year, $period->month, min($cutoffStartEnd['end'], $currDaysInMonth));
                            $currentLogsCount = $this->db->table('attendance_logs')
                                                         ->where('employee_id', $employee->id)
                                                         ->where('log_date >=', $currentMonthStartStr)
                                                         ->where('log_date <=', $currentCutoffEndStr)
                                                         ->countAllResults();
                            
                            $prevCutoffStartStr = date('Y-m-d', strtotime('+1 day', strtotime($prevCutoffEndDate)));
                            $prevMonthLogsCount = $this->db->table('attendance_logs')
                                                         ->where('employee_id', $employee->id)
                                                         ->where('log_date >=', $prevCutoffStartStr)
                                                         ->where('log_date <', $currentMonthStartStr)
                                                         ->countAllResults();
                            
                            if ($currentLogsCount > 0 && $prevMonthLogsCount === 0) {
                                $isActiveRegularPrevMonth = true;
                            }
                        }
                    }
                }

                if ($isJoinedPrevMonth || $isActiveRegularPrevMonth) {
                    if ($hasAnyLogs === 0) {
                        $hariKerja = $this->getStandardWorkingDaysInRange($startDateStr, $endDateStr, $workDaysConfig);
                    }
                    $deductionAbsensi = 0.0;
                }

                // Set breakdown values (only available when we have daily logs)
                $jamOvertimeDayBiasa = $overtimeBiasa ?? 0;
                $jamOvertimeDayLibur = $overtimeLibur ?? 0;
            }

            $existing = $this->db->table('payroll_attendance')
                                 ->where('period_id', $periodId)
                                 ->where('pkwt_id', $pkwtId)
                                 ->get()->getRow();

            $saveData = [
                'period_id' => $periodId,
                'pkwt_id' => $pkwtId,
                'hari_kerja' => $hariKerja,
                'jam_overtime' => $jamOvertime,
                'jam_overtime_hari_biasa' => $jamOvertimeDayBiasa ?? 0,
                'jam_overtime_hari_libur' => $jamOvertimeDayLibur ?? 0,
                'early_arrival_minutes' => $earlyArrivalMinutes,
                'deduction_absensi' => $deductionAbsensi,
                'bonus_tambahan' => $record['bonus_tambahan'] ?? 0,
                'is_manual' => 0
            ];

            if ($existing) {
                $this->db->table('payroll_attendance')->where('id', $existing->id)->update($saveData);
            } else {
                $this->db->table('payroll_attendance')->insert($saveData);
            }
            $count++;
        }

        return $this->respond(['message' => "Successfully menyimpan {$count} data cut-off"]);
    }

    // --- GENERATE PAYROLL ---
    public function generatePayroll($periodId)
    {
        $clientId = ($this->request !== null) ? $this->request->getGet('client_id') : ($_GET['client_id'] ?? null);
        if ($deny = $this->guardPeriodAccess($periodId, $clientId)) return $deny;
        $this->syncEmployeesToPKWT($clientId);
        $this->syncOvertimeToPayrollAttendance($periodId, $clientId);
        $period = $this->db->table('payroll_periods')->where('id', $periodId)->get()->getRow();
        if (!$period) {
            return $this->failNotFound('Period not found');
        }

        // Reset early arrival records for this period back to NOT_PROCESSED
        $payoutPeriodStr = ($period->month < 10 ? '0' : '') . $period->month . '/' . $period->year;
        $this->db->table('early_arrival')
                 ->where('payroll_period', $payoutPeriodStr)
                 ->update([
                     'payroll_status' => 'NOT_PROCESSED',
                     'payroll_period' => null,
                     'updated_at' => date('Y-m-d H:i:s')
                 ]);

        $this->syncEarlyArrivalToPayrollAttendance($periodId, $clientId);
        $daysInMonth = date('t', mktime(0, 0, 0, intval($period->month), 1, intval($period->year)));

        // 1. Get all PKWTs
        $query = $this->db->table('pkwt');
        if ($clientId) {
            $query->where('client_id', $clientId);
        }
        $pkwts = $query->get()->getResult();

        foreach ($pkwts as $pkwt) {
            // 4. Resolve Employee / Client UMR (Minimum Wage)
            $emp = $this->db->table('employees')
                            ->select('employees.*, minimum_wages.nominal as umr_nominal, minimum_wages.id as mw_id, positions.hari_kerja as position_hari_kerja')
                            ->join('minimum_wages', 'minimum_wages.id = employees.minimum_wage_id', 'left')
                            ->join('positions', 'positions.id = employees.position_id', 'left')
                            ->where('employees.nama', $pkwt->employee_name)
                            ->where('employees.client_id', $pkwt->client_id)
                            ->get()
                            ->getRow();

            if (!$emp) {
                continue;
            }

            // Get client config to find payroll scheme ID
            $clientConfig = $this->resolveClientConfig($pkwt->client_id, $pkwt->position_name);

            // Verify if join date (tgl_masuk) is after the cutoff date
            $cutoffStart = $clientConfig ? intval($clientConfig->cutoff_start) : 21;
            $cutoffEnd = $clientConfig ? intval($clientConfig->cutoff_end) : 20;

            if ($clientConfig) {
                $startField = "cutoff_gaji_pokok_start";
                $endField = "cutoff_gaji_pokok_end";
                $refField = "cutoff_gaji_pokok_schedule_ref";
                
                $start = isset($clientConfig->$startField) ? intval($clientConfig->$startField) : null;
                $end = isset($clientConfig->$endField) ? intval($clientConfig->$endField) : null;
                $refId = isset($clientConfig->$refField) ? intval($clientConfig->$refField) : null;
                
                if ($refId) {
                    $sched = $this->db->table('payroll_schedules')->where('id', $refId)->get()->getRow();
                    if ($sched) {
                        $start = intval($sched->cutoff_start);
                        $end = intval($sched->cutoff_end);
                    }
                }
                
                if ($start === null) {
                    if (isset($clientConfig->cutoff_start)) {
                        $start = intval($clientConfig->cutoff_start);
                    } else {
                        $start = 21;
                    }
                }
                if ($end === null) {
                    if (isset($clientConfig->cutoff_end)) {
                        $end = intval($clientConfig->cutoff_end);
                    } else {
                        $end = $start - 1;
                        if ($end < 1) $end = $daysInMonth;
                    }
                }
                
                $cutoffStart = $start;
                $cutoffEnd = $end;
            }

            if ($cutoffStart <= 0) $cutoffStart = 1;
            if ($cutoffEnd <= 0) {
                $cutoffEnd = $cutoffStart - 1;
                if ($cutoffEnd < 1) $cutoffEnd = $daysInMonth;
            }

            // Check if employee is on hold due to absence in days after cutoff
            $holdPayroll = false;
            $tYear = intval($period->year);
            $tMonth = intval($period->month);
            $prevMonth = $tMonth - 1;
            $prevYear = $tYear;
            if ($prevMonth == 0) {
                $prevMonth = 12;
                $prevYear--;
            }
            if ($cutoffStart > 1) {
                $daysInPrevMonth = intval(date('t', mktime(0, 0, 0, $prevMonth, 1, $prevYear)));
                $safeRemainingStart = min($cutoffStart, $daysInPrevMonth);
                $remainingStartDate = sprintf('%04d-%02d-%02d', $prevYear, $prevMonth, $safeRemainingStart);
                $remainingEndDate = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $prevYear, $prevMonth)));
                
                $absentCountRemaining = $this->db->table('attendance_logs')
                    ->where('employee_id', $emp->id)
                    ->where('log_date >=', $remainingStartDate)
                    ->where('log_date <=', $remainingEndDate)
                    ->where('status', 'Absen')
                    ->countAllResults();
                if ($absentCountRemaining > 0) {
                    $holdPayroll = true;
                }
            }

            // Calculate cutoff-based period dates (not calendar month)
            // For cutoff_start > cutoff_end (cross-month cutoff, e.g. 5th to 4th):
            //   period month=7 means: June 5 → July 4
            // For cutoff_start <= cutoff_end (same-month cutoff, e.g. 1st to 31st):
            //   period month=7 means: July 1 → July 31
            $month_start_period = intval($period->month);
            $year_start_period = intval($period->year);
            $month_end_period = $month_start_period;
            $year_end_period = $year_start_period;
            if ($cutoffStart > $cutoffEnd && $cutoffStart > 1) {
                $month_start_period -= 1;
                if ($month_start_period < 1) {
                    $month_start_period = 12;
                    $year_start_period -= 1;
                }
            }

            $daysInStartMonth = date('t', mktime(0, 0, 0, $month_start_period, 1, $year_start_period));
            $daysInEndMonth = date('t', mktime(0, 0, 0, $month_end_period, 1, $year_end_period));

            $actualCutoffStart = min($cutoffStart, $daysInStartMonth);
            $actualCutoffEnd = min($cutoffEnd, $daysInEndMonth);

            $startDateStr = sprintf('%04d-%02d-%02d', $year_start_period, $month_start_period, $actualCutoffStart);
            $endDateStr = sprintf('%04d-%02d-%02d', $year_end_period, $month_end_period, $actualCutoffEnd);

            // CHECK PKWT EXPIRY FOR CONTRACT HOLD
            if (isset($emp->tipe_perjanjian) && $emp->tipe_perjanjian === 'PKWT') {
                $endDate = !empty($emp->end_contract) ? $emp->end_contract : null;
                $periodCalendarEndDate = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $tYear, $tMonth)));
                if ($endDate && $endDate < $periodCalendarEndDate) {
                    $holdPayroll = true;
                }
            }

            // Reset old rapel flags for this employee in the current period range
            if ($emp) {
                $this->db->table('attendance_logs')
                    ->where('employee_id', $emp->id)
                    ->where('log_date >=', $startDateStr)
                    ->where('log_date <=', $endDateStr)
                    ->update([
                        'is_rapel' => 0,
                        'payout_period' => null
                    ]);

                $this->db->table('overtime_logs')
                    ->where('employee_id', $emp->id)
                    ->where('tanggal >=', $startDateStr)
                    ->where('tanggal <=', $endDateStr)
                    ->update([
                        'is_rapel' => 0,
                        'payout_period' => null
                    ]);
            }

            $isNewHireRapel = false;
            $nextPeriodStr = '';
            if ($emp && !empty($emp->tgl_masuk)) {
                $joinTs = strtotime($emp->tgl_masuk);
                $joinDateStr = date('Y-m-d', $joinTs);
                
                $currDaysInMonth = intval(date('t', mktime(0, 0, 0, $tMonth, 1, $tYear)));
                $currentPeriodCutoffEnd = sprintf('%04d-%02d-%02d', $tYear, $tMonth, min($cutoffEnd, $currDaysInMonth));
                
                if ($joinDateStr > $currentPeriodCutoffEnd) {
                    $isRapelGP = ($clientConfig && isset($clientConfig->is_rapel_gaji_pokok)) ? intval($clientConfig->is_rapel_gaji_pokok) : 1;
                    $joinYear = intval(date('Y', $joinTs));
                    $joinMonth = intval(date('n', $joinTs));
                    $joinDay = intval(date('j', $joinTs));
                    
                    if ($joinYear === $tYear && $joinMonth === $tMonth && $joinDay >= $cutoffStart && $isRapelGP === 1) {
                        $isNewHireRapel = true;
                        
                        $nextMonth = $tMonth + 1;
                        $nextYear = $tYear;
                        if ($nextMonth > 12) {
                            $nextMonth = 1;
                            $nextYear++;
                        }
                        $nextPeriodStr = $nextMonth . '-' . $nextYear;
                    } else {
                        // Skip employee because join date is after cutoff date and not in current calendar month
                        $this->db->table('payroll_final')
                             ->where('period_id', $periodId)
                             ->where('pkwt_id', $pkwt->id)
                             ->delete();
                        continue;
                    }
                }
            }
            
            $isProrate = false;
            $isAbsenTidakPotong = false;
            $nominalDeductionAbsen = 0;
            $payrollScheme = null;
            
            // Try resolving from payroll scheme first
            if ($clientConfig && $clientConfig->payroll_scheme_id) {
                $payrollScheme = $this->db->table('payroll_schemes')->where('id', $clientConfig->payroll_scheme_id)->get()->getRow();
                if ($payrollScheme) {
                    $isProrate = ($payrollScheme->prorate == 1);
                    $isAbsenTidakPotong = ($payrollScheme->absen_tidak_potong == 1);
                    $nominalDeductionAbsen = floatval($payrollScheme->nominal_deduction ?? 0);
                }
            }
            
            // Fallback to client_absence_configs
            $absenceConfig = $this->db->table('client_absence_configs')->where('client_id', $pkwt->client_id)->get()->getRow();
            if ($clientConfig && !$isProrate && !$isAbsenTidakPotong && $nominalDeductionAbsen == 0) {
                if ($absenceConfig) {
                    $isProrate = ($absenceConfig->prorate == 1);
                    $isAbsenTidakPotong = ($absenceConfig->absen_tidak_potong == 1);
                    $nominalDeductionAbsen = floatval($absenceConfig->nominal_deduction);
                }
            }

            // Get Client's overtime_rate_per_hour (manual flat rate, or 0 = use formula)
            $client = $this->db->table('clients')->where('id', $pkwt->client_id)->get()->getRow();
            $clientManualOtRate = ($client && isset($client->overtime_rate_per_hour)) ? floatval($client->overtime_rate_per_hour) : 0;
            // Also check scheme template rate
            // Check if employee joined in the previous calendar month
            $isJoinedPrevMonth = false;
            $prevMonth = 0;
            $prevYear = 0;
            $joinTs = 0;
            if ($emp && !empty($emp->tgl_masuk)) {
                $joinTs = strtotime($emp->tgl_masuk);
                $joinYear = intval(date('Y', $joinTs));
                $joinMonth = intval(date('n', $joinTs));
                
                $prevMonth = intval($period->month) - 1;
                $prevYear = intval($period->year);
                if ($prevMonth == 0) {
                    $prevMonth = 12;
                    $prevYear--;
                }
                
                if ($joinYear === $prevYear && $joinMonth === $prevMonth) {
                    $joinDateStr = date('Y-m-d', $joinTs);
                    $prevDaysInMonth = intval(date('t', mktime(0, 0, 0, $prevMonth, 1, $prevYear)));
                    $prevCutoffEndDate = sprintf('%04d-%02d-%02d', $prevYear, $prevMonth, min($cutoffEnd, $prevDaysInMonth));
                    if ($joinDateStr > $prevCutoffEndDate) {
                        $isJoinedPrevMonth = true;
                    }
                }
            }

            $isActiveRegularPrevMonth = false;
            if ($emp && !empty($emp->tgl_masuk) && !$isJoinedPrevMonth) {
                $joinTs = strtotime($emp->tgl_masuk);
                $joinYear = intval(date('Y', $joinTs));
                $joinMonth = intval(date('n', $joinTs));
                
                $prevMonth = intval($period->month) - 1;
                $prevYear = intval($period->year);
                if ($prevMonth == 0) {
                    $prevMonth = 12;
                    $prevYear--;
                }
                
                if ($joinYear === $prevYear && $joinMonth === $prevMonth) {
                    $cutoffStartEnd = $this->resolveCutoffStartEnd($clientConfig);
                    $cutoffEndVal = $cutoffStartEnd['end'];
                    $currDaysInMonth = intval(date('t', mktime(0, 0, 0, intval($period->month), 1, intval($period->year))));
                    $prevDaysInMonth = intval(date('t', mktime(0, 0, 0, $prevMonth, 1, $prevYear)));
                    
                    $currentMonthStartStr = sprintf('%04d-%02d-01', intval($period->year), intval($period->month));
                    $currentCutoffEndStr = sprintf('%04d-%02d-%02d', intval($period->year), intval($period->month), min($cutoffEndVal, $currDaysInMonth));
                    
                    $currentLogsCount = $this->db->table('attendance_logs')
                                                 ->where('employee_id', $emp->id)
                                                 ->where('log_date >=', $currentMonthStartStr)
                                                 ->where('log_date <=', $currentCutoffEndStr)
                                                 ->countAllResults();
                    
                    $prevCutoffEndDate = sprintf('%04d-%02d-%02d', $prevYear, $prevMonth, min($cutoffEndVal, $prevDaysInMonth));
                    $prevCutoffStartStr = date('Y-m-d', strtotime('+1 day', strtotime($prevCutoffEndDate)));
                    $prevMonthLogsCount = $this->db->table('attendance_logs')
                                                 ->where('employee_id', $emp->id)
                                                 ->where('log_date >=', $prevCutoffStartStr)
                                                 ->where('log_date <', $currentMonthStartStr)
                                                 ->countAllResults();
                    
                    if ($currentLogsCount > 0 && $prevMonthLogsCount === 0) {
                        $isActiveRegularPrevMonth = true;
                    }
                }
            }

            // 2. Get Fixed Components from PKWT
            $rawComponents = $this->db->table('pkwt_components')->where('pkwt_id', $pkwt->id)->get()->getResult();
            $components = [];
            foreach ($rawComponents as $comp) {
                // Check if Ad-hoc and verify period matching current month/year
                $isAdhoc = isset($comp->allowance_type) && $comp->allowance_type === 'Ad-hoc';
                if ($isAdhoc) {
                    $payoutPeriod = trim($comp->payout_period ?? '');
                    $currentPeriod1 = intval($period->month) . '-' . intval($period->year);
                    $currentPeriod2 = sprintf('%02d-%d', intval($period->month), intval($period->year));
                    if ($payoutPeriod !== $currentPeriod1 && $payoutPeriod !== $currentPeriod2) {
                        continue; // Skip this component
                    }
                }
                $components[] = $comp;
            }
            
            // 3. Get Attendance Data
            $att = $this->db->table('payroll_attendance')
                            ->where('period_id', $periodId)
                            ->where('pkwt_id', $pkwt->id)
                            ->get()->getRow();

            $workDaysConfig = 5;
            if ($emp) {
                if (isset($emp->hari_kerja) && intval($emp->hari_kerja) > 0) {
                    $workDaysConfig = intval($emp->hari_kerja);
                } elseif (isset($emp->position_hari_kerja) && intval($emp->position_hari_kerja) > 0) {
                    $workDaysConfig = intval($emp->position_hari_kerja);
                }
            }
            $systemStandardDays = ($clientConfig && isset($clientConfig->standard_work_days) && intval($clientConfig->standard_work_days) > 0)
                ? max(1, intval($clientConfig->standard_work_days) - $this->getWeekdayHolidaysCount($startDateStr, $endDateStr, $workDaysConfig))
                : $this->getStandardWorkingDaysInRange($startDateStr, $endDateStr, $workDaysConfig);
            
            $stdWorkingDays = ($emp && isset($emp->custom_standard_days) && intval($emp->custom_standard_days) > 0)
                ? intval($emp->custom_standard_days)
                : $systemStandardDays;

            // Calculate expected working days since join date
            $expectedWorkingDays = $stdWorkingDays;
            $effectiveStartDateStr = $startDateStr;
            if ($emp && !empty($emp->tgl_masuk)) {
                $joinDateStr = date('Y-m-d', strtotime($emp->tgl_masuk));
                if ($joinDateStr >= $startDateStr && $joinDateStr <= $endDateStr) {
                    $effectiveStartDateStr = $joinDateStr;
                    // Calculate expected working days from join date to end date
                    $expectedWorkingDays = $this->getStandardWorkingDaysInRange($joinDateStr, $endDateStr, $workDaysConfig);
                }
            }
            
            $prevMonthStart = $effectiveStartDateStr;
            $prevMonthEnd = $endDateStr;
            
            $minimumWage = 0;
            $mwId = null;
            if ($emp && isset($emp->umr_nominal) && $emp->umr_nominal > 0) {
                $minimumWage = floatval($emp->umr_nominal);
                $mwId = $emp->mw_id;
            } else {
                // Fallback to client config's minimum wage
                if ($clientConfig && isset($clientConfig->minimum_wage_id)) {
                    $mw = $this->db->table('minimum_wages')->where('id', $clientConfig->minimum_wage_id)->get()->getRow();
                    if ($mw) {
                        $minimumWage = floatval($mw->nominal);
                        $mwId = $mw->id;
                    }
                }
            }

            // Resolve UMP and UMK values - pass employee's work location province for UMP lookup
            $empProvince = null;
            if ($emp && !empty($emp->work_location_id)) {
                $wl = $this->db->table('work_locations')->where('id', $emp->work_location_id)->get()->getRow();
                if ($wl && !empty($wl->provinsi)) {
                    $empProvince = $wl->provinsi;
                }
            }
            $resolvedWages = $this->resolveUmpUmk($mwId, null, $empProvince);
            $umpWageValue = $resolvedWages['ump'];
            $umkWageValue = $resolvedWages['umk'];

            // Check if we have a basic salary component with source = kompensasi
            $hasKompensasiSource = false;
            foreach ($components as $comp) {
                $isBasicSalary = (isset($comp->jenis_component) && $comp->jenis_component === 'basic_salary') || (stripos($comp->nama, 'Base Salary') !== false);
                if ($isBasicSalary && isset($comp->sumber_value) && $comp->sumber_value === 'kompensasi') {
                    $hasKompensasiSource = true;
                    break;
                }
            }

            // 5. First pass: Find and calculate Base Salary (Basic Salary)
            $gajiPokok = 0;
            $unproratedGajiPokok = 0;
            foreach ($components as $comp) {
                $isBasicSalary = false;
                if (isset($comp->jenis_component) && $comp->jenis_component === 'basic_salary') {
                    $isBasicSalary = true;
                } elseif (stripos($comp->nama, 'Base Salary') !== false) {
                    $isBasicSalary = true;
                }

                if ($isBasicSalary) {
                    $base_value = floatval($comp->value);
                    $sumber_value = $comp->sumber_value ?? 'nominal';
                    
                    if ($sumber_value === 'ump') {
                        $base_value = $umpWageValue * ($base_value / 100);
                    } else if ($sumber_value === 'umk') {
                        $base_value = $umkWageValue * ($base_value / 100);
                    } else if ($sumber_value === 'ump_umk') {
                        $base_value = $minimumWage * ($base_value / 100);
                    } else if ($sumber_value === 'kompensasi') {
                        $kompTetapValue = 0;
                        foreach ($components as $c) {
                            if (($c->jenis_component ?? '') === 'kompensasi' && ($c->sifat_kompensasi ?? '') === 'tetap') {
                                $kompTetapValue += floatval($c->value);
                            }
                        }
                        $base_value = $kompTetapValue * ($base_value / 100);
                    } else {
                        if ($base_value > 0) {
                            // Keep base_value from component / scheme!
                        } else if ($emp && isset($emp->gaji_pokok) && floatval($emp->gaji_pokok) > 0) {
                            $base_value = floatval($emp->gaji_pokok);
                        } else if ($minimumWage > 0) {
                            $base_value = $minimumWage;
                        }
                    }
                    
                    $unproratedGajiPokok = $base_value;
                    
                    // Scale by period
                    if (isset($comp->period)) {
                        if ($comp->period === 'jam') {
                            $actualHours = 0.0;
                            if ($emp && !empty($emp->id)) {
                                $hrsRow = $this->db->table('attendance_logs')
                                    ->selectSum('calculated_work_hours')
                                    ->where('employee_id', $emp->id)
                                    ->where('log_date >=', $startDateStr)
                                    ->where('log_date <=', $endDateStr)
                                    ->get()->getRow();
                                if ($hrsRow && floatval($hrsRow->calculated_work_hours) > 0) {
                                    $actualHours = floatval($hrsRow->calculated_work_hours);
                                }
                            }
                            if ($actualHours <= 0) {
                                $days = ($att && isset($att->hari_kerja) && $att->hari_kerja !== null) ? intval($att->hari_kerja) : $stdWorkingDays;
                                $actualHours = $days * 8;
                            }
                            $base_value = $base_value * $actualHours;
                        } elseif ($comp->period === 'hari' || $comp->period === 'hari_kerja') {
                            $days = ($att && isset($att->hari_kerja) && $att->hari_kerja !== null) ? intval($att->hari_kerja) : $stdWorkingDays;
                            $base_value = $base_value * $days;
                        } elseif ($comp->period === 'minggu') {
                            $base_value = $base_value * 4;
                        } elseif ($comp->period === 'year') {
                            $base_value = $base_value / 12;
                        }
                    }
                    
                    $gajiPokok = $base_value;
                    break; // Assume only one basic salary component
                }
            }

            if ($gajiPokok <= 0 && $emp && isset($emp->gaji_pokok)) {
                $unproratedGajiPokok = floatval($emp->gaji_pokok);
                $gajiPokok = $unproratedGajiPokok;
            }

            if ($isJoinedPrevMonth) {
                // Calculate Rapel Gaji for the previous month using resolved unprorated basic salary
                $prevStartDateStr = sprintf('%04d-%02d-01', $prevYear, $prevMonth);
                $prevEndDateStr = date('Y-m-t', strtotime($prevStartDateStr));
                
                $workDaysConfig = 5;
                if (isset($emp->hari_kerja) && intval($emp->hari_kerja) > 0) {
                    $workDaysConfig = intval($emp->hari_kerja);
                } elseif (isset($emp->position_hari_kerja) && intval($emp->position_hari_kerja) > 0) {
                    $workDaysConfig = intval($emp->position_hari_kerja);
                }
                
                $prevStdWorkingDays = $this->getStandardWorkingDaysInRange($prevStartDateStr, $prevEndDateStr, $workDaysConfig);
                
                $joinDateStr = date('Y-m-d', $joinTs);
                $hasAnyLogsPrev = $this->db->table('attendance_logs')
                                           ->where('employee_id', $emp->id)
                                           ->where('log_date >=', $joinDateStr)
                                           ->where('log_date <=', $prevEndDateStr)
                                           ->countAllResults() > 0;
                
                if ($hasAnyLogsPrev) {
                    $actualDaysWorkedPrev = $this->db->table('attendance_logs')
                                                 ->where('employee_id', $emp->id)
                                                 ->where('log_date >=', $joinDateStr)
                                                 ->where('log_date <=', $prevEndDateStr)
                                                 ->where('status', 'Hadir')
                                                 ->countAllResults();
                } else {
                    $actualDaysWorkedPrev = $this->getStandardWorkingDaysInRange($joinDateStr, $prevEndDateStr, $workDaysConfig);
                }
                
                $prevMonthComponents = [];
                foreach ($rawComponents as $comp) {
                    $isAdhoc = isset($comp->allowance_type) && $comp->allowance_type === 'Ad-hoc';
                    if ($isAdhoc) {
                        $payoutPeriod = trim($comp->payout_period ?? '');
                        $prevPeriod1 = intval($prevMonth) . '-' . intval($prevYear);
                        $prevPeriod2 = sprintf('%02d-%d', intval($prevMonth), intval($prevYear));
                        if ($payoutPeriod !== $prevPeriod1 && $payoutPeriod !== $prevPeriod2) {
                            continue; // Skip this component
                        }
                    }
                    $prevMonthComponents[] = $comp;
                }

                $prevProrateFactor = ($prevStdWorkingDays > 0) ? ($actualDaysWorkedPrev / $prevStdWorkingDays) : 0.0;
                $totalPrevEarnings = 0.0;
                $totalPrevDeductions = 0.0;

                foreach ($prevMonthComponents as $comp) {
                    $isBasic = (isset($comp->jenis_component) && $comp->jenis_component === 'basic_salary') || (stripos($comp->nama, 'Base Salary') !== false);
                    
                    if ($isBasic) {
                        $value = $unproratedGajiPokok * $prevProrateFactor;
                    } else {
                        $base_value = floatval($comp->value);
                        if (isset($comp->sumber_value)) {
                            if ($comp->sumber_value === 'ump') {
                                $base_value = $umpWageValue * ($base_value / 100);
                            } else if ($comp->sumber_value === 'umk') {
                                $base_value = $umkWageValue * ($base_value / 100);
                            } else if ($comp->sumber_value === 'ump_umk') {
                                $base_value = $minimumWage * ($base_value / 100);
                            }
                        }
                        
                        // Determine period and scale
                        $period = $comp->period ?? 'month';
                        if ($period === 'hari' || $period === 'hari_kerja') {
                            $value = $base_value * $actualDaysWorkedPrev;
                        } elseif ($period === 'minggu') {
                            $value = $base_value * 4 * $prevProrateFactor;
                        } elseif ($period === 'year') {
                            $value = ($base_value / 12) * $prevProrateFactor;
                        } else {
                            // monthly/month
                            $value = $base_value * $prevProrateFactor;
                        }
                    }

                    if (($comp->tipe ?? 'revenue') === 'revenue') {
                        $totalPrevEarnings += $value;
                    } else {
                        $totalPrevDeductions += $value;
                    }
                }
                $rapelAmount = $totalPrevEarnings - $totalPrevDeductions;
                if ($rapelAmount < 0) $rapelAmount = 0.0;
                
                if ($rapelAmount > 0) {
                    $payoutPeriodStr = intval($period->month) . '-' . intval($period->year);
                    $existingRapel = $this->db->table('pkwt_components')
                                              ->where('pkwt_id', $pkwt->id)
                                              ->where('allowance_type', 'Ad-hoc')
                                              ->where('payout_period', $payoutPeriodStr)
                                              ->like('nama', 'Rapel')
                                              ->get()->getRow();
                    $compData = [
                        'pkwt_id' => $pkwt->id,
                        'nama' => 'Rapel Gaji (Prorata Month Pertama)',
                        'tipe' => 'revenue',
                        'value' => $rapelAmount,
                        'is_persentase' => 0,
                        'jenis_component' => 'kompensasi',
                        'sifat_kompensasi' => 'tidak_tetap',
                        'sumber_value' => 'nominal',
                        'period' => 'month',
                        'allowance_type' => 'Ad-hoc',
                        'payout_period' => $payoutPeriodStr
                    ];
                    if (!$existingRapel) {
                        $this->db->table('pkwt_components')->insert($compData);
                        $compData['id'] = $this->db->insertID();
                        $components[] = (object) $compData;
                    } else {
                        $this->db->table('pkwt_components')
                                 ->where('id', $existingRapel->id)
                                 ->update([
                                     'value' => $rapelAmount
                                 ]);
                        $foundInMemory = false;
                        foreach ($components as &$c) {
                            if (isset($c->id) && $c->id == $existingRapel->id) {
                                $c->value = $rapelAmount;
                                $foundInMemory = true;
                                break;
                            }
                        }
                        if (!$foundInMemory) {
                            $compData['id'] = $existingRapel->id;
                            $components[] = (object) $compData;
                        }
                    }
                }
            } else {
                $payoutPeriodStr = intval($period->month) . '-' . intval($period->year);
                $this->db->table('pkwt_components')
                         ->where('pkwt_id', $pkwt->id)
                         ->where('allowance_type', 'Ad-hoc')
                         ->where('payout_period', $payoutPeriodStr)
                         ->like('nama', 'Rapel')
                         ->delete();
                
                // Also remove it from $components array in memory to prevent it being calculated in the current run
                foreach ($components as $key => $c) {
                    if (isset($c->allowance_type) && $c->allowance_type === 'Ad-hoc' &&
                        isset($c->payout_period) && $c->payout_period === $payoutPeriodStr &&
                        stripos($c->nama ?? '', 'Rapel') !== false) {
                        unset($components[$key]);
                    }
                }
                $components = array_values($components); // Reindex array
            }

            // Prorate basic salary if the employee is a new hire joining within this period
            if (!$isNewHireRapel && !$isJoinedPrevMonth && $emp && !empty($emp->tgl_masuk)) {
                $joinTs = strtotime($emp->tgl_masuk);
                $joinDateStr = date('Y-m-d', $joinTs);
                if ($joinDateStr >= $startDateStr && $joinDateStr <= $endDateStr) {
                    $joinMonth = intval(date('n', $joinTs));
                    $joinYear = intval(date('Y', $joinTs));
                    $isSameMonth = ($joinYear === $tYear && $joinMonth === $tMonth);

                    if (!$isSameMonth && $expectedWorkingDays < $stdWorkingDays && $stdWorkingDays > 0) {
                        $gajiPokok = ($expectedWorkingDays / $stdWorkingDays) * $gajiPokok;
                    }
                }
            }

            if ($isNewHireRapel) {
                // Calculate actual present days from attendance_logs
                $actualDaysWorked = 0;
                
                $currMonthStart = sprintf('%04d-%02d-01', $tYear, $tMonth);
                $currMonthEnd = date('Y-m-t', strtotime($currMonthStart));
                $currStdWorkingDays = $this->getStandardWorkingDaysInRange($currMonthStart, $currMonthEnd, $workDaysConfig);
                
                $joinDateStr = date('Y-m-d', $joinTs);
                $hasAnyLogsPrev = $this->db->table('attendance_logs')
                                           ->where('employee_id', $emp->id)
                                           ->where('log_date >=', $joinDateStr)
                                           ->where('log_date <=', $currMonthEnd)
                                           ->countAllResults() > 0;
                
                if ($hasAnyLogsPrev) {
                    $actualDaysWorkedPrev = $this->db->table('attendance_logs')
                                                 ->where('employee_id', $emp->id)
                                                 ->where('log_date >=', $joinDateStr)
                                                 ->where('log_date <=', $currMonthEnd)
                                                 ->where('status', 'Hadir')
                                                 ->countAllResults();
                } else {
                    $actualDaysWorkedPrev = $this->getStandardWorkingDaysInRange($joinDateStr, $currMonthEnd, $workDaysConfig);
                }
                
                $rapelAmount = 0.0;
                $totalEarnings = 0.0;
                $totalDeductions = 0.0;
                $prorateFactor = ($currStdWorkingDays > 0) ? ($actualDaysWorkedPrev / $currStdWorkingDays) : 0.0;

                foreach ($components as $comp) {
                    $isBasic = (isset($comp->jenis_component) && $comp->jenis_component === 'basic_salary') || (stripos($comp->nama, 'Base Salary') !== false);
                    
                    if ($isBasic) {
                        $value = $unproratedGajiPokok * $prorateFactor;
                        $gajiPokok = $value;
                    } else {
                        $base_value = floatval($comp->value);
                        if (isset($comp->sumber_value)) {
                            if ($comp->sumber_value === 'ump') {
                                $base_value = $umpWageValue * ($base_value / 100);
                            } else if ($comp->sumber_value === 'umk') {
                                $base_value = $umkWageValue * ($base_value / 100);
                            } else if ($comp->sumber_value === 'ump_umk') {
                                $base_value = $minimumWage * ($base_value / 100);
                            }
                        }
                        
                        // Determine period and scale
                        $period = $comp->period ?? 'month';
                        if ($period === 'hari' || $period === 'hari_kerja') {
                            $value = $base_value * $actualDaysWorkedPrev;
                        } elseif ($period === 'minggu') {
                            $value = $base_value * 4 * $prorateFactor;
                        } elseif ($period === 'year') {
                            $value = ($base_value / 12) * $prorateFactor;
                        } else {
                            // monthly/month
                            $value = $base_value * $prorateFactor;
                        }
                    }

                    if (($comp->tipe ?? 'revenue') === 'revenue') {
                        $totalEarnings += $value;
                    } else {
                        $totalDeductions += $value;
                    }
                }

                $rapelAmount = $totalEarnings - $totalDeductions;
                if ($rapelAmount < 0) $rapelAmount = 0.0;

                if ($rapelAmount > 0) {
                    $existingRapel = $this->db->table('pkwt_components')
                        ->where('pkwt_id', $pkwt->id)
                        ->where('allowance_type', 'Ad-hoc')
                        ->where('payout_period', $nextPeriodStr)
                        ->like('nama', 'Rapel')
                        ->get()->getRow();
                    
                    if (!$existingRapel) {
                        $this->db->table('pkwt_components')->insert([
                            'pkwt_id' => $pkwt->id,
                            'nama' => 'Rapel Gaji (Prorata Month Pertama)',
                            'tipe' => 'revenue',
                            'value' => $rapelAmount,
                            'is_persentase' => 0,
                            'jenis_component' => 'kompensasi',
                            'sifat_kompensasi' => 'tidak_tetap',
                            'sumber_value' => 'nominal',
                            'period' => 'month',
                            'allowance_type' => 'Ad-hoc',
                            'payout_period' => $nextPeriodStr
                        ]);
                    } else {
                        $this->db->table('pkwt_components')
                            ->where('id', $existingRapel->id)
                            ->update([
                                'value' => $rapelAmount
                            ]);
                    }
                }

                // Update logs
                $this->db->table('attendance_logs')
                    ->where('employee_id', $emp->id)
                    ->where('log_date >=', $startDateStr)
                    ->where('log_date <=', $endDateStr)
                    ->where('status', 'Hadir')
                    ->update([
                        'is_rapel' => 1,
                        'payout_period' => $nextPeriodStr
                    ]);

                $this->db->table('overtime_logs')
                    ->where('employee_id', $emp->id)
                    ->where('tanggal >=', $startDateStr)
                    ->where('tanggal <=', $endDateStr)
                    ->update([
                        'is_rapel' => 1,
                        'payout_period' => $nextPeriodStr
                    ]);

                $this->db->table('payroll_attendance')
                    ->where('period_id', $periodId)
                    ->where('pkwt_id', $pkwt->id)
                    ->update([
                        'hari_kerja' => 0,
                        'jam_overtime' => 0.0,
                        'early_arrival_minutes' => 0,
                        'deduction_absensi' => 0.0,
                        'bonus_tambahan' => 0.0
                    ]);

                // Save draft to payroll_final with 0 values
                $existingFinal = $this->db->table('payroll_final')
                                          ->where('period_id', $periodId)
                                          ->where('pkwt_id', $pkwt->id)
                                          ->get()->getRow();
                
                $finalData = [
                    'period_id' => $periodId,
                    'pkwt_id' => $pkwt->id,
                    'total_revenue' => $rapelAmount,
                    'total_deduction' => 0.0,
                    'take_home_pay' => $rapelAmount,
                    'status_approval' => 'Hold',
                    'bpjs_kes_karyawan' => 0.0,
                    'bpjs_kes_company' => 0.0,
                    'bpjs_jht_karyawan' => 0.0,
                    'bpjs_jht_company' => 0.0,
                    'bpjs_jp_karyawan' => 0.0,
                    'bpjs_jp_company' => 0.0,
                    'bpjs_jkk_company' => 0.0,
                    'bpjs_jkm_company' => 0.0,
                    'pph21' => 0.0,
                    'tax_allowance' => 0.0,
                    'tax_method' => ($client && isset($client->tax_method)) ? $client->tax_method : 'Gross',
                    'ptkp_status' => ($emp && isset($emp->ptkp)) ? $emp->ptkp : 'TK/0',
                    'gaji_pokok' => $gajiPokok,
                    'deduction_absen' => 0.0,
                    'jam_overtime' => 0.0,
                    'jam_overtime_biasa' => 0.0,
                    'jam_overtime_libur' => 0.0,
                    'overtime_pay' => 0.0,
                    'bonus_tambahan' => 0.0,
                    'early_arrival_minutes' => 0,
                    'early_arrival_pay' => 0.0,
                    'raw_components' => json_encode($components)
                ];

                if ($existingFinal) {
                    $this->db->table('payroll_final')->where('id', $existingFinal->id)->update($finalData);
                } else {
                    $this->db->table('payroll_final')->insert($finalData);
                }

                continue; // Skip the rest of the loop!
            }

            // 6. Second pass: Calculate all components
            $totalRevenue = 0;
            $totalDeduction = 0;
            $allowanceTetap = 0;

            // Match payroll scheme template for organizational matching
            $schemeModel = new \App\Models\PayrollSchemeTemplateModel();
            $schemeTemplateObj = $schemeModel->getSchemeForEmployee(
                $pkwt->client_id,
                $emp->division_id ?? null,
                $emp->department_id ?? null,
                $emp->position_id ?? null
            );
            $schemeTemplate = $schemeTemplateObj ? (array)$schemeTemplateObj : null;
            $schemeOtRate = ($schemeTemplate && isset($schemeTemplate['rate_overtime_per_jam'])) ? floatval($schemeTemplate['rate_overtime_per_jam']) : 0;

            $bpjsWageBase = 0;
            $pphWageBase = 0;

            foreach ($components as $comp) {
                $isBasic = false;
                if (isset($comp->jenis_component) && $comp->jenis_component === 'basic_salary') {
                    $isBasic = true;
                } elseif (stripos($comp->nama, 'Base Salary') !== false) {
                    $isBasic = true;
                }

                $value = 0;

                if ($isBasic) {
                    $value = $gajiPokok;
                } else {
                    if ($hasKompensasiSource && ($comp->jenis_component ?? '') === 'kompensasi' && ($comp->sifat_kompensasi ?? '') === 'tetap') {
                        $value = 0; // Prevent double-counting by setting it to 0
                    } else if (isset($comp->jenis_component) && !empty($comp->jenis_component)) {
                        // New Master Compensation component logic
                        $base_value = floatval($comp->value);
                        
                        if (isset($comp->sumber_value)) {
                            if ($comp->sumber_value === 'ump') {
                                $base_value = $umpWageValue * ($base_value / 100);
                            } else if ($comp->sumber_value === 'umk') {
                                $base_value = $umkWageValue * ($base_value / 100);
                            } else if ($comp->sumber_value === 'ump_umk') {
                                $base_value = $minimumWage * ($base_value / 100);
                            }
                        }
                        
                        // Scale by period
                        if ($comp->period === 'jam') {
                            $actualHours = 0.0;
                            if ($emp && !empty($emp->id)) {
                                $hrsRow = $this->db->table('attendance_logs')
                                    ->selectSum('calculated_work_hours')
                                    ->where('employee_id', $emp->id)
                                    ->where('log_date >=', $startDateStr)
                                    ->where('log_date <=', $endDateStr)
                                    ->get()->getRow();
                                if ($hrsRow && floatval($hrsRow->calculated_work_hours) > 0) {
                                    $actualHours = floatval($hrsRow->calculated_work_hours);
                                }
                            }
                            if ($actualHours <= 0) {
                                $days = ($att && isset($att->hari_kerja) && $att->hari_kerja !== null) ? intval($att->hari_kerja) : $stdWorkingDays;
                                $actualHours = $days * 8;
                            }
                            $value = $base_value * $actualHours;
                        } elseif ($comp->period === 'hari' || $comp->period === 'hari_kerja') {
                            $days = ($att && isset($att->hari_kerja) && $att->hari_kerja !== null) ? intval($att->hari_kerja) : $stdWorkingDays;
                            $value = $base_value * $days;
                        } elseif ($comp->period === 'minggu') {
                            $value = $base_value * 4;
                        } elseif ($comp->period === 'year') {
                            $value = $base_value / 12;
                        } else {
                            // monthan
                            // Fixed allowance: Value allowance tetap bersifat konstan setiap period (TIDAK terprorate)
                            $isCompAdhoc = isset($comp->allowance_type) && $comp->allowance_type === 'Ad-hoc';
                            if ($isProrate && isset($comp->sifat_kompensasi) && $comp->sifat_kompensasi === 'tidak_tetap' && !$isCompAdhoc) {
                                $days = ($att && isset($att->hari_kerja) && $att->hari_kerja !== null) ? intval($att->hari_kerja) : $stdWorkingDays;
                                $value = $base_value * ($days / $stdWorkingDays);
                            } else {
                                $value = $base_value;
                            }
                        }
                    } else {
                        // Legacy component logic
                        $value = floatval($comp->value);
                        if (intval($comp->is_persentase) === 1 || $comp->is_persentase === true) {
                            $value = $gajiPokok * ($value / 100);
                        }
                    }
                }

                if ($comp->tipe === 'revenue') {
                    $totalRevenue += $value;

                    // Resolve inclusion flags
                    $nameClean = strtolower(trim($comp->nama));
                    $isBpjsInc = false;
                    $isPphInc = true;

                    if ($isBasic) {
                        $isBpjsInc = true;
                        $isPphInc = true;
                    } else {
                        $isBpjsInc = (isset($comp->is_bpjs) && $comp->is_bpjs == 1);
                        $isPphInc = (!isset($comp->is_pph21) || $comp->is_pph21 == 1);
                    }

                    if ($isBpjsInc) {
                        $bpjsWageBase += $value;
                    }
                    if ($isPphInc) {
                        $pphWageBase += $value;
                    }
                } else {
                    $totalDeduction += $value;
                }
            }

            // 7. Add Variable Data from Attendance
            $deductionAbsenVal = 0;
            $overtimePay = 0;
            $earlyArrivalMinutes = 0;
            $earlyArrivalPay = 0.0;
            if ($att) {

                if (!$isAbsenTidakPotong) {
                    $deduction_absen = floatval($att->deduction_absensi);
                    $hasDailyLogs = $this->db->table('attendance_logs')
                                             ->where('employee_id', $emp->id)
                                             ->where('log_date >=', $startDateStr)
                                             ->where('log_date <=', $endDateStr)
                                             ->countAllResults() > 0;
                    if ($deduction_absen == 0 && !$hasDailyLogs && !$isJoinedPrevMonth && !$isActiveRegularPrevMonth) {
                        $prevMonth = intval($period->month) - 1;
                        $prevYear = intval($period->year);
                        if ($prevMonth == 0) {
                            $prevMonth = 12;
                            $prevYear--;
                        }
                        $prevMonthStart = sprintf('%04d-%02d-01', $prevYear, $prevMonth);
                        $prevMonthEnd = date('Y-m-t', strtotime($prevMonthStart));
                        $prevCalendarStdDays = ($emp && isset($emp->custom_standard_days) && intval($emp->custom_standard_days) > 0)
                            ? intval($emp->custom_standard_days)
                            : (($clientConfig && isset($clientConfig->standard_work_days) && intval($clientConfig->standard_work_days) > 0)
                                ? max(1, intval($clientConfig->standard_work_days) - $this->getWeekdayHolidaysCount($prevMonthStart, $prevMonthEnd, $workDaysConfig))
                                : $this->getStandardWorkingDaysInRange($prevMonthStart, $prevMonthEnd, $workDaysConfig));

                        $expectedWorkingDaysCalendar = $prevCalendarStdDays;
                        if ($emp && !empty($emp->tgl_masuk)) {
                            $joinDateStr = date('Y-m-d', strtotime($emp->tgl_masuk));
                            if ($joinDateStr >= $prevMonthStart && $joinDateStr <= $prevMonthEnd) {
                                $expectedWorkingDaysCalendar = $this->getStandardWorkingDaysInRange($joinDateStr, $prevMonthEnd, $workDaysConfig);
                            }
                        }

                        $missingDays = max(0, $expectedWorkingDaysCalendar - intval($att->hari_kerja));
                        if ($missingDays > 0) {
                            if ($isProrate) {
                                // Pro-rate: deduction = Base Salary * (Day Tidak Masuk / Working Days Standard)
                                $div = ($prevCalendarStdDays > 0) ? $prevCalendarStdDays : ($stdWorkingDays > 0 ? $stdWorkingDays : 22);
                                $deduction_absen = $unproratedGajiPokok * ($missingDays / $div);
                            } else {
                                // Deduction tetap: deduction = nominal yang ditetapkan per hari absen
                                $nominalDeduction = ($nominalDeductionAbsen > 0) ? $nominalDeductionAbsen : (($absenceConfig && isset($absenceConfig->nominal_deduction)) ? floatval($absenceConfig->nominal_deduction) : 0);
                                $deduction_absen = $missingDays * $nominalDeduction;
                            }
                        }
                    }
                    $deductionAbsenVal = $deduction_absen;
                    $totalDeduction += $deduction_absen;
                }
                
                // Deduction Late dan Early Leave based on attendance_logs
                $cutoffStart = $clientConfig ? intval($clientConfig->cutoff_start) : 1;
                $daysInMonth = date('t', mktime(0, 0, 0, intval($period->month), 1, intval($period->year)));
                $cutoffEnd = $clientConfig ? intval($clientConfig->cutoff_end) : ($cutoffStart - 1 > 0 ? $cutoffStart - 1 : $daysInMonth);
                if ($cutoffStart === 0) $cutoffStart = 1;
                if ($cutoffEnd === 0) {
                    $cutoffEnd = $cutoffStart - 1;
                    if ($cutoffEnd < 1) $cutoffEnd = $daysInMonth;
                }

                $month_start = intval($period->month);
                $year_start = intval($period->year);
                $month_end = $month_start;
                $year_end = $year_start;
                if ($cutoffStart > $cutoffEnd && $cutoffStart > 1) {
                    $month_start -= 1;
                    if ($month_start < 1) {
                        $month_start = 12;
                        $year_start -= 1;
                    }
                }

                $daysInStartMonth = date('t', mktime(0, 0, 0, $month_start, 1, $year_start));
                $daysInEndMonth = date('t', mktime(0, 0, 0, $month_end, 1, $year_end));

                $actualCutoffStart = min($cutoffStart, $daysInStartMonth);
                $actualCutoffEnd = min($cutoffEnd, $daysInEndMonth);

                $startDateStr = sprintf('%04d-%02d-%02d', $year_start, $month_start, $actualCutoffStart);
                $endDateStr = sprintf('%04d-%02d-%02d', $year_end, $month_end, $actualCutoffEnd);

                $lateEarlySum = null;
                if ($emp && isset($emp->id)) {
                    $lateEarlySum = $this->db->table('attendance_logs')
                        ->selectSum('late_hours')
                        ->selectSum('early_leave_hours')
                        ->where('employee_id', $emp->id)
                        ->where('log_date >=', $effectiveStartDateStr)
                        ->where('log_date <=', $endDateStr)
                        ->get()->getRow();
                }
                
                $lateHours = $lateEarlySum ? floatval($lateEarlySum->late_hours) : 0;
                $earlyHours = $lateEarlySum ? floatval($lateEarlySum->early_leave_hours) : 0;
                
                $standardHours = 160.0;
                $upahPerJam = $gajiPokok / $standardHours;
                
                $deductionLate = $lateHours * $upahPerJam;
                $deductionEarly = $earlyHours * $upahPerJam;
                
                $totalDeduction += $deductionLate + $deductionEarly;
                
                // === Early Arrival Calculation ===
                $companySetting = $this->db->table('company_payroll_setting')->get()->getRowArray();
                $minMinutes = isset($companySetting['early_arrival_min_minutes']) ? intval($companySetting['early_arrival_min_minutes']) : 30;
                $calcUnit = (isset($companySetting['early_arrival_calculation_unit']) && intval($companySetting['early_arrival_calculation_unit']) > 0) ? intval($companySetting['early_arrival_calculation_unit']) : 60;
                $roundingMethod = $companySetting['early_arrival_rounding_method'] ?? 'CEILING';
                $maxMinutes = isset($companySetting['max_early_arrival_minutes']) ? intval($companySetting['max_early_arrival_minutes']) : 180;
                $earlyArrivalEnabled = isset($companySetting['early_arrival_enabled']) ? intval($companySetting['early_arrival_enabled']) : 1;

                $totalEarlyArrivalBobotHours = 0.0;
                $earlyArrivalMinutes = isset($att->early_arrival_minutes) ? intval($att->early_arrival_minutes) : 0;

                if ($earlyArrivalEnabled) {
                    // Search total APPROVED early arrival logs untuk menentukan override
                    $approvedEarlyArrivals = [];
                    if ($emp && isset($emp->id)) {
                        $approvedEarlyArrivals = $this->db->table('early_arrival')
                            ->where('employee_id', $emp->id)
                            ->where('date >=', $effectiveStartDateStr)
                            ->where('date <=', $endDateStr)
                            ->where('status', 'APPROVED')
                            ->where('payroll_status', 'NOT_PROCESSED')
                            ->get()->getResultArray();
                    }

                    $sumEligibleMinutes = 0;
                    foreach ($approvedEarlyArrivals as $eaLog) {
                        $sumEligibleMinutes += intval($eaLog['eligible_minutes']);
                    }

                    // Check override manual (bila data di payroll_attendance.early_arrival_minutes berbeda dengan logs di DB)
                    $isOverride = ($att && ($earlyArrivalMinutes !== $sumEligibleMinutes || (empty($approvedEarlyArrivals) && $earlyArrivalMinutes > 0)));

                    if ($isOverride) {
                        // Gunakan value override dari payroll_attendance
                        if ($earlyArrivalMinutes >= $minMinutes) {
                            $jamEA = ($roundingMethod === 'CEILING') ? ceil($earlyArrivalMinutes / $calcUnit) : ($earlyArrivalMinutes / $calcUnit);
                            
                            // Batasi max_early_arrival_minutes
                            $maxHours = $maxMinutes / $calcUnit;
                            if ($jamEA > $maxHours) {
                                $jamEA = $maxHours;
                            }
                            
                            // Hitung bobot jam Kemnaker
                            if ($jamEA >= 1) {
                                $totalEarlyArrivalBobotHours = 1.5 + ($jamEA - 1) * 2.0;
                            }
                        }
                    } else {
                        // Hitung per transaksi harian
                        foreach ($approvedEarlyArrivals as $eaLog) {
                            $minutes = intval($eaLog['eligible_minutes']);
                            if ($minutes >= $minMinutes) {
                                $jamEA = ($roundingMethod === 'CEILING') ? ceil($minutes / $calcUnit) : ($minutes / $calcUnit);
                                
                                // Batasi max_early_arrival_minutes
                                $maxHours = $maxMinutes / $calcUnit;
                                if ($jamEA > $maxHours) {
                                    $jamEA = $maxHours;
                                }
                                
                                // Hitung bobot jam Kemnaker
                                if ($jamEA >= 1) {
                                    $totalEarlyArrivalBobotHours += (1.5 + ($jamEA - 1) * 2.0);
                                }
                            }
                        }
                    }

                    // Update early arrival records to PROCESSED
                    $eaIds = [];
                    foreach ($approvedEarlyArrivals as $eaLog) {
                        $eaIds[] = $eaLog['id'];
                    }

                    if (!empty($eaIds)) {
                        $payoutPeriodStr = ($period->month < 10 ? '0' : '') . $period->month . '/' . $period->year;
                        $this->db->table('early_arrival')
                            ->whereIn('id', $eaIds)
                            ->update([
                                'payroll_status' => 'PROCESSED',
                                'payroll_period' => $payoutPeriodStr,
                                'updated_at' => date('Y-m-d H:i:s')
                            ]);
                    }
                }

                $totalAllowanceTetap = 0.0;
                $totalAllowanceTidakTetap = 0.0;
                
                // 1. Dari $components (dari pkwt_components)
                foreach ($components as $comp) {
                    $isAllowance = (isset($comp->tipe) && (strtolower($comp->tipe) === 'revenue' || strtolower($comp->tipe) === 'allowance'));
                    $isTetap = (isset($comp->sifat_kompensasi) && strtolower($comp->sifat_kompensasi) === 'tetap');
                    $isMonthan = (empty($comp->period) || strtolower($comp->period) === 'month');
                    $isBasic = (isset($comp->jenis_component) && $comp->jenis_component === 'basic_salary') || (stripos($comp->nama, 'Base Salary') !== false);
                    
                    if ($isAllowance && !$isBasic) {
                        $base_value = floatval($comp->value ?? $comp->nominal ?? 0);
                        $sumber_value = $comp->sumber_value ?? 'nominal';
                        if ($sumber_value === 'ump') {
                            $base_value = $umpWageValue * ($base_value / 100);
                        } else if ($sumber_value === 'umk') {
                            $base_value = $umkWageValue * ($base_value / 100);
                        } else if ($sumber_value === 'ump_umk') {
                            $base_value = $minimumWage * ($base_value / 100);
                        }

                        if ($isTetap && $isMonthan) {
                            $totalAllowanceTetap += $base_value;
                        } else {
                            $totalAllowanceTidakTetap += $base_value;
                        }
                    }
                }

                // 2. Dari $schemeTemplate
                if ($schemeTemplate) {
                    $totalAllowanceTetap += floatval($schemeTemplate['allowance_transport'] ?? 0);
                    $totalAllowanceTetap += floatval($schemeTemplate['allowance_komunikasi'] ?? 0);
                    $totalAllowanceTetap += floatval($schemeTemplate['allowance_position'] ?? 0);
                    $totalAllowanceTetap += floatval($schemeTemplate['allowance_kehadiran'] ?? 0);
                    $totalAllowanceTetap += floatval($schemeTemplate['allowance_kinerja'] ?? 0);
                }

                $eaDivisor = ($clientConfig && isset($clientConfig->overtime_divisor) && intval($clientConfig->overtime_divisor) > 0) ? intval($clientConfig->overtime_divisor) : 173.0;
                $gajiPokokForEa = ($unproratedGajiPokok > 0) ? $unproratedGajiPokok : $gajiPokok;
                $eaCalcResult = $this->hitungDasarUpahLembur(
                    $gajiPokokForEa,
                    $totalAllowanceTetap,
                    $totalAllowanceTidakTetap,
                    floatval($minimumWage ?: ($umkWageValue ?: $umpWageValue)),
                    $eaDivisor
                );
                $eaUpahPerJam = $eaCalcResult['upah_per_jam'];

                $earlyArrivalPay = 0.0;
                if ($totalEarlyArrivalBobotHours > 0) {
                    $earlyArrivalPay = $totalEarlyArrivalBobotHours * $eaUpahPerJam;
                    $totalRevenue += $earlyArrivalPay;
                }
                
                $totalRevenue += $att->bonus_tambahan;
                
                // === Overtime Calculation — PP No. 35 Year 2021 (Progressive Multiplier) ===
                $jamOvertimeBiasa = floatval($att->jam_overtime_hari_biasa ?? 0);
                $jamOvertimeLibur = floatval($att->jam_overtime_hari_libur ?? 0);

                // Fallback: if breakdown columns are empty but total jam_overtime has value,
                // treat all as regular day overtime (backward compatibility)
                if ($jamOvertimeBiasa == 0 && $jamOvertimeLibur == 0 && floatval($att->jam_overtime) > 0) {
                    $jamOvertimeBiasa = floatval($att->jam_overtime);
                }

                // Determine working days per week for holiday multiplier bracket
                $workDaysPerWeek = 5;
                if ($emp && isset($emp->position_hari_kerja)) {
                    $posHkOt = intval($emp->position_hari_kerja);
                    if ($posHkOt == 6) $workDaysPerWeek = 6;
                }

                // Calculate upah sejam (hourly rate for overtime)
                $overtimeType = ($payrollScheme && !empty($payrollScheme->overtime_type)) ? $payrollScheme->overtime_type : 'standard';
                $overtimePay = 0;

                // Resolve calendar month range start/end for overtime logs query
                $calPrevMonth = intval($period->month) - 1;
                $calPrevYear = intval($period->year);
                if ($calPrevMonth == 0) {
                    $calPrevMonth = 12;
                    $calPrevYear--;
                }
                $calStartDateStr = sprintf('%04d-%02d-01', $calPrevYear, $calPrevMonth);
                $calEndDateStr = date('Y-m-t', strtotime($calStartDateStr));
                $calEffectiveStartDateStr = !empty($emp->tgl_masuk) ? max($calStartDateStr, date('Y-m-d', strtotime($emp->tgl_masuk))) : $calStartDateStr;

                if ($overtimeType === 'lumpsum') {
                    $lumpsumSubtype = $payrollScheme->lumpsum_subtype ?? 'per_jam';
                    $lumpsumNominal = floatval($payrollScheme->lumpsum_nominal ?? 0);
                    
                    if ($lumpsumSubtype === 'per_jam') {
                        $overtimePay = ($jamOvertimeBiasa + $jamOvertimeLibur) * $lumpsumNominal;
                    } elseif ($lumpsumSubtype === 'harian') {
                        // Count the number of distinct approved overtime logs in the calendar month range for this employee
                        $numDaysOvertime = 0;
                        if ($emp && isset($emp->id)) {
                            $otLogsQuery = $this->db->table('overtime_logs')
                                ->select('tanggal')
                                ->where('employee_id', $emp->id)
                                ->where('tanggal >=', $calEffectiveStartDateStr)
                                ->where('tanggal <=', $calEndDateStr)
                                ->where('status', 'Approved')
                                ->where('(is_rapel = 0 OR is_rapel IS NULL)')
                                ->get()
                                ->getResultArray();
                            
                            $distinctDays = [];
                            foreach ($otLogsQuery as $otLog) {
                                $distinctDays[$otLog['tanggal']] = true;
                            }
                            $numDaysOvertime = count($distinctDays);
                        }
                        $overtimePay = $numDaysOvertime * $lumpsumNominal;
                    } elseif ($lumpsumSubtype === 'monthan') {
                        $overtimePay = (($jamOvertimeBiasa + $jamOvertimeLibur) > 0) ? $lumpsumNominal : 0.0;
                    }
                } else {
                    // Priority: manual client rate > scheme template rate > formula PP 35/2021 (gaji pokok + tunjangan tetap min UMK aturan 75% / divisor)
                    $upahSejamOvertime = 0;
                    if ($clientManualOtRate > 0) {
                        $upahSejamOvertime = $clientManualOtRate;
                    } elseif ($schemeOtRate > 0) {
                        $upahSejamOvertime = $schemeOtRate;
                    } else {
                        $otDivisor = ($clientConfig && isset($clientConfig->overtime_divisor) && intval($clientConfig->overtime_divisor) > 0) ? intval($clientConfig->overtime_divisor) : 173;
                        $gajiPokokForOt = ($unproratedGajiPokok > 0) ? $unproratedGajiPokok : $gajiPokok;
                        $otCalcResult = $this->hitungDasarUpahLembur(
                            $gajiPokokForOt,
                            $totalAllowanceTetap,
                            $totalAllowanceTidakTetap,
                            floatval($minimumWage ?: ($umkWageValue ?: $umpWageValue)),
                            $otDivisor
                        );
                        $upahSejamOvertime = $otCalcResult['upah_per_jam'];
                    }

                    // Query daily overtime logs in the calendar month range
                    $otLogsQuery = [];
                    if ($emp && isset($emp->id)) {
                        $otLogsQuery = $this->db->table('overtime_logs')
                            ->where('employee_id', $emp->id)
                            ->where('tanggal >=', $calEffectiveStartDateStr)
                            ->where('tanggal <=', $calEndDateStr)
                            ->where('status', 'Approved')
                            ->where('(is_rapel = 0 OR is_rapel IS NULL)')
                            ->get()
                            ->getResultArray();
                    }

                    if (!empty($otLogsQuery)) {
                        $jamKonversiBiasa = 0.0;
                        $jamKonversiLibur = 0.0;
                        $weeklyHours = [];

                        foreach ($otLogsQuery as $otLog) {
                            $otHours = floatval($otLog['jam_overtime']);
                            $isOtHoliday = isset($otLog['is_holiday']) && intval($otLog['is_holiday']) === 1;
                            if ($isOtHoliday) {
                                $jamKonversiLibur += $this->hitungJamOvertimeKonversi($otHours, true, $workDaysPerWeek, true);
                            } else {
                                // Batas lembur hari kerja biasa PP 35/2021: 4 jam/hari dan 18 jam/minggu
                                $weekKey = !empty($otLog['tanggal']) ? date('o-W', strtotime($otLog['tanggal'])) : 'current';
                                $usedHours = $weeklyHours[$weekKey] ?? 0.0;
                                $dailyCapped = min(4.0, $otHours);
                                $remainingWeekly = max(0.0, 18.0 - $usedHours);
                                $effectiveHours = min($dailyCapped, $remainingWeekly);
                                $weeklyHours[$weekKey] = $usedHours + $effectiveHours;

                                if ($effectiveHours > 0) {
                                    $jamKonversiBiasa += $this->hitungJamOvertimeKonversi($effectiveHours, false, $workDaysPerWeek, false);
                                }
                            }
                        }
                    } else {
                        // Fallback to monthly total, without daily cap
                        $jamKonversiBiasa = $this->hitungJamOvertimeKonversi($jamOvertimeBiasa, false, $workDaysPerWeek, false);
                        $jamKonversiLibur = $this->hitungJamOvertimeKonversi($jamOvertimeLibur, true, $workDaysPerWeek, false);
                    }

                    $overtimePay = ($jamKonversiBiasa + $jamKonversiLibur) * $upahSejamOvertime;
                }
                
                $totalRevenue += $overtimePay;
            }

            // Calculate scheme template allowances and deductions
            $days = ($att && isset($att->hari_kerja) && $att->hari_kerja !== null) ? intval($att->hari_kerja) : $stdWorkingDays;
            $schemeAllowances = [];
            $schemeDeductions = [];

            if ($schemeTemplate) {
                $schemeAllowances = [
                    'Allowance Transport' => floatval($schemeTemplate['allowance_transport'] ?? 0),
                    'Allowance Makan Dayan' => floatval($schemeTemplate['allowance_makan'] ?? 0),
                    'Allowance Komunikasi' => floatval($schemeTemplate['allowance_komunikasi'] ?? 0),
                    'Allowance Position' => floatval($schemeTemplate['allowance_position'] ?? 0),
                    'Allowance Kehadiran' => floatval($schemeTemplate['allowance_kehadiran'] ?? 0),
                    'Allowance Kinerja' => floatval($schemeTemplate['allowance_kinerja'] ?? 0),
                ];

                foreach ($schemeAllowances as $allowanceName => $allowanceValue) {
                    if ($allowanceValue > 0) {
                        if ($allowanceName === 'Allowance Makan Dayan') {
                            $finalValue = $allowanceValue * $days;
                        } else {
                            $finalValue = $allowanceValue;
                        }

                        $totalRevenue += $finalValue;

                        // Check BPJS and PPh inclusion for this allowance
                        $bpjsField = '';
                        $pphField = '';
                        switch ($allowanceName) {
                            case 'Allowance Transport':
                                $bpjsField = 'bpjs_inc_transport';
                                $pphField = 'pph_inc_transport';
                                break;
                            case 'Allowance Makan Dayan':
                                $bpjsField = 'bpjs_inc_makan';
                                $pphField = 'pph_inc_makan';
                                break;
                            case 'Allowance Komunikasi':
                                $bpjsField = 'bpjs_inc_komunikasi';
                                $pphField = 'pph_inc_komunikasi';
                                break;
                            case 'Allowance Position':
                                $bpjsField = 'bpjs_inc_position';
                                $pphField = 'pph_inc_position';
                                break;
                            case 'Allowance Kehadiran':
                                $bpjsField = 'bpjs_inc_kehadiran';
                                $pphField = 'pph_inc_kehadiran';
                                break;
                            case 'Allowance Kinerja':
                                $bpjsField = 'bpjs_inc_kinerja';
                                $pphField = 'pph_inc_kinerja';
                                break;
                        }

                        $isBpjsInc = !empty($bpjsField) && ($schemeTemplate[$bpjsField] ?? 0) == 1;
                        $isPphInc = !empty($pphField) && ($schemeTemplate[$pphField] ?? 0) == 1;

                        if ($isBpjsInc) {
                            $bpjsWageBase += $finalValue;
                        }
                        if ($isPphInc) {
                            $pphWageBase += $finalValue;
                        }

                        // Append to components array for raw_components JSON storage
                        $compObj = new \stdClass();
                        $compObj->nama = $allowanceName;
                        $compObj->tipe = 'revenue';
                        $compObj->value = $finalValue;
                        $compObj->is_persentase = 0;
                        $compObj->jenis_component = 'scheme_template';
                        $components[] = $compObj;
                    }
                }

                $schemeDeductions = [
                    'Deduction Pinjaman' => floatval($schemeTemplate['deduction_pinjaman'] ?? 0),
                    'Deduction Kasbon' => floatval($schemeTemplate['deduction_kasbon'] ?? 0),
                    'Other Deductionsnya' => floatval($schemeTemplate['deduction_lainnya'] ?? 0),
                ];

                foreach ($schemeDeductions as $deductionName => $deductionValue) {
                    if ($deductionValue > 0) {
                        $totalDeduction += $deductionValue;

                        // Append to components array for raw_components JSON storage
                        $compObj = new \stdClass();
                        $compObj->nama = $deductionName;
                        $compObj->tipe = 'deduction';
                        $compObj->value = $deductionValue;
                        $compObj->is_persentase = 0;
                        $compObj->jenis_component = 'scheme_template';
                        $components[] = $compObj;
                    }
                }
            }

            // Adjust PPh wage base for attendance variations
            $pphWageBaseFinal = $pphWageBase;
            if ($att) {
                $pphWageBaseFinal += $overtimePay + floatval($att->bonus_tambahan) + $earlyArrivalPay - $deductionAbsenVal;
            }

            // Fallback for BPJS wage base to minimumWage if lower
            if ($minimumWage > 0 && $bpjsWageBase < $minimumWage) {
                $bpjsWageBase = $minimumWage;
            }

            // Calculate BPJS & Tax
            $taxScheme = null;
            if ($clientConfig && $clientConfig->tax_scheme_id) {
                $taxScheme = $this->db->table('tax_schemes')->where('id', $clientConfig->tax_scheme_id)->get()->getRow();
            }

            $bpjsScheme = null;
            if ($clientConfig && !empty($clientConfig->bpjs_scheme_id)) {
                $bpjsScheme = $this->db->table('tax_schemes')->where('id', $clientConfig->bpjs_scheme_id)->get()->getRow();
            }
            if (!$bpjsScheme) {
                $bpjsScheme = $taxScheme;
            }

            $ptkpStatus = 'TK/0';
            if ($emp && !empty($emp->ptkp)) {
                $ptkpStatus = $emp->ptkp;
            } elseif ($taxScheme && !empty($taxScheme->ptkp_status)) {
                $ptkpStatus = $taxScheme->ptkp_status;
            } elseif ($schemeTemplate && !empty($schemeTemplate['ptkp_status'])) {
                $ptkpStatus = $schemeTemplate['ptkp_status'];
            }

            // Resolve BPJS & Tax calculations
            $calc = $this->calculateBpjsAndTax($gajiPokok, $bpjsWageBase, $pphWageBaseFinal, $schemeTemplate, $taxScheme, $minimumWage, $ptkpStatus, $bpjsScheme, intval($period->month), intval($period->year), $pkwt->id);

            // Deductions from Employee: BPJS Kes Karyawan, JHT Karyawan, JP Karyawan
            $employeeBpjsDeductions = $calc['bpjs_kes_karyawan'] + $calc['bpjs_jht_karyawan'] + $calc['bpjs_jp_karyawan'];

            $isGrossUp = (strcasecmp($calc['metode_tax'] ?? '', 'Gross Up') === 0 || strcasecmp($calc['metode_tax'] ?? '', 'Grossup') === 0);
            $isNett = (strcasecmp($calc['metode_tax'] ?? '', 'Net') === 0 || strcasecmp($calc['metode_tax'] ?? '', 'Nett') === 0);

            if ($isGrossUp) {
                $totalRevenue += $calc['tax_allowance'];
                $totalDeduction += $employeeBpjsDeductions + $calc['pph21'];
            } else { // Gross and Nett (Always deduct tax from employee)
                $totalDeduction += $employeeBpjsDeductions + $calc['pph21'];
            }

            $thp = $totalRevenue - $totalDeduction;

            // 8. Save to payroll_final
            $existingFinal = $this->db->table('payroll_final')
                                      ->where('period_id', $periodId)
                                      ->where('pkwt_id', $pkwt->id)
                                      ->get()->getRow();
            
            $finalData = [
                'period_id' => $periodId,
                'pkwt_id' => $pkwt->id,
                'total_revenue' => $totalRevenue,
                'total_deduction' => $totalDeduction,
                'take_home_pay' => $thp,
                'status_approval' => $holdPayroll ? 'Hold' : 'Pending',
                'bpjs_kes_karyawan' => $calc['bpjs_kes_karyawan'],
                'bpjs_kes_company' => $calc['bpjs_kes_company'],
                'bpjs_jht_karyawan' => $calc['bpjs_jht_karyawan'],
                'bpjs_jht_company' => $calc['bpjs_jht_company'],
                'bpjs_jp_karyawan' => $calc['bpjs_jp_karyawan'],
                'bpjs_jp_company' => $calc['bpjs_jp_company'],
                'bpjs_jkk_company' => $calc['bpjs_jkk_company'],
                'bpjs_jkm_company' => $calc['bpjs_jkm_company'],
                'pph21' => $calc['pph21'],
                'tax_allowance' => $calc['tax_allowance'],
                'tax_method' => $calc['metode_tax'],
                'ptkp_status' => $ptkpStatus,
                'gaji_pokok' => $gajiPokok,
                'deduction_absen' => $deductionAbsenVal,
                'jam_overtime' => ($att) ? floatval($att->jam_overtime) : 0,
                'jam_overtime_biasa' => ($att) ? floatval($att->jam_overtime_hari_biasa ?? 0) : 0,
                'jam_overtime_libur' => ($att) ? floatval($att->jam_overtime_hari_libur ?? 0) : 0,
                'overtime_pay' => ($att) ? $overtimePay : 0,
                'bonus_tambahan' => ($att) ? floatval($att->bonus_tambahan) : 0,
                'early_arrival_minutes' => $earlyArrivalMinutes,
                'early_arrival_pay' => $earlyArrivalPay,
                'raw_components' => json_encode($components)
            ];

            if ($existingFinal) {
                $this->db->table('payroll_final')->where('id', $existingFinal->id)->update($finalData);
            } else {
                $this->db->table('payroll_final')->insert($finalData);
            }

        }

        $period = $this->db->table('payroll_periods')->where('id', $periodId)->get()->getRow();
        $period = $this->formatPeriodRow($period);
        $periodName = $period ? $period->nama : "ID: $periodId";
        $this->logActivity("Men-generate payroll untuk period: " . $periodName);
        return $this->respond(['message' => 'Gaji monthan successfully di-generate untuk period ini']);
    }

    public function getPayrollResults($periodId)
    {
        $clientId = $this->request->getGet('client_id');
        if ($deny = $this->guardPeriodAccess($periodId, $clientId)) return $deny;
        $period = $this->db->table('payroll_periods')->where('id', $periodId)->get()->getRow();
        $tYear = $period ? intval($period->year) : intval(date('Y'));
        $tMonth = $period ? intval($period->month) : intval(date('n'));
        $totalDaysInPeriod = intval(date('t', mktime(0, 0, 0, $tMonth, 1, $tYear)));

        // Pre-fetch holiday_calendar for the period month to compute accurate payable_days
        $periodMonthStart = sprintf('%04d-%02d-01', $tYear, $tMonth);
        $periodMonthEnd = date('Y-m-t', strtotime($periodMonthStart));
        $holidayRows = $this->db->table('holiday_calendar')
                                ->where('tanggal >=', $periodMonthStart)
                                ->where('tanggal <=', $periodMonthEnd)
                                ->get()->getResultArray();
        $holidaysInPeriod = [];
        foreach ($holidayRows as $h) {
            $holidaysInPeriod[$h['tanggal']] = true;
        }

        $query = $this->db->table('payroll_final')
                         ->select('
                             payroll_final.*, 
                             pkwt.employee_name, 
                             pkwt.tipe_perjanjian, 
                             pkwt.position_name as pkwt_position_name,
                             pkwt.client_id,
                             clients.nama as client_name,
                             divisions.nama as division_name,
                             departments.nama as department_name,
                             positions.nama as position_name,
                             employees.employ_id,
                             employees.nik,
                             employees.npwp,
                             employees.end_contract,
                             employees.tgl_masuk as join_date,
                             employees.start_contract as emp_start_contract,
                             pkwt.start_date as pkwt_start_date,
                             payroll_attendance.hari_kerja as working_days,
                             employees.hari_kerja as emp_hari_kerja,
                             employees.custom_standard_days as emp_custom_standard_days,
                             positions.hari_kerja as position_hari_kerja
                         ')
                         ->join('pkwt', 'pkwt.id = payroll_final.pkwt_id')
                         ->join('clients', 'clients.id = pkwt.client_id', 'left')
                         ->join('employees', 'employees.nama = pkwt.employee_name AND employees.client_id = pkwt.client_id', 'left')
                         ->join('positions', 'positions.id = employees.position_id', 'left')
                         ->join('departments', 'departments.id = positions.department_id', 'left')
                         ->join('divisions', 'divisions.id = departments.division_id', 'left')
                         ->join('payroll_attendance', 'payroll_attendance.period_id = payroll_final.period_id AND payroll_attendance.pkwt_id = payroll_final.pkwt_id', 'left')
                         ->where('payroll_final.period_id', $periodId);
        if ($clientId) {
            $query->where('pkwt.client_id', $clientId);
        }
        $allowedClientIds = $this->getAllowedClientIdsForCurrentUser();
        if ($allowedClientIds !== null) {
            $query->whereIn('pkwt.client_id', $allowedClientIds);
        }
        $data = $query->get()->getResult();

        foreach ($data as &$row) {
            $row->employ_id = !empty($row->employ_id) ? $row->employ_id : (!empty($row->nik) ? $row->nik : '-');

            // Auto-hold if PKWT contract is expired in the processed period and not yet Approved
            if (($row->tipe_perjanjian ?? '') === 'PKWT' && !empty($row->end_contract)) {
                $periodCalendarEndDate = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $tYear, $tMonth)));
                if ($row->end_contract < $periodCalendarEndDate && $row->status_approval !== 'Approved') {
                    if ($row->status_approval !== 'Hold') {
                        $this->db->table('payroll_final')->where('id', $row->id)->update(['status_approval' => 'Hold']);
                        $row->status_approval = 'Hold';
                    }
                }
            }

            $row->division_name = $row->division_name ?? '-';
            $row->department_name = $row->department_name ?? '-';
            $row->position_name = $row->position_name ?? $row->pkwt_position_name ?? '-';

            // Resolve Join Date, Working Days, and Total Days in Period
            $rawJoinDate = !empty($row->join_date) ? $row->join_date : (!empty($row->pkwt_start_date) ? $row->pkwt_start_date : (!empty($row->emp_start_contract) ? $row->emp_start_contract : null));
            $row->join_date = $rawJoinDate ? date('Y-m-d', strtotime($rawJoinDate)) : '-';
            $row->working_days = (isset($row->working_days) && $row->working_days !== null) ? floatval($row->working_days) : 0;
            $row->total_days_in_period = $totalDaysInPeriod;

            // Compute Payable Days by iterating each day in the period month,
            // excluding weekends (based on hari_kerja config) and holiday_calendar dates.
            // This matches the getStandardWorkingDaysInRange() logic used in Payroll.php.
            $workDaysConfig = intval($row->emp_hari_kerja ?? 5);
            if ($workDaysConfig <= 0) {
                $workDaysConfig = intval($row->position_hari_kerja ?? 5);
            }
            if ($workDaysConfig <= 0) {
                $workDaysConfig = 5;
            }

            // If employee has custom_standard_days override, use it directly
            if (isset($row->emp_custom_standard_days) && intval($row->emp_custom_standard_days) > 0) {
                $row->payable_days = intval($row->emp_custom_standard_days);
            } else {
                // Count actual working days in the period month
                $payableDaysCount = 0;
                $startTs = strtotime($periodMonthStart);
                $endTs = strtotime($periodMonthEnd);
                for ($curr = $startTs; $curr <= $endTs; $curr = strtotime('+1 day', $curr)) {
                    $currDate = date('Y-m-d', $curr);
                    if (isset($holidaysInPeriod[$currDate])) {
                        continue; // Skip holiday calendar dates
                    }
                    $dayOfWeek = intval(date('w', $curr)); // 0=Sun, 6=Sat
                    if ($workDaysConfig === 5) {
                        if ($dayOfWeek !== 0 && $dayOfWeek !== 6) $payableDaysCount++;
                    } elseif ($workDaysConfig === 6) {
                        if ($dayOfWeek !== 0) $payableDaysCount++;
                    } else {
                        $payableDaysCount++; // 7 days = all days
                    }
                }
                $row->payable_days = $payableDaysCount;
            }
            $row->work_days_per_week = $workDaysConfig;
            $row->target_work_hours = floatval($row->payable_days) * 8.0;

            // Query actual worked hours from attendance_logs for this employee in this period
            $actualHours = 0.0;
            if (!empty($row->employee_name) && !empty($row->client_id)) {
                $empObj = $this->db->table('employees')
                    ->select('id')
                    ->where('nama', $row->employee_name)
                    ->where('client_id', $row->client_id)
                    ->get()->getRow();
                if ($empObj) {
                    $hrsRow = $this->db->table('attendance_logs')
                        ->selectSum('calculated_work_hours')
                        ->where('employee_id', $empObj->id)
                        ->where('log_date >=', $periodMonthStart)
                        ->where('log_date <=', $periodMonthEnd)
                        ->get()->getRow();
                    if ($hrsRow && floatval($hrsRow->calculated_work_hours) > 0) {
                        $actualHours = floatval($hrsRow->calculated_work_hours);
                    }
                }
            }
            if ($actualHours <= 0 && floatval($row->working_days) > 0) {
                $actualHours = floatval($row->working_days) * 8.0;
            }
            $row->actual_work_hours = $actualHours;

            // Resolve Scheme Name
            $schemeName = '-';
            $clientConfig = $this->resolveClientConfig($row->client_id, $row->position_name);

            // Cap working_days at payable_days
            if (isset($row->payable_days) && floatval($row->working_days) > floatval($row->payable_days)) {
                $row->working_days = floatval($row->payable_days);
            }
            if ($clientConfig) {
                if ($clientConfig->payroll_type === 'Nominal') {
                    $schemeName = 'Nominal (Rp ' . number_format($clientConfig->custom_nominal, 0, ',', '.') . ')';
                } elseif ($clientConfig->payroll_type === 'UMP/UMK' || $clientConfig->payroll_type === 'UMP' || $clientConfig->payroll_type === 'UMK') {
                    $schemeName = $clientConfig->payroll_type;
                } elseif ($clientConfig->payroll_type === 'Template' && $clientConfig->payroll_scheme_id) {
                    $payrollScheme = $this->db->table('payroll_schemes')
                                              ->where('id', $clientConfig->payroll_scheme_id)
                                              ->get()
                                              ->getRow();
                    if ($payrollScheme) {
                        $schemeName = $payrollScheme->nama;
                    } else {
                        $schemeName = 'Template';
                    }
                }
            }
            $row->scheme_name = $schemeName;

            // Resolve new hire rapel details for frontend display
            $emp = $this->db->table('employees')
                            ->where('nama', $row->employee_name)
                            ->where('client_id', $row->client_id)
                            ->get()->getRow();

            $isNewHireRapel = false;
            $nextPeriodStr = '';
            if ($emp && !empty($emp->tgl_masuk)) {
                $joinTs = strtotime($emp->tgl_masuk);
                $joinDateStr = date('Y-m-d', $joinTs);

                $start = null;
                $end = null;
                $refId = null;
                if ($clientConfig) {
                    $startField = "cutoff_gaji_pokok_start";
                    $endField = "cutoff_gaji_pokok_end";
                    $refField = "cutoff_gaji_pokok_schedule_ref";
                    
                    $start = isset($clientConfig->$startField) ? intval($clientConfig->$startField) : null;
                    $end = isset($clientConfig->$endField) ? intval($clientConfig->$endField) : null;
                    $refId = isset($clientConfig->$refField) ? intval($clientConfig->$refField) : null;
                    
                    if ($refId) {
                        $sched = $this->db->table('payroll_schedules')->where('id', $refId)->get()->getRow();
                        if ($sched) {
                            $start = intval($sched->cutoff_start);
                            $end = intval($sched->cutoff_end);
                        }
                    }
                }
                
                if ($start === null) {
                    $start = ($clientConfig && isset($clientConfig->cutoff_start)) ? intval($clientConfig->cutoff_start) : 21;
                }
                if ($end === null) {
                    $end = ($clientConfig && isset($clientConfig->cutoff_end)) ? intval($clientConfig->cutoff_end) : ($start - 1);
                    if ($end < 1) $end = 31;
                }
                
                $cutoffStart = $start;
                $cutoffEnd = $end;
                
                $currDaysInMonth = intval(date('t', mktime(0, 0, 0, $tMonth, 1, $tYear)));
                $currentPeriodCutoffEnd = sprintf('%04d-%02d-%02d', $tYear, $tMonth, min($cutoffEnd, $currDaysInMonth));
                
                if ($joinDateStr > $currentPeriodCutoffEnd) {
                    $isRapelGP = ($clientConfig && isset($clientConfig->is_rapel_gaji_pokok)) ? intval($clientConfig->is_rapel_gaji_pokok) : 1;
                    $joinYear = intval(date('Y', $joinTs));
                    $joinMonth = intval(date('n', $joinTs));
                    $joinDay = intval(date('j', $joinTs));
                    
                    if ($joinYear === $tYear && $joinMonth === $tMonth && $joinDay >= $cutoffStart && $isRapelGP === 1) {
                        $isNewHireRapel = true;
                        
                        $nextMonth = $tMonth + 1;
                        $nextYear = $tYear;
                        if ($nextMonth > 12) {
                            $nextMonth = 1;
                            $nextYear++;
                        }
                        $nextPeriodStr = $nextMonth . '-' . $nextYear;
                    }
                }
            }

            $row->is_new_hire_rapel = $isNewHireRapel;
            $row->rapel_payout_period = $nextPeriodStr;
        }

        return $this->respond($data);
    }

    public function approvePayroll($id)
    {
        $row = $this->db->table('payroll_final')
                        ->select('payroll_final.*, pkwt.employee_name, pkwt.position_name, pkwt.client_id')
                        ->join('pkwt', 'pkwt.id = payroll_final.pkwt_id')
                        ->where('payroll_final.id', $id)
                        ->get()
                        ->getRow();
        if (!$row) {
            return $this->failNotFound('Data gaji not found');
        }

        $allowedClientIds = $this->getAllowedClientIdsForCurrentUser();
        if ($allowedClientIds !== null && !in_array((int)$row->client_id, $allowedClientIds)) {
            return $this->failForbidden('Anda tidak memiliki akses untuk menyetujui gaji dari klien ini.');
        }
        
        if ($row->status_approval === 'Hold') {
            // Check if it's a PKWT contract expired hold
            $emp = $this->db->table('employees')
                            ->where('nama', $row->employee_name)
                            ->where('client_id', $row->client_id)
                            ->select('tipe_perjanjian, end_contract')
                            ->get()
                            ->getRow();
            
            $isPkwtExpired = false;
            if ($emp && ($emp->tipe_perjanjian ?? '') === 'PKWT') {
                $endDate = !empty($emp->end_contract) ? $emp->end_contract : null;
                if ($endDate && strtotime($endDate) < time()) {
                    $isPkwtExpired = true;
                }
            }

            if (!$isPkwtExpired) {
                return $this->fail('Gaji untuk karyawan ' . $row->employee_name . ' tidak dapat disetujui karena berstatus Hold (dirapel ke month depan).');
            }
        }
        
        // Resolve Scheme
        $clientConfig = $this->resolveClientConfig($row->client_id, $row->position_name);
        if (!$clientConfig) {
            return $this->fail('Gaji untuk karyawan ' . $row->employee_name . ' tidak dapat disetujui karena belum memiliki scheme payroll.');
        }
        
        // Check Net Salary (take_home_pay)
        if (floatval($row->take_home_pay) <= 0) {
            return $this->fail('Gaji untuk karyawan ' . $row->employee_name . ' tidak dapat disetujui karena Net Salary bervalue Rp 0 atau kurang.');
        }

        $username = $this->request->getHeaderLine('X-User-Action') ?: 'Admin';
        $this->db->table('payroll_final')->where('id', $id)->update([
            'status_approval' => 'Approved',
            'approved_by' => $username
        ]);
        
        $employeeName = $row->employee_name;
        $this->logActivity("Menyetujui payroll karyawan: " . $employeeName . " (Payroll ID: " . $id . ")");
        return $this->respond(['message' => 'Gaji telah disetujui']);
    }

    public function approvePayrollBulk()
    {
        $json = $this->request->getJSON(true);
        $ids = $json['ids'] ?? [];
        if (empty($ids)) {
            return $this->fail('No data available gaji yang dipilih.');
        }

        // Validation check for all selected IDs first
        foreach ($ids as $id) {
            $row = $this->db->table('payroll_final')
                            ->select('payroll_final.*, pkwt.employee_name, pkwt.position_name, pkwt.client_id')
                            ->join('pkwt', 'pkwt.id = payroll_final.pkwt_id')
                            ->where('payroll_final.id', $id)
                            ->get()
                            ->getRow();
            if (!$row) {
                return $this->failNotFound('Data gaji tidak ditemukan (ID: ' . $id . ')');
            }

            $allowedClientIds = $this->getAllowedClientIdsForCurrentUser();
            if ($allowedClientIds !== null && !in_array((int)$row->client_id, $allowedClientIds)) {
                return $this->failForbidden('Anda tidak memiliki akses untuk menyetujui gaji karyawan ' . $row->employee_name . '.');
            }
            
            if ($row->status_approval === 'Hold') {
                return $this->fail('Gaji untuk karyawan ' . $row->employee_name . ' tidak dapat disetujui karena berstatus Hold (dirapel ke month depan).');
            }
            
            // Resolve Scheme
            $clientConfig = $this->resolveClientConfig($row->client_id, $row->position_name);
            if (!$clientConfig) {
                return $this->fail('Gaji untuk karyawan ' . $row->employee_name . ' tidak dapat disetujui karena belum memiliki scheme payroll.');
            }
            
            // Check Net Salary (take_home_pay)
            if (floatval($row->take_home_pay) <= 0) {
                return $this->fail('Gaji untuk karyawan ' . $row->employee_name . ' tidak dapat disetujui karena Net Salary bervalue Rp 0 atau kurang.');
            }
        }

        $username = $this->request->getHeaderLine('X-User-Action') ?: 'Admin';
        $this->db->table('payroll_final')
                 ->whereIn('id', $ids)
                 ->update([
                     'status_approval' => 'Approved',
                     'approved_by' => $username
                 ]);
                 
        // Log activity for each approved employee
        $finals = $this->db->table('payroll_final')
                          ->select('payroll_final.id, pkwt.employee_name')
                          ->join('pkwt', 'pkwt.id = payroll_final.pkwt_id')
                          ->whereIn('payroll_final.id', $ids)
                          ->get()
                          ->getResult();
                          
        foreach ($finals as $final) {
            $this->logActivity("Menyetujui payroll karyawan: " . $final->employee_name . " (Payroll ID: " . $final->id . ")");
        }
        
        return $this->respond(['status' => 'success', 'message' => 'All data gaji terpilih approved successfully']);
    }

    public function uploadManualPayroll()
    {
        $json = $this->request->getJSON(true);
        $periodId = $json['period_id'] ?? null;
        $clientId = $json['client_id'] ?? null;
        $rows = $json['rows'] ?? [];

        if (!$periodId) {
            return $this->fail('Parameter period_id dibutuhkan.');
        }
        if (empty($rows)) {
            return $this->fail('No data available gaji untuk di-upload.');
        }

        $period = $this->db->table('payroll_periods')->where('id', $periodId)->get()->getRow();
        if (!$period) {
            return $this->failNotFound('Period tidak ditemukan.');
        }

        $periodStatus = strtolower($period->status ?? 'open');
        if ($periodStatus !== 'open' && $periodStatus !== 'active' && $periodStatus !== 'terbuka') {
            return $this->fail('Period payroll sudah ditutup, tidak dapat meng-upload data gaji.');
        }

        $this->db->transStart();

        foreach ($rows as $row) {
            $pkwtId = $row['pkwt_id'] ?? null;
            if (!$pkwtId) continue;

            // Fetch PKWT
            $pkwt = $this->db->table('pkwt')->where('id', $pkwtId)->get()->getRow();
            if (!$pkwt) continue;

            // Fetch Employee
            $emp = $this->db->table('employees')
                            ->select('employees.*, minimum_wages.nominal as umr_nominal, minimum_wages.id as mw_id, positions.hari_kerja as position_hari_kerja')
                            ->join('minimum_wages', 'minimum_wages.id = employees.minimum_wage_id', 'left')
                            ->join('positions', 'positions.id = employees.position_id', 'left')
                            ->where('employees.nama', $pkwt->employee_name)
                            ->where('employees.client_id', $pkwt->client_id)
                            ->get()
                            ->getRow();
            if (!$emp) continue;

            // Resolve Client Config
            $clientConfig = $this->resolveClientConfig($pkwt->client_id, $pkwt->position_name);

            // Update payroll_attendance first to match manual upload values
            $att = $this->db->table('payroll_attendance')
                            ->where('period_id', $periodId)
                            ->where('pkwt_id', $pkwtId)
                            ->get()->getRow();

            $attData = [
                'period_id' => $periodId,
                'pkwt_id' => $pkwtId,
                'hari_kerja' => $row['working_days'],
                'jam_overtime' => $row['overtime_hours'],
                'early_arrival_minutes' => intval(($row['early_arrival_hours'] ?? 0) * 60),
                'deduction_absensi' => $row['deduction_absen'],
                'bonus_tambahan' => $row['bonus_tambahan'],
                'is_manual' => 1
            ];

            if ($att) {
                $this->db->table('payroll_attendance')->where('id', $att->id)->update($attData);
            } else {
                $this->db->table('payroll_attendance')->insert($attData);
            }

            // Resolve UMP & UMK minimum wages
            $minimumWage = 0;
            $mwId = null;
            if ($emp && isset($emp->umr_nominal) && $emp->umr_nominal > 0) {
                $minimumWage = floatval($emp->umr_nominal);
                $mwId = $emp->mw_id;
            } else {
                if ($clientConfig && isset($clientConfig->minimum_wage_id)) {
                    $mw = $this->db->table('minimum_wages')->where('id', $clientConfig->minimum_wage_id)->get()->getRow();
                    if ($mw) {
                        $minimumWage = floatval($mw->nominal);
                        $mwId = $mw->id;
                    }
                }
            }

            $empProvince = null;
            if ($emp && !empty($emp->work_location_id)) {
                $wl = $this->db->table('work_locations')->where('id', $emp->work_location_id)->get()->getRow();
                if ($wl && !empty($wl->provinsi)) {
                    $empProvince = $wl->provinsi;
                }
            }
            $resolvedWages = $this->resolveUmpUmk($mwId, null, $empProvince);
            $umpWageValue = $resolvedWages['ump'];
            $umkWageValue = $resolvedWages['umk'];

            // Fetch and build components
            $rawComponents = $this->db->table('pkwt_components')->where('pkwt_id', $pkwtId)->get()->getResult();
            $components = [];
            $hasBasicSalaryInComponents = false;
            $hasRapelInComponents = false;
            $hasDeductionLainInComponents = false;

            foreach ($rawComponents as $comp) {
                // If it is rapel, we override its value with the uploaded rapel
                if (stripos($comp->nama, 'Rapel') !== false) {
                    $comp->value = $row['rapel'];
                    $hasRapelInComponents = true;
                }
                
                // Skip ad-hoc components if period doesn't match and it is not a rapel component
                $isAdhoc = isset($comp->allowance_type) && $comp->allowance_type === 'Ad-hoc';
                if ($isAdhoc) {
                    $payoutPeriod = trim($comp->payout_period ?? '');
                    $currentPeriod1 = intval($period->month) . '-' . intval($period->year);
                    $currentPeriod2 = sprintf('%02d-%d', intval($period->month), intval($period->year));
                    if ($payoutPeriod !== $currentPeriod1 && $payoutPeriod !== $currentPeriod2 && stripos($comp->nama, 'Rapel') === false) {
                        continue;
                    }
                }

                $isBasic = (isset($comp->jenis_component) && $comp->jenis_component === 'basic_salary') || (stripos($comp->nama, 'Base Salary') !== false);
                if ($isBasic) {
                    $comp->value = $row['gaji_pokok'];
                    $hasBasicSalaryInComponents = true;
                }
                
                if (stripos($comp->nama, 'Other Deductions') !== false || stripos($comp->nama, 'Other Deductionsnya') !== false) {
                    $comp->value = $row['deduction_lain'];
                    $hasDeductionLainInComponents = true;
                }

                $components[] = $comp;
            }

            if (!$hasBasicSalaryInComponents) {
                $compObj = new \stdClass();
                $compObj->nama = 'Base Salary';
                $compObj->tipe = 'revenue';
                $compObj->value = $row['gaji_pokok'];
                $compObj->is_persentase = 0;
                $compObj->jenis_component = 'basic_salary';
                $components[] = $compObj;
            }

            if (!$hasRapelInComponents && $row['rapel'] > 0) {
                $compObj = new \stdClass();
                $compObj->nama = 'Rapel Gaji (Manual Upload)';
                $compObj->tipe = 'revenue';
                $compObj->value = $row['rapel'];
                $compObj->is_persentase = 0;
                $compObj->jenis_component = 'kompensasi';
                $compObj->sifat_kompensasi = 'tidak_tetap';
                $compObj->allowance_type = 'Ad-hoc';
                $components[] = $compObj;
            }

            if (!$hasDeductionLainInComponents && $row['deduction_lain'] > 0) {
                $compObj = new \stdClass();
                $compObj->nama = 'Other Deductions (Manual Upload)';
                $compObj->tipe = 'deduction';
                $compObj->value = $row['deduction_lain'];
                $compObj->is_persentase = 0;
                $compObj->jenis_component = 'scheme_template';
                $components[] = $compObj;
            }

            // Calculate wage bases
            $bpjsWageBase = 0;
            $pphWageBase = 0;
            $totalRevenue = 0;
            $totalDeduction = 0;

            foreach ($components as $comp) {
                $isBasic = (isset($comp->jenis_component) && $comp->jenis_component === 'basic_salary') || (stripos($comp->nama, 'Base Salary') !== false);
                $value = 0;

                if ($isBasic) {
                    $value = $row['gaji_pokok'];
                } else {
                    if (($comp->jenis_component ?? '') === 'kompensasi' && ($comp->sifat_kompensasi ?? '') === 'tetap') {
                        $value = floatval($comp->value);
                    } else if (isset($comp->jenis_component) && !empty($comp->jenis_component)) {
                        $base_value = floatval($comp->value);
                        if (isset($comp->sumber_value)) {
                            if ($comp->sumber_value === 'ump') {
                                $base_value = $umpWageValue * ($base_value / 100);
                            } else if ($comp->sumber_value === 'umk') {
                                $base_value = $umkWageValue * ($base_value / 100);
                            } else if ($comp->sumber_value === 'ump_umk') {
                                $base_value = $minimumWage * ($base_value / 100);
                            }
                        }
                        // Scale by period
                        $period = $comp->period ?? 'month';
                        if ($period === 'hari' || $period === 'hari_kerja') {
                            $value = $base_value * $row['working_days'];
                        } elseif ($period === 'minggu') {
                            $value = $base_value * 4;
                        } elseif ($period === 'year') {
                            $value = $base_value / 12;
                        } else {
                            $value = $base_value;
                        }
                    } else {
                        $value = floatval($comp->value);
                        if (intval($comp->is_persentase) === 1 || $comp->is_persentase === true) {
                            $value = $row['gaji_pokok'] * ($value / 100);
                        }
                    }
                }

                $comp->value = $value;

                if ($comp->tipe === 'revenue') {
                    $totalRevenue += $value;

                    $isBpjsInc = false;
                    $isPphInc = true;
                    if ($isBasic) {
                        $isBpjsInc = true;
                        $isPphInc = true;
                    } else {
                        $isBpjsInc = (isset($comp->is_bpjs) && $comp->is_bpjs == 1);
                        $isPphInc = (!isset($comp->is_pph21) || $comp->is_pph21 == 1);
                    }

                    if ($isBpjsInc) {
                        $bpjsWageBase += $value;
                    }
                    if ($isPphInc) {
                        $pphWageBase += $value;
                    }
                } else {
                    $totalDeduction += $value;
                }
            }

            // Calculate overtime pay and early arrival pay automatically
            $overtimePay = 0.0;
            $earlyArrivalPay = 0.0;

            // 1. Tentukan rate overtime per jam
            $otDivisor = ($clientConfig && isset($clientConfig->overtime_divisor) && intval($clientConfig->overtime_divisor) > 0) ? intval($clientConfig->overtime_divisor) : 173;
            $clientManualOtRate = ($clientConfig && isset($clientConfig->overtime_rate_per_hour)) ? floatval($clientConfig->overtime_rate_per_hour) : 0;
            
            // Get scheme template
            $schemeModel = new \App\Models\PayrollSchemeTemplateModel();
            $schemeTemplateObj = $schemeModel->getSchemeForEmployee(
                $pkwt->client_id,
                $emp->division_id ?? null,
                $emp->department_id ?? null,
                $emp->position_id ?? null
            );
            $schemeTemplate = $schemeTemplateObj ? (array)$schemeTemplateObj : null;
            $schemeOtRate = ($schemeTemplate && isset($schemeTemplate['rate_overtime_per_jam'])) ? floatval($schemeTemplate['rate_overtime_per_jam']) : 0;

            $upahSejamOvertime = ($clientManualOtRate > 0) ? $clientManualOtRate : (($schemeOtRate > 0) ? $schemeOtRate : (($row['gaji_pokok'] > 0) ? ($row['gaji_pokok'] / $otDivisor) : 0));

            // Tentukan working days per week
            $workDaysPerWeek = 5;
            if ($emp) {
                $posHkOt = intval($emp->position_hari_kerja ?? 5);
                if ($posHkOt == 6) $workDaysPerWeek = 6;
            }

            // Hitung konversi jam overtime
            $jamKonversiBiasa = $this->hitungJamOvertimeKonversi(floatval($row['overtime_hours']), false, $workDaysPerWeek, false);
            $overtimePay = $jamKonversiBiasa * $upahSejamOvertime;

            // 2. Hitung early arrival pay
            $companySetting = $this->db->table('company_payroll_setting')->get()->getRowArray();
            $minMinutes = isset($companySetting['early_arrival_min_minutes']) ? intval($companySetting['early_arrival_min_minutes']) : 30;
            $calcUnit = (isset($companySetting['early_arrival_calculation_unit']) && intval($companySetting['early_arrival_calculation_unit']) > 0) ? intval($companySetting['early_arrival_calculation_unit']) : 60;
            $roundingMethod = $companySetting['early_arrival_rounding_method'] ?? 'CEILING';
            $maxMinutes = isset($companySetting['max_early_arrival_minutes']) ? intval($companySetting['max_early_arrival_minutes']) : 180;
            $earlyArrivalEnabled = isset($companySetting['early_arrival_enabled']) ? intval($companySetting['early_arrival_enabled']) : 1;

            $earlyArrivalMinutes = intval(floatval($row['early_arrival_hours']) * 60);

            if ($earlyArrivalEnabled && $earlyArrivalMinutes >= $minMinutes) {
                $jamEA = ($roundingMethod === 'CEILING') ? ceil($earlyArrivalMinutes / $calcUnit) : ($earlyArrivalMinutes / $calcUnit);
                
                // Batasi max_early_arrival_minutes
                $maxHours = $maxMinutes / $calcUnit;
                if ($jamEA > $maxHours) {
                    $jamEA = $maxHours;
                }
                
                // Hitung bobot jam Kemnaker
                $totalEarlyArrivalBobotHours = 0;
                if ($jamEA >= 1) {
                    $totalEarlyArrivalBobotHours = 1.5 + ($jamEA - 1) * 2.0;
                }

                // Hitung totalAllowanceTetap
                $totalAllowanceTetap = 0;
                foreach ($components as $comp) {
                    if (($comp->tipe ?? '') === 'revenue') {
                        $isBasic = (isset($comp->jenis_component) && $comp->jenis_component === 'basic_salary') || (stripos($comp->nama, 'Base Salary') !== false);
                        $isTetap = (isset($comp->sifat_kompensasi) && strtolower($comp->sifat_kompensasi) === 'tetap');
                        $isMonthan = (empty($comp->period) || strtolower($comp->period) === 'month');
                        
                        if ($isTetap && $isMonthan && !$isBasic) {
                            $totalAllowanceTetap += floatval($comp->value);
                        }
                    }
                }

                if ($schemeTemplate) {
                    $totalAllowanceTetap += floatval($schemeTemplate['allowance_transport'] ?? 0);
                    $totalAllowanceTetap += floatval($schemeTemplate['allowance_komunikasi'] ?? 0);
                    $totalAllowanceTetap += floatval($schemeTemplate['allowance_position'] ?? 0);
                    $totalAllowanceTetap += floatval($schemeTemplate['allowance_kehadiran'] ?? 0);
                    $totalAllowanceTetap += floatval($schemeTemplate['allowance_kinerja'] ?? 0);
                }

                $eaDivisor = ($clientConfig && isset($clientConfig->overtime_divisor) && intval($clientConfig->overtime_divisor) > 0) ? intval($clientConfig->overtime_divisor) : 173.0;
                $eaUpahPerJam = ($row['gaji_pokok'] + $totalAllowanceTetap) / $eaDivisor;
                $earlyArrivalPay = $totalEarlyArrivalBobotHours * $eaUpahPerJam;
            }

            // Include calculated Overtime Pay & Early Arrival Pay to total income and PPh wage base
            $totalRevenue += $overtimePay + $earlyArrivalPay;
            $pphWageBaseFinal = $pphWageBase + $overtimePay + $earlyArrivalPay - $row['deduction_absen'];

            // Cap/Floor BPJS base
            if ($minimumWage > 0 && $bpjsWageBase < $minimumWage) {
                $bpjsWageBase = $minimumWage;
            }

            // Resolve Schemes and PTKP
            $taxScheme = null;
            if ($clientConfig && $clientConfig->tax_scheme_id) {
                $taxScheme = $this->db->table('tax_schemes')->where('id', $clientConfig->tax_scheme_id)->get()->getRow();
            }
            $bpjsScheme = null;
            if ($clientConfig && !empty($clientConfig->bpjs_scheme_id)) {
                $bpjsScheme = $this->db->table('tax_schemes')->where('id', $clientConfig->bpjs_scheme_id)->get()->getRow();
            }
            if (!$bpjsScheme) {
                $bpjsScheme = $taxScheme;
            }
            $ptkpStatus = 'TK/0';
            if ($emp && !empty($emp->ptkp)) {
                $ptkpStatus = $emp->ptkp;
            }

            // Recalculate BPJS and Tax
            $calc = $this->calculateBpjsAndTax($row['gaji_pokok'], $bpjsWageBase, $pphWageBaseFinal, null, $taxScheme, $minimumWage, $ptkpStatus, $bpjsScheme, intval($period->month), intval($period->year), $pkwtId);

            // Compute net take home pay
            $employeeBpjsDeductions = $calc['bpjs_kes_karyawan'] + $calc['bpjs_jht_karyawan'] + $calc['bpjs_jp_karyawan'];
            $totalDeduction += $row['deduction_absen'] + $row['deduction_lain'];

            $isGrossUp = (strcasecmp($calc['metode_tax'] ?? '', 'Gross Up') === 0 || strcasecmp($calc['metode_tax'] ?? '', 'Grossup') === 0);
            $isNett = (strcasecmp($calc['metode_tax'] ?? '', 'Net') === 0 || strcasecmp($calc['metode_tax'] ?? '', 'Nett') === 0);

            if ($isGrossUp) {
                $totalRevenue += $calc['tax_allowance'];
                $totalDeduction += $employeeBpjsDeductions + $calc['pph21'];
            } else { // Gross and Nett (Always deduct tax from employee)
                $totalDeduction += $employeeBpjsDeductions + $calc['pph21'];
            }

            $thp = $totalRevenue - $totalDeduction;

            // Save to payroll_final
            $existingFinal = $this->db->table('payroll_final')
                                      ->where('period_id', $periodId)
                                      ->where('pkwt_id', $pkwtId)
                                      ->get()->getRow();

            $finalData = [
                'period_id' => $periodId,
                'pkwt_id' => $pkwtId,
                'total_revenue' => $totalRevenue,
                'total_deduction' => $totalDeduction,
                'take_home_pay' => $thp,
                'status_approval' => 'Pending',
                'bpjs_kes_karyawan' => $calc['bpjs_kes_karyawan'],
                'bpjs_kes_company' => $calc['bpjs_kes_company'],
                'bpjs_jht_karyawan' => $calc['bpjs_jht_karyawan'],
                'bpjs_jht_company' => $calc['bpjs_jht_company'],
                'bpjs_jp_karyawan' => $calc['bpjs_jp_karyawan'],
                'bpjs_jp_company' => $calc['bpjs_jp_company'],
                'bpjs_jkk_company' => $calc['bpjs_jkk_company'],
                'bpjs_jkm_company' => $calc['bpjs_jkm_company'],
                'pph21' => $calc['pph21'],
                'tax_allowance' => $calc['tax_allowance'],
                'tax_method' => $calc['metode_tax'],
                'ptkp_status' => $ptkpStatus,
                'gaji_pokok' => $row['gaji_pokok'],
                'deduction_absen' => $row['deduction_absen'],
                'jam_overtime' => $row['overtime_hours'],
                'jam_overtime_biasa' => $row['overtime_hours'],
                'jam_overtime_libur' => 0,
                'overtime_pay' => $overtimePay,
                'bonus_tambahan' => $row['bonus_tambahan'],
                'early_arrival_minutes' => $earlyArrivalMinutes,
                'early_arrival_pay' => $earlyArrivalPay,
                'raw_components' => json_encode($components)
            ];

            if ($existingFinal) {
                $this->db->table('payroll_final')->where('id', $existingFinal->id)->update($finalData);
            } else {
                $this->db->table('payroll_final')->insert($finalData);
            }
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return $this->fail('Failed to save atau memproses data upload gaji.');
        }

        $username = $this->request->getHeaderLine('X-User-Action') ?: 'Admin';
        $periodName = $period ? $period->nama : "ID: $periodId";
        $this->logActivity("Meng-upload gaji manual dari Excel untuk period: " . $periodName . " oleh " . $username);

        return $this->respond(['status' => 'success', 'message' => 'Gaji manual successfully di-upload dan dikalkulasi otomatis.']);
    }

    public function getSlipDetails($id)
    {
        $final = $this->db->table('payroll_final')
                          ->select('payroll_final.*, pkwt.employee_name, pkwt.position_name, pkwt.client_id, payroll_periods.month, payroll_periods.year, clients.nama as client_name, employees.employ_id, employees.npwp, departments.nama as department_name')
                          ->join('pkwt', 'pkwt.id = payroll_final.pkwt_id')
                          ->join('payroll_periods', 'payroll_periods.id = payroll_final.period_id')
                          ->join('clients', 'clients.id = pkwt.client_id')
                          ->join('employees', 'employees.nama = pkwt.employee_name AND employees.client_id = pkwt.client_id', 'left')
                          ->join('positions', 'positions.id = employees.position_id', 'left')
                          ->join('departments', 'departments.id = positions.department_id', 'left')
                          ->where('payroll_final.id', $id)
                          ->get()
                          ->getRowArray();
        
        if (!$final) return $this->failNotFound('Data not found');
        if (!$this->checkClientAccess((int) ($final['client_id'] ?? 0))) {
            return $this->failClientForbidden('Akses ditolak. Slip gaji ini bukan milik klien yang Anda kelola.');
        }

        $months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
        $final['period_name'] = ($months[intval($final['month'] ?? 1) - 1] ?? '') . " " . ($final['year'] ?? '');

        // Load active period to calculate calendar days
        $period = $this->db->table('payroll_periods')->where('id', $final['period_id'])->get()->getRow();
        $period = $this->formatPeriodRow($period);
        $daysInMonth = $period ? date('t', mktime(0, 0, 0, intval($period->month), 1, intval($period->year))) : 30;

        // Get client config to find payroll scheme ID
        $clientConfig = $this->resolveClientConfig($final['client_id'], $final['position_name'] ?? null);
        
        $isProrate = false;
        $isAbsenTidakPotong = false;
        $nominalDeductionAbsen = 0;
        $payrollScheme = null;
        
        // Try resolving from payroll scheme first
        if ($clientConfig && $clientConfig->payroll_scheme_id) {
            $payrollScheme = $this->db->table('payroll_schemes')->where('id', $clientConfig->payroll_scheme_id)->get()->getRow();
            if ($payrollScheme) {
                $isProrate = ($payrollScheme->prorate == 1);
                $isAbsenTidakPotong = ($payrollScheme->absen_tidak_potong == 1);
                $nominalDeductionAbsen = floatval($payrollScheme->nominal_deduction ?? 0);
            }
        }
        
        // Fallback to client_absence_configs
        $absenceConfig = $this->db->table('client_absence_configs')->where('client_id', $final['client_id'])->get()->getRow();
        if ($clientConfig && !$isProrate && !$isAbsenTidakPotong && $nominalDeductionAbsen == 0) {
            if ($absenceConfig) {
                $isProrate = ($absenceConfig->prorate == 1);
                $isAbsenTidakPotong = ($absenceConfig->absen_tidak_potong == 1);
                $nominalDeductionAbsen = floatval($absenceConfig->nominal_deduction);
            }
        }

        // Get Client's overtime_rate_per_hour
        $client = $this->db->table('clients')->where('id', $final['client_id'])->get()->getRow();
        $otRate = ($client && isset($client->overtime_rate_per_hour) && floatval($client->overtime_rate_per_hour) > 0)
                  ? floatval($client->overtime_rate_per_hour)
                  : 20000;

        // Get Fixed Components
        $fixed = [];
        if (!empty($final['raw_components'])) {
            $fixed = json_decode($final['raw_components'], true);
        } else {
            $fixed = $this->db->table('pkwt_components')
                              ->where('pkwt_id', $final['pkwt_id'])
                              ->get()
                              ->getResultArray();
        }
        
        // Get Variable Components (Attendance)
        $att = $this->db->table('payroll_attendance')
                        ->where('period_id', $final['period_id'])
                        ->where('pkwt_id', $final['pkwt_id'])
                        ->get()
                        ->getRowArray();

        // 1. Resolve Employee / Client UMR (Minimum Wage)
        $emp = $this->db->table('employees')
                        ->select('employees.*, minimum_wages.nominal as umr_nominal, minimum_wages.id as mw_id, positions.hari_kerja as position_hari_kerja')
                        ->join('minimum_wages', 'minimum_wages.id = employees.minimum_wage_id', 'left')
                        ->join('positions', 'positions.id = employees.position_id', 'left')
                        ->where('employees.nama', $final['employee_name'])
                        ->where('employees.client_id', $final['client_id'])
                        ->get()
                        ->getRow();

        $workDaysConfig = 5;
        if ($emp) {
            if (isset($emp->hari_kerja) && intval($emp->hari_kerja) > 0) {
                $workDaysConfig = intval($emp->hari_kerja);
            } elseif (isset($emp->position_hari_kerja) && intval($emp->position_hari_kerja) > 0) {
                $workDaysConfig = intval($emp->position_hari_kerja);
            }
        }
        $prevMonth = intval($period->month) - 1;
        $prevYear = intval($period->year);
        if ($prevMonth == 0) {
            $prevMonth = 12;
            $prevYear--;
        }
        $startDateStr = sprintf('%04d-%02d-01', $prevYear, $prevMonth);
        $endDateStr = date('Y-m-t', strtotime($startDateStr));
        
        $systemStandardDays = ($clientConfig && isset($clientConfig->standard_work_days) && intval($clientConfig->standard_work_days) > 0)
            ? max(1, intval($clientConfig->standard_work_days) - $this->getWeekdayHolidaysCount($startDateStr, $endDateStr, $workDaysConfig))
            : $this->getStandardWorkingDaysInRange($startDateStr, $endDateStr, $workDaysConfig);

        $stdWorkingDays = ($emp && isset($emp->custom_standard_days) && intval($emp->custom_standard_days) > 0)
            ? intval($emp->custom_standard_days)
            : $systemStandardDays;
        
        if ($emp && !empty($emp->tgl_masuk)) {
            $joinDateStr = date('Y-m-d', strtotime($emp->tgl_masuk));
            if ($joinDateStr >= $startDateStr && $joinDateStr <= $endDateStr) {
                $startDateStr = $joinDateStr;
            }
        }

        $isHoldNewHire = false;
        $holdActualDaysWorked = 0;
        $holdStdWorkingDays = $stdWorkingDays;

        if (($final['status_approval'] ?? '') === 'Hold' && $emp && !empty($emp->tgl_masuk)) {
            $joinTs = strtotime($emp->tgl_masuk);
            $joinYear = intval(date('Y', $joinTs));
            $joinMonth = intval(date('n', $joinTs));
            
            $tYear = intval($final['year']);
            $tMonth = intval($final['month']);
            
            if ($joinYear === $tYear && $joinMonth === $tMonth) {
                $isHoldNewHire = true;
                
                $currMonthStart = sprintf('%04d-%02d-01', $tYear, $tMonth);
                $currMonthEnd = date('Y-m-t', strtotime($currMonthStart));
                $holdStdWorkingDays = $this->getStandardWorkingDaysInRange($currMonthStart, $currMonthEnd, $workDaysConfig);
                
                $joinDateStr = date('Y-m-d', $joinTs);
                $hasAnyLogsPrev = $this->db->table('attendance_logs')
                                           ->where('employee_id', $emp->id)
                                           ->where('log_date >=', $joinDateStr)
                                           ->where('log_date <=', $currMonthEnd)
                                           ->countAllResults() > 0;
                
                if ($hasAnyLogsPrev) {
                    $holdActualDaysWorked = $this->db->table('attendance_logs')
                                                 ->where('employee_id', $emp->id)
                                                 ->where('log_date >=', $joinDateStr)
                                                 ->where('log_date <=', $currMonthEnd)
                                                 ->where('status', 'Hadir')
                                                 ->countAllResults();
                } else {
                    $holdActualDaysWorked = $this->getStandardWorkingDaysInRange($joinDateStr, $currMonthEnd, $workDaysConfig);
                }
            }
        }
        
        // Resolve Scheme Template and Overtime Rate
        $schemeModel = new \App\Models\PayrollSchemeTemplateModel();
        $schemeTemplateObj = $schemeModel->getSchemeForEmployee(
            $final['client_id'],
            $emp->division_id ?? null,
            $emp->department_id ?? null,
            $emp->position_id ?? null
        );
        $schemeTemplate = $schemeTemplateObj ? (array)$schemeTemplateObj : null;
        $schemeOtRate = ($schemeTemplate && isset($schemeTemplate['rate_overtime_per_jam'])) ? floatval($schemeTemplate['rate_overtime_per_jam']) : 0;
        
        $minimumWage = 0;
        $mwId = null;
        if ($emp && isset($emp->umr_nominal) && $emp->umr_nominal > 0) {
            $minimumWage = floatval($emp->umr_nominal);
            $mwId = $emp->mw_id;
        } else {
            // Fallback to client config's minimum wage
            if ($clientConfig && isset($clientConfig->minimum_wage_id)) {
                $mw = $this->db->table('minimum_wages')->where('id', $clientConfig->minimum_wage_id)->get()->getRow();
                if ($mw) {
                    $minimumWage = floatval($mw->nominal);
                    $mwId = $mw->id;
                }
            }
        }

        // Resolve UMP and UMK values - pass employee's work location province for UMP lookup
        $empProvince = null;
        if ($emp && !empty($emp->work_location_id)) {
            $wl = $this->db->table('work_locations')->where('id', $emp->work_location_id)->get()->getRow();
            if ($wl && !empty($wl->provinsi)) {
                $empProvince = $wl->provinsi;
            }
        }
        $resolvedWages = $this->resolveUmpUmk($mwId, null, $empProvince);
        $umpWageValue = $resolvedWages['ump'];
        $umkWageValue = $resolvedWages['umk'];

        // Check if we have a basic salary component with source = kompensasi
        $hasKompensasiSource = false;
        foreach ($fixed as $comp) {
            $isBasic = (isset($comp['jenis_component']) && $comp['jenis_component'] === 'basic_salary') || (stripos($comp['nama'], 'Base Salary') !== false);
            if ($isBasic && isset($comp['sumber_value']) && $comp['sumber_value'] === 'kompensasi') {
                $hasKompensasiSource = true;
                break;
            }
        }

        // 2. First pass: Find and calculate Base Salary (Basic Salary)
        $gajiPokok = 0;
        $unproratedGajiPokok = 0;
        foreach ($fixed as $comp) {
            $isBasicSalary = false;
            if (isset($comp['jenis_component']) && $comp['jenis_component'] === 'basic_salary') {
                $isBasicSalary = true;
            } elseif (stripos($comp['nama'], 'Base Salary') !== false) {
                $isBasicSalary = true;
            }

            if ($isBasicSalary) {
                $base_value = floatval($comp['value']);
                $sumber_value = $comp['sumber_value'] ?? 'nominal';
                
                // Force check against actual client scheme if available
                if (isset($clientConfig) && $clientConfig->payroll_scheme_id) {
                    $schemeComp = $this->db->table('payroll_components')
                        ->where('scheme_id', $clientConfig->payroll_scheme_id)
                        ->where('jenis_component', 'basic_salary')
                        ->get()->getRow();
                    if ($schemeComp) {
                        $sumber_value = $schemeComp->sumber_value ?? 'nominal';
                        $base_value = floatval($schemeComp->value);
                    }
                }
                
                if ($sumber_value === 'ump') {
                    $base_value = $umpWageValue * ($base_value / 100);
                } else if ($sumber_value === 'umk') {
                    $base_value = $umkWageValue * ($base_value / 100);
                } else if ($sumber_value === 'ump_umk') {
                    $base_value = $minimumWage * ($base_value / 100);
                } else if ($sumber_value === 'kompensasi') {
                    $kompTetapValue = 0;
                    foreach ($fixed as $c) {
                        if (($c['jenis_component'] ?? '') === 'kompensasi' && ($c['sifat_kompensasi'] ?? '') === 'tetap') {
                            $kompTetapValue += floatval($c['value']);
                        }
                    }
                    $base_value = $kompTetapValue * ($base_value / 100);
                } else {
                    // Force base_value to use Employee's setup if available!
                    if ($emp && isset($emp->gaji_pokok) && floatval($emp->gaji_pokok) > 0) {
                        $base_value = floatval($emp->gaji_pokok);
                    } else if ($minimumWage > 0) {
                        $base_value = $minimumWage;
                    }
                }

                $unproratedGajiPokok = $base_value;

                // Scale by period
                if (isset($comp['period'])) {
                    if ($comp['period'] === 'hari' || $comp['period'] === 'hari_kerja') {
                        $days = ($att && isset($att['hari_kerja'])) ? intval($att['hari_kerja']) : 0;
                        $base_value = $base_value * $days;
                    } elseif ($comp['period'] === 'minggu') {
                        $base_value = $base_value * 4;
                    } elseif ($comp['period'] === 'year') {
                        $base_value = $base_value / 12;
                    }
                }
                
                $gajiPokok = $base_value;
                break; // Assume only one basic salary component
            }
        }

        if ($gajiPokok <= 0 && $emp && isset($emp->gaji_pokok)) {
            $unproratedGajiPokok = floatval($emp->gaji_pokok);
            $gajiPokok = $unproratedGajiPokok;
        }

        $earnings = [];
        $deductions = [];

        // 3. Calculate components
        foreach ($fixed as $comp) {
            $isCompAdhoc = isset($comp['allowance_type']) && $comp['allowance_type'] === 'Ad-hoc';
            if ($isCompAdhoc) {
                $payoutPeriod = trim($comp['payout_period'] ?? '');
                $currentPeriod1 = intval($final['month']) . '-' . intval($final['year']);
                $currentPeriod2 = sprintf('%02d-%d', intval($final['month']), intval($final['year']));
                if ($payoutPeriod !== $currentPeriod1 && $payoutPeriod !== $currentPeriod2) {
                    continue; // Skip this component
                }
            }
            $isBasic = false;
            if (isset($comp['jenis_component']) && $comp['jenis_component'] === 'basic_salary') {
                $isBasic = true;
            } elseif (stripos($comp['nama'], 'Base Salary') !== false) {
                $isBasic = true;
            }

            $value = 0;

            if ($isBasic) {
                $value = isset($final['gaji_pokok']) ? floatval($final['gaji_pokok']) : $gajiPokok;
            } else {
                if ($hasKompensasiSource && ($comp['jenis_component'] ?? '') === 'kompensasi' && ($comp['sifat_kompensasi'] ?? '') === 'tetap') {
                    $value = 0; // Prevent double-counting by setting it to 0
                } else if (isset($comp['jenis_component']) && !empty($comp['jenis_component'])) {
                    // New Master Compensation component logic
                    $base_value = floatval($comp['value']);
                    
                    if (isset($comp['sumber_value'])) {
                        if ($comp['sumber_value'] === 'ump') {
                            $base_value = $umpWageValue * ($base_value / 100);
                        } else if ($comp['sumber_value'] === 'umk') {
                            $base_value = $umkWageValue * ($base_value / 100);
                        } else if ($comp['sumber_value'] === 'ump_umk') {
                            $base_value = $minimumWage * ($base_value / 100);
                        }
                    }
                    
                    // Scale by period
                    if ($comp['period'] === 'hari' || $comp['period'] === 'hari_kerja') {
                        $days = ($att && isset($att['hari_kerja'])) ? intval($att['hari_kerja']) : 0;
                        if ($isHoldNewHire) {
                            $days = $holdActualDaysWorked;
                        }
                        $value = $base_value * $days;
                    } elseif ($comp['period'] === 'minggu') {
                        $value = $base_value * 4;
                        if ($isHoldNewHire) {
                            $value = $value * ($holdStdWorkingDays > 0 ? $holdActualDaysWorked / $holdStdWorkingDays : 0);
                        }
                    } elseif ($comp['period'] === 'year') {
                        $value = $base_value / 12;
                        if ($isHoldNewHire) {
                            $value = $value * ($holdStdWorkingDays > 0 ? $holdActualDaysWorked / $holdStdWorkingDays : 0);
                        }
                    } else {
                        // monthan
                        // Fixed allowance: Value allowance tetap bersifat konstan setiap period (TIDAK terprorate)
                        $isCompAdhoc = isset($comp['allowance_type']) && $comp['allowance_type'] === 'Ad-hoc';
                        if ($isProrate && isset($comp['sifat_kompensasi']) && $comp['sifat_kompensasi'] === 'tidak_tetap' && !$isCompAdhoc) {
                            $days = ($att && isset($att['hari_kerja'])) ? intval($att['hari_kerja']) : 0;
                            $stdDays = $stdWorkingDays;
                            if ($isHoldNewHire) {
                                $days = $holdActualDaysWorked;
                                $stdDays = $holdStdWorkingDays;
                            }
                            $value = $base_value * ($days / $stdDays);
                        } else {
                            if ($isHoldNewHire && !$isCompAdhoc) {
                                $value = $base_value * ($holdStdWorkingDays > 0 ? $holdActualDaysWorked / $holdStdWorkingDays : 0);
                            } else {
                                $value = $base_value;
                            }
                        }
                    }
                } else {
                    // Legacy component logic
                    $value = floatval($comp['value'] ?? $comp['nominal'] ?? $comp['value_nominal'] ?? 0);
                    if (intval($comp['is_persentase'] ?? 0) === 1 || ($comp['is_persentase'] ?? false) === true) {
                        $value = (isset($final['gaji_pokok']) ? floatval($final['gaji_pokok']) : $gajiPokok) * ($value / 100);
                    } else {
                        if ($isHoldNewHire) {
                            $value = $value * ($holdStdWorkingDays > 0 ? $holdActualDaysWorked / $holdStdWorkingDays : 0);
                        }
                    }
                }
            }

            if ($value > 0) {
                if ($comp['tipe'] === 'revenue') {
                    $earnings[] = ['nama' => $comp['nama'], 'value' => $value];
                } else {
                    $deductions[] = ['nama' => $comp['nama'], 'value' => $value];
                }
            }
        }

        if ($att) {
            // === Overtime Breakdown in Salary Slip ===
            $jamOvertimeBiasa = floatval($final['jam_overtime_biasa'] ?? 0);
            $jamOvertimeLibur = floatval($final['jam_overtime_libur'] ?? 0);
            $overtimePayTotal = floatval($final['overtime_pay'] ?? 0);

            $overtimeType = ($payrollScheme && !empty($payrollScheme->overtime_type)) ? $payrollScheme->overtime_type : 'standard';

            if ($overtimeType === 'lumpsum' && $overtimePayTotal > 0) {
                $lumpsumSubtype = $payrollScheme->lumpsum_subtype ?? 'per_jam';
                if ($lumpsumSubtype === 'per_jam') {
                    $totalJam = $jamOvertimeBiasa + $jamOvertimeLibur;
                    $earnings[] = [
                        'nama' => 'Overtime Lumpsum Per Jam (' . $totalJam . ' jam)',
                        'value' => $overtimePayTotal
                    ];
                } elseif ($lumpsumSubtype === 'harian') {
                    $numDaysOvertime = 0;
                    if ($emp && isset($emp->id)) {
                        $cutoffStart = $clientConfig ? intval($clientConfig->cutoff_start) : 21;
                        $cutoffEnd = $clientConfig ? intval($clientConfig->cutoff_end) : 20;
                        if ($clientConfig) {
                            $startField = "cutoff_overtime_start";
                            $endField = "cutoff_overtime_end";
                            $refField = "cutoff_overtime_schedule_ref";
                            
                            $start = isset($clientConfig->$startField) ? intval($clientConfig->$startField) : null;
                            $end = isset($clientConfig->$endField) ? intval($clientConfig->$endField) : null;
                            $refId = isset($clientConfig->$refField) ? intval($clientConfig->$refField) : null;
                            
                            if ($refId) {
                                $sched = $this->db->table('payroll_schedules')->where('id', $refId)->get()->getRow();
                                if ($sched) {
                                    $start = intval($sched->cutoff_start);
                                    $end = intval($sched->cutoff_end);
                                }
                            }
                            
                            if ($start === null) {
                                if (isset($clientConfig->cutoff_start)) {
                                    $start = intval($clientConfig->cutoff_start);
                                } else {
                                    $start = 21;
                                }
                            }
                            if ($end === null) {
                                if (isset($clientConfig->cutoff_end)) {
                                    $end = intval($clientConfig->cutoff_end);
                                } else {
                                    $end = $start - 1;
                                    if ($end < 1) $end = 31;
                                }
                            }
                            
                            $cutoffStart = $start;
                            $cutoffEnd = $end;
                        }

                        if ($cutoffStart <= 0) $cutoffStart = 1;
                        if ($cutoffEnd <= 0) {
                            $cutoffEnd = $cutoffStart - 1;
                            if ($cutoffEnd < 1) $cutoffEnd = 31;
                        }

                        $month_start = intval($period->month);
                        $year_start = intval($period->year);
                        $month_end = $month_start;
                        $year_end = $year_start;
                        if ($cutoffStart > $cutoffEnd && $cutoffStart > 1) {
                            $month_start -= 1;
                            if ($month_start < 1) {
                                $month_start = 12;
                                $year_start -= 1;
                            }
                        }
                        $daysInStartMonth = intval(date('t', mktime(0, 0, 0, $month_start, 1, $year_start)));
                        $daysInEndMonth = intval(date('t', mktime(0, 0, 0, $month_end, 1, $year_end)));
                        $safeStart = min($cutoffStart, $daysInStartMonth);
                        $safeEnd = min($cutoffEnd, $daysInEndMonth);
                        $cutoffStartDateStr = sprintf('%04d-%02d-%02d', $year_start, $month_start, $safeStart);
                        $cutoffEndDateStr = sprintf('%04d-%02d-%02d', $year_end, $month_end, $safeEnd);

                        // Resolve calendar month range
                        $calPrevMonth = intval($period->month) - 1;
                        $calPrevYear = intval($period->year);
                        if ($calPrevMonth == 0) {
                            $calPrevMonth = 12;
                            $calPrevYear--;
                        }
                        $calStartDateStr = sprintf('%04d-%02d-01', $calPrevYear, $calPrevMonth);
                        $calEndDateStr = date('Y-m-t', strtotime($calStartDateStr));
                        $calEffectiveStartDateStr = !empty($emp->tgl_masuk) ? max($calStartDateStr, date('Y-m-d', strtotime($emp->tgl_masuk))) : $calStartDateStr;

                        $otLogsSlip = $this->db->table('overtime_logs')
                            ->select('tanggal')
                            ->where('employee_id', $emp->id)
                            ->where('tanggal >=', $calEffectiveStartDateStr)
                            ->where('tanggal <=', $calEndDateStr)
                            ->where('status', 'Approved')
                            ->where('(is_rapel = 0 OR is_rapel IS NULL)')
                            ->get()
                            ->getResultArray();
                        
                        $distinctDaysSlip = [];
                        foreach ($otLogsSlip as $otLog) {
                            $distinctDaysSlip[$otLog['tanggal']] = true;
                        }
                        $numDaysOvertime = count($distinctDaysSlip);
                    }
                    $earnings[] = [
                        'nama' => 'Overtime Lumpsum Dayan (' . $numDaysOvertime . ' hari)',
                        'value' => $overtimePayTotal
                    ];
                } elseif ($lumpsumSubtype === 'monthan') {
                    $earnings[] = [
                        'nama' => 'Overtime Lumpsum Monthan',
                        'value' => $overtimePayTotal
                    ];
                }
            } else if ($overtimePayTotal > 0 && ($jamOvertimeBiasa > 0 || $jamOvertimeLibur > 0)) {
                // We have breakdown data — show separate lines
                // Determine working days per week for conversion calc
                $wdPerWeekSlip = 5;
                if ($emp && isset($emp->position_hari_kerja) && intval($emp->position_hari_kerja) == 6) {
                    $wdPerWeekSlip = 6;
                }

                // Calculate upah sejam for proportional split (PP 35/2021)
                $clientManualOtRateSlip = ($client && isset($client->overtime_rate_per_hour)) ? floatval($client->overtime_rate_per_hour) : 0;
                $unproratedGpSlip = floatval($final['gaji_pokok'] ?? 0);
                $otDivisorSlip = ($clientConfig && isset($clientConfig->overtime_divisor) && intval($clientConfig->overtime_divisor) > 0) ? intval($clientConfig->overtime_divisor) : 173;

                // Tunjangan tetap & tidak tetap untuk slip
                $slipTotTetap = 0.0;
                $slipTotTidakTetap = 0.0;
                if (!empty($components)) {
                    foreach ($components as $sc) {
                        $sTipe = strtolower($sc['tipe'] ?? '');
                        if ($sTipe === 'revenue' || $sTipe === 'allowance') {
                            $sVal = floatval($sc['value'] ?? $sc['nominal'] ?? 0);
                            $sSifat = strtolower($sc['sifat_kompensasi'] ?? 'tetap');
                            if ($sSifat === 'tidak_tetap') {
                                $slipTotTidakTetap += $sVal;
                            } else {
                                $slipTotTetap += $sVal;
                            }
                        }
                    }
                }

                if ($clientManualOtRateSlip > 0) {
                    $upahSejamSlip = $clientManualOtRateSlip;
                } elseif ($schemeOtRate > 0) {
                    $upahSejamSlip = $schemeOtRate;
                } else {
                    $slipOtRes = $this->hitungDasarUpahLembur(
                        $unproratedGpSlip,
                        $slipTotTetap,
                        $slipTotTidakTetap,
                        floatval($minimumWage ?: ($umkWageValue ?: $umpWageValue)),
                        $otDivisorSlip
                    );
                    $upahSejamSlip = $slipOtRes['upah_per_jam'];
                }

                // Resolve calendar month range
                $calPrevMonth = intval($period->month) - 1;
                $calPrevYear = intval($period->year);
                if ($calPrevMonth == 0) {
                    $calPrevMonth = 12;
                    $calPrevYear--;
                }
                $calStartDateStr = sprintf('%04d-%02d-01', $calPrevYear, $calPrevMonth);
                $calEndDateStr = date('Y-m-t', strtotime($calStartDateStr));
                $calEffectiveStartDateStr = !empty($emp->tgl_masuk) ? max($calStartDateStr, date('Y-m-d', strtotime($emp->tgl_masuk))) : $calStartDateStr;

                $otLogsQuery = [];
                if ($emp && isset($emp->id)) {
                    $otLogsQuery = $this->db->table('overtime_logs')
                        ->where('employee_id', $emp->id)
                        ->where('tanggal >=', $calEffectiveStartDateStr)
                        ->where('tanggal <=', $calEndDateStr)
                        ->where('status', 'Approved')
                        ->where('(is_rapel = 0 OR is_rapel IS NULL)')
                        ->get()
                        ->getResultArray();
                }

                if (!empty($otLogsQuery)) {
                    $konversiBiasa = 0.0;
                    $konversiLibur = 0.0;
                    foreach ($otLogsQuery as $otLog) {
                        $otHours = floatval($otLog['jam_overtime']);
                        $isOtHoliday = isset($otLog['is_holiday']) && intval($otLog['is_holiday']) === 1;
                        if ($isOtHoliday) {
                            $konversiLibur += $this->hitungJamOvertimeKonversi($otHours, true, $wdPerWeekSlip, true);
                        } else {
                            $konversiBiasa += $this->hitungJamOvertimeKonversi($otHours, false, $wdPerWeekSlip, true);
                        }
                    }
                } else {
                    $konversiBiasa = $this->hitungJamOvertimeKonversi($jamOvertimeBiasa, false, $wdPerWeekSlip, false);
                    $konversiLibur = $this->hitungJamOvertimeKonversi($jamOvertimeLibur, true, $wdPerWeekSlip, false);
                }

                if ($jamOvertimeBiasa > 0 && $jamOvertimeLibur > 0) {
                    $payBiasa = round($konversiBiasa * $upahSejamSlip, 2);
                    $payLibur = $overtimePayTotal - $payBiasa;
                    
                    $earnings[] = [
                        'nama' => 'Overtime Working Days (' . $jamOvertimeBiasa . ' jam → ' . $konversiBiasa . 'x upah perjam)',
                        'value' => $payBiasa
                    ];
                    $earnings[] = [
                        'nama' => 'Overtime Holiday (' . $jamOvertimeLibur . ' jam → ' . $konversiLibur . 'x upah perjam)',
                        'value' => $payLibur
                    ];
                } else if ($jamOvertimeBiasa > 0) {
                    $earnings[] = [
                        'nama' => 'Overtime Working Days (' . $jamOvertimeBiasa . ' jam → ' . $konversiBiasa . 'x upah perjam)',
                        'value' => $overtimePayTotal
                    ];
                } else if ($jamOvertimeLibur > 0) {
                    $earnings[] = [
                        'nama' => 'Overtime Holiday (' . $jamOvertimeLibur . ' jam → ' . $konversiLibur . 'x upah perjam)',
                        'value' => $overtimePayTotal
                    ];
                }
            } else if ($overtimePayTotal > 0) {
                // Fallback: no breakdown, show single line
                $earnings[] = ['nama' => 'Overtime', 'value' => $overtimePayTotal];
            } else if (floatval($att['jam_overtime'] ?? 0) > 0) {
                // Legacy fallback: calculate from attendance
                $earnings[] = ['nama' => 'Overtime', 'value' => floatval($att['jam_overtime']) * $otRate];
            }

            if (isset($final['bonus_tambahan']) && floatval($final['bonus_tambahan']) > 0) {
                $earnings[] = ['nama' => 'Bonus/Lainnya', 'value' => floatval($final['bonus_tambahan'])];
            } else if ($att['bonus_tambahan'] > 0) {
                $earnings[] = ['nama' => 'Bonus/Lainnya', 'value' => $att['bonus_tambahan']];
            }

            if (isset($final['early_arrival_pay']) && floatval($final['early_arrival_pay']) > 0) {
                $earnings[] = [
                    'nama' => 'Early Arrival (' . round(intval($final['early_arrival_minutes']) / 60) . ' jam)',
                    'value' => floatval($final['early_arrival_pay'])
                ];
            }
            
            if (array_key_exists('deduction_absen', $final) && $final['deduction_absen'] !== null) {
                $deductionAbsenDb = floatval($final['deduction_absen']);
                if ($deductionAbsenDb > 0) {
                    $deductions[] = ['nama' => 'Absence Deduction', 'value' => $deductionAbsenDb];
                }
            } else if (!$isAbsenTidakPotong) {
                $deduction_absen = floatval($att['deduction_absensi'] ?? 0);
                if ($deduction_absen == 0) {
                    $hasDailyLogs = ($emp && !empty($emp->id)) ? ($this->db->table('attendance_logs')
                                             ->where('employee_id', $emp->id)
                                             ->where('log_date >=', $startDateStr)
                                             ->where('log_date <=', $endDateStr)
                                             ->countAllResults() > 0) : false;
                    if (!$hasDailyLogs) {
                        $missingDays = max(0, $stdWorkingDays - intval($att['hari_kerja'] ?? 0));
                        if ($missingDays > 0) {
                            if ($isProrate) {
                                $deduction_absen = $unproratedGajiPokok * ($missingDays / $stdWorkingDays);
                            } else {
                                $nominalDeduction = ($nominalDeductionAbsen > 0) ? $nominalDeductionAbsen : (($absenceConfig && isset($absenceConfig->nominal_deduction)) ? floatval($absenceConfig->nominal_deduction) : 0);
                                $deduction_absen = $missingDays * $nominalDeduction;
                            }
                        }
                    }
                }
                if ($deduction_absen > 0) {
                    $deductions[] = ['nama' => 'Absence Deduction', 'value' => $deduction_absen];
                }
            }
        }

        // Match payroll scheme template for organizational matching
        // Scheme template is already resolved at the beginning of the function

        $workingDays = ($att && isset($att['hari_kerja']) && $att['hari_kerja'] !== null) ? intval($att['hari_kerja']) : $stdWorkingDays;

        if ($schemeTemplate) {
            $schemeAllowances = [
                'Allowance Transport' => floatval($schemeTemplate['allowance_transport'] ?? 0),
                'Allowance Makan Dayan' => floatval($schemeTemplate['allowance_makan'] ?? 0),
                'Allowance Komunikasi' => floatval($schemeTemplate['allowance_komunikasi'] ?? 0),
                'Allowance Position' => floatval($schemeTemplate['allowance_position'] ?? 0),
                'Allowance Kehadiran' => floatval($schemeTemplate['allowance_kehadiran'] ?? 0),
                'Allowance Kinerja' => floatval($schemeTemplate['allowance_kinerja'] ?? 0),
            ];

            foreach ($schemeAllowances as $allowanceName => $allowanceValue) {
                if ($allowanceValue > 0) {
                    if ($allowanceName === 'Allowance Makan Dayan') {
                        $finalValue = $allowanceValue * $workingDays;
                    } else {
                        $finalValue = $allowanceValue;
                    }

                    $earnings[] = ['nama' => $allowanceName, 'value' => $finalValue];
                }
            }

            $schemeDeductions = [
                'Deduction Pinjaman' => floatval($schemeTemplate['deduction_pinjaman'] ?? 0),
                'Deduction Kasbon' => floatval($schemeTemplate['deduction_kasbon'] ?? 0),
                'Other Deductionsnya' => floatval($schemeTemplate['deduction_lainnya'] ?? 0),
            ];

            foreach ($schemeDeductions as $deductionName => $deductionValue) {
                if ($deductionValue > 0) {
                    $deductions[] = ['nama' => $deductionName, 'value' => $deductionValue];
                }
            }
        }

        // Resolve BPJS Rates dynamically
        $taxScheme = null;
        if ($clientConfig && $clientConfig->tax_scheme_id) {
            $taxScheme = $this->db->table('tax_schemes')->where('id', $clientConfig->tax_scheme_id)->get()->getRow();
        }
        $bpjsScheme = null;
        if ($clientConfig && !empty($clientConfig->bpjs_scheme_id)) {
            $bpjsScheme = $this->db->table('tax_schemes')->where('id', $clientConfig->bpjs_scheme_id)->get()->getRow();
        }
        if (!$bpjsScheme) {
            $bpjsScheme = $taxScheme;
        }

        $bpjsSrc = $bpjsScheme ?: ($schemeTemplate ?: $taxScheme);
        $isBpjsTemplate = is_array($bpjsSrc);

        $kesRateEmp = 1.0;
        $jhtRateEmp = 2.0;
        $jpRateEmp = 1.0;
        $kesRateComp = 4.0;
        $jhtRateComp = 3.7;
        $jpRateComp = 2.0;
        $jkkRateComp = 0.24;
        $jkmRateComp = 0.30;

        if ($bpjsSrc) {
            $kesRateEmp = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_kes_karyawan'] ?? 1.0) : ($bpjsSrc->bpjs_kes_karyawan ?? 1.0));
            $jhtRateEmp = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_jht_karyawan'] ?? 2.0) : ($bpjsSrc->bpjs_jht_karyawan ?? 2.0));
            $jpRateEmp = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_jp_karyawan'] ?? 1.0) : ($bpjsSrc->bpjs_jp_karyawan ?? 1.0));
            $kesRateComp = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_kes_company'] ?? 4.0) : ($bpjsSrc->bpjs_kes_company ?? 4.0));
            $jhtRateComp = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_jht_company'] ?? 3.7) : ($bpjsSrc->bpjs_jht_company ?? 3.7));
            $jpRateComp = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_jp_company'] ?? 2.0) : ($bpjsSrc->bpjs_jp_company ?? 2.0));
            $jkkRateComp = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_jkk_company'] ?? 0.24) : ($bpjsSrc->bpjs_jkk_company ?? 0.24));
            $jkmRateComp = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_jkm_company'] ?? 0.30) : ($bpjsSrc->bpjs_jkm_company ?? 0.30));
        }

        // Add BPJS and PPh 21 detailed lines
        if (isset($final['bpjs_kes_karyawan'])) {
            if (floatval($final['bpjs_kes_karyawan']) > 0) {
                $deductions[] = ['nama' => 'BPJS Health (' . floatval($kesRateEmp) . '% Karyawan)', 'value' => floatval($final['bpjs_kes_karyawan'])];
            }
            if (floatval($final['bpjs_jht_karyawan']) > 0) {
                $deductions[] = ['nama' => 'BPJS TK JHT (' . floatval($jhtRateEmp) . '% Karyawan)', 'value' => floatval($final['bpjs_jht_karyawan'])];
            }
            if (floatval($final['bpjs_jp_karyawan']) > 0) {
                $deductions[] = ['nama' => 'BPJS TK JP (' . floatval($jpRateEmp) . '% Karyawan)', 'value' => floatval($final['bpjs_jp_karyawan'])];
            }

            if (floatval($final['pph21']) != 0) {
                $isDecember = (intval($final['month'] ?? 0) === 12);
                $pphVal = floatval($final['pph21']);
                
                if ($isDecember) {
                    $allowanceLabel = $pphVal < 0 ? 'Penyesuaian Allowance Tax (Gross Up Pasal 17)' : 'Allowance Tax (Gross Up Pasal 17)';
                    $taxLabel = $pphVal < 0 ? 'Kelebihan Deduction PPh 21 (Refund)' : 'Deduction Tax PPh 21 (Pasal 17)';
                } else {
                    $ptkpCategory = $this->determineTerCategory($final['ptkp_status'] ?? 'TK/0');
                    
                    // Re-calculate bruto to get TER rate
                    $taxableEarnings = 0;
                    if (isset($earnings)) {
                        foreach ($earnings as $earn) {
                            if ($earn['nama'] !== 'Allowance Tax (Gross Up TER)' && $earn['nama'] !== 'Allowance Tax (Gross Up Pasal 17)') {
                                $taxableEarnings += floatval($earn['value']);
                            }
                        }
                    }
                    if (isset($final['deduction_absen'])) {
                        $taxableEarnings -= floatval($final['deduction_absen']);
                    }
                    
                    $bpjsCoPremiums = floatval($final['bpjs_jkk_company'] ?? 0) + floatval($final['bpjs_jkm_company'] ?? 0);
                    $brutoTax = $taxableEarnings + $bpjsCoPremiums;
                    
                    if ($final['tax_method'] === 'Gross Up') {
                        $brutoTax += floatval($final['tax_allowance'] ?? 0);
                    }
                    
                    $terRate = $this->getTerRate($ptkpCategory, $brutoTax);
                    
                    $allowanceLabel = 'Allowance Tax (Gross Up TER Kategori ' . $ptkpCategory . ' - ' . floatval($terRate) . '%)';
                    $taxLabel = 'Deduction Tax PPh 21 (TER Kategori ' . $ptkpCategory . ' - ' . floatval($terRate) . '%)';
                }

                if ($final['tax_method'] === 'Gross Up') {
                    $earnings[] = ['nama' => $allowanceLabel, 'value' => floatval($final['tax_allowance'])];
                    $deductions[] = ['nama' => $taxLabel, 'value' => floatval($final['pph21'])];
                } else {
                    $deductions[] = ['nama' => $taxLabel, 'value' => floatval($final['pph21'])];
                }
            }
        }

        // Add company burdens (informational)
        $companyBurdens = [];
        if (isset($final['bpjs_kes_company'])) {
            if (floatval($final['bpjs_kes_company']) > 0) {
                $companyBurdens[] = ['nama' => 'BPJS Health (' . floatval($kesRateComp) . '% Dependents Perusahaan)', 'value' => floatval($final['bpjs_kes_company'])];
            }
            if (floatval($final['bpjs_jht_company']) > 0) {
                $companyBurdens[] = ['nama' => 'BPJS TK JHT (' . floatval($jhtRateComp) . '% Dependents Perusahaan)', 'value' => floatval($final['bpjs_jht_company'])];
            }
            if (floatval($final['bpjs_jp_company']) > 0) {
                $companyBurdens[] = ['nama' => 'BPJS TK JP (' . floatval($jpRateComp) . '% Dependents Perusahaan)', 'value' => floatval($final['bpjs_jp_company'])];
            }
            if (floatval($final['bpjs_jkk_company']) > 0) {
                $companyBurdens[] = ['nama' => 'BPJS TK JKK (' . floatval($jkkRateComp) . '% Dependents Perusahaan)', 'value' => floatval($final['bpjs_jkk_company'])];
            }
            if (floatval($final['bpjs_jkm_company']) > 0) {
                $companyBurdens[] = ['nama' => 'BPJS TK JKM (' . floatval($jkmRateComp) . '% Dependents Perusahaan)', 'value' => floatval($final['bpjs_jkm_company'])];
            }
        }

        return $this->respond([
            'info' => $final,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'company_burdens' => $companyBurdens
        ]);
    }

    public function exportPayrollCsv($periodId)
    {
        $clientId = $this->request->getGet('client_id');
        if ($deny = $this->guardPeriodAccess($periodId, $clientId)) return $deny;
        
        $period = $this->db->table('payroll_periods')->where('id', $periodId)->get()->getRow();
        $tYear = $period ? intval($period->year) : intval(date('Y'));
        $tMonth = $period ? intval($period->month) : intval(date('n'));
        $totalDaysInPeriod = intval(date('t', mktime(0, 0, 0, $tMonth, 1, $tYear)));

        // Pre-fetch holiday_calendar for the period month
        $expPeriodMonthStart = sprintf('%04d-%02d-01', $tYear, $tMonth);
        $expPeriodMonthEnd = date('Y-m-t', strtotime($expPeriodMonthStart));
        $expHolidayRows = $this->db->table('holiday_calendar')
                                   ->where('tanggal >=', $expPeriodMonthStart)
                                   ->where('tanggal <=', $expPeriodMonthEnd)
                                   ->get()->getResultArray();
        $expHolidaysInPeriod = [];
        foreach ($expHolidayRows as $h) {
            $expHolidaysInPeriod[$h['tanggal']] = true;
        }

        $query = $this->db->table('payroll_final')
                          ->select('
                              payroll_final.*, 
                              pkwt.employee_name, 
                              pkwt.position_name, 
                              pkwt.tipe_perjanjian, 
                              pkwt.client_id, 
                              clients.nama as client_name,
                              employees.employ_id,
                              employees.tempat_lahir,
                              employees.tanggal_lahir,
                              employees.npwp,
                              employees.tgl_masuk as join_date,
                              employees.start_contract as emp_start_contract,
                              pkwt.start_date as pkwt_start_date,
                              payroll_attendance.hari_kerja as working_days,
                              employees.hari_kerja as emp_hari_kerja,
                              employees.custom_standard_days as emp_custom_standard_days,
                              positions.hari_kerja as position_hari_kerja,
                              divisions.nama as division_name,
                              departments.nama as department_name,
                              work_locations.lokasi_kerja as location_name,
                              minimum_wages.nominal as min_wage
                          ')
                          ->join('pkwt', 'pkwt.id = payroll_final.pkwt_id')
                          ->join('clients', 'clients.id = pkwt.client_id')
                          ->join('employees', 'employees.nama = pkwt.employee_name AND employees.client_id = pkwt.client_id', 'left')
                          ->join('positions', 'positions.id = employees.position_id', 'left')
                          ->join('departments', 'departments.id = positions.department_id', 'left')
                          ->join('divisions', 'divisions.id = departments.division_id', 'left')
                          ->join('work_locations', 'work_locations.id = employees.work_location_id', 'left')
                          ->join('minimum_wages', 'minimum_wages.id = employees.minimum_wage_id', 'left')
                          ->join('payroll_attendance', 'payroll_attendance.period_id = payroll_final.period_id AND payroll_attendance.pkwt_id = payroll_final.pkwt_id', 'left')
                          ->where('payroll_final.period_id', $periodId);
                          
        if ($clientId) {
            $query->where('pkwt.client_id', $clientId);
        }
        
        $results = $query->get()->getResultArray();

        foreach ($results as &$row) {
            $rawJoinDate = !empty($row['join_date']) ? $row['join_date'] : (!empty($row['pkwt_start_date']) ? $row['pkwt_start_date'] : (!empty($row['emp_start_contract']) ? $row['emp_start_contract'] : null));
            $row['join_date'] = $rawJoinDate ? date('Y-m-d', strtotime($rawJoinDate)) : '-';
            $row['working_days'] = (isset($row['working_days']) && $row['working_days'] !== null) ? floatval($row['working_days']) : 0;
            $row['total_days_in_period'] = $totalDaysInPeriod;

            // Compute Payable Days (accurate: excludes weekends + holiday_calendar)
            $wdc = intval($row['emp_hari_kerja'] ?? 5);
            if ($wdc <= 0) $wdc = intval($row['position_hari_kerja'] ?? 5);
            if ($wdc <= 0) $wdc = 5;

            if (isset($row['emp_custom_standard_days']) && intval($row['emp_custom_standard_days']) > 0) {
                $row['payable_days'] = intval($row['emp_custom_standard_days']);
            } else {
                $pdCount = 0;
                $sTs = strtotime($expPeriodMonthStart);
                $eTs = strtotime($expPeriodMonthEnd);
                for ($c = $sTs; $c <= $eTs; $c = strtotime('+1 day', $c)) {
                    $cd = date('Y-m-d', $c);
                    if (isset($expHolidaysInPeriod[$cd])) continue;
                    $dw = intval(date('w', $c));
                    if ($wdc === 5) { if ($dw !== 0 && $dw !== 6) $pdCount++; }
                    elseif ($wdc === 6) { if ($dw !== 0) $pdCount++; }
                    else { $pdCount++; }
                }
                $row['payable_days'] = $pdCount;
            }

            if (isset($row['payable_days']) && floatval($row['working_days']) > floatval($row['payable_days'])) {
                $row['working_days'] = floatval($row['payable_days']);
            }

            $row['target_work_hours'] = floatval($row['payable_days']) * 8.0;

            // Query actual worked hours from attendance_logs for this employee in this period
            $actualHours = 0.0;
            if (!empty($row['employee_name']) && !empty($row['client_id'])) {
                $empObj = $this->db->table('employees')
                    ->select('id')
                    ->where('nama', $row['employee_name'])
                    ->where('client_id', $row['client_id'])
                    ->get()->getRow();
                if ($empObj) {
                    $hrsRow = $this->db->table('attendance_logs')
                        ->selectSum('calculated_work_hours')
                        ->where('employee_id', $empObj->id)
                        ->where('log_date >=', $expPeriodMonthStart)
                        ->where('log_date <=', $expPeriodMonthEnd)
                        ->get()->getRow();
                    if ($hrsRow && floatval($hrsRow->calculated_work_hours) > 0) {
                        $actualHours = floatval($hrsRow->calculated_work_hours);
                    }
                }
            }
            if ($actualHours <= 0 && floatval($row['working_days']) > 0) {
                $actualHours = floatval($row['working_days']) * 8.0;
            }
            $row['actual_work_hours'] = $actualHours;

            // Resolve Scheme Name
            $schemeName = '-';
            $clientConfig = $this->resolveClientConfig($row['client_id'], $row['position_name'] ?? null);
            if ($clientConfig) {
                if ($clientConfig->payroll_type === 'Nominal') {
                    $schemeName = 'Nominal (Rp ' . number_format($clientConfig->custom_nominal, 0, ',', '.') . ')';
                } elseif ($clientConfig->payroll_type === 'UMP/UMK' || $clientConfig->payroll_type === 'UMP' || $clientConfig->payroll_type === 'UMK') {
                    $schemeName = $clientConfig->payroll_type;
                } elseif ($clientConfig->payroll_type === 'Template' && $clientConfig->payroll_scheme_id) {
                    $payrollScheme = $this->db->table('payroll_schemes')
                                              ->where('id', $clientConfig->payroll_scheme_id)
                                              ->get()->getRow();
                    if ($payrollScheme) {
                        $schemeName = $payrollScheme->nama;
                    }
                }
            }
            $row['scheme_name'] = $schemeName;
        }
        
        if ($this->request->getGet('format') === 'json') {
            return $this->respond($results);
        }
        
        // Define file name
        $periodInfo = $this->db->table('payroll_periods')->where('id', $periodId)->get()->getRow();
        $periodInfo = $this->formatPeriodRow($periodInfo);
        $periodName = $periodInfo ? $periodInfo->nama : 'Unknown_Period';
        $filename = "Payroll_Report_" . str_replace(' ', '_', $periodName) . ".csv";
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        
        // Open output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM to fix UTF-8 in Excel
        fputs($output, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF)));

        // Write Header Row
        fputcsv($output, [
            'No', 
            'Company / Client',
            'Employee ID (NIK)',
            'Employee Name', 
            'Place & Date of Birth',
            'NPWP',
            'Status PTKP',
            'Division',
            'Department',
            'Position / Role', 
            'Work Location',
            'Basic Salary', 
            'Overtime Pay',
            'Early Arrival',
            'Rapel',
            'Allowance Lainnya/Bonus',
            'Total Income (Revenue)', 
            'Absence Deduction',
            'BPJS Kes (Karyawan)',
            'BPJS JHT (Karyawan)',
            'BPJS JP (Karyawan)',
            'Tax (PPh21)',
            'Other Deductionsnya',
            'Total Deductions (Deduction)', 
            'Take Home Pay', 
            'BPJS Kes (Perusahaan)',
            'BPJS JHT (Perusahaan)',
            'BPJS JP (Perusahaan)',
            'BPJS JKK (Perusahaan)',
            'BPJS JKM (Perusahaan)',
            'Total BPJS (Perusahaan)',
            'Status'
        ], ';');
        
        // Write Data Rows
        $no = 1;
        $totalGp = 0;
        $totalOt = 0;
        $totalEa = 0;
        $totalRapel = 0;
        $totalAllowanceLainnya = 0;
        $totalTotalRevenue = 0;
        $totalPotAbsen = 0;
        $totalBpjsKes = 0;
        $totalBpjsJht = 0;
        $totalBpjsJp = 0;
        $totalPph21 = 0;
        $totalDeductionLainnya = 0;
        $totalTotalDeduction = 0;
        $totalThp = 0;
        $totalBpjsKesCo = 0;
        $totalBpjsJhtCo = 0;
        $totalBpjsJpCo = 0;
        $totalBpjsJkkCo = 0;
        $totalBpjsJkmCo = 0;
        $totalTotalBpjsCo = 0;

        foreach ($results as $row) {
            $placeDob = '';
            if (!empty($row['tempat_lahir']) && !empty($row['tanggal_lahir'])) {
                $placeDob = $row['tempat_lahir'] . ', ' . $row['tanggal_lahir'];
            } elseif (!empty($row['tempat_lahir'])) {
                $placeDob = $row['tempat_lahir'];
            } elseif (!empty($row['tanggal_lahir'])) {
                $placeDob = $row['tanggal_lahir'];
            }
            
            $gp = floatval($row['gaji_pokok'] ?? 0);
            $ot = floatval($row['overtime_pay'] ?? 0);
            $ea = floatval($row['early_arrival_pay'] ?? 0);
            $taxAllowance = floatval($row['tax_allowance'] ?? 0);
            $totalRevenue = floatval($row['total_revenue'] ?? 0);
            
            // Extract rapel
            $rapelVal = 0.0;
            if (!empty($row['raw_components'])) {
                try {
                    $comps = json_decode($row['raw_components'], true);
                    if (is_array($comps)) {
                        foreach ($comps as $c) {
                            if (isset($c['nama']) && stripos($c['nama'], 'rapel') !== false) {
                                $rapelVal += floatval($c['value'] ?? 0);
                            }
                        }
                    }
                } catch (\Exception $e) {
                }
            }
            
            $allowanceLainnya = $totalRevenue - ($gp + $ot + $ea + $rapelVal + $taxAllowance);
            if ($allowanceLainnya < 0) $allowanceLainnya = 0;
            
            $potAbsen = floatval($row['deduction_absen'] ?? 0);
            $bpjsKes = floatval($row['bpjs_kes_karyawan'] ?? 0);
            $bpjsJht = floatval($row['bpjs_jht_karyawan'] ?? 0);
            $bpjsJp = floatval($row['bpjs_jp_karyawan'] ?? 0);
            $pph21 = floatval($row['pph21'] ?? 0);
            $totalDeduction = floatval($row['total_deduction'] ?? 0);
            
            $taxMethod = $row['tax_method'] ?? 'Gross';
            $taxDikurangi = ($taxMethod === 'Net') ? 0 : $pph21;
            
            $deductionLainnya = $totalDeduction - ($potAbsen + $bpjsKes + $bpjsJht + $bpjsJp + $taxDikurangi);
            if ($deductionLainnya < 0) $deductionLainnya = 0;
            
            $thp = floatval($row['take_home_pay'] ?? 0);
            
            $bpjsKesCo = floatval($row['bpjs_kes_company'] ?? 0);
            $bpjsJhtCo = floatval($row['bpjs_jht_company'] ?? 0);
            $bpjsJpCo = floatval($row['bpjs_jp_company'] ?? 0);
            $bpjsJkkCo = floatval($row['bpjs_jkk_company'] ?? 0);
            $bpjsJkmCo = floatval($row['bpjs_jkm_company'] ?? 0);
            $totalBpjsCo = $bpjsKesCo + $bpjsJhtCo + $bpjsJpCo + $bpjsJkkCo + $bpjsJkmCo;

            $totalGp += $gp;
            $totalOt += $ot;
            $totalEa += $ea;
            $totalRapel += $rapelVal;
            $totalAllowanceLainnya += $allowanceLainnya;
            $totalTotalRevenue += $totalRevenue;
            $totalPotAbsen += $potAbsen;
            $totalBpjsKes += $bpjsKes;
            $totalBpjsJht += $bpjsJht;
            $totalBpjsJp += $bpjsJp;
            $totalPph21 += $pph21;
            $totalDeductionLainnya += $deductionLainnya;
            $totalTotalDeduction += $totalDeduction;
            $totalThp += $thp;
            $totalBpjsKesCo += $bpjsKesCo;
            $totalBpjsJhtCo += $bpjsJhtCo;
            $totalBpjsJpCo += $bpjsJpCo;
            $totalBpjsJkkCo += $bpjsJkkCo;
            $totalBpjsJkmCo += $bpjsJkmCo;
            $totalTotalBpjsCo += $totalBpjsCo;
            
            fputcsv($output, [
                $no++,
                $row['client_name'] ?? '-',
                $row['employ_id'] ?? '-',
                $row['employee_name'] ?? '-',
                $placeDob ?: '-',
                $row['npwp'] ?? '-',
                $row['ptkp_status'] ?? 'TK/0',
                $row['division_name'] ?? '-',
                $row['department_name'] ?? '-',
                $row['position_name'] ?? '-',
                $row['location_name'] ?? '-',
                $gp,
                $ot,
                $ea,
                $rapelVal,
                $allowanceLainnya,
                $totalRevenue,
                $potAbsen,
                $bpjsKes,
                $bpjsJht,
                $bpjsJp,
                $pph21,
                $deductionLainnya,
                $totalDeduction,
                $thp,
                $bpjsKesCo,
                $bpjsJhtCo,
                $bpjsJpCo,
                $bpjsJkkCo,
                $bpjsJkmCo,
                $totalBpjsCo,
                $row['status_approval'] ?? 'Pending'
            ], ';');
        }

        // Write Summary Row
        fputcsv($output, [
            'TOTAL',
            '',
            '',
            '',
            '',
            '',
            '', // status ptkp
            '',
            '',
            '',
            '',
            $totalGp,
            $totalOt,
            $totalEa,
            $totalRapel,
            $totalAllowanceLainnya,
            $totalTotalRevenue,
            $totalPotAbsen,
            $totalBpjsKes,
            $totalBpjsJht,
            $totalBpjsJp,
            $totalPph21,
            $totalDeductionLainnya,
            $totalTotalDeduction,
            $totalThp,
            $totalBpjsKesCo,
            $totalBpjsJhtCo,
            $totalBpjsJpCo,
            $totalBpjsJkkCo,
            $totalBpjsJkmCo,
            $totalTotalBpjsCo,
            ''
        ], ';');
        
        fclose($output);
        exit;
    }


    // --- EMPLOYEES ---
    public function getEmployees()
    {
        $clientId = $this->request->getGet('client_id');
        $query = $this->db->table('positions')
                             ->select('positions.*, departments.nama as department_name, divisions.nama as division_name')
                             ->join('departments', 'departments.id = positions.department_id')
                             ->join('divisions', 'divisions.id = departments.division_id');
        if ($clientId) {
            $query->where('divisions.client_id', $clientId);
        }
        $employees = $query->get()->getResult();
        return $this->respond($employees);
    }

    // --- CLIENT COMPENSATIONS ---
    public function getCompensations($clientId)
    {
        $data = $this->db->table('client_compensations')
                         ->where('client_id', $clientId)
                         ->orderBy('id', 'ASC')
                         ->get()
                         ->getResultArray();
        return $this->respond($data);
    }

    public function createCompensation()
    {
        $data = $this->request->getJSON(true);
        $this->db->table('client_compensations')->insert($data);
        return $this->respondCreated(['message' => 'Component kompensasi added successfully']);
    }

    public function updateCompensation($id)
    {
        $data = $this->request->getJSON(true);
        unset($data['id']);
        $this->db->table('client_compensations')->where('id', $id)->update($data);
        return $this->respond(['message' => 'Component kompensasi updated successfully']);
    }

    public function deleteCompensation($id)
    {
        $this->db->table('client_compensations')->where('id', $id)->delete();
        return $this->respondDeleted(['message' => 'Component kompensasi deleted successfully']);
    }

    // --- CLIENT ABSENCE CONFIG ---
    public function getAbsenceConfig($clientId)
    {
        $data = $this->db->table('client_absence_configs')
                         ->where('client_id', $clientId)
                         ->get()
                         ->getRowArray();
        return $this->respond($data ?: []);
    }

    public function saveAbsenceConfig()
    {
        $data = $this->request->getJSON(true);
        $clientId = $data['client_id'];

        $existing = $this->db->table('client_absence_configs')
                             ->where('client_id', $clientId)
                             ->get()
                             ->getRow();

        if ($existing) {
            $this->db->table('client_absence_configs')->where('client_id', $clientId)->update($data);
        } else {
            $this->db->table('client_absence_configs')->insert($data);
        }

        $this->logActivity("Menyimpan configuration absensi klien ID: " . $clientId);
        return $this->respond(['message' => 'Configuration absensi saved successfully']);
    }

    // --- LOGS ---
    public function getLogs()
    {
        $logs = $this->db->table('status_logs')
                         ->orderBy('id', 'DESC')
                         ->get()
                         ->getResultArray();
        return $this->respond($logs ?: []);
    }

    protected function logActivity($action, $username = null)
    {
        if ($username === null) {
            $username = ($this->request !== null) ? ($this->request->getHeaderLine('X-User-Action') ?: 'System') : 'System';
        }
        
        $this->db->table('status_logs')->insert([
            'description' => $action,
            'user_action' => $username,
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    protected function resolveUmpUmk($minimumWageId, $year = null, $employeeProvince = null)
    {
        $res = ['ump' => 0, 'umk' => 0];
        if (!$minimumWageId) return $res;

        $currentWage = $this->db->table('minimum_wages')->where('id', $minimumWageId)->get()->getRow();
        if (!$currentWage) return $res;

        // Try current year first, then wage record's year
        $currentYear = date('Y');
        $wageYear = $currentWage->tahun ?: $currentYear;
        $year = $year ?: $currentYear;

        if ($currentWage->tipe === 'UMP') {
            // If the stored UMP is for the current year, use it directly
            if ($currentWage->tahun == $currentYear) {
                $res['ump'] = floatval($currentWage->nominal);
            } else {
                // Try to find a more recent UMP for the same region
                $searchName = $currentWage->nama_daerah ?: $currentWage->provinsi;
                $newerUmp = $this->db->table('minimum_wages')
                                     ->where('tipe', 'UMP')
                                     ->where('tahun', $currentYear)
                                     ->groupStart()
                                         ->where('nama_daerah', $searchName)
                                         ->orWhere('provinsi', $searchName)
                                     ->groupEnd()
                                     ->get()
                                     ->getRow();
                $res['ump'] = $newerUmp ? floatval($newerUmp->nominal) : floatval($currentWage->nominal);
            }
            // Fallback UMK to the same value
            $res['umk'] = $res['ump'];
        } else if ($currentWage->tipe === 'UMK') {
            // For UMK, also try to find the current year's UMK
            if ($currentWage->tahun == $currentYear) {
                $res['umk'] = floatval($currentWage->nominal);
            } else {
                $searchName = $currentWage->nama_daerah;
                $newerUmk = $this->db->table('minimum_wages')
                                     ->where('tipe', 'UMK')
                                     ->where('tahun', $currentYear)
                                     ->where('nama_daerah', $searchName)
                                     ->get()
                                     ->getRow();
                $res['umk'] = $newerUmk ? floatval($newerUmk->nominal) : floatval($currentWage->nominal);
            }
            
            // Find corresponding UMP for the province
            // Build list of province names to search for
            $provinceSearchNames = [];
            if (!empty($currentWage->provinsi)) {
                $provinceSearchNames[] = $currentWage->provinsi;
            }
            if (!empty($employeeProvince)) {
                $provinceSearchNames[] = $employeeProvince;
                // Also try uppercase variant
                $provinceSearchNames[] = strtoupper($employeeProvince);
            }
            // Also try to extract province from kode_daerah (e.g. "ID 31.73" -> province code 31)
            if (!empty($currentWage->kode_daerah)) {
                $parts = explode(' ', $currentWage->kode_daerah);
                if (count($parts) >= 2) {
                    $codeParts = explode('.', $parts[1]);
                    $provCode = $codeParts[0]; // e.g. "31"
                    // Look for UMP with matching province code prefix
                    $umpByCode = $this->db->table('minimum_wages')
                                         ->where('tipe', 'UMP')
                                         ->where('tahun', $currentYear)
                                         ->like('kode_daerah', "ID $provCode", 'after')
                                         ->get()
                                         ->getRow();
                    if (!$umpByCode) {
                        // Fallback to wage record's year
                        $umpByCode = $this->db->table('minimum_wages')
                                             ->where('tipe', 'UMP')
                                             ->where('tahun', $wageYear)
                                             ->like('kode_daerah', "ID $provCode", 'after')
                                             ->get()
                                             ->getRow();
                    }
                    if ($umpByCode) {
                        $res['ump'] = floatval($umpByCode->nominal);
                        return $res;
                    }
                }
            }
            
            // Search UMP by province name
            $umpWage = null;
            $yearsToTry = array_unique([$currentYear, $wageYear]);
            foreach ($yearsToTry as $tryYear) {
                foreach ($provinceSearchNames as $provName) {
                    if (empty($provName)) continue;
                    $umpWage = $this->db->table('minimum_wages')
                                        ->where('tipe', 'UMP')
                                        ->where('tahun', $tryYear)
                                        ->groupStart()
                                            ->where('nama_daerah', $provName)
                                            ->orWhere('provinsi', $provName)
                                            ->orWhere('nama_daerah', strtoupper($provName))
                                            ->orWhere('provinsi', strtoupper($provName))
                                        ->groupEnd()
                                        ->get()
                                        ->getRow();
                    if ($umpWage) break 2;
                }
            }
            
            if ($umpWage) {
                $res['ump'] = floatval($umpWage->nominal);
            } else {
                $res['ump'] = $res['umk']; // fallback UMP to UMK value
            }
        }
        return $res;
    }

    public function checkSchema()
    {
        $clientId = $this->request->getGet('client_id');
        $divId = $this->request->getGet('division_id');
        $deptId = $this->request->getGet('department_id');
        $posId = $this->request->getGet('position_id');

        if (!$clientId) return $this->fail('Client ID required');

        // Cek Position
        if ($posId) {
            $config = $this->db->table('client_payroll_configs')->where(['client_id' => $clientId, 'position_id' => $posId])->get()->getRow();
            if ($config) return $this->respond(['level' => 'Position', 'config' => $config]);
        }
        
        // Cek Department
        if ($deptId) {
            $config = $this->db->table('client_payroll_configs')->where(['client_id' => $clientId, 'department_id' => $deptId, 'position_id' => null])->get()->getRow();
            if ($config) return $this->respond(['level' => 'Department', 'config' => $config]);
        }

        // Cek Division
        if ($divId) {
            $config = $this->db->table('client_payroll_configs')->where(['client_id' => $clientId, 'division_id' => $divId, 'department_id' => null, 'position_id' => null])->get()->getRow();
            if ($config) return $this->respond(['level' => 'Division', 'config' => $config]);
        }

        // Cek General Client
        $config = $this->db->table('client_payroll_configs')->where(['client_id' => $clientId, 'division_id' => null, 'department_id' => null, 'position_id' => null])->get()->getRow();
        if ($config) return $this->respond(['level' => 'General Client', 'config' => $config]);

    }

    public function previewPayroll()
    {
        $clientId = $this->request->getGet('client_id');
        $divId = $this->request->getGet('division_id');
        $deptId = $this->request->getGet('department_id');
        $posId = $this->request->getGet('position_id');
        $workLocId = $this->request->getGet('work_location_id');

        if (!$clientId) return $this->fail('Client ID required');

        // Resolve Config
        $config = null;
        $level = 'Tidak Ada';
        
        if ($posId) {
            $config = $this->db->table('client_payroll_configs')
                ->where('client_id', $clientId)
                ->where('position_id', $posId)
                ->get()->getRow();
            if ($config) $level = 'Position';
        }
        if (!$config && $deptId) {
            $config = $this->db->table('client_payroll_configs')
                ->where('client_id', $clientId)
                ->where('department_id', $deptId)
                ->where('position_id IS NULL')
                ->get()->getRow();
            if ($config) $level = 'Department';
        }
        if (!$config && $divId) {
            $config = $this->db->table('client_payroll_configs')
                ->where('client_id', $clientId)
                ->where('division_id', $divId)
                ->where('department_id IS NULL')
                ->where('position_id IS NULL')
                ->get()->getRow();
            if ($config) $level = 'Division';
        }
        if (!$config) {
            $config = $this->db->table('client_payroll_configs')
                ->where('client_id', $clientId)
                ->where('division_id IS NULL')
                ->where('department_id IS NULL')
                ->where('position_id IS NULL')
                ->get()->getRow();
            if ($config) $level = 'General Client';
        }

        if (!$config) {
            return $this->respond(['status' => 'error', 'message' => 'Scheme not found']);
        }

        $effectivePayrollType = $config->payroll_type;
        $resolvedPayrollType = $config->payroll_type;
        if ($config->payroll_type === 'Template' && $config->payroll_scheme_id) {
            $basicComp = $this->db->table('payroll_components')
                ->where('scheme_id', $config->payroll_scheme_id)
                ->groupStart()
                    ->where('jenis_component', 'basic_salary')
                    ->orLike('nama', 'Base Salary')
                ->groupEnd()
                ->get()
                ->getRow();
            if ($basicComp && !empty($basicComp->sumber_value)) {
                if ($basicComp->sumber_value === 'ump') {
                    $resolvedPayrollType = 'UMP';
                    $effectivePayrollType = 'UMP';
                } else if ($basicComp->sumber_value === 'umk') {
                    $resolvedPayrollType = 'UMK';
                    $effectivePayrollType = 'UMK';
                } else if ($basicComp->sumber_value === 'ump_umk') {
                    $resolvedPayrollType = 'UMP/UMK';
                    $effectivePayrollType = 'UMP/UMK';
                }
            }
        }

        $gajiPokok = 0;
        $desc = "Tipe Scheme: {$config->payroll_type}. ";

        if ($config->payroll_type === 'Nominal') {
            $gajiPokok = floatval($config->custom_nominal);
            $desc .= "Menggunakan nominal kustom Rp " . number_format($gajiPokok, 0, ',', '.');
        } else if ($effectivePayrollType === 'UMP/UMK' || $effectivePayrollType === 'UMP' || $effectivePayrollType === 'UMK') {
            $mw = null;
            if ($workLocId) {
                $loc = $this->db->table('work_locations')->where('id', $workLocId)->get()->getRow();
                if ($loc) {
                    $searchCity = !empty($loc->kota_kabupaten) ? trim(strtolower($loc->kota_kabupaten)) : null;
                    $searchProv = !empty($loc->provinsi) ? trim(strtolower($loc->provinsi)) : null;
                    
                    if ($effectivePayrollType === 'UMK') {
                        if ($searchCity) {
                            $mw = $this->db->table('minimum_wages')->where(['tipe' => 'UMK', 'nama_daerah' => $loc->kota_kabupaten])->orderBy('tahun', 'DESC')->get()->getRow();
                            if (!$mw) {
                                $mw = $this->db->table('minimum_wages')->where('tipe', 'UMK')->where('LOWER(nama_daerah)', $searchCity)->orderBy('tahun', 'DESC')->get()->getRow();
                            }
                        }
                    } else if ($effectivePayrollType === 'UMP') {
                        if ($searchProv) {
                            $mw = $this->db->table('minimum_wages')->where(['tipe' => 'UMP', 'nama_daerah' => $loc->provinsi])->orderBy('tahun', 'DESC')->get()->getRow();
                            if (!$mw) {
                                $mw = $this->db->table('minimum_wages')->where('tipe', 'UMP')->where('LOWER(nama_daerah)', $searchProv)->orderBy('tahun', 'DESC')->get()->getRow();
                            }
                        }
                    } else { // UMP/UMK
                        if ($searchCity) {
                            $mw = $this->db->table('minimum_wages')->where(['tipe' => 'UMK', 'nama_daerah' => $loc->kota_kabupaten])->orderBy('tahun', 'DESC')->get()->getRow();
                            if (!$mw) {
                                $mw = $this->db->table('minimum_wages')->where('tipe', 'UMK')->where('LOWER(nama_daerah)', $searchCity)->orderBy('tahun', 'DESC')->get()->getRow();
                            }
                        }
                        if (!$mw && $searchProv) {
                            $mw = $this->db->table('minimum_wages')->where(['tipe' => 'UMP', 'nama_daerah' => $loc->provinsi])->orderBy('tahun', 'DESC')->get()->getRow();
                            if (!$mw) {
                                $mw = $this->db->table('minimum_wages')->where('tipe', 'UMP')->where('LOWER(nama_daerah)', $searchProv)->orderBy('tahun', 'DESC')->get()->getRow();
                            }
                        }
                    }
                    
                    if ($mw) {
                        $desc .= "Mendeteksi lokasi {$mw->nama_daerah}. ";
                    }
                }
            }
            if (!$mw && $config->minimum_wage_id) {
                $mw = $this->db->table('minimum_wages')->where('id', $config->minimum_wage_id)->get()->getRow();
            }
            
            if ($mw) {
                $gajiPokok = floatval($mw->nominal);
                if ($config->payroll_type === 'Template' && isset($basicComp) && in_array($basicComp->sumber_value, ['ump', 'umk', 'ump_umk'])) {
                    $multiplier = floatval($basicComp->value);
                    $gajiPokok = $gajiPokok * ($multiplier / 100);
                    $desc .= "Menggunakan UMR {$mw->nama_daerah} (Rp " . number_format($mw->nominal, 0, ',', '.') . ") dengan multiplier {$multiplier}% menjadi Rp " . number_format($gajiPokok, 0, ',', '.');
                } else {
                    $desc .= "Menggunakan UMR {$mw->nama_daerah} (Rp " . number_format($gajiPokok, 0, ',', '.') . ")";
                }
            } else {
                $desc .= "UMR tidak ditemukan untuk lokasi ini.";
            }
        } else if ($config->payroll_type === 'Template') {
            // Find template components if possible. For now, zero as fallback if no fixed income found.
            $desc .= "Menggunakan Template. Silakan sesuaikan salary components pokok jika diperlukan.";
            $gajiPokok = 0;
        }

        $hariKerja = 5;
        if ($posId) {
            $pos = $this->db->table('positions')->where('id', $posId)->get()->getRow();
            if ($pos && isset($pos->hari_kerja)) {
                $hariKerja = intval($pos->hari_kerja);
            }
        }

        $hariKerjaMonthan = 22;
        if ($hariKerja == 6) $hariKerjaMonthan = 26;
        if ($hariKerja == 7) $hariKerjaMonthan = 30;

        $gajiDayan = $gajiPokok / $hariKerjaMonthan;
        $dendaAbsen = $gajiDayan;

        return $this->respond([
            'status' => 'success',
            'level' => $level,
            'payroll_type' => $resolvedPayrollType,
            'gaji_pokok' => $gajiPokok,
            'hari_kerja' => $hariKerja,
            'gaji_harian' => round($gajiDayan),
            'denda_absen' => round($dendaAbsen),
            'description' => $desc
        ]);
    }

    /**
     * Hitung Jam Overtime Konversi berdasarkan PP No. 35 Year 2021.
     * 
     * Working Days Biasa:
     *   Jam pertama  => x 1.5
     *   Jam ke-2 dst => x 2.0
     * 
     * Holiday / Tanggal Merah (5 working days/minggu):
     *   8 jam pertama => x 2.0
     *   Jam ke-9      => x 3.0
     *   Jam ke-10+    => x 4.0
     * 
     * Holiday / Tanggal Merah (6 working days/minggu):
     *   7 jam pertama => x 2.0
     *   Jam ke-8      => x 3.0
     *   Jam ke-9+     => x 4.0
     *
     * @param float $jamOvertime  Total jam overtime riil
     * @param bool  $isHoliday  Apakah overtime di holiday/tanggal merah
     * @param int   $workingDaysPerWeek  Amount working days per minggu (5 atau 6)
     * @return float Jam overtime konversi (sudah dikalikan multiplier)
     */
    private function hitungJamOvertimeKonversi(float $jamOvertime, bool $isHoliday = false, int $workingDaysPerWeek = 5, bool $applyDailyCap = true): float
    {
        if ($jamOvertime <= 0) {
            return 0.0;
        }

        $jamKonversi = 0.0;

        if (!$isHoliday) {
            // === Overtime di Hari Kerja Biasa (PP 35/2021 Pasal 26 & 31) ===
            // Batas lembur maksimal 4 jam sehari
            if ($applyDailyCap) {
                $jamOvertime = min(4.0, $jamOvertime);
            }

            // Jam ke-1 x 1.5
            $jamPertama = min(1.0, $jamOvertime);
            $jamKonversi += $jamPertama * 1.5;

            // Jam ke-2 dan berikutnya x 2.0 (hapus pengali x 3.0 sesuai PP 35/2021)
            if ($jamOvertime > 1.0) {
                $jamBerikutnya = $jamOvertime - 1.0;
                $jamKonversi += $jamBerikutnya * 2.0;
            }
        } else {
            // === Overtime di Holiday / Tanggal Merah ===
            if ($workingDaysPerWeek == 6) {
                // Scheme 6 working days: 7 jam x2, jam ke-8 x3, jam ke-9+ x4 (maksimal 11 jam)
                $jamOvertime = min(11.0, $jamOvertime);
                $batasAwal = 7.0;
            } else {
                // Scheme 5 working days: 8 jam x2, jam ke-9 x3, jam ke-10 s/d ke-12 x4 (maksimal 12 jam)
                $jamOvertime = min(12.0, $jamOvertime);
                $batasAwal = 8.0;
            }

            if ($jamOvertime <= $batasAwal) {
                $jamKonversi += $jamOvertime * 2.0;
            } else {
                $jamKonversi += $batasAwal * 2.0; // Jam awal

                $remaining = $jamOvertime - $batasAwal;
                if ($remaining <= 1.0) {
                    // Jam transisi x 3.0
                    $jamKonversi += $remaining * 3.0;
                } else {
                    // Jam transisi penuh x 3.0
                    $jamKonversi += 1.0 * 3.0;
                    // Jam selebihnya x 4.0
                    $jamKonversi += ($remaining - 1.0) * 4.0;
                }
            }
        }

        return round($jamKonversi, 2);
    }

    private function getStandardWorkingDays(int $year, int $month, int $workDaysConfig): int
    {
        $db = \Config\Database::connect();
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));
        $holidays = [];
        $holidayRows = $db->table('holiday_calendar')
                          ->where('tanggal >=', $startDate)
                          ->where('tanggal <=', $endDate)
                          ->get()->getResultArray();
        foreach ($holidayRows as $h) {
            $holidays[$h['tanggal']] = true;
        }

        $daysInMonth = date('t', mktime(0, 0, 0, $month, 1, $year));
        $stdDays = 0;
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $currDate = sprintf('%04d-%02d-%02d', $year, $month, $d);
            if (isset($holidays[$currDate])) {
                continue; // Skip holiday calendar dates
            }

            $dayOfWeek = date('w', mktime(0, 0, 0, $month, $d, $year)); // 0 = Sunday, 6 = Saturday
            if ($workDaysConfig === 5) {
                if ($dayOfWeek != 0 && $dayOfWeek != 6) {
                    $stdDays++;
                }
            } elseif ($workDaysConfig === 6) {
                if ($dayOfWeek != 0) {
                    $stdDays++;
                }
            } else {
                $stdDays++;
            }
        }
        return $stdDays;
    }

    private function getCutoffRange($period, $clientConfig)
    {
        $cutoffStart = $clientConfig ? intval($clientConfig->cutoff_start) : 21;
        $cutoffEnd = $clientConfig ? intval($clientConfig->cutoff_end) : 20;
        
        if ($cutoffStart <= 0) $cutoffStart = 1;
        if ($cutoffEnd <= 0) {
            $cutoffEnd = $cutoffStart - 1;
            if ($cutoffEnd < 1) $cutoffEnd = 31;
        }

        $month_start = intval($period->month);
        $year_start = intval($period->year);
        $month_end = $month_start;
        $year_end = $year_start;

        if ($cutoffStart > $cutoffEnd && $cutoffStart > 1) {
            $month_start -= 1;
            if ($month_start < 1) {
                $month_start = 12;
                $year_start -= 1;
            }
        } else if ($cutoffStart == 1) {
            $daysInMonth = date('t', mktime(0, 0, 0, $month_end, 1, $year_end));
            $cutoffEnd = $daysInMonth;
        }

        $daysInStartMonth = intval(date('t', mktime(0, 0, 0, $month_start, 1, $year_start)));
        $daysInEndMonth = intval(date('t', mktime(0, 0, 0, $month_end, 1, $year_end)));
        $safeStart = min($cutoffStart, $daysInStartMonth);
        $safeEnd = min($cutoffEnd, $daysInEndMonth);

        $startDateStr = sprintf('%04d-%02d-%02d', $year_start, $month_start, $safeStart);
        $endDateStr = sprintf('%04d-%02d-%02d', $year_end, $month_end, $safeEnd);

        return [
            'start_date' => $startDateStr,
            'end_date' => $endDateStr,
            'cutoff_start' => $cutoffStart,
            'cutoff_end' => $cutoffEnd
        ];
    }

    private function getStandardWorkingDaysInRange(string $startDate, string $endDate, int $workDaysConfig): int
    {
        $db = \Config\Database::connect();
        $holidays = [];
        $holidayRows = $db->table('holiday_calendar')
                          ->where('tanggal >=', $startDate)
                          ->where('tanggal <=', $endDate)
                          ->get()->getResultArray();
        foreach ($holidayRows as $h) {
            $holidays[$h['tanggal']] = true;
        }

        $startTs = strtotime($startDate);
        $endTs = strtotime($endDate);
        $stdDays = 0;
        for ($curr = $startTs; $curr <= $endTs; $curr = strtotime('+1 day', $curr)) {
            $currDate = date('Y-m-d', $curr);
            if (isset($holidays[$currDate])) {
                continue; // Skip holiday calendar dates
            }

            $dayOfWeek = date('w', $curr);
            if ($workDaysConfig === 5) {
                if ($dayOfWeek != 0 && $dayOfWeek != 6) {
                    $stdDays++;
                }
            } elseif ($workDaysConfig === 6) {
                if ($dayOfWeek != 0) {
                    $stdDays++;
                }
            } else {
                $stdDays++;
            }
        }
        return $stdDays;
    }

    private function getWeekdayHolidaysCount(string $startDate, string $endDate, int $workDaysConfig): int
    {
        $db = \Config\Database::connect();
        $holidayRows = $db->table('holiday_calendar')
                          ->where('tanggal >=', $startDate)
                          ->where('tanggal <=', $endDate)
                          ->get()->getResultArray();
        $numHolidays = 0;
        foreach ($holidayRows as $h) {
            $dayOfWeek = date('w', strtotime($h['tanggal']));
            if ($workDaysConfig === 5) {
                if ($dayOfWeek != 0 && $dayOfWeek != 6) {
                    $numHolidays++;
                }
            } elseif ($workDaysConfig === 6) {
                if ($dayOfWeek != 0) {
                    $numHolidays++;
                }
            } else {
                $numHolidays++;
            }
        }
        return $numHolidays;
    }


    private function calculateBpjsAndTax($gajiPokok, $bpjsWageBase, $pphWageBase, $schemeTemplate, $taxScheme, $minimumWage, $ptkpStatus, $bpjsScheme = null, $month = null, $year = null, $pkwtId = null)
    {
        $result = [
            'bpjs_kes_karyawan' => 0,
            'bpjs_kes_company' => 0,
            'bpjs_jht_karyawan' => 0,
            'bpjs_jht_company' => 0,
            'bpjs_jp_karyawan' => 0,
            'bpjs_jp_company' => 0,
            'bpjs_jkk_company' => 0,
            'bpjs_jkm_company' => 0,
            'pph21' => 0,
            'tax_allowance' => 0,
            'ter_rate' => 0,
            'metode_tax' => 'Gross'
        ];

        // Resolve tax method - System enforced to Gross
        $result['metode_tax'] = 'Gross';

        if ($gajiPokok <= 0) {
            return $result;
        }

        // Determine Rates source
        $bpjsSrc = $bpjsScheme ?: ($schemeTemplate ?: $taxScheme);
        $taxSrc = $schemeTemplate ?: $taxScheme;

        if ($bpjsSrc) {
            $isBpjsTemplate = is_array($bpjsSrc);
            $kesRateEmp = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_kes_karyawan'] ?? 1.0) : ($bpjsSrc->bpjs_kes_karyawan ?? 1.0)) / 100;
            $kesRateCo = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_kes_company'] ?? 4.0) : ($bpjsSrc->bpjs_kes_company ?? 4.0)) / 100;
            $kesMaxSal = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_kes_max_salary'] ?? 12000000) : ($bpjsSrc->bpjs_kes_max_salary ?? 12000000));
            $jhtRateEmp = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_jht_karyawan'] ?? 2.0) : ($bpjsSrc->bpjs_jht_karyawan ?? 2.0)) / 100;
            $jhtRateCo = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_jht_company'] ?? 3.7) : ($bpjsSrc->bpjs_jht_company ?? 3.7)) / 100;
            $jpRateEmp = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_jp_karyawan'] ?? 1.0) : ($bpjsSrc->bpjs_jp_karyawan ?? 1.0)) / 100;
            $jpRateCo = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_jp_company'] ?? 2.0) : ($bpjsSrc->bpjs_jp_company ?? 2.0)) / 100;
            $jpMaxSal = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_jp_max_salary'] ?? 10547400) : ($bpjsSrc->bpjs_jp_max_salary ?? 10547400));
            $jkkRateCo = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_jkk_company'] ?? 0.24) : ($bpjsSrc->bpjs_jkk_company ?? 0.24)) / 100;
            $jkmRateCo = floatval($isBpjsTemplate ? ($bpjsSrc['bpjs_jkm_company'] ?? 0.30) : ($bpjsSrc->bpjs_jkm_company ?? 0.30)) / 100;
        } else {
            // Default BPJS rates
            $kesRateEmp = 0.01;
            $kesRateCo = 0.04;
            $kesMaxSal = 12000000;
            $jhtRateEmp = 0.02;
            $jhtRateCo = 0.037;
            $jpRateEmp = 0.01;
            $jpRateCo = 0.02;
            $jpMaxSal = 10547400;
            $jkkRateCo = 0.0024;
            $jkmRateCo = 0.0030;
        }

        // Apply caps
        $kesWageBase = min($bpjsWageBase, $kesMaxSal);
        $jpWageBase = min($bpjsWageBase, $jpMaxSal);

        // Calculations
        $result['bpjs_kes_karyawan'] = $kesWageBase * $kesRateEmp;
        $result['bpjs_kes_company'] = $kesWageBase * $kesRateCo;

        $result['bpjs_jht_karyawan'] = $bpjsWageBase * $jhtRateEmp;
        $result['bpjs_jht_company'] = $bpjsWageBase * $jhtRateCo;

        $result['bpjs_jp_karyawan'] = $jpWageBase * $jpRateEmp;
        $result['bpjs_jp_company'] = $jpWageBase * $jpRateCo;

        $result['bpjs_jkk_company'] = $bpjsWageBase * $jkkRateCo;
        $result['bpjs_jkm_company'] = $bpjsWageBase * $jkmRateCo;

        // PPh 21 TER 2024 Calculation (UU PPh & PMK 168/2023 Pasal 5 ayat 1)
        // Premi asuransi yang dibayar pemberi kerja (BPJS Kesehatan 4% + JKK 0.24% + JKM 0.3%) adalah objek PPh 21 dan masuk bruto TER
        // JHT company (3.7%) dan JP company (2%) BUKAN objek PPh 21
        $bpjsCoPremiums = $result['bpjs_kes_company'] + $result['bpjs_jkk_company'] + $result['bpjs_jkm_company'];
        
        $ptkpCategory = $this->determineTerCategory($ptkpStatus);

        // Rekonsiliasi Desember: gunakan tarif progresif Pasal 17
        if ($month == 12 && $year && $pkwtId) {
            $decResult = $this->calculateDecemberTaxReconciliation(
                $pkwtId, $year, $pphWageBase, $bpjsCoPremiums,
                $result['bpjs_jht_karyawan'], $result['bpjs_jp_karyawan'],
                $ptkpStatus, 'Gross'
            );
            $result['pph21'] = $decResult['pph21'];
            $result['tax_allowance'] = 0;
            $result['ter_rate'] = 0; // Not applicable for December progressive
            return $result;
        }

        // Gross Tax Method: Tax dipotong dari income bruto
        $brutoTax = $pphWageBase + $bpjsCoPremiums;
        $terRate = $this->getTerRate($ptkpCategory, $brutoTax);
        $result['pph21'] = $brutoTax * ($terRate / 100);
        $result['tax_allowance'] = 0;
        $result['ter_rate'] = $terRate;

        return $result;
    }

    /**
     * Rekonsiliasi PPh 21 Desember - Tarif Progresif Pasal 17 UU HPP
     * Menghitung PPh 21 terutang seyear dikurangi akumulasi PPh 21 Jan-Nov
     * Menggunakan tabel payroll_final + payroll_periods
     */
    private function calculateDecemberTaxReconciliation($pkwtId, $year, $decPphWageBase, $decBpjsCoPremiums, $decJhtKaryawan, $decJpKaryawan, $ptkpStatus, $metodeTax)
    {
        // 1. Ambil data kumulatif Jan-Nov dari payroll_final
        $prevPayrolls = $this->db->table('payroll_final')
            ->select('payroll_final.*')
            ->join('payroll_periods', 'payroll_periods.id = payroll_final.period_id')
            ->where('payroll_final.pkwt_id', $pkwtId)
            ->where('payroll_final.status_approval', 'Approved')
            ->where('payroll_periods.year', $year)
            ->where('payroll_periods.month >=', 1)
            ->where('payroll_periods.month <=', 11)
            ->get()->getResultArray();

        $totalBrutoJanNov = 0;
        $totalPph21JanNov = 0;
        $totalJhtKaryawanJanNov = 0;
        $totalJpKaryawanJanNov = 0;

        foreach ($prevPayrolls as $pp) {
            // Bruto = total_revenue - deduction_absen + bpjs company (kes 4% + jkk + jkm)
            // total_revenue already includes gaji_pokok + allowance + overtime etc.
            $ppBruto = floatval($pp['total_revenue'] ?? 0)
                     - floatval($pp['deduction_absen'] ?? 0)
                     + floatval($pp['bpjs_kes_company'] ?? 0)
                     + floatval($pp['bpjs_jkk_company'] ?? 0)
                     + floatval($pp['bpjs_jkm_company'] ?? 0);
            $totalBrutoJanNov += $ppBruto;
            $totalPph21JanNov += floatval($pp['pph21'] ?? 0);
            $totalJhtKaryawanJanNov += floatval($pp['bpjs_jht_karyawan'] ?? 0);
            $totalJpKaryawanJanNov += floatval($pp['bpjs_jp_karyawan'] ?? 0);
        }

        // 2. Hitung Bruto Desember
        $brutoDesember = $decPphWageBase + $decBpjsCoPremiums;

        // Untuk Gross Up, tambahkan allowance tax iteratif
        $taxAllowanceDec = 0;
        if ($metodeTax === 'Gross Up') {
            for ($i = 0; $i < 10; $i++) {
                $brutoSeyear = $totalBrutoJanNov + $brutoDesember + $taxAllowanceDec;
                $biayaPositionSeyear = min($brutoSeyear * 0.05, 6000000);
                $contributionJhtJpSeyear = $totalJhtKaryawanJanNov + $decJhtKaryawan + $totalJpKaryawanJanNov + $decJpKaryawan;
                $nettoSeyear = $brutoSeyear - $biayaPositionSeyear - $contributionJhtJpSeyear;
                $ptkpSeyear = $this->getPtkpAmount($ptkpStatus);
                $pkpSeyear = max(0, floor(($nettoSeyear - $ptkpSeyear) / 1000) * 1000);
                $pph21Seyear = $this->calculateProgressiveTax($pkpSeyear);
                $pph21Desember = $pph21Seyear - $totalPph21JanNov;
                $taxAllowanceDec = $pph21Desember;
            }
            return [
                'pph21' => $taxAllowanceDec,
                'tax_allowance' => $taxAllowanceDec
            ];
        }

        // 3. Hitung Bruto Seyear (Gross / Nett)
        $brutoSeyear = $totalBrutoJanNov + $brutoDesember;

        // 4. Pengurang: Biaya Position (5% dari bruto, max 6 juta/year)
        $biayaPositionSeyear = min($brutoSeyear * 0.05, 6000000);

        // 5. Pengurang: Contribution JHT + JP Karyawan seyear
        $contributionJhtJpSeyear = $totalJhtKaryawanJanNov + $decJhtKaryawan + $totalJpKaryawanJanNov + $decJpKaryawan;

        // 6. Netto Seyear
        $nettoSeyear = $brutoSeyear - $biayaPositionSeyear - $contributionJhtJpSeyear;

        // 7. PTKP Seyear
        $ptkpSeyear = $this->getPtkpAmount($ptkpStatus);

        // 8. PKP Seyear (dibulatkan ke ribuan ke bawah)
        $pkpSeyear = max(0, floor(($nettoSeyear - $ptkpSeyear) / 1000) * 1000);

        // 9. PPh 21 Terutang Seyear (Tarif Progresif Pasal 17)
        $pph21Seyear = $this->calculateProgressiveTax($pkpSeyear);

        // 10. PPh 21 Desember = PPh 21 Seyear - Total PPh 21 Jan-Nov
        $pph21Desember = $pph21Seyear - $totalPph21JanNov;

        return [
            'pph21' => $pph21Desember,
            'tax_allowance' => 0
        ];
    }

    /**
     * Mengembalikan value PTKP seyear berdasarkan status
     */
    private function getPtkpAmount($ptkpStatus)
    {
        $ptkpStatus = strtoupper(trim($ptkpStatus ?? 'TK/0'));
        $ptkpMap = [
            'TK/0' => 54000000,
            'TK/1' => 58500000,
            'K/0'  => 58500000,
            'TK/2' => 63000000,
            'K/1'  => 63000000,
            'TK/3' => 67500000,
            'K/2'  => 67500000,
            'K/3'  => 72000000,
        ];
        return $ptkpMap[$ptkpStatus] ?? 54000000;
    }

    /**
     * Menghitung PPh 21 dengan tarif progresif Pasal 17 UU HPP
     */
    private function calculateProgressiveTax($pkp)
    {
        if ($pkp <= 0) return 0;

        $tax = 0;
        // Lapisan 1: s/d 60 juta → 5%
        if ($pkp > 0) {
            $layer = min($pkp, 60000000);
            $tax += $layer * 0.05;
            $pkp -= $layer;
        }
        // Lapisan 2: >60 juta s/d 250 juta → 15%
        if ($pkp > 0) {
            $layer = min($pkp, 190000000);
            $tax += $layer * 0.15;
            $pkp -= $layer;
        }
        // Lapisan 3: >250 juta s/d 500 juta → 25%
        if ($pkp > 0) {
            $layer = min($pkp, 250000000);
            $tax += $layer * 0.25;
            $pkp -= $layer;
        }
        // Lapisan 4: >500 juta s/d 5 miliar → 30%
        if ($pkp > 0) {
            $layer = min($pkp, 4500000000);
            $tax += $layer * 0.30;
            $pkp -= $layer;
        }
        // Lapisan 5: >5 miliar → 35%
        if ($pkp > 0) {
            $tax += $pkp * 0.35;
        }

        return $tax;
    }

    private function determineTerCategory($ptkpStatus)
    {
        $ptkpStatus = strtoupper(trim($ptkpStatus ?? 'TK/0'));
        if (in_array($ptkpStatus, ['TK/0', 'TK/1', 'K/0'])) {
            return 'A';
        }
        if (in_array($ptkpStatus, ['TK/2', 'TK/3', 'K/1', 'K/2'])) {
            return 'B';
        }
        if ($ptkpStatus === 'K/3') {
            return 'C';
        }
        return 'A';
    }

    private function getTerRate($category, $bruto)
    {
        if ($category === 'A') {
            $table = [
                [5400000, 0.0],
                [5650000, 0.25],
                [5950000, 0.5],
                [6300000, 0.75],
                [6750000, 1.0],
                [7500000, 1.25],
                [8550000, 1.5],
                [9650000, 1.75],
                [10950000, 2.0],
                [12450000, 2.25],
                [14300000, 2.5],
                [16100000, 3.0],
                [18300000, 3.5],
                [21050000, 4.0],
                [24200000, 4.5],
                [28300000, 5.0],
                [33750000, 6.0],
                [40500000, 7.0],
                [48600000, 8.0],
                [58700000, 9.0],
                [71300000, 10.0],
                [87000000, 11.0],
                [106600000, 12.0],
                [131700000, 13.0],
                [164300000, 14.0],
                [207500000, 15.0],
                [266300000, 16.0],
                [349500000, 17.0],
                [469300000, 18.0],
                [650000000, 19.0],
                [954000000, 20.0],
                [1400000000, 21.0],
                [999999999999, 34.0]
            ];
        } elseif ($category === 'B') {
            $table = [
                [6200000, 0.0],
                [6500000, 0.25],
                [6850000, 0.5],
                [7300000, 0.75],
                [9200000, 1.0],
                [10750000, 1.5],
                [11250000, 2.0],
                [11600000, 2.5],
                [12600000, 3.0],
                [13600000, 4.0],
                [14950000, 5.0],
                [16400000, 6.0],
                [18450000, 7.0],
                [21850000, 8.0],
                [26000000, 9.0],
                [27700000, 10.0],
                [29350000, 11.0],
                [31450000, 12.0],
                [33950000, 13.0],
                [37100000, 14.0],
                [41100000, 15.0],
                [45800000, 16.0],
                [49500000, 17.0],
                [53800000, 18.0],
                [58500000, 19.0],
                [64000000, 20.0],
                [71000000, 21.0],
                [80000000, 22.0],
                [93000000, 23.0],
                [109000000, 24.0],
                [130000000, 25.0],
                [160000000, 26.0],
                [200000000, 27.0],
                [250000000, 28.0],
                [350000000, 29.0],
                [500000000, 31.0],
                [700000000, 32.0],
                [1000000000, 33.0],
                [1405000000, 33.0],
                [999999999999, 34.0]
            ];
        } else {
            $table = [
                [6600000, 0.0],
                [6950000, 0.25],
                [7350000, 0.5],
                [7800000, 0.75],
                [8300000, 1.0],
                [9300000, 1.25],
                [10550000, 1.5],
                [12300000, 1.75],
                [15500000, 2.0],
                [21700000, 2.5],
                [28000000, 3.0],
                [35500000, 4.0],
                [47000000, 5.0],
                [59000000, 6.0],
                [75000000, 7.0],
                [104000000, 8.0],
                [135000000, 9.0],
                [200000000, 10.0],
                [300000000, 11.0],
                [400000000, 12.0],
                [500000000, 13.0],
                [1000000000, 14.0],
                [999999999999, 17.0]
            ];
        }

        foreach ($table as $row) {
            if ($bruto <= $row[0]) {
                return $row[1];
            }
        }
        return 0.0;
    }

    private function resolveClientConfig($clientId, $positionName = null)
    {
        $positions = [];
        if (!empty($positionName)) {
            // Find the client-specific position and its org hierarchy
            $positions = $this->db->table('positions')
                      ->select('positions.id as position_id, departments.id as department_id, divisions.id as division_id')
                      ->join('departments', 'departments.id = positions.department_id', 'left')
                      ->join('divisions', 'divisions.id = departments.division_id', 'left')
                      ->where('positions.nama', $positionName)
                      ->where('divisions.client_id', $clientId)
                      ->get()
                      ->getResult();
        }
        
        if (!empty($positions)) {
            // 2. Try to search by position_id
            foreach ($positions as $p) {
                if ($p->position_id) {
                    $config = $this->db->table('client_payroll_configs')
                                 ->where('client_id', $clientId)
                                 ->where('position_id', $p->position_id)
                                 ->get()
                                 ->getRow();
                    if ($config && ($config->payroll_scheme_id || $config->tax_scheme_id || $config->compensation_scheme_id)) {
                        return $config;
                    }
                }
            }
            
            // 3. Fallback to department_id
            foreach ($positions as $p) {
                if ($p->department_id) {
                    $config = $this->db->table('client_payroll_configs')
                                 ->where('client_id', $clientId)
                                 ->where('department_id', $p->department_id)
                                 ->where('position_id IS NULL')
                                 ->get()
                                 ->getRow();
                    if ($config && ($config->payroll_scheme_id || $config->tax_scheme_id || $config->compensation_scheme_id)) {
                        return $config;
                    }
                }
            }
            
            // 4. Fallback to division_id
            foreach ($positions as $p) {
                if ($p->division_id) {
                    $config = $this->db->table('client_payroll_configs')
                                 ->where('client_id', $clientId)
                                 ->where('division_id', $p->division_id)
                                 ->where('department_id IS NULL')
                                 ->where('position_id IS NULL')
                                 ->get()
                                 ->getRow();
                    if ($config && ($config->payroll_scheme_id || $config->tax_scheme_id || $config->compensation_scheme_id)) {
                        return $config;
                    }
                }
            }
        }
        
        // 5. Fallback to global config (where all org columns are NULL)
        $config = $this->db->table('client_payroll_configs')
                  ->where('client_id', $clientId)
                  ->where('division_id IS NULL')
                  ->where('department_id IS NULL')
                  ->where('position_id IS NULL')
                  ->get()
                  ->getRow();

        if ($config) {
            return $config;
        }

        // 6. Last fallback: return the first available config for this client
        return $this->db->table('client_payroll_configs')
                  ->where('client_id', $clientId)
                  ->get()
                  ->getRow();
    }

    public function syncPKWTComponents($pkwtId, $defaultBasicSalary = null)
    {
        $pkwt = $this->db->table('pkwt')->where('id', $pkwtId)->get()->getRow();
        if (!$pkwt) {
            return;
        }

        $basicSalary = 0;
        if ($defaultBasicSalary !== null && floatval($defaultBasicSalary) > 0) {
            $basicSalary = floatval($defaultBasicSalary);
        } else {
            // Fallback to employee's current gaji_pokok in database
            $emp = $this->db->table('employees')
                            ->where('client_id', $pkwt->client_id)
                            ->where('nama', $pkwt->employee_name)
                            ->get()
                            ->getRow();
            if ($emp) {
                $basicSalary = floatval($emp->gaji_pokok);
            } else {
                // Last fallback: existing basic component
                $existingBasic = $this->db->table('pkwt_components')
                                          ->where('pkwt_id', $pkwtId)
                                          ->groupStart()
                                              ->like('nama', 'Base Salary')
                                              ->orWhere('jenis_component', 'basic_salary')
                                          ->groupEnd()
                                          ->get()
                                          ->getRow();
                if ($existingBasic) {
                    $basicSalary = floatval($existingBasic->value);
                }
            }
        }

        // Clear existing pkwt components (except Ad-hoc components, e.g. rapel)
        $this->db->table('pkwt_components')
                 ->where('pkwt_id', $pkwtId)
                 ->where('(allowance_type != \'Ad-hoc\' OR allowance_type IS NULL)')
                 ->delete();

        // Get current active Client Scheme Config
        $config = $this->resolveClientConfig($pkwt->client_id, $pkwt->position_name);

        $hasComponents = false;
        if ($config && $config->payroll_scheme_id) {
            // Fetch components from scheme
            $components = $this->db->table('payroll_components')
                                   ->where('scheme_id', $config->payroll_scheme_id)
                                   ->get()
                                   ->getResult();

            if (!empty($components)) {
                $hasComponents = true;
                foreach ($components as $comp) {
                    $value = $comp->value;
                    if (stripos($comp->nama, 'Base Salary') !== false || ($comp->jenis_component ?? '') === 'basic_salary') {
                        if (isset($comp->sumber_value) && ($comp->sumber_value === 'ump' || $comp->sumber_value === 'umk' || $comp->sumber_value === 'kompensasi')) {
                            $value = $comp->value;
                        } else {
                            $value = ($basicSalary > 0) ? $basicSalary : floatval($comp->value);
                        }
                    }

                    $this->db->table('pkwt_components')->insert([
                        'pkwt_id' => $pkwtId,
                        'nama' => $comp->nama,
                        'tipe' => $comp->tipe,
                        'value' => $value,
                        'is_persentase' => $comp->is_persentase,
                        'jenis_component' => $comp->jenis_component ?? 'basic_salary',
                        'sifat_kompensasi' => $comp->sifat_kompensasi ?? 'tetap',
                        'sumber_value' => $comp->sumber_value ?? 'nominal',
                        'period' => $comp->period ?? 'month',
                        'is_bpjs' => $comp->is_bpjs ?? 0,
                        'is_pph21' => $comp->is_pph21 ?? 1
                    ]);
                }
            }
        }

        if (!$hasComponents) {
            // Default Base Salary component
            $this->db->table('pkwt_components')->insert([
                'pkwt_id' => $pkwtId,
                'nama' => 'Base Salary',
                'tipe' => 'revenue',
                'value' => $basicSalary,
                'is_persentase' => false,
                'is_bpjs' => 1,
                'is_pph21' => 1
            ]);
        }

        // Add global compensation scheme components if configured
        $compensationSchemeId = null;
        if ($config && $config->payroll_scheme_id) {
            $payrollScheme = $this->db->table('payroll_schemes')->where('id', $config->payroll_scheme_id)->get()->getRow();
            if ($payrollScheme && !empty($payrollScheme->compensation_scheme_id)) {
                $compensationSchemeId = $payrollScheme->compensation_scheme_id;
            }
        }
        if (!$compensationSchemeId && $config && $config->compensation_scheme_id) {
            $compensationSchemeId = $config->compensation_scheme_id;
        }

        if ($compensationSchemeId) {
            $compComponents = $this->db->table('compensation_components')
                                       ->where('scheme_id', $compensationSchemeId)
                                       ->get()
                                       ->getResult();
            foreach ($compComponents as $comp) {
                // Check if this component name is already added as part of payroll scheme (to avoid duplicate)
                $dup = $this->db->table('pkwt_components')
                                ->where('pkwt_id', $pkwtId)
                                ->where('nama', $comp->nama)
                                ->get()
                                ->getRow();
                if (!$dup) {
                    $this->db->table('pkwt_components')->insert([
                        'pkwt_id' => $pkwtId,
                        'nama' => $comp->nama,
                        'tipe' => $comp->tipe,
                        'value' => $comp->value,
                        'is_persentase' => $comp->is_persentase,
                        'jenis_component' => $comp->jenis_component ?? 'kompensasi',
                        'sifat_kompensasi' => $comp->sifat_kompensasi ?? 'tetap',
                        'sumber_value' => $comp->sumber_value ?? 'nominal',
                        'period' => $comp->period ?? 'month',
                        'is_bpjs' => $comp->is_bpjs ?? 0,
                        'is_pph21' => $comp->is_pph21 ?? 1
                    ]);
                }
            }
        }
    }

    public function syncEmployeesToPKWT($clientId = null)
    {
        if (empty($clientId)) {
            return;
        }

        $activeEmployees = $this->db->table('employees')
                                     ->select('employees.nama, employees.gaji_pokok, employees.tgl_masuk, employees.end_contract, employees.tipe_perjanjian, positions.nama as position_name')
                                     ->join('positions', 'positions.id = employees.position_id', 'left')
                                     ->where('employees.client_id', $clientId)
                                     ->where('employees.status', 'Active')
                                     ->get()
                                     ->getResult();

        foreach ($activeEmployees as $emp) {
            // Check if PKWT record already exists for this employee
            $exists = $this->db->table('pkwt')
                               ->where('client_id', $clientId)
                               ->where('employee_name', $emp->nama)
                               ->get()
                               ->getRow();

            if (!$exists) {
                // Create PKWT record
                $tglMulai = !empty($emp->tgl_masuk) ? $emp->tgl_masuk : date('Y-m-d');
                $tglBerakhir = !empty($emp->end_contract) ? $emp->end_contract : date('Y-m-d', strtotime('+1 year', strtotime($tglMulai)));
                
                $pkwtData = [
                    'client_id' => $clientId,
                    'employee_name' => $emp->nama,
                    'position_name' => $emp->position_name ?? 'Staff',
                    'tipe_perjanjian' => $emp->tipe_perjanjian ?? 'PKWT',
                    'start_date' => $tglMulai,
                    'end_date' => $tglBerakhir,
                    'status' => 'Active',
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                $this->db->table('pkwt')->insert($pkwtData);
                $pkwtId = $this->db->insertID();

                $this->syncPKWTComponents($pkwtId, $emp->gaji_pokok);
            } else {
                $updatePkwt = [];
                if (!empty($emp->position_name) && $exists->position_name !== $emp->position_name) {
                    $updatePkwt['position_name'] = $emp->position_name;
                }
                if (empty($exists->tipe_perjanjian) && !empty($emp->tipe_perjanjian)) {
                    $updatePkwt['tipe_perjanjian'] = $emp->tipe_perjanjian;
                }
                
                // Update start_date and end_date if they differ or are empty
                if (!empty($emp->tgl_masuk) && $exists->start_date !== $emp->tgl_masuk) {
                    $updatePkwt['start_date'] = $emp->tgl_masuk;
                }
                if (!empty($emp->end_contract) && $exists->end_date !== $emp->end_contract) {
                    $updatePkwt['end_date'] = $emp->end_contract;
                }
                
                if (!empty($updatePkwt)) {
                    $this->db->table('pkwt')
                             ->where('id', $exists->id)
                             ->update($updatePkwt);
                }
                
                // ALWAYS synchronize the PKWT components to keep it up to date!
                $this->syncPKWTComponents($exists->id, $emp->gaji_pokok);
            }
        }
    }

    public function exportExcel($periodId)
    {
        $clientId = $this->request->getGet('client_id');
        if (!$clientId) {
            return $this->fail('Client ID required');
        }

        // Fetch client name for filename
        $clientName = 'All_Clients';
        $client = $this->db->table('clients')->where('id', $clientId)->get()->getRow();
        if ($client) {
            $clientName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $client->nama);
        }

        // Fetch period name
        $periodName = 'Unknown_Period';
        $period = $this->db->table('payroll_periods')->where('id', $periodId)->get()->getRow();
        $period = $this->formatPeriodRow($period);
        if ($period) {
            $periodName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $period->nama);
        }

        $filename = "Payroll_{$clientName}_{$periodName}.csv";

        // Query payroll results joining employees to get NIK & bank details
        $query = $this->db->table('payroll_final')
                         ->select('payroll_final.*, pkwt.employee_name, pkwt.position_name, pkwt.tipe_perjanjian, employees.nik, employees.bank_name, employees.no_rekening')
                         ->join('pkwt', 'pkwt.id = payroll_final.pkwt_id')
                         ->join('employees', 'employees.nama = pkwt.employee_name', 'left')
                         ->where('payroll_final.period_id', $periodId)
                         ->where('pkwt.client_id', $clientId);

        $data = $query->get()->getResultArray();

        // Stream the CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // UTF-8 BOM for proper Excel encoding
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Define Headers
        $headers = [
            'No', 'NIK', 'Employee Name', 'Tipe Perjanjian', 'Position', 'Metode Tax', 'Status PTKP',
            'Base Salary', 'Allowance Overtime', 'Early Arrival', 'Rapel', 'Bonus Tambahan', 'Allowance Lainnya',
            'Total Revenue', 'Absence Deductionsi', 'BPJS Kes (Karyawan)', 'BPJS JHT (Karyawan)',
            'BPJS JP (Karyawan)', 'PPh 21', 'Other Deductionsnya', 'Total Deduction',
            'Take Home Pay (THP)', 'BPJS Kes (Perusahaan)', 'BPJS JHT (Perusahaan)', 'BPJS JP (Perusahaan)', 'BPJS JKK (Perusahaan)', 'BPJS JKM (Perusahaan)', 'Total BPJS (Perusahaan)', 'Bank Name', 'No Rekening'
        ];
        fputcsv($output, $headers);
        
        $no = 1;
        $totalGp = 0;
        $totalOvertime = 0;
        $totalEa = 0;
        $totalRapel = 0;
        $totalBonus = 0;
        $totalAllowanceLainnya = 0;
        $totalTotalRevenue = 0;
        $totalDeductionAbsen = 0;
        $totalBpjsKes = 0;
        $totalBpjsJht = 0;
        $totalBpjsJp = 0;
        $totalPph21 = 0;
        $totalDeductionLainnya = 0;
        $totalTotalDeduction = 0;
        $totalThp = 0;
        $totalBpjsKesCo = 0;
        $totalBpjsJhtCo = 0;
        $totalBpjsJpCo = 0;
        $totalBpjsJkkCo = 0;
        $totalBpjsJkmCo = 0;
        $totalTotalBpjsCo = 0;

        foreach ($data as $row) {
            // Compute Allowance Lainnya
            $overtimePay = floatval($row['overtime_pay'] ?? 0);
            $bonus = floatval($row['bonus_tambahan'] ?? 0);
            $taxAllowance = floatval($row['tax_allowance'] ?? 0);
            $totalRevenue = floatval($row['total_revenue'] ?? 0);
            $gajiPokok = floatval($row['gaji_pokok'] ?? 0);
            $ea = floatval($row['early_arrival_pay'] ?? 0);
            
            // Extract rapel
            $rapelVal = 0.0;
            if (!empty($row['raw_components'])) {
                try {
                    $comps = json_decode($row['raw_components'], true);
                    if (is_array($comps)) {
                        foreach ($comps as $c) {
                            if (isset($c['nama']) && stripos($c['nama'], 'rapel') !== false) {
                                $rapelVal += floatval($c['value'] ?? 0);
                            }
                        }
                    }
                } catch (\Exception $e) {
                }
            }
            
            $allowanceLainnya = $totalRevenue - ($gajiPokok + $overtimePay + $bonus + $ea + $rapelVal + $taxAllowance);
            if ($allowanceLainnya < 0) $allowanceLainnya = 0;
            
            // Compute Other Deductionsnya
            $bpjsKes = floatval($row['bpjs_kes_karyawan'] ?? 0);
            $bpjsJht = floatval($row['bpjs_jht_karyawan'] ?? 0);
            $bpjsJp = floatval($row['bpjs_jp_karyawan'] ?? 0);
            $pph21 = floatval($row['pph21'] ?? 0);
            $deductionAbsen = floatval($row['deduction_absen'] ?? 0);
            $totalDeduction = floatval($row['total_deduction'] ?? 0);
            
            $taxMethod = $row['tax_method'] ?? 'Gross';
            $taxDikurangi = ($taxMethod === 'Net') ? 0 : $pph21;
            
            $deductionLainnya = $totalDeduction - ($deductionAbsen + $bpjsKes + $bpjsJht + $bpjsJp + $taxDikurangi);
            if ($deductionLainnya < 0) $deductionLainnya = 0;
            
            $thp = floatval($row['take_home_pay'] ?? 0);
            
            $bpjsKesCo = floatval($row['bpjs_kes_company'] ?? 0);
            $bpjsJhtCo = floatval($row['bpjs_jht_company'] ?? 0);
            $bpjsJpCo = floatval($row['bpjs_jp_company'] ?? 0);
            $bpjsJkkCo = floatval($row['bpjs_jkk_company'] ?? 0);
            $bpjsJkmCo = floatval($row['bpjs_jkm_company'] ?? 0);
            $totalBpjsCo = $bpjsKesCo + $bpjsJhtCo + $bpjsJpCo + $bpjsJkkCo + $bpjsJkmCo;

            $totalGp += $gajiPokok;
            $totalOvertime += $overtimePay;
            $totalEa += $ea;
            $totalRapel += $rapelVal;
            $totalBonus += $bonus;
            $totalAllowanceLainnya += $allowanceLainnya;
            $totalTotalRevenue += $totalRevenue;
            $totalDeductionAbsen += $deductionAbsen;
            $totalBpjsKes += $bpjsKes;
            $totalBpjsJht += $bpjsJht;
            $totalBpjsJp += $bpjsJp;
            $totalPph21 += $pph21;
            $totalDeductionLainnya += $deductionLainnya;
            $totalTotalDeduction += $totalDeduction;
            $totalThp += $thp;
            $totalBpjsKesCo += $bpjsKesCo;
            $totalBpjsJhtCo += $bpjsJhtCo;
            $totalBpjsJpCo += $bpjsJpCo;
            $totalBpjsJkkCo += $bpjsJkkCo;
            $totalBpjsJkmCo += $bpjsJkmCo;
            $totalTotalBpjsCo += $totalBpjsCo;
            
            fputcsv($output, [
                $no++,
                $row['nik'] ?? '-',
                $row['employee_name'],
                $row['tipe_perjanjian'] ?? 'PKWT',
                $row['position_name'] ?? '-',
                $taxMethod,
                $row['ptkp_status'] ?? '-',
                $gajiPokok,
                $overtimePay,
                $ea,
                $rapelVal,
                $bonus,
                $allowanceLainnya,
                $totalRevenue,
                $deductionAbsen,
                $bpjsKes,
                $bpjsJht,
                $bpjsJp,
                $pph21,
                $deductionLainnya,
                $totalDeduction,
                $thp,
                $bpjsKesCo,
                $bpjsJhtCo,
                $bpjsJpCo,
                $bpjsJkkCo,
                $bpjsJkmCo,
                $totalBpjsCo,
                $row['bank_name'] ?? '-',
                $row['no_rekening'] ?? '-'
            ]);
        }

        // Write Summary Row
        fputcsv($output, [
            'TOTAL',
            '',
            '',
            '',
            '',
            '',
            '',
            $totalGp,
            $totalOvertime,
            $totalEa,
            $totalRapel,
            $totalBonus,
            $totalAllowanceLainnya,
            $totalTotalRevenue,
            $totalDeductionAbsen,
            $totalBpjsKes,
            $totalBpjsJht,
            $totalBpjsJp,
            $totalPph21,
            $totalDeductionLainnya,
            $totalTotalDeduction,
            $totalThp,
            $totalBpjsKesCo,
            $totalBpjsJhtCo,
            $totalBpjsJpCo,
            $totalBpjsJkkCo,
            $totalBpjsJkmCo,
            $totalTotalBpjsCo,
            '',
            ''
        ]);
        
        fclose($output);
        exit();
    }

    private function formatPeriodRow($row)
    {
        if (!$row) return $row;
        $months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
        
        if (is_array($row)) {
            if (empty($row['nama']) && isset($row['month'], $row['year'])) {
                $row['nama'] = ($months[intval($row['month']) - 1] ?? '') . " " . $row['year'];
            }
            if (!isset($row['status']) && isset($row['status_cutoff'])) {
                $row['status'] = $row['status_cutoff'];
            }
            // Calculate dynamic start_date and end_date from client cutoff config
            if (isset($row['client_id'])) {
                $dates = $this->calculatePeriodDates(intval($row['month']), intval($row['year']), $row['client_id']);
                $row['start_date'] = $dates[0];
                $row['end_date'] = $dates[1];
            }
            return $row;
        } else if (is_object($row)) {
            if (empty($row->nama) && isset($row->month, $row->year)) {
                $row->nama = ($months[intval($row->month) - 1] ?? '') . " " . $row->year;
            }
            if (!isset($row->status) && isset($row->status_cutoff)) {
                $row->status = $row->status_cutoff;
            }
            // Calculate dynamic start_date and end_date from client cutoff config
            if (isset($row->client_id)) {
                $dates = $this->calculatePeriodDates(intval($row->month), intval($row->year), $row->client_id);
                $row->start_date = $dates[0];
                $row->end_date = $dates[1];
            }
            return $row;
        }
        return $row;
    }

    /**
     * Calculate dynamic period start/end dates from client cutoff configuration.
     * For cutoff_start=5 cutoff_end=4, period June 2026 = May 5 to June 4.
     */
    private function calculatePeriodDates($month, $year, $clientId)
    {
        $config = $this->db->table('client_payroll_configs')
                           ->where('client_id', $clientId)
                           ->where('division_id IS NULL')
                           ->where('department_id IS NULL')
                           ->where('position_id IS NULL')
                           ->get()->getRow();
        
        if (!$config) {
            $config = $this->db->table('client_payroll_configs')
                               ->where('client_id', $clientId)
                               ->get()->getRow();
        }
        
        $cutoffStart = ($config && isset($config->cutoff_start)) ? intval($config->cutoff_start) : 21;
        $cutoffEnd = ($config && isset($config->cutoff_end)) ? intval($config->cutoff_end) : 20;
        
        if ($cutoffStart <= 0) $cutoffStart = 1;
        if ($cutoffEnd <= 0) {
            $cutoffEnd = $cutoffStart - 1;
            if ($cutoffEnd < 1) $cutoffEnd = 31;
        }

        $tMonth = intval($month);
        $tYear = intval($year);

        $endDay = min($cutoffEnd, date('t', mktime(0, 0, 0, $tMonth, 1, $tYear)));
        $endDateStr = sprintf('%04d-%02d-%02d', $tYear, $tMonth, $endDay);

        $month_start = $tMonth;
        $year_start = $tYear;
        if ($cutoffStart > $cutoffEnd && $cutoffStart > 1) {
            $month_start--;
            if ($month_start < 1) {
                $month_start = 12;
                $year_start--;
            }
        }
        $daysInStartMonth = intval(date('t', mktime(0, 0, 0, $month_start, 1, $year_start)));
        $safeStart = min($cutoffStart, $daysInStartMonth);
        $startDateStr = sprintf('%04d-%02d-%02d', $year_start, $month_start, $safeStart);
        
        return [$startDateStr, $endDateStr];
    }

    public function getSettings()
    {
        $settings = $this->db->table('system_settings')->get()->getResultArray();

        // Get company payroll settings for early arrival
        $companySetting = $this->db->table('company_payroll_setting')->get()->getRowArray();
        if ($companySetting) {
            $settings[] = [
                'setting_key' => 'early_arrival_enabled',
                'setting_value' => strval($companySetting['early_arrival_enabled']),
                'updated_at' => $companySetting['updated_at'] ?? null
            ];
            $settings[] = [
                'setting_key' => 'max_early_arrival_minutes',
                'setting_value' => strval($companySetting['max_early_arrival_minutes']),
                'updated_at' => $companySetting['updated_at'] ?? null
            ];
            $settings[] = [
                'setting_key' => 'early_arrival_min_minutes',
                'setting_value' => strval($companySetting['early_arrival_min_minutes'] ?? 30),
                'updated_at' => $companySetting['updated_at'] ?? null
            ];
            $settings[] = [
                'setting_key' => 'early_arrival_calculation_unit',
                'setting_value' => strval($companySetting['early_arrival_calculation_unit'] ?? 60),
                'updated_at' => $companySetting['updated_at'] ?? null
            ];
            $settings[] = [
                'setting_key' => 'early_arrival_rounding_method',
                'setting_value' => strval($companySetting['early_arrival_rounding_method'] ?? 'CEILING'),
                'updated_at' => $companySetting['updated_at'] ?? null
            ];
        }

        return $this->respond($settings);
    }

    public function saveSettings()
    {
        $data = $this->request->getJSON(true);
        if (!is_array($data)) {
            return $this->fail('Invalid JSON data format');
        }

        $companySettingsUpdate = [];
        $systemSettingsData = [];

        foreach ($data as $key => $val) {
            if ($key === 'early_arrival_enabled') {
                $companySettingsUpdate['early_arrival_enabled'] = ($val === 'true' || $val === '1' || $val === true || $val === 1) ? 1 : 0;
            } elseif ($key === 'max_early_arrival_minutes') {
                $companySettingsUpdate['max_early_arrival_minutes'] = intval($val);
            } elseif ($key === 'early_arrival_min_minutes') {
                $companySettingsUpdate['early_arrival_min_minutes'] = intval($val);
            } elseif ($key === 'early_arrival_calculation_unit') {
                $companySettingsUpdate['early_arrival_calculation_unit'] = intval($val);
            } elseif ($key === 'early_arrival_rounding_method') {
                $companySettingsUpdate['early_arrival_rounding_method'] = strval($val);
            } else {
                $systemSettingsData[$key] = $val;
            }
        }

        if (!empty($companySettingsUpdate)) {
            $companySettingsUpdate['updated_at'] = date('Y-m-d H:i:s');
            $exists = $this->db->table('company_payroll_setting')->get()->getRow();
            if ($exists) {
                $this->db->table('company_payroll_setting')->update($companySettingsUpdate);
            } else {
                $this->db->table('company_payroll_setting')->insert($companySettingsUpdate);
            }
        }

        foreach ($systemSettingsData as $key => $val) {
            $exists = $this->db->table('system_settings')->where('setting_key', $key)->get()->getRow();
            if ($exists) {
                $this->db->table('system_settings')->where('setting_key', $key)->update([
                    'setting_value' => strval($val),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                $this->db->table('system_settings')->insert([
                    'setting_key' => $key,
                    'setting_value' => strval($val)
                ]);
            }
        }

        $this->logActivity("Memperbarui configuration sistem");
        return $this->respond(['message' => 'Settings saved successfully']);
    }
    // --- SHIFT SCHEMES ---
    public function getShiftSchemes()
    {
        $schemes = $this->db->table('shift_schemes')->get()->getResultArray();
        return $this->respond($schemes);
    }

    public function createShiftScheme()
    {
        $data = $this->request->getJSON(true);
        if (empty($data['name']) || empty($data['start_time']) || empty($data['end_time'])) {
            return $this->failValidationErrors('Name, start time, dan end time required');
        }

        $duration = 8.0;
        if (!empty($data['start_time']) && !empty($data['end_time'])) {
            try {
                $startTime = new \DateTime($data['start_time']);
                $endTime = new \DateTime($data['end_time']);
                if ($endTime < $startTime) {
                    $endTime->modify('+1 day');
                }
                $diff = $startTime->diff($endTime);
                $duration = floatval(number_format($diff->h + ($diff->i / 60), 1));
            } catch (\Exception $e) {
                $duration = isset($data['duration']) ? floatval($data['duration']) : 8.0;
            }
        }

        $insertData = [
            'name' => $data['name'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'duration' => $duration,
            'grace_period_late' => isset($data['grace_period_late']) ? intval($data['grace_period_late']) : 0,
            'grace_period_early' => isset($data['grace_period_early']) ? intval($data['grace_period_early']) : 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->table('shift_schemes')->insert($insertData);
        $this->logActivity("Membuat scheme shift baru: " . $data['name']);
        return $this->respondCreated(['message' => 'Shift scheme dibuat']);
    }

    public function updateShiftScheme($id)
    {
        $data = $this->request->getJSON(true);
        if (empty($data['name']) || empty($data['start_time']) || empty($data['end_time'])) {
            return $this->failValidationErrors('Name, start time, dan end time required');
        }

        $duration = 8.0;
        if (!empty($data['start_time']) && !empty($data['end_time'])) {
            try {
                $startTime = new \DateTime($data['start_time']);
                $endTime = new \DateTime($data['end_time']);
                if ($endTime < $startTime) {
                    $endTime->modify('+1 day');
                }
                $diff = $startTime->diff($endTime);
                $duration = floatval(number_format($diff->h + ($diff->i / 60), 1));
            } catch (\Exception $e) {
                $duration = isset($data['duration']) ? floatval($data['duration']) : 8.0;
            }
        }

        $updateData = [
            'name' => $data['name'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'duration' => $duration,
            'grace_period_late' => isset($data['grace_period_late']) ? intval($data['grace_period_late']) : 0,
            'grace_period_early' => isset($data['grace_period_early']) ? intval($data['grace_period_early']) : 0,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->table('shift_schemes')->where('id', $id)->update($updateData);
        $this->logActivity("Mengubah scheme shift ID: " . $id);
        return $this->respond(['message' => 'Scheme shift updated successfully']);
    }

    public function deleteShiftScheme($id)
    {
        $referenced = $this->db->table('employee_shifts')->where('shift_scheme_id', $id)->get()->getRow();
        if ($referenced) {
            return $this->fail('Scheme shift ini sedang digunakan oleh karyawan dan tidak dapat dihapus');
        }

        $this->db->table('shift_schemes')->where('id', $id)->delete();
        $this->logActivity("Menghapus scheme shift ID: " . $id);
        return $this->respondDeleted(['message' => 'Scheme shift deleted successfully']);
    }

    public function importShiftSchemes()
    {
        $json = $this->request->getJSON(true);
        $schemes = $json['schemes'] ?? [];

        if (empty($schemes)) {
            return $this->failValidationErrors('No data available scheme shift yang diunggah.');
        }

        $successCount = 0;
        $errorLogs = [];

        foreach ($schemes as $index => $row) {
            $name = trim($row['name'] ?? '');
            $startTime = trim($row['start_time'] ?? '');
            $endTime = trim($row['end_time'] ?? '');
            $duration = floatval($row['duration'] ?? 0);
            $graceLate = intval($row['grace_period_late'] ?? 0);
            $graceEarly = intval($row['grace_period_early'] ?? 0);

            if (empty($name) || empty($startTime) || empty($endTime)) {
                $errorLogs[] = "Baris " . ($index + 1) . ": Nama shift, jam masuk, dan jam keluar required.";
                continue;
            }

            if (strlen($startTime) == 5) $startTime .= ':00';
            if (strlen($endTime) == 5) $endTime .= ':00';

            if ($duration <= 0) {
                try {
                    $startObj = new \DateTime($startTime);
                    $endObj = new \DateTime($endTime);
                    if ($endObj < $startObj) {
                        $endObj->modify('+1 day');
                    }
                    $diff = $startObj->diff($endObj);
                    $duration = floatval(number_format($diff->h + ($diff->i / 60), 1));
                } catch (\Exception $e) {
                    $duration = 8.0;
                }
            }

            $existing = $this->db->table('shift_schemes')->where('LOWER(name)', strtolower($name))->get()->getRowArray();
            if ($existing) {
                $this->db->table('shift_schemes')->where('id', $existing['id'])->update([
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'duration' => $duration,
                    'grace_period_late' => $graceLate,
                    'grace_period_early' => $graceEarly,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                $this->db->table('shift_schemes')->insert([
                    'name' => $name,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'duration' => $duration,
                    'grace_period_late' => $graceLate,
                    'grace_period_early' => $graceEarly,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            }
            $successCount++;
        }

        $this->logActivity("Mengimpor $successCount scheme shift dari file Excel");
        return $this->respond([
            'success' => true,
            'imported_count' => $successCount,
            'errors' => $errorLogs,
            'message' => "Successfully mengimpor $successCount scheme shift."
        ]);
    }

    // --- EMPLOYEE SHIFTS ---
    public function getEmployeeShifts()
    {
        $employeeId = $this->request->getGet('employee_id');
        $builder = $this->db->table('employee_shifts')
            ->select('employee_shifts.*, shift_schemes.name as shift_name, shift_schemes.start_time, shift_schemes.end_time, employees.nama as employee_name')
            ->join('shift_schemes', 'shift_schemes.id = employee_shifts.shift_scheme_id', 'inner')
            ->join('employees', 'employees.id = employee_shifts.employee_id', 'inner');

        if (!empty($employeeId)) {
            $builder->where('employee_shifts.employee_id', intval($employeeId));
        }

        $shifts = $builder->orderBy('employee_shifts.start_date', 'DESC')->get()->getResultArray();
        return $this->respond($shifts);
    }

    public function assignEmployeeShift()
    {
        $data = $this->request->getJSON(true);
        if (empty($data['employee_id']) || empty($data['shift_scheme_id']) || empty($data['start_date'])) {
            return $this->failValidationErrors('Employee ID, Shift Scheme ID, dan Start Date required');
        }

        $empId = intval($data['employee_id']);
        $shiftId = intval($data['shift_scheme_id']);
        $startDate = $data['start_date'];
        $endDate = !empty($data['end_date']) ? $data['end_date'] : null;

        $yesterday = date('Y-m-d', strtotime($startDate . ' -1 day'));

        $activeShifts = $this->db->table('employee_shifts')
            ->where('employee_id', $empId)
            ->where("(end_date IS NULL OR end_date >= '{$startDate}')")
            ->get()->getResultArray();

        foreach ($activeShifts as $active) {
            if ($active['start_date'] < $startDate) {
                $this->db->table('employee_shifts')
                    ->where('id', $active['id'])
                    ->update([
                        'end_date' => $yesterday,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            } else {
                $this->db->table('employee_shifts')
                    ->where('id', $active['id'])
                    ->delete();
            }
        }

        $insertData = [
            'employee_id' => $empId,
            'shift_scheme_id' => $shiftId,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->db->table('employee_shifts')->insert($insertData);
        $this->logActivity("Menugaskan scheme shift ke karyawan ID: " . $empId);
        return $this->respondCreated(['message' => 'Shift successfully ditugaskan ke karyawan']);
    }

    public function deleteEmployeeShift($id)
    {
        $this->db->table('employee_shifts')->where('id', $id)->delete();
        $this->logActivity("Menghapus allocation shift karyawan ID: " . $id);
        return $this->respondDeleted(['message' => 'Allocation shift deleted successfully']);
    }

    private function syncPayrollAttendanceOvertime($employeeId, $date)
    {
        $employee = $this->db->table('employees')->where('id', intval($employeeId))->get()->getRow();
        if (!$employee) return;
        $clientId = $employee->client_id;

        $periods = $this->db->table('payroll_periods')
                            ->where('client_id', $clientId)
                            ->get()->getResult();

        foreach ($periods as $period) {
            $clientConfig = $this->db->table('client_payroll_configs')
                                 ->where('client_id', $clientId)
                                 ->get()->getRow();
            $daysInMonth = date('t', mktime(0, 0, 0, intval($period->month), 1, intval($period->year)));
            $cutoffStart = $clientConfig ? intval($clientConfig->cutoff_start) : 21;
            $cutoffEnd = $clientConfig ? intval($clientConfig->cutoff_end) : 20;

            if ($cutoffStart <= 0) $cutoffStart = 1;
            if ($cutoffEnd <= 0) {
                $cutoffEnd = $cutoffStart - 1;
                if ($cutoffEnd < 1) $cutoffEnd = 31;
            }

            $otMonth = intval($period->month);
            $otYear = intval($period->year);
            $startDateStr = sprintf('%04d-%02d-01', $otYear, $otMonth);
            $endDateStr = date('Y-m-t', strtotime($startDateStr));

            $effectiveStartDateStr = !empty($employee->tgl_masuk) ? max($startDateStr, date('Y-m-d', strtotime($employee->tgl_masuk))) : $startDateStr;

            if ($date >= $effectiveStartDateStr && $date <= $endDateStr) {
                // Query regular and holiday overtime separately
                $overtimeBiasaObj = $this->db->table('overtime_logs')
                                         ->selectSum('jam_overtime')
                                         ->where('employee_id', $employeeId)
                                         ->where('tanggal >=', $effectiveStartDateStr)
                                         ->where('tanggal <=', $endDateStr)
                                         ->where('status', 'Approved')
                                         ->where('(is_rapel = 0 OR is_rapel IS NULL)')
                                         ->where('(is_holiday = 0 OR is_holiday IS NULL)')
                                         ->get()->getRow();
                $overtimeBiasa = $overtimeBiasaObj ? floatval($overtimeBiasaObj->jam_overtime) : 0.0;

                $overtimeLiburObj = $this->db->table('overtime_logs')
                                         ->selectSum('jam_overtime')
                                         ->where('employee_id', $employeeId)
                                         ->where('tanggal >=', $effectiveStartDateStr)
                                         ->where('tanggal <=', $endDateStr)
                                         ->where('status', 'Approved')
                                         ->where('(is_rapel = 0 OR is_rapel IS NULL)')
                                         ->where('is_holiday', 1)
                                         ->get()->getRow();
                $overtimeLibur = $overtimeLiburObj ? floatval($overtimeLiburObj->jam_overtime) : 0.0;
                $overtimeSum = $overtimeBiasa + $overtimeLibur;

                $pkwt = $this->db->table('pkwt')
                                 ->where('client_id', $clientId)
                                 ->where('employee_name', $employee->nama)
                                 ->get()->getRow();
                if (!$pkwt) continue;

                $existing = $this->db->table('payroll_attendance')
                                     ->where('period_id', $period->id)
                                     ->where('pkwt_id', $pkwt->id)
                                     ->get()->getRow();

                if ($existing && intval($existing->is_manual ?? 0) === 1) {
                    continue;
                }

                if ($existing) {
                    $this->db->table('payroll_attendance')
                             ->where('id', $existing->id)
                             ->update([
                                 'jam_overtime' => $overtimeSum,
                                 'jam_overtime_hari_biasa' => $overtimeBiasa,
                                 'jam_overtime_hari_libur' => $overtimeLibur,
                             ]);
                } else {
                    $hasAnyClientLogs = $this->db->table('attendance_logs')
                                                 ->join('employees', 'employees.id = attendance_logs.employee_id')
                                                 ->where('employees.client_id', $clientId)
                                                 ->where('attendance_logs.log_date >=', $startDateStr)
                                                 ->where('attendance_logs.log_date <=', $endDateStr)
                                                 ->countAllResults();

                    if ($hasAnyClientLogs > 0) {
                        $stdWorkingDays = 0;
                    } else {
                        $stdWorkingDays = 20;
                        $posHk = 5;
                        if ($employee->position_id) {
                            $pos = $this->db->table('positions')->where('id', $employee->position_id)->get()->getRow();
                            if ($pos && isset($pos->hari_kerja)) {
                                $posHk = intval($pos->hari_kerja);
                            }
                        }
                        if ($posHk === 6) {
                            $stdWorkingDays = 25;
                        } elseif ($posHk === 7) {
                            $stdWorkingDays = 30;
                        }
                        
                        if (isset($employee->hari_kerja) && intval($employee->hari_kerja) > 0) {
                            $stdWorkingDays = ($employee->hari_kerja === 6) ? 25 : (($employee->hari_kerja === 7) ? 30 : 20);
                        }
                    }

                    $this->db->table('payroll_attendance')->insert([
                        'period_id' => $period->id,
                        'pkwt_id' => $pkwt->id,
                        'hari_kerja' => $stdWorkingDays,
                        'jam_overtime' => $overtimeSum,
                        'jam_overtime_hari_biasa' => $overtimeBiasa,
                        'jam_overtime_hari_libur' => $overtimeLibur,
                        'deduction_absensi' => 0.0,
                        'bonus_tambahan' => 0.0
                    ]);
                }
            }
        }
    }

    private function resolveCutoffStartEnd($clientConfig)
    {
        $cutoffStart = $clientConfig ? intval($clientConfig->cutoff_start) : 21;
        $cutoffEnd = $clientConfig ? intval($clientConfig->cutoff_end) : 20;

        if ($clientConfig) {
            $startField = "cutoff_gaji_pokok_start";
            $endField = "cutoff_gaji_pokok_end";
            $refField = "cutoff_gaji_pokok_schedule_ref";
            
            $start = isset($clientConfig->$startField) ? intval($clientConfig->$startField) : null;
            $end = isset($clientConfig->$endField) ? intval($clientConfig->$endField) : null;
            $refId = isset($clientConfig->$refField) ? intval($clientConfig->$refField) : null;
            
            if ($refId) {
                $sched = $this->db->table('payroll_schedules')->where('id', $refId)->get()->getRow();
                if ($sched) {
                    $start = intval($sched->cutoff_start);
                    $end = intval($sched->cutoff_end);
                }
            }
            
            if ($start === null) {
                if (isset($clientConfig->cutoff_start)) {
                    $start = intval($clientConfig->cutoff_start);
                } else {
                    $start = 21;
                }
            }
            if ($end === null) {
                if (isset($clientConfig->cutoff_end)) {
                    $end = intval($clientConfig->cutoff_end);
                } else {
                    $end = $start - 1;
                    if ($end < 1) $end = 31;
                }
            }
            
            $cutoffStart = $start;
            $cutoffEnd = $end;
        }

        if ($cutoffStart <= 0) $cutoffStart = 1;
        if ($cutoffEnd <= 0) {
            $cutoffEnd = $cutoffStart - 1;
            if ($cutoffEnd < 1) $cutoffEnd = 31;
        }

        return ['start' => $cutoffStart, 'end' => $cutoffEnd];
    }

    private function syncPayrollAttendanceOvertimeForLog($id)
    {
        $log = $this->db->table('overtime_logs')->where('id', intval($id))->get()->getRow();
        if ($log) {
            $this->syncPayrollAttendanceOvertime($log->employee_id, $log->tanggal);
        }
    }

    private function syncOvertimeToPayrollAttendance($periodId, $clientId = null)
    {
        $period = $this->db->table('payroll_periods')->where('id', $periodId)->get()->getRow();
        if (!$period) return;

        $pkwtQuery = $this->db->table('pkwt');
        if ($clientId) {
            $pkwtQuery->where('client_id', intval($clientId));
        }
        $pkwts = $pkwtQuery->get()->getResult();

        foreach ($pkwts as $pkwt) {
            // Skip sync if manually overridden
            $existing = $this->db->table('payroll_attendance')
                                 ->where('period_id', $periodId)
                                 ->where('pkwt_id', $pkwt->id)
                                 ->get()->getRow();
            if ($existing && intval($existing->is_manual ?? 0) === 1) {
                continue;
            }

            $emp = $this->db->table('employees')
                            ->where('client_id', $pkwt->client_id)
                            ->where('nama', $pkwt->employee_name)
                            ->get()->getRow();
            if (!$emp) continue;

            $clientConfig = $this->resolveClientConfig($pkwt->client_id, $pkwt->position_name);
            
            // Resolve cut-off date range using client config
            $cutoffStartEnd = $this->resolveCutoffStartEnd($clientConfig);
            $cutoffStart = $cutoffStartEnd['start'];
            $cutoffEnd = $cutoffStartEnd['end'];

            $month_start = intval($period->month);
            $year_start = intval($period->year);
            $month_end = $month_start;
            $year_end = $year_start;

            if ($cutoffStart > $cutoffEnd && $cutoffStart > 1) {
                $month_start -= 1;
                if ($month_start < 1) {
                    $month_start = 12;
                    $year_start -= 1;
                }
            }

            $daysInStartMonth = intval(date('t', mktime(0, 0, 0, $month_start, 1, $year_start)));
            $daysInEndMonth = intval(date('t', mktime(0, 0, 0, $month_end, 1, $year_end)));
            $safeStart = min($cutoffStart, $daysInStartMonth);
            $safeEnd = min($cutoffEnd, $daysInEndMonth);

            $startDateStr = sprintf('%04d-%02d-%02d', $year_start, $month_start, $safeStart);
            $endDateStr = sprintf('%04d-%02d-%02d', $year_end, $month_end, $safeEnd);

            $effectiveStartDateStr = !empty($emp->tgl_masuk) ? max($startDateStr, date('Y-m-d', strtotime($emp->tgl_masuk))) : $startDateStr;

            // Overtime dates are calendar-month based (current period month)
            $otMonth = intval($period->month);
            $otYear = intval($period->year);
            $otStartDateStr = sprintf('%04d-%02d-01', $otYear, $otMonth);
            $otEndDateStr = date('Y-m-t', strtotime($otStartDateStr));

            $effectiveOtStartDateStr = !empty($emp->tgl_masuk) ? max($otStartDateStr, date('Y-m-d', strtotime($emp->tgl_masuk))) : $otStartDateStr;

            // 2a. Overtime Working Days (is_holiday = 0 OR is_holiday IS NULL)
            $overtimeBiasaObj = $this->db->table('overtime_logs')
                                     ->selectSum('jam_overtime')
                                     ->where('employee_id', $emp->id)
                                     ->where('tanggal >=', $effectiveOtStartDateStr)
                                     ->where('tanggal <=', $otEndDateStr)
                                     ->where('status', 'Approved')
                                     ->where('(is_rapel = 0 OR is_rapel IS NULL)')
                                     ->where('(is_holiday = 0 OR is_holiday IS NULL)')
                                     ->get()->getRow();
            $overtimeBiasa = $overtimeBiasaObj ? floatval($overtimeBiasaObj->jam_overtime) : 0.0;

            // 2b. Overtime Holiday (is_holiday = 1)
            $overtimeLiburObj = $this->db->table('overtime_logs')
                                     ->selectSum('jam_overtime')
                                     ->where('employee_id', $emp->id)
                                     ->where('tanggal >=', $effectiveOtStartDateStr)
                                     ->where('tanggal <=', $otEndDateStr)
                                     ->where('status', 'Approved')
                                     ->where('(is_rapel = 0 OR is_rapel IS NULL)')
                                     ->where('is_holiday', 1)
                                     ->get()->getRow();
            $overtimeLibur = $overtimeLiburObj ? floatval($overtimeLiburObj->jam_overtime) : 0.0;

            $overtimeSum = $overtimeBiasa + $overtimeLibur;

            // Calculate actual present days from attendance_logs in the cutoff period range
            $actualHadir = $this->db->table('attendance_logs')
                                    ->where('employee_id', $emp->id)
                                    ->where('log_date >=', $effectiveStartDateStr)
                                    ->where('log_date <=', $endDateStr)
                                    ->where('status', 'Hadir')
                                    ->where('(is_rapel = 0 OR is_rapel IS NULL)')
                                    ->countAllResults();

            // Check if there are any logs uploaded for this client in the cutoff period range
            $hasAnyClientLogs = $this->db->table('attendance_logs')
                                         ->join('employees', 'employees.id = attendance_logs.employee_id')
                                         ->where('employees.client_id', $pkwt->client_id)
                                         ->where('attendance_logs.log_date >=', $startDateStr)
                                         ->where('attendance_logs.log_date <=', $endDateStr)
                                         ->countAllResults();

            if ($actualHadir > 0) {
                $hariKerjaVal = $actualHadir;
            } else {
                if ($hasAnyClientLogs > 0) {
                    $hariKerjaVal = 0;
                } else {
                    $posHk = 5;
                    if ($emp->position_id) {
                        $pos = $this->db->table('positions')->where('id', $emp->position_id)->get()->getRow();
                        if ($pos && isset($pos->hari_kerja)) {
                            $posHk = intval($pos->hari_kerja);
                        }
                    }
                    if (isset($emp->hari_kerja) && intval($emp->hari_kerja) > 0) {
                        $posHk = intval($emp->hari_kerja);
                    }
                    $stdWorkingDays = $this->getStandardWorkingDaysInRange($startDateStr, $endDateStr, $posHk);
                    $hariKerjaVal = $stdWorkingDays;
                }
            }

            // Loop daily logs in the cutoff range to determine alpaCount automatically
            $currTime = strtotime($startDateStr);
            $endTime = strtotime($endDateStr);
            $alpaCount = 0;
            
            $attLogs = $this->db->table('attendance_logs')
                                ->where('employee_id', $emp->id)
                                ->where('log_date >=', $startDateStr)
                                ->where('log_date <=', $endDateStr)
                                ->where('(is_rapel = 0 OR is_rapel IS NULL)')
                                ->get()->getResultArray();
                                
            $logsByDate = [];
            foreach ($attLogs as $log) {
                $logsByDate[$log['log_date']] = $log;
            }

            $workDaysConfig = 5;
            if ($emp) {
                $workDaysConfig = intval($emp->hari_kerja ?? 5);
                if ($workDaysConfig < 1) {
                    $posId = $emp->position_id ?? null;
                    if ($posId) {
                        $pos = $this->db->table('positions')->where('id', $posId)->get()->getRow();
                        if ($pos && isset($pos->hari_kerja) && intval($pos->hari_kerja) > 0) {
                            $workDaysConfig = intval($pos->hari_kerja);
                        }
                    }
                }
            }

            $holidaysList = [];
            $holidayRowsForAlpa = $this->db->table('holiday_calendar')
                              ->where('tanggal >=', $startDateStr)
                              ->where('tanggal <=', $endDateStr)
                              ->get()->getResultArray();
            foreach ($holidayRowsForAlpa as $h) {
                $holidaysList[$h['tanggal']] = true;
            }

            while ($currTime <= $endTime) {
                $currDateStr = date('Y-m-d', $currTime);
                $dayOfWeek = intval(date('w', $currTime));
                
                $joinDate = !empty($emp->tgl_masuk) ? $emp->tgl_masuk : ($emp->start_contract ?? null);
                if (!empty($joinDate) && strtotime($currDateStr) < strtotime($joinDate)) {
                    $currTime = strtotime('+1 day', $currTime);
                    continue;
                }

                $isWorkingDay = false;
                if (!isset($holidaysList[$currDateStr])) {
                    if ($workDaysConfig === 5) {
                        $isWorkingDay = ($dayOfWeek !== 0 && $dayOfWeek !== 6);
                    } elseif ($workDaysConfig === 6) {
                        $isWorkingDay = ($dayOfWeek !== 0);
                    } else {
                        $isWorkingDay = true;
                    }
                }
                
                if ($isWorkingDay) {
                    if (!isset($logsByDate[$currDateStr])) {
                        $alpaCount++;
                    } else {
                        $logStatus = $logsByDate[$currDateStr]['status'] ?? 'Hadir';
                        $logStatusNorm = strtolower(trim($logStatus));
                        if ($logStatusNorm === 'absen' || $logStatusNorm === 'alpa' || $logStatusNorm === 'absent' || $logStatusNorm === 'missing') {
                            $alpaCount++;
                        }
                    }
                }
                
                $currTime = strtotime('+1 day', $currTime);
            }

            // Calculate deduction_absensi based on basic salary & hari_kerja config
            $gajiPokok = floatval($emp->gaji_pokok);
            
            $activeContract = $this->db->table('contracts')
                                        ->where('employee_id', $emp->id)
                                        ->where('status_pkwt', 'Active')
                                        ->orderBy('tgl_mulai', 'DESC')
                                        ->get()->getRow();
            if ($activeContract && floatval($activeContract->gaji_pokok) > 0) {
                $gajiPokok = floatval($activeContract->gaji_pokok);
            }

            if ($gajiPokok <= 0 && $pkwt->id) {
                $basicComp = $this->db->table('pkwt_components')
                                      ->where('pkwt_id', $pkwt->id)
                                      ->groupStart()
                                          ->where('jenis_component', 'basic_salary')
                                          ->orLike('nama', 'Base Salary')
                                      ->groupEnd()
                                      ->get()->getRow();
                if ($basicComp) {
                    $base_value = floatval($basicComp->value);
                    $sumber_value = $basicComp->sumber_value ?? 'nominal';
                    
                    if ($sumber_value === 'ump' || $sumber_value === 'umk' || $sumber_value === 'ump_umk') {
                        $mwId = $emp->minimum_wage_id ?? null;
                        $minimumWage = 0;
                        if ($emp && isset($emp->minimum_wage_id)) {
                            $mw = $this->db->table('minimum_wages')->where('id', $emp->minimum_wage_id)->get()->getRow();
                            if ($mw) {
                                $minimumWage = floatval($mw->nominal);
                                $mwId = $mw->id;
                            }
                        } else if ($clientConfig && isset($clientConfig->minimum_wage_id)) {
                            $mw = $this->db->table('minimum_wages')->where('id', $clientConfig->minimum_wage_id)->get()->getRow();
                            if ($mw) {
                                $minimumWage = floatval($mw->nominal);
                                $mwId = $mw->id;
                            }
                        }

                        $empProvince = null;
                        if ($emp && !empty($emp->work_location_id)) {
                            $wl = $this->db->table('work_locations')->where('id', $emp->work_location_id)->get()->getRow();
                            if ($wl && !empty($wl->provinsi)) {
                                $empProvince = $wl->provinsi;
                            }
                        }

                        $resolvedWages = $this->resolveUmpUmk($mwId, null, $empProvince);
                        $umpWageValue = $resolvedWages['ump'];
                        $umkWageValue = $resolvedWages['umk'];

                        if ($sumber_value === 'ump') {
                            $base_value = $umpWageValue * ($base_value / 100);
                        } else if ($sumber_value === 'umk') {
                            $base_value = $umkWageValue * ($base_value / 100);
                        } else if ($sumber_value === 'ump_umk') {
                            $base_value = $minimumWage * ($base_value / 100);
                        }
                    }
                    $gajiPokok = $base_value;
                }
            }

            $divider = $this->getStandardWorkingDaysInRange($startDateStr, $endDateStr, $workDaysConfig);
            if ($divider <= 0) {
                $divider = ($workDaysConfig === 5) ? 22 : (($workDaysConfig === 6) ? 26 : 30);
            }
            $dendaAbsenPerDay = $gajiPokok / $divider;
            $deductionAbsensi = $alpaCount * $dendaAbsenPerDay;

            $isJoinedPrevMonth = false;
            $isActiveRegularPrevMonth = false;
            if ($emp && !empty($emp->tgl_masuk)) {
                $joinTs = strtotime($emp->tgl_masuk);
                $joinYear = intval(date('Y', $joinTs));
                $joinMonth = intval(date('n', $joinTs));
                
                $prevMonthVal = intval($period->month) - 1;
                $prevYearVal = intval($period->year);
                if ($prevMonthVal == 0) {
                    $prevMonthVal = 12;
                    $prevYearVal--;
                }
                if ($joinYear === $prevYearVal && $joinMonth === $prevMonthVal) {
                    $joinDateStr = date('Y-m-d', $joinTs);
                    $prevDaysInMonth = intval(date('t', mktime(0, 0, 0, $prevMonthVal, 1, $prevYearVal)));
                    $prevCutoffEndDate = sprintf('%04d-%02d-%02d', $prevYearVal, $prevMonthVal, min($cutoffStartEnd['end'], $prevDaysInMonth));
                    if ($joinDateStr > $prevCutoffEndDate) {
                        $isJoinedPrevMonth = true;
                    } else {
                        $currentMonthStartStr = sprintf('%04d-%02d-01', $period->year, $period->month);
                        $currDaysInMonth = intval(date('t', mktime(0, 0, 0, intval($period->month), 1, intval($period->year))));
                        $currentCutoffEndStr = sprintf('%04d-%02d-%02d', $period->year, $period->month, min($cutoffStartEnd['end'], $currDaysInMonth));
                        $currentLogsCount = $this->db->table('attendance_logs')
                                                     ->where('employee_id', $emp->id)
                                                     ->where('log_date >=', $currentMonthStartStr)
                                                     ->where('log_date <=', $currentCutoffEndStr)
                                                     ->countAllResults();
                        
                        $prevCutoffStartStr = date('Y-m-d', strtotime('+1 day', strtotime($prevCutoffEndDate)));
                        $prevMonthLogsCount = $this->db->table('attendance_logs')
                                                     ->where('employee_id', $emp->id)
                                                     ->where('log_date >=', $prevCutoffStartStr)
                                                     ->where('log_date <', $currentMonthStartStr)
                                                     ->countAllResults();
                        
                        if ($currentLogsCount > 0 && $prevMonthLogsCount === 0) {
                            $isActiveRegularPrevMonth = true;
                        }
                    }
                }
            }

            if ($isJoinedPrevMonth || $isActiveRegularPrevMonth) {
                if ($actualHadir === 0 && $hasAnyClientLogs === 0) {
                    $posHk = 5;
                    if ($emp->position_id) {
                        $pos = $this->db->table('positions')->where('id', $emp->position_id)->get()->getRow();
                        if ($pos && isset($pos->hari_kerja)) {
                            $posHk = intval($pos->hari_kerja);
                        }
                    }
                    if (isset($emp->hari_kerja) && intval($emp->hari_kerja) > 0) {
                        $posHk = intval($emp->hari_kerja);
                    }
                    $stdWorkingDays = $this->getStandardWorkingDaysInRange($startDateStr, $endDateStr, $posHk);
                    $hariKerjaVal = $stdWorkingDays;
                }
                $deductionAbsensi = 0.0;
            }

            $existing = $this->db->table('payroll_attendance')
                                 ->where('period_id', $periodId)
                                 ->where('pkwt_id', $pkwt->id)
                                 ->get()->getRow();

            if ($existing) {
                $updateData = [
                    'jam_overtime' => $overtimeSum,
                    'jam_overtime_hari_biasa' => $overtimeBiasa,
                    'jam_overtime_hari_libur' => $overtimeLibur,
                    'hari_kerja' => $hariKerjaVal,
                    'deduction_absensi' => $deductionAbsensi
                ];
                $this->db->table('payroll_attendance')
                         ->where('id', $existing->id)
                         ->update($updateData);
            } else {
                $this->db->table('payroll_attendance')->insert([
                    'period_id' => $periodId,
                    'pkwt_id' => $pkwt->id,
                    'hari_kerja' => $hariKerjaVal,
                    'jam_overtime' => $overtimeSum,
                    'jam_overtime_hari_biasa' => $overtimeBiasa,
                    'jam_overtime_hari_libur' => $overtimeLibur,
                    'deduction_absensi' => $deductionAbsensi,
                    'bonus_tambahan' => 0.0
                ]);
            }
        }
    }

    private function syncEarlyArrivalToPayrollAttendance($periodId, $clientId = null)
    {
        $period = $this->db->table('payroll_periods')->where('id', $periodId)->get()->getRow();
        if (!$period) return;

        $pkwtQuery = $this->db->table('pkwt');
        if ($clientId) {
            $pkwtQuery->where('client_id', intval($clientId));
        }
        $pkwts = $pkwtQuery->get()->getResult();

        foreach ($pkwts as $pkwt) {
            // Skip sync if manually overridden
            $existing = $this->db->table('payroll_attendance')
                                 ->where('period_id', $periodId)
                                 ->where('pkwt_id', $pkwt->id)
                                 ->get()->getRow();
            if ($existing && intval($existing->is_manual ?? 0) === 1) {
                continue;
            }

            $emp = $this->db->table('employees')
                            ->where('client_id', $pkwt->client_id)
                            ->where('nama', $pkwt->employee_name)
                            ->get()->getRow();
            if (!$emp) continue;

            $clientConfig = $this->resolveClientConfig($pkwt->client_id, $pkwt->position_name);

            $prevMonth = intval($period->month) - 1;
            $prevYear = intval($period->year);
            if ($prevMonth == 0) {
                $prevMonth = 12;
                $prevYear--;
            }
            $startDateStr = sprintf('%04d-%02d-01', $prevYear, $prevMonth);
            $endDateStr = date('Y-m-t', strtotime($startDateStr));

            $effectiveStartDateStr = !empty($emp->tgl_masuk) ? max($startDateStr, date('Y-m-d', strtotime($emp->tgl_masuk))) : $startDateStr;

            // Also match by payroll_period column for records assigned to this period
            $payoutPeriodStr = intval($period->month) . '-' . intval($period->year);
            $payoutPeriodStrPadded = sprintf('%02d/%04d', intval($period->month), intval($period->year));

            $eaSumObj = $this->db->table('early_arrival')
                                 ->selectSum('eligible_minutes')
                                 ->where('employee_id', $emp->id)
                                 ->where('status', 'APPROVED')
                                 ->groupStart()
                                     ->groupStart()
                                         ->where('date >=', $effectiveStartDateStr)
                                         ->where('date <=', $endDateStr)
                                     ->groupEnd()
                                     ->orWhere('payroll_period', $payoutPeriodStr)
                                     ->orWhere('payroll_period', $payoutPeriodStrPadded)
                                 ->groupEnd()
                                 ->get()->getRow();
            $eaSum = $eaSumObj ? intval($eaSumObj->eligible_minutes) : 0;

            $existing = $this->db->table('payroll_attendance')
                                 ->where('period_id', $periodId)
                                 ->where('pkwt_id', $pkwt->id)
                                 ->get()->getRow();

            if ($existing) {
                $this->db->table('payroll_attendance')
                         ->where('id', $existing->id)
                         ->update([
                             'early_arrival_minutes' => $eaSum
                         ]);
            } else {
                $this->db->table('payroll_attendance')->insert([
                    'period_id' => $periodId,
                    'pkwt_id' => $pkwt->id,
                    'hari_kerja' => 20,
                    'jam_overtime' => 0.0,
                    'early_arrival_minutes' => $eaSum,
                    'deduction_absensi' => 0.0,
                    'bonus_tambahan' => 0.0
                ]);
            }
        }
    }

    public function getPayrollSummaryReport()
    {
        $clientId  = $this->request->getGet('client_id');
        $year     = $this->request->getGet('year');
        $startDate = $this->request->getGet('start_date');
        $endDate   = $this->request->getGet('end_date');

        $startCode = null;
        if (!empty($startDate)) {
            $ts = strtotime($startDate);
            if ($ts !== false) {
                $startCode = ((int)date('Y', $ts) * 100) + (int)date('n', $ts);
            }
        }

        $endCode = null;
        if (!empty($endDate)) {
            $ts = strtotime($endDate);
            if ($ts !== false) {
                $endCode = ((int)date('Y', $ts) * 100) + (int)date('n', $ts);
            }
        }

        // 1. Get client options
        $clients = $this->db->table('clients')->select('id, nama, sector')->orderBy('nama', 'ASC')->get()->getResultArray();

        // 2. Query payroll_final summary grouped by client & period
        $builderFinal = $this->db->table('payroll_final pf')
            ->select('c.id as client_id, c.nama as client_name, pp.year, pp.month, 
                COUNT(pf.id) as total_karyawan, 
                SUM(COALESCE(pf.gaji_pokok,0)) as total_gaji_pokok, 
                SUM(COALESCE(pf.total_revenue,0)) as total_revenue, 
                SUM(COALESCE(pf.total_deduction,0)) as total_deduction, 
                SUM(COALESCE(pf.take_home_pay,0)) as total_thp,
                SUM(COALESCE(pf.bpjs_kes_company,0)) as bpjs_kes_company,
                SUM(COALESCE(pf.bpjs_jht_company,0) + COALESCE(pf.bpjs_jp_company,0) + COALESCE(pf.bpjs_jkk_company,0) + COALESCE(pf.bpjs_jkm_company,0)) as bpjs_tk_company,
                SUM(COALESCE(pf.bpjs_kes_karyawan,0)) as bpjs_kes_karyawan,
                SUM(COALESCE(pf.bpjs_jht_karyawan,0) + COALESCE(pf.bpjs_jp_karyawan,0)) as bpjs_tk_karyawan,
                SUM(COALESCE(pf.deduction_absen,0)) as deduction_absen,
                SUM(COALESCE(pf.bpjs_kes_company,0) + COALESCE(pf.bpjs_jht_company,0) + COALESCE(pf.bpjs_jp_company,0) + COALESCE(pf.bpjs_jkk_company,0) + COALESCE(pf.bpjs_jkm_company,0)) as bpjs_company,
                SUM(COALESCE(pf.bpjs_kes_karyawan,0) + COALESCE(pf.bpjs_jht_karyawan,0) + COALESCE(pf.bpjs_jp_karyawan,0)) as bpjs_karyawan,
                SUM(COALESCE(pf.pph21,0)) as total_pph21')
            ->join('payroll_periods pp', 'pp.id = pf.period_id')
            ->join('clients c', 'c.id = pp.client_id')
            ->groupBy('c.id, c.nama, pp.year, pp.month')
            ->orderBy('pp.year', 'ASC')
            ->orderBy('pp.month', 'ASC')
            ->orderBy('c.nama', 'ASC');

        if (!empty($clientId) && $clientId !== 'all') {
            $builderFinal->where('c.id', $clientId);
        }
        if (!empty($startCode)) {
            $builderFinal->where('((pp.year * 100) + pp.month) >=', $startCode);
        }
        if (!empty($endCode)) {
            $builderFinal->where('((pp.year * 100) + pp.month) <=', $endCode);
        }
        if (empty($startCode) && empty($endCode) && !empty($year) && $year !== 'all') {
            $builderFinal->where('pp.year', $year);
        }

        $finalSummary = $builderFinal->get()->getResultArray();

        // 3. Query legacy payrolls summary grouped by client & period
        $builderLegacy = $this->db->table('payrolls p')
            ->select('c.id as client_id, c.nama as client_name, p.year, p.month, 
                COUNT(p.id) as total_karyawan, 
                SUM(COALESCE(p.gaji_pokok,0)) as total_gaji_pokok, 
                SUM(COALESCE(p.gaji_pokok,0) + COALESCE(p.total_allowance,0) + COALESCE(p.overtime_pay,0) + COALESCE(p.bonus_tambahan,0)) as total_revenue, 
                SUM(COALESCE(p.total_deduction,0) + COALESCE(p.deduction_absen,0)) as total_deduction, 
                SUM(COALESCE(p.take_home_pay,0)) as total_thp,
                SUM(COALESCE(p.bpjs_kes_company,0)) as bpjs_kes_company,
                SUM(COALESCE(p.bpjs_jht_company,0) + COALESCE(p.bpjs_jp_company,0) + COALESCE(p.bpjs_jkk_company,0) + COALESCE(p.bpjs_jkm_company,0)) as bpjs_tk_company,
                SUM(COALESCE(p.bpjs_kes_karyawan,0)) as bpjs_kes_karyawan,
                SUM(COALESCE(p.bpjs_jht_karyawan,0) + COALESCE(p.bpjs_jp_karyawan,0)) as bpjs_tk_karyawan,
                SUM(COALESCE(p.deduction_absen,0)) as deduction_absen,
                SUM(COALESCE(p.bpjs_kes_company,0) + COALESCE(p.bpjs_jht_company,0) + COALESCE(p.bpjs_jp_company,0) + COALESCE(p.bpjs_jkk_company,0) + COALESCE(p.bpjs_jkm_company,0)) as bpjs_company,
                SUM(COALESCE(p.bpjs_kes_karyawan,0) + COALESCE(p.bpjs_jht_karyawan,0) + COALESCE(p.bpjs_jp_karyawan,0)) as bpjs_karyawan,
                SUM(COALESCE(p.pph21,0)) as total_pph21')
            ->join('employees e', 'e.id = p.employee_id')
            ->join('clients c', 'c.id = e.client_id')
            ->groupBy('c.id, c.nama, p.year, p.month')
            ->orderBy('p.year', 'ASC')
            ->orderBy('p.month', 'ASC')
            ->orderBy('c.nama', 'ASC');

        if (!empty($clientId) && $clientId !== 'all') {
            $builderLegacy->where('c.id', $clientId);
        }
        if (!empty($startCode)) {
            $builderLegacy->where('((p.year * 100) + p.month) >=', $startCode);
        }
        if (!empty($endCode)) {
            $builderLegacy->where('((p.year * 100) + p.month) <=', $endCode);
        }
        if (empty($startCode) && empty($endCode) && !empty($year) && $year !== 'all') {
            $builderLegacy->where('p.year', $year);
        }

        $legacySummary = $builderLegacy->get()->getResultArray();

        // Merge records by client_id, year, month
        $mergedMap = [];
        $monthNames = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];

        foreach (array_merge($finalSummary, $legacySummary) as $row) {
            $key = $row['client_id'] . '_' . $row['year'] . '_' . sprintf('%02d', $row['month']);
            if (!isset($mergedMap[$key])) {
                $bNum = (int)$row['month'];
                $tNum = (int)$row['year'];
                $mergedMap[$key] = [
                    'client_id' => (int)$row['client_id'],
                    'client_name' => $row['client_name'],
                    'year' => $tNum,
                    'month' => $bNum,
                    'month_year_label' => ($monthNames[$bNum] ?? $bNum) . ' ' . $tNum,
                    'total_karyawan' => 0,
                    'total_gaji_pokok' => 0.0,
                    'total_revenue' => 0.0,
                    'total_deduction' => 0.0,
                    'total_thp' => 0.0,
                    'bpjs_kes_company' => 0.0,
                    'bpjs_tk_company' => 0.0,
                    'bpjs_kes_karyawan' => 0.0,
                    'bpjs_tk_karyawan' => 0.0,
                    'deduction_absen' => 0.0,
                    'bpjs_company' => 0.0,
                    'bpjs_karyawan' => 0.0,
                    'total_pph21' => 0.0,
                ];
            }
            $mergedMap[$key]['total_karyawan'] += (int)$row['total_karyawan'];
            $mergedMap[$key]['total_gaji_pokok'] += (float)$row['total_gaji_pokok'];
            $mergedMap[$key]['total_revenue'] += (float)$row['total_revenue'];
            $mergedMap[$key]['total_deduction'] += (float)$row['total_deduction'];
            $mergedMap[$key]['total_thp'] += (float)$row['total_thp'];
            $mergedMap[$key]['bpjs_kes_company'] += (float)($row['bpjs_kes_company'] ?? 0);
            $mergedMap[$key]['bpjs_tk_company'] += (float)($row['bpjs_tk_company'] ?? 0);
            $mergedMap[$key]['bpjs_kes_karyawan'] += (float)($row['bpjs_kes_karyawan'] ?? 0);
            $mergedMap[$key]['bpjs_tk_karyawan'] += (float)($row['bpjs_tk_karyawan'] ?? 0);
            $mergedMap[$key]['deduction_absen'] += (float)($row['deduction_absen'] ?? 0);
            $mergedMap[$key]['bpjs_company'] += (float)$row['bpjs_company'];
            $mergedMap[$key]['bpjs_karyawan'] += (float)$row['bpjs_karyawan'];
            $mergedMap[$key]['total_pph21'] += (float)$row['total_pph21'];
        }

        // Sort chronologically by year, month, client_name
        uksort($mergedMap, function($a, $b) use ($mergedMap) {
            $itemA = $mergedMap[$a];
            $itemB = $mergedMap[$b];
            if ($itemA['year'] != $itemB['year']) return $itemA['year'] <=> $itemB['year'];
            if ($itemA['month'] != $itemB['month']) return $itemA['month'] <=> $itemB['month'];
            return strcmp($itemA['client_name'], $itemB['client_name']);
        });
        
        $reportData = array_values($mergedMap);

        // Calculate Month-on-Month Growth (%) per Client
        $prevThpPerClient = [];
        $prevEmpPerClient = [];
        foreach ($reportData as &$item) {
            $cid = $item['client_id'];
            $currThp = $item['total_thp'];
            $currEmp = $item['total_karyawan'];

            if (isset($prevThpPerClient[$cid]) && $prevThpPerClient[$cid] > 0) {
                $diff = $currThp - $prevThpPerClient[$cid];
                $item['mom_growth_percent'] = round(($diff / $prevThpPerClient[$cid]) * 100, 2);
                $item['mom_diff_amount'] = $diff;
            } else {
                $item['mom_growth_percent'] = 0.0;
                $item['mom_diff_amount'] = 0.0;
            }

            if (isset($prevEmpPerClient[$cid]) && $prevEmpPerClient[$cid] > 0) {
                $item['mom_emp_diff'] = $currEmp - $prevEmpPerClient[$cid];
            } else {
                $item['mom_emp_diff'] = 0;
            }

            $prevThpPerClient[$cid] = $currThp;
            $prevEmpPerClient[$cid] = $currEmp;
        }
        unset($item);

        // Calculate Overall Summary & Headcount (Avoid summing headcount across multiple periods for same client)
        $grandTotalThp = array_sum(array_column($reportData, 'total_thp'));
        $grandTotalGp = array_sum(array_column($reportData, 'total_gaji_pokok'));
        $grandTotalRevenue = array_sum(array_column($reportData, 'total_revenue'));
        $grandTotalDeduction = array_sum(array_column($reportData, 'total_deduction'));
        $totalBpjsCompany = array_sum(array_column($reportData, 'bpjs_company'));
        $totalBpjsKesCo = array_sum(array_column($reportData, 'bpjs_kes_company'));
        $totalBpjsTkCo = array_sum(array_column($reportData, 'bpjs_tk_company'));
        $totalBpjsKesEmp = array_sum(array_column($reportData, 'bpjs_kes_karyawan'));
        $totalBpjsTkEmp = array_sum(array_column($reportData, 'bpjs_tk_karyawan'));
        $totalDeductionAbsen = array_sum(array_column($reportData, 'deduction_absen'));

        if (!empty($clientId) && $clientId !== 'all') {
            $empCount = $this->db->table('employees')->where('client_id', $clientId)->countAllResults();
            if ($empCount === 0 && !empty($reportData)) {
                $lastRow = end($reportData);
                $empCount = (int)$lastRow['total_karyawan'];
            }
            $totalHeadcount = $empCount;
        } else {
            $latestPerClient = [];
            foreach ($reportData as $row) {
                $latestPerClient[$row['client_id']] = (int)$row['total_karyawan'];
            }
            $totalHeadcount = array_sum($latestPerClient);
        }

        return $this->respond([
            'status' => 'success',
            'clients' => $clients,
            'data' => $reportData,
            'summary' => [
                'total_thp' => $grandTotalThp,
                'total_gaji_pokok' => $grandTotalGp,
                'total_revenue' => $grandTotalRevenue,
                'total_deduction' => $grandTotalDeduction,
                'total_headcount' => $totalHeadcount,
                'total_bpjs_company' => $totalBpjsPerusahaan,
                'bpjs_kes_company' => $totalBpjsKesCo,
                'bpjs_tk_company' => $totalBpjsTkCo,
                'bpjs_kes_karyawan' => $totalBpjsKesEmp,
                'bpjs_tk_karyawan' => $totalBpjsTkEmp,
                'deduction_absen' => $totalDeductionAbsen,
                'avg_thp_per_employee' => $totalHeadcount > 0 ? round($grandTotalThp / $totalHeadcount, 2) : 0,
            ]
        ]);
    }

    public function getBpjsReport()
    {
        $clientId  = $this->request->getGet('client_id');
        $year     = $this->request->getGet('year');
        $startDate = $this->request->getGet('start_date');
        $endDate   = $this->request->getGet('end_date');
        $viewMode  = $this->request->getGet('view_mode') ?? 'summary';

        $startCode = null;
        if (!empty($startDate)) {
            $ts = strtotime($startDate);
            if ($ts !== false) {
                $startCode = ((int)date('Y', $ts) * 100) + (int)date('n', $ts);
            }
        }

        $endCode = null;
        if (!empty($endDate)) {
            $ts = strtotime($endDate);
            if ($ts !== false) {
                $endCode = ((int)date('Y', $ts) * 100) + (int)date('n', $ts);
            }
        }

        $clients = $this->db->table('clients')->select('id, nama, sector')->orderBy('nama', 'ASC')->get()->getResultArray();

        $monthNames = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];

        if ($viewMode === 'employee') {
            $builderFinal = $this->db->table('payroll_final pf')
                ->select('c.id as client_id, c.nama as client_name, pp.year, pp.month, pkwt.employee_name, CAST(pkwt.id AS VARCHAR) as employ_id, pkwt.position_name,
                    COALESCE(pf.bpjs_kes_karyawan,0) as bpjs_kes_karyawan,
                    COALESCE(pf.bpjs_kes_company,0) as bpjs_kes_company,
                    COALESCE(pf.bpjs_jht_karyawan,0) as bpjs_jht_karyawan,
                    COALESCE(pf.bpjs_jht_company,0) as bpjs_jht_company,
                    COALESCE(pf.bpjs_jp_karyawan,0) as bpjs_jp_karyawan,
                    COALESCE(pf.bpjs_jp_company,0) as bpjs_jp_company,
                    COALESCE(pf.bpjs_jkk_company,0) as bpjs_jkk_company,
                    COALESCE(pf.bpjs_jkm_company,0) as bpjs_jkm_company')
                ->join('payroll_periods pp', 'pp.id = pf.period_id')
                ->join('clients c', 'c.id = pp.client_id')
                ->join('pkwt', 'pkwt.id = pf.pkwt_id', 'left')
                ->orderBy('pp.year', 'DESC')
                ->orderBy('pp.month', 'DESC')
                ->orderBy('c.nama', 'ASC')
                ->orderBy('pkwt.employee_name', 'ASC');

            if (!empty($clientId) && $clientId !== 'all') {
                $builderFinal->where('c.id', $clientId);
            }
            if (!empty($startCode)) {
                $builderFinal->where('((pp.year * 100) + pp.month) >=', $startCode);
            }
            if (!empty($endCode)) {
                $builderFinal->where('((pp.year * 100) + pp.month) <=', $endCode);
            }
            if (empty($startCode) && empty($endCode) && !empty($year) && $year !== 'all') {
                $builderFinal->where('pp.year', $year);
            }

            $finalList = $builderFinal->get()->getResultArray();

            $builderLegacy = $this->db->table('payrolls p')
                ->select('c.id as client_id, c.nama as client_name, p.year, p.month, e.nama as employee_name, e.employ_id, COALESCE(pos.nama, \'-\') as position_name,
                    COALESCE(p.bpjs_kes_karyawan,0) as bpjs_kes_karyawan,
                    COALESCE(p.bpjs_kes_company,0) as bpjs_kes_company,
                    COALESCE(p.bpjs_jht_karyawan,0) as bpjs_jht_karyawan,
                    COALESCE(p.bpjs_jht_company,0) as bpjs_jht_company,
                    COALESCE(p.bpjs_jp_karyawan,0) as bpjs_jp_karyawan,
                    COALESCE(p.bpjs_jp_company,0) as bpjs_jp_company,
                    COALESCE(p.bpjs_jkk_company,0) as bpjs_jkk_company,
                    COALESCE(p.bpjs_jkm_company,0) as bpjs_jkm_company')
                ->join('employees e', 'e.id = p.employee_id')
                ->join('clients c', 'c.id = e.client_id')
                ->join('positions pos', 'pos.id = e.position_id', 'left')
                ->orderBy('p.year', 'DESC')
                ->orderBy('p.month', 'DESC')
                ->orderBy('c.nama', 'ASC')
                ->orderBy('e.nama', 'ASC');

            if (!empty($clientId) && $clientId !== 'all') {
                $builderLegacy->where('c.id', $clientId);
            }
            if (!empty($startCode)) {
                $builderLegacy->where('((p.year * 100) + p.month) >=', $startCode);
            }
            if (!empty($endCode)) {
                $builderLegacy->where('((p.year * 100) + p.month) <=', $endCode);
            }
            if (empty($startCode) && empty($endCode) && !empty($year) && $year !== 'all') {
                $builderLegacy->where('p.year', $year);
            }

            $legacyList = $builderLegacy->get()->getResultArray();
            $rawList = array_merge($finalList, $legacyList);

            $reportData = [];
            foreach ($rawList as $row) {
                $bNum = (int)$row['month'];
                $tNum = (int)$row['year'];
                $kesEmp = (float)$row['bpjs_kes_karyawan'];
                $kesCo  = (float)$row['bpjs_kes_company'];
                $subtotalKes = $kesEmp + $kesCo;

                $jhtEmp = (float)$row['bpjs_jht_karyawan'];
                $jhtCo  = (float)$row['bpjs_jht_company'];
                $jpEmp  = (float)$row['bpjs_jp_karyawan'];
                $jpCo   = (float)$row['bpjs_jp_company'];
                $jkkCo  = (float)$row['bpjs_jkk_company'];
                $jkmCo  = (float)$row['bpjs_jkm_company'];

                $tkEmp = $jhtEmp + $jpEmp;
                $tkCo  = $jhtCo + $jpCo + $jkkCo + $jkmCo;
                $subtotalTk = $tkEmp + $tkCo;

                $totalPribadi = $kesEmp + $tkEmp;
                $totalCompany = $kesCo + $tkCo;
                $grandTotal   = $totalPribadi + $totalCompany;

                $reportData[] = [
                    'client_id' => (int)$row['client_id'],
                    'client_name' => $row['client_name'] ?? '-',
                    'year' => $tNum,
                    'month' => $bNum,
                    'month_year_label' => ($monthNames[$bNum] ?? $bNum) . ' ' . $tNum,
                    'employee_name' => $row['employee_name'] ?? '-',
                    'employ_id' => $row['employ_id'] ?? '-',
                    'position_name' => $row['position_name'] ?? '-',
                    'bpjs_kes_karyawan' => $kesEmp,
                    'bpjs_kes_company' => $kesCo,
                    'subtotal_bpjs_kes' => $subtotalKes,
                    'bpjs_jht_karyawan' => $jhtEmp,
                    'bpjs_jht_company' => $jhtCo,
                    'bpjs_jp_karyawan' => $jpEmp,
                    'bpjs_jp_company' => $jpCo,
                    'bpjs_jkk_company' => $jkkCo,
                    'bpjs_jkm_company' => $jkmCo,
                    'bpjs_tk_karyawan' => $tkEmp,
                    'bpjs_tk_company' => $tkCo,
                    'subtotal_bpjs_tk' => $subtotalTk,
                    'total_pribadi' => $totalPribadi,
                    'total_company' => $totalCompany,
                    'grand_total_bpjs' => $grandTotal
                ];
            }
        } else {
            $builderFinal = $this->db->table('payroll_final pf')
                ->select('c.id as client_id, c.nama as client_name, pp.year, pp.month, 
                    COUNT(pf.id) as total_karyawan, 
                    SUM(COALESCE(pf.bpjs_kes_karyawan,0)) as bpjs_kes_karyawan, 
                    SUM(COALESCE(pf.bpjs_kes_company,0)) as bpjs_kes_company,
                    SUM(COALESCE(pf.bpjs_jht_karyawan,0)) as bpjs_jht_karyawan, 
                    SUM(COALESCE(pf.bpjs_jht_company,0)) as bpjs_jht_company,
                    SUM(COALESCE(pf.bpjs_jp_karyawan,0)) as bpjs_jp_karyawan, 
                    SUM(COALESCE(pf.bpjs_jp_company,0)) as bpjs_jp_company,
                    SUM(COALESCE(pf.bpjs_jkk_company,0)) as bpjs_jkk_company,
                    SUM(COALESCE(pf.bpjs_jkm_company,0)) as bpjs_jkm_company')
                ->join('payroll_periods pp', 'pp.id = pf.period_id')
                ->join('clients c', 'c.id = pp.client_id')
                ->groupBy('c.id, c.nama, pp.year, pp.month')
                ->orderBy('pp.year', 'ASC')
                ->orderBy('pp.month', 'ASC')
                ->orderBy('c.nama', 'ASC');

            if (!empty($clientId) && $clientId !== 'all') {
                $builderFinal->where('c.id', $clientId);
            }
            if (!empty($startCode)) {
                $builderFinal->where('((pp.year * 100) + pp.month) >=', $startCode);
            }
            if (!empty($endCode)) {
                $builderFinal->where('((pp.year * 100) + pp.month) <=', $endCode);
            }
            if (empty($startCode) && empty($endCode) && !empty($year) && $year !== 'all') {
                $builderFinal->where('pp.year', $year);
            }

            $finalSummary = $builderFinal->get()->getResultArray();

            $builderLegacy = $this->db->table('payrolls p')
                ->select('c.id as client_id, c.nama as client_name, p.year, p.month, 
                    COUNT(p.id) as total_karyawan, 
                    SUM(COALESCE(p.bpjs_kes_karyawan,0)) as bpjs_kes_karyawan, 
                    SUM(COALESCE(p.bpjs_kes_company,0)) as bpjs_kes_company,
                    SUM(COALESCE(p.bpjs_jht_karyawan,0)) as bpjs_jht_karyawan, 
                    SUM(COALESCE(p.bpjs_jht_company,0)) as bpjs_jht_company,
                    SUM(COALESCE(p.bpjs_jp_karyawan,0)) as bpjs_jp_karyawan, 
                    SUM(COALESCE(p.bpjs_jp_company,0)) as bpjs_jp_company,
                    SUM(COALESCE(p.bpjs_jkk_company,0)) as bpjs_jkk_company,
                    SUM(COALESCE(p.bpjs_jkm_company,0)) as bpjs_jkm_company')
                ->join('employees e', 'e.id = p.employee_id')
                ->join('clients c', 'c.id = e.client_id')
                ->groupBy('c.id, c.nama, p.year, p.month')
                ->orderBy('p.year', 'ASC')
                ->orderBy('p.month', 'ASC')
                ->orderBy('c.nama', 'ASC');

            if (!empty($clientId) && $clientId !== 'all') {
                $builderLegacy->where('c.id', $clientId);
            }
            if (!empty($startCode)) {
                $builderLegacy->where('((p.year * 100) + p.month) >=', $startCode);
            }
            if (!empty($endCode)) {
                $builderLegacy->where('((p.year * 100) + p.month) <=', $endCode);
            }
            if (empty($startCode) && empty($endCode) && !empty($year) && $year !== 'all') {
                $builderLegacy->where('p.year', $year);
            }

            $legacySummary = $builderLegacy->get()->getResultArray();

            $mergedMap = [];
            foreach (array_merge($finalSummary, $legacySummary) as $row) {
                $key = $row['client_id'] . '_' . $row['year'] . '_' . sprintf('%02d', $row['month']);
                if (!isset($mergedMap[$key])) {
                    $bNum = (int)$row['month'];
                    $tNum = (int)$row['year'];
                    $mergedMap[$key] = [
                        'client_id' => (int)$row['client_id'],
                        'client_name' => $row['client_name'],
                        'year' => $tNum,
                        'month' => $bNum,
                        'month_year_label' => ($monthNames[$bNum] ?? $bNum) . ' ' . $tNum,
                        'total_karyawan' => 0,
                        'bpjs_kes_karyawan' => 0.0,
                        'bpjs_kes_company' => 0.0,
                        'bpjs_jht_karyawan' => 0.0,
                        'bpjs_jht_company' => 0.0,
                        'bpjs_jp_karyawan' => 0.0,
                        'bpjs_jp_company' => 0.0,
                        'bpjs_jkk_company' => 0.0,
                        'bpjs_jkm_company' => 0.0,
                    ];
                }
                $mergedMap[$key]['total_karyawan'] += (int)$row['total_karyawan'];
                $mergedMap[$key]['bpjs_kes_karyawan'] += (float)$row['bpjs_kes_karyawan'];
                $mergedMap[$key]['bpjs_kes_company'] += (float)$row['bpjs_kes_company'];
                $mergedMap[$key]['bpjs_jht_karyawan'] += (float)$row['bpjs_jht_karyawan'];
                $mergedMap[$key]['bpjs_jht_company'] += (float)$row['bpjs_jht_company'];
                $mergedMap[$key]['bpjs_jp_karyawan'] += (float)$row['bpjs_jp_karyawan'];
                $mergedMap[$key]['bpjs_jp_company'] += (float)$row['bpjs_jp_company'];
                $mergedMap[$key]['bpjs_jkk_company'] += (float)$row['bpjs_jkk_company'];
                $mergedMap[$key]['bpjs_jkm_company'] += (float)$row['bpjs_jkm_company'];
            }

            uksort($mergedMap, function($a, $b) use ($mergedMap) {
                $itemA = $mergedMap[$a];
                $itemB = $mergedMap[$b];
                if ($itemA['year'] != $itemB['year']) return $itemA['year'] <=> $itemB['year'];
                if ($itemA['month'] != $itemB['month']) return $itemA['month'] <=> $itemB['month'];
                return strcmp($itemA['client_name'], $itemB['client_name']);
            });

            $reportData = [];
            foreach (array_values($mergedMap) as $item) {
                $kesEmp = $item['bpjs_kes_karyawan'];
                $kesCo  = $item['bpjs_kes_company'];
                $subtotalKes = $kesEmp + $kesCo;

                $jhtEmp = $item['bpjs_jht_karyawan'];
                $jhtCo  = $item['bpjs_jht_company'];
                $jpEmp  = $item['bpjs_jp_karyawan'];
                $jpCo   = $item['bpjs_jp_company'];
                $jkkCo  = $item['bpjs_jkk_company'];
                $jkmCo  = $item['bpjs_jkm_company'];

                $tkEmp = $jhtEmp + $jpEmp;
                $tkCo  = $jhtCo + $jpCo + $jkkCo + $jkmCo;
                $subtotalTk = $tkEmp + $tkCo;

                $totalPribadi = $kesEmp + $tkEmp;
                $totalCompany = $kesCo + $tkCo;
                $grandTotal   = $totalPribadi + $totalCompany;

                $item['subtotal_bpjs_kes'] = $subtotalKes;
                $item['bpjs_tk_karyawan']  = $tkEmp;
                $item['bpjs_tk_company']= $tkCo;
                $item['subtotal_bpjs_tk']  = $subtotalTk;
                $item['total_pribadi']     = $totalPribadi;
                $item['total_company']     = $totalCompany;
                $item['grand_total_bpjs']  = $grandTotal;

                $reportData[] = $item;
            }
        }

        $totalKesEmp = array_sum(array_column($reportData, 'bpjs_kes_karyawan'));
        $totalKesCo  = array_sum(array_column($reportData, 'bpjs_kes_company'));
        $totalKes    = array_sum(array_column($reportData, 'subtotal_bpjs_kes'));

        $totalTkEmp  = array_sum(array_column($reportData, 'bpjs_tk_karyawan'));
        $totalTkCo   = array_sum(array_column($reportData, 'bpjs_tk_company'));
        $totalTk     = array_sum(array_column($reportData, 'subtotal_bpjs_tk'));

        $totalPribadi = array_sum(array_column($reportData, 'total_pribadi'));
        $totalCompany = array_sum(array_column($reportData, 'total_company'));
        $grandTotal   = array_sum(array_column($reportData, 'grand_total_bpjs'));

        return $this->respond([
            'status' => 'success',
            'view_mode' => $viewMode,
            'clients' => $clients,
            'data' => $reportData,
            'summary' => [
                'total_bpjs_kes_karyawan' => $totalKesEmp,
                'total_bpjs_kes_company' => $totalKesCo,
                'total_bpjs_kes' => $totalKes,
                'total_bpjs_tk_karyawan' => $totalTkEmp,
                'total_bpjs_tk_company' => $totalTkCo,
                'total_bpjs_tk' => $totalTk,
                'total_bpjs_pribadi' => $totalPribadi,
                'total_bpjs_company' => $totalCompany,
                'grand_total_bpjs' => $grandTotal,
            ]
        ]);
    }

    public function getTaxReport()
    {
        $clientId  = $this->request->getGet('client_id');
        $year     = $this->request->getGet('year');
        $startDate = $this->request->getGet('start_date');
        $endDate   = $this->request->getGet('end_date');
        $viewMode  = $this->request->getGet('view_mode') ?? 'summary';

        $startCode = null;
        if (!empty($startDate)) {
            $ts = strtotime($startDate);
            if ($ts !== false) {
                $startCode = ((int)date('Y', $ts) * 100) + (int)date('n', $ts);
            }
        }

        $endCode = null;
        if (!empty($endDate)) {
            $ts = strtotime($endDate);
            if ($ts !== false) {
                $endCode = ((int)date('Y', $ts) * 100) + (int)date('n', $ts);
            }
        }

        $clients = $this->db->table('clients')->select('id, nama, sector')->orderBy('nama', 'ASC')->get()->getResultArray();

        $monthNames = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
        ];

        if ($viewMode === 'employee') {
            $builderFinal = $this->db->table('payroll_final pf')
                ->select('c.id as client_id, c.nama as client_name, pp.year, pp.month, pkwt.employee_name, CAST(pkwt.id AS VARCHAR) as employ_id, pkwt.position_name,
                    COALESCE(pf.gaji_pokok,0) as gaji_pokok,
                    COALESCE(pf.tax_allowance,0) as tax_allowance,
                    COALESCE(pf.pph21,0) as pph21,
                    COALESCE(pf.tax_method,\'Gross\') as tax_method,
                    COALESCE(pf.ptkp_status,\'TK/0\') as ptkp_status,
                    COALESCE(pf.take_home_pay,0) as take_home_pay')
                ->join('payroll_periods pp', 'pp.id = pf.period_id')
                ->join('clients c', 'c.id = pp.client_id')
                ->join('pkwt', 'pkwt.id = pf.pkwt_id', 'left')
                ->orderBy('pp.year', 'DESC')
                ->orderBy('pp.month', 'DESC')
                ->orderBy('c.nama', 'ASC')
                ->orderBy('pkwt.employee_name', 'ASC');

            if (!empty($clientId) && $clientId !== 'all') {
                $builderFinal->where('c.id', $clientId);
            }
            if (!empty($startCode)) {
                $builderFinal->where('((pp.year * 100) + pp.month) >=', $startCode);
            }
            if (!empty($endCode)) {
                $builderFinal->where('((pp.year * 100) + pp.month) <=', $endCode);
            }
            if (empty($startCode) && empty($endCode) && !empty($year) && $year !== 'all') {
                $builderFinal->where('pp.year', $year);
            }

            $finalList = $builderFinal->get()->getResultArray();

            $builderLegacy = $this->db->table('payrolls p')
                ->select('c.id as client_id, c.nama as client_name, p.year, p.month, e.nama as employee_name, e.employ_id, COALESCE(pos.nama, \'-\') as position_name,
                    COALESCE(p.gaji_pokok,0) as gaji_pokok,
                    COALESCE(p.tax_allowance,0) as tax_allowance,
                    COALESCE(p.pph21,0) as pph21,
                    COALESCE(p.tax_method,\'Gross\') as tax_method,
                    COALESCE(p.ptkp_status,\'TK/0\') as ptkp_status,
                    COALESCE(p.take_home_pay,0) as take_home_pay')
                ->join('employees e', 'e.id = p.employee_id')
                ->join('clients c', 'c.id = e.client_id')
                ->join('positions pos', 'pos.id = e.position_id', 'left')
                ->orderBy('p.year', 'DESC')
                ->orderBy('p.month', 'DESC')
                ->orderBy('c.nama', 'ASC')
                ->orderBy('e.nama', 'ASC');

            if (!empty($clientId) && $clientId !== 'all') {
                $builderLegacy->where('c.id', $clientId);
            }
            if (!empty($startCode)) {
                $builderLegacy->where('((p.year * 100) + p.month) >=', $startCode);
            }
            if (!empty($endCode)) {
                $builderLegacy->where('((p.year * 100) + p.month) <=', $endCode);
            }
            if (empty($startCode) && empty($endCode) && !empty($year) && $year !== 'all') {
                $builderLegacy->where('p.year', $year);
            }

            $legacyList = $builderLegacy->get()->getResultArray();

            $reportData = [];
            foreach (array_merge($finalList, $legacyList) as $item) {
                $bNum = (int)$item['month'];
                $tNum = (int)$item['year'];
                $item['month_year_label'] = ($monthNames[$bNum] ?? $bNum) . ' ' . $tNum;
                $item['gaji_pokok']        = (float)$item['gaji_pokok'];
                $item['tax_allowance']     = (float)$item['tax_allowance'];
                $item['pph21']             = (float)$item['pph21'];
                $item['take_home_pay']     = (float)$item['take_home_pay'];
                $reportData[] = $item;
            }
        } else {
            $builderFinal = $this->db->table('payroll_final pf')
                ->select('c.id as client_id, c.nama as client_name, pp.year, pp.month,
                    COUNT(pf.id) as total_karyawan,
                    SUM(COALESCE(pf.gaji_pokok,0)) as total_gaji_pokok,
                    SUM(COALESCE(pf.tax_allowance,0)) as total_tax_allowance,
                    SUM(COALESCE(pf.pph21,0)) as total_pph21,
                    SUM(COALESCE(pf.take_home_pay,0)) as total_take_home_pay')
                ->join('payroll_periods pp', 'pp.id = pf.period_id')
                ->join('clients c', 'c.id = pp.client_id')
                ->groupBy('c.id, c.nama, pp.year, pp.month')
                ->orderBy('pp.year', 'ASC')
                ->orderBy('pp.month', 'ASC')
                ->orderBy('c.nama', 'ASC');

            if (!empty($clientId) && $clientId !== 'all') {
                $builderFinal->where('c.id', $clientId);
            }
            if (!empty($startCode)) {
                $builderFinal->where('((pp.year * 100) + pp.month) >=', $startCode);
            }
            if (!empty($endCode)) {
                $builderFinal->where('((pp.year * 100) + pp.month) <=', $endCode);
            }
            if (empty($startCode) && empty($endCode) && !empty($year) && $year !== 'all') {
                $builderFinal->where('pp.year', $year);
            }

            $finalSummary = $builderFinal->get()->getResultArray();

            $builderLegacy = $this->db->table('payrolls p')
                ->select('c.id as client_id, c.nama as client_name, p.year, p.month,
                    COUNT(p.id) as total_karyawan,
                    SUM(COALESCE(p.gaji_pokok,0)) as total_gaji_pokok,
                    SUM(COALESCE(p.tax_allowance,0)) as total_tax_allowance,
                    SUM(COALESCE(p.pph21,0)) as total_pph21,
                    SUM(COALESCE(p.take_home_pay,0)) as total_take_home_pay')
                ->join('employees e', 'e.id = p.employee_id')
                ->join('clients c', 'c.id = e.client_id')
                ->groupBy('c.id, c.nama, p.year, p.month')
                ->orderBy('p.year', 'ASC')
                ->orderBy('p.month', 'ASC')
                ->orderBy('c.nama', 'ASC');

            if (!empty($clientId) && $clientId !== 'all') {
                $builderLegacy->where('c.id', $clientId);
            }
            if (!empty($startCode)) {
                $builderLegacy->where('((p.year * 100) + p.month) >=', $startCode);
            }
            if (!empty($endCode)) {
                $builderLegacy->where('((p.year * 100) + p.month) <=', $endCode);
            }
            if (empty($startCode) && empty($endCode) && !empty($year) && $year !== 'all') {
                $builderLegacy->where('p.year', $year);
            }

            $legacySummary = $builderLegacy->get()->getResultArray();

            $mergedMap = [];
            foreach (array_merge($finalSummary, $legacySummary) as $row) {
                $key = $row['client_id'] . '_' . $row['year'] . '_' . sprintf('%02d', $row['month']);
                if (!isset($mergedMap[$key])) {
                    $bNum = (int)$row['month'];
                    $tNum = (int)$row['year'];
                    $mergedMap[$key] = [
                        'client_id' => (int)$row['client_id'],
                        'client_name' => $row['client_name'],
                        'year' => $tNum,
                        'month' => $bNum,
                        'month_year_label' => ($monthNames[$bNum] ?? $bNum) . ' ' . $tNum,
                        'total_karyawan' => 0,
                        'total_gaji_pokok' => 0.0,
                        'total_tax_allowance' => 0.0,
                        'total_pph21' => 0.0,
                        'total_take_home_pay' => 0.0,
                    ];
                }
                $mergedMap[$key]['total_karyawan'] += (int)$row['total_karyawan'];
                $mergedMap[$key]['total_gaji_pokok'] += (float)$row['total_gaji_pokok'];
                $mergedMap[$key]['total_tax_allowance'] += (float)$row['total_tax_allowance'];
                $mergedMap[$key]['total_pph21'] += (float)$row['total_pph21'];
                $mergedMap[$key]['total_take_home_pay'] += (float)$row['total_take_home_pay'];
            }

            uksort($mergedMap, function($a, $b) use ($mergedMap) {
                $itemA = $mergedMap[$a];
                $itemB = $mergedMap[$b];
                if ($itemA['year'] != $itemB['year']) return $itemA['year'] <=> $itemB['year'];
                if ($itemA['month'] != $itemB['month']) return $itemA['month'] <=> $itemB['month'];
                return strcmp($itemA['client_name'], $itemB['client_name']);
            });

            $reportData = array_values($mergedMap);
        }

        $totalPph21 = array_sum(array_column($reportData, $viewMode === 'employee' ? 'pph21' : 'total_pph21'));
        $totalAllowance = array_sum(array_column($reportData, $viewMode === 'employee' ? 'tax_allowance' : 'total_tax_allowance'));
        $totalBruto = array_sum(array_column($reportData, $viewMode === 'employee' ? 'gaji_pokok' : 'total_gaji_pokok'));

        if ($viewMode === 'employee') {
            $totalHeadcount = count($reportData);
        } else {
            $latestPerClient = [];
            foreach ($reportData as $row) {
                $latestPerClient[$row['client_id']] = (int)$row['total_karyawan'];
            }
            $totalHeadcount = array_sum($latestPerClient);
        }

        return $this->respond([
            'status' => 'success',
            'view_mode' => $viewMode,
            'clients' => $clients,
            'data' => $reportData,
            'summary' => [
                'total_pph21' => $totalPph21,
                'total_tax_allowance' => $totalAllowance,
                'total_bruto' => $totalBruto,
                'total_headcount' => $totalHeadcount,
            ]
        ]);
    }
}


