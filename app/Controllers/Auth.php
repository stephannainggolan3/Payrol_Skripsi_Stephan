<?php

namespace App\Controllers;

use App\Models\UserModel;
use CodeIgniter\RESTful\ResourceController;

class Auth extends ResourceController
{
    protected $modelName = 'App\Models\UserModel';
    protected $format    = 'json';

    /**
     * POST /api/login — Sign In dengan Username atau Email
     */
    public function login()
    {
        $json = $this->request->getJSON();
        $usernameOrEmail = trim($json->username ?? '');
        $password = $json->password ?? '';

        if (empty($usernameOrEmail) || empty($password)) {
            return $this->fail('Username/Email and password are required');
        }

        // Check if input is email by scanning '@' character
        $isEmail = strpos($usernameOrEmail, '@') !== false;

        if ($isEmail) {
            // Validate email
            if (!filter_var($usernameOrEmail, FILTER_VALIDATE_EMAIL)) {
                return $this->fail('Invalid email format');
            }

            $user = $this->model->where('email', $usernameOrEmail)->first();
        } else {
            $user = $this->model->where('username', $usernameOrEmail)->first();
        }

        if ($user) {
            // Verify password using password_verify, with graceful fallback for legacy plaintext passwords
            $isPasswordValid = password_verify($password, $user['password']);
            if (!$isPasswordValid && $password === $user['password']) {
                $isPasswordValid = true;
                // Upgrade plaintext password to bcrypt hash
                $this->model->update($user['id'], [
                    'password' => password_hash($password, PASSWORD_BCRYPT)
                ]);
            }

            if (!$isPasswordValid) {
                return $this->failUnauthorized('Incorrect username/email or password');
            }
            // Check if user is active
            if (isset($user['is_active']) && !$user['is_active']) {
                return $this->failUnauthorized('Your account has been deactivated. Please contact the administrator.');
            }

            // Check if role is pending
            if (($user['role'] ?? '') === 'pending') {
                return $this->failUnauthorized('Your account has not been approved or assigned a role by the Administrator yet.');
            }

            $userClientModel = new \App\Models\UserClientModel();
            $allowedClientIds = [];
            $role = $user['role'] ?? 'admin';

            if ($role === 'admin') {
                $allowedClientIds = ['all'];
            } else {
                $allowedClientIds = $userClientModel->getUserClientIds($user['id']);
            }

            $token = \App\Libraries\JwtHelper::generateToken($user, $allowedClientIds);

            return $this->respond([
                'success'    => true,
                'message'    => 'Login successful',
                'token'      => $token,
                'expires_in' => 7200,
                'user'       => [
                    'id'                 => $user['id'],
                    'username'           => $user['username'],
                    'email'              => $user['email'],
                    'role'               => $role,
                    'full_name'          => $user['full_name'] ?? $user['username'],
                    'allowed_client_ids' => $allowedClientIds
                ]
            ]);
        }

        return $this->failUnauthorized('Incorrect username/email or password');
    }

    /**
     * POST /api/auth/register
     */
    public function register()
    {
        $json = $this->request->getJSON();
        $username = trim($json->username ?? '');
        $fullName = trim($json->full_name ?? '');
        $email = trim($json->email ?? '');
        $password = $json->password ?? '';
        $confirmPassword = $json->confirm_password ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            return $this->fail('Username, email, and password are required');
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Invalid email format');
        }

        if ($password !== $confirmPassword) {
            return $this->fail('Password confirmation does not match');
        }

        if (strlen($password) < 6) {
            return $this->fail('New password must be at least 6 characters');
        }

        // Check if username already exists
        $existingUser = $this->model->where('username', $username)->first();
        if ($existingUser) {
            return $this->fail('Username is already registered');
        }

        // Check if email already exists
        $existingEmail = $this->model->where('email', $email)->first();
        if ($existingEmail) {
            return $this->fail('Email is already registered');
        }

        // Save new user with 'pending' role and hashed password
        $this->model->insert([
            'username'  => $username,
            'full_name' => $fullName,
            'email'     => $email,
            'password'  => password_hash($password, PASSWORD_BCRYPT),
            'role'      => 'pending',
            'is_active' => 1
        ]);

        return $this->respond([
            'success' => true,
            'message' => 'Registration successful! Your account is pending approval and role assignment by the Administrator.'
        ]);
    }

    /**
     * POST /api/auth/forgot-password
     */
    public function forgotPassword()
    {
        $json = $this->request->getJSON();
        $email = trim($json->email ?? '');

        if (empty($email)) {
            return $this->fail('Email is required');
        }

        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail('Invalid email format');
        }

        // Check if user exists
        $user = $this->model->where('email', $email)->first();
        if (!$user) {
            return $this->failNotFound('Email is not registered in the system');
        }

        // Generate 6 digit OTP
        $otp = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        
        $db = \Config\Database::connect();
        
        $db->table('password_resets')->where('email', $email)->delete();

        $db->table('password_resets')->insert([
            'email'      => $email,
            'token'      => $otp,
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+15 minutes'))
        ]);

        return $this->respond([
            'success' => true,
            'message' => 'OTP code generated successfully (Simulation)',
            'email'   => $email,
            'otp'     => $otp
        ]);
    }

    /**
     * POST /api/auth/reset-password
     */
    public function resetPassword()
    {
        $json = $this->request->getJSON();
        $email = trim($json->email ?? '');
        $token = trim($json->token ?? '');
        $password = $json->password ?? '';
        $confirmPassword = $json->confirm_password ?? '';

        if (empty($email) || empty($token) || empty($password) || empty($confirmPassword)) {
            return $this->fail('All fields are required');
        }

        if ($password !== $confirmPassword) {
            return $this->fail('Password confirmation does not match');
        }

        if (strlen($password) < 6) {
            return $this->fail('New password must be at least 6 characters');
        }

        $db = \Config\Database::connect();

        $reset = $db->table('password_resets')
                    ->where('email', $email)
                    ->where('token', $token)
                    ->where('expires_at >=', date('Y-m-d H:i:s'))
                    ->get()
                    ->getRow();

        if (!$reset) {
            return $this->fail('Invalid or expired OTP code');
        }

        $user = $this->model->where('email', $email)->first();
        if (!$user) {
            return $this->failNotFound('User not found');
        }

        $this->model->update($user['id'], [
            'password' => password_hash($password, PASSWORD_BCRYPT)
        ]);

        $db->table('password_resets')->where('email', $email)->delete();

        return $this->respond([
            'success' => true,
            'message' => 'Password updated successfully. Please log in again.'
        ]);
    }
}
