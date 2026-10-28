// ===== CONTRACT COMPENSATION MODULE =====

let currentEmployeesKompensasi = [];

// Helper cookie reader
function getCookie(name) {
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) return parts.pop().split(';').shift();
}

// Helper to format numeric values to Rupiah input format using global helper
function formatFormattedNumber(val) {
    const tempInput = document.createElement('input');
    tempInput.value = Math.round(val).toString();
    if (typeof formatRupiahInput === 'function') {
        formatRupiahInput(tempInput);
    }
    return tempInput.value;
}

function switchClientPKWTSubTab(subTab) {
    document.querySelectorAll('.sub-tab-btn').forEach(btn => {
        if (btn.id === 'subTabPKWTData' || btn.id === 'subTabPKWTKompensasi') {
            btn.classList.remove('active');
            btn.style.color = '#64748b';
            btn.style.borderBottom = '2px solid transparent';
        }
    });

    const activeBtn = document.getElementById(subTab === 'pkwt_data' ? 'subTabPKWTData' : 'subTabPKWTKompensasi');
    if (activeBtn) {
        activeBtn.classList.add('active');
        activeBtn.style.color = 'var(--primary-color)';
        activeBtn.style.borderBottom = '2px solid var(--primary-color)';
    }

    document.querySelectorAll('.client-pkwt-subpanel').forEach(panel => {
        panel.style.display = 'none';
    });

    if (subTab === 'pkwt_data') {
        document.getElementById('panelPKWTData').style.display = 'block';
        renderPKWTTable();
    } else {
        document.getElementById('panelPKWTKompensasi').style.display = 'block';
        renderContractCompensationTable();
    }
}

async function renderContractCompensationTable() {
    const tbody = document.getElementById('tabelKompensasiKontrakBody');
    if (!tbody) return;

    tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; padding: 30px; color: #64748b;"><i class="fas fa-spinner fa-spin" style="font-size: 20px; margin-bottom: 10px; color: var(--primary-color);"></i><br>Loading data...</td></tr>`;

    try {
        const clientId = window.selectedClientId;
        if (!clientId) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; padding: 20px; color: #64748b;">Please select a client first.</td></tr>`;
            return;
        }

        const res = await fetch(`${API_URL}/contract-compensations?client_id=${clientId}`);
        if (!res.ok) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; padding: 20px; color: #ef4444;">Failed to load data kompensasi.</td></tr>`;
            return;
        }

        const data = await res.json();
        if (data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; padding: 30px; color: #64748b;"><i class="fas fa-info-circle" style="font-size: 24px; margin-bottom: 10px; color: #94a3b8;"></i><br>No compensation data available kontrak untuk klien ini.</td></tr>`;
            return;
        }

        const userRole = getCookie('user_role') || 'staff';

        tbody.innerHTML = data.map(row => {
            const basic = parseFloat(row.basic_salary);
            const nominal = parseFloat(row.value_kompensasi);
            const finalVal = row.value_kompensasi_final ? parseFloat(row.value_kompensasi_final) : null;
            
            // Format status badge
            let badgeStyle = 'background: #f1f5f9; color: #475569;';
            if (row.status === 'Ditetapkan') badgeStyle = 'background: #fff3cd; color: #856404;';
            else if (row.status === 'Approved') badgeStyle = 'background: #d4edda; color: #155724;';
            else if (row.status === 'Rejected') badgeStyle = 'background: #f8d7da; color: #721c24;';
            else if (row.status === 'Dibayar') badgeStyle = 'background: #cce5ff; color: #004085;';

            // Multiplier display
            const multDisplay = parseFloat(row.multiplier).toFixed(4) + 'x';
            
            // Duration
            const duration = `${row.masa_kerja_year}th ${row.masa_kerja_month}bln ${row.masa_kerja_hari}hr`;

            // Action buttons
            let actions = `<button class="btn-icon" onclick="bukaModalDetailKompensasi(${row.id})" title="View Details" style="background: #e2e8f0; color: #475569; border: none; border-radius: 6px; padding: 6px 10px; cursor: pointer; transition: all 0.2s;"><i class="fas fa-eye"></i></button>`;

            if (userRole === 'admin' || userRole === 'hc_ops') {
                if (row.status === 'Draft' || row.status === 'Rejected') {
                    actions += `
                        <button class="btn-icon" onclick="bukaModalTetapkan(${row.id}, ${row.value_kompensasi}, ${row.value_kompensasi_final || row.value_kompensasi}, '${row.notes || ''}')" title="Tetapkan Value" style="background: #fef3c7; color: #d97706; border: none; border-radius: 6px; padding: 6px 10px; cursor: pointer; transition: all 0.2s; margin-left: 6px;"><i class="fas fa-check-double"></i></button>
                        <button class="btn-icon" onclick="hapusKompensasiKontrak(${row.id})" title="Delete Draft" style="background: #fee2e2; color: #ef4444; border: none; border-radius: 6px; padding: 6px 10px; cursor: pointer; transition: all 0.2s; margin-left: 6px;"><i class="fas fa-trash"></i></button>
                    `;
                } else if (row.status === 'Ditetapkan') {
                    actions += `
                        <button class="btn-icon" onclick="bukaModalTetapkan(${row.id}, ${row.value_kompensasi}, ${row.value_kompensasi_final || row.value_kompensasi}, '${row.notes || ''}')" title="Edit Value" style="background: #e0f2fe; color: #0284c7; border: none; border-radius: 6px; padding: 6px 10px; cursor: pointer; transition: all 0.2s; margin-left: 6px;"><i class="fas fa-edit"></i></button>
                    `;
                }
            }

            return `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 16px; text-align: left;">
                        <strong style="color: #334155; display: block;">${row.nama_karyawan}</strong>
                        <span style="color: #64748b; font-size: 12px;">NIK: ${row.nik}</span>
                    </td>
                    <td style="padding: 16px; text-align: left; font-size: 13px;">
                        <span>Mulai: ${row.tgl_mulai_kerja}</span><br>
                        <span>Akhir: ${row.tgl_akhir_kontrak}</span>
                    </td>
                    <td style="padding: 16px; text-align: center; font-size: 13px;">${duration}</td>
                    <td style="padding: 16px; text-align: right; font-weight: 500;">${formatRupiah(basic)}</td>
                    <td style="padding: 16px; text-align: center; font-weight: 600; color: var(--primary-color);">${multDisplay}</td>
                    <td style="padding: 16px; text-align: right; color: #475569;">${formatRupiah(nominal)}</td>
                    <td style="padding: 16px; text-align: right; font-weight: bold; color: var(--secondary-color);">${finalVal ? formatRupiah(finalVal) : '-'}</td>
                    <td style="padding: 16px; text-align: center;">
                        <span class="badge" style="padding: 6px 12px; border-radius: 50px; font-size: 11px; font-weight: 600; display: inline-block; ${badgeStyle}">
                            ${row.status}
                        </span>
                    </td>
                    <td style="padding: 16px; text-align: center;">
                        <div style="display: flex; justify-content: center; align-items: center;">
                            ${actions}
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

    } catch (err) {
        console.error(err);
        tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; padding: 20px; color: #ef4444;">An error occurred saat memuat data.</td></tr>`;
    }
}

async function bukaModalHitungKompensasi() {
    const select = document.getElementById('hkEmployeeId');
    if (!select) return;

    select.innerHTML = '<option value="">-- Memuat Karyawan... --</option>';
    document.getElementById('hkPreviewContainer').style.display = 'none';
    document.getElementById('formHitungKompensasi').reset();

    document.getElementById('modalHitungKompensasi').style.display = 'block';
    document.getElementById('overlay').style.display = 'block';

    try {
        const clientId = window.selectedClientId;
        const res = await fetch(`${API_URL}/employees?client_id=${clientId}`);
        if (!res.ok) throw new Error();
        
        const employees = await res.json();
        // Filter only PKWT type
        currentEmployeesKompensasi = employees.filter(e => e.tipe_perjanjian === 'PKWT');

        if (currentEmployeesKompensasi.length === 0) {
            select.innerHTML = '<option value="">-- Tidak ada karyawan PKWT --</option>';
            return;
        }

        select.innerHTML = '<option value="">-- Select PKWT Employee --</option>' + 
            currentEmployeesKompensasi.map(e => `<option value="${e.id}">${e.nama} (${e.nik})</option>`).join('');

    } catch (err) {
        console.error(err);
        select.innerHTML = '<option value="">-- Failed to load data karyawan --</option>';
    }
}

function tutupModalHitungKompensasi() {
    document.getElementById('modalHitungKompensasi').style.display = 'none';
    document.getElementById('overlay').style.display = 'none';
}

function onEmployeeChangeKompensasi() {
    const empId = document.getElementById('hkEmployeeId').value;
    if (!empId) return;

    const emp = currentEmployeesKompensasi.find(e => e.id == empId);
    if (!emp) return;

    document.getElementById('hkStartDate').value = emp.tgl_masuk || '';
    document.getElementById('hkEndDate').value = emp.end_contract || '';
    document.getElementById('hkBasicSalary').value = formatFormattedNumber(emp.gaji_pokok || 0);
    document.getElementById('hkActualDays').value = '';
    document.getElementById('hkPreviewContainer').style.display = 'none';
}

async function getKalkulasiPayload() {
    const empId = document.getElementById('hkEmployeeId').value;
    const startDate = document.getElementById('hkStartDate').value;
    const endDate = document.getElementById('hkEndDate').value;
    const basicSalaryStr = document.getElementById('hkBasicSalary').value;
    const actualDays = document.getElementById('hkActualDays').value;

    if (!empId || !startDate || !endDate || !basicSalaryStr) {
        showToast('Please complete semua field yang berbintang (*)', 'warning');
        return null;
    }

    const basicSalary = parseFormattedNumber(basicSalaryStr);

    return {
        employee_id: parseInt(empId),
        tgl_mulai_kerja: startDate,
        tgl_akhir_kontrak: endDate,
        basic_salary: basicSalary,
        actual_days: actualDays ? parseInt(actualDays) : null
    };
}

async function previewKompensasiKontrak() {
    const payload = await getKalkulasiPayload();
    if (!payload) return;

    const previewContainer = document.getElementById('hkPreviewContainer');
    previewContainer.innerHTML = 'Preview: Menghitung...';
    previewContainer.style.display = 'block';

    try {
        const res = await fetch(`${API_URL}/contract-compensations/calculate`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (!res.ok) {
            previewContainer.innerHTML = '<span style="color:#ef4444;">Failed menghitung kompensasi. Cek inputan Anda.</span>';
            return;
        }

        const result = await res.json();
        const data = result.data;

        previewContainer.innerHTML = `
            <h4 style="margin: 0 0 10px 0; color: var(--secondary-color); font-weight: 600;">Preview Calculation:</h4>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 10px;">
                <div>Tenure: <strong>${data.masa_kerja_year}th ${data.masa_kerja_month}bln ${data.masa_kerja_hari}hr</strong></div>
                <div>Multiplier: <strong>${parseFloat(data.multiplier).toFixed(4)}x</strong></div>
            </div>
            <div style="border-top: 1px solid #e2e8f0; padding-top: 10px; font-size: 14px;">
                Estimated Compensation: <strong style="color: var(--primary-color); font-size: 16px;">${formatRupiah(data.value_kompensasi)}</strong>
            </div>
        `;

    } catch (err) {
        console.error(err);
        previewContainer.innerHTML = '<span style="color:#ef4444;">An error occurred koneksi.</span>';
    }
}

async function hitungKompensasiKontrak(e) {
    e.preventDefault();
    const payload = await getKalkulasiPayload();
    if (!payload) return;

    try {
        const res = await fetch(`${API_URL}/contract-compensations/calculate`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        if (res.ok) {
            tutupModalHitungKompensasi();
            renderContractCompensationTable();
            showToast('Draf kompensasi successfully dihitung dan disimpan!', 'success');
        } else {
            showToast('Failed to process compensation calculation.', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('An error occurred koneksi.', 'error');
    }
}

function bukaModalTetapkan(id, kalkulasi, finalVal, notes) {
    document.getElementById('tkId').value = id;
    document.getElementById('tkKalkulasiSistem').value = formatRupiah(kalkulasi);
    document.getElementById('tkValueFinal').value = formatFormattedNumber(finalVal || kalkulasi);
    document.getElementById('tkNotes').value = notes || '';

    document.getElementById('modalTetapkanKompensasi').style.display = 'block';
    document.getElementById('overlay').style.display = 'block';
}

function tutupModalTetapkanKompensasi() {
    document.getElementById('modalTetapkanKompensasi').style.display = 'none';
    document.getElementById('overlay').style.display = 'none';
}

async function tetapkanKompensasiKontrak(e) {
    e.preventDefault();
    const id = document.getElementById('tkId').value;
    const finalVal = parseFormattedNumber(document.getElementById('tkValueFinal').value);
    const notes = document.getElementById('tkNotes').value;

    if (!finalVal || finalVal < 0) {
        showToast('Value final kompensasi tidak valid.', 'warning');
        return;
    }

    try {
        const res = await fetch(`${API_URL}/contract-compensations/set/${id}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                value_kompensasi_final: finalVal,
                notes: notes
            })
        });

        if (res.ok) {
            tutupModalTetapkanKompensasi();
            renderContractCompensationTable();
            showToast('Value kompensasi successfully ditetapkan!', 'success');
        } else {
            showToast('Failed menetapkan value kompensasi.', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('An error occurred koneksi.', 'error');
    }
}

async function bukaModalDetailKompensasi(id) {
    try {
        const res = await fetch(`${API_URL}/contract-compensations/${id}`);
        if (!res.ok) throw new Error();

        const data = await res.json();
        
        document.getElementById('dkId').value = data.id;
        document.getElementById('dkNamaKaryawan').innerText = data.nama_karyawan;
        document.getElementById('dkNik').innerText = data.nik;
        document.getElementById('dkTglMulai').innerText = data.tgl_mulai_kerja;
        document.getElementById('dkTglAkhir').innerText = data.tgl_akhir_kontrak;
        document.getElementById('dkMasaKerja').innerText = `${data.masa_kerja_year} year, ${data.masa_kerja_month} month, ${data.masa_kerja_hari} hari`;
        document.getElementById('dkGajiPokok').innerText = formatRupiah(data.basic_salary);
        document.getElementById('dkMultiplier').innerText = parseFloat(data.multiplier).toFixed(4) + 'x';
        document.getElementById('dkKompensasiSistem').innerText = formatRupiah(data.value_kompensasi);
        document.getElementById('dkKompensasiFinal').innerText = data.value_kompensasi_final ? formatRupiah(data.value_kompensasi_final) : '-';

        // Badge Status
        const badge = document.getElementById('dkStatusBadge');
        badge.innerText = data.status;
        badge.className = 'badge';
        let badgeStyle = 'background: #f1f5f9; color: #475569; padding: 6px 12px; border-radius: 50px; font-size: 11px; font-weight: 600; display: inline-block;';
        if (data.status === 'Ditetapkan') badgeStyle += 'background: #fff3cd; color: #856404;';
        else if (data.status === 'Approved') badgeStyle += 'background: #d4edda; color: #155724;';
        else if (data.status === 'Rejected') badgeStyle += 'background: #f8d7da; color: #721c24;';
        else if (data.status === 'Dibayar') badgeStyle += 'background: #cce5ff; color: #004085;';
        badge.style = badgeStyle;

        // Notes
        const notesBox = document.getElementById('dkNotesBox');
        if (data.notes) {
            document.getElementById('dkNotes').innerText = data.notes;
            notesBox.style.display = 'block';
        } else {
            notesBox.style.display = 'none';
        }

        // Workflow details
        const flowBox = document.getElementById('dkWorkflowBox');
        const flowDitetapkan = document.getElementById('dkFlowDitetapkan');
        const flowApproved = document.getElementById('dkFlowApproved');
        
        flowBox.style.display = 'none';
        flowDitetapkan.style.display = 'none';
        flowApproved.style.display = 'none';

        if (data.ditetapkan_oleh) {
            document.getElementById('dkDitetapkanOleh').innerText = data.ditetapkan_oleh;
            document.getElementById('dkDitetapkanPada').innerText = data.ditetapkan_pada;
            flowDitetapkan.style.display = 'block';
            flowBox.style.display = 'block';
        }
        if (data.disetujui_oleh) {
            document.getElementById('dkApprovedOleh').innerText = data.disetujui_oleh;
            document.getElementById('dkApprovedPada').innerText = data.disetujui_pada;
            flowApproved.style.display = 'block';
            flowBox.style.display = 'block';
        }

        // Approve/Reject Buttons display
        const btnApprove = document.getElementById('btnApproveKompensasi');
        const btnReject = document.getElementById('btnRejectKompensasi');
        
        if (btnApprove) btnApprove.style.display = 'none';
        if (btnReject) btnReject.style.display = 'none';

        const userRole = getCookie('user_role') || 'staff';
        if ((userRole === 'client_superior' || userRole === 'admin') && data.status === 'Ditetapkan') {
            if (btnApprove) btnApprove.style.display = 'inline-block';
            if (btnReject) btnReject.style.display = 'inline-block';
        }

        document.getElementById('modalDetailKompensasi').style.display = 'block';
        document.getElementById('overlay').style.display = 'block';

    } catch (err) {
        console.error(err);
        showToast('Failed to load detail kompensasi.', 'error');
    }
}

function tutupModalDetailKompensasi() {
    document.getElementById('modalDetailKompensasi').style.display = 'none';
    document.getElementById('overlay').style.display = 'none';
}

async function setujuiKompensasiKontrakWorkflow() {
    const id = document.getElementById('dkId').value;
    if (!id) return;

    if (!await showConfirm('Are you sure menyetujui kompensasi kontrak ini?')) return;

    try {
        const res = await fetch(`${API_URL}/contract-compensations/approve/${id}`, {
            method: 'POST'
        });

        if (res.ok) {
            tutupModalDetailKompensasi();
            renderContractCompensationTable();
            showToast('Contract compensation disetujui!', 'success');
        } else {
            showToast('Failed menyetujui kompensasi.', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('An error occurred koneksi.', 'error');
    }
}

async function tolakKompensasiKontrakWorkflow() {
    const id = document.getElementById('dkId').value;
    if (!id) return;

    const notes = prompt('Masukkan alasan penolakan:');
    if (notes === null) return; // cancel click

    if (!notes.trim()) {
        showToast('Notes penolakan harus diisi.', 'warning');
        return;
    }

    try {
        const res = await fetch(`${API_URL}/contract-compensations/reject/${id}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ notes: notes })
        });

        if (res.ok) {
            tutupModalDetailKompensasi();
            renderContractCompensationTable();
            showToast('Contract compensation successfully ditolak.', 'success');
        } else {
            showToast('Failed menolak kompensasi.', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('An error occurred koneksi.', 'error');
    }
}

async function hapusKompensasiKontrak(id) {
    if (!await showConfirm('Are you sure you want to delete draf kompensasi kontrak ini?')) return;

    try {
        const res = await fetch(`${API_URL}/contract-compensations/${id}`, {
            method: 'DELETE'
        });

        if (res.ok) {
            renderContractCompensationTable();
            showToast('Draf kompensasi kontrak deleted successfully.', 'success');
        } else {
            showToast('Failed to delete draf kompensasi.', 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('An error occurred koneksi.', 'error');
    }
}

// Bind custom formatting to basic salary inputs for clean input experience
document.addEventListener('DOMContentLoaded', () => {
    const salaryInput = document.getElementById('hkBasicSalary');
    const finalInput = document.getElementById('tkValueFinal');
    
    if (salaryInput) {
        salaryInput.addEventListener('input', (e) => {
            const raw = parseFormattedNumber(e.target.value);
            e.target.value = formatFormattedNumber(raw);
        });
    }

    if (finalInput) {
        finalInput.addEventListener('input', (e) => {
            const raw = parseFormattedNumber(e.target.value);
            e.target.value = formatFormattedNumber(raw);
        });
    }
});
