<?php

namespace App\Filters;

use App\Libraries\JwtHelper;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class JwtFilter implements FilterInterface
{
    /**
     * @param RequestInterface $request
     * @param array|null       $arguments Allowed roles, e.g. ['admin', 'payroll', 'hc_ops']
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader) || !preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON([
                    'status'   => 401,
                    'error'    => 401,
                    'messages' => ['error' => 'Akses ditolak. Token otorisasi Bearer tidak ditemukan.']
                ]);
        }

        $token = $matches[1];
        $decoded = JwtHelper::validateToken($token);

        if (!$decoded) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON([
                    'status'   => 401,
                    'error'    => 401,
                    'messages' => ['error' => 'Token tidak valid atau sudah kadaluwarsa. Silakan login kembali.']
                ]);
        }

        // Cek status keaktifan user di database
        $db = \Config\Database::connect();
        $userRecord = $db->table('users')
                         ->select('id, is_active, role')
                         ->where('id', $decoded->user_id)
                         ->get()
                         ->getRow();

        if (!$userRecord) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON([
                    'status'   => 401,
                    'error'    => 401,
                    'messages' => ['error' => 'User dari token tidak ditemukan di sistem.']
                ]);
        }

        if (isset($userRecord->is_active) && !$userRecord->is_active) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON([
                    'status'   => 403,
                    'error'    => 403,
                    'messages' => ['error' => 'Akun Anda telah dinonaktifkan.']
                ]);
        }

        // Pengecekan role jika route menentukan batas role
        $userRole = $userRecord->role ?? $decoded->role ?? 'staff';

        // Admin selalu memiliki akses ke seluruh endpoint
        if ($userRole !== 'admin' && !empty($arguments)) {
            if (!in_array($userRole, $arguments, true)) {
                return service('response')
                    ->setStatusCode(403)
                    ->setJSON([
                        'status'   => 403,
                        'error'    => 403,
                        'messages' => ['error' => "Akses ditolak. Role '{$userRole}' tidak memiliki izin untuk operasi ini."]
                    ]);
            }
        }

        // Simpan data user ke objek request agar controller dapat mengaksesnya tanpa session
        $request->jwtUser = $decoded;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No post-processing needed
    }
}
