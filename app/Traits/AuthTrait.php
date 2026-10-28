<?php

namespace App\Traits;

trait AuthTrait
{
    /**
     * Get the decoded JWT user from request (injected by JwtFilter or decoded from Authorization header)
     */
    protected function getAuthUser(): ?object
    {
        if (isset($this->request->jwtUser)) {
            return $this->request->jwtUser;
        }

        $authHeader = $this->request->getHeaderLine('Authorization');
        if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $decoded = \App\Libraries\JwtHelper::validateToken($matches[1]);
            if ($decoded) {
                $this->request->jwtUser = $decoded;
                return $decoded;
            }
        }

        return null;
    }

    protected function getAuthUserId(): int
    {
        $user = $this->getAuthUser();
        return $user ? (int) ($user->user_id ?? 1) : 1;
    }

    protected function getAuthUsername(): string
    {
        $user = $this->getAuthUser();
        return $user ? ($user->username ?? 'System') : ($this->request->getHeaderLine('X-User-Action') ?: 'System');
    }

    protected function getAuthRole(): string
    {
        $user = $this->getAuthUser();
        return $user ? ($user->role ?? 'staff') : 'staff';
    }

    protected function getAuthClients(): array
    {
        $user = $this->getAuthUser();
        return $user && isset($user->clients) && is_array($user->clients) ? $user->clients : [];
    }

    /**
     * Memeriksa apakah client_id diizinkan untuk user yang sedang login.
     * Admin atau allowed_client_ids = ['all'] selalu diizinkan.
     */
    protected function checkClientAccess(?int $clientId): bool
    {
        if (empty($clientId) || $clientId <= 0) {
            return false;
        }

        $role = $this->getAuthRole();
        if ($role === 'admin') {
            return true;
        }

        $allowedClients = $this->getAuthClients();
        if (in_array('all', $allowedClients, true) || in_array((string)$clientId, $allowedClients, false) || in_array((int)$clientId, $allowedClients, true)) {
            return true;
        }

        return false;
    }

    /**
     * Mengembalikan respons standard 403 Forbidden saat client_id ditolak.
     */
    protected function failClientForbidden(string $message = 'Akses ditolak. Anda tidak memiliki izin untuk mengakses data klien ini.')
    {
        return service('response')
            ->setStatusCode(403)
            ->setJSON([
                'status'   => 403,
                'error'    => 403,
                'messages' => ['error' => $message]
            ]);
    }

    /**
     * Mengambil client_id milik entity tertentu berdasarkan ID (Proteksi IDOR).
     */
    protected function getEntityClientId(string $table, int $entityId): ?int
    {
        $db = \Config\Database::connect();

        switch ($table) {
            case 'clients':
                return $entityId;

            case 'employees':
            case 'pkwt':
            case 'contracts':
            case 'payroll_periods':
            case 'contract_compensations':
            case 'payroll_scheme_templates':
            case 'client_payroll_configs':
                $row = $db->table($table)->select('client_id')->where('id', $entityId)->get()->getRow();
                return $row ? (int) $row->client_id : null;

            case 'overtime_logs':
                $row = $db->table('overtime_logs')
                          ->select('employees.client_id')
                          ->join('employees', 'employees.id = overtime_logs.employee_id', 'left')
                          ->where('overtime_logs.id', $entityId)
                          ->get()->getRow();
                return $row ? (int) $row->client_id : null;

            case 'attendance_logs':
                $row = $db->table('attendance_logs')
                          ->select('employees.client_id')
                          ->join('employees', 'employees.id = attendance_logs.employee_id', 'left')
                          ->where('attendance_logs.id', $entityId)
                          ->get()->getRow();
                return $row ? (int) $row->client_id : null;

            case 'early_arrival':
                $row = $db->table('early_arrival')
                          ->select('employees.client_id')
                          ->join('employees', 'employees.id = early_arrival.employee_id', 'left')
                          ->where('early_arrival.id', $entityId)
                          ->get()->getRow();
                return $row ? (int) $row->client_id : null;

            case 'payroll_final':
                $row = $db->table('payroll_final')
                          ->select('pkwt.client_id')
                          ->join('pkwt', 'pkwt.id = payroll_final.pkwt_id', 'left')
                          ->where('payroll_final.id', $entityId)
                          ->get()->getRow();
                return $row ? (int) $row->client_id : null;

            case 'payroll_attendance':
                $row = $db->table('payroll_attendance')
                          ->select('pkwt.client_id')
                          ->join('pkwt', 'pkwt.id = payroll_attendance.pkwt_id', 'left')
                          ->where('payroll_attendance.id', $entityId)
                          ->get()->getRow();
                return $row ? (int) $row->client_id : null;

            case 'payrolls':
                $row = $db->table('payrolls')
                          ->select('employees.client_id')
                          ->join('employees', 'employees.id = payrolls.employee_id', 'left')
                          ->where('payrolls.id', $entityId)
                          ->get()->getRow();
                return $row ? (int) $row->client_id : null;

            default:
                if ($db->fieldExists('client_id', $table)) {
                    $row = $db->table($table)->select('client_id')->where('id', $entityId)->get()->getRow();
                    return $row ? (int) $row->client_id : null;
                }
                return null;
        }
    }

    /**
     * Memeriksa apakah user berhak mengakses entity berdasarkan ID-nya (Proteksi IDOR).
     */
    protected function checkEntityAccess(string $table, int $entityId): bool
    {
        $role = $this->getAuthRole();
        if ($role === 'admin') {
            return true;
        }

        $clientId = $this->getEntityClientId($table, $entityId);
        if ($clientId === null) {
            return false;
        }

        return $this->checkClientAccess($clientId);
    }

    /**
     * True jika user boleh mengakses semua klien (admin atau allowed_client_ids = ['all']).
     */
    protected function hasGlobalClientAccess(): bool
    {
        if ($this->getAuthRole() === 'admin') {
            return true;
        }
        return in_array('all', $this->getAuthClients(), true);
    }

    /**
     * Daftar client_id (int) yang diizinkan untuk user non-global.
     */
    protected function getAllowedClientIdsInt(): array
    {
        $ids = [];
        foreach ($this->getAuthClients() as $c) {
            if (is_numeric($c) && (int) $c > 0) {
                $ids[] = (int) $c;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * Batasi query builder ke klien yang diizinkan (untuk endpoint list tanpa client_id).
     */
    protected function scopeToAllowedClients($builder, string $column)
    {
        if ($this->hasGlobalClientAccess()) {
            return $builder;
        }
        $ids = $this->getAllowedClientIdsInt();
        if (empty($ids)) {
            // Tidak punya akses ke klien manapun -> hasil kosong
            return $builder->where('1 = 0', null, false);
        }
        return $builder->whereIn($column, $ids);
    }

    /**
     * Validasi parameter client_id (jika dikirim). Mengembalikan response 403 jika ditolak, null jika lolos.
     */
    protected function guardClientParam($clientId)
    {
        if ($clientId === null || $clientId === '') {
            return null;
        }
        if (!$this->checkClientAccess((int) $clientId)) {
            return $this->failClientForbidden();
        }
        return null;
    }

    /**
     * Validasi akses entity berdasarkan ID. Mengembalikan response 403/404 jika ditolak, null jika lolos.
     */
    protected function guardEntity(string $table, $entityId)
    {
        if ($this->hasGlobalClientAccess()) {
            return null;
        }
        if (!$this->checkEntityAccess($table, (int) $entityId)) {
            return $this->failClientForbidden('Akses ditolak. Data ini bukan milik klien yang Anda kelola.');
        }
        return null;
    }

    /**
     * Validasi akses untuk banyak ID sekaligus (bulk approve/reject). 403 jika ada satu saja yang ditolak.
     */
    protected function guardEntityIds(string $table, array $ids)
    {
        if ($this->hasGlobalClientAccess()) {
            return null;
        }
        foreach ($ids as $id) {
            if (!$this->checkEntityAccess($table, (int) $id)) {
                return $this->failClientForbidden('Akses ditolak. Sebagian data yang dipilih bukan milik klien yang Anda kelola.');
            }
        }
        return null;
    }
}
