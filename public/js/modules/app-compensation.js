// ===== COMPENSATION MODULE =====
// Extracted from app.js for modular monolith architecture

    // Form Compensation Scheme submit handler
    if (document.getElementById('formSchemeKompensasi')) {
        document.getElementById('formSchemeKompensasi').addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('schemeKompensasiId').value;
            const data = {
                nama: document.getElementById('schemeKompensasiNama').value,
                deskripsi: document.getElementById('schemeKompensasiDeskripsi').value || '',
                tipe: 'revenue',
                sumber_value: document.getElementById('schemeKompensasiSumber').value,
                value: parseFormattedNumber(document.getElementById('schemeKompensasiValue').value) || 0,
                period: document.getElementById('schemeKompensasiPeriod').value,
                is_persentase: parseInt(document.getElementById('schemeKompensasiIsPersentase').value) || 0,
                sifat_kompensasi: document.getElementById('schemeKompensasiSifat').value
            };
            const url = id ? `${API_URL}/compensation-schemes/${id}` : `${API_URL}/compensation-schemes`;
            const res = await fetch(url, {
                method: id ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            if (res.ok) {
                tutupAllModal();
                renderMasterKompensasi();
                showToast(id ? 'Allowance scheme updated successfully!' : 'Allowance scheme added successfully!', 'success');
            } else {
                showToast('Failed to save scheme!', 'error');
            }
        });
    }

    // Form Component Kompensasi submit handler
    if (document.getElementById('formComponentKompensasi')) {
        document.getElementById('formComponentKompensasi').addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('componentKompensasiId').value;
            const schemeId = document.getElementById('componentKompensasiSchemeId').value;
            const jenis = document.getElementById('componentKompensasiJenis').value;
            const sifat = document.getElementById('componentKompensasiSifat').value;
            let nama = '';
            if (jenis === 'basic_salary') {
                nama = 'Basic Salary';
            } else {
                nama = sifat === 'tetap' ? 'Fixed Allowance' : 'Variable Allowance';
            }

            const data = {
                scheme_id: parseInt(schemeId),
                nama: nama,
                tipe: 'revenue',
                value: parseFormattedNumber(document.getElementById('componentKompensasiValue').value) || 0,
                is_persentase: parseInt(document.getElementById('componentKompensasiIsPersentase').value) || 0,
                jenis_component: jenis,
                sifat_kompensasi: sifat,
                sumber_value: document.getElementById('componentKompensasiSumber').value,
                period: document.getElementById('componentKompensasiPeriod').value
            };
            const url = id ? `${API_URL}/compensation-components/${id}` : `${API_URL}/compensation-components`;
            const res = await fetch(url, {
                method: id ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            if (res.ok) {
                tutupAllModal();
                renderMasterKompensasi();
                showToast(id ? 'Allowance updated successfully!' : 'Allowance added successfully!', 'success');
            } else {
                showToast('Failed to save allowance!', 'error');
            }
        });
    }

// ===== MASTER SKEMA KOMPENSASI =====
async function renderMasterKompensasi() {
    try {
        const container = document.getElementById('compensationSchemesContainer');
        if (container) {
            container.innerHTML = `<tr><td colspan="5" style="text-align: center; padding: 20px; color: #94a3b8;"><i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i>Loading data...</td></tr>`;
        }

        const res = await fetch(`${API_URL}/compensation-schemes`);
        window.compensationSchemes = await res.json();
        if (!container) return;
        
        if (window.compensationSchemes.length === 0) {
            container.innerHTML = `
                <tr>
                    <td colspan="5" style="text-align: center; padding: 40px;">
                        <div class="empty-schemes" style="border: 2px dashed #cbd5e1; border-radius: 12px; background: white; padding: 30px; margin: 0 auto; max-width: 500px;">
                            <i class="fas fa-coins" style="font-size: 40px; color: #94a3b8; margin-bottom: 12px; display: block;"></i>
                            <p style="color: #64748b; font-weight: 600; margin-bottom: 15px;">No global allowance schemes available.</p>
                            <button class="btn-add" onclick="bukaModalSchemeKompensasi('tambah')" style="margin: 0 auto; background: var(--primary-color);">
                                <i class="fas fa-plus"></i> Add First Scheme
                            </button>
                        </div>
                    </td>
                </tr>
            `;
            return;
        }

        container.innerHTML = window.compensationSchemes.map((scheme, index) => {
            const comp = (scheme.components && scheme.components.length > 0) ? scheme.components[0] : null;

            let valueDisplay = '-';
            if (comp) {
                if (comp.sumber_value === 'ump') {
                    valueDisplay = `${parseFloat(comp.value)}% UMP`;
                } else if (comp.sumber_value === 'umk') {
                    valueDisplay = `${parseFloat(comp.value)}% UMK`;
                } else if (comp.sumber_value === 'ump_umk') {
                    valueDisplay = `${parseFloat(comp.value)}% UMP/UMK`;
                } else {
                    valueDisplay = comp.is_persentase == 1 ? `${parseFloat(comp.value)}%` : formatRupiah(comp.value);
                }
            }

            let periodDisplay = '-';
            if (comp) {
                if (comp.period === 'jam' || comp.period === 'per_jam') {
                    periodDisplay = 'Per Hour';
                } else if (comp.period === 'hari_kerja') {
                    periodDisplay = 'Per Working Day';
                } else if (comp.period === 'minggu') {
                    periodDisplay = 'Per Week';
                } else if (comp.period === 'year') {
                    periodDisplay = 'Per Year';
                } else {
                    periodDisplay = 'Per Month';
                }
            }

            let sifatDisplay = '';
            if (comp) {
                sifatDisplay = comp.sifat_kompensasi === 'tidak_tetap' ? 'Variable Allowance' : 'Fixed Allowance';
            }

            return `
                <tr id="comp-scheme-row-${scheme.id}" style="border-bottom: 1px solid #e2e8f0; transition: background 0.2s;">
                    <td style="text-align: center; padding: 16px; color: #475569;">${index + 1}</td>
                    <td style="padding: 16px; font-weight: 600; color: #1e293b;">
                        <div style="display: flex; flex-direction: column;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-coins" style="color: #10b981;"></i>
                            ${scheme.nama}
                        </div>
                            <span style="font-size: 11px; color: #64748b; font-weight: normal; margin-left: 22px; margin-top: 4px;">
                                ${sifatDisplay}
                            </span>
                        </div>
                    </td>
                    <td style="text-align: center; padding: 16px; font-weight: 600; color: #1e293b;">${valueDisplay}</td>
                    <td style="text-align: center; padding: 16px; color: #475569;">${periodDisplay}</td>
                    <td style="text-align: center; padding: 16px;">
                        <div style="display: flex; gap: 8px; justify-content: center; align-items: center;">
                            <button class="btn-icon btn-edit" onclick="bukaModalSchemeKompensasi('edit', ${scheme.id})" title="Edit Scheme"><i class="fas fa-edit"></i></button>
                            <button class="btn-icon btn-delete" onclick="hapusSchemeKompensasi(${scheme.id})" title="Delete Scheme"><i class="fas fa-trash"></i></button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        console.error(err);
    }
}

function toggleSchemeCardBody(schemeId) {
    const body = document.getElementById(`comp-scheme-body-${schemeId}`);
    const toggle = document.getElementById(`comp-scheme-toggle-${schemeId}`);
    if (body) {
        body.classList.toggle('expanded');
        if (body.classList.contains('expanded')) {
            body.style.display = 'table-row';
            if (toggle) toggle.classList.add('expanded');
        } else {
            body.style.display = 'none';
            if (toggle) toggle.classList.remove('expanded');
        }
    }
}

function handleSchemeSumberValueChange() {
    const sumber = document.getElementById('schemeKompensasiSumber').value;
    const labelValue = document.getElementById('labelValueScheme');
    const inputValue = document.getElementById('schemeKompensasiValue');
    const isPersentase = document.getElementById('schemeKompensasiIsPersentase');
    const selectPeriod = document.getElementById('schemeKompensasiPeriod');

    if (sumber === 'ump' || sumber === 'umk') {
        if (isPersentase) isPersentase.value = '1';
        if (labelValue) labelValue.innerText = `Percentage Value (%) from ${sumber.toUpperCase()}`;
        if (inputValue) {
            inputValue.placeholder = 'e.g., 100';
            const cleanVal = parseFormattedNumber(inputValue.value);
            inputValue.value = cleanVal || '100';
        }
        if (selectPeriod) {
            selectPeriod.value = 'month';
            selectPeriod.disabled = true;
        }
    } else {
        if (isPersentase) isPersentase.value = '0';
        if (labelValue) labelValue.innerText = 'Custom Nominal (Rp)';
        if (inputValue) {
            inputValue.placeholder = 'e.g., 200000';
            const cleanVal = parseFormattedNumber(inputValue.value);
            inputValue.value = cleanVal || '';
            formatRupiahInput(inputValue);
        }
        if (selectPeriod) {
            // Only re-enable if Allowance Type is not Fixed (tetap locks period)
            const sifat = document.getElementById('schemeKompensasiSifat').value;
            selectPeriod.disabled = (sifat === 'tetap');
        }
    }
}

// Lock Period to "Per Month" when Fixed Allowance is selected (Scheme modal)
function handleSchemeSifatChange() {
    const sifat = document.getElementById('schemeKompensasiSifat').value;
    const selectPeriod = document.getElementById('schemeKompensasiPeriod');
    if (!selectPeriod) return;
    if (sifat === 'tetap') {
        selectPeriod.value = 'month';
        selectPeriod.disabled = true;
    } else {
        // Only re-enable if sumber_value is not UMP/UMK (those also lock period)
        const sumber = document.getElementById('schemeKompensasiSumber').value;
        if (sumber !== 'ump' && sumber !== 'umk') {
            selectPeriod.disabled = false;
        }
    }
}

// Lock Period to "Month" when Fixed Allowance is selected (Component modal)
function handleComponentSifatChange() {
    const sifat = document.getElementById('componentKompensasiSifat').value;
    const selectPeriod = document.getElementById('componentKompensasiPeriod');
    if (!selectPeriod) return;
    if (sifat === 'tetap') {
        selectPeriod.value = 'month';
        selectPeriod.disabled = true;
    } else {
        selectPeriod.disabled = false;
    }
}

window.handlePayrollSchemeSumberValueChange = function() {
    const sumber = document.getElementById('schemeSumber').value;
    const labelValue = document.getElementById('labelValueSchemePayroll');
    const inputValue = document.getElementById('schemeValue');
    const isPersentase = document.getElementById('schemeIsPersentase');
    const selectPeriod = document.getElementById('schemePeriod');

    if (sumber === 'ump' || sumber === 'umk') {
        if (isPersentase) isPersentase.value = '1';
        if (labelValue) labelValue.innerText = `Percentage Value (%) from ${sumber.toUpperCase()}`;
        if (inputValue) {
            inputValue.placeholder = 'e.g., 100';
            const cleanVal = parseFormattedNumber(inputValue.value);
            inputValue.value = cleanVal || '100';
        }
        if (selectPeriod) {
            selectPeriod.value = 'month';
            selectPeriod.disabled = true;
        }
    } else {
        if (isPersentase) isPersentase.value = '0';
        if (labelValue) labelValue.innerText = 'Custom Nominal (Rp)';
        if (inputValue) {
            inputValue.placeholder = 'e.g., 5000000';
            const cleanVal = parseFormattedNumber(inputValue.value);
            inputValue.value = cleanVal || '';
            formatRupiahInput(inputValue);
        }
        if (selectPeriod) {
            selectPeriod.disabled = false;
        }
    }
}

window.handleSchemeValueInput = function(element) {
    const sumber = document.getElementById('schemeSumber').value;
    if (sumber === 'ump' || sumber === 'umk') {
        element.value = element.value.replace(/[^0-9.,]/g, '');
    } else {
        formatRupiahInput(element);
    }
}

window.handleSchemeValueInput = function(element) {
    const sumber = document.getElementById('schemeKompensasiSumber').value;
    if (sumber === 'ump' || sumber === 'umk') {
        element.value = element.value.replace(/[^0-9.,]/g, '');
    } else {
        formatRupiahInput(element);
    }
}

function bukaModalSchemeKompensasi(mode, id = null) {
    const modal = document.getElementById('modalSchemeKompensasi');
    const overlay = document.getElementById('overlay');
    if (modal) {
        modal.style.display = 'block';
        modal.style.zIndex = '2050';
    }
    if (overlay) {
        overlay.style.display = 'block';
        overlay.style.zIndex = '2040';
    }
    if (mode === 'edit' && id) {
        const scheme = window.compensationSchemes.find(s => s.id == id);
        if (scheme) {
            const comp = (scheme.components && scheme.components.length > 0) ? scheme.components[0] : null;
            document.getElementById('modalSchemeKompensasiTitle').innerText = 'Edit Allowance Scheme';
            document.getElementById('schemeKompensasiId').value = scheme.id;
            document.getElementById('schemeKompensasiNama').value = scheme.nama;
            document.getElementById('schemeKompensasiDeskripsi').value = '';
            
            if (comp) {
                document.getElementById('schemeKompensasiSumber').value = comp.sumber_value || 'nominal';
                document.getElementById('schemeKompensasiPeriod').value = comp.period || 'month';
                const elValue = document.getElementById('schemeKompensasiValue');
                if (comp.sumber_value === 'ump' || comp.sumber_value === 'umk') {
                    elValue.value = parseFloat(comp.value) || 0;
                } else {
                    elValue.value = Math.round(parseFloat(comp.value) || 0);
                    formatRupiahInput(elValue);
                }
                document.getElementById('schemeKompensasiIsPersentase').value = comp.is_persentase || '0';
                document.getElementById('schemeKompensasiSifat').value = comp.sifat_kompensasi || 'tetap';
            } else {
                document.getElementById('schemeKompensasiSumber').value = 'nominal';
                document.getElementById('schemeKompensasiPeriod').value = 'month';
                const elValue = document.getElementById('schemeKompensasiValue');
                elValue.value = '0';
                formatRupiahInput(elValue);
                document.getElementById('schemeKompensasiIsPersentase').value = '0';
                document.getElementById('schemeKompensasiSifat').value = 'tetap';
            }
            handleSchemeSumberValueChange();
            handleSchemeSifatChange();
        }
    } else {
        document.getElementById('modalSchemeKompensasiTitle').innerText = 'Add Allowance Scheme';
        document.getElementById('formSchemeKompensasi').reset();
        document.getElementById('schemeKompensasiId').value = '';
        document.getElementById('schemeKompensasiNama').value = '';
        document.getElementById('schemeKompensasiDeskripsi').value = '';
        document.getElementById('schemeKompensasiSumber').value = 'nominal';
        document.getElementById('schemeKompensasiPeriod').value = 'month';
        document.getElementById('schemeKompensasiValue').value = '';
        document.getElementById('schemeKompensasiIsPersentase').value = '0';
        document.getElementById('schemeKompensasiSifat').value = 'tetap';
        handleSchemeSumberValueChange();
        handleSchemeSifatChange();
    }
}

function tutupModalSchemeKompensasi() {
    tutupAllModal();
}

async function hapusSchemeKompensasi(id) {
    if (!await showConfirm('Are you sure you want to delete this allowance scheme and all allowances inside it?')) return;
    try {
        const res = await fetch(`${API_URL}/compensation-schemes/${id}`, { method: 'DELETE' });
        if (res.ok) {
            renderMasterKompensasi();
            showToast('Allowance scheme deleted successfully!', 'success');
        } else {
            showToast('Failed to delete scheme!', 'error');
        }
    } catch (err) {
        console.error(err);
    }
}

function handleJenisComponentChange() {
    const jenis = document.getElementById('componentKompensasiJenis').value;
    const sifatContainer = document.getElementById('containerSifatKompensasi');
    if (jenis === 'kompensasi') {
        if (sifatContainer) sifatContainer.style.display = 'block';
    } else {
        if (sifatContainer) sifatContainer.style.display = 'none';
    }
}

function handleSumberValueChange() {
    const sumber = document.getElementById('componentKompensasiSumber').value;
    const formatContainer = document.getElementById('containerFormatValue');
    const labelValue = document.getElementById('labelValueKompensasi');
    const inputValue = document.getElementById('componentKompensasiValue');
    const selectIsPersentase = document.getElementById('componentKompensasiIsPersentase');

    if (sumber === 'ump') {
        if (formatContainer) formatContainer.style.display = 'none';
        if (selectIsPersentase) selectIsPersentase.value = '1';
        if (labelValue) labelValue.innerText = 'Percentage Value (%) of UMP';
        if (inputValue) inputValue.placeholder = 'e.g., 100';
    } else if (sumber === 'umk') {
        if (formatContainer) formatContainer.style.display = 'none';
        if (selectIsPersentase) selectIsPersentase.value = '1';
        if (labelValue) labelValue.innerText = 'Percentage Value (%) of UMK';
        if (inputValue) inputValue.placeholder = 'e.g., 100';
    } else if (sumber === 'ump_umk') {
        if (formatContainer) formatContainer.style.display = 'none';
        if (selectIsPersentase) selectIsPersentase.value = '1';
        if (labelValue) labelValue.innerText = 'Percentage Value (%) of UMP/UMK';
        if (inputValue) inputValue.placeholder = 'e.g., 100';
    } else {
        if (formatContainer) formatContainer.style.display = 'none';
        if (selectIsPersentase) selectIsPersentase.value = '0';
        if (labelValue) labelValue.innerText = 'Custom Nominal (Rp)';
        if (inputValue) inputValue.placeholder = 'e.g., 5000000';
    }
}

function bukaModalComponentKompensasi(schemeId, mode, id = null) {
    document.getElementById('modalComponentKompensasi').style.display = 'block';
    document.getElementById('overlay').style.display = 'block';
    document.getElementById('componentKompensasiSchemeId').value = schemeId;
    if (mode === 'edit' && id) {
        const scheme = window.compensationSchemes.find(s => s.id == schemeId);
        const k = scheme ? (scheme.components || []).find(comp => comp.id == id) : null;
        if (k) {
            document.getElementById('modalComponentKompensasiTitle').innerText = 'Edit Allowance';
            document.getElementById('componentKompensasiId').value = k.id;
            const nameEl = document.getElementById('componentKompensasiNama');
            if (nameEl) nameEl.value = k.nama;
            const tipeEl = document.getElementById('componentKompensasiTipe');
            if (tipeEl) tipeEl.value = k.tipe;
            const elValue = document.getElementById('componentKompensasiValue');
            elValue.value = k.value;
            handleComponentKompensasiValueInput(elValue);
            document.getElementById('componentKompensasiIsPersentase').value = (k.is_persentase == 1 || k.is_persentase === true || k.is_persentase === '1') ? '1' : '0';
            
            // New fields
            document.getElementById('componentKompensasiJenis').value = k.jenis_component || 'kompensasi';
            document.getElementById('componentKompensasiSifat').value = k.sifat_kompensasi || 'tetap';
            document.getElementById('componentKompensasiSumber').value = k.sumber_value || 'nominal';
            document.getElementById('componentKompensasiPeriod').value = k.period || 'month';
        }
    } else {
        document.getElementById('modalComponentKompensasiTitle').innerText = 'Add Allowance';
        document.getElementById('formComponentKompensasi').reset();
        document.getElementById('componentKompensasiId').value = '';
        document.getElementById('componentKompensasiSchemeId').value = schemeId;
        
        // Defaults
        document.getElementById('componentKompensasiJenis').value = 'kompensasi';
        document.getElementById('componentKompensasiSifat').value = 'tetap';
        document.getElementById('componentKompensasiSumber').value = 'nominal';
        document.getElementById('componentKompensasiPeriod').value = 'month';
        document.getElementById('componentKompensasiIsPersentase').value = '0';
    }
    
    // Trigger handlers to update visibility/labels
    handleJenisComponentChange();
    handleSumberValueChange();
    handleComponentSifatChange();
}

function tutupModalComponentKompensasi() {
    tutupAllModal();
}

async function hapusComponentKompensasi(id) {
    if (!await showConfirm('Are you sure you want to delete this allowance?')) return;
    try {
        const res = await fetch(`${API_URL}/compensation-components/${id}`, { method: 'DELETE' });
        if (res.ok) {
            renderMasterKompensasi();
            showToast('Allowance deleted successfully!', 'success');
        } else {
            showToast('Failed to delete allowance!', 'error');
        }
    } catch (err) {
        console.error(err);
    }
}

// ===== EXCEL TEMPLATE & BULK UPLOAD FOR ALLOWANCE SCHEME =====

function downloadAllowanceSchemeTemplate() {
    try {
        const headers = [['Allowance Scheme Name', 'Allowance Type', 'Value Source', 'Period', 'Custom Nominal (Rp) / Value']];
        const dummyRows = [
            ['Meal Allowance', 'Variable Allowance', 'Custom Nominal', 'Per Working Day', 10000],
            ['Transport Allowance', 'Fixed Allowance', 'Custom Nominal', 'Per Month', 200000],
            ['Position Allowance', 'Fixed Allowance', 'UMP (Province)', 'Per Month', 10]
        ];
        const data = headers.concat(dummyRows);

        const ws = XLSX.utils.aoa_to_sheet(data);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Allowance Schemes');

        // Set column widths
        ws['!cols'] = [
            { wch: 28 }, // Allowance Scheme Name
            { wch: 20 }, // Allowance Type
            { wch: 20 }, // Value Source
            { wch: 20 }, // Period
            { wch: 30 }  // Custom Nominal (Rp) / Value
        ];

        XLSX.writeFile(wb, 'master_allowance_scheme_template.xlsx');
        showToast('Template downloaded successfully', 'success');
    } catch (err) {
        console.error(err);
        showToast('Failed to download template', 'error');
    }
}

function triggerAllowanceSchemeExcelUpload() {
    const input = document.getElementById('allowanceSchemeExcelInput');
    if (input) {
        input.value = ''; // Reset to allow uploading same file
        input.click();
    }
}

function handleAllowanceSchemeExcelUpload(event) {
    const file = event.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = async (e) => {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const sheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[sheetName];

            const rows = XLSX.utils.sheet_to_json(worksheet, { header: 1 });
            if (rows.length < 2) {
                showToast('The uploaded Excel file contains no data rows.', 'error');
                return;
            }

            // Normalize headers
            const rawHeaders = rows[0].map(h => h ? h.toString().trim() : '');
            const normHeaders = rawHeaders.map(h => h.toLowerCase().replace(/[^a-z0-9]/g, ''));
            
            let nameIndex = normHeaders.findIndex(h => h === 'allowanceschemename' || h === 'schemename' || h === 'namascheme' || h === 'namaallowance' || h === 'nama' || h === 'name');
            if (nameIndex === -1) nameIndex = normHeaders.findIndex(h => h.includes('scheme') || h.includes('nama') || h.includes('name'));
            if (nameIndex === -1) nameIndex = 0; // Fallback to column 0

            let typeIndex = normHeaders.findIndex(h => h === 'allowancetype' || h === 'schemetype' || h === 'sifatkompensasi' || h === 'sifat' || h === 'type');
            if (typeIndex === -1) typeIndex = normHeaders.findIndex(h => h.includes('type') || h.includes('sifat'));
            if (typeIndex === -1) typeIndex = 1; // Fallback to column 1

            let sourceIndex = normHeaders.findIndex(h => h === 'valuesource' || h === 'sumbervalue' || h === 'sumber' || h === 'source');
            if (sourceIndex === -1) sourceIndex = normHeaders.findIndex(h => h.includes('source') || h.includes('sumber'));
            if (sourceIndex === -1) sourceIndex = 2; // Fallback to column 2

            let periodIndex = normHeaders.findIndex(h => h === 'period' || h === 'period');
            if (periodIndex === -1) periodIndex = normHeaders.findIndex(h => h.includes('period') || h.includes('period'));
            if (periodIndex === -1) periodIndex = 3; // Fallback to column 3

            let valueIndex = normHeaders.findIndex((h, idx) => idx !== sourceIndex && (h.includes('custom') || h.includes('nominal') || h.includes('value') || h.includes('amount') || h === 'value'));
            if (valueIndex === -1) {
                valueIndex = normHeaders.findIndex((h, idx) => idx !== sourceIndex && idx !== nameIndex && idx !== typeIndex && idx !== periodIndex && h.includes('value'));
            }
            if (valueIndex === -1) valueIndex = 4; // Fallback to column 4

            let percentIndex = normHeaders.findIndex(h => h.includes('percent') || h.includes('persen'));
            let descIndex = normHeaders.findIndex(h => h.includes('desc') || h.includes('description') || h.includes('deskripsi'));

            const schemes = [];
            for (let i = 1; i < rows.length; i++) {
                const r = rows[i];
                if (!r || r.length === 0) continue;

                const isRowEmpty = r.every(cell => cell === null || cell === undefined || cell.toString().trim() === '');
                if (isRowEmpty) continue;

                const namaVal = r[nameIndex] ? r[nameIndex].toString().trim() : '';
                if (!namaVal) continue;

                const typeVal = typeIndex !== -1 && r[typeIndex] ? r[typeIndex].toString().trim() : 'Fixed Allowance';
                const sourceVal = sourceIndex !== -1 && r[sourceIndex] ? r[sourceIndex].toString().trim() : 'Custom Nominal';
                const periodVal = periodIndex !== -1 && r[periodIndex] ? r[periodIndex].toString().trim() : 'Per Month';
                
                const rawValue = (valueIndex !== -1 && r[valueIndex] !== undefined && r[valueIndex] !== null) ? r[valueIndex] : 0;
                let valStr = rawValue.toString().trim();
                let cleanStr = valStr.replace(/[^0-9.,-]/g, '');
                if (cleanStr.includes('.') && !cleanStr.includes(',')) {
                    const parts = cleanStr.split('.');
                    if (parts.length > 1 && parts[parts.length - 1].length === 3) {
                        cleanStr = cleanStr.replace(/\./g, '');
                    }
                } else if (cleanStr.includes(',')) {
                    cleanStr = cleanStr.replace(/,/g, '');
                }
                let numValue = parseFloat(cleanStr) || 0;

                // Determine percentage
                let isPersentase = 0;
                if (percentIndex !== -1 && r[percentIndex] !== undefined && r[percentIndex] !== null) {
                    const rawPercent = r[percentIndex].toString().trim().toLowerCase();
                    isPersentase = (rawPercent === 'yes' || rawPercent === '1' || rawPercent === 'true' || rawPercent === 'ya') ? 1 : 0;
                } else {
                    const sourceLower = sourceVal.toLowerCase();
                    if (sourceLower.includes('ump') || sourceLower.includes('umk') || valStr.includes('%')) {
                        isPersentase = 1;
                    }
                }

                const descVal = descIndex !== -1 && r[descIndex] ? r[descIndex].toString().trim() : '';

                schemes.push({
                    nama: namaVal,
                    sifat_kompensasi: typeVal,
                    sumber_value: sourceVal,
                    value: numValue,
                    is_persentase: isPersentase,
                    period: periodVal,
                    deskripsi: descVal
                });
            }

            if (schemes.length === 0) {
                showToast('No valid data rows found in the uploaded file.', 'error');
                return;
            }

            const userObj = JSON.parse(localStorage.getItem('user'));
            const username = userObj ? userObj.username : 'admin';

            const response = await fetch(`${API_URL}/compensation-schemes/bulk`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-User-Action': username
                },
                body: JSON.stringify(schemes)
            });

            const resData = await response.json();
            if (response.ok) {
                showToast(resData.message || 'Bulk allowance schemes uploaded successfully!', 'success');
                renderMasterKompensasi();
            } else {
                if (resData.errors && Array.isArray(resData.errors)) {
                    alert('Failed to upload allowance schemes:\n\n' + resData.errors.join('\n'));
                } else {
                    showToast(resData.message || resData.error || 'Failed to import allowance schemes.', 'error');
                }
            }
        } catch (err) {
            console.error(err);
            showToast('Error parsing Excel file.', 'error');
        }
    };
    reader.readAsArrayBuffer(file);
}

window.downloadAllowanceSchemeTemplate = downloadAllowanceSchemeTemplate;
window.triggerAllowanceSchemeExcelUpload = triggerAllowanceSchemeExcelUpload;
window.handleAllowanceSchemeExcelUpload = handleAllowanceSchemeExcelUpload;
window.bukaModalSchemeKompensasi = bukaModalSchemeKompensasi;
window.tutupModalSchemeKompensasi = tutupModalSchemeKompensasi;
window.bukaModalComponentKompensasi = bukaModalComponentKompensasi;
window.tutupModalComponentKompensasi = tutupModalComponentKompensasi;
window.renderMasterKompensasi = renderMasterKompensasi;
window.hapusSchemeKompensasi = hapusSchemeKompensasi;
window.hapusComponentKompensasi = hapusComponentKompensasi;
window.handleSchemeSifatChange = handleSchemeSifatChange;
window.handleSchemeSumberValueChange = handleSchemeSumberValueChange;
window.handleJenisComponentChange = handleJenisComponentChange;
window.handleComponentSifatChange = handleComponentSifatChange;
window.handleSumberValueChange = handleSumberValueChange;
window.handleComponentKompensasiValueInput = handleComponentKompensasiValueInput;
