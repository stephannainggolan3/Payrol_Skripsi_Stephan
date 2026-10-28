// ===== WORKSPACE MODULE =====
// Extracted from app.js for modular monolith architecture

async function populateMinimumWageDropdown(tipe, selectElementId) {
    try {
        const res = await fetch(`${API_URL}/minimum-wages?tipe=${tipe}`);
        const data = await res.json();
        const select = document.getElementById(selectElementId);
        if (select) {
            select.innerHTML = '<option value="">-- Select Region --</option>' +
                data.map(item => `<option value="${item.id}">${item.nama_daerah} (Rp ${parseFloat(item.nominal).toLocaleString('id-ID')})</option>`).join('');
        }
    } catch (err) {
        console.error('Error fetching minimum wages:', err);
    }
}

async function handlePilihanSchemePayrollTipeChange() {
    const tipe = document.getElementById('pilihanSchemePayrollTipe').value;
    const wilContainer = document.getElementById('pilihanSchemePayrollRegionContainer');
    const nomContainer = document.getElementById('pilihanSchemePayrollNominalContainer');
    const tplContainer = document.getElementById('pilihanSchemePayrollTemplateContainer');

    wilContainer.style.display = 'none';
    nomContainer.style.display = 'none';
    tplContainer.style.display = 'none';

    if (tipe === 'UMP' || tipe === 'UMK') {
        wilContainer.style.display = 'block';
        await populateMinimumWageDropdown(tipe, 'pilihanSchemePayrollRegion');
    } else if (tipe === 'Nominal') {
        nomContainer.style.display = 'block';
    } else if (tipe === 'Template') {
        tplContainer.style.display = 'block';
    }
}

async function handleSetupPayrollSchemeTipeChange() {
    const tipe = document.getElementById('setupPayrollSchemeTipe').value;
    const wilContainer = document.getElementById('setupPayrollSchemeRegionContainer');
    const nomContainer = document.getElementById('setupPayrollSchemeNominalContainer');
    const tplContainer = document.getElementById('setupPayrollSchemeTemplateContainer');

    wilContainer.style.display = 'none';
    nomContainer.style.display = 'none';
    tplContainer.style.display = 'none';

    if (tipe === 'UMP' || tipe === 'UMK') {
        wilContainer.style.display = 'block';
        await populateMinimumWageDropdown(tipe, 'setupPayrollSchemeRegion');
    } else if (tipe === 'Nominal') {
        nomContainer.style.display = 'block';
    } else if (tipe === 'Template') {
        tplContainer.style.display = 'block';
    }
}

async function loadWorkspaceSetup() {
    if (!window.selectedClientId) return;
    try {
        const response = await fetch(`${API_URL}/client-configs`);
        const configs = await response.json();
        const conf = configs.find(c => c.client_id == window.selectedClientId);
        
        document.getElementById('wSetupClientName').innerText = window.selectedClientName || '-';
        
        if (conf) {
            let payrollSchemeText = 'Belum Set';
            if (conf.payroll_type === 'UMP') {
                payrollSchemeText = `UMP: ${conf.minimum_wage_region || 'Belum Set'} (Rp ${conf.minimum_wage_nominal ? parseFloat(conf.minimum_wage_nominal).toLocaleString('id-ID') : '-'})`;
            } else if (conf.payroll_type === 'UMK') {
                payrollSchemeText = `UMK: ${conf.minimum_wage_region || 'Belum Set'} (Rp ${conf.minimum_wage_nominal ? parseFloat(conf.minimum_wage_nominal).toLocaleString('id-ID') : '-'})`;
            } else if (conf.payroll_type === 'Nominal') {
                payrollSchemeText = `Nominal: Rp ${conf.custom_nominal ? parseFloat(conf.custom_nominal).toLocaleString('id-ID') : '-'}`;
            } else if (conf.payroll_type === 'Template') {
                payrollSchemeText = conf.payroll_scheme_name || 'Belum Set';
            }
            document.getElementById('wSetupPayrollScheme').innerText = payrollSchemeText;
            document.getElementById('wSetupBpjsScheme').innerText = conf.bpjs_scheme_name || 'Belum Set';
            document.getElementById('wSetupTaxScheme').innerText = conf.tax_scheme_name || 'Belum Set';
            document.getElementById('wSetupPayDate').innerText = conf.pay_date ? `Tgl ${conf.pay_date}` : 'Belum Set';
            document.getElementById('wSetupCutoff').innerText = conf.cutoff_start ? `${conf.cutoff_start} s/d ${(conf.cutoff_start - 1)}` : 'Belum Set';
        } else {
            document.getElementById('wSetupPayrollScheme').innerText = 'Belum Set';
            document.getElementById('wSetupBpjsScheme').innerText = 'Belum Set';
            document.getElementById('wSetupTaxScheme').innerText = 'Belum Set';
            document.getElementById('wSetupPayDate').innerText = 'Belum Set';
            document.getElementById('wSetupCutoff').innerText = 'Belum Set';
        }
    } catch (err) {
        console.error(err);
    }
}

// ===== 5. SETUP PAYROLL KLIEN =====
async function renderClientSetup() {
    try {
        const response = await fetch(`${API_URL}/client-configs`);
        clientConfigs = await response.json();
        const tbody = document.getElementById('tabelSetupBody');
        if (!tbody) return;
        tbody.innerHTML = clientConfigs.map(conf => {
            let payrollSchemeText = 'Belum Set';
            if (conf.payroll_type === 'UMP') {
                payrollSchemeText = `UMP: ${conf.minimum_wage_region || 'Belum Set'} (Rp ${conf.minimum_wage_nominal ? parseFloat(conf.minimum_wage_nominal).toLocaleString('id-ID') : '-'})`;
            } else if (conf.payroll_type === 'UMK') {
                payrollSchemeText = `UMK: ${conf.minimum_wage_region || 'Belum Set'} (Rp ${conf.minimum_wage_nominal ? parseFloat(conf.minimum_wage_nominal).toLocaleString('id-ID') : '-'})`;
            } else if (conf.payroll_type === 'Nominal') {
                payrollSchemeText = `Nominal: Rp ${conf.custom_nominal ? parseFloat(conf.custom_nominal).toLocaleString('id-ID') : '-'}`;
            } else if (conf.payroll_type === 'Template') {
                payrollSchemeText = conf.payroll_scheme_name || 'Belum Set';
            }
            return `
                <tr>
                    <td style="font-weight: 600;">${conf.client_name}</td>
                    <td><span class="scheme-badge monthan">${payrollSchemeText}</span></td>
                    <td><span class="scheme-badge" style="background:#e74c3c;">${conf.tax_scheme_name || 'Belum Set'}</span></td>
                    <td>Tgl ${conf.pay_date || '-'}</td>
                    <td>${conf.cutoff_start || '-'}-${(conf.cutoff_start - 1) || '-'}</td>
                    <td>
                        <button class="btn-icon btn-edit" onclick="bukaModalSetup(${conf.client_id}, '${conf.client_name}')"><i class="fas fa-cog"></i></button>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) { console.error(err); }
}

async function bukaModalSetup(clientId, clientName) {
    document.getElementById('modalSetup').style.display = 'block';
    document.getElementById('overlay').style.display = 'block';
    document.getElementById('setupClientId').value = clientId;
    document.getElementById('setupClientNama').value = clientName;
    
    const pRes = await fetch(`${API_URL}/payroll-schemes`);
    const pSchemes = await pRes.json();
    const tRes = await fetch(`${API_URL}/tax-schemes`);
    const tSchemes = await tRes.json();

    const bpjsOpts = tSchemes.filter(s => s.tipe === 'bpjs');
    const taxOpts = tSchemes.filter(s => s.tipe === 'pph21');

    document.getElementById('setupPayrollScheme').innerHTML = '<option value="">-- Select Scheme --</option>' + pSchemes.map(s => `<option value="${s.id}">${s.nama}</option>`).join('');
    document.getElementById('setupBpjsScheme').innerHTML = '<option value="">-- Select BPJS Scheme --</option>' + bpjsOpts.map(s => `<option value="${s.id}">${s.nama}</option>`).join('');
    document.getElementById('setupTaxScheme').innerHTML = '<option value="">-- Select PPh 21 Scheme --</option>' + taxOpts.map(s => `<option value="${s.id}">${s.nama}</option>`).join('');
    
    const current = clientConfigs.find(c => c.client_id == clientId);
    if(current) {
        document.getElementById('setupPayDate').value = current.pay_date || 25;
        document.getElementById('setupCutoffStart').value = current.cutoff_start || 21;
        document.getElementById('setupBpjsScheme').value = current.bpjs_scheme_id || '';
        document.getElementById('setupTaxScheme').value = current.tax_scheme_id || '';
        
        const tipeSelect = document.getElementById('setupPayrollSchemeTipe');
        if (tipeSelect) {
            tipeSelect.value = current.payroll_type || '';
            await handleSetupPayrollSchemeTipeChange();
            
            if (current.payroll_type === 'UMP' || current.payroll_type === 'UMK') {
                const wilSelect = document.getElementById('setupPayrollSchemeRegion');
                if (wilSelect && current.minimum_wage_id) {
                    wilSelect.value = current.minimum_wage_id;
                }
            } else if (current.payroll_type === 'Nominal') {
                const nomInput = document.getElementById('setupPayrollSchemeNominal');
                if (nomInput && current.custom_nominal) {
                    nomInput.value = current.custom_nominal;
                }
            } else if (current.payroll_type === 'Template') {
                const tplSelect = document.getElementById('setupPayrollScheme');
                if (tplSelect && current.payroll_scheme_id) {
                    tplSelect.value = current.payroll_scheme_id;
                }
            }
        }
    } else {
        const tipeSelect = document.getElementById('setupPayrollSchemeTipe');
        if (tipeSelect) {
            tipeSelect.value = '';
            handleSetupPayrollSchemeTipeChange();
        }
    }
}

    // formSetup submit handler
    if(document.getElementById('formSetup')) {
        document.getElementById('formSetup').addEventListener('submit', async (e) => {
            e.preventDefault();
            const payrollType = document.getElementById('setupPayrollSchemeTipe').value;
            const minimumWageId = document.getElementById('setupPayrollSchemeRegion').value;
            const customNominal = document.getElementById('setupPayrollSchemeNominal').value;
            const payrollSchemeId = document.getElementById('setupPayrollScheme').value;

            const data = {
                client_id: document.getElementById('setupClientId').value,
                payroll_type: payrollType || null,
                minimum_wage_id: (payrollType === 'UMP' || payrollType === 'UMK') ? (minimumWageId || null) : null,
                custom_nominal: (payrollType === 'Nominal') ? (customNominal || null) : null,
                payroll_scheme_id: (payrollType === 'Template') ? (payrollSchemeId || null) : null,
                bpjs_scheme_id: document.getElementById('setupBpjsScheme').value || null,
                tax_scheme_id: document.getElementById('setupTaxScheme').value || null,
                pay_date: parseInt(document.getElementById('setupPayDate').value),
                cutoff_start: parseInt(document.getElementById('setupCutoffStart').value)
            };
            const res = await fetch(`${API_URL}/client-configs`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            if (res.ok) {
                tutupAllModal();
                showToast('Payroll setup saved successfully!', 'success');
                if (window.selectedClientId) {
                    loadWorkspaceSetup();
                } else {
                    renderClientSetup();
                }
            } else {
                showToast('Failed to save Setup Payroll!', 'error');
            }
        });
    }

// ===== PILIHAN SKEMA (Client Workspace Tab) =====
async function renderPilihanKompensasiSummary(schemeId) {
    const summaryDiv = document.getElementById('pilihanKompensasiSummary');
    if (!summaryDiv) return;
    if (!schemeId) {
        summaryDiv.innerHTML = `<p style="text-align: center; color: #94a3b8; font-size: 13px; margin: 0;">Select a component scheme above to view details.</p>`;
        return;
    }
    try {
        const res = await fetch(`${API_URL}/compensation-schemes`);
        const schemes = await res.json();
        const scheme = schemes.find(s => s.id == schemeId);
        if (!scheme) {
            summaryDiv.innerHTML = `<p style="text-align: center; color: #94a3b8; font-size: 13px; margin: 0;">Scheme tidak ditemukan.</p>`;
            return;
        }
        
        const comps = scheme.components || [];
        if (comps.length === 0) {
            summaryDiv.innerHTML = `
                <div style="text-align: center; padding: 10px;">
                    <i class="fas fa-info-circle" style="color: #94a3b8; margin-right: 6px;"></i>
                    <span style="color: #94a3b8; font-size: 13px;">Belum ada component dalam scheme ini.</span>
                </div>`;
            return;
        }

        const revenue = comps.filter(c => c.tipe === 'revenue');
        const deduction = comps.filter(c => c.tipe === 'deduction');
        const totalRevenue = revenue.reduce((sum, c) => sum + parseFloat(c.value || 0), 0);
        const totalDeduction = deduction.reduce((sum, c) => sum + parseFloat(c.value || 0), 0);
        
        summaryDiv.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9;">
                <span style="font-weight: 600; color: #1e293b; font-size: 14px;"><i class="fas fa-list" style="margin-right: 6px; color: var(--primary-color);"></i> ${comps.length} Component</span>
                <div style="display: flex; gap: 12px;">
                    <span style="font-size: 12px; background: #dcfce7; color: #16a34a; padding: 4px 10px; border-radius: 20px; font-weight: 600;">+ ${formatRupiah(totalRevenue)}</span>
                    <span style="font-size: 12px; background: #fee2e2; color: #dc2626; padding: 4px 10px; border-radius: 20px; font-weight: 600;">- ${formatRupiah(totalDeduction)}</span>
                </div>
            </div>
            ${comps.map(c => `
                <div style="display: flex; justify-content: space-between; align-items: center; padding: 6px 0; font-size: 13px;">
                    <span style="color: #475569;">${c.nama}</span>
                    <span style="font-weight: 600; color: ${c.tipe === 'revenue' ? '#16a34a' : '#dc2626'};">
                        ${c.tipe === 'revenue' ? '+' : '-'} ${c.is_persentase == 1 ? parseFloat(c.value) + '%' : formatRupiah(c.value)}
                    </span>
                </div>
            `).join('')}
        `;
    } catch (err) {
        console.error(err);
    }
}

let wsClientOrgHierarchy = [];
window.editSchemaMappingId = null;

async function loadSchemaMappingOrgDropdowns() {
    if (!window.selectedClientId) return;
    try {
        const r = await fetch(`${API_URL}/org?client_id=${window.selectedClientId}`);
        wsClientOrgHierarchy = await r.json();
        
        const divSelect = document.getElementById('pilihanSchemeDivisionId');
        if(divSelect) {
            divSelect.innerHTML = '<option value="">-- Select Division --</option>';
            if (Array.isArray(wsClientOrgHierarchy)) {
                wsClientOrgHierarchy.forEach(div => {
                    divSelect.innerHTML += `<option value="${div.id}">${div.nama}</option>`;
                });
            }
        }
    } catch(e) { console.error(e); }
}

function handlePilihanSchemeLevelChange() {
    const level = document.getElementById('pilihanSchemeLevel').value;
    document.getElementById('pilihanSchemeDivisionContainer').style.display = (level !== 'general') ? 'block' : 'none';
    document.getElementById('pilihanSchemeDeptContainer').style.display = (level === 'department' || level === 'position') ? 'block' : 'none';
    document.getElementById('pilihanSchemePositionContainer').style.display = (level === 'position') ? 'block' : 'none';
}

function initCascadingSchemeDropdowns() {
    const divSelect = document.getElementById('pilihanSchemeDivisionId');
    const deptSelect = document.getElementById('pilihanSchemeDeptId');
    const posSelect = document.getElementById('pilihanSchemePositionId');
    
    if(divSelect) divSelect.onchange = () => {
        const divId = divSelect.value;
        if(deptSelect) deptSelect.innerHTML = '<option value="">-- Select Department --</option>';
        if(posSelect) posSelect.innerHTML = '<option value="">-- Select Position --</option>';
        if (divId && Array.isArray(wsClientOrgHierarchy)) {
            const division = wsClientOrgHierarchy.find(d => d.id == divId);
            if (division && Array.isArray(division.departments)) {
                division.departments.forEach(dept => {
                    if(deptSelect) deptSelect.innerHTML += `<option value="${dept.id}">${dept.nama}</option>`;
                });
            }
        }
    };
    
    if(deptSelect) deptSelect.onchange = () => {
        const divId = divSelect.value;
        const deptId = deptSelect.value;
        if(posSelect) posSelect.innerHTML = '<option value="">-- Select Position --</option>';
        if (divId && deptId && Array.isArray(wsClientOrgHierarchy)) {
            const division = wsClientOrgHierarchy.find(d => d.id == divId);
            if (division && Array.isArray(division.departments)) {
                const dept = division.departments.find(dp => dp.id == deptId);
                if (dept && Array.isArray(dept.positions)) {
                    dept.positions.forEach(pos => {
                        if(posSelect) posSelect.innerHTML += `<option value="${pos.id}">${pos.nama}</option>`;
                    });
                }
            }
        }
    };
}

async function loadSchemaMappingTable() {
    if (!window.selectedClientId) return;
    try {
        const tbody = document.getElementById('tabelPilihanSchemeKlien');
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; padding: 20px; color: #94a3b8;"><i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i>Loading data...</td></tr>`;
        }
        const res = await fetch(`${API_URL}/client-configs-mapping/${window.selectedClientId}`);
        const mappings = await res.json();
        
        if (!tbody) return;
        
        if (!mappings || mappings.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" style="text-align:center; padding: 40px; color: #94a3b8;"><i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; display: block;"></i>No payroll schemes registered yet. Click the "Add Scheme" button to configure.</td></tr>';
            return;
        }
        
        tbody.innerHTML = mappings.map(m => {
            const divLabel = m.division_name ? `<span style="font-weight:600; color:#1e293b;">${m.division_name}</span>` : `<span style="color:#94a3b8; font-style:italic;">Global</span>`;
            const deptLabel = m.department_name ? `<span style="font-weight:600; color:#1e293b;">${m.department_name}</span>` : `<span style="color:#94a3b8; font-style:italic;">Global</span>`;
            const posLabel = m.position_name ? `<span style="font-weight:600; color:#1e293b;">${m.position_name}</span>` : `<span style="color:#94a3b8; font-style:italic;">Global</span>`;
            const psName = m.payroll_scheme_name || m.payroll_type || '-';
            const bpjsName = m.bpjs_scheme_name || '-';

            return `
                <tr style="transition: background 0.2s;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='white'">
                    <td style="padding: 14px 16px; border-bottom: 1px solid #e2e8f0; font-size: 13.5px;">${divLabel}</td>
                    <td style="padding: 14px 16px; border-bottom: 1px solid #e2e8f0; font-size: 13.5px;">${deptLabel}</td>
                    <td style="padding: 14px 16px; border-bottom: 1px solid #e2e8f0; font-size: 13.5px;">${posLabel}</td>
                    <td style="padding: 14px 16px; border-bottom: 1px solid #e2e8f0; font-size: 13.5px;">
                        <span style="background: #eff6ff; color: #2563eb; font-weight: 600; padding: 4px 10px; border-radius: 6px; font-size: 12.5px; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fas fa-file-invoice-dollar" style="font-size: 11px;"></i> ${psName}
                        </span>
                    </td>
                    <td style="padding: 14px 16px; border-bottom: 1px solid #e2e8f0; font-size: 13.5px;">
                        <span style="background: #f0fdf4; color: #16a34a; font-weight: 600; padding: 4px 10px; border-radius: 6px; font-size: 12.5px; display: inline-flex; align-items: center; gap: 6px;">
                            <i class="fas fa-shield-alt" style="font-size: 11px;"></i> ${bpjsName}
                        </span>
                    </td>
                    <td style="padding: 14px 16px; border-bottom: 1px solid #e2e8f0; text-align: center;">
                        <div style="display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
                            <button onclick="editSchemaMapping(${m.id})" title="Edit" style="background: #f8fafc; border: 1px solid #cbd5e1; color: #3b82f6; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;" onmouseover="this.style.background='#3b82f6'; this.style.color='white'; this.style.borderColor='#3b82f6';" onmouseout="this.style.background='#f8fafc'; this.style.color='#3b82f6'; this.style.borderColor='#cbd5e1';">
                                <i class="fas fa-edit" style="font-size: 13px;"></i>
                            </button>
                            <button onclick="hapusSchemaMapping(${m.id})" title="Delete" style="background: #fef2f2; border: 1px solid #fecaca; color: #ef4444; width: 32px; height: 32px; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.2s;" onmouseover="this.style.background='#ef4444'; this.style.color='white'; this.style.borderColor='#ef4444';" onmouseout="this.style.background='#fef2f2'; this.style.color='#ef4444'; this.style.borderColor='#fecaca';">
                                <i class="fas fa-trash" style="font-size: 13px;"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
    } catch (err) {
        console.error('Error loading mapping table:', err);
    }
}

async function editSchemaMapping(id) {
    try {
        window.editSchemaMappingId = id;
        
        const res = await fetch(`${API_URL}/client-configs-mapping/${window.selectedClientId}`);
        const mappings = await res.json();
        const conf = mappings.find(m => m.id == id);
        if (!conf) return;
        
        await window.openModalPilihanScheme(true);
        document.getElementById('modalPilihanSchemeTitle').innerText = 'Edit Client Scheme';
        
        // Use TomSelect setValue for org structure dropdowns
        const divEl = document.getElementById('modalPilihanSchemeDivision');
        const deptEl = document.getElementById('modalPilihanSchemeDepartment');
        const posEl = document.getElementById('modalPilihanSchemePosition');
        const psEl = document.getElementById('modalPilihanSchemePayroll');
        const bpjsEl = document.getElementById('modalPilihanSchemeBpjs');
        const tsEl = document.getElementById('modalPilihanSchemeTax');

        if (conf.division_id && divEl) {
            divEl.value = conf.division_id;
            window.updateModalDeptDropdown(conf.division_id, conf.department_id);
            if (conf.department_id) {
                window.updateModalPosDropdown(conf.division_id, conf.department_id, conf.position_id);
            }
        }
        if (conf.payroll_scheme_id && psEl) {
            psEl.value = conf.payroll_scheme_id;
            if (typeof handleModalPilihanSchemePayrollChange === 'function') {
                handleModalPilihanSchemePayrollChange(conf.payroll_scheme_id);
            }
        }
        if (bpjsEl) {
            const hasOption = Array.from(bpjsEl.options).some(opt => opt.value == conf.bpjs_scheme_id);
            if (conf.bpjs_scheme_id && hasOption) {
                bpjsEl.value = conf.bpjs_scheme_id;
                window.handleModalPilihanSchemeBpjsChange(conf.bpjs_scheme_id);
            } else {
                bpjsEl.value = 'tambah_scheme';
                window.handleModalPilihanSchemeBpjsChange('tambah_scheme');
            }
        }
        if (conf.tax_scheme_id && tsEl) {
            tsEl.value = conf.tax_scheme_id;
            if (typeof handleModalPilihanSchemeTaxChange === 'function') {
                handleModalPilihanSchemeTaxChange(conf.tax_scheme_id);
            }
        }
        document.getElementById('modalPilihanSchemePayDate').value = conf.pay_date !== undefined && conf.pay_date !== null ? conf.pay_date : '';
        document.getElementById('modalPilihanSchemeCutoffStart').value = conf.cutoff_start !== undefined && conf.cutoff_start !== null ? conf.cutoff_start : (conf.cutoff_gaji_pokok_start !== undefined ? conf.cutoff_gaji_pokok_start : '');
    } catch(e) { console.error('Error in editSchemaMapping:', e); }
}

async function hapusSchemaMapping(id) {
    if (!await showConfirm('Are you sure you want to delete this scheme mapping?')) return;
    try {
        const res = await fetch(`${API_URL}/client-configs/${id}`, { method: 'DELETE' });
        if (res.ok) {
            showToast('Scheme mapping deleted successfully', 'success');
            loadSchemaMappingTable();
        } else {
            showToast('Failed to delete scheme mapping', 'error');
        }
    } catch (err) {
        console.error(err);
    }
}


async function loadPilihanScheme() {
    if (!window.selectedClientId) return;
    try {
        // Load payroll schemes for dropdown
        const psRes = await fetch(`${API_URL}/payroll-schemes`);
        const payrollSchemes = await psRes.json();
        const psSelect = document.getElementById('pilihanSchemePayroll');
        if (psSelect) {
            psSelect.innerHTML = '<option value="">-- Select Payroll Scheme --</option>' +
                payrollSchemes.map(s => `<option value="${s.id}">${s.nama} (${s.tipe || 'Umum'})</option>`).join('');
        }

        // Load tax schemes for dropdown
        const tsRes = await fetch(`${API_URL}/tax-schemes`);
        const taxSchemes = await tsRes.json();
        
        const bpjsOpts = taxSchemes.filter(s => s.tipe === 'bpjs');
        const taxOpts = taxSchemes.filter(s => s.tipe === 'pph21');

        const bpjsSelect = document.getElementById('pilihanSchemeBpjs');
        if (bpjsSelect) {
            bpjsSelect.innerHTML = '<option value="">-- Select BPJS Scheme --</option>' +
                bpjsOpts.map(s => `<option value="${s.id}">${s.nama}</option>`).join('');
        }

        const tsSelect = document.getElementById('pilihanSchemeTax');
        if (tsSelect) {
            tsSelect.innerHTML = '<option value="">-- Select Tax Scheme --</option>' +
                taxOpts.map(s => `<option value="${s.id}">${s.nama} (${s.metode || '-'})</option>`).join('');
        }

        // Load global compensation schemes for dropdown
        const compRes = await fetch(`${API_URL}/compensation-schemes`);
        const compensationSchemes = await compRes.json();
        const compSelect = document.getElementById('pilihanSchemeKompensasi');
        if (compSelect) {
            compSelect.innerHTML = '<option value="">-- Select Component Scheme --</option>' +
                compensationSchemes.map(s => `<option value="${s.id}">${s.nama}</option>`).join('');
        }

        // Load current client config to pre-select values
        const cfgRes = await fetch(`${API_URL}/client-configs-mapping/${window.selectedClientId}`);
        const configs = await cfgRes.json();
        const conf = configs.find(c => c.division_id === null && c.department_id === null && c.position_id === null); // Load General as default form
        window.editSchemaMappingId = conf ? conf.id : null;
        
        const summaryDiv = document.getElementById('pilihanKompensasiSummary');
        if (summaryDiv) {
            summaryDiv.innerHTML = `<p style="text-align: center; color: #94a3b8; font-size: 13px; margin: 0;">Select a component scheme above to view details.</p>`;
        }

        if (conf) {
            const tipeSelect = document.getElementById('pilihanSchemePayrollTipe');
            if (tipeSelect) {
                tipeSelect.value = conf.payroll_type || '';
                await handlePilihanSchemePayrollTipeChange();
                
                if (conf.payroll_type === 'UMP' || conf.payroll_type === 'UMK') {
                    const wilSelect = document.getElementById('pilihanSchemePayrollRegion');
                    if (wilSelect && conf.minimum_wage_id) {
                        wilSelect.value = conf.minimum_wage_id;
                    }
                } else if (conf.payroll_type === 'Nominal') {
                    const nomInput = document.getElementById('pilihanSchemePayrollNominal');
                    if (nomInput && conf.custom_nominal) {
                        nomInput.value = conf.custom_nominal;
                    }
                } else if (conf.payroll_type === 'Template') {
                    if (psSelect && conf.payroll_scheme_id) {
                        psSelect.value = conf.payroll_scheme_id;
                    }
                }
            }
            if (bpjsSelect && conf.bpjs_scheme_id) bpjsSelect.value = conf.bpjs_scheme_id;
            if (tsSelect && conf.tax_scheme_id) tsSelect.value = conf.tax_scheme_id;
            if (compSelect && conf.compensation_scheme_id) {
                compSelect.value = conf.compensation_scheme_id;
                renderPilihanKompensasiSummary(conf.compensation_scheme_id);
            }
        } else {
            const tipeSelect = document.getElementById('pilihanSchemePayrollTipe');
            if (tipeSelect) {
                tipeSelect.value = '';
                handlePilihanSchemePayrollTipeChange();
            }
        }
        try { await loadAbsenConfig(); } catch(e) { console.warn('loadAbsenConfig skipped:', e); }
        
        try {
            await loadSchemaMappingOrgDropdowns();
            initCascadingSchemeDropdowns();
            handlePilihanSchemeLevelChange();
        } catch(e) { console.warn('Org dropdowns init skipped:', e); }
    } catch (err) {
        console.error('Error loading pilihan scheme:', err);
    }
    // Always load the mapping table, even if form setup fails
    await loadSchemaMappingTable();
}

window.simpanPilihanScheme = async function() {
    if (!window.selectedClientId) {
        showToast('Please select a client first!', 'error');
        return;
    }
    
    const isModal = document.getElementById('modalPilihanScheme') && document.getElementById('modalPilihanScheme').style.display !== 'none';
    
    let payrollType, minimumWageId, customNominal, payrollSchemeId, bpjsSchemeId, taxSchemeId, compSchemeId;
    let level, divId, deptId, posId;
    
    if (isModal) {
        payrollType = 'Template';
        payrollSchemeId = document.getElementById('modalPilihanSchemePayroll').value;
        bpjsSchemeId = document.getElementById('modalPilihanSchemeBpjs').value;
        taxSchemeId = document.getElementById('modalPilihanSchemeTax').value;
        compSchemeId = '';
        divId = document.getElementById('modalPilihanSchemeDivision').value;
        deptId = document.getElementById('modalPilihanSchemeDepartment').value;
        posId = document.getElementById('modalPilihanSchemePosition').value;
        
        if (posId) level = 'position';
        else if (deptId) level = 'department';
        else if (divId) level = 'division';
        else level = 'general';
        
    } else {
        payrollType = document.getElementById('pilihanSchemePayrollTipe').value;
        minimumWageId = document.getElementById('pilihanSchemePayrollRegion').value;
        customNominal = document.getElementById('pilihanSchemePayrollNominal').value;
        payrollSchemeId = document.getElementById('pilihanSchemePayroll').value;
        bpjsSchemeId = document.getElementById('pilihanSchemeBpjs')?.value || null;
        taxSchemeId = document.getElementById('pilihanSchemeTax').value;
        compSchemeId = document.getElementById('pilihanSchemeKompensasi').value;
        
        level = document.getElementById('pilihanSchemeLevel').value;
        divId = document.getElementById('pilihanSchemeDivisionId')?.value;
        deptId = document.getElementById('pilihanSchemeDeptId')?.value;
        posId = document.getElementById('pilihanSchemePositionId')?.value;
    }

    if (!payrollType && !bpjsSchemeId && !taxSchemeId && !compSchemeId) {
        showToast('Select at least one scheme!', 'error');
        return;
    }

    if (isModal) {
        const payDate = parseInt(document.getElementById('modalPilihanSchemePayDate').value, 10);
        if (!payDate || payDate < 1 || payDate > 31) {
            showToast('Pay date must be between 1 and 31!', 'error');
            return;
        }

        const checkCutoff = (name, startVal) => {
            const start = parseInt(startVal, 10);
            if (isNaN(start) || start < 1 || start > 31) {
                showToast(`${name} cut-off start date must be between 1 and 31!`, 'error');
                return false;
            }
            
            // Cross-check: cutoff ends after or on payday
            const end = start - 1;
            if (start > 1 && end >= payDate) {
                showToast(`Conflict: ${name} cut-off ends on day ${end}, which is after or on payday (day ${payDate})!`, 'error');
                return false;
            }
            if (start === 1 && payDate < 28) {
                showToast(`Conflict: ${name} cut-off is full month, which ends after payday (day ${payDate})!`, 'error');
                return false;
            }
            return true;
        };

        if (!checkCutoff('Cutoff', document.getElementById('modalPilihanSchemeCutoffStart').value)) return;
    }

    try {
        if (isModal && bpjsSchemeId) {
            // Read active states and fallback to base rates or 0
            const base = window.modalClientBpjsBaseRates || {};
            const currentValues = {
                bpjs_kes_karyawan: document.getElementById('mClientBpjsKesActive').checked ? (base.bpjs_kes_karyawan || 0) : 0,
                bpjs_kes_company: document.getElementById('mClientBpjsKesActive').checked ? (base.bpjs_kes_company || 0) : 0,
                bpjs_kes_max_salary: document.getElementById('mClientBpjsKesActive').checked ? (base.bpjs_kes_max_salary || 0) : 0,
                bpjs_jht_karyawan: document.getElementById('mClientBpjsJhtActive').checked ? (base.bpjs_jht_karyawan || 0) : 0,
                bpjs_jht_company: document.getElementById('mClientBpjsJhtActive').checked ? (base.bpjs_jht_company || 0) : 0,
                bpjs_jp_karyawan: document.getElementById('mClientBpjsJpActive').checked ? (base.bpjs_jp_karyawan || 0) : 0,
                bpjs_jp_company: document.getElementById('mClientBpjsJpActive').checked ? (base.bpjs_jp_company || 0) : 0,
                bpjs_jp_max_salary: document.getElementById('mClientBpjsJpActive').checked ? (base.bpjs_jp_max_salary || 0) : 0,
                bpjs_jkk_company: document.getElementById('mClientBpjsJkkActive').checked ? (base.bpjs_jkk_company || 0) : 0,
                bpjs_jkm_company: document.getElementById('mClientBpjsJkmActive').checked ? (base.bpjs_jkm_company || 0) : 0
            };

            const original = window.modalClientBpjsOriginalValues;
            const isModified = !original ||
                original.bpjs_kes_karyawan !== currentValues.bpjs_kes_karyawan ||
                original.bpjs_kes_company !== currentValues.bpjs_kes_company ||
                original.bpjs_kes_max_salary !== currentValues.bpjs_kes_max_salary ||
                original.bpjs_jht_karyawan !== currentValues.bpjs_jht_karyawan ||
                original.bpjs_jht_company !== currentValues.bpjs_jht_company ||
                original.bpjs_jp_karyawan !== currentValues.bpjs_jp_karyawan ||
                original.bpjs_jp_company !== currentValues.bpjs_jp_company ||
                original.bpjs_jp_max_salary !== currentValues.bpjs_jp_max_salary ||
                original.bpjs_jkk_company !== currentValues.bpjs_jkk_company ||
                original.bpjs_jkm_company !== currentValues.bpjs_jkm_company;

            if (bpjsSchemeId === 'tambah_scheme' || isModified) {
                // Find current scheme
                const selectedScheme = (window.workspaceBpjsSchemes || []).find(s => s.id == bpjsSchemeId);
                const isAlreadyCustom = selectedScheme && selectedScheme.nama && selectedScheme.nama.startsWith('Custom BPJS -');
                
                if (isAlreadyCustom && bpjsSchemeId !== 'tambah_scheme') {
                    // Update in place
                    try {
                        const putRes = await fetch(`${API_URL}/tax-schemes/${bpjsSchemeId}`, {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                nama: selectedScheme.nama,
                                tipe: 'bpjs',
                                ...currentValues
                            })
                        });
                        if (!putRes.ok) {
                            console.error('Failed to update custom BPJS scheme');
                        }
                    } catch (err) {
                        console.error('Error updating custom BPJS scheme:', err);
                    }
                } else {
                    // Create a new custom BPJS scheme
                    const clientName = document.getElementById('modalPilihanSchemeNamaKlien').value || 'Client';
                    const divEl = document.getElementById('modalPilihanSchemeDivision');
                    const deptEl = document.getElementById('modalPilihanSchemeDepartment');
                    const posEl = document.getElementById('modalPilihanSchemePosition');
                    const divText = divEl && divEl.tomselect ? divEl.tomselect.getItem(divEl.value)?.textContent?.trim() : '';
                    const deptText = deptEl && deptEl.tomselect ? deptEl.tomselect.getItem(deptEl.value)?.textContent?.trim() : '';
                    const posText = posEl && posEl.tomselect ? posEl.tomselect.getItem(posEl.value)?.textContent?.trim() : '';

                    let orgInfo = 'General';
                    if (posText) orgInfo = `Position: ${posText}`;
                    else if (deptText) orgInfo = `Department: ${deptText}`;
                    else if (divText) orgInfo = `Division: ${divText}`;

                    const customName = `Custom BPJS - ${clientName} (${orgInfo})`;
                    
                    try {
                        const postRes = await fetch(`${API_URL}/tax-schemes`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                nama: customName,
                                tipe: 'bpjs',
                                ...currentValues
                            })
                        });
                        if (postRes.ok) {
                            const resData = await postRes.json();
                            if (resData && resData.id) {
                                bpjsSchemeId = resData.id;
                            }
                        } else {
                            console.error('Failed to create custom BPJS scheme');
                        }
                    } catch (err) {
                        console.error('Error creating custom BPJS scheme:', err);
                    }
                }
            }
        }

        // Load existing general config to preserve pay_date and cutoff
        const cfgRes = await fetch(`${API_URL}/client-configs-mapping/${window.selectedClientId}`);
        const configs = await cfgRes.json();
        const generalExisting = configs.find(c => c.division_id === null && c.department_id === null && c.position_id === null);

        const data = {
            client_id: window.selectedClientId,
            payroll_type: payrollType || null,
            minimum_wage_id: (payrollType === 'UMP' || payrollType === 'UMK') ? (minimumWageId || null) : null,
            custom_nominal: (payrollType === 'Nominal') ? (customNominal || null) : null,
            payroll_scheme_id: (payrollType === 'Template') ? (payrollSchemeId || null) : null,
            bpjs_scheme_id: bpjsSchemeId || (generalExisting ? generalExisting.bpjs_scheme_id : null),
            tax_scheme_id: taxSchemeId || (generalExisting ? generalExisting.tax_scheme_id : null),
            compensation_scheme_id: compSchemeId || (generalExisting ? generalExisting.compensation_scheme_id : null),
            pay_date: isModal ? (parseInt(document.getElementById('modalPilihanSchemePayDate').value, 10) || null) : (generalExisting ? generalExisting.pay_date : null),
            cutoff_start: isModal ? (parseInt(document.getElementById('modalPilihanSchemeCutoffStart').value, 10) || null) : (generalExisting ? generalExisting.cutoff_start : null),
            // Add org hierarchy based on selected level
            division_id: (level !== 'general' && divId) ? divId : null,
            department_id: ((level === 'department' || level === 'position') && deptId) ? deptId : null,
            position_id: (level === 'position' && posId) ? posId : null
        };

        if (isModal) {
            const cutoffVal = parseInt(document.getElementById('modalPilihanSchemeCutoffStart').value, 10) || null;
            data.cutoff_start = cutoffVal;
            data.cutoff_gaji_pokok_start = cutoffVal;
            data.cutoff_overtime_start = cutoffVal;
            data.cutoff_insentif_start = cutoffVal;
            data.is_rapel_gaji_pokok = 1;
            data.is_rapel_overtime = 1;
            data.is_rapel_insentif = 1;
        }
        
        if (window.editSchemaMappingId) {
            data.id = window.editSchemaMappingId;
        }

        const res = await fetch(`${API_URL}/client-configs`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });

        if (res.ok) {
            if (!isModal) {
                await simpanKonfigAbsen(false);
            }
            showToast('Scheme selection saved successfully!', 'success');
            window.editSchemaMappingId = null;
            if (isModal) {
                tutupModalPilihanScheme();
            } else {
                document.getElementById('pilihanSchemeLevel').value = 'general';
                handlePilihanSchemeLevelChange();
            }
            if (typeof loadPilihanSchemeKlienTable === 'function') {
                loadPilihanSchemeKlienTable(); // reload friend's UI
            }
            await loadSchemaMappingTable();
            // Also update the Setup Payroll tab data
            loadWorkspaceSetup();
        } else {
            const d = await res.json();
            let msg = 'Failed to save scheme selection!';
            if (d.messages && d.messages.error) msg = d.messages.error;
            else if (d.message) msg = d.message;
            showToast(msg, 'error');
        }
    } catch (err) {
        console.error(err);
        showToast('Failed to save scheme selection!', 'error');
    }
}

window.updateModalDeptDropdown = function(divId, selectedDeptId = '') {
    const deptSelect = document.getElementById('modalPilihanSchemeDepartment');
    if (!deptSelect) return;
    
    deptSelect.innerHTML = '<option value="">-- Select Department --</option>';
    
    if (divId && Array.isArray(wsClientOrgHierarchy)) {
        const division = wsClientOrgHierarchy.find(d => d.id == divId);
        if (division && Array.isArray(division.departments)) {
            division.departments.forEach(dept => {
                deptSelect.innerHTML += `<option value="${dept.id}">${dept.nama}</option>`;
            });
        }
    }
    
    deptSelect.value = selectedDeptId || '';
    window.updateModalPosDropdown(divId, deptSelect.value, '');
};

window.updateModalPosDropdown = function(divId, deptId, selectedPosId = '') {
    const posSelect = document.getElementById('modalPilihanSchemePosition');
    if (!posSelect) return;
    
    posSelect.innerHTML = '<option value="">-- Select Position --</option>';
    
    if (divId && deptId && Array.isArray(wsClientOrgHierarchy)) {
        const division = wsClientOrgHierarchy.find(d => d.id == divId);
        if (division && Array.isArray(division.departments)) {
            const dept = division.departments.find(dp => dp.id == deptId);
            if (dept && Array.isArray(dept.positions)) {
                dept.positions.forEach(pos => {
                    posSelect.innerHTML += `<option value="${pos.id}">${pos.nama}</option>`;
                });
            }
        }
    }
    
    posSelect.value = selectedPosId || '';
};

window.openModalPilihanScheme = async function(isEdit = false) {
    if (!window.selectedClientId) {
        showToast('Please select a client first!', 'error');
        return;
    }
    if (!isEdit) {
        window.editSchemaMappingId = null;
    }
    
    document.getElementById('modalPilihanSchemeTitle').innerText = isEdit ? 'Edit Client Scheme' : 'Add Scheme Client';
    document.getElementById('formPilihanScheme').reset();
    
    // Set default values for cutoff and payday to empty
    document.getElementById('modalPilihanSchemeCutoffStart').value = '';
    document.getElementById('modalPilihanSchemePayDate').value = '';

    // Set client name
    const titleEl = document.getElementById('clientWorkspaceTitle');
    if (titleEl) {
        const clientName = titleEl.innerText.replace('🏢 ', '');
        document.getElementById('modalPilihanSchemeNamaKlien').value = clientName;
    }
    
    // Show modal IMMEDIATELY
    document.getElementById('modalPilihanScheme').style.display = 'flex';
    
    // Get all select elements
    const divSelect = document.getElementById('modalPilihanSchemeDivision');
    const deptSelect = document.getElementById('modalPilihanSchemeDepartment');
    const posSelect = document.getElementById('modalPilihanSchemePosition');
    const psSelect = document.getElementById('modalPilihanSchemePayroll');
    const bpjsSelect = document.getElementById('modalPilihanSchemeBpjs');
    const tsSelect = document.getElementById('modalPilihanSchemeTax');

    // Immediately show default BPJS fields and values if we are not editing
    if (!isEdit) {
        if (bpjsSelect) {
            bpjsSelect.value = 'tambah_scheme';
        }
        window.handleModalPilihanSchemeBpjsChange('tambah_scheme');
    }

    try {
        // Fetch client-specific org hierarchy, schemes, and schedule templates in parallel
        const [orgRes, psRes, tsRes, schedRes] = await Promise.all([
            fetch(`${API_URL}/org?client_id=${window.selectedClientId}`),
            fetch(`${API_URL}/payroll-schemes`),
            fetch(`${API_URL}/tax-schemes`),
            fetch(`${API_URL}/schedule-templates`)
        ]);
        wsClientOrgHierarchy = await orgRes.json();
        const payrollSchemes = await psRes.json();
        const taxSchemes = await tsRes.json();
        const scheduleTemplates = await schedRes.json();

        // Populate payroll schemes
        if (psSelect) {
            psSelect.innerHTML = '<option value="">-- Select Payroll Scheme --</option>' +
                payrollSchemes.map(s => `<option value="${s.id}">${s.nama} (${s.tipe || 'Umum'})</option>`).join('');
        }
        
        // Filter tax schemes
        const bpjsOpts = taxSchemes.filter(s => s.tipe === 'bpjs');
        const taxOpts = taxSchemes.filter(s => s.tipe === 'pph21');

        window.workspaceBpjsSchemes = bpjsOpts;

        // Populate bpjs schemes
        if (bpjsSelect) {
            const currentVal = bpjsSelect.value;
            bpjsSelect.innerHTML = '<option value="tambah_scheme">Add Scheme</option>' +
                bpjsOpts.map(s => `<option value="${s.id}">${s.nama}</option>`).join('');
            bpjsSelect.value = currentVal;
        }
        // Populate tax schemes
        if (tsSelect) {
            tsSelect.innerHTML = '<option value="">-- Select Tax Scheme --</option>' +
                taxOpts.map(s => `<option value="${s.id}">${s.nama}</option>`).join('');
        }

        // Populate divisions from client-specific org hierarchy
        if (divSelect) {
            divSelect.innerHTML = '<option value="">-- Select Division --</option>' +
                wsClientOrgHierarchy.map(d => `<option value="${d.id}">${d.nama}</option>`).join('');
        }
        // Populate empty placeholders for departments and positions
        if (deptSelect) {
            deptSelect.innerHTML = '<option value="">-- Select Department --</option>';
        }
        if (posSelect) {
            posSelect.innerHTML = '<option value="">-- Select Position --</option>';
        }

        // Bind cascading change listeners for native dropdowns
        if (divSelect) {
            divSelect.onchange = function() {
                window.updateModalDeptDropdown(this.value);
            };
        }
        if (deptSelect) {
            deptSelect.onchange = function() {
                window.updateModalPosDropdown(divSelect ? divSelect.value : '', this.value);
            };
        }

        // Trigger change handler if not editing to ensure fields are fresh
        if (!isEdit && bpjsSelect && bpjsSelect.value) {
            window.handleModalPilihanSchemeBpjsChange(bpjsSelect.value);
        }
    } catch (e) {
        console.error('Error loading modal data:', e);
    }
};

window.handleModalPilihanSchemeBpjsChange = function(value) {
    const fieldsDiv = document.getElementById('modalClientBpjsOverrideFields');
    if (!fieldsDiv) return;

    if (!value) {
        fieldsDiv.style.display = 'none';
        window.modalClientBpjsOriginalValues = null;
        window.modalClientBpjsBaseRates = null;
        return;
    }

    const schemes = window.workspaceBpjsSchemes || [];
    let baseScheme = null;

    if (value !== 'tambah_scheme') {
        const selected = schemes.find(s => s.id == value);
        if (selected) {
            const isAlreadyCustom = selected.nama && selected.nama.startsWith('Custom BPJS -');
            if (isAlreadyCustom) {
                // Find a non-custom scheme to use as base rates reference
                baseScheme = schemes.find(s => !s.nama || !s.nama.startsWith('Custom BPJS -'));
            } else {
                baseScheme = selected;
            }
        }
    }

    // Resolve base rates (defaulting to standard BPJS rates if not found)
    const baseRates = {
        bpjs_kes_karyawan: baseScheme ? parseFloat(baseScheme.bpjs_kes_karyawan) : 1.00,
        bpjs_kes_company: baseScheme ? parseFloat(baseScheme.bpjs_kes_company) : 4.00,
        bpjs_kes_max_salary: baseScheme ? parseFloat(baseScheme.bpjs_kes_max_salary) : 12000000,
        bpjs_jht_karyawan: baseScheme ? parseFloat(baseScheme.bpjs_jht_karyawan) : 2.00,
        bpjs_jht_company: baseScheme ? parseFloat(baseScheme.bpjs_jht_company) : 3.70,
        bpjs_jp_karyawan: baseScheme ? parseFloat(baseScheme.bpjs_jp_karyawan) : 1.00,
        bpjs_jp_company: baseScheme ? parseFloat(baseScheme.bpjs_jp_company) : 2.00,
        bpjs_jp_max_salary: baseScheme ? parseFloat(baseScheme.bpjs_jp_max_salary) : 10024600,
        bpjs_jkk_company: baseScheme ? parseFloat(baseScheme.bpjs_jkk_company) : 0.24,
        bpjs_jkm_company: baseScheme ? parseFloat(baseScheme.bpjs_jkm_company) : 0.30
    };
    window.modalClientBpjsBaseRates = baseRates;

    // Format helper
    const formatRupiah = (val) => {
        if (!val) return 'Rp 0';
        return 'Rp ' + parseFloat(val).toLocaleString('id-ID');
    };

    // Update label descriptions
    document.getElementById('mClientBpjsKesDesc').innerText = `Rate: Karyawan ${parseFloat(baseRates.bpjs_kes_karyawan)}%, Company ${parseFloat(baseRates.bpjs_kes_company)}% (Max: ${formatRupiah(baseRates.bpjs_kes_max_salary)})`;
    document.getElementById('mClientBpjsJhtDesc').innerText = `Rate: Karyawan ${parseFloat(baseRates.bpjs_jht_karyawan)}%, Company ${parseFloat(baseRates.bpjs_jht_company)}%`;
    document.getElementById('mClientBpjsJpDesc').innerText = `Rate: Karyawan ${parseFloat(baseRates.bpjs_jp_karyawan)}%, Company ${parseFloat(baseRates.bpjs_jp_company)}% (Max: ${formatRupiah(baseRates.bpjs_jp_max_salary)})`;
    document.getElementById('mClientBpjsJkkDesc').innerText = `Rate: Company ${parseFloat(baseRates.bpjs_jkk_company)}%`;
    document.getElementById('mClientBpjsJkmDesc').innerText = `Rate: Company ${parseFloat(baseRates.bpjs_jkm_company)}%`;

    // Show override fields
    fieldsDiv.style.display = 'flex';

    // Populate active states
    const selectedScheme = schemes.find(s => s.id == value);
    if (selectedScheme) {
        document.getElementById('mClientBpjsKesActive').checked = parseFloat(selectedScheme.bpjs_kes_karyawan) > 0 || parseFloat(selectedScheme.bpjs_kes_company) > 0;
        document.getElementById('mClientBpjsJhtActive').checked = parseFloat(selectedScheme.bpjs_jht_karyawan) > 0 || parseFloat(selectedScheme.bpjs_jht_company) > 0;
        document.getElementById('mClientBpjsJpActive').checked = parseFloat(selectedScheme.bpjs_jp_karyawan) > 0 || parseFloat(selectedScheme.bpjs_jp_company) > 0;
        document.getElementById('mClientBpjsJkkActive').checked = parseFloat(selectedScheme.bpjs_jkk_company) > 0;
        document.getElementById('mClientBpjsJkmActive').checked = parseFloat(selectedScheme.bpjs_jkm_company) > 0;

        // Store original values matching database state
        window.modalClientBpjsOriginalValues = {
            bpjs_kes_karyawan: (parseFloat(selectedScheme.bpjs_kes_karyawan) > 0 || parseFloat(selectedScheme.bpjs_kes_company) > 0) ? parseFloat(selectedScheme.bpjs_kes_karyawan) : 0,
            bpjs_kes_company: (parseFloat(selectedScheme.bpjs_kes_karyawan) > 0 || parseFloat(selectedScheme.bpjs_kes_company) > 0) ? parseFloat(selectedScheme.bpjs_kes_company) : 0,
            bpjs_kes_max_salary: (parseFloat(selectedScheme.bpjs_kes_karyawan) > 0 || parseFloat(selectedScheme.bpjs_kes_company) > 0) ? parseFloat(selectedScheme.bpjs_kes_max_salary) : 0,
            bpjs_jht_karyawan: (parseFloat(selectedScheme.bpjs_jht_karyawan) > 0 || parseFloat(selectedScheme.bpjs_jht_company) > 0) ? parseFloat(selectedScheme.bpjs_jht_karyawan) : 0,
            bpjs_jht_company: (parseFloat(selectedScheme.bpjs_jht_karyawan) > 0 || parseFloat(selectedScheme.bpjs_jht_company) > 0) ? parseFloat(selectedScheme.bpjs_jht_company) : 0,
            bpjs_jp_karyawan: (parseFloat(selectedScheme.bpjs_jp_karyawan) > 0 || parseFloat(selectedScheme.bpjs_jp_company) > 0) ? parseFloat(selectedScheme.bpjs_jp_karyawan) : 0,
            bpjs_jp_company: (parseFloat(selectedScheme.bpjs_jp_karyawan) > 0 || parseFloat(selectedScheme.bpjs_jp_company) > 0) ? parseFloat(selectedScheme.bpjs_jp_company) : 0,
            bpjs_jp_max_salary: (parseFloat(selectedScheme.bpjs_jp_karyawan) > 0 || parseFloat(selectedScheme.bpjs_jp_company) > 0) ? parseFloat(selectedScheme.bpjs_jp_max_salary) : 0,
            bpjs_jkk_company: parseFloat(selectedScheme.bpjs_jkk_company) > 0 ? parseFloat(selectedScheme.bpjs_jkk_company) : 0,
            bpjs_jkm_company: parseFloat(selectedScheme.bpjs_jkm_company) > 0 ? parseFloat(selectedScheme.bpjs_jkm_company) : 0
        };
    } else {
        // If tambah_scheme or nothing found, all checked by default
        document.getElementById('mClientBpjsKesActive').checked = true;
        document.getElementById('mClientBpjsJhtActive').checked = true;
        document.getElementById('mClientBpjsJpActive').checked = true;
        document.getElementById('mClientBpjsJkkActive').checked = true;
        document.getElementById('mClientBpjsJkmActive').checked = true;

        window.modalClientBpjsOriginalValues = {
            is_tambah_scheme: true,
            bpjs_kes_karyawan: baseRates.bpjs_kes_karyawan,
            bpjs_kes_company: baseRates.bpjs_kes_company,
            bpjs_kes_max_salary: baseRates.bpjs_kes_max_salary,
            bpjs_jht_karyawan: baseRates.bpjs_jht_karyawan,
            bpjs_jht_company: baseRates.bpjs_jht_company,
            bpjs_jp_karyawan: baseRates.bpjs_jp_karyawan,
            bpjs_jp_company: baseRates.bpjs_jp_company,
            bpjs_jp_max_salary: baseRates.bpjs_jp_max_salary,
            bpjs_jkk_company: baseRates.bpjs_jkk_company,
            bpjs_jkm_company: baseRates.bpjs_jkm_company
        };
    }
};

window.tutupModalPilihanScheme = function() {
    document.getElementById('modalPilihanScheme').style.display = 'none';
    window.editSchemaMappingId = null;
    
    const overrideFields = document.getElementById('modalClientBpjsOverrideFields');
    if (overrideFields) overrideFields.style.display = 'none';
    window.modalClientBpjsOriginalValues = null;
    window.modalClientBpjsBaseRates = null;
};

function goToMasterKompensasi() {
    switchView('masterKompensasi');
    renderMasterKompensasi();
}

// ===== 9. KONFIGURASI ABSEN =====
async function loadAbsenConfig() {
    if (!window.selectedClientId) return;
    try {
        const res = await fetch(`${API_URL}/client-absence-config/${window.selectedClientId}`);
        const data = await res.json();
        const elProrate = document.getElementById('cfgProrate');
        const elAbsen = document.getElementById('cfgAbsenTidakPotong');
        const nominalInput = document.getElementById('cfgNominalDeduction');
        if (data && data.id) {
            if (elProrate) elProrate.checked = data.prorate == 1;
            if (elAbsen) elAbsen.checked = data.absen_tidak_potong == 1;
            if (nominalInput) {
                const val = data.nominal_deduction ? parseFloat(data.nominal_deduction) : 0;
                nominalInput.value = val > 0 ? new Intl.NumberFormat('id-ID').format(val) : '';
            }
        } else {
            if (elProrate) elProrate.checked = false;
            if (elAbsen) elAbsen.checked = false;
            if (nominalInput) nominalInput.value = '';
        }
    } catch (err) { console.error(err); }
}

async function simpanKonfigAbsen(showToastOnSuccess = true) {
    if (!window.selectedClientId) return;
    const nominalRaw = document.getElementById('cfgNominalDeduction').value;
    const nominalClean = parseFloat(nominalRaw.replace(/\./g, '').replace(/,/g, '.')) || 0;
    
    const data = {
        client_id: window.selectedClientId,
        prorate: document.getElementById('cfgProrate').checked ? 1 : 0,
        absen_tidak_potong: document.getElementById('cfgAbsenTidakPotong').checked ? 1 : 0,
        nominal_deduction: nominalClean
    };
    try {
        const res = await fetch(`${API_URL}/client-absence-config`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        if (res.ok && showToastOnSuccess) { showToast('Attendance configuration saved successfully!', 'success'); }
    } catch (err) { console.error(err); if (showToastOnSuccess) showToast('Failed to save configuration', 'error'); }
}
