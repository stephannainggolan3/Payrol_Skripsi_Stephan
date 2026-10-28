<?php

namespace App\Controllers;

use App\Models\ContractCompensationModel;
use App\Models\EmployeeModel;
use App\Models\ContractModel;
use App\Models\SystemLogModel;
use CodeIgniter\RESTful\ResourceController;

class ContractCompensation extends ResourceController
{
    use \App\Traits\AuthTrait;

    protected $modelName = 'App\Models\ContractCompensationModel';
    protected $format    = 'json';

    /**
     * Tampilkan semua kompensasi kontrak untuk client
     */
    public function index()
    {
        $clientId = $this->request->getGet('client_id');
        if (empty($clientId)) {
            return $this->respond([]);
        }

        if (!$this->checkClientAccess((int)$clientId)) {
            return $this->failClientForbidden();
        }

        $query = $this->model
            ->select('contract_compensations.*, employees.nama as nama_karyawan, employees.nik')
            ->join('employees', 'employees.id = contract_compensations.employee_id')
            ->where('contract_compensations.client_id', $clientId)
            ->orderBy('contract_compensations.id', 'DESC');

        $data = $query->findAll();
        return $this->respond($data);
    }

    /**
     * Tampilkan detail kompensasi kontrak
     */
    public function show($id = null)
    {
        $data = $this->model
            ->select('contract_compensations.*, employees.nama as nama_karyawan, employees.nik')
            ->join('employees', 'employees.id = contract_compensations.employee_id')
            ->find($id);

        if (!$data) {
            return $this->failNotFound('Contract compensation tidak ditemukan');
        }

        if (!$this->checkClientAccess((int)$data['client_id'])) {
            return $this->failClientForbidden();
        }

        return $this->respond($data);
    }

    /**
     * Hitung preview kompensasi dan simpan/update sebagai DRAFT
     */
    public function calculate()
    {
        $json = $this->request->getJSON(true);
        $employeeId = $json['employee_id'] ?? null;
        
        if (!$employeeId) {
            return $this->fail('Employee ID diperlukan');
        }

        $employeeModel = new EmployeeModel();
        $employee = $employeeModel->find($employeeId);
        if (!$employee) {
            return $this->failNotFound('Karyawan tidak ditemukan');
        }

        $clientId = $employee['client_id'];
        if (!$this->checkClientAccess((int)$clientId)) {
            return $this->failClientForbidden();
        }
        
        // Dapatkan data tanggal & gaji pokok
        $tglMulai = $json['tgl_mulai_kerja'] ?? $employee['tgl_masuk'] ?? null;
        $tglAkhir = $json['tgl_akhir_kontrak'] ?? $employee['end_contract'] ?? null;
        $basicSalary = isset($json['basic_salary']) ? floatval($json['basic_salary']) : floatval($employee['gaji_pokok'] ?? 0);
        $actualDays = isset($json['actual_days']) ? intval($json['actual_days']) : null;

        // Ambil contract_id jika ada
        $contractModel = new ContractModel();
        $contract = $contractModel->where('employee_id', $employeeId)->where('status_pkwt', 'Active')->first();
        $contractId = $contract ? $contract['id'] : null;

        if (empty($tglMulai) || empty($tglAkhir)) {
            return $this->fail('Tanggal mulai kerja dan tanggal akhir kontrak diperlukan untuk calculation');
        }

        // Tentukan standard working days (default 22)
        $db = \Config\Database::connect();
        $empConfig = null;
        if (!empty($employee['position_id'])) {
            $empConfig = $db->table('client_payroll_configs')
                ->where('client_id', $clientId)
                ->where('position_id', $employee['position_id'])
                ->get()->getRow();
        }
        if (!$empConfig && !empty($employee['department_id'])) {
            $empConfig = $db->table('client_payroll_configs')
                ->where('client_id', $clientId)
                ->where('department_id', $employee['department_id'])
                ->where('position_id IS NULL')
                ->get()->getRow();
        }
        if (!$empConfig) {
            $empConfig = $db->table('client_payroll_configs')
                ->where('client_id', $clientId)
                ->where('division_id IS NULL')
                ->where('department_id IS NULL')
                ->where('position_id IS NULL')
                ->get()->getRow();
        }

        $standardDays = ($empConfig && isset($empConfig->standard_work_days) && intval($empConfig->standard_work_days) > 0) 
            ? intval($empConfig->standard_work_days) 
            : 22;

        if (isset($employee['custom_standard_days']) && intval($employee['custom_standard_days']) > 0) {
            $standardDays = intval($employee['custom_standard_days']);
        }

        // Lakukan kalkulasi kompensasi
        $calc = $this->model->calculateCompensation($basicSalary, $tglMulai, $tglAkhir, $standardDays, $actualDays);

        // Cek jika sudah ada draft untuk employee ini pada rentang kontrak tersebut
        $existing = $this->model->where([
            'employee_id' => $employeeId,
            'client_id' => $clientId,
        ])->whereIn('status', ['Draft', 'Ditetapkan', 'Rejected'])->first();

        $dataSave = [
            'employee_id' => $employeeId,
            'contract_id' => $contractId,
            'client_id' => $clientId,
            'basic_salary' => $basicSalary,
            'masa_kerja_year' => $calc['year'],
            'masa_kerja_month' => $calc['month'],
            'masa_kerja_hari' => $calc['hari'],
            'multiplier' => $calc['multiplier'],
            'value_kompensasi' => $calc['value'],
            'tgl_mulai_kerja' => $tglMulai,
            'tgl_akhir_kontrak' => $tglAkhir,
            'status' => $existing ? $existing['status'] : 'Draft',
        ];

        if ($existing) {
            $this->model->update($existing['id'], $dataSave);
            $dataSave['id'] = $existing['id'];
        } else {
            $dataSave['status'] = 'Draft';
            $id = $this->model->insert($dataSave);
            $dataSave['id'] = $id;
        }

        // Log action
        $log = new SystemLogModel();
        $username = $this->request->getHeaderLine('X-User-Action') ?: 'Sistem';
        $log->insert([
            'action' => 'CALCULATE_COMPENSATION',
            'description' => "Menghitung draf kompensasi kontrak untuk {$employee['nama']} (Value: Rp " . number_format($calc['value'], 0, ',', '.') . ")",
            'client_id' => $clientId,
            'created_by' => 1,
            'user_name' => $username,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        return $this->respond([
            'status' => 'success',
            'message' => 'Contract compensation successfully dihitung',
            'data' => $dataSave
        ]);
    }

    /**
     * HCOPS menetapkan value kompensasi final
     */
    public function setCompensation($id = null)
    {
        $comp = $this->model->find($id);
        if (!$comp) {
            return $this->failNotFound('Contract compensation tidak ditemukan');
        }

        if (!$this->checkClientAccess((int)$comp['client_id'])) {
            return $this->failClientForbidden();
        }

        $json = $this->request->getJSON(true);
        $valueFinal = isset($json['value_kompensasi_final']) ? floatval($json['value_kompensasi_final']) : $comp['value_kompensasi'];
        $notes = $json['notes'] ?? '';

        $username = $this->request->getHeaderLine('X-User-Action') ?: 'HCOPS';

        $dataUpdate = [
            'value_kompensasi_final' => $valueFinal,
            'status' => 'Ditetapkan',
            'ditetapkan_oleh' => $username,
            'ditetapkan_pada' => date('Y-m-d H:i:s'),
            'notes' => $notes
        ];

        if ($this->model->update($id, $dataUpdate)) {
            $empModel = new EmployeeModel();
            $emp = $empModel->find($comp['employee_id']);

            $log = new SystemLogModel();
            $log->insert([
                'action' => 'SET_COMPENSATION',
                'description' => "Menetapkan value kompensasi kontrak untuk {$emp['nama']} sebesar Rp " . number_format($valueFinal, 0, ',', '.'),
                'client_id' => $comp['client_id'],
                'created_by' => 1,
                'user_name' => $username,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            return $this->respond([
                'status' => 'success',
                'message' => 'Value kompensasi successfully ditetapkan dan menunggu persetujuan superior'
            ]);
        }

        return $this->fail('Failed menetapkan kompensasi');
    }

    /**
     * Superior menyetujui value kompensasi
     */
    public function approve($id = null)
    {
        $comp = $this->model->find($id);
        if (!$comp) {
            return $this->failNotFound('Contract compensation tidak ditemukan');
        }

        if (!$this->checkClientAccess((int)$comp['client_id'])) {
            return $this->failClientForbidden();
        }

        $username = $this->request->getHeaderLine('X-User-Action') ?: 'Superior';

        $dataUpdate = [
            'status' => 'Approved',
            'disetujui_oleh' => $username,
            'disetujui_pada' => date('Y-m-d H:i:s')
        ];

        if ($this->model->update($id, $dataUpdate)) {
            $empModel = new EmployeeModel();
            $emp = $empModel->find($comp['employee_id']);

            $log = new SystemLogModel();
            $log->insert([
                'action' => 'APPROVE_COMPENSATION',
                'description' => "Menyetujui kompensasi kontrak untuk {$emp['nama']} (Value final: Rp " . number_format($comp['value_kompensasi_final'] ?? $comp['value_kompensasi'], 0, ',', '.') . ")",
                'client_id' => $comp['client_id'],
                'created_by' => 1,
                'user_name' => $username,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            return $this->respond([
                'status' => 'success',
                'message' => 'Contract compensation successfully disetujui'
            ]);
        }

        return $this->fail('Failed menyetujui kompensasi');
    }

    /**
     * Superior menolak value kompensasi
     */
    public function reject($id = null)
    {
        $comp = $this->model->find($id);
        if (!$comp) {
            return $this->failNotFound('Contract compensation tidak ditemukan');
        }

        if (!$this->checkClientAccess((int)$comp['client_id'])) {
            return $this->failClientForbidden();
        }

        $json = $this->request->getJSON(true);
        $notes = $json['notes'] ?? 'Rejected oleh Superior';

        $username = $this->request->getHeaderLine('X-User-Action') ?: 'Superior';

        $dataUpdate = [
            'status' => 'Rejected',
            'notes' => $notes
        ];

        if ($this->model->update($id, $dataUpdate)) {
            $empModel = new EmployeeModel();
            $emp = $empModel->find($comp['employee_id']);

            $log = new SystemLogModel();
            $log->insert([
                'action' => 'REJECT_COMPENSATION',
                'description' => "Menolak kompensasi kontrak untuk {$emp['nama']} dengan notes: {$notes}",
                'client_id' => $comp['client_id'],
                'created_by' => 1,
                'user_name' => $username,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            return $this->respond([
                'status' => 'success',
                'message' => 'Contract compensation ditolak'
            ]);
        }

        return $this->fail('Failed menolak kompensasi');
    }

    /**
     * Hapus draft kompensasi kontrak
     */
    public function delete($id = null)
    {
        $comp = $this->model->find($id);
        if (!$comp) {
            return $this->failNotFound('Contract compensation tidak ditemukan');
        }

        if (!$this->checkClientAccess((int)$comp['client_id'])) {
            return $this->failClientForbidden();
        }

        if ($comp['status'] !== 'Draft' && $comp['status'] !== 'Rejected') {
            return $this->fail('Hanya draf atau status ditolak yang dapat dihapus');
        }

        if ($this->model->delete($id)) {
            $empModel = new EmployeeModel();
            $emp = $empModel->find($comp['employee_id']);
            $username = $this->request->getHeaderLine('X-User-Action') ?: 'HCOPS';

            $log = new SystemLogModel();
            $log->insert([
                'action' => 'DELETE_COMPENSATION',
                'description' => "Menghapus draf kompensasi kontrak untuk {$emp['nama']}",
                'client_id' => $comp['client_id'],
                'created_by' => 1,
                'user_name' => $username,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            return $this->respondDeleted([
                'id' => $id,
                'message' => 'Draf kompensasi kontrak deleted successfully'
            ]);
        }

        return $this->fail('Failed to delete kompensasi');
    }
}
