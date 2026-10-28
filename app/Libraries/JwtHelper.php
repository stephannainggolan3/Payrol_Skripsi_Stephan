<?php

namespace App\Libraries;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtHelper
{
    /**
     * Generate JWT Token
     *
     * @param array $user Data user (id, username, email, role, etc)
     * @param array $allowedClientIds Array client IDs atau ['all']
     * @return string
     */
    public static function generateToken(array $user, array $allowedClientIds): string
    {
        $secret = getenv('JWT_SECRET') ?: 'b4f9814d9b7348e02d84950e1837c7324df2d634177b94ad14ea988a82cf6549';
        $algo   = getenv('JWT_ALGORITHM') ?: 'HS256';
        $ttl    = (int) (getenv('JWT_EXPIRE_SECONDS') ?: 7200); // 2 jam = 7200 detik

        $issuedAt = time();
        $expire   = $issuedAt + $ttl;

        $payload = [
            'iss'      => 'BiPayroll-API',
            'iat'      => $issuedAt,
            'exp'      => $expire,
            'user_id'  => (int) ($user['id'] ?? $user['user_id'] ?? 1),
            'username' => $user['username'] ?? '',
            'role'     => $user['role'] ?? 'staff',
            'clients'  => $allowedClientIds
        ];

        return JWT::encode($payload, $secret, $algo);
    }

    /**
     * Decode and Validate JWT Token
     *
     * @param string $token
     * @return object|null Decoded payload object or null if invalid/expired
     */
    public static function validateToken(string $token): ?object
    {
        $secret = getenv('JWT_SECRET') ?: 'b4f9814d9b7348e02d84950e1837c7324df2d634177b94ad14ea988a82cf6549';
        $algo   = getenv('JWT_ALGORITHM') ?: 'HS256';

        try {
            return JWT::decode($token, new Key($secret, $algo));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
