// ===== PAYROLL SCHEME MODULE =====
// Extracted from app.js for modular monolith architecture

// ===== 3. PAYROLL SCHEMES =====
let payrollSchemes = [];

async function renderPayrollSchemes() {
    try {
        const container = document.getElementById('payrollSchemesContainer');
        if (container) {
            container.innerHTML = `<div style="text-align: center; padding: 40px; color: #94a3b8; width: 100%;"><i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i>Loading data...</div>`;
        }

        if (!window.compensationSchemes) {
            const compRes = await fetch(`${API_URL}/compensation-schemes`);
            window.compensationSchemes = await compRes.json();
        }
        const res = await fetch(`${API_URL}/payroll-schemes`);
        payrollSchemes = await res.json();
        if(!container) return;
        container.innerHTML = payrollSchemes.map(scheme => {
            const basic = scheme.components ? scheme.components.find(c => c.jenis_component === 'basic_salary' || c.nama.includes('Base Salary') || c.nama.includes('Basic Salary')) : null;
            let basicDetails = 'Not configured';
            if (basic) {
                if (basic.sumber_value === 'ump') {
                    basicDetails = `UMP (${parseFloat(basic.value)}%)`;
                } else if (basic.sumber_value === 'umk') {
                    basicDetails = `UMK (${parseFloat(basic.value)}%)`;
                } else if (basic.sumber_value === 'kompensasi') {
                    basicDetails = `Take from Allowance (${parseFloat(basic.value)}%)`;
                } else {
                    basicDetails = formatRupiah(basic.value);
                }
            }

            const linkedComps = (scheme.components || []).filter(c => c.jenis_component !== 'basic_salary');
            const compName = linkedComps.length > 0 
                ? linkedComps.map(c => c.nama).join(', ') 
                : 'No allowance';

            const absenceDetails = `Prorate: ${scheme.prorate == 1 ? 'Yes' : 'No'} | Absen No Salary Cut: ${scheme.absen_tidak_potong == 1 ? 'Yes' : 'No'} | Cut: ${formatRupiah(scheme.nominal_deduction || 0)}/day`;

            return `
            <div class="scheme-card">
                <div class="scheme-card-header">
                    <div class="scheme-card-info">
                        <h4><i class="fas fa-file-invoice-dollar"></i> ${scheme.nama}</h4>
                            <div class="scheme-card-desc" style="margin-bottom: 8px;">${scheme.deskripsi || 'No description'}</div>
                            <div style="font-size: 12px; color: #475569; display: grid; gap: 4px; border-top: 1px solid #f1f5f9; padding-top: 8px;">
                                <div><strong>Basic Salary:</strong> ${basicDetails}</div>
                                <div><strong>Allowance Scheme:</strong> ${compName}</div>
                                <div><strong>Absence Scheme:</strong> ${absenceDetails}</div>
                            </div>
                    </div>
                    <div class="scheme-card-actions">
                        <button class="btn-icon btn-edit" onclick="window.bukaModalScheme('edit', ${scheme.id})"><i class="fas fa-edit"></i></button>
                        <button class="btn-icon btn-delete" onclick="window.hapusScheme(${scheme.id})"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
            </div>
            `;
        }).join('');
    } catch (err) { console.error(err); }
}

    // Global Closing Handlers for Payroll Scheme
    function tutupModalScheme() {
        const modal = document.getElementById('modalScheme');
        const overlay = document.getElementById('overlay');
        if (modal) modal.style.display = 'none';
        if (overlay) overlay.style.display = 'none';
        if (typeof tutupAllModal === 'function') {
            tutupAllModal();
        }
    }
    window.tutupModalScheme = tutupModalScheme;

    // Form Scheme Payroll submit handler
    if (document.getElementById('formScheme')) {
        document.getElementById('formScheme').addEventListener('submit', async (e) => {
            e.preventDefault();
            const id = document.getElementById('schemeId').value;
            
            const namaEl = document.getElementById('schemeNama');
            if (!namaEl || !namaEl.value.trim()) {
                if (typeof showToast === 'function') {
                    showToast('Please enter the Scheme Name', 'warning');
                } else {
                    alert('Please enter the Scheme Name');
                }
                namaEl?.focus();
                document.querySelector('#modalScheme .modal-body')?.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }

            const valueEl = document.getElementById('schemeValue');
            if (!valueEl || valueEl.value.trim() === '') {
                if (typeof showToast === 'function') {
                    showToast('Please enter the Basic Salary', 'warning');
                } else {
                    alert('Please enter the Basic Salary');
                }
                valueEl?.focus();
                document.querySelector('#modalScheme .modal-body')?.scrollTo({ top: 0, behavior: 'smooth' });
                return;
            }

            // Gather selected compensation components
            const selectedComponents = [];
            document.querySelectorAll('.scheme-comp-checkbox:checked').forEach(cb => {
                try {
                    const comp = JSON.parse(decodeURIComponent(cb.getAttribute('data-comp')));
                    selectedComponents.push({
                        nama: comp.nama,
                        tipe: comp.tipe,
                        value: parseFloat(comp.value) || 0,
                        is_persentase: comp.is_persentase,
                        jenis_component: 'kompensasi',
                        sumber_value: comp.sumber_value || 'nominal',
                        period: comp.period || 'month',
                        sifat_kompensasi: comp.sifat_kompensasi || 'tetap',
                        is_bpjs: (comp.is_bpjs === true || comp.is_bpjs == 1) ? 1 : 0,
                        is_pph21: (comp.is_pph21 === false || comp.is_pph21 == 0) ? 0 : 1
                    });
                } catch (err) {
                    console.error('Error parsing checkbox data-comp:', err);
                }
            });

            const overtimeType = document.querySelector('input[name="schemeOvertimeType"]:checked')?.value || 'standard';
            const lumpsumSubtype = document.querySelector('input[name="schemeLumpsumSubtype"]:checked')?.value || 'per_jam';
            const lumpsumNominal = parseFormattedNumber(document.getElementById('schemeLumpsumNominal').value) || 0;

            const data = {
                nama: namaEl.value.trim(),
                deskripsi: document.getElementById('schemeDeskripsi').value,
                tipe: document.getElementById('schemeTipe').value,
                compensation_scheme_id: null,
                components: selectedComponents,
                prorate: (document.querySelector('input[name="schemeAbsenRule"]:checked')?.value === 'prorate') ? 1 : 0,
                absen_tidak_potong: (document.querySelector('input[name="schemeAbsenRule"]:checked')?.value === 'tidak_potong') ? 1 : 0,
                nominal_deduction: parseFormattedNumber(document.getElementById('schemeNominalDeduction').value) || 0,
                sumber_value: document.getElementById('schemeSumber').value,
                period: document.getElementById('schemePeriod').value,
                value: parseFormattedNumber(valueEl.value) || 0,
                is_persentase: parseInt(document.getElementById('schemeIsPersentase').value) || 0,
                grace_period_late: parseInt(document.getElementById('schemeGraceLate').value) || 0,
                grace_period_early: parseInt(document.getElementById('schemeGraceEarly').value) || 0,
                min_overtime: parseInt(document.getElementById('schemeMinOvertime').value) || 30,
                max_early_arrival_minutes: parseInt(document.getElementById('schemeMaxEarlyArrivalMinutes').value) || 180,
                denda_terlambat_per_jam: parseFormattedNumber(document.getElementById('schemeDendaTerlambatPerJam').value) || 0,
                denda_alfa_per_hari: parseFormattedNumber(document.getElementById('schemeDendaAlfaPerDay').value) || 0,
                overtime_type: overtimeType,
                lumpsum_subtype: overtimeType === 'lumpsum' ? lumpsumSubtype : null,
                lumpsum_nominal: overtimeType === 'lumpsum' ? lumpsumNominal : 0,
            };
            const url = id ? `${API_URL}/payroll-schemes/${id}` : `${API_URL}/payroll-schemes`;
            const res = await fetch(url, {
                method: id ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            if (res.ok) {
                tutupModalScheme();
                renderPayrollSchemes();
                showToast(id ? 'Payroll scheme updated successfully!' : 'Payroll scheme added successfully!', 'success');
            } else {
                showToast('Failed to save payroll scheme!', 'error');
            }
        });
    }

async function bukaModalScheme(mode, id = null) {
    const modal = document.getElementById('modalScheme');
    const overlay = document.getElementById('overlay');
    if (modal) {
        modal.style.display = 'block';
        modal.style.zIndex = '2050';
    }
    if (overlay) {
        overlay.style.display = 'block';
        overlay.style.zIndex = '2040';
    }
    document.querySelector('#modalScheme .modal-body')?.scrollTo(0, 0);

    try {
        const compRes = await fetch(`${API_URL}/compensation-schemes`);
        window.compensationSchemes = await compRes.json();
    } catch (err) {
        console.error('Error fetching compensation schemes in bukaModalScheme:', err);
    }

    const tetapBody = document.getElementById('tabelKompensasiTetapBody');
    const tidakTetapBody = document.getElementById('tabelKompensasiTidakTetapBody');
    if (tetapBody) tetapBody.innerHTML = `<tr><td colspan="2" style="padding: 12px; text-align: center; color: #94a3b8; font-size: 13px;">No allowance scheme selected yet</td></tr>`;
    if (tidakTetapBody) tidakTetapBody.innerHTML = `<tr><td colspan="2" style="padding: 12px; text-align: center; color: #94a3b8; font-size: 13px;">No allowance scheme selected yet</td></tr>`;

    if(mode === 'edit' && id) {
        const s = payrollSchemes.find(x => x.id == id);
        if(s) {
            document.getElementById('modalSchemeTitle').innerText = 'Edit Payroll Scheme';
            document.getElementById('schemeId').value = s.id;
            document.getElementById('schemeNama').value = s.nama;
            document.getElementById('schemeDeskripsi').value = s.deskripsi;
            document.getElementById('schemeTipe').value = s.tipe;
            
            // Set absence radio buttons and nominal
            const radioProrate = document.querySelector('input[name="schemeAbsenRule"][value="prorate"]');
            const radioTidakPotong = document.querySelector('input[name="schemeAbsenRule"][value="tidak_potong"]');
            const radioPotongNominal = document.querySelector('input[name="schemeAbsenRule"][value="potong_nominal"]');

            if (radioProrate) radioProrate.checked = false;
            if (radioTidakPotong) radioTidakPotong.checked = false;
            if (radioPotongNominal) radioPotongNominal.checked = false;

            if (s.prorate == 1) {
                if (radioProrate) radioProrate.checked = true;
            } else if (s.absen_tidak_potong == 1) {
                if (radioTidakPotong) radioTidakPotong.checked = true;
            } else if (s.nominal_deduction > 0) {
                if (radioPotongNominal) radioPotongNominal.checked = true;
            }
            document.getElementById('schemeNominalDeduction').value = s.nominal_deduction ? formatRupiah(Math.round(s.nominal_deduction)) : '';
            handleSchemeAbsenRuleChange();
            
            document.getElementById('schemeGraceLate').value = s.grace_period_late || 0;
            document.getElementById('schemeGraceEarly').value = s.grace_period_early || 0;
            document.getElementById('schemeMinOvertime').value = s.min_overtime || 30;
            document.getElementById('schemeMaxEarlyArrivalMinutes').value = s.max_early_arrival_minutes !== undefined && s.max_early_arrival_minutes !== null ? s.max_early_arrival_minutes : 180;
            document.getElementById('schemeDendaTerlambatPerJam').value = formatRupiah(s.denda_terlambat_per_jam || 0);
            document.getElementById('schemeDendaAlfaPerDay').value = formatRupiah(s.denda_alfa_per_hari || 0);

            // Load overtime configuration
            const savedOvertimeType = s.overtime_type || 'standard';
            setOvertimeType(savedOvertimeType);
            if (savedOvertimeType === 'lumpsum') {
                const savedSubtype = s.lumpsum_subtype || 'per_jam';
                setLumpsumSubtype(savedSubtype);
                const nomEl = document.getElementById('schemeLumpsumNominal');
                if (nomEl) {
                    nomEl.value = s.lumpsum_nominal ? formatRupiah(Math.round(s.lumpsum_nominal)) : '';
                }
            }

            // Find basic salary component
            const basic = s.components ? s.components.find(c => c.jenis_component === 'basic_salary' || c.nama.includes('Base Salary') || c.nama.includes('Basic Salary')) : null;
            if (basic) {
                document.getElementById('schemeSumber').value = basic.sumber_value || 'nominal';
                document.getElementById('schemePeriod').value = basic.period || 'month';
                const elValue = document.getElementById('schemeValue');
                if (basic.sumber_value === 'ump' || basic.sumber_value === 'umk') {
                    elValue.value = parseFloat(basic.value) || 0;
                } else {
                    elValue.value = Math.round(parseFloat(basic.value) || 0);
                    formatRupiahInput(elValue);
                }
                document.getElementById('schemeIsPersentase').value = basic.is_persentase || '0';
            } else {
                document.getElementById('schemeSumber').value = 'nominal';
                document.getElementById('schemePeriod').value = 'month';
                const elValue = document.getElementById('schemeValue');
                elValue.value = 0;
                formatRupiahInput(elValue);
                document.getElementById('schemeIsPersentase').value = '0';
            }
            handlePayrollSchemeSumberValueChange();

            const savedComponents = s.components || [];

            const fixedSaved = savedComponents.filter(c => c.jenis_component !== 'basic_salary' && c.sifat_kompensasi === 'tetap');
            const variableSaved = savedComponents.filter(c => c.jenis_component !== 'basic_salary' && c.sifat_kompensasi === 'tidak_tetap');

            if (fixedSaved.length > 0 && tetapBody) {
                tetapBody.innerHTML = fixedSaved.map(c => {
                    let valStr = '';
                    if (c.sumber_value === 'ump') {
                        valStr = `${parseFloat(c.value)}% UMP`;
                    } else if (c.sumber_value === 'umk') {
                        valStr = `${parseFloat(c.value)}% UMK`;
                    } else if (c.sumber_value === 'ump_umk') {
                        valStr = `${parseFloat(c.value)}% UMP/UMK`;
                    } else {
                        valStr = c.is_persentase == 1 ? `${parseFloat(c.value)}%` : formatRupiahVal(c.value);
                    }
                    const dataAttr = encodeURIComponent(JSON.stringify(c));
                    return `
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 10px 12px; font-size: 13px; color: #334155; text-align: left; display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" class="scheme-comp-checkbox" checked data-comp="${dataAttr}" style="cursor: pointer; width: 16px; height: 16px;">
                                <div><span style="font-weight: 500;">${c.nama}</span></div>
                            </td>
                            <td style="padding: 10px 12px; font-size: 13px; color: #1e293b; font-weight: 600; text-align: right; vertical-align: middle;">${valStr}</td>
                        </tr>
                    `;
                }).join('');
            }

            if (variableSaved.length > 0 && tidakTetapBody) {
                tidakTetapBody.innerHTML = variableSaved.map(c => {
                    let valStr = '';
                    if (c.sumber_value === 'ump') {
                        valStr = `${parseFloat(c.value)}% UMP`;
                    } else if (c.sumber_value === 'umk') {
                        valStr = `${parseFloat(c.value)}% UMK`;
                    } else if (c.sumber_value === 'ump_umk') {
                        valStr = `${parseFloat(c.value)}% UMP/UMK`;
                    } else {
                        valStr = c.is_persentase == 1 ? `${parseFloat(c.value)}%` : formatRupiahVal(c.value);
                    }
                    const dataAttr = encodeURIComponent(JSON.stringify(c));
                    return `
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 10px 12px; font-size: 13px; color: #334155; text-align: left; display: flex; align-items: center; gap: 8px;">
                                <input type="checkbox" class="scheme-comp-checkbox" checked data-comp="${dataAttr}" style="cursor: pointer; width: 16px; height: 16px;">
                                <div><span style="font-weight: 500;">${c.nama}</span></div>
                            </td>
                            <td style="padding: 10px 12px; font-size: 13px; color: #1e293b; font-weight: 600; text-align: right; vertical-align: middle;">${valStr}</td>
                        </tr>
                    `;
                }).join('');
            }
        }
    } else {
        document.getElementById('modalSchemeTitle').innerText = 'Add Payroll Scheme';
        document.getElementById('formScheme').reset();
        document.getElementById('schemeId').value = '';
        const radioProrate = document.querySelector('input[name="schemeAbsenRule"][value="prorate"]');
        const radioTidakPotong = document.querySelector('input[name="schemeAbsenRule"][value="tidak_potong"]');
        const radioPotongNominal = document.querySelector('input[name="schemeAbsenRule"][value="potong_nominal"]');
        if (radioProrate) radioProrate.checked = false;
        if (radioTidakPotong) radioTidakPotong.checked = false;
        if (radioPotongNominal) radioPotongNominal.checked = false;
        document.getElementById('schemeNominalDeduction').value = '';
        handleSchemeAbsenRuleChange();
        
        document.getElementById('schemeGraceLate').value = 0;
        document.getElementById('schemeGraceEarly').value = 0;
        document.getElementById('schemeMinOvertime').value = 30;
        document.getElementById('schemeMaxEarlyArrivalMinutes').value = 180;
        document.getElementById('schemeDendaTerlambatPerJam').value = '0';
        document.getElementById('schemeDendaAlfaPerDay').value = '0';
        document.getElementById('schemeSumber').value = 'nominal';
        document.getElementById('schemePeriod').value = 'month';
        document.getElementById('schemeValue').value = '';
        document.getElementById('schemeIsPersentase').value = '0';
        handlePayrollSchemeSumberValueChange();

        // Reset overtime configuration
        setOvertimeType('standard');
        setLumpsumSubtype('per_jam');
        document.getElementById('schemeLumpsumNominal').value = '';
    }
}

const rupiahFormatter = new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0
});

function formatRupiahVal(val) {
    return rupiahFormatter.format(val);
}

async function bukaModalPilihScheme(sifat) {
    window.activePilihSchemeSifat = sifat;
    
    const titleEl = document.getElementById('modalPilihSchemeTitle');
    const bodyEl = document.getElementById('modalPilihSchemeBody');
    if (!titleEl || !bodyEl) return;

    titleEl.innerText = sifat === 'tetap' ? 'Select Fixed Allowance Scheme' : 'Select Variable Allowance Scheme';

    if (!window.compensationSchemes || window.compensationSchemes.length === 0) {
        try {
            const compRes = await fetch(`${API_URL}/compensation-schemes`);
            window.compensationSchemes = await compRes.json();
        } catch (err) {
            console.error('Error fetching compensation schemes in bukaModalPilihScheme:', err);
        }
    }

    const filteredSchemes = (window.compensationSchemes || []).filter(s => 
        (s.components || []).some(c => c.sifat_kompensasi === sifat)
    );

    if (filteredSchemes.length === 0) {
        bodyEl.innerHTML = `<tr><td colspan="3" style="padding: 15px; text-align: center; color: #64748b;">No allowance schemes available</td></tr>`;
    } else {
        const mainCompNames = Array.from(document.querySelectorAll(`#tabelKompensasi${sifat === 'tetap' ? 'Tetap' : 'TidakTetap'}Body .scheme-comp-checkbox`))
            .map(cb => {
                try {
                    return JSON.parse(decodeURIComponent(cb.getAttribute('data-comp'))).nama;
                } catch (e) {
                    return null;
                }
            }).filter(Boolean);

        bodyEl.innerHTML = filteredSchemes.map(s => {
            const compsList = (s.components || []).filter(c => c.sifat_kompensasi === sifat).map(c => {
                let valStr = '';
                if (c.sumber_value === 'ump') {
                    valStr = `${parseFloat(c.value)}% UMP`;
                } else if (c.sumber_value === 'umk') {
                    valStr = `${parseFloat(c.value)}% UMK`;
                } else if (c.sumber_value === 'ump_umk') {
                    valStr = `${parseFloat(c.value)}% UMP/UMK`;
                } else {
                    valStr = c.is_persentase == 1 ? `${parseFloat(c.value)}%` : formatRupiahVal(c.value);
                }
                return `<span style="display: inline-block; background: #e2e8f0; color: #334155; padding: 2px 6px; border-radius: 4px; font-size: 11px; margin-right: 4px; margin-bottom: 4px;">${c.nama}: ${valStr}</span>`;
            }).join('');

            const isChecked = (s.components || []).some(c => c.sifat_kompensasi === sifat && mainCompNames.includes(c.nama));
            const checkedAttr = isChecked ? 'checked' : '';

            return `
                <tr style="border-bottom: 1px solid #e2e8f0;">
                    <td style="padding: 10px 8px; text-align: center; vertical-align: middle;">
                        <input type="checkbox" class="modal-choice-scheme-checkbox" value="${s.id}" ${checkedAttr} style="cursor: pointer; width: 16px; height: 16px; accent-color: var(--primary-color);">
                    </td>
                    <td style="padding: 10px 8px; font-weight: 600; color: #1e293b; vertical-align: middle;">${s.nama}</td>
                    <td style="padding: 10px 8px; vertical-align: middle; line-height: 1.5;">${compsList}</td>
                </tr>
            `;
        }).join('');
    }

    const overlay = document.getElementById('overlayPilihScheme');
    const modal = document.getElementById('modalPilihScheme');
    if (overlay) {
        overlay.style.display = 'block';
        overlay.style.zIndex = '2090';
    }
    if (modal) {
        modal.style.display = 'block';
        modal.style.zIndex = '2100';
    }
}

function tutupModalPilihScheme() {
    document.getElementById('overlayPilihScheme').style.display = 'none';
    document.getElementById('modalPilihScheme').style.display = 'none';
}

function terapkanPilihanScheme() {
    const sifat = window.activePilihSchemeSifat;
    const body = sifat === 'tetap' ? document.getElementById('tabelKompensasiTetapBody') : document.getElementById('tabelKompensasiTidakTetapBody');
    if (!body) return;

    const checkedCheckboxes = document.querySelectorAll('.modal-choice-scheme-checkbox:checked');
    const checkedSchemeIds = Array.from(checkedCheckboxes).map(cb => cb.value);

    const existingComps = [];
    body.querySelectorAll('.scheme-comp-checkbox').forEach(cb => {
        try {
            const data = JSON.parse(decodeURIComponent(cb.getAttribute('data-comp')));
            existingComps.push({
                data: data,
                checked: cb.checked
            });
        } catch (e) {}
    });

    const componentsToRender = [];
    const addedNames = new Set();

    checkedSchemeIds.forEach(sid => {
        const cs = (window.compensationSchemes || []).find(s => s.id == sid);
        if (cs && cs.components) {
            cs.components.forEach(c => {
                if (c.sifat_kompensasi === sifat && c.jenis_component !== 'basic_salary') {
                    if (!addedNames.has(c.nama)) {
                        addedNames.add(c.nama);
                        componentsToRender.push({
                            data: c,
                            checked: true
                        });
                    }
                }
            });
        }
    });

    existingComps.forEach(ec => {
        if (!addedNames.has(ec.data.nama)) {
            addedNames.add(ec.data.nama);
            componentsToRender.push(ec);
        } else {
            const idx = componentsToRender.findIndex(x => x.data.nama === ec.data.nama);
            if (idx !== -1) {
                componentsToRender[idx].checked = ec.checked;
            }
        }
    });

    if (componentsToRender.length === 0) {
        body.innerHTML = `<tr><td colspan="2" style="padding: 12px; text-align: center; color: #94a3b8; font-size: 13px;">No allowance scheme selected yet</td></tr>`;
        tutupModalPilihScheme();
        return;
    }

    body.innerHTML = componentsToRender.map(item => {
        const c = item.data;
        let valStr = '';
        if (c.sumber_value === 'ump') {
            valStr = `${parseFloat(c.value)}% UMP`;
        } else if (c.sumber_value === 'umk') {
            valStr = `${parseFloat(c.value)}% UMK`;
        } else if (c.sumber_value === 'ump_umk') {
            valStr = `${parseFloat(c.value)}% UMP/UMK`;
        } else {
            valStr = c.is_persentase == 1 ? `${parseFloat(c.value)}%` : formatRupiahVal(c.value);
        }

        const checkedAttr = item.checked ? 'checked' : '';
        const dataAttr = encodeURIComponent(JSON.stringify(c));

        return `
            <tr style="border-bottom: 1px solid #f1f5f9;">
                <td style="padding: 10px 12px; font-size: 13px; color: #334155; text-align: left; display: flex; align-items: center; gap: 8px;">
                    <input type="checkbox" class="scheme-comp-checkbox" ${checkedAttr} data-comp="${dataAttr}" style="cursor: pointer; width: 16px; height: 16px;">
                    <div>
                        <span style="font-weight: 500;">${c.nama}</span>
                    </div>
                </td>
                <td style="padding: 10px 12px; font-size: 13px; color: #1e293b; font-weight: 600; text-align: right; vertical-align: middle;">${valStr}</td>
            </tr>
        `;
    }).join('');

    tutupModalPilihScheme();
}

window.bukaModalPilihScheme = bukaModalPilihScheme;
window.tutupModalPilihScheme = tutupModalPilihScheme;
window.terapkanPilihanScheme = terapkanPilihanScheme;

function handleSchemeAbsenRuleChange() {
    const isNominal = document.querySelector('input[name="schemeAbsenRule"][value="potong_nominal"]')?.checked || false;
    const inputNominal = document.getElementById('schemeNominalDeduction');
    if (inputNominal) {
        inputNominal.disabled = !isNominal;
        if (!isNominal) {
            inputNominal.value = '';
        }
    }
}
window.handleSchemeAbsenRuleChange = handleSchemeAbsenRuleChange;

window.bukaModalScheme = bukaModalScheme;

async function hapusScheme(id) {
    if (!await showConfirm('Are you sure you want to delete this payroll scheme?')) return;
    try {
        const res = await fetch(`${API_URL}/payroll-schemes/${id}`, {
            method: 'DELETE'
        });
        if (res.ok) {
            renderPayrollSchemes();
            showToast('Payroll scheme deleted successfully!', 'success');
        } else {
            showToast('Failed to delete payroll scheme!', 'error');
        }
    } catch (err) {
        console.error('Error deleting payroll scheme:', err);
        showToast('Failed to delete payroll scheme!', 'error');
    }
}
window.hapusScheme = hapusScheme;
window.renderPayrollSchemes = renderPayrollSchemes;

// ===== OVERTIME CONFIGURATION FUNCTIONS =====
function setOvertimeType(type) {
    const radioStandard = document.querySelector('input[name="schemeOvertimeType"][value="standard"]');
    const radioLumpsum = document.querySelector('input[name="schemeOvertimeType"][value="lumpsum"]');
    const standardPanel = document.getElementById('overtimeStandardPanel');
    const lumpsumPanel = document.getElementById('overtimeLumpsumPanel');
    const labelStandard = document.getElementById('labelOvertimeStandard');
    const labelLumpsum = document.getElementById('labelOvertimeLumpsum');

    if (type === 'standard') {
        if (radioStandard) radioStandard.checked = true;
        if (radioLumpsum) radioLumpsum.checked = false;
        if (standardPanel) standardPanel.style.display = 'block';
        if (lumpsumPanel) lumpsumPanel.style.display = 'none';
        if (labelStandard) {
            labelStandard.style.borderColor = '#3b82f6';
            labelStandard.style.background = '#eff6ff';
            labelStandard.style.color = '#1e40af';
        }
        if (labelLumpsum) {
            labelLumpsum.style.borderColor = '#e2e8f0';
            labelLumpsum.style.background = 'white';
            labelLumpsum.style.color = '#475569';
        }
    } else {
        if (radioStandard) radioStandard.checked = false;
        if (radioLumpsum) radioLumpsum.checked = true;
        if (standardPanel) standardPanel.style.display = 'none';
        if (lumpsumPanel) lumpsumPanel.style.display = 'flex';
        if (labelLumpsum) {
            labelLumpsum.style.borderColor = '#3b82f6';
            labelLumpsum.style.background = '#eff6ff';
            labelLumpsum.style.color = '#1e40af';
        }
        if (labelStandard) {
            labelStandard.style.borderColor = '#e2e8f0';
            labelStandard.style.background = 'white';
            labelStandard.style.color = '#475569';
        }
        handleLumpsumSubtypeChange();
    }
}
window.setOvertimeType = setOvertimeType;

function handleOvertimeTypeChange() {
    const val = document.querySelector('input[name="schemeOvertimeType"]:checked')?.value || 'standard';
    setOvertimeType(val);
}
window.handleOvertimeTypeChange = handleOvertimeTypeChange;

function setLumpsumSubtype(subtype) {
    const radios = document.querySelectorAll('input[name="schemeLumpsumSubtype"]');
    radios.forEach(r => {
        r.checked = (r.value === subtype);
    });

    // Update label styles
    const labels = {
        'per_jam': document.getElementById('labelLumpsumPerJam'),
        'harian': document.getElementById('labelLumpsumDayan'),
        'monthan': document.getElementById('labelLumpsumMonthan')
    };
    Object.keys(labels).forEach(key => {
        const el = labels[key];
        if (!el) return;
        if (key === subtype) {
            el.style.borderColor = '#3b82f6';
            el.style.background = '#eff6ff';
        } else {
            el.style.borderColor = '#e2e8f0';
            el.style.background = 'white';
        }
    });

    // Update nominal label
    const nomLabel = document.getElementById('labelLumpsumNominal');
    if (nomLabel) {
        const subtypeLabels = {
            'per_jam': 'Overtime Hourly Rate (IDR)',
            'harian': 'Overtime Daily Rate (IDR)',
            'monthan': 'Overtime Monthly Rate (IDR)'
        };
        nomLabel.textContent = subtypeLabels[subtype] || subtypeLabels['per_jam'];
    }
}
window.setLumpsumSubtype = setLumpsumSubtype;

function handleLumpsumSubtypeChange() {
    const val = document.querySelector('input[name="schemeLumpsumSubtype"]:checked')?.value || 'per_jam';
    setLumpsumSubtype(val);
}
window.handleLumpsumSubtypeChange = handleLumpsumSubtypeChange;

// ===== EXCEL TEMPLATE DOWNLOAD & UPLOAD FITUR SKEMA PAYROLL =====

function downloadTemplateSchemePayroll() {
    try {
        // Form template excel kosong (hanya header kolom tanpa data contoh)
        const headers = [[
            'Scheme Name',
            'Deskripsi',
            'Tipe Scheme (monthan/harian)',
            'Base Salary Nominal (Rp)',
            'Sumber Gaji (nominal/ump/umk)',
            'Prorate (1=Ya, 0=Tidak)',
            'Absen Tidak Potong (1=Ya, 0=Tidak)',
            'Nominal Deduction Alpa (Rp/hari)',
            'Toleransi Terlambat (Menit)',
            'Toleransi Pulang Cepat (Menit)',
            'Minimal Overtime (Menit)',
            'Tipe Overtime (standard/lumpsum)',
            'Nominal Overtime Lumpsum (Rp)',
            'Component 1 Nama',
            'Component 1 Tipe (revenue/deduction)',
            'Component 1 Value',
            'Component 1 Persentase (1=Ya, 0=Tidak)',
            'Component 2 Nama',
            'Component 2 Tipe (revenue/deduction)',
            'Component 2 Value',
            'Component 2 Persentase (1=Ya, 0=Tidak)',
            'Component 3 Nama',
            'Component 3 Tipe (revenue/deduction)',
            'Component 3 Value',
            'Component 3 Persentase (1=Ya, 0=Tidak)'
        ]];

        const ws = XLSX.utils.aoa_to_sheet(headers);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Payroll Scheme Template');

        // Adjust column widths
        ws['!cols'] = [
            { wch: 25 }, // Scheme Name
            { wch: 25 }, // Deskripsi
            { wch: 22 }, // Tipe Scheme
            { wch: 22 }, // Base Salary Nominal
            { wch: 22 }, // Sumber Gaji
            { wch: 20 }, // Prorate
            { wch: 22 }, // Absen Tidak Potong
            { wch: 24 }, // Nominal Deduction Alpa
            { wch: 22 }, // Toleransi Terlambat
            { wch: 24 }, // Toleransi Pulang Cepat
            { wch: 20 }, // Minimal Overtime
            { wch: 24 }, // Tipe Overtime
            { wch: 24 }, // Nominal Overtime Lumpsum
            { wch: 20 }, // Component 1 Nama
            { wch: 25 }, // Component 1 Tipe
            { wch: 18 }, // Component 1 Value
            { wch: 24 }, // Component 1 Persentase
            { wch: 20 }, // Component 2 Nama
            { wch: 25 }, // Component 2 Tipe
            { wch: 18 }, // Component 2 Value
            { wch: 24 }, // Component 2 Persentase
            { wch: 20 }, // Component 3 Nama
            { wch: 25 }, // Component 3 Tipe
            { wch: 18 }, // Component 3 Value
            { wch: 24 }  // Component 3 Persentase
        ];

        XLSX.writeFile(wb, 'template_master_payroll_scheme.xlsx');
        if (typeof showToast === 'function') {
            showToast('Blank Excel template downloaded successfully!', 'success');
        } else {
            alert('Blank Excel template downloaded successfully!');
        }
    } catch (err) {
        console.error('Error downloading template:', err);
        if (typeof showToast === 'function') {
            showToast('Failed mendownload template Excel', 'error');
        }
    }
}
window.downloadTemplateSchemePayroll = downloadTemplateSchemePayroll;

function bukaModalUploadSchemePayroll() {
    const modal = document.getElementById('modalUploadSchemePayroll');
    const overlay = document.getElementById('overlay');
    if (modal) modal.style.display = 'block';
    if (overlay) overlay.style.display = 'block';

    // Reset file input label
    const fileInput = document.getElementById('fileSchemePayrollExcel');
    const fileLabel = document.getElementById('labelSchemePayrollFilename');
    if (fileInput) fileInput.value = '';
    if (fileLabel) {
        fileLabel.textContent = 'Tidak ada file yang dipilih';
        fileLabel.style.color = '#059669';
    }
}
window.bukaModalUploadSchemePayroll = bukaModalUploadSchemePayroll;

function tutupModalUploadSchemePayroll() {
    const modal = document.getElementById('modalUploadSchemePayroll');
    const overlay = document.getElementById('overlay');
    if (modal) modal.style.display = 'none';
    if (overlay) overlay.style.display = 'none';
}
window.tutupModalUploadSchemePayroll = tutupModalUploadSchemePayroll;

function handleSchemePayrollFileSelect(e) {
    const file = e && e.target && e.target.files ? e.target.files[0] : null;
    const fileLabel = document.getElementById('labelSchemePayrollFilename');
    if (file && fileLabel) {
        fileLabel.textContent = `📌 File Terpilih: ${file.name}`;
        fileLabel.style.color = '#059669';
    } else if (fileLabel) {
        fileLabel.textContent = 'Tidak ada file yang dipilih';
    }
}
window.handleSchemePayrollFileSelect = handleSchemePayrollFileSelect;

async function importSchemePayrollExcel() {
    const fileInput = document.getElementById('fileSchemePayrollExcel');
    const file = fileInput && fileInput.files ? fileInput.files[0] : null;

    if (!file) {
        if (typeof showToast === 'function') {
            showToast('Please select file Excel terlebih dahulu!', 'warning');
        } else {
            alert('Please select file Excel terlebih dahulu!');
        }
        return;
    }

    const reader = new FileReader();
    reader.onload = async (e) => {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            const sheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[sheetName];

            const rows = XLSX.utils.sheet_to_json(worksheet, { header: 1 });
            if (rows.length < 2) {
                if (typeof showToast === 'function') {
                    showToast('File Excel tidak berisi baris data!', 'error');
                } else {
                    alert('File Excel tidak berisi baris data!');
                }
                return;
            }

            const headerRow = rows[0].map(h => h ? h.toString().trim().toLowerCase() : '');
            
            // Helper to get value by matching header keyword
            const getVal = (rowArr, keywords, defaultVal = '') => {
                for (const kw of keywords) {
                    const idx = headerRow.findIndex(h => h.includes(kw));
                    if (idx !== -1 && rowArr[idx] !== undefined && rowArr[idx] !== null) {
                        return rowArr[idx].toString().trim();
                    }
                }
                return defaultVal;
            };

            const parsedSchemes = [];

            for (let i = 1; i < rows.length; i++) {
                const r = rows[i];
                if (!r || r.length === 0) continue;
                const isRowEmpty = r.every(cell => cell === null || cell === undefined || cell.toString().trim() === '');
                if (isRowEmpty) continue;

                // Read Scheme Name (first column or header search)
                let namaScheme = r[0] ? r[0].toString().trim() : '';
                if (!namaScheme) {
                    namaScheme = getVal(r, ['nama scheme', 'nama', 'scheme']);
                }
                if (!namaScheme) continue;

                const deskripsi = getVal(r, ['deskripsi', 'notes', 'description']);
                const tipe = getVal(r, ['tipe scheme', 'tipe'], 'monthan').toLowerCase();
                const gajiPokok = parseFloat(getVal(r, ['gaji pokok', 'gajipokok', 'basic salary', 'nominal gaji'], 0)) || 0;
                const sumberGaji = getVal(r, ['sumber gaji', 'sumbergaji'], 'nominal').toLowerCase();
                const prorate = parseInt(getVal(r, ['prorate'], 0)) || 0;
                const absenTidakPotong = parseInt(getVal(r, ['absen tidak potong', 'tidak potong'], 0)) || 0;
                const nominalDeduction = parseFloat(getVal(r, ['nominal deduction', 'deduction alpa'], 0)) || 0;
                const graceLate = parseInt(getVal(r, ['toleransi terlambat', 'late'], 0)) || 0;
                const graceEarly = parseInt(getVal(r, ['toleransi pulang cepat', 'early'], 0)) || 0;
                const minOvertime = parseInt(getVal(r, ['minimal overtime', 'min overtime'], 30)) || 30;
                const tipeOvertime = getVal(r, ['tipe overtime', 'overtime type'], 'standard').toLowerCase();
                const lumpsumNominal = parseFloat(getVal(r, ['nominal overtime lumpsum', 'lumpsum'], 0)) || 0;

                // Extract components dynamically if present
                const components = [];
                for (let compIdx = 1; compIdx <= 5; compIdx++) {
                    const compNama = getVal(r, [`component ${compIdx} nama`, `component${compIdx}nama`, `allowance ${compIdx} nama`, `deduction ${compIdx} nama`]);
                    if (compNama) {
                        const compTipe = getVal(r, [`component ${compIdx} tipe`, `allowance ${compIdx} tipe`, `deduction ${compIdx} tipe`], 'revenue').toLowerCase();
                        const compValue = parseFloat(getVal(r, [`component ${compIdx} value`, `allowance ${compIdx} nominal`, `deduction ${compIdx} nominal`], 0)) || 0;
                        const compPersen = parseInt(getVal(r, [`component ${compIdx} persentase`], 0)) || 0;
                        components.push({
                            nama: compNama,
                            tipe: compTipe,
                            value: compValue,
                            is_persentase: compPersen
                        });
                    }
                }

                parsedSchemes.push({
                    nama: namaScheme,
                    deskripsi: deskripsi,
                    tipe: tipe,
                    gaji_pokok: gajiPokok,
                    sumber_gaji: sumberGaji,
                    prorate: prorate,
                    absen_tidak_potong: absenTidakPotong,
                    nominal_deduction: nominalDeduction,
                    grace_period_late: graceLate,
                    grace_period_early: graceEarly,
                    min_overtime: minOvertime,
                    overtime_type: tipeOvertime,
                    lumpsum_nominal: lumpsumNominal,
                    components: components
                });
            }

            if (parsedSchemes.length === 0) {
                if (typeof showToast === 'function') {
                    showToast('Tidak ada scheme valid yang ditemukan pada file Excel!', 'warning');
                } else {
                    alert('Tidak ada scheme valid yang ditemukan pada file Excel!');
                }
                return;
            }

            const btnSubmit = document.getElementById('btnSubmitUploadedSchemePayroll');
            if (btnSubmit) {
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = `<i class="fas fa-spinner fa-spin"></i> Mengimpor...`;
            }

            const response = await fetch(`${API_URL}/payroll-schemes/upload-excel`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ schemes: parsedSchemes })
            });

            const result = await response.json();

            if (btnSubmit) {
                btnSubmit.disabled = false;
                btnSubmit.innerHTML = `<i class="fas fa-upload"></i> Upload & Import`;
            }

            if (response.ok) {
                tutupModalUploadSchemePayroll();
                if (typeof showToast === 'function') {
                    showToast(result.message || 'Successfully mengimpor scheme payroll!', 'success');
                } else {
                    alert(result.message || 'Successfully mengimpor scheme payroll!');
                }
                if (typeof renderPayrollSchemes === 'function') {
                    renderPayrollSchemes();
                }
            } else {
                if (typeof showToast === 'function') {
                    showToast(result.message || 'Failed mengimpor scheme payroll.', 'error');
                } else {
                    alert(result.message || 'Failed mengimpor scheme payroll.');
                }
            }
        } catch (err) {
            console.error('Error importing Excel:', err);
            if (typeof showToast === 'function') {
                showToast('Failed to process Excel file: ' + err.message, 'error');
            } else {
                alert('Failed to process Excel file: ' + err.message);
            }
        }
    };
    reader.readAsArrayBuffer(file);
}
window.importSchemePayrollExcel = importSchemePayrollExcel;
