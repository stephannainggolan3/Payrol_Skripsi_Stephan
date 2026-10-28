<?php

namespace App\Controllers;

use App\Models\FpkMasterModel;
use App\Models\FpkAssignmentModel;
use App\Models\EmployeeModel;
use App\Models\SystemLogModel;
use CodeIgniter\RESTful\ResourceController;

class Fpk extends ResourceController
{
    use \App\Traits\AuthTrait;

    protected $format = 'json';

    // =====================================================================
    // MASTER FPK CRUD
    // =====================================================================

    /**
     * GET /api/fpk — List all FPK master data
     */
    public function index()
    {
        $model = new FpkMasterModel();
        return $this->respond($model->orderBy('created_at', 'DESC')->findAll());
    }

    /**
     * POST /api/fpk — Create a new FPK
     */
    public function create()
    {
        $model = new FpkMasterModel();
        $data = $this->request->getJSON(true);

        $data['status'] = 'Open';

        if (!$model->validate($data)) {
            return $this->fail($model->errors());
        }

        $id = $model->insert($data);
        if (!$id) {
            return $this->fail('Failed to save data FPK.');
        }

        // Log activity
        $logModel = new SystemLogModel();
        $logModel->logAction('CREATE_FPK', "FPK '{$data['number_fpk']}' added successfully.");

        return $this->respondCreated(['id' => $id, 'message' => 'FPK added successfully.']);
    }

    /**
     * PUT /api/fpk/{id} — Update existing FPK
     */
    public function update($id = null)
    {
        $model = new FpkMasterModel();
        $fpk = $model->find($id);

        if (!$fpk) {
            return $this->failNotFound('Data FPK tidak ditemukan.');
        }

        if ($fpk['status'] === 'Closed') {
            return $this->fail('FPK yang berstatus Closed tidak bisa diedit.');
        }

        $data = $this->request->getJSON(true);
        $data['id'] = $id; // For unique validation bypass

        if (!$model->validate($data)) {
            return $this->fail($model->errors());
        }

        $model->update($id, $data);

        // Log activity
        $logModel = new SystemLogModel();
        $logModel->logAction('UPDATE_FPK', "FPK '{$data['number_fpk']}' successfully diperbarui.");

        return $this->respond(['message' => 'FPK successfully diperbarui.']);
    }

    /**
     * DELETE /api/fpk/{id} — Delete FPK (only Open status)
     */
    public function delete($id = null)
    {
        $model = new FpkMasterModel();
        $fpk = $model->find($id);

        if (!$fpk) {
            return $this->failNotFound('Data FPK tidak ditemukan.');
        }

        if ($fpk['status'] === 'Closed') {
            return $this->fail('FPK yang berstatus Closed tidak bisa dihapus. Cabut assignment karyawan terlebih dahulu.');
        }

        $model->delete($id);

        // Log activity
        $logModel = new SystemLogModel();
        $logModel->logAction('DELETE_FPK', "FPK '{$fpk['number_fpk']}' deleted successfully.");

        return $this->respondDeleted(['message' => 'FPK deleted successfully.']);
    }

    // =====================================================================
    // FPK ASSIGNMENTS (Penempelan Karyawan)
    // =====================================================================

    /**
     * GET /api/fpk/assignments — List all FPK assignments
     */
    public function getAssignments()
    {
        $model = new FpkAssignmentModel();
        return $this->respond($model->orderBy('created_at', 'DESC')->findAll());
    }

    /**
     * POST /api/fpk/assign — Assign employee to FPK
     */
    public function assign()
    {
        $fpkModel = new FpkMasterModel();
        $assignModel = new FpkAssignmentModel();
        $empModel = new EmployeeModel();
        $logModel = new SystemLogModel();

        $data = $this->request->getJSON(true);

        $fpkId = $data['fpk_id'] ?? null;
        $employeeId = $data['employee_id'] ?? null;

        if (!$fpkId || !$employeeId) {
            return $this->fail('FPK and Employee must be selected.');
        }

        // Validate FPK exists and is Open
        $fpk = $fpkModel->find($fpkId);
        if (!$fpk) {
            return $this->failNotFound('Data FPK tidak ditemukan.');
        }
        if ($fpk['status'] !== 'Open') {
            return $this->fail('FPK ini sudah berstatus Closed. Tidak bisa menerima karyawan lagi.');
        }

        // Validate employee exists
        $db = \Config\Database::connect();
        $employee = $db->table('employees')
                       ->select('employees.*, positions.nama as nama_position, departments.nama as nama_dept')
                       ->join('positions', 'positions.id = employees.position_id', 'left')
                       ->join('departments', 'departments.id = COALESCE(employees.department_id, positions.department_id)', 'left')
                       ->where('employees.id', $employeeId)
                       ->get()
                       ->getRowArray();

        if (!$employee) {
            return $this->failNotFound('Employee Data not found.');
        }

        // Check if employee already has an active FPK assignment
        $existingAssignment = $assignModel->where('employee_id', $employeeId)
                                          ->where('status', 'Active')
                                          ->first();
        if ($existingAssignment) {
            return $this->fail('Karyawan ini sudah memiliki FPK aktif (' . $existingAssignment['number_fpk'] . '). Cabut assignment terlebih dahulu.');
        }

        // Create assignment record
        $userName = $this->getAuthUsername();
        
        $assignData = [
            'fpk_id'              => $fpkId,
            'employee_id'         => $employeeId,
            'nik'                 => $employee['nik'],
            'nama_karyawan'       => $employee['nama'],
            'number_fpk'           => $fpk['number_fpk'],
            'nama_fpk'            => $fpk['nama_fpk'],
            'provinsi'            => $fpk['provinsi'],
            'city'                => $fpk['city'],
            'tanggal_assignment'  => date('Y-m-d H:i:s'),
            'user_submit'         => $userName,
            'status'              => 'Active'
        ];

        $assignId = $assignModel->insert($assignData);
        if (!$assignId) {
            return $this->fail('Failed to save FPK assignment.');
        }

        // Update employee fpk_id
        $empModel->update($employeeId, ['fpk_id' => $fpkId]);

        // Update FPK status to Closed (1:1 ratio)
        $fpkModel->update($fpkId, ['status' => 'Closed']);

        // Log activity
        $logModel->logAction('ASSIGN_FPK', "Karyawan '{$employee['nama']}' (NIK: {$employee['nik']}) ditempelkan ke FPK '{$fpk['number_fpk']}'.");

        return $this->respondCreated([
            'id'      => $assignId,
            'message' => 'Karyawan successfully ditempelkan ke FPK.'
        ]);
    }

    /**
     * DELETE /api/fpk/assignments/{id} — Revoke FPK assignment
     */
    public function revokeAssignment($id = null)
    {
        $assignModel = new FpkAssignmentModel();
        $fpkModel = new FpkMasterModel();
        $empModel = new EmployeeModel();
        $logModel = new SystemLogModel();

        $assignment = $assignModel->find($id);
        if (!$assignment) {
            return $this->failNotFound('Data assignment tidak ditemukan.');
        }

        // Remove fpk_id from employee
        $empModel->update($assignment['employee_id'], ['fpk_id' => null]);

        // Reopen FPK
        $fpkModel->update($assignment['fpk_id'], ['status' => 'Open']);

        // Delete assignment record
        $assignModel->delete($id);

        // Log activity
        $logModel->logAction('REVOKE_FPK', "FPK Assignment '{$assignment['number_fpk']}' untuk karyawan '{$assignment['nama_karyawan']}' (NIK: {$assignment['nik']}) dicabut.");

        return $this->respondDeleted(['message' => 'FPK Assignment successfully dicabut.']);
    }
}
