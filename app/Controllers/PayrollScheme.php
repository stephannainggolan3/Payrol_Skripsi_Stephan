<?php

namespace App\Controllers;

use App\Models\PayrollSchemeTemplateModel;
use App\Models\SystemLogModel;
use CodeIgniter\RESTful\ResourceController;

class PayrollScheme extends ResourceController
{
    use \App\Traits\AuthTrait;

    protected $modelName = 'App\Models\PayrollSchemeTemplateModel';
    protected $format    = 'json';

    /**
     * Get all schemes for a client
     */
    public function index()
    {
        $clientId = $this->request->getGet('client_id');
        if ($clientId && !$this->checkClientAccess((int)$clientId)) {
            return $this->failClientForbidden();
        }
        
        if ($clientId) {
            $schemes = $this->model->getByClient($clientId);
        } else {
            $role = $this->getAuthRole();
            $allowed = $this->getAuthClients();
            if ($role !== 'admin' && !in_array('all', $allowed, true)) {
                if (empty($allowed)) {
                    return $this->respond([]);
                }
                $schemes = $this->model->whereIn('client_id', $allowed)->findAll();
            } else {
                $schemes = $this->model->findAll();
            }
        }
        return $this->respond($schemes);
    }

    /**
     * Get schemes filtered by org structure
     */
    public function getByOrgStructure()
    {
        $clientId = $this->request->getGet('client_id');
        $divisionId = $this->request->getGet('division_id');
        $departmentId = $this->request->getGet('department_id');
        $positionId = $this->request->getGet('position_id');

        if (!$clientId) {
            return $this->fail('client_id is required');
        }

        if (!$this->checkClientAccess((int)$clientId)) {
            return $this->failClientForbidden();
        }

        $schemes = $this->model->getByOrgStructure(
            $clientId,
            $divisionId ?: null,
            $departmentId ?: null,
            $positionId ?: null
        );

        return $this->respond($schemes);
    }

    /**
     * Get a single scheme by ID
     */
    public function show($id = null)
    {
        $scheme = $this->model->select('payroll_scheme_templates.*, 
                                        divisions.nama as division_name,
                                        departments.nama as department_name,
                                        positions.nama as position_name,
                                        minimum_wages.nama_daerah as minimum_wage_name,
                                        minimum_wages.nominal as minimum_wage_nominal')
                              ->join('divisions', 'divisions.id = payroll_scheme_templates.division_id', 'left')
                              ->join('departments', 'departments.id = payroll_scheme_templates.department_id', 'left')
                              ->join('positions', 'positions.id = payroll_scheme_templates.position_id', 'left')
                              ->join('minimum_wages', 'minimum_wages.id = payroll_scheme_templates.minimum_wage_id', 'left')
                              ->find($id);

        if (!$scheme) {
            return $this->failNotFound('Scheme not found');
        }

        if (!$this->checkClientAccess((int)$scheme['client_id'])) {
            return $this->failClientForbidden();
        }

        return $this->respond($scheme);
    }

    /**
     * Create a new scheme
     */
    public function create()
    {
        $data = $this->request->getJSON(true);
        if (!empty($data['client_id']) && !$this->checkClientAccess((int)$data['client_id'])) {
            return $this->failClientForbidden();
        }

        // Convert empty strings to null for foreign keys
        $data['division_id'] = !empty($data['division_id']) ? $data['division_id'] : null;
        $data['department_id'] = !empty($data['department_id']) ? $data['department_id'] : null;
        $data['position_id'] = !empty($data['position_id']) ? $data['position_id'] : null;
        $data['minimum_wage_id'] = !empty($data['minimum_wage_id']) ? $data['minimum_wage_id'] : null;

        if ($id = $this->model->insert($data)) {
            $data['id'] = $id;

            // Log activity
            $log = new SystemLogModel();
            $orgInfo = $this->getOrgInfo($data);
            $log->logAction(
                'CREATE_PAYROLL_SCHEME',
                "Menambahkan scheme payroll baru: {$data['nama_scheme']} untuk {$orgInfo}",
                $data['client_id'],
                $this->getAuthUserId()
            );

            return $this->respondCreated($data);
        }

        return $this->fail($this->model->errors());
    }

    /**
     * Update an existing scheme
     */
    public function update($id = null)
    {
        $data = $this->request->getJSON(true);
        $oldScheme = $this->model->find($id);

        if (!$oldScheme) {
            return $this->failNotFound('Scheme not found');
        }

        if (!$this->checkClientAccess((int)$oldScheme['client_id'])) {
            return $this->failClientForbidden();
        }

        if (!empty($data['client_id']) && !$this->checkClientAccess((int)$data['client_id'])) {
            return $this->failClientForbidden();
        }

        // Convert empty strings to null for foreign keys
        $data['division_id'] = !empty($data['division_id']) ? $data['division_id'] : null;
        $data['department_id'] = !empty($data['department_id']) ? $data['department_id'] : null;
        $data['position_id'] = !empty($data['position_id']) ? $data['position_id'] : null;
        $data['minimum_wage_id'] = !empty($data['minimum_wage_id']) ? $data['minimum_wage_id'] : null;

        if ($this->model->update($id, $data)) {
            $data['id'] = $id;

            // Log activity
            $log = new SystemLogModel();
            $orgInfo = $this->getOrgInfo($data);
            $log->logAction(
                'UPDATE_PAYROLL_SCHEME',
                "Memperbarui scheme payroll: {$oldScheme['nama_scheme']} untuk {$orgInfo}",
                $oldScheme['client_id'],
                $this->getAuthUserId()
            );

            return $this->respond($data);
        }

        return $this->fail($this->model->errors());
    }

    /**
     * Delete a scheme
     */
    public function delete($id = null)
    {
        $scheme = $this->model->find($id);

        if (!$scheme) {
            return $this->failNotFound('Scheme not found');
        }

        if (!$this->checkClientAccess((int)$scheme['client_id'])) {
            return $this->failClientForbidden();
        }

        if ($this->model->delete($id)) {
            // Log activity
            $log = new SystemLogModel();
            $orgInfo = $this->getOrgInfo($scheme);
            $log->logAction(
                'DELETE_PAYROLL_SCHEME',
                "Menghapus scheme payroll: {$scheme['nama_scheme']} untuk {$orgInfo}",
                $scheme['client_id'],
                $this->getAuthUserId()
            );

            return $this->respondDeleted(['message' => 'Scheme deleted successfully']);
        }

        return $this->fail('Failed to delete scheme');
    }

    /**
     * Toggle active status
     */
    public function toggleActive($id = null)
    {
        $scheme = $this->model->find($id);

        if (!$scheme) {
            return $this->failNotFound('Scheme not found');
        }

        if (!$this->checkClientAccess((int)$scheme['client_id'])) {
            return $this->failClientForbidden();
        }

        $newStatus = $scheme['is_active'] == 1 ? 0 : 1;

        if ($this->model->update($id, ['is_active' => $newStatus])) {
            $statusText = $newStatus == 1 ? 'diaktifkan' : 'dinonaktifkan';

            // Log activity
            $log = new SystemLogModel();
            $log->logAction(
                'TOGGLE_PAYROLL_SCHEME',
                "Scheme payroll '{$scheme['nama_scheme']}' {$statusText}",
                $scheme['client_id'],
                $this->getAuthUserId()
            );

            return $this->respond([
                'message' => "Scheme successfully {$statusText}",
                'is_active' => $newStatus
            ]);
        }

        return $this->fail('Failed mengubah status scheme');
    }

    /**
     * Get the best matching scheme for an employee
     */
    public function getSchemeForEmployee()
    {
        $clientId = $this->request->getGet('client_id');
        $divisionId = $this->request->getGet('division_id');
        $departmentId = $this->request->getGet('department_id');
        $positionId = $this->request->getGet('position_id');

        if (!$clientId) {
            return $this->fail('client_id is required');
        }

        $scheme = $this->model->getSchemeForEmployee(
            $clientId,
            $divisionId ?: null,
            $departmentId ?: null,
            $positionId ?: null
        );

        if (!$scheme) {
            return $this->failNotFound('Tidak ada scheme yang cocok ditemukan');
        }

        return $this->respond($scheme);
    }

    /**
     * Helper: Get organization info string for logging
     */
    private function getOrgInfo($data)
    {
        $db = \Config\Database::connect();
        $parts = [];

        if (!empty($data['division_id'])) {
            $div = $db->table('divisions')->where('id', $data['division_id'])->get()->getRow();
            if ($div) $parts[] = "Division: {$div->nama}";
        }

        if (!empty($data['department_id'])) {
            $dept = $db->table('departments')->where('id', $data['department_id'])->get()->getRow();
            if ($dept) $parts[] = "Department: {$dept->nama}";
        }

        if (!empty($data['position_id'])) {
            $pos = $db->table('positions')->where('id', $data['position_id'])->get()->getRow();
            if ($pos) $parts[] = "Position: {$pos->nama}";
        }

        return count($parts) > 0 ? implode(', ', $parts) : 'All structure organisasi';
    }
}
