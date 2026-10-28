<?php

namespace App\Controllers;

use App\Models\ClientModel;
use App\Models\PayrollComponentModel;
use App\Models\SystemLogModel;
use CodeIgniter\RESTful\ResourceController;

class Client extends ResourceController
{
    use \App\Traits\AuthTrait;

    protected $modelName = 'App\Models\ClientModel';
    protected $format    = 'json';

    public function index()
    {
        $role = $this->getAuthRole();
        $allowed = $this->getAuthClients();
        $query = $this->model->orderBy('id', 'DESC');

        if ($role !== 'admin' && !in_array('all', $allowed, true)) {
            if (empty($allowed)) {
                return $this->respond([]);
            }
            $query->whereIn('id', $allowed);
        }

        $data = $query->findAll();
        foreach ($data as &$client) {
            if (isset($client['npwp'])) {
                $client['npwp'] = (string)$client['npwp'];
            }
        }
        return $this->respond($data);
    }

    public function create()
    {
        $data = $this->request->getJSON(true);
        if (isset($data['npwp'])) {
            $data['npwp'] = (string)$data['npwp'];
        }
        
        // Generate No Klien Otomatis (Format: CLI-001)
        $count = $this->model->countAllResults();
        $data['no_klien'] = 'CLI-' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);

        if ($id = $this->model->insert($data)) {
            $data['id'] = $id;
            
            $desc = 'Menambahkan Klien baru: ' . ($data['nama'] ?? $data['no_klien']);
            $fields = [];
            foreach ($data as $key => $val) {
                if ($key !== 'id' && $key !== 'nama' && !is_array($val) && !is_object($val)) {
                    $fields[] = "$key: $val";
                }
            }
            if (count($fields) > 0) {
                $desc .= "\nDetail: " . implode(", ", $fields);
            }
            
            $log = new SystemLogModel();
            $log->logAction('CREATE_CLIENT', $desc, $id, $this->getAuthUserId());
            return $this->respondCreated($data);
        }
        return $this->fail($this->model->errors());
    }

    public function update($id = null)
    {
        if (!$this->checkClientAccess((int)$id)) {
            return $this->failClientForbidden();
        }
        $data = $this->request->getJSON(true);
        if (isset($data['npwp'])) {
            $data['npwp'] = (string)$data['npwp'];
        }
        $oldClient = $this->model->find($id);
        
        if ($this->model->update($id, $data)) {
            $data['id'] = $id;
            
            $changes = [];
            if ($oldClient) {
                foreach ($data as $key => $val) {
                    if (array_key_exists($key, $oldClient) && $oldClient[$key] != $val && !is_array($val) && !is_object($val)) {
                        $changes[] = "- $key diubah dari '{$oldClient[$key]}' menjadi '$val'";
                    }
                }
            }
            
            $clientName = $oldClient['nama'] ?? 'ID '.$id;
            $desc = "Memperbarui data Klien $clientName (ID: $id)";
            if (count($changes) > 0) {
                $desc .= ".\nPerubahan:\n" . implode("\n", $changes);
            } else {
                $desc .= " (Tidak ada perubahan kolom)";
            }
            
            $log = new SystemLogModel();
            $log->logAction('UPDATE_CLIENT', $desc, $id, $this->getAuthUserId());
            return $this->respond($data);
        }
        return $this->fail($this->model->errors());
    }

    public function delete($id = null)
    {
        if (!$this->checkClientAccess((int)$id)) {
            return $this->failClientForbidden();
        }
        $client = $this->model->find($id);
        if ($client && $this->model->delete($id)) {
            $desc = 'Menghapus data Klien: ' . ($client['nama'] ?? 'ID '.$id);
            $fields = [];
            foreach ($client as $key => $val) {
                if ($key !== 'id' && $key !== 'nama' && !is_array($val) && !is_object($val)) {
                    $fields[] = "$key: $val";
                }
            }
            if (count($fields) > 0) {
                $desc .= "\nData terakhir: " . implode(", ", $fields);
            }
            
            $log = new SystemLogModel();
            $log->logAction('DELETE_CLIENT', $desc, $id, $this->getAuthUserId());
            return $this->respondDeleted(['message' => 'Client dihapus']);
        }
        return $this->failNotFound('Client tidak ditemukan');
    }

    // --- Schema Payroll & Tax ---

    public function getSchema($clientId)
    {
        if (!$this->checkClientAccess((int)$clientId)) {
            return $this->failClientForbidden();
        }
        $schema = $this->model->find($clientId);
        return $this->respond($schema ?: []);
    }

    public function saveSchema()
    {
        $data = $this->request->getJSON(true);
        $clientId = $data['client_id'];
        if (!$this->checkClientAccess((int)$clientId)) {
            return $this->failClientForbidden();
        }
        unset($data['client_id']); // Remove client_id since we update by primary key id
        
        $this->model->update($clientId, $data);
        
        $log = new SystemLogModel();
        $log->logAction('UPDATE_CLIENT_SCHEMA', 'Memperbarui scheme payroll/tax Klien ID: ' . $clientId, $clientId, $this->getAuthUserId());
        
        return $this->respond(['message' => 'Schema saved']);
    }

    // --- Component Payroll (Allowance/Deduction custom per klien) ---

    public function getComponents($clientId)
    {
        if (!$this->checkClientAccess((int)$clientId)) {
            return $this->failClientForbidden();
        }
        $compModel = new PayrollComponentModel();
        $components = $compModel->getByClient($clientId);
        return $this->respond($components);
    }

    public function saveComponent()
    {
        $data = $this->request->getJSON(true);
        $compModel = new PayrollComponentModel();

        if (!empty($data['id'])) {
            $comp = $compModel->find($data['id']);
            if (!$comp) return $this->failNotFound('Component tidak ditemukan');
            if (!$this->checkClientAccess((int)$comp['client_id'])) return $this->failClientForbidden();

            $compModel->update($data['id'], $data);
            $log = new SystemLogModel();
            $log->logAction('UPDATE_COMPONENT', 'Memperbarui component ID: ' . $data['id'], $data['client_id'] ?? null, $this->getAuthUserId());
            return $this->respond(['message' => 'Component diperbarui']);
        } else {
            if (!empty($data['client_id']) && !$this->checkClientAccess((int)$data['client_id'])) {
                return $this->failClientForbidden();
            }
            $id = $compModel->insert($data);
            if ($id) {
                $data['id'] = $id;
                $log = new SystemLogModel();
                $log->logAction('CREATE_COMPONENT', 'Menambahkan component baru', $data['client_id'] ?? null, $this->getAuthUserId());
                return $this->respondCreated($data);
            }
            return $this->fail($compModel->errors());
        }
    }

    public function deleteComponent($id)
    {
        $compModel = new PayrollComponentModel();
        $comp = $compModel->find($id);
        if (!$comp) {
            return $this->failNotFound('Component tidak ditemukan');
        }
        if (!$this->checkClientAccess((int)$comp['client_id'])) {
            return $this->failClientForbidden();
        }
        if ($compModel->delete($id)) {
            $log = new SystemLogModel();
            $log->logAction('DELETE_COMPONENT', 'Menghapus component ID: ' . $id, $comp['client_id'] ?? null, $this->getAuthUserId());
            return $this->respondDeleted(['message' => 'Component dihapus']);
        }
        return $this->failNotFound('Component tidak ditemukan');
    }
}
