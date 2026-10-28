// ===== PROCESS PAYROLL MODULE =====
// Extracted from app.js for modular monolith architecture

// ===== 7. PROSES PAYROLL BULANAN =====
async function autoCreatePeriod(month, year) {
    if (!window.selectedClientId) return;
    const data = {
        client_id: parseInt(window.selectedClientId),
        month: parseInt(month),
        year: parseInt(year)
    };
    try {
        const res = await fetch(`${API_URL}/periods`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        if (res.ok) {
            const url = `${API_URL}/periods?client_id=${window.selectedClientId}`;
            const response = await fetch(url);
            const periods = await response.json();
            window.loadedPeriods = periods;
            
            const activePeriod = periods.find(p => parseInt(p.month) === month && parseInt(p.year) === year);
            if (activePeriod) {
                currentPeriodId = activePeriod.id;
                window.currentPeriodId = activePeriod.id;
                selectPeriod(currentPeriodId, activePeriod.nama);
            }
        } else {
            console.error('Failed to auto-create period');
        }
    } catch (err) {
        console.error(err);
    }
}

async function loadActivePeriod() {
    try {
        const monthSelect = document.getElementById('processMonthSelect');
        const yearSelect = document.getElementById('processYearSelect');
        
        const d = new Date();
        const currentMonth = d.getMonth() + 1;
        const currentYear = d.getFullYear();
        
        if (monthSelect && !monthSelect.value) {
            monthSelect.value = currentMonth;
        }
        if (yearSelect && !yearSelect.value) {
            yearSelect.value = currentYear;
        }

        const selMonth = monthSelect ? parseInt(monthSelect.value) : currentMonth;
        const selYear = yearSelect ? parseInt(yearSelect.value) : currentYear;

        const url = window.selectedClientId ? `${API_URL}/periods?client_id=${window.selectedClientId}` : `${API_URL}/periods`;
        const response = await fetch(url);
        const periods = await response.json();
        window.loadedPeriods = periods;
        
        const list = document.getElementById('periodHistoryList');
        if (list) {
            list.innerHTML = periods.map(p => `
                <div class="period-item ${p.id == currentPeriodId ? 'active' : ''}" onclick="selectPeriod(${p.id}, '${p.nama}')" style="padding: 10px; border-bottom: 1px solid #eee; cursor: pointer; display: flex; justify-content: space-between; align-items: center; background: ${p.id == currentPeriodId ? '#f0fdf4' : 'transparent'};">
                    <span style="font-weight: 500;">${p.nama}</span>
                    <span class="status-badge ${p.status && (p.status.toLowerCase().includes('open') || p.status.toLowerCase().includes('terbuka') || p.status.toLowerCase().includes('active')) ? 'success' : 'danger'}" style="font-size: 11px;">${p.status}</span>
                </div>
            `).join('');
        }
        
        let activePeriod = periods.find(p => parseInt(p.month) === selMonth && parseInt(p.year) === selYear);
        
        if (activePeriod) {
            currentPeriodId = activePeriod.id;
            window.currentPeriodId = activePeriod.id;
            
            if (monthSelect) monthSelect.value = activePeriod.month;
            if (yearSelect) yearSelect.value = activePeriod.year;
            
            selectPeriod(currentPeriodId, activePeriod.nama);
        } else {
            if (window.selectedClientId) {
                await autoCreatePeriod(selMonth, selYear);
            } else {
                currentPeriodId = null;
                window.currentPeriodId = null;
                selectPeriod(null, '');
            }
        }
    } catch (err) { console.error(err); }
}

function selectPeriod(id, name) {
    currentPeriodId = id;
    window.currentPeriodId = id;
    if (!id || id === 'null' || id === '') {
        document.getElementById('prosesActions').style.display = 'none';
        document.getElementById('prosesEmptyState').style.display = 'block';
        if (document.getElementById('resultSection')) document.getElementById('resultSection').style.display = 'none';
        if (document.getElementById('resultsEmptyState')) document.getElementById('resultsEmptyState').style.display = 'block';
        return;
    }
    document.getElementById('activePeriodName').innerText = name;
    
    // Update active period status badge dynamically
    const statusBadge = document.getElementById('activePeriodStatus');
    if (statusBadge && window.loadedPeriods) {
        const period = window.loadedPeriods.find(p => p.id == id);
        const status = period ? period.status : 'Open';
        statusBadge.innerText = status;
        statusBadge.className = 'status-badge ' + (status.toLowerCase().includes('open') || status.toLowerCase().includes('terbuka') || status.toLowerCase().includes('active') ? 'success' : 'danger');
    }

    document.getElementById('prosesActions').style.display = 'block';
    document.getElementById('prosesEmptyState').style.display = 'none';
    if (document.getElementById('resultsEmptyState')) document.getElementById('resultsEmptyState').style.display = 'none';
    if (document.getElementById('resultSection')) document.getElementById('resultSection').style.display = 'block';
    renderCutOffTable();
    renderReviewGajiTable();
    tutupAllModal();
}

async function onProcessPeriodChange() {
    const monthSelect = document.getElementById('processMonthSelect');
    const yearSelect = document.getElementById('processYearSelect');
    if (!monthSelect || !yearSelect || !window.loadedPeriods) return;
    
    const selectedMonth = parseInt(monthSelect.value);
    const selectedYear = parseInt(yearSelect.value);
    
    const matchedPeriod = window.loadedPeriods.find(p => parseInt(p.month) === selectedMonth && parseInt(p.year) === selectedYear);
    
    if (matchedPeriod) {
        selectPeriod(matchedPeriod.id, matchedPeriod.nama);
    } else {
        if (window.selectedClientId) {
            showToast('Membuka period baru secara otomatis...', 'info');
            await autoCreatePeriod(selectedMonth, selectedYear);
        } else {
            currentPeriodId = null;
            window.currentPeriodId = null;
            document.getElementById('prosesActions').style.display = 'none';
            document.getElementById('prosesEmptyState').style.display = 'block';
            
            if (document.getElementById('resultSection')) document.getElementById('resultSection').style.display = 'none';
            if (document.getElementById('resultsEmptyState')) document.getElementById('resultsEmptyState').style.display = 'block';
        }
    }
}
window.onProcessPeriodChange = onProcessPeriodChange;

function switchPayrollProcessSubTab(tab) {
    document.querySelectorAll('.client-proses-subpanel').forEach(p => p.style.display = 'none');
    document.querySelectorAll('#viewProcess .sub-tab-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.style.color = '#64748b';
        btn.style.borderBottomColor = 'transparent';
    });

    if (tab === 'processing') {
        const panel = document.getElementById('panelSalaryProcessing');
        if (panel) panel.style.display = 'block';
        const activeBtn = document.getElementById('subTabSalaryProcessing');
        if (activeBtn) {
            activeBtn.classList.add('active');
            activeBtn.style.color = 'var(--primary-color)';
            activeBtn.style.borderBottomColor = 'var(--primary-color)';
        }
    } else if (tab === 'results') {
        const panel = document.getElementById('panelCalculationResults');
        if (panel) panel.style.display = 'block';
        const activeBtn = document.getElementById('subTabCalculationResults');
        if (activeBtn) {
            activeBtn.classList.add('active');
            activeBtn.style.color = 'var(--primary-color)';
            activeBtn.style.borderBottomColor = 'var(--primary-color)';
        }
    }
}
window.switchPayrollProcessSubTab = switchPayrollProcessSubTab;

async function renderCutOffTable() {
    if(!currentPeriodId) return;
    try {
        const tbody = document.getElementById('tabelCutOffBody');
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 20px; color: #94a3b8;"><i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i>Loading data...</td></tr>`;
        }
        const url = window.selectedClientId ? `${API_URL}/attendance/${currentPeriodId}?client_id=${window.selectedClientId}` : `${API_URL}/attendance/${currentPeriodId}`;
        const res = await fetch(url);
        if (!res.ok) {
            throw new Error('Failed to load attendance data (HTTP ' + res.status + ')');
        }
        const data = await res.json();
        window.currentPeriodAttendance = data;
        if (!tbody) return;
        if (!data || data.length === 0) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 20px; color: #64748b;"><i class="fas fa-info-circle" style="margin-right: 8px;"></i>No data available karyawan aktif untuk period ini.</td></tr>`;
            return;
        }
        tbody.innerHTML = data.map(row => {
            const hariKerja = parseFloat(row.hari_kerja) || 0;
            const rapelDay = parseInt(row.rapel_hari_kerja) || 0;
            const displayDay = hariKerja > 0 ? `${hariKerja} Days` : (rapelDay > 0 ? `${rapelDay} Days (Rapel)` : '0 Days');
            const jamOvertime = parseFloat(row.jam_overtime) || 0;
            const earlyArrival = parseInt(row.early_arrival_minutes) || 0;
            const earlyArrivalHours = earlyArrival > 0 ? Math.round(earlyArrival / 60) : 0;
            const deduction = parseFloat(row.deduction_absensi) || 0;
            const bonus = parseFloat(row.bonus_tambahan) || 0;
            const empId = row.employ_id || row.nik || '-';
            return `
            <tr>
                <td><span style="font-weight: 600; color: #475569;">${empId}</span></td>
                <td>${row.employee_name} <span class="status-badge info" style="font-size:10px; margin-left:5px; padding:2px 6px;">${row.tipe_perjanjian || 'PKWT'}</span></td>
                <td>${displayDay}</td>
                <td>${jamOvertime > 0 ? jamOvertime + ' Hours' : '-'}</td>
                <td>${earlyArrivalHours > 0 ? earlyArrivalHours + ' Hours' : '-'}</td>
                <td>${formatRupiah(deduction)}</td>
                <td>${formatRupiah(bonus)}</td>
                <td><button class="btn-icon btn-edit" onclick="bukaModalCutOff(${row.pkwt_id}, '${row.employee_name}', ${hariKerja}, ${jamOvertime}, ${deduction}, ${bonus}, ${earlyArrivalHours})"><i class="fas fa-edit"></i></button></td>
            </tr>`;
        }).join('');
    } catch (err) { 
        console.error(err); 
        const tbody = document.getElementById('tabelCutOffBody');
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align: center; padding: 20px; color: #ef4444;"><i class="fas fa-exclamation-circle" style="margin-right: 8px;"></i>Failed to load data cut-off: ${err.message || err}</td></tr>`;
        }
    }
}

async function renderReviewGajiTable() {
    if(!currentPeriodId) return;
    try {
        const tbody = document.getElementById('tabelReviewGajiBody');
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="38" style="text-align: center; padding: 20px; color: #94a3b8;"><i class="fas fa-spinner fa-spin" style="margin-right: 8px;"></i>Loading data...</td></tr>`;
        }
        const url = window.selectedClientId ? `${API_URL}/payroll-results/${currentPeriodId}?client_id=${window.selectedClientId}` : `${API_URL}/payroll-results/${currentPeriodId}`;
        const res = await fetch(url);
        if (!res.ok) {
            throw new Error('Failed to load payroll results (HTTP ' + res.status + ')');
        }
        const data = await res.json();
        const section = document.getElementById('resultSection');
        if (!tbody) return;

        const monthSelect = document.getElementById('processMonthSelect');
        const yearSelect = document.getElementById('processYearSelect');
        const selectedMonth = monthSelect ? parseInt(monthSelect.value) : new Date().getMonth() + 1;
        const selectedYear = yearSelect ? parseInt(yearSelect.value) : new Date().getFullYear();
        const lastDayOfPeriodMonth = new Date(selectedYear, selectedMonth, 0);

        let displayData = data;
        window.lastLoadedProcessGajiData = displayData;
        const role = typeof getCurrentRole === 'function' ? getCurrentRole() : 'admin';
        if (role === 'staff') {
            displayData = data.filter(row => {
                const currentFullName = (currentUser && currentUser.full_name) ? currentUser.full_name.toLowerCase().trim() : '';
                const currentUsername = (currentUser && currentUser.username) ? currentUser.username.toLowerCase().trim() : '';
                const empName = (row.employee_name || '').toLowerCase().trim();
                return empName === currentFullName || empName === currentUsername;
            });
            // Staff can view payslip status whether Approved, Pending, or Hold
            displayData = displayData.filter(row => ['Approved', 'Pending', 'Hold'].includes(row.status_approval));
        }

        document.querySelectorAll('#selectAllReviewGaji').forEach(input => {
            const th = input.closest('th');
            if (th) th.style.display = (role === 'staff') ? 'none' : '';
        });
        document.querySelectorAll('#btnApproveSelectedGaji').forEach(btn => {
            btn.style.display = (role === 'staff') ? 'none' : '';
        });

        if (displayData.length > 0) {
            section.style.display = 'block';
            tbody.innerHTML = displayData.map(row => {
                const gp = parseFloat(row.gaji_pokok || 0);
                const ot = parseFloat(row.overtime_pay || 0);
                const ea = parseFloat(row.early_arrival_pay || 0);
                
                // Parse raw_components for Rapel component
                let rapelVal = 0;
                if (row.raw_components) {
                    try {
                        const comps = JSON.parse(row.raw_components);
                        comps.forEach(c => {
                            if (c.nama && c.nama.toLowerCase().includes('rapel')) {
                                rapelVal += parseFloat(c.value || 0);
                            }
                        });
                    } catch(e) {
                        console.error('Error parsing raw_components:', e);
                    }
                }

                const totalRevenue = parseFloat(row.total_revenue || 0);
                const lainBonus = Math.max(0, totalRevenue - gp - ot - ea - rapelVal);

                const potAbsen = parseFloat(row.deduction_absen || 0);
                const bpjsKes = parseFloat(row.bpjs_kes_karyawan || 0);
                const bpjsJht = parseFloat(row.bpjs_jht_karyawan || 0);
                const bpjsJp = parseFloat(row.bpjs_jp_karyawan || 0);
                const bpjsKaryawan = bpjsKes + bpjsJht + bpjsJp;
                const pph21 = parseFloat(row.pph21 || 0);
                const totalDeduction = parseFloat(row.total_deduction || 0);
                const potLain = Math.max(0, totalDeduction - potAbsen - bpjsKaryawan - pph21);

                const isPkwtExpired = row.tipe_perjanjian === 'PKWT' && row.end_contract && new Date(row.end_contract) < lastDayOfPeriodMonth;
                const empId = row.employ_id || row.nik || '-';

                return `
                    <tr>
                        <td style="text-align: center; vertical-align: middle;">
                            <input type="checkbox" class="review-gaji-checkbox" data-id="${row.id}" data-employee-name="${row.employee_name}" data-scheme="${row.scheme_name || ''}" data-net-salary="${row.take_home_pay}" data-is-rapel="${row.is_new_hire_rapel ? 'true' : 'false'}" ${(row.status_approval === 'Approved' || row.status_approval === 'Hold') ? 'disabled' : ''} ${row.status_approval === 'Approved' ? 'checked' : ''} style="transform: scale(1.2); cursor: pointer;">
                        </td>
                        <td><span style="font-weight: 600; color: #475569;">${empId}</span></td>
                        <td>
                            ${row.employee_name} 
                            <span class="status-badge info" style="font-size:10px; margin-left:5px; padding:2px 6px;">${row.tipe_perjanjian || 'PKWT'}</span>
                            ${row.status_approval === 'Hold' ? (
                                isPkwtExpired 
                                    ? '<br><span style="font-size:10px; color:#ef4444; font-weight:600;"><i class="fas fa-exclamation-circle"></i> Ditunda (Kontrak PKWT Expired)</span>'
                                    : (row.is_new_hire_rapel 
                                        ? '<br><span style="font-size:10px; color:#ef4444; font-weight:600;"><i class="fas fa-exclamation-circle"></i> Ditunda (Gaji dirapel ke month depan)</span>' 
                                        : '<br><span style="font-size:10px; color:#ef4444; font-weight:600;"><i class="fas fa-exclamation-circle"></i> Ditunda (Absen setelah cut-off)</span>')
                            ) : ''}
                            ${row.is_new_hire_rapel ? ` <span class="status-badge warning" style="font-size:10px; margin-left:5px; padding:2px 6px; background-color:#fff3cd; color:#856404; border:1px solid #ffeeba; font-weight:600; border-radius:4px;">Dirapel ke ${row.rapel_payout_period || ''}</span>` : ''}
                        </td>
                        <td>${row.client_name || '-'}</td>
                        <td>${row.division_name || '-'}</td>
                        <td>${row.department_name || '-'}</td>
                        <td>${row.position_name || '-'}</td>
                        <td>${row.join_date || '-'}</td>
                        <td>${(row.work_days_per_week !== undefined && row.work_days_per_week !== null) ? row.work_days_per_week + ' Days/Wk' : '5 Days/Wk'}</td>
                        <td>${(row.working_days !== undefined && row.working_days !== null) ? row.working_days + ' Days' : '0 Days'}</td>
                        <td>${(row.total_days_in_period !== undefined && row.total_days_in_period !== null) ? row.total_days_in_period + ' Days' : '-'}</td>
                        <td>${(row.payable_days !== undefined && row.payable_days !== null) ? row.payable_days + ' Days' : '-'}</td>
                        <td>${(row.target_work_hours !== undefined && row.target_work_hours !== null) ? row.target_work_hours + ' Hours' : ((row.payable_days || 0) * 8) + ' Hours'}</td>
                        <td>${(row.actual_work_hours !== undefined && row.actual_work_hours !== null) ? row.actual_work_hours + ' Hours' : ((row.working_days || 0) * 8) + ' Hours'}</td>
                        <td>${row.scheme_name || '-'}</td>
                        <td>${row.ptkp_status || 'TK/0'}</td>
                        <td>${formatRupiah(gp)}</td>
                        <td>${formatRupiah(ot)}</td>
                        <td>${formatRupiah(ea)}</td>
                        <td>${formatRupiah(rapelVal)}</td>
                        <td>${formatRupiah(lainBonus)}</td>
                        <td style="font-weight:600;">${formatRupiah(totalRevenue)}</td>
                        <td>${formatRupiah(potAbsen)}</td>
                        <td>${formatRupiah(bpjsKes)}</td>
                        <td>${formatRupiah(bpjsJht)}</td>
                        <td>${formatRupiah(bpjsJp)}</td>
                        <td>${formatRupiah(pph21)}</td>
                        <td>${formatRupiah(potLain)}</td>
                        <td style="font-weight:600;">${formatRupiah(totalDeduction)}</td>
                        <td style="font-weight:700; color:#2e7d32;">${formatRupiah(row.take_home_pay)}</td>
                        <td>${formatRupiah(parseFloat(row.bpjs_kes_company || 0))}</td>
                        <td>${formatRupiah(parseFloat(row.bpjs_jht_company || 0))}</td>
                        <td>${formatRupiah(parseFloat(row.bpjs_jp_company || 0))}</td>
                        <td>${formatRupiah(parseFloat(row.bpjs_jkk_company || 0))}</td>
                        <td>${formatRupiah(parseFloat(row.bpjs_jkm_company || 0))}</td>
                        <td style="font-weight:600;">${formatRupiah(
                            parseFloat(row.bpjs_kes_company || 0) + 
                            parseFloat(row.bpjs_jht_company || 0) + 
                            parseFloat(row.bpjs_jp_company || 0) + 
                            parseFloat(row.bpjs_jkk_company || 0) + 
                            parseFloat(row.bpjs_jkm_company || 0)
                        )}</td>
                        <td>
                            <span class="status-badge ${row.status_approval === 'Approved' ? 'success' : (row.status_approval === 'Hold' ? 'danger' : 'warning')}">${row.status_approval}</span>
                            ${row.status_approval === 'Hold' ? (
                                isPkwtExpired 
                                    ? '<br><span style="font-size:10px; color:#ef4444; font-weight:600; display:block; margin-top:4px;">(Kontrak PKWT Expired)</span>'
                                    : (row.is_new_hire_rapel 
                                        ? '<br><span style="font-size:10px; color:#ef4444; font-weight:600; display:block; margin-top:4px;">(Gaji Dirapel)</span>' 
                                        : '<br><span style="font-size:10px; color:#ef4444; font-weight:600; display:block; margin-top:4px;">(Absen Setelah Cut-Off)</span>')
                            ) : ''}
                        </td>
                        <td>
                            <div style="display:flex; gap:6px; align-items:center;">
                                <button class="btn-icon btn-neutral" onclick="bukaSlipGaji(${row.id})" title="View Salary Slip / Details" style="width:30px; height:30px; border-radius:6px; display:flex; align-items:center; justify-content:center;"><i class="fas fa-eye"></i></button>
                                ${(row.status_approval === 'Hold' && isPkwtExpired && (role === 'client_superior' || role === 'admin')) ? `
                                <button class="btn-icon" onclick="releaseHoldDanApprove(${row.id}, '${row.employee_name}')" title="Release Hold & Approve" style="background:#22c55e; color:white; border:none; border-radius:6px; width:30px; height:30px; display:flex; align-items:center; justify-content:center; cursor:pointer;"><i class="fas fa-check"></i></button>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');

            // Calculate totals for BPJS & Deductions summary cards
            let sumBpjsKesCo = 0;
            let sumBpjsTkCo = 0;
            let sumBpjsKesEmp = 0;
            let sumBpjsTkEmp = 0;
            let sumPotAbsen = 0;
            let sumNetSalary = 0;

            displayData.forEach(row => {
                sumBpjsKesCo += parseFloat(row.bpjs_kes_company || 0);
                sumBpjsTkCo += (
                    parseFloat(row.bpjs_jht_company || 0) +
                    parseFloat(row.bpjs_jp_company || 0) +
                    parseFloat(row.bpjs_jkk_company || 0) +
                    parseFloat(row.bpjs_jkm_company || 0)
                );
                sumBpjsKesEmp += parseFloat(row.bpjs_kes_karyawan || 0);
                sumBpjsTkEmp += (
                    parseFloat(row.bpjs_jht_karyawan || 0) +
                    parseFloat(row.bpjs_jp_karyawan || 0)
                );
                sumPotAbsen += parseFloat(row.deduction_absen || 0);
                const netVal = (row.take_home_pay !== undefined && row.take_home_pay !== null) ? parseFloat(row.take_home_pay) : (parseFloat(row.total_revenue || 0) - parseFloat(row.total_deduction || 0));
                sumNetSalary += netVal;
            });

            const elNetSalary = document.getElementById('kpiProcessTotalNetSalary');
            const elKesCo = document.getElementById('kpiProcessBpjsKesCo');
            const elTkCo = document.getElementById('kpiProcessBpjsTkCo');
            const elKesEmp = document.getElementById('kpiProcessBpjsKesEmp');
            const elTkEmp = document.getElementById('kpiProcessBpjsTkEmp');
            const elAbsen = document.getElementById('kpiProcessDeductionAbsen');

            if (elNetSalary) elNetSalary.innerText = formatRupiah(sumNetSalary);
            if (elKesCo) elKesCo.innerText = formatRupiah(sumBpjsKesCo);
            if (elTkCo) elTkCo.innerText = formatRupiah(sumBpjsTkCo);
            if (elKesEmp) elKesEmp.innerText = formatRupiah(sumBpjsKesEmp);
            if (elTkEmp) elTkEmp.innerText = formatRupiah(sumBpjsTkEmp);
            if (elAbsen) elAbsen.innerText = formatRupiah(sumPotAbsen);

            // Hide the checkbox cells for staff role to avoid column shifting
            if (role === 'staff') {
                tbody.querySelectorAll('.review-gaji-checkbox').forEach(input => {
                    const td = input.closest('td');
                    if (td) td.style.display = 'none';
                });
            }
        } else { 
            section.style.display = 'block';
            tbody.innerHTML = `<tr><td colspan="38" style="text-align:center; padding: 20px; color:#7f8c8d;"><i class="fas fa-info-circle" style="margin-right: 8px;"></i>No data yet gaji yang di-generate untuk period ini.</td></tr>`;
        }
    } catch (err) { 
        console.error(err); 
        const tbody = document.getElementById('tabelReviewGajiBody');
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="38" style="text-align: center; padding: 20px; color: #ef4444;"><i class="fas fa-exclamation-circle" style="margin-right: 8px;"></i>Failed to load hasil kalkulasi: ${err.message || err}</td></tr>`;
        }
    }
}

// Add event listener for select all checkbox using event delegation
document.addEventListener('change', function(e) {
    if (e.target && e.target.id === 'selectAllReviewGaji') {
        const checkboxes = document.querySelectorAll('.review-gaji-checkbox:not(:disabled)');
        checkboxes.forEach(cb => {
            cb.checked = e.target.checked;
        });
    }
});

async function approveSelectedGaji() {
    const checkboxes = document.querySelectorAll('.review-gaji-checkbox:checked:not(:disabled)');
    if (checkboxes.length === 0) {
        showToast('Select at least one salary record with Pending status!', 'warning');
        return;
    }

    const ids = [];
    let validationFailed = false;
    let failMessage = '';

    for (let cb of checkboxes) {
        const id = cb.getAttribute('data-id');
        const name = cb.getAttribute('data-employee-name');
        const scheme = cb.getAttribute('data-scheme');
        const netSalary = parseFloat(cb.getAttribute('data-net-salary') || 0);

        // Validation 1: Must have a scheme
        if (!scheme || scheme === '-' || scheme.trim() === '') {
            validationFailed = true;
            failMessage = `Gaji untuk karyawan "${name}" tidak dapat disetujui karena belum memiliki scheme payroll.`;
            break;
        }

        // Validation 2: Net salary must be > 0
        if (netSalary <= 0) {
            validationFailed = true;
            failMessage = `Gaji untuk karyawan "${name}" tidak dapat disetujui karena Net Salary bervalue Rp 0 atau kurang.`;
            break;
        }

        ids.push(id);
    }

    if (validationFailed) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'error',
                title: 'Validasi Failed',
                text: failMessage,
                confirmButtonColor: '#3498db'
            });
        } else {
            showToast(failMessage, 'error');
        }
        return;
    }

    const confirmMsg = `Are you sure ingin menyetujui ${ids.length} data gaji yang dipilih?`;
    let confirmed = false;
    if (typeof showConfirm === 'function') {
        confirmed = await showConfirm(confirmMsg, 'Confirm Approval', 'Yes, Approve', 'Cancel', 'primary');
    } else {
        confirmed = confirm(confirmMsg);
    }

    if (!confirmed) return;

    showToast('Approving selected salaries...', 'info');

    try {
        const res = await fetch(`${API_URL}/approve-payroll-bulk`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: ids })
        });

        if (res.ok) {
            showToast('All selected salary data approved successfully!', 'success');
            const selectAll = document.getElementById('selectAllReviewGaji');
            if (selectAll) selectAll.checked = false;
            renderReviewGajiTable();
        } else {
            const errData = await res.json();
            const errMsg = errData.messages?.error || errData.message || 'Failed menyetujui data gaji terpilih!';
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Persetujuan Failed',
                    text: errMsg,
                    confirmButtonColor: '#3498db'
                });
            } else {
                showToast(errMsg, 'error');
            }
        }
    } catch (err) {
        console.error(err);
        showToast('An error occurred saat memproses persetujuan.', 'error');
    }
}

async function generateGaji() {
    if(!currentPeriodId) return;
    showToast('Calculating salary...', 'info');
    const url = window.selectedClientId ? `${API_URL}/generate-payroll/${currentPeriodId}?client_id=${window.selectedClientId}` : `${API_URL}/generate-payroll/${currentPeriodId}`;
    const res = await fetch(url, { method: 'POST' });
    if (res.ok) {
        showToast('Salary generated successfully!', 'success');
        renderReviewGajiTable();
        if (typeof loadPayrollReport === 'function') {
            loadPayrollReport(window.selectedClientId);
        }
    }
}

async function downloadSalaryTemplate() {
    if (!currentPeriodId) {
        showToast('Please select a period first.', 'warning');
        return;
    }
    if (!window.selectedClientId) {
        showToast('Please select a client first.', 'warning');
        return;
    }

    showToast('Generating manual salary template...', 'info');

    try {
        const attUrl = `${API_URL}/attendance/${currentPeriodId}?client_id=${window.selectedClientId}`;
        const resultsUrl = `${API_URL}/payroll-results/${currentPeriodId}?client_id=${window.selectedClientId}`;
        
        const [attRes, resultsRes] = await Promise.all([
            fetch(attUrl),
            fetch(resultsUrl)
        ]);
        
        if (!attRes.ok) {
            throw new Error('Failed to load employee list');
        }
        const attData = await attRes.json();
        const resultsData = resultsRes.ok ? await resultsRes.json() : [];

        if (!attData || attData.length === 0) {
            showToast('No active employees found for this client.', 'warning');
            return;
        }

        // Map the employee list to template rows
        const formatted = attData.map((row) => {
            // Find matched payroll result for pre-fill
            const matched = resultsData.find(r => r.pkwt_id == row.pkwt_id);
            
            let gp = parseFloat(row.gaji_pokok) || 0;
            let otPay = 0;
            let eaPay = 0;
            let rapel = 0;
            let bonus = parseFloat(row.bonus_tambahan) || 0;
            let potAbsen = parseFloat(row.deduction_absensi) || 0;
            let potLain = 0;

            if (matched) {
                gp = parseFloat(matched.gaji_pokok || 0);
                otPay = parseFloat(matched.overtime_pay || 0);
                eaPay = parseFloat(matched.early_arrival_pay || 0);
                
                // Parse rapel from raw_components
                if (matched.raw_components) {
                    try {
                        const comps = JSON.parse(matched.raw_components);
                        comps.forEach(c => {
                            if (c.nama && c.nama.toLowerCase().includes('rapel')) {
                                rapel += parseFloat(c.value || 0);
                            }
                        });
                    } catch(e) {}
                }
                
                const totalRevenue = parseFloat(matched.total_revenue || 0);
                bonus = Math.max(0, totalRevenue - gp - otPay - eaPay - rapel);

                potAbsen = parseFloat(matched.deduction_absen || 0);
                const bpjsKaryawan = parseFloat(matched.bpjs_kes_karyawan || 0) + parseFloat(matched.bpjs_jht_karyawan || 0) + parseFloat(matched.bpjs_jp_karyawan || 0);
                const totalDeduction = parseFloat(matched.total_deduction || 0);
                potLain = Math.max(0, totalDeduction - potAbsen - bpjsKaryawan - parseFloat(matched.pph21 || 0));
            }

            return {
                'PKWT ID': row.pkwt_id,
                'Employee Name': row.employee_name,
                'Employee ID (NIK)': row.employ_id || '',
                'Working Days': parseFloat(row.hari_kerja) || 0,
                'Overtime Hours': parseFloat(row.jam_overtime) || 0,
                'Early Arrival Hours': row.early_arrival_minutes ? Math.round(row.early_arrival_minutes / 60) : 0,
                'Basic Salary (Base Salary)': gp,
                'Rapel': rapel,
                'Bonus / Lainnya': bonus,
                'Absence Deduction (Absence Deduction)': potAbsen,
                'Other Deductions': potLain
            };
        });

        const ws = XLSX.utils.json_to_sheet(formatted);
        const wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, "Salary Upload Template");

        // Set column widths
        const wscols = [
            { wch: 10 }, // PKWT ID
            { wch: 25 }, // Employee Name
            { wch: 18 }, // Employee ID (NIK)
            { wch: 15 }, // Working Days
            { wch: 15 }, // Overtime Hours
            { wch: 18 }, // Early Arrival Hours
            { wch: 25 }, // Basic Salary (Base Salary)
            { wch: 15 }, // Rapel
            { wch: 20 }, // Bonus / Lainnya
            { wch: 30 }, // Absence Deduction (Absence Deduction)
            { wch: 15 }  // Other Deductions
        ];
        ws['!cols'] = wscols;

        // Save file
        const monthSelect = document.getElementById('processMonthSelect');
        const yearSelect = document.getElementById('processYearSelect');
        const periodStr = monthSelect && yearSelect ? `${monthSelect.options[monthSelect.selectedIndex].text}_${yearSelect.value}` : currentPeriodId;
        const filename = `manual_salary_template_${periodStr}.xlsx`;
        XLSX.writeFile(wb, filename);

        showToast('Template downloaded successfully!', 'success');
    } catch (err) {
        console.error(err);
        showToast('Failed to generate template: ' + err.message, 'error');
    }
}

function bukaModalUploadManualSalary() {
    window.uploadedManualSalaryData = null;
    const fileInput = document.getElementById('fileManualSalaryExcel');
    if (fileInput) fileInput.value = '';
    
    const labelFilename = document.getElementById('labelManualSalaryFilename');
    if (labelFilename) labelFilename.innerText = 'No file chosen';
    
    const btnSave = document.getElementById('btnSaveManualSalary');
    if (btnSave) {
        btnSave.disabled = true;
        btnSave.style.cursor = 'not-allowed';
        btnSave.style.opacity = '0.5';
    }

    const dropzone = document.getElementById('dropzoneManualSalaryExcel');
    if (dropzone) {
        dropzone.style.background = 'rgba(41, 128, 185, 0.08)';
    }
    
    document.getElementById('modalUploadManualSalary').style.display = 'block';
    document.getElementById('overlay').style.display = 'block';
}

function tutupModalUploadManualSalary() {
    document.getElementById('modalUploadManualSalary').style.display = 'none';
    document.getElementById('overlay').style.display = 'none';
}

function handleManualSalaryDragOver(e) {
    e.preventDefault();
    const dropzone = document.getElementById('dropzoneManualSalaryExcel');
    if (dropzone) {
        dropzone.style.background = 'rgba(41, 128, 185, 0.15)';
    }
}

function handleManualSalaryDragLeave(e) {
    e.preventDefault();
    const dropzone = document.getElementById('dropzoneManualSalaryExcel');
    if (dropzone) {
        dropzone.style.background = 'rgba(41, 128, 185, 0.08)';
    }
}

function handleManualSalaryDrop(e) {
    e.preventDefault();
    const dropzone = document.getElementById('dropzoneManualSalaryExcel');
    if (dropzone) {
        dropzone.style.background = 'rgba(41, 128, 185, 0.08)';
    }
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        processManualSalaryFile(files[0]);
    }
}

function handleManualSalaryFileSelect(e) {
    const file = e.target.files[0];
    if (file) {
        processManualSalaryFile(file);
    }
}

function processManualSalaryFile(file) {
    showToast('Reading Excel file...', 'info');
    
    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            
            const firstSheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[firstSheetName];
            
            const jsonRows = XLSX.utils.sheet_to_json(worksheet);
            if (!jsonRows || jsonRows.length === 0) {
                showToast('The uploaded sheet is empty.', 'error');
                return;
            }

            const firstRow = jsonRows[0];
            if (!firstRow.hasOwnProperty('PKWT ID')) {
                showToast('Invalid template: Column "PKWT ID" is missing.', 'error');
                return;
            }

            // Map rows
            const payload = jsonRows.map(row => {
                return {
                    pkwt_id: parseInt(row['PKWT ID']),
                    employee_name: row['Employee Name'],
                    working_days: parseFloat(row['Working Days']) || 0,
                    overtime_hours: parseFloat(row['Overtime Hours']) || 0,
                    early_arrival_hours: parseFloat(row['Early Arrival Hours']) || 0,
                    gaji_pokok: parseFloat(row['Basic Salary (Base Salary)']) || 0,
                    rapel: parseFloat(row['Rapel']) || 0,
                    bonus_tambahan: parseFloat(row['Bonus / Lainnya']) || 0,
                    deduction_absen: parseFloat(row['Absence Deduction (Absence Deduction)']) || 0,
                    deduction_lain: parseFloat(row['Other Deductions']) || 0
                };
            });

            window.uploadedManualSalaryData = payload;
            
            const labelFilename = document.getElementById('labelManualSalaryFilename');
            if (labelFilename) {
                labelFilename.innerText = file.name;
            }
            
            const btnSave = document.getElementById('btnSaveManualSalary');
            if (btnSave) {
                btnSave.disabled = false;
                btnSave.style.cursor = 'pointer';
                btnSave.style.opacity = '1';
            }
            
            showToast('File loaded. Click "Apply & Save Salary" to finish.', 'success');
        } catch (err) {
            console.error(err);
            showToast('Failed to process Excel: ' + err.message, 'error');
        }
    };
    reader.readAsArrayBuffer(file);
}

async function saveManualSalary() {
    if (!window.uploadedManualSalaryData) {
        showToast('Please select or drop a file first.', 'warning');
        return;
    }

    showToast('Uploading and calculating salaries...', 'info');
    
    try {
        const res = await fetch(`${API_URL}/upload-manual-payroll`, {
            method: 'POST',
            headers: { 
                'Content-Type': 'application/json',
                'X-User-Action': typeof currentUser !== 'undefined' && currentUser ? currentUser.username : 'Admin'
            },
            body: JSON.stringify({
                period_id: currentPeriodId,
                client_id: window.selectedClientId,
                rows: window.uploadedManualSalaryData
            })
        });

        if (res.ok) {
            showToast('Salaries uploaded and calculated successfully!', 'success');
            tutupModalUploadManualSalary();
            renderCutOffTable();
            renderReviewGajiTable();
        } else {
            const errData = await res.json();
            const errMsg = errData.messages?.error || errData.message || 'Failed to upload manual salaries.';
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'error',
                    title: 'Upload Failed',
                    text: errMsg,
                    confirmButtonColor: '#3498db'
                });
            } else {
                showToast(errMsg, 'error');
            }
        }
    } catch(err) {
        console.error(err);
        showToast('Failed to save salaries: ' + err.message, 'error');
    }
}

window.downloadSalaryTemplate = downloadSalaryTemplate;
window.bukaModalUploadManualSalary = bukaModalUploadManualSalary;
window.tutupModalUploadManualSalary = tutupModalUploadManualSalary;
window.handleManualSalaryDragOver = handleManualSalaryDragOver;
window.handleManualSalaryDragLeave = handleManualSalaryDragLeave;
window.handleManualSalaryDrop = handleManualSalaryDrop;
window.handleManualSalaryFileSelect = handleManualSalaryFileSelect;
window.saveManualSalary = saveManualSalary;

async function approveGaji(id) {
    // Keep this individual helper but delegate to bulk logic for safety or perform verification
    showToast('Approving salary...', 'info');
    try {
        const res = await fetch(`${API_URL}/approve-payroll/${id}`, { method: 'POST' });
        if (res.ok) { 
            showToast('Salary approved!', 'success'); 
            renderReviewGajiTable(); 
        } else {
            const errData = await res.json();
            const errMsg = errData.messages?.error || errData.message || 'Failed menyetujui gaji!';
            showToast(errMsg, 'error');
        }
    } catch(err) {
        console.error(err);
        showToast('Failed memproses persetujuan.', 'error');
    }
}

// ===== EXPORT =====
async function exportGajiToExcel() {
    if(!currentPeriodId) {
        showToast('Please select a period first.', 'warning');
        return;
    }
    
    showToast('Exporting to Excel...', 'info');
    
    try {
        let url = `${API_URL}/export-payroll/${currentPeriodId}?format=json`;
        if (window.selectedClientId) {
            url += `&client_id=${window.selectedClientId}`;
        }
        
        const response = await fetch(url);
        if (!response.ok) {
            throw new Error('Failed to fetch export data');
        }
        
        const data = await response.json();
        if (!data || data.length === 0) {
            showToast('No payroll data to export.', 'warning');
            return;
        }
        
        // Initialize sums
        let totalGp = 0;
        let totalOt = 0;
        let totalEa = 0;
        let totalRapel = 0;
        let totalLainBonus = 0;
        let totalTotalRevenue = 0;
        let totalPotAbsen = 0;
        let totalBpjsKes = 0;
        let totalBpjsJht = 0;
        let totalBpjsJp = 0;
        let totalPph21 = 0;
        let totalPotLain = 0;
        let totalTotalDeduction = 0;
        let totalThp = 0;
        let totalBpjsKesCo = 0;
        let totalBpjsJhtCo = 0;
        let totalBpjsJpCo = 0;
        let totalBpjsJkkCo = 0;
        let totalBpjsJkmCo = 0;
        let totalTotalBpjsCo = 0;

        // Format the rows for Excel
        const formatted = data.map((row, index) => {
            const dob = [row.tempat_lahir, row.tanggal_lahir].filter(Boolean).join(', ') || '-';
            
            const gp = parseFloat(row.gaji_pokok || 0);
            const ot = parseFloat(row.overtime_pay || 0);
            const ea = parseFloat(row.early_arrival_pay || 0);

            // Parse raw_components for Rapel component
            let rapelVal = 0;
            if (row.raw_components) {
                try {
                    const comps = JSON.parse(row.raw_components);
                    comps.forEach(c => {
                        if (c.nama && c.nama.toLowerCase().includes('rapel')) {
                            rapelVal += parseFloat(c.value || 0);
                        }
                    });
                } catch(e) {
                    console.error('Error parsing raw_components:', e);
                }
            }

            const totalRevenue = parseFloat(row.total_revenue || 0);
            const lainBonus = Math.max(0, totalRevenue - gp - ot - ea - rapelVal);

            const potAbsen = parseFloat(row.deduction_absen || 0);
            const bpjsKes = parseFloat(row.bpjs_kes_karyawan || 0);
            const bpjsJht = parseFloat(row.bpjs_jht_karyawan || 0);
            const bpjsJp = parseFloat(row.bpjs_jp_karyawan || 0);
            const bpjsKaryawan = bpjsKes + bpjsJht + bpjsJp;
            const pph21 = parseFloat(row.pph21 || 0);
            const totalDeduction = parseFloat(row.total_deduction || 0);
            const potLain = Math.max(0, totalDeduction - potAbsen - bpjsKaryawan - pph21);
            const thp = parseFloat(row.take_home_pay || 0);

            const bpjsKesCo = parseFloat(row.bpjs_kes_company || 0);
            const bpjsJhtCo = parseFloat(row.bpjs_jht_company || 0);
            const bpjsJpCo = parseFloat(row.bpjs_jp_company || 0);
            const bpjsJkkCo = parseFloat(row.bpjs_jkk_company || 0);
            const bpjsJkmCo = parseFloat(row.bpjs_jkm_company || 0);
            const totalCoBpjsRow = bpjsKesCo + bpjsJhtCo + bpjsJpCo + bpjsJkkCo + bpjsJkmCo;

            // Accumulate sums
            totalGp += gp;
            totalOt += ot;
            totalEa += ea;
            totalRapel += rapelVal;
            totalLainBonus += lainBonus;
            totalTotalRevenue += totalRevenue;
            totalPotAbsen += potAbsen;
            totalBpjsKes += bpjsKes;
            totalBpjsJht += bpjsJht;
            totalBpjsJp += bpjsJp;
            totalPph21 += pph21;
            totalPotLain += potLain;
            totalTotalDeduction += totalDeduction;
            totalThp += thp;
            totalBpjsKesCo += bpjsKesCo;
            totalBpjsJhtCo += bpjsJhtCo;
            totalBpjsJpCo += bpjsJpCo;
            totalBpjsJkkCo += bpjsJkkCo;
            totalBpjsJkmCo += bpjsJkmCo;
            totalTotalBpjsCo += totalCoBpjsRow;

            return {
                'No': index + 1,
                'Employee Name': row.employee_name || '-',
                'Contract Type': row.tipe_perjanjian || 'PKWT',
                'Company / Client': row.client_name || '-',
                'Division': row.division_name || '-',
                'Department': row.department_name || '-',
                'Position / Role': row.position_name || '-',
                'Join Date': row.join_date || '-',
                'Work Scheme': ((row.work_days_per_week !== undefined && row.work_days_per_week !== null) ? row.work_days_per_week : 5) + ' Days/Wk',
                'Working Days': (row.working_days !== undefined && row.working_days !== null) ? row.working_days : 0,
                'Total Days in Period': (row.total_days_in_period !== undefined && row.total_days_in_period !== null) ? row.total_days_in_period : 0,
                'Payable Days': (row.payable_days !== undefined && row.payable_days !== null) ? row.payable_days : 0,
                'Target Hours': (row.target_work_hours !== undefined && row.target_work_hours !== null) ? row.target_work_hours : ((row.payable_days || 0) * 8),
                'Actual Worked Hours': (row.actual_work_hours !== undefined && row.actual_work_hours !== null) ? row.actual_work_hours : ((row.working_days || 0) * 8),
                'Scheme': row.scheme_name || '-',
                'Status PTKP': row.ptkp_status || 'TK/0',
                'Basic Salary (Base Salary)': Math.round(gp * 100) / 100,
                'Overtime Pay (Overtime)': Math.round(ot * 100) / 100,
                'Early Arrival Pay': Math.round(ea * 100) / 100,
                'Rapel': Math.round(rapelVal * 100) / 100,
                'Bonus / Lainnya': Math.round(lainBonus * 100) / 100,
                'Total Income (Revenue)': Math.round(totalRevenue * 100) / 100,
                'Absence Deduction (Absence Deduction)': Math.round(potAbsen * 100) / 100,
                'BPJS Kes (Karyawan)': Math.round(bpjsKes * 100) / 100,
                'BPJS JHT (Karyawan)': Math.round(bpjsJht * 100) / 100,
                'BPJS JP (Karyawan)': Math.round(bpjsJp * 100) / 100,
                'Tax (PPh21)': Math.round(pph21 * 100) / 100,
                'Other Deductions': Math.round(potLain * 100) / 100,
                'Total Deductions (Deduction)': Math.round(totalDeduction * 100) / 100,
                'Net Salary (Take Home Pay)': Math.round(thp * 100) / 100,
                'BPJS Kes (Perusahaan)': Math.round(bpjsKesCo * 100) / 100,
                'BPJS JHT (Perusahaan)': Math.round(bpjsJhtCo * 100) / 100,
                'BPJS JP (Perusahaan)': Math.round(bpjsJpCo * 100) / 100,
                'BPJS JKK (Perusahaan)': Math.round(bpjsJkkCo * 100) / 100,
                'BPJS JKM (Perusahaan)': Math.round(bpjsJkmCo * 100) / 100,
                'Total BPJS (Perusahaan)': Math.round(totalCoBpjsRow * 100) / 100,
                'Status': row.status_approval || 'Pending',
                'Employee ID (NIK)': row.employ_id || '-',
                'NPWP': row.npwp || '-',
                'Work Location': row.location_name || '-',
                'Place & Date of Birth': dob
            };
        });

        // Push total row
        formatted.push({
            'No': 'TOTAL',
            'Employee Name': '',
            'Contract Type': '',
            'Company / Client': '',
            'Division': '',
            'Department': '',
            'Position / Role': '',
            'Join Date': '',
            'Work Scheme': '',
            'Working Days': '',
            'Total Days in Period': '',
            'Payable Days': '',
            'Target Hours': '',
            'Actual Worked Hours': '',
            'Scheme': '',
            'Status PTKP': '',
            'Basic Salary (Base Salary)': Math.round(totalGp * 100) / 100,
            'Overtime Pay (Overtime)': Math.round(totalOt * 100) / 100,
            'Early Arrival Pay': Math.round(totalEa * 100) / 100,
            'Rapel': Math.round(totalRapel * 100) / 100,
            'Bonus / Lainnya': Math.round(totalLainBonus * 100) / 100,
            'Total Income (Revenue)': Math.round(totalTotalRevenue * 100) / 100,
            'Absence Deduction (Absence Deduction)': Math.round(totalPotAbsen * 100) / 100,
            'BPJS Kes (Karyawan)': Math.round(totalBpjsKes * 100) / 100,
            'BPJS JHT (Karyawan)': Math.round(totalBpjsJht * 100) / 100,
            'BPJS JP (Karyawan)': Math.round(totalBpjsJp * 100) / 100,
            'Tax (PPh21)': Math.round(totalPph21 * 100) / 100,
            'Other Deductions': Math.round(totalPotLain * 100) / 100,
            'Total Deductions (Deduction)': Math.round(totalTotalDeduction * 100) / 100,
            'Net Salary (Take Home Pay)': Math.round(totalThp * 100) / 100,
            'BPJS Kes (Perusahaan)': Math.round(totalBpjsKesCo * 100) / 100,
            'BPJS JHT (Perusahaan)': Math.round(totalBpjsJhtCo * 100) / 100,
            'BPJS JP (Perusahaan)': Math.round(totalBpjsJpCo * 100) / 100,
            'BPJS JKK (Perusahaan)': Math.round(totalBpjsJkkCo * 100) / 100,
            'BPJS JKM (Perusahaan)': Math.round(totalBpjsJkmCo * 100) / 100,
            'Total BPJS (Perusahaan)': Math.round(totalTotalBpjsCo * 100) / 100,
            'Status': '',
            'Employee ID (NIK)': '',
            'NPWP': '',
            'Work Location': '',
            'Place & Date of Birth': ''
        });
        
        // Create SheetJS workbook and worksheet
        const xl = typeof XLSXStyle !== 'undefined' ? XLSXStyle : XLSX;
        const ws = xl.utils.json_to_sheet(formatted);
        
        // Apply Cell Formatting for currency columns (display as "Rp 5.000.000" but keeps numeric values)
        const numericKeys = [
            'Basic Salary (Base Salary)',
            'Overtime Pay (Overtime)',
            'Early Arrival Pay',
            'Rapel',
            'Bonus / Lainnya',
            'Total Income (Revenue)',
            'Absence Deduction (Absence Deduction)',
            'BPJS Kes (Karyawan)',
            'BPJS JHT (Karyawan)',
            'BPJS JP (Karyawan)',
            'Tax (PPh21)',
            'Other Deductions',
            'Total Deductions (Deduction)',
            'Net Salary (Take Home Pay)',
            'BPJS Kes (Perusahaan)',
            'BPJS JHT (Perusahaan)',
            'BPJS JP (Perusahaan)',
            'BPJS JKK (Perusahaan)',
            'BPJS JKM (Perusahaan)',
            'Total BPJS (Perusahaan)'
        ];

        if (ws['!ref']) {
            const range = xl.utils.decode_range(ws['!ref']);
            for (let R = range.s.r + 1; R <= range.e.r; ++R) {
                const isTotalRow = (R === range.e.r);
                for (let C = range.s.c; C <= range.e.c; ++C) {
                    const cell_ref = xl.utils.encode_cell({ r: R, c: C });
                    const cell = ws[cell_ref];
                    if (cell) {
                        if (cell.t === 'n' || typeof cell.v === 'number') {
                            cell.t = 'n'; // Ensure type is number
                            const header_cell_ref = xl.utils.encode_cell({ r: range.s.r, c: C });
                            const header_cell = ws[header_cell_ref];
                            if (header_cell && numericKeys.includes(header_cell.v)) {
                                // Excel Custom Format String: "Rp"#,##0;("-Rp"#,##0);"-"
                                cell.z = '"Rp"#,##0;("-Rp"#,##0);"-"';
                            }
                        }
                        if (isTotalRow && typeof XLSXStyle !== 'undefined') {
                            cell.s = {
                                font: {
                                    bold: true
                                }
                            };
                        }
                    }
                }
            }
        }
        
        const wb = xl.utils.book_new();
        xl.utils.book_append_sheet(wb, ws, "Payroll Report");
        
        // Adjust column widths for clean readability
        const wscols = [
            {wch: 5},   // No
            {wch: 25},  // Company / Client
            {wch: 18},  // Employee ID (NIK)
            {wch: 20},  // Employee Name
            {wch: 15},  // Contract Type
            {wch: 22},  // Place & Date of Birth
            {wch: 20},  // NPWP
            {wch: 15},  // Status PTKP
            {wch: 15},  // Division
            {wch: 15},  // Department
            {wch: 18},  // Position / Role
            {wch: 25},  // Work Location
            {wch: 15},  // Payable Days
            {wch: 25},  // Basic Salary (Base Salary)
            {wch: 20},  // Overtime Pay (Overtime)
            {wch: 20},  // Early Arrival Pay
            {wch: 20},  // Rapel
            {wch: 20},  // Bonus / Lainnya
            {wch: 25},  // Total Income (Revenue)
            {wch: 28},  // Absence Deduction (Absence Deduction)
            {wch: 20},  // BPJS Kes (Karyawan)
            {wch: 20},  // BPJS JHT (Karyawan)
            {wch: 20},  // BPJS JP (Karyawan)
            {wch: 15},  // Tax (PPh21)
            {wch: 18},  // Other Deductions
            {wch: 25},  // Total Deductions (Deduction)
            {wch: 25},  // Net Salary (Take Home Pay)
            {wch: 22},  // BPJS Kes (Perusahaan)
            {wch: 22},  // BPJS JHT (Perusahaan)
            {wch: 22},  // BPJS JP (Perusahaan)
            {wch: 22},  // BPJS JKK (Perusahaan)
            {wch: 22},  // BPJS JKM (Perusahaan)
            {wch: 24},  // Total BPJS (Perusahaan)
            {wch: 12}   // Status
        ];
        ws['!cols'] = wscols;
        
        // Get period name from current period ID
        let periodText = 'Report';
        if (currentPeriodId && window.loadedPeriods) {
            const periodObj = window.loadedPeriods.find(p => p.id == currentPeriodId);
            if (periodObj) {
                periodText = periodObj.nama.trim().replace(/\s+/g, '_');
            }
        }
        
        // Download real .xlsx file
        xl.writeFile(wb, `Payroll_Report_${periodText}.xlsx`);
        showToast('Export successful!', 'success');
    } catch (error) {
        console.error(error);
        showToast('Export failed: ' + error.message, 'danger');
    }
}

// ===== UTILS & MODAL CLOSING =====

// ===== SLIP GAJI =====

async function bukaSlipGaji(id) {
    if (!id) {
        if (typeof showToast === 'function') showToast('ID Payslip tidak valid.', 'error');
        return;
    }

    // Immediately open modal and overlay so the pop-up appears right away!
    const modalSlip = document.getElementById('modalSlip');
    const overlay = document.getElementById('overlay');
    const slipContentEl = document.getElementById('slipContent');

    if (modalSlip) {
        modalSlip.style.display = 'block';
        modalSlip.style.zIndex = '2000';
        const modalBody = modalSlip.querySelector('.modal-body');
        if (modalBody) modalBody.scrollTop = 0;
    }
    if (overlay) {
        overlay.style.display = 'block';
        overlay.style.zIndex = '1999';
    }

    if (slipContentEl) {
        slipContentEl.innerHTML = `
            <div style="text-align: center; padding: 60px 20px; color: #64748b;">
                <i class="fas fa-spinner fa-spin" style="font-size: 36px; color: var(--primary-color, #f39c12); margin-bottom: 15px;"></i>
                <h4 style="margin: 0; font-size: 15px; font-weight: 600; color: #334155;">Memuat Detail Payslip...</h4>
                <p style="margin: 5px 0 0 0; font-size: 12px; color: #94a3b8;">Please wait sebentar</p>
            </div>
        `;
    }

    try {
        const response = await fetch(`${API_URL}/slip-details/${id}`);
        if (!response.ok) {
            const errData = await response.json().catch(() => ({}));
            throw new Error(errData.message || errData.messages?.error || `Failed to fetch data slip (HTTP ${response.status})`);
        }
        const data = await response.json();
        if (!data || !data.info) {
            throw new Error('Data payslip tidak ditemukan.');
        }

        const info = data.info;
        
        // Extract values
        let basicSalary = parseFloat(info.gaji_pokok) || 0;
        let transportAlw = 0;
        let specialAlw = 0;
        let overtime = 0;
        let earlyArrival = 0;
        let taxAllowance = 0;
        let taxAllowanceLabel = '';
        let rapelComponents = [];
        
        if (data.earnings && Array.isArray(data.earnings)) {
            data.earnings.forEach(e => {
                const namaStr = (e && e.nama) ? String(e.nama) : '';
                const nameLower = namaStr.toLowerCase();
                const val = parseFloat(e ? e.value : 0) || 0;
                if (nameLower.includes('gaji pokok') || nameLower.includes('basic salary')) {
                    basicSalary = val;
                } else if (nameLower.includes('transport')) {
                    transportAlw += val;
                } else if (nameLower.includes('overtime') || nameLower.includes('overtime')) {
                    overtime += val;
                } else if (nameLower.includes('early arrival')) {
                    earlyArrival += val;
                } else if (nameLower.includes('allowance tax') || nameLower.includes('tax allowance')) {
                    taxAllowance = val;
                    taxAllowanceLabel = namaStr;
                } else if (nameLower.includes('rapel')) {
                    rapelComponents.push({
                        nama: namaStr,
                        value: val
                    });
                } else {
                    specialAlw += val;
                }
            });
        }
        
        const jkk = parseFloat(info.bpjs_jkk_company) || 0;
        const jkm = parseFloat(info.bpjs_jkm_company) || 0;
        const jhtc = parseFloat(info.bpjs_jht_company) || 0;
        const bpjsCompany = parseFloat(info.bpjs_kes_company) || 0;
        const jpCompany = parseFloat(info.bpjs_jp_company) || 0;
        
        const tax = parseFloat(info.pph21) || 0;
        const jhte = parseFloat(info.bpjs_jht_karyawan) || 0;
        const bpjsEmployee = parseFloat(info.bpjs_kes_karyawan) || 0;
        const jpEmployee = parseFloat(info.bpjs_jp_karyawan) || 0;
        
        let contributionWajib = 0;
        let shopDeduction = 0;
        let absenceDeduction = 0;
        
        if (data.deductions && Array.isArray(data.deductions)) {
            data.deductions.forEach(d => {
                const namaStr = (d && d.nama) ? String(d.nama) : '';
                const nameLower = namaStr.toLowerCase();
                const val = parseFloat(d ? d.value : 0) || 0;
                if (nameLower.includes('jht') || nameLower.includes('kesehatan') || nameLower.includes('bpjs') || nameLower.includes('tax') || nameLower.includes('pph')) {
                    return;
                }
                if (nameLower.includes('absen') || nameLower.includes('absence')) {
                    absenceDeduction += val;
                } else if (nameLower.includes('wajib') || nameLower.includes('contribution')) {
                    contributionWajib += val;
                } else {
                    shopDeduction += val;
                }
            });
        }

        let taxLabel = 'TAX (PPH 21)';
        if (data.deductions && Array.isArray(data.deductions)) {
            const taxItem = data.deductions.find(d => {
                const namaStr = (d && d.nama) ? String(d.nama) : '';
                const nameLower = namaStr.toLowerCase();
                return nameLower.includes('tax') || nameLower.includes('pph') || nameLower.includes('tax');
            });
            if (taxItem && taxItem.nama) {
                taxLabel = taxItem.nama;
            }
        }

        const totalRapel = rapelComponents.reduce((sum, r) => sum + r.value, 0);
        const totalIncome = basicSalary + transportAlw + specialAlw + overtime + earlyArrival + taxAllowance + totalRapel;
        const totalDeduction = contributionWajib + shopDeduction + absenceDeduction + tax + jhte + bpjsEmployee + jpEmployee;
        const totalCompanyBpjs = jkk + jkm + jhtc + bpjsCompany + jpCompany;
        const hasBpjs = jkk > 0 || jkm > 0 || jhtc > 0 || bpjsCompany > 0 || jpCompany > 0 || bpjsEmployee > 0 || jhte > 0 || jpEmployee > 0;
        const brutoTax = totalIncome - absenceDeduction + jkk + jkm;

        if (slipContentEl) {
            slipContentEl.innerHTML = `
            <style>
                #slipContent table tbody tr:hover {
                    background: transparent !important;
                }
                #slipContent table tbody tr:hover td:first-child {
                    box-shadow: none !important;
                }
            </style>
            <div style="font-family: Arial, sans-serif; font-size: 12px; color: #333; line-height: 1.4; padding: 10px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px;">
                    <span style="font-size: 16px; font-weight: bold; text-transform: uppercase;">
                        ${info.client_name || info.nama_klien || 'PT Duta Karya Sukses Nusantara'}
                    </span>
                    ${hasBpjs ? `<a href="javascript:void(0)" onclick="bukaDetailBpjsModal('pkwt', ${id})" class="no-pdf" style="font-size: 12px; color: #f39c12; font-weight: bold; text-decoration: none;"><i class="fas fa-calculator"></i> Detail Calculation BPJS</a>` : ''}
                </div>
                
                <table style="width: 100%; border: none; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">
                    <tr>
                        <td style="width: 50%; vertical-align: top; border: none; padding: 0;">
                            <table style="border: none; border-collapse: collapse;">
                                <tr><td style="padding: 2px 0; font-weight: bold; width: 80px;">PERIOD</td><td style="padding: 2px 0; width: 10px;">:</td><td style="padding: 2px 0;">${info.period_name || info.period || '-'}</td></tr>
                                <tr><td style="padding: 2px 0; font-weight: bold;">NAME</td><td style="padding: 2px 0;">:</td><td style="padding: 2px 0;">${info.employee_name || '-'}</td></tr>
                                <tr><td style="padding: 2px 0; font-weight: bold;">NIK</td><td style="padding: 2px 0;">:</td><td style="padding: 2px 0;">${info.employ_id || info.nik_karyawan || '-'}</td></tr>
                                <tr><td style="padding: 2px 0; font-weight: bold;">PTKP</td><td style="padding: 2px 0;">:</td><td style="padding: 2px 0;">${info.ptkp_status || '-'}</td></tr>
                            </table>
                        </td>
                        <td style="width: 50%; vertical-align: top; border: none; padding: 0;">
                            <table style="border: none; border-collapse: collapse; margin-left: auto;">
                                <tr><td style="padding: 2px 0; font-weight: bold; width: 80px;">POSITION</td><td style="padding: 2px 0; width: 10px;">:</td><td style="padding: 2px 0;">${info.position_name || '-'}</td></tr>
                                <tr><td style="padding: 2px 0; font-weight: bold;">DEPT</td><td style="padding: 2px 0;">:</td><td style="padding: 2px 0;">${info.department_name || '-'}</td></tr>
                                <tr><td style="padding: 2px 0; font-weight: bold;">NPWP</td><td style="padding: 2px 0;">:</td><td style="padding: 2px 0;">${info.npwp || '-'}</td></tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <hr style="border: none; border-top: 1px solid #000; margin-bottom: 15px;">
                
                <table style="width: 100%; border: none; border-collapse: collapse; font-size: 11px; margin-bottom: 15px;">
                    <tr>
                        <!-- Left Column: INCOME -->
                        <td style="width: 50%; vertical-align: top; border: none; padding: 0 15px 0 0;">
                            <div style="font-weight: bold; font-size: 12px; margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 4px;">EARNINGS (PENDAPATAN)</div>
                            <table style="width: 100%; border: none; border-collapse: collapse;">
                                <tr><td style="padding: 4px 0; font-weight: bold; width: 60%; text-transform: uppercase;">BASIC SALARY</td><td style="padding: 4px 0; text-align: right; width: 40%;">${formatRupiah(basicSalary)}</td></tr>
                                <tr><td style="padding: 4px 0; font-weight: bold; text-transform: uppercase;">TRANSPORT ALW</td><td style="padding: 4px 0; text-align: right;">${formatRupiah(transportAlw)}</td></tr>
                                <tr><td style="padding: 4px 0; font-weight: bold; text-transform: uppercase;">SPECIAL ALW</td><td style="padding: 4px 0; text-align: right;">${formatRupiah(specialAlw)}</td></tr>
                                ${rapelComponents.map(r => `<tr><td style="padding: 4px 0; font-weight: bold; text-transform: uppercase;">${r.nama}</td><td style="padding: 4px 0; text-align: right;">${formatRupiah(r.value)}</td></tr>`).join('')}
                                <tr><td style="padding: 4px 0; font-weight: bold; text-transform: uppercase;">OVERTIME</td><td style="padding: 4px 0; text-align: right;">${formatRupiah(overtime)}</td></tr>
                                ${earlyArrival > 0 ? `<tr><td style="padding: 4px 0; font-weight: bold; text-transform: uppercase;">EARLY ARRIVAL</td><td style="padding: 4px 0; text-align: right;">${formatRupiah(earlyArrival)}</td></tr>` : ''}
                                ${taxAllowance > 0 ? `<tr><td style="padding: 4px 0; font-weight: bold; text-transform: uppercase;">${taxAllowanceLabel}</td><td style="padding: 4px 0; text-align: right;">${formatRupiah(taxAllowance)}</td></tr>` : ''}
                            </table>
                        </td>
                        <!-- Vertical Divider -->
                        <td style="width: 1px; border-left: 1px solid #000; padding: 0;"></td>
                        <!-- Right Column: DEDUCTION -->
                        <td style="width: 50%; vertical-align: top; border: none; padding: 0 0 0 15px;">
                            <div style="font-weight: bold; font-size: 12px; margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 4px; color: #e74c3c;">DEDUCTIONS (POTONGAN)</div>
                            <table style="width: 100%; border: none; border-collapse: collapse;">
                                <tr><td style="padding: 4px 0; font-weight: bold; width: 60%; text-transform: uppercase;">MANDATORY CONTRIBUTIONS</td><td style="padding: 4px 0; text-align: right; color: #e74c3c; width: 40%;">${formatRupiah(contributionWajib)}</td></tr>
                                ${absenceDeduction > 0 ? `<tr><td style="padding: 4px 0; font-weight: bold; text-transform: uppercase;">POTONGAN ABSEN</td><td style="padding: 4px 0; text-align: right; color: #e74c3c;">${formatRupiah(absenceDeduction)}</td></tr>` : ''}
                                <tr><td style="padding: 4px 0; font-weight: bold; text-transform: uppercase;">SHOP DEDUCTION</td><td style="padding: 4px 0; text-align: right; color: #e74c3c;">${formatRupiah(shopDeduction)}</td></tr>
                                <tr><td style="padding: 4px 0; font-weight: bold; text-transform: uppercase;">${taxLabel}</td><td style="padding: 4px 0; text-align: right; color: #e74c3c;">${formatRupiah(tax)}</td></tr>
                                <tr><td style="padding: 4px 0; font-weight: bold; text-transform: uppercase;">BPJS JHT (JHTE)</td><td style="padding: 4px 0; text-align: right; color: #e74c3c;">${formatRupiah(jhte)}</td></tr>
                                <tr><td style="padding: 4px 0; font-weight: bold; text-transform: uppercase;">BPJS KESEHATAN (EMP)</td><td style="padding: 4px 0; text-align: right; color: #e74c3c;">${formatRupiah(bpjsEmployee)}</td></tr>
                                <tr><td style="padding: 4px 0; font-weight: bold; text-transform: uppercase;">BPJS JP (EMP)</td><td style="padding: 4px 0; text-align: right; color: #e74c3c;">${formatRupiah(jpEmployee)}</td></tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <hr style="border: none; border-top: 1px solid #000; margin: 15px 0;">
                
                <table style="width: 100%; border: none; border-collapse: collapse; font-size: 11px; margin-bottom: 15px;">
                    <tr>
                        <td style="width: 50%; vertical-align: middle; border: none; padding: 0 15px 0 0;">
                            <table style="width: 100%; border: none; border-collapse: collapse;">
                                <tr><td style="padding: 4px 0; font-weight: bold; width: 60%; text-transform: uppercase;">TOTAL CASH INCOME</td><td style="padding: 4px 0; text-align: right; font-weight: bold; width: 40%;">${formatRupiah(totalIncome)}</td></tr>
                            </table>
                        </td>
                        <td style="width: 1px; border-left: 1px solid #000; padding: 0;"></td>
                        <td style="width: 50%; vertical-align: middle; border: none; padding: 0 0 0 15px;">
                            <table style="width: 100%; border: none; border-collapse: collapse;">
                                <tr><td style="padding: 4px 0; font-weight: bold; width: 60%; text-transform: uppercase;">TOTAL CASH DEDUCTION</td><td style="padding: 4px 0; text-align: right; font-weight: bold; color: #e74c3c; width: 40%;">${formatRupiah(totalDeduction)}</td></tr>
                            </table>
                        </td>
                    </tr>
                </table>
                
                <hr style="border: none; border-top: 1px solid #000; margin: 15px 0;">
                
                <table style="width: 100%; border: none; border-collapse: collapse; font-size: 12px; font-weight: bold; background-color: #fafafa; border: 1px solid #eee; margin-bottom: 15px;">
                    <tr>
                        <td style="padding: 12px 15px; text-transform: uppercase; width: 50%;">TAKE HOME PAY (THP)</td>
                        <td style="padding: 12px 15px; text-align: right; color: var(--success); font-size: 14px; width: 50%;">${formatRupiah(totalIncome - totalDeduction)}</td>
                    </tr>
                </table>

                ${totalCompanyBpjs > 0 ? `
                <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; font-size: 11px;">
                    <div style="font-weight: bold; font-size: 12px; margin-bottom: 6px; color: #475569; border-bottom: 1px dashed #cbd5e1; padding-bottom: 4px;">COMPANY BENEFITS (BPJS DIBAYAR PERUSAHAAN)</div>
                    <table style="width: 100%; border: none; border-collapse: collapse; color: #64748b;">
                        <tr><td style="padding: 3px 0; width: 60%;">BPJS Health (4%)</td><td style="padding: 3px 0; text-align: right; width: 40%; font-weight: 500;">${formatRupiah(bpjsCompany)}</td></tr>
                        <tr><td style="padding: 3px 0;">BPJS JHT (JHTC) (3.7%)</td><td style="padding: 3px 0; text-align: right; font-weight: 500;">${formatRupiah(jhtc)}</td></tr>
                        <tr><td style="padding: 3px 0;">BPJS JP (Company) (2%)</td><td style="padding: 3px 0; text-align: right; font-weight: 500;">${formatRupiah(jpCompany)}</td></tr>
                        <tr><td style="padding: 3px 0;">BPJS JKK (0.24%)</td><td style="padding: 3px 0; text-align: right; font-weight: 500;">${formatRupiah(jkk)}</td></tr>
                        <tr><td style="padding: 3px 0;">BPJS JKM (0.3%)</td><td style="padding: 3px 0; text-align: right; font-weight: 500;">${formatRupiah(jkm)}</td></tr>
                        <tr style="border-top: 1px dashed #cbd5e1; font-weight: bold; color: #475569;"><td style="padding: 4px 0; padding-top: 6px;">Total Allowance BPJS Company</td><td style="padding: 4px 0; padding-top: 6px; text-align: right;">${formatRupiah(totalCompanyBpjs)}</td></tr>
                    </table>
                    <div style="font-size: 9px; color: #94a3b8; margin-top: 6px; line-height: 1.3;"><i class="fas fa-info-circle"></i> Component di atas dibayarkan oleh company langsung ke BPJS Health dan BPJS Employment (tidak memotong atau menambah amount uang tunai yang Anda terima).</div>
                </div>
                ` : ''}

                ${brutoTax > 0 ? `
                <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; font-size: 11px; margin-top: 10px;">
                    <div style="font-weight: bold; font-size: 12px; margin-bottom: 6px; color: #475569; border-bottom: 1px dashed #cbd5e1; padding-bottom: 4px;">DETAIL PERHITUNGAN PAJAK (TAX DETAILS)</div>
                    <table style="width: 100%; border: none; border-collapse: collapse; color: #64748b;">
                        <tr><td style="padding: 3px 0; width: 60%;">Total Gross Salary (Basis PPh 21)</td><td style="padding: 3px 0; text-align: right; width: 40%; font-weight: 500;">${formatRupiah(brutoTax)}</td></tr>
                        <tr>
                            <td style="padding: 3px 0;">Deduction Tax PPh 21</td>
                            <td style="padding: 3px 0; text-align: right; font-weight: 500; color: ${tax >= 0 ? '#e74c3c' : 'var(--success)'};">
                                ${tax >= 0 ? '-' : '+'}${formatRupiah(Math.abs(tax))}
                            </td>
                        </tr>
                        <tr style="border-top: 1px dashed #cbd5e1; font-weight: bold; color: #475569;"><td style="padding: 4px 0; padding-top: 6px;">Gross Salary Setelah Tax</td><td style="padding: 4px 0; padding-top: 6px; text-align: right;">${formatRupiah(brutoTax - tax)}</td></tr>
                    </table>
                    <div style="font-size: 9px; color: #94a3b8; margin-top: 6px; line-height: 1.3;"><i class="fas fa-info-circle"></i> Total Gross Salary di atas merupakan dasar pengenaan tax (DPP) PPh 21 sesuai PMK 168/2023, yang terdiri dari all revenue tunai dikurangi denda/absence deduction, ditambah premi JKK (0.24%) dan JKM (0.3%) yang borne by company (BPJS Health, JHT & JP borne by company tidak masuk bruto TER).</div>
                </div>
                ` : ''}
            </div>
            `;
        }
    } catch (err) {
        console.error('Error loading slip details:', err);
        if (slipContentEl) {
            slipContentEl.innerHTML = `
                <div style="text-align: center; padding: 40px 20px; color: #ef4444;">
                    <i class="fas fa-exclamation-triangle fa-2x" style="margin-bottom: 12px;"></i>
                    <h4 style="margin: 0 0 8px 0; font-size: 14px; font-weight: 700;">Failed Memuat Detail Payslip</h4>
                    <p style="margin: 0; font-size: 12px; color: #64748b;">${err.message || 'An error occurred pada server.'}</p>
                    <button class="btn-cancel" onclick="bukaSlipGaji(${id})" style="margin-top: 15px; padding: 6px 16px; background: #3b82f6; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px;">Coba Lagi</button>
                </div>
            `;
        }
        if (typeof showToast === 'function') {
            showToast('Failed to load detail payslip: ' + (err.message || 'An error occurred server'), 'error');
        }
    }
}

function tutupModalSlip() {
    document.getElementById('modalSlip').style.display = 'none';
    if(document.getElementById('modalDetailBpjs')) {
        document.getElementById('modalDetailBpjs').style.display = 'none';
    }
    document.getElementById('overlay').style.display = 'none';
}

function downloadSlip() {
    const element = document.getElementById('slipContent');
    if (!element) return;
    
    // Physically remove no-pdf elements from DOM temporarily so html2pdf cannot render them
    const noPdfElements = Array.from(element.querySelectorAll('.no-pdf'));
    const placeholders = noPdfElements.map(el => {
        const parent = el.parentNode;
        const next = el.nextSibling;
        parent.removeChild(el);
        return { parent, next, el };
    });
    
    let filename = 'salary_slip.pdf';
    try {
        const htmlText = element.innerHTML;
        // Parse the name and period from the DOM structure
        const nameMatch = htmlText.match(/Name:<\/strong>\s*([^<]+)/i);
        const periodMatch = htmlText.match(/Period:\s*([^<|]+)/i);
        
        let nameStr = 'employee';
        let periodStr = '';
        
        if (nameMatch && nameMatch[1]) {
            nameStr = nameMatch[1].trim().toLowerCase().replace(/[^a-z0-9]/g, '_');
        }
        if (periodMatch && periodMatch[1]) {
            periodStr = '_' + periodMatch[1].trim().toLowerCase().replace(/[^a-z0-9]/g, '_');
        }
        
        filename = `salary_slip_${nameStr}${periodStr}.pdf`;
    } catch(e) {
        console.error('Error generating filename:', e);
    }
    
    // Show loading toast
    showToast('Generating PDF, please wait...', 'info');
    
    // Custom print/download optimizations for html2pdf (A4 Portrait for full-page payslip)
    const opt = {
        margin:       [10, 10, 10, 10], // top, left, bottom, right margins in mm
        filename:     filename,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { 
            scale: 2, 
            useCORS: true, 
            letterRendering: true,
            scrollY: 0,
            scrollX: 0
        },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak:    { mode: ['avoid-all', 'css', 'legacy'] }
    };
    
    html2pdf().set(opt).from(element).save().then(() => {
        // Restore no-pdf elements to the exact DOM position
        placeholders.forEach(({ parent, next, el }) => {
            if (next && next.parentNode === parent) {
                parent.insertBefore(el, next);
            } else {
                parent.appendChild(el);
            }
        });
        showToast('Salary slip downloaded successfully!', 'success');
    }).catch(err => {
        // Restore no-pdf elements to the exact DOM position
        placeholders.forEach(({ parent, next, el }) => {
            if (next && next.parentNode === parent) {
                parent.insertBefore(el, next);
            } else {
                parent.appendChild(el);
            }
        });
        console.error('PDF Generation Error:', err);
        showToast('Failed to download PDF', 'error');
    });
}

    // formPeriod submit handler
    if(document.getElementById('formPeriod')) {
        document.getElementById('formPeriod').addEventListener('submit', async (e) => {
            e.preventDefault();
            const data = {
                client_id: window.selectedClientId ? parseInt(window.selectedClientId) : null,
                month: parseInt(document.getElementById('periodMonth').value),
                year: parseInt(document.getElementById('periodYear').value)
            };
            const res = await fetch(`${API_URL}/periods`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            if (res.ok) {
                tutupAllModal();
                showToast('New period opened successfully!', 'success');
                loadActivePeriod();
            } else {
                showToast('Failed to open new period!', 'error');
            }
        });
    }

    // Form Cut-Off submit handler
    if (document.getElementById('formCutOff')) {
        document.getElementById('formCutOff').addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!currentPeriodId) {
                showToast('Active period not found!', 'error');
                return;
            }
            const data = {
                period_id: parseInt(currentPeriodId),
                pkwt_id: parseInt(document.getElementById('cutoffPkwtId').value),
                hari_kerja: parseInt(document.getElementById('cutoffDayKerja').value) || 0,
                jam_overtime: parseFloat(document.getElementById('cutoffJamOvertime').value) || 0,
                deduction_absensi: parseFloat(document.getElementById('cutoffDeduction').value) || 0,
                bonus_tambahan: parseFloat(document.getElementById('cutoffBonus').value) || 0,
                early_arrival_minutes: (parseInt(document.getElementById('cutoffEarlyArrival').value) || 0) * 60
            };
            try {
                const res = await fetch(`${API_URL}/attendance`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                if (res.ok) {
                    tutupAllModal();
                    renderCutOffTable();
                    showToast('Cut-off data saved successfully!', 'success');
                } else {
                    showToast('Failed to save cut-off data!', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Failed to save cut-off data!', 'error');
            }
        });
    }

    // Dynamic recalculation of CutOff Deduction based on hari_kerja
    const cutoffDayKerjaInput = document.getElementById('cutoffDayKerja');
    if (cutoffDayKerjaInput) {
        cutoffDayKerjaInput.addEventListener('input', () => {
            if (window.currentAbsenceConfig && window.currentAbsenceConfig.nominal_deduction > 0) {
                const nominal = parseFloat(window.currentAbsenceConfig.nominal_deduction) || 0;
                const hariKerja = parseInt(cutoffDayKerjaInput.value) || 0;
                const missingDays = 21 - hariKerja;
                const deductionInput = document.getElementById('cutoffDeduction');
                if (deductionInput) {
                    deductionInput.value = missingDays > 0 ? (missingDays * nominal) : 0;
                }
            }
        });
    }

function bukaModalComponent(schemeId) {
    document.getElementById('modalComponent').style.display = 'block';
    document.getElementById('overlay').style.display = 'block';
    document.getElementById('formComponent').reset();
    document.getElementById('componentSchemeId').value = schemeId;
}

function bukaModalOrg(type, mode, id = null) {
    document.getElementById('modalOrg').style.display = 'block';
    document.getElementById('overlay').style.display = 'block';
    document.getElementById('orgType').value = type;
}

function bukaModalPeriod() {
    // Sinkronkan selectedClientId dari select attendance jika window.selectedClientId belum terisi
    if (!window.selectedClientId) {
        const attClientId = document.getElementById('attendanceClientSelect')?.value;
        if (attClientId) {
            window.selectedClientId = attClientId;
        }
    }

    if (!window.selectedClientId) {
        showToast('Please select a client first!', 'warning');
        return;
    }

    document.getElementById('modalPeriod').style.display = 'block';
    document.getElementById('overlay').style.display = 'block';
    
    // Set default month and year in the form based on current process selection if available, otherwise current date
    const d = new Date();
    let currentMonth = d.getMonth() + 1; // 1-12
    let currentYear = d.getFullYear();

    const processMonthSelect = document.getElementById('processMonthSelect');
    const processYearSelect = document.getElementById('processYearSelect');
    if (processMonthSelect && processMonthSelect.value) {
        currentMonth = parseInt(processMonthSelect.value);
    }
    if (processYearSelect && processYearSelect.value) {
        currentYear = parseInt(processYearSelect.value);
    }
    
    const monthSelect = document.getElementById('periodMonth');
    const yearInput = document.getElementById('periodYear');
    if (monthSelect) monthSelect.value = currentMonth;
    if (yearInput) yearInput.value = currentYear;

    loadActivePeriod();
}
window.bukaModalPeriod = bukaModalPeriod;

function bukaModalCutOff(pkwtId, empName, hariKerja = 22, jamOvertime = 0, deduction = 0, bonus = 0, earlyArrival = 0) {
    document.getElementById('modalCutOff').style.display = 'block';
    document.getElementById('overlay').style.display = 'block';
    document.getElementById('cutoffPkwtId').value = pkwtId;
    document.getElementById('cutoffEmployeeName').value = empName;
    document.getElementById('cutoffDayKerja').value = parseFloat(hariKerja) || 0;
    document.getElementById('cutoffJamOvertime').value = parseFloat(jamOvertime) || 0;
    document.getElementById('cutoffEarlyArrival').value = parseInt(earlyArrival) || 0;
    
    // Calculate default deduction if nominal_deduction is set and no manual deduction exists yet
    let finalDeduction = parseFloat(deduction) || 0;
    if (finalDeduction == 0 && window.currentAbsenceConfig && window.currentAbsenceConfig.nominal_deduction > 0) {
        const nominal = parseFloat(window.currentAbsenceConfig.nominal_deduction) || 0;
        const missingDays = 21 - (parseFloat(hariKerja) || 0);
        if (missingDays > 0) {
            finalDeduction = missingDays * nominal;
        }
    }
    document.getElementById('cutoffDeduction').value = finalDeduction;
    document.getElementById('cutoffBonus').value = parseFloat(bonus) || 0;
}

function exportPayrollExcel() {
    if (!currentPeriodId) {
        showToast('Please select a period first!', 'error');
        return;
    }
    const clientId = window.selectedClientId || '';
    if (!clientId) {
        showToast('Please select a client first!', 'error');
        return;
    }
    
    // Redirect to download endpoint
    window.location.href = `${API_URL}/payroll-export/${currentPeriodId}?client_id=${clientId}`;
}

// Global Closing Handlers
function tutupModal() { tutupAllModal(); }
function tutupModalScheme() { tutupAllModal(); }
function tutupModalComponent() { tutupAllModal(); }
function tutupModalTax() { tutupAllModal(); }

function tutupModalPKWT() { tutupAllModal(); }
function tutupModalPeriod() { tutupAllModal(); }
function tutupModalCutOff() { tutupAllModal(); }

// ===== EXCEL ATTENDANCE UPLOAD =====
let parsedAttendanceData = [];
let parsedDailyLogs = [];

async function bukaModalUploadAbsensi() {
    document.getElementById('modalUploadAbsensi').style.display = 'block';
    document.getElementById('overlay').style.display = 'block';
    
    // Reset file input & UI
    document.getElementById('fileAbsensiExcel').value = '';
    const labelAbs = document.getElementById('labelAbsensiFilename');
    if (labelAbs) labelAbs.innerText = 'No file chosen';
    
    const text1 = document.getElementById('dropzoneAbsensiText1');
    const text2 = document.getElementById('dropzoneAbsensiText2');
    if (text1) text1.innerText = 'Drag & Drop file here';
    if (text2) text2.innerText = 'or click to browse files from your computer';
    
    const zone = document.getElementById('dropzoneAbsensiExcel');
    if (zone) {
        zone.style.borderColor = '#cbd5e1';
        zone.style.backgroundColor = '#ffffff';
    }

    document.getElementById('uploadAbsensiLogs').innerHTML = 'Waiting for file...';
    parsedAttendanceData = [];
    parsedDailyLogs = [];
    window.modalUploadResolvedPeriodId = null;
    
    const saveBtn = document.getElementById('btnSaveUploadedAbsensi');
    if (saveBtn) {
        saveBtn.disabled = true;
        saveBtn.style.cursor = 'not-allowed';
        saveBtn.style.opacity = '0.5';
    }

    // Populate Year dropdown
    const yearSelect = document.getElementById('modalUploadAbsensiYear');
    const currentYear = new Date().getFullYear();
    yearSelect.innerHTML = '';
    for (let y = currentYear - 2; y <= currentYear + 2; y++) {
        yearSelect.innerHTML += `<option value="${y}" ${y === currentYear ? 'selected' : ''}>${y}</option>`;
    }

    // Set current month
    const monthSelect = document.getElementById('modalUploadAbsensiMonth');
    const currentMonth = new Date().getMonth() + 1;
    monthSelect.value = currentMonth;

    // Check main page selectors for pre-selected month/year
    const mainMonth = document.getElementById('attendanceMonthSelect')?.value;
    const mainYear = document.getElementById('attendanceYearSelect')?.value;
    if (mainMonth) monthSelect.value = parseInt(mainMonth);
    if (mainYear) yearSelect.value = parseInt(mainYear);

    // Load clients
    try {
        const res = await fetch(`${API_URL}/clients`);
        const configs = res.ok ? await res.json() : [];
        const clientSelect = document.getElementById('modalUploadAbsensiClient');
        
        if ($.fn.select2 && $(clientSelect).data('select2')) {
            $(clientSelect).select2('destroy');
        }
        
        clientSelect.innerHTML = '<option value=""></option>' + configs.map(c => `
            <option value="${c.id}">${c.nama}</option>
        `).join('');

        if ($.fn.select2) {
            $(clientSelect).select2({
                width: '100%',
                placeholder: "-- Select Client --",
                dropdownParent: $('#modalUploadAbsensi')
            }).off('change.absensiClient').on('change.absensiClient', function() {
                onAbsensiClientChanged();
            });
        }

        // If client is already active in workspace or main filter, auto-select it!
        let activeClientId = null;
        if (window.selectedClientId) {
            activeClientId = window.selectedClientId;
        } else {
            const mainClientSelect = document.getElementById('attendanceClientSelect');
            if (mainClientSelect && mainClientSelect.value) {
                activeClientId = mainClientSelect.value;
            }
        }

        if (activeClientId && configs.some(c => c.id == activeClientId)) {
            if ($.fn.select2) {
                $(clientSelect).prop('disabled', true).val(activeClientId).trigger('change');
            } else {
                clientSelect.disabled = true;
                clientSelect.value = activeClientId;
                onAbsensiClientChanged();
            }
        } else {
            if ($.fn.select2) {
                $(clientSelect).prop('disabled', false).val('').trigger('change');
            } else {
                clientSelect.disabled = false;
                clientSelect.value = '';
            }
        }
    } catch (e) {
        console.error(e);
        showToast('Failed to load clients list', 'error');
    }
}

function tutupModalUploadAbsensi() {
    document.getElementById('modalUploadAbsensi').style.display = 'none';
    document.getElementById('overlay').style.display = 'none';
}

async function onAbsensiClientChanged() {
    const clientId = document.getElementById('modalUploadAbsensiClient').value;
    const month = document.getElementById('modalUploadAbsensiMonth').value;
    const year = document.getElementById('modalUploadAbsensiYear').value;
    
    if (!clientId || !month || !year) {
        window.currentPeriodAttendance = [];
        window.modalUploadResolvedPeriodId = null;
        return;
    }

    if (window.isCheckingPeriods) {
        return;
    }
    window.isCheckingPeriods = true;

    const logsDiv = document.getElementById('uploadAbsensiLogs');
    if (logsDiv && logsDiv.parentElement) {
        logsDiv.parentElement.style.display = 'none';
    }
    logsDiv.innerHTML = 'Loading employees...\n';

    try {
        // Auto-create period if it doesn't exist
        const res = await fetch(`${API_URL}/periods?client_id=${clientId}`);
        let periods = res.ok ? await res.json() : [];

        let matchedPeriod = periods.find(p => parseInt(p.month) == parseInt(month) && parseInt(p.year) == parseInt(year));
        if (!matchedPeriod) {
            const createRes = await fetch(`${API_URL}/periods`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    client_id: parseInt(clientId),
                    month: parseInt(month),
                    year: parseInt(year)
                })
            });
            if (createRes.ok) {
                const refetchRes = await fetch(`${API_URL}/periods?client_id=${clientId}`);
                periods = refetchRes.ok ? await refetchRes.json() : [];
                matchedPeriod = periods.find(p => parseInt(p.month) == parseInt(month) && parseInt(p.year) == parseInt(year));
            }
        }

        window.modalUploadPeriods = periods;
        window.modalUploadResolvedPeriodId = matchedPeriod ? matchedPeriod.id : null;

        if (!matchedPeriod) {
            logsDiv.innerHTML += 'Warning: Could not find or create period.\n';
            window.isCheckingPeriods = false;
            return;
        }

        // Load employees for this period
        const periodId = matchedPeriod.id;
        logsDiv.innerHTML += `Period resolved (ID: ${periodId}). Fetching employees...\n`;
        const empRes = await fetch(`${API_URL}/attendance/${periodId}?client_id=${clientId}`);
        if (!empRes.ok) throw new Error('HTTP ' + empRes.status);
        const data = await empRes.json();
        window.currentPeriodAttendance = data;
        logsDiv.innerHTML += `Loaded ${data.length} active employees.\nReady to select attendance Excel file.\n`;
    } catch (e) {
        console.error(e);
        logsDiv.innerHTML += `Error: ${e.message || e}\n`;
    } finally {
        window.isCheckingPeriods = false;
    }
}

// Keep as stub for backward compatibility
function onAbsensiPeriodChanged() {
    // No-op: period is now auto-resolved from month/year
}

async function downloadAbsensiTemplate() {
    const clientId = document.getElementById('modalUploadAbsensiClient').value;
    const monthVal = document.getElementById('modalUploadAbsensiMonth').value;
    const yearVal = document.getElementById('modalUploadAbsensiYear').value;

    if (!clientId || !monthVal || !yearVal) {
        showToast('Select Client, Month dan Year terlebih dahulu.', 'warning');
        return;
    }

    const employees = window.currentPeriodAttendance || [];
    if (employees.length === 0) {
        showToast('No active employees found to generate template.', 'warning');
        return;
    }

    const month = parseInt(monthVal);
    const year = parseInt(yearVal);
    const daysInMonth = new Date(year, month, 0).getDate();

    // Fetch holidays for the year from Holiday Calendar
    let holidayMap = {};
    try {
        const resHolidays = await fetch(`${API_URL}/holidays?year=${year}`);
        if (resHolidays.ok) {
            const holidayList = await resHolidays.json();
            (Array.isArray(holidayList) ? holidayList : []).forEach(h => {
                const hDate = (h.tanggal || '').substring(0, 10);
                if (hDate) holidayMap[hDate] = h.deskripsi || 'Holiday';
            });
        }
    } catch(e) { console.warn('Could not load holidays for template:', e); }
    
    const dayNames = ["Week", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"];
    const monthNames = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    const templateData = [];

    employees.forEach(emp => {
        const empId = emp.employ_id || emp.nik || '';
        const empName = emp.employee_name || '';
        const workDaysConfig = parseInt(emp.employee_hari_kerja || emp.position_hari_kerja || 5);
        const joinDate = (emp.tgl_masuk || emp.start_contract || '').substring(0, 10);

        for (let d = 1; d <= daysInMonth; d++) {
            const dateObj = new Date(year, month - 1, d);
            const dayOfWeek = dateObj.getDay();
            const dateStr = `${year}-${String(month).padStart(2, '0')}-${String(d).padStart(2, '0')}`;

            // Skip dates prior to employee's join date
            if (joinDate && dateStr < joinDate) {
                continue;
            }

            const dayName = dayNames[dayOfWeek];
            const tglDayStr = `${dateStr} ${dayName}`;

            let jamMasuk = '08:00';
            let jamKeluar = '17:00';

            // Weekend rest day check
            let isRestDay = false;
            if (workDaysConfig === 5) {
                isRestDay = (dayOfWeek === 0 || dayOfWeek === 6);
            } else if (workDaysConfig === 6) {
                isRestDay = (dayOfWeek === 0);
            }

            // Public holiday check from holiday_calendar
            const isPublicHoliday = !!holidayMap[dateStr];

            if (isRestDay || isPublicHoliday) {
                jamMasuk = '';
                jamKeluar = '';
            }

            let status = 'Hadir';
            if (isPublicHoliday) {
                status = 'Off';
            } else if (isRestDay) {
                status = 'Off';
            }

            templateData.push({
                'Employee ID': empId,
                'Nama': empName,
                'Tgl dan Day': tglDayStr,
                'Shift': '',
                'Clock In Time': jamMasuk,
                'Clock Out Time': jamKeluar,
                'Status': status
            });
        }
    });

    const worksheet = XLSX.utils.json_to_sheet(templateData);
    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, worksheet, "Attendance Template");
    
    const max_widths = [15, 25, 20, 12, 12, 12, 12];
    worksheet['!cols'] = max_widths.map(w => ({ wch: w }));

    const filename = `Attendance_Template_${monthNames[month]}_${year}.xlsx`;
    XLSX.writeFile(workbook, filename);
    showToast('Template downloaded successfully!', 'success');
}

function parseAttendanceExcelDate(val) {
    if (val instanceof Date) return val;
    if (typeof val === 'number') {
        return new Date((val - 25569) * 86400 * 1000);
    }
    if (typeof val === 'string') {
        const num = parseFloat(val);
        if (!isNaN(num) && num > 40000 && num < 60000) {
            return new Date((num - 25569) * 86400 * 1000);
        }
        // Handle Indonesian date format: "Rabu 1 Juli 2026", "Senin 6 Juli 2026", etc.
        const monthID = {
            'januari': 0, 'februari': 1, 'maret': 2, 'april': 3,
            'mei': 4, 'juni': 5, 'juli': 6, 'agustus': 7,
            'september': 8, 'oktober': 9, 'november': 10, 'desember': 11
        };
        const hariID = ['senin','selasa','rabu','kamis','jumat','sabtu','minggu'];

        const lower = val.toLowerCase().trim();

        // Try "Day Tgl Month Year" - e.g. "Rabu 1 Juli 2026"
        const matchID = lower.match(/(?:senin|selasa|rabu|kamis|jumat|sabtu|minggu)?\s*(\d{1,2})\s+([a-z]+)\s+(\d{4})/);
        if (matchID) {
            const tgl = parseInt(matchID[1]);
            const month = monthID[matchID[2]];
            const thn = parseInt(matchID[3]);
            if (month !== undefined) {
                return new Date(thn, month, tgl);
            }
        }

        // Try standard formats: remove day names then parse
        let clean = val.replace(/senin|selasa|rabu|kamis|jumat|sabtu|minggu/gi, '').trim();
        // Replace Indonesian month names
        Object.entries(monthID).forEach(([name, idx]) => {
            clean = clean.replace(new RegExp(name, 'gi'), String(idx + 1).padStart(2, '0'));
        });
        clean = clean.replace(/[^0-9\/\-]/g, ' ').trim().replace(/\s+/g, '-');

        let parts = clean.split(/[-/]/);
        if (parts.length === 3) {
            if (parts[0].length === 4) {
                return new Date(parts[0], parts[1] - 1, parts[2]);
            } else {
                return new Date(parts[2], parts[1] - 1, parts[0]);
            }
        }
        let parsed = Date.parse(clean);
        if (!isNaN(parsed)) return new Date(parsed);
    }
    return null;
}

function processAbsensiFile(file) {
    const clientId = document.getElementById('modalUploadAbsensiClient').value;
    const month = document.getElementById('modalUploadAbsensiMonth').value;
    const year = document.getElementById('modalUploadAbsensiYear').value;
    if (!clientId || !month || !year) {
        showToast('Select Client, Month dan Year terlebih dahulu.', 'warning');
        return;
    }

    const text1 = document.getElementById('dropzoneAbsensiText1');
    const text2 = document.getElementById('dropzoneAbsensiText2');
    if (text1) text1.innerText = file.name;
    if (text2) text2.innerText = 'File selected. Click or drag another file to replace.';

    const labelAbs = document.getElementById('labelAbsensiFilename');
    if (labelAbs) labelAbs.innerText = file.name;

    const logsDiv = document.getElementById('uploadAbsensiLogs');
    if (logsDiv && logsDiv.parentElement) {
        logsDiv.parentElement.style.display = 'none';
    }
    logsDiv.innerHTML = "Reading file...\n";

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array', cellDates: false });
            const sheetName = workbook.SheetNames[0];
            const worksheet = workbook.Sheets[sheetName];
            const json = XLSX.utils.sheet_to_json(worksheet, { raw: false, dateNF: 'yyyy-mm-dd' });

            if (json.length === 0) {
                logsDiv.innerHTML += "Error: Excel file is empty.\n";
                return;
            }

            logsDiv.innerHTML += `Parsed ${json.length} rows from sheet "${sheetName}".\n`;
            processParsedAttendance(json);
        } catch (err) {
            console.error(err);
            logsDiv.innerHTML += `Error parsing file: ${err.message || err}\n`;
        }
    };
    reader.readAsArrayBuffer(file);
}

function handleAbsensiFileSelect(event) {
    const file = event.target.files[0];
    if (!file) return;
    processAbsensiFile(file);
}

function processParsedAttendance(rows) {
    const month = document.getElementById('modalUploadAbsensiMonth').value;
    const year = document.getElementById('modalUploadAbsensiYear').value;
    const periodId = window.modalUploadResolvedPeriodId;
    const activePeriod = periodId ? (window.modalUploadPeriods || []).find(p => p.id == periodId) : null;
    const payoutPeriodStr = month && year ? `${month}-${year}` : '';
    const logsDiv = document.getElementById('uploadAbsensiLogs');
    const employees = window.currentPeriodAttendance || [];
    if (employees.length === 0) {
        const errMsg = "Gaji/Absensi tidak bisa diproses karena tidak ada data karyawan aktif untuk Client ini di database. Daftarkan karyawan terlebih dahulu di menu Employee Management.";
        logsDiv.innerHTML += `Error: ${errMsg}\n`;
        showToast(errMsg, 'warning');
        return;
    }

    // Group the Excel rows by Employee ID or Name
    const excelByEmp = {};
    rows.forEach(row => {
        const keys = Object.keys(row);
        const empIdKey = keys.find(k => {
            const clean = k.toLowerCase().replace(/[^a-z0-9]/g, '');
            return clean === 'employeeid' || clean === 'nik' || clean === 'idkaryawan' || clean.includes('employeeid') || clean.includes('idkaryawan');
        });
        const nameKey = keys.find(k => {
            const clean = k.toLowerCase().replace(/[^a-z0-9]/g, '');
            return clean === 'nama' || clean === 'name' || clean === 'employeename' || clean === 'namakaryawan' || clean.includes('name') || clean.includes('nama');
        });
        const tglKey = keys.find(k => {
            const clean = k.toLowerCase().replace(/[^a-z0-9]/g, '');
            return clean === 'tgldanhari' || clean === 'tanggal' || clean === 'date' || clean === 'tgl' || clean.includes('date') || clean.includes('tanggal') || clean.includes('tgl');
        });
        const checkinKey = keys.find(k => {
            const clean = k.toLowerCase().replace(/[^a-z0-9]/g, '');
            return clean === 'jammasuk' || clean === 'checkin' || clean === 'timein' || clean === 'masuk' || clean.includes('masuk') || clean.includes('checkin') || clean.includes('timein');
        });
        const checkoutKey = keys.find(k => {
            const clean = k.toLowerCase().replace(/[^a-z0-9]/g, '');
            return clean === 'jamkeluar' || clean === 'checkout' || clean === 'timeout' || clean === 'keluar' || clean.includes('keluar') || clean.includes('checkout') || clean.includes('timeout');
        });
        const statusKey = keys.find(k => {
            const clean = k.toLowerCase().replace(/[^a-z0-9]/g, '');
            return clean === 'status' || clean.includes('status');
        });
        const shiftKey = keys.find(k => {
            const clean = k.toLowerCase().replace(/[^a-z0-9]/g, '');
            return clean === 'shift' || clean.includes('shift');
        });

        const empId = empIdKey ? String(row[empIdKey] || '').trim() : '';
        const empName = nameKey ? String(row[nameKey] || '').trim() : '';
        const tglVal = tglKey ? row[tglKey] : '';
        
        let checkin = '';
        if (checkinKey) {
            const rawVal = row[checkinKey];
            if (rawVal !== undefined && rawVal !== null) {
                const strVal = String(rawVal).trim();
                const num = parseFloat(strVal);
                if (!isNaN(num) && num >= 0 && num < 1) {
                    const totalMinutes = Math.round(num * 24 * 60);
                    const hours = Math.floor(totalMinutes / 60);
                    const minutes = totalMinutes % 60;
                    checkin = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
                } else {
                    checkin = strVal;
                }
            }
        }
        
        let checkout = '';
        if (checkoutKey) {
            const rawVal = row[checkoutKey];
            if (rawVal !== undefined && rawVal !== null) {
                const strVal = String(rawVal).trim();
                const num = parseFloat(strVal);
                if (!isNaN(num) && num >= 0 && num < 1) {
                    const totalMinutes = Math.round(num * 24 * 60);
                    const hours = Math.floor(totalMinutes / 60);
                    const minutes = totalMinutes % 60;
                    checkout = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`;
                } else {
                    checkout = strVal;
                }
            }
        }
        
        const status = statusKey ? String(row[statusKey] || '').trim() : '';
        const shift = shiftKey ? String(row[shiftKey] || '').trim() : '';

        if (!empId && !empName) return;
        const rowData = {
            dateVal: tglVal,
            checkin: checkin,
            checkout: checkout,
            status: status,
            shift: shift
        };

        if (empId) {
            if (!excelByEmp[empId]) {
                excelByEmp[empId] = [];
            }
            excelByEmp[empId].push(rowData);
        }
        if (empName) {
            const nameKey = empName.toLowerCase();
            if (!excelByEmp[nameKey]) {
                excelByEmp[nameKey] = [];
            }
            excelByEmp[nameKey].push(rowData);
        }
    });

    const finalAttendance = [];
    const dailyLogs = [];
    let logText = "";

    employees.forEach(emp => {
        const empId = String(emp.employ_id || emp.nik || '').trim();
        const empName = String(emp.employee_name || '').trim();
        
        let matchedRows = excelByEmp[empId] || excelByEmp[empName.toLowerCase()];
        if (!matchedRows) {
            const matchingKey = Object.keys(excelByEmp).find(k => 
                k.toLowerCase() === empName.toLowerCase() || 
                empName.toLowerCase().includes(k.toLowerCase()) ||
                k.toLowerCase().includes(empName.toLowerCase())
            );
            if (matchingKey) {
                matchedRows = excelByEmp[matchingKey];
            }
        }

        if (!matchedRows) {
            logText += `⚠️ Employee not found in Excel: "${empName}" (ID: ${empId || 'N/A'}). Will use default/current values.\n`;
            return;
        }

        const workDaysConfig = parseInt(emp.employee_hari_kerja || emp.position_hari_kerja || 5);
        let totalHadir = 0;
        let totalOvertime = 0;
        let totalAlfa = 0;
        
        matchedRows.forEach(row => {
            const dateObj = parseAttendanceExcelDate(row.dateVal);
            if (!dateObj) return;

            const yyyy = dateObj.getFullYear();
            const mm = String(dateObj.getMonth() + 1).padStart(2, '0');
            const dd = String(dateObj.getDate()).padStart(2, '0');
            const formattedDate = `${yyyy}-${mm}-${dd}`;

            // Filter: allow rows in the selected calendar month OR within the active period cutoff range
            if (month && year) {
                const filterMonth = parseInt(month);
                const filterYear = parseInt(year);
                const inCalendarMonth = (dateObj.getMonth() + 1 === filterMonth && dateObj.getFullYear() === filterYear);
                let inPeriodCutoff = false;
                if (activePeriod && activePeriod.start_date && activePeriod.end_date) {
                    const startParts = activePeriod.start_date.split('-');
                    const endParts = activePeriod.end_date.split('-');
                    const startDate = new Date(parseInt(startParts[0]), parseInt(startParts[1]) - 1, parseInt(startParts[2]), 0, 0, 0);
                    const endDate = new Date(parseInt(endParts[0]), parseInt(endParts[1]) - 1, parseInt(endParts[2]), 23, 59, 59);
                    inPeriodCutoff = (dateObj >= startDate && dateObj <= endDate);
                }
                if (!inCalendarMonth && !inPeriodCutoff) {
                    return; // Skip this row - neither in calendar month nor in period cutoff
                }
            }

            // Determine final status - auto-detect Day Off for weekends without data
            const hasCheckin = row.checkin && row.checkin !== 'null';
            const hasCheckout = row.checkout && row.checkout !== 'null';
            let finalStatus = row.status || 'Hadir';

            if (!hasCheckin && !hasCheckout && (!row.status || row.status.toLowerCase().trim() === 'hadir')) {
                const dayOfWeekCheck = dateObj.getDay();
                let isRestDayCheck = false;
                if (workDaysConfig === 5) {
                    isRestDayCheck = (dayOfWeekCheck === 0 || dayOfWeekCheck === 6);
                } else if (workDaysConfig === 6) {
                    isRestDayCheck = (dayOfWeekCheck === 0);
                }
                if (isRestDayCheck) {
                    finalStatus = 'Day Off';
                }
            }

            dailyLogs.push({
                employee_id: emp.employee_id,
                tanggal: formattedDate,
                jam_masuk: hasCheckin ? row.checkin : '',
                jam_keluar: hasCheckout ? row.checkout : '',
                status: finalStatus,
                description: '',
                shift_name: row.shift,
                payout_period: payoutPeriodStr
            });

            const dayOfWeek = dateObj.getDay();
            const statusNorm = finalStatus.toLowerCase().trim();

            let isRestDay = false;
            if (workDaysConfig === 5) {
                isRestDay = (dayOfWeek === 0 || dayOfWeek === 6);
            } else if (workDaysConfig === 6) {
                isRestDay = (dayOfWeek === 0);
            }

            let hasTimes = false;
            let checkinTime = null;
            let checkoutTime = null;

            if (row.checkin && row.checkout && row.checkin !== 'null' && row.checkout !== 'null') {
                const ciParts = row.checkin.split(':');
                const coParts = row.checkout.split(':');
                if (ciParts.length >= 2 && coParts.length >= 2) {
                    hasTimes = true;
                    checkinTime = new Date(2000, 0, 1, parseInt(ciParts[0]), parseInt(ciParts[1]), 0);
                    checkoutTime = new Date(2000, 0, 1, parseInt(coParts[0]), parseInt(coParts[1]), 0);
                    if (checkoutTime < checkinTime) {
                        checkoutTime.setDate(checkoutTime.getDate() + 1);
                    }
                }
            }

            const isPresent = (statusNorm === 'hadir' || statusNorm === 'present' || (statusNorm === '' && hasTimes));
            if (isPresent) {
                totalHadir++;
            }

            // Overtime will be calculated on the backend from daily shift attendance logs


            const isAbsent = (statusNorm === 'alfa' || statusNorm === 'absent' || statusNorm === 'missing' || statusNorm === 'absen');
            if (isAbsent && !isRestDay) {
                totalAlfa++;
            }
        });

        const gajiPokok = parseFloat(emp.gaji_pokok || 0);
        let divider = (workDaysConfig === 5) ? 22 : ((workDaysConfig === 6) ? 26 : 30);
        if (month && year) {
            const filterMonth = parseInt(month);
            const filterYear = parseInt(year);
            const daysInMonth = new Date(filterYear, filterMonth, 0).getDate();
            let stdDays = 0;
            for (let d = 1; d <= daysInMonth; d++) {
                const dateObj = new Date(filterYear, filterMonth - 1, d);
                const dayOfWeek = dateObj.getDay();
                if (workDaysConfig === 5) {
                    if (dayOfWeek !== 0 && dayOfWeek !== 6) {
                        stdDays++;
                    }
                } else if (workDaysConfig === 6) {
                    if (dayOfWeek !== 0) {
                        stdDays++;
                    }
                } else {
                    stdDays++;
                }
            }
            if (stdDays > 0) {
                divider = stdDays;
            }
        }
        const dendaAbsenPerDay = gajiPokok / divider;
        const totalDeduction = totalAlfa * dendaAbsenPerDay;

        logText += `✅ Parsed "${empName}":\n`;
        logText += `   - Work week config: ${workDaysConfig} days (${workDaysConfig === 5 ? 'Sat/Sun off' : workDaysConfig === 6 ? 'Sun off' : 'No off'})\n`;
        logText += `   - Attended: ${totalHadir} Days, Overtime: ${totalOvertime.toFixed(1)} Hours\n`;
        logText += `   - Absent (Alfa): ${totalAlfa} Days => Deduction: ${formatRupiah(totalDeduction)}\n`;

        finalAttendance.push({
            period_id: periodId,
            pkwt_id: emp.pkwt_id,
            hari_kerja: totalHadir,
            jam_overtime: parseFloat(totalOvertime.toFixed(1)),
            deduction_absensi: parseFloat(totalDeduction.toFixed(2)),
            bonus_tambahan: parseFloat(emp.bonus_tambahan || 0)
        });
    });

    parsedAttendanceData = finalAttendance;
    parsedDailyLogs = dailyLogs;
    logsDiv.innerHTML += "\n" + logText;
    logsDiv.innerHTML += `\nSuccess: Ready to apply ${finalAttendance.length} records.`;
    
    if (typeof window.renderInlineAttendanceTable === 'function') {
        window.renderInlineAttendanceTable(rows, false);
    }

    const btn = document.getElementById('btnSaveUploadedAbsensi');
    if (btn) {
        btn.disabled = false;
        btn.style.cursor = 'pointer';
        btn.style.opacity = '1';
    }
}

async function saveUploadedAbsensi() {
    if (parsedAttendanceData.length === 0) {
        showToast('No parsed data to apply.', 'warning');
        return;
    }

    showToast('Applying daily attendance logs...', 'info');
    try {
        // 1. Save daily logs
        const resLogs = await fetch(`${API_URL}/attendance-logs/bulk`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ logs: parsedDailyLogs })
        });

        if (!resLogs.ok) {
            const err = await resLogs.json();
            throw new Error(err.message || 'Failed to save log absensi harian');
        }

        // 2. Save cut-off summary
        showToast('Applying summary attendance records...', 'info');
        const resSummary = await fetch(`${API_URL}/attendance-bulk`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(parsedAttendanceData)
        });

        if (resSummary.ok) {
            showToast('Attendance logs successfully imported!', 'success');
            
            const uploadedClientId = document.getElementById('modalUploadAbsensiClient')?.value;
            const uploadedMonth = document.getElementById('modalUploadAbsensiMonth')?.value;
            const uploadedYear = document.getElementById('modalUploadAbsensiYear')?.value;

            tutupModalUploadAbsensi();

            // Sync main dashboard attendance selectors
            const mainClientSelect = document.getElementById('attendanceClientSelect');
            const mainMonthSelect = document.getElementById('attendanceMonthSelect');
            const mainYearSelect = document.getElementById('attendanceYearSelect');

            if (mainClientSelect && uploadedClientId) {
                mainClientSelect.value = uploadedClientId;
                if (typeof window.syncCustomClientDropdown === 'function') {
                    window.syncCustomClientDropdown();
                }
            }
            if (uploadedMonth && mainMonthSelect) mainMonthSelect.value = parseInt(uploadedMonth);
            if (uploadedYear && mainYearSelect) mainYearSelect.value = parseInt(uploadedYear);

            // Refresh table
            const periodId = window.modalUploadResolvedPeriodId;
            if (typeof currentPeriodId !== 'undefined' && window.currentPeriodId == periodId) {
                renderCutOffTable();
            }
            
            // Refresh daily attendance logs table if the function exists
            if (typeof loadAttendanceLogs === 'function') {
                loadAttendanceLogs();
            }
        } else {
            const err = await resSummary.json();
            showToast(`Failed: ${err.message || 'Error occurred'}`, 'error');
        }
    } catch (err) {
        console.error(err);
        showToast(`Error saving: ${err.message || err}`, 'error');
    }
}

window.bukaModalUploadAbsensi = bukaModalUploadAbsensi;
window.tutupModalUploadAbsensi = tutupModalUploadAbsensi;
window.onAbsensiClientChanged = onAbsensiClientChanged;
window.onAbsensiPeriodChanged = onAbsensiPeriodChanged;
window.downloadAbsensiTemplate = downloadAbsensiTemplate;
window.handleAbsensiFileSelect = handleAbsensiFileSelect;
window.saveUploadedAbsensi = saveUploadedAbsensi;
window.parseExcelDate = parseAttendanceExcelDate;
window.renderCutOffTable = renderCutOffTable;

function handleAbsensiDragOver(event) {
    event.preventDefault();
    const zone = document.getElementById('dropzoneAbsensiExcel');
    if (zone) {
        zone.style.borderColor = '#e67e22';
        zone.style.backgroundColor = 'rgba(243, 156, 18, 0.18)';
    }
}

function handleAbsensiDragLeave(event) {
    event.preventDefault();
    const zone = document.getElementById('dropzoneAbsensiExcel');
    if (zone) {
        zone.style.borderColor = '#f39c12';
        zone.style.backgroundColor = 'rgba(243, 156, 18, 0.08)';
    }
}

function handleAbsensiDrop(event) {
    event.preventDefault();
    const zone = document.getElementById('dropzoneAbsensiExcel');
    if (zone) {
        zone.style.borderColor = '#f39c12';
        zone.style.backgroundColor = 'rgba(243, 156, 18, 0.08)';
    }
    
    if (event.dataTransfer.files && event.dataTransfer.files.length > 0) {
        const file = event.dataTransfer.files[0];
        const fileInput = document.getElementById('fileAbsensiExcel');
        if (fileInput) {
            fileInput.files = event.dataTransfer.files;
        }
        processAbsensiFile(file);
    }
}

// Prevent default drag/drop behavior on window to avoid browser navigating away
window.addEventListener("dragover", function(e) {
    e.preventDefault();
}, false);
window.addEventListener("drop", function(e) {
    e.preventDefault();
}, false);

window.handleAbsensiDragOver = handleAbsensiDragOver;
window.handleAbsensiDragLeave = handleAbsensiDragLeave;
window.handleAbsensiDrop = handleAbsensiDrop;

async function releaseHoldDanApprove(id, employeeName) {
    let confirmed = false;
    if (typeof showConfirm === 'function') {
        confirmed = await showConfirm(
            `Are you sure ingin melepas hold dan menyetujui (Approve) gaji untuk ${employeeName} meskipun kontrak PKWT telah berakhir?`,
            'Konfirmasi Pelepasan Hold',
            'Ya, Lepas & Approve',
            'Cancel',
            'primary'
        );
    } else {
        confirmed = confirm(`Are you sure ingin melepas hold dan menyetujui (Approve) gaji untuk ${employeeName} meskipun kontrak PKWT telah berakhir?`);
    }
    
    if (!confirmed) return;
    
    showToast('Memproses persetujuan...', 'info');
    try {
        const res = await fetch(`${API_URL}/approve-payroll/${id}`, { method: 'POST' });
        if (res.ok) { 
            showToast('Hold successfully dilepas dan gaji disetujui!', 'success'); 
            renderReviewGajiTable(); 
        } else {
            const errData = await res.json();
            const errMsg = errData.messages?.error || errData.message || 'Failed melepas hold!';
            showToast(errMsg, 'error');
        }
    } catch(err) {
        console.error(err);
        showToast('Failed memproses persetujuan.', 'error');
    }
}

window.releaseHoldDanApprove = releaseHoldDanApprove;
window.bukaSlipGaji = bukaSlipGaji;
window.tutupModalSlip = tutupModalSlip;
window.downloadSlip = downloadSlip;

/**
 * Show Modal Detail Breakdown for BPJS & Deductions in Process Payroll
 */
function showProcessBpjsDetailModal(type) {
    const modal = document.getElementById('modalReportKpiDetail');
    const overlay = document.getElementById('overlay');
    if (!modal) return;

    const titleEl = document.getElementById('modalKpiDetailTitle');
    const summaryEl = document.getElementById('modalKpiDetailSummary');
    const theadEl = document.getElementById('modalKpiDetailThead');
    const tbodyEl = document.getElementById('modalKpiDetailTbody');

    const rows = window.lastLoadedProcessGajiData || [];

    if (!rows || rows.length === 0) {
        showToast('No data available kalkulasi gaji yang diproses untuk period ini', 'warning');
        return;
    }

    if (type === 'bpjs_kes_co') {
        titleEl.innerHTML = `<i class="fas fa-building-shield" style="color: #ffffff;"></i> Details Calculation BPJS Health (Company)`;
        
        let totalVal = 0;
        rows.forEach(r => totalVal += parseFloat(r.bpjs_kes_company || 0));
        const avgVal = rows.length > 0 ? (totalVal / rows.length) : 0;

        summaryEl.innerHTML = `
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">TOTAL BPJS KES (COMPANY)</div>
                <div style="font-size: 16px; font-weight: 800; color: #0284c7;">${formatRupiah(totalVal)}</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">TOTAL KARYAWAN</div>
                <div style="font-size: 16px; font-weight: 800; color: #1e293b;">${rows.length} Orang</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">RATA-RATA / KARYAWAN</div>
                <div style="font-size: 16px; font-weight: 800; color: #10b981;">${formatRupiah(avgVal)}</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">TARIF PERUSAHAAN</div>
                <div style="font-size: 16px; font-weight: 800; color: #3b82f6;">4.00% (Cap Rp12 Jt)</div>
            </div>
        `;

        theadEl.innerHTML = `
            <tr style="background: #f1f5f9; color: #475569; font-weight: 700;">
                <th style="padding: 12px; text-align: center; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">No</th>
                <th style="padding: 12px; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Employee Name</th>
                <th style="padding: 12px; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Position / Klien</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Base Salary</th>
                <th style="padding: 12px; text-align: center; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Tarif Company</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Nominal BPJS Kes (Co)</th>
            </tr>
        `;

        let htmlRows = '';
        rows.forEach((r, idx) => {
            const val = parseFloat(r.bpjs_kes_company || 0);
            htmlRows += `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 12px; text-align: center; font-weight: 600;">${idx + 1}</td>
                    <td style="padding: 12px; font-weight: 700;">${escapeHtml(r.employee_name || '-')}</td>
                    <td style="padding: 12px;">${escapeHtml(r.position_name || r.client_name || '-')}</td>
                    <td style="padding: 12px; text-align: right;">${formatRupiah(r.gaji_pokok || 0)}</td>
                    <td style="padding: 12px; text-align: center;"><span style="background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700;">4.00%</span></td>
                    <td style="padding: 12px; text-align: right; font-weight: 800; color: #0284c7;">${formatRupiah(val)}</td>
                </tr>
            `;
        });
        tbodyEl.innerHTML = htmlRows;

    } else if (type === 'bpjs_tk_co') {
        titleEl.innerHTML = `<i class="fas fa-shield-alt" style="color: #ffffff;"></i> Details Calculation BPJS Employment (Company)`;
        
        let sumJht = 0, sumJp = 0, sumJkk = 0, sumJkm = 0, totalVal = 0;
        rows.forEach(r => {
            const jht = parseFloat(r.bpjs_jht_company || 0);
            const jp = parseFloat(r.bpjs_jp_company || 0);
            const jkk = parseFloat(r.bpjs_jkk_company || 0);
            const jkm = parseFloat(r.bpjs_jkm_company || 0);
            sumJht += jht;
            sumJp += jp;
            sumJkk += jkk;
            sumJkm += jkm;
            totalVal += (jht + jp + jkk + jkm);
        });

        summaryEl.innerHTML = `
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">TOTAL BPJS TK (COMPANY)</div>
                <div style="font-size: 16px; font-weight: 800; color: #4f46e5;">${formatRupiah(totalVal)}</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">JHT CO (3.7%)</div>
                <div style="font-size: 16px; font-weight: 800; color: #1e293b;">${formatRupiah(sumJht)}</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">JP CO (2.0%)</div>
                <div style="font-size: 16px; font-weight: 800; color: #10b981;">${formatRupiah(sumJp)}</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">JKK & JKM CO</div>
                <div style="font-size: 16px; font-weight: 800; color: #f59e0b;">${formatRupiah(sumJkk + sumJkm)}</div>
            </div>
        `;

        theadEl.innerHTML = `
            <tr style="background: #f1f5f9; color: #475569; font-weight: 700;">
                <th style="padding: 12px; text-align: center; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">No</th>
                <th style="padding: 12px; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Employee Name</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Base Salary</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">JHT (3.7%)</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">JP (2%)</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">JKK</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">JKM</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Total BPJS TK (Co)</th>
            </tr>
        `;

        let htmlRows = '';
        rows.forEach((r, idx) => {
            const jht = parseFloat(r.bpjs_jht_company || 0);
            const jp = parseFloat(r.bpjs_jp_company || 0);
            const jkk = parseFloat(r.bpjs_jkk_company || 0);
            const jkm = parseFloat(r.bpjs_jkm_company || 0);
            const tot = jht + jp + jkk + jkm;
            htmlRows += `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 12px; text-align: center; font-weight: 600;">${idx + 1}</td>
                    <td style="padding: 12px; font-weight: 700;">${escapeHtml(r.employee_name || '-')}</td>
                    <td style="padding: 12px; text-align: right;">${formatRupiah(r.gaji_pokok || 0)}</td>
                    <td style="padding: 12px; text-align: right;">${formatRupiah(jht)}</td>
                    <td style="padding: 12px; text-align: right;">${formatRupiah(jp)}</td>
                    <td style="padding: 12px; text-align: right;">${formatRupiah(jkk)}</td>
                    <td style="padding: 12px; text-align: right;">${formatRupiah(jkm)}</td>
                    <td style="padding: 12px; text-align: right; font-weight: 800; color: #4f46e5;">${formatRupiah(tot)}</td>
                </tr>
            `;
        });
        tbodyEl.innerHTML = htmlRows;

    } else if (type === 'bpjs_kes_emp') {
        titleEl.innerHTML = `<i class="fas fa-user-shield" style="color: #ffffff;"></i> Details Calculation BPJS Health (Employee)`;
        
        let totalVal = 0;
        rows.forEach(r => totalVal += parseFloat(r.bpjs_kes_karyawan || 0));
        const avgVal = rows.length > 0 ? (totalVal / rows.length) : 0;

        summaryEl.innerHTML = `
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">TOTAL BPJS KES (EMPLOYEE)</div>
                <div style="font-size: 16px; font-weight: 800; color: #10b981;">${formatRupiah(totalVal)}</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">TOTAL KARYAWAN</div>
                <div style="font-size: 16px; font-weight: 800; color: #1e293b;">${rows.length} Orang</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">RATA-RATA / KARYAWAN</div>
                <div style="font-size: 16px; font-weight: 800; color: #3b82f6;">${formatRupiah(avgVal)}</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">TARIF KARYAWAN</div>
                <div style="font-size: 16px; font-weight: 800; color: #10b981;">1.00% (Cap Rp12 Jt)</div>
            </div>
        `;

        theadEl.innerHTML = `
            <tr style="background: #f1f5f9; color: #475569; font-weight: 700;">
                <th style="padding: 12px; text-align: center; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">No</th>
                <th style="padding: 12px; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Employee Name</th>
                <th style="padding: 12px; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Position / Klien</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Base Salary</th>
                <th style="padding: 12px; text-align: center; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Tarif Karyawan</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Nominal BPJS Kes (Emp)</th>
            </tr>
        `;

        let htmlRows = '';
        rows.forEach((r, idx) => {
            const val = parseFloat(r.bpjs_kes_karyawan || 0);
            htmlRows += `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 12px; text-align: center; font-weight: 600;">${idx + 1}</td>
                    <td style="padding: 12px; font-weight: 700;">${escapeHtml(r.employee_name || '-')}</td>
                    <td style="padding: 12px;">${escapeHtml(r.position_name || r.client_name || '-')}</td>
                    <td style="padding: 12px; text-align: right;">${formatRupiah(r.gaji_pokok || 0)}</td>
                    <td style="padding: 12px; text-align: center;"><span style="background: #dcfce7; color: #15803d; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700;">1.00%</span></td>
                    <td style="padding: 12px; text-align: right; font-weight: 800; color: #10b981;">${formatRupiah(val)}</td>
                </tr>
            `;
        });
        tbodyEl.innerHTML = htmlRows;

    } else if (type === 'bpjs_tk_emp') {
        titleEl.innerHTML = `<i class="fas fa-id-card" style="color: #ffffff;"></i> Details Calculation BPJS Employment (Employee)`;
        
        let sumJht = 0, sumJp = 0, totalVal = 0;
        rows.forEach(r => {
            const jht = parseFloat(r.bpjs_jht_karyawan || 0);
            const jp = parseFloat(r.bpjs_jp_karyawan || 0);
            sumJht += jht;
            sumJp += jp;
            totalVal += (jht + jp);
        });

        summaryEl.innerHTML = `
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">TOTAL BPJS TK (EMPLOYEE)</div>
                <div style="font-size: 16px; font-weight: 800; color: #d97706;">${formatRupiah(totalVal)}</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">JHT KARYAWAN (2.0%)</div>
                <div style="font-size: 16px; font-weight: 800; color: #1e293b;">${formatRupiah(sumJht)}</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">JP KARYAWAN (1.0%)</div>
                <div style="font-size: 16px; font-weight: 800; color: #10b981;">${formatRupiah(sumJp)}</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">TOTAL KARYAWAN</div>
                <div style="font-size: 16px; font-weight: 800; color: #3b82f6;">${rows.length} Orang</div>
            </div>
        `;

        theadEl.innerHTML = `
            <tr style="background: #f1f5f9; color: #475569; font-weight: 700;">
                <th style="padding: 12px; text-align: center; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">No</th>
                <th style="padding: 12px; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Employee Name</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Base Salary</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">JHT (2.0%)</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">JP (1.0%)</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Total BPJS TK (Emp)</th>
            </tr>
        `;

        let htmlRows = '';
        rows.forEach((r, idx) => {
            const jht = parseFloat(r.bpjs_jht_karyawan || 0);
            const jp = parseFloat(r.bpjs_jp_karyawan || 0);
            const tot = jht + jp;
            htmlRows += `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 12px; text-align: center; font-weight: 600;">${idx + 1}</td>
                    <td style="padding: 12px; font-weight: 700;">${escapeHtml(r.employee_name || '-')}</td>
                    <td style="padding: 12px; text-align: right;">${formatRupiah(r.gaji_pokok || 0)}</td>
                    <td style="padding: 12px; text-align: right;">${formatRupiah(jht)}</td>
                    <td style="padding: 12px; text-align: right;">${formatRupiah(jp)}</td>
                    <td style="padding: 12px; text-align: right; font-weight: 800; color: #d97706;">${formatRupiah(tot)}</td>
                </tr>
            `;
        });
        tbodyEl.innerHTML = htmlRows;

    } else if (type === 'deduction_absen') {
        titleEl.innerHTML = `<i class="fas fa-user-clock" style="color: #ffffff;"></i> Details Calculation Absence Deductionsi (Deduction)`;
        
        let totalVal = 0;
        let countDeducted = 0;
        rows.forEach(r => {
            const pot = parseFloat(r.deduction_absen || 0);
            totalVal += pot;
            if (pot > 0) countDeducted++;
        });

        summaryEl.innerHTML = `
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">TOTAL ATTENDANCE DEDUCTIONS</div>
                <div style="font-size: 16px; font-weight: 800; color: #dc2626;">${formatRupiah(totalVal)}</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">KARYAWAN TERPOTONG</div>
                <div style="font-size: 16px; font-weight: 800; color: #1e293b;">${countDeducted} / ${rows.length} Orang</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">RUMUS DASAR</div>
                <div style="font-size: 12px; font-weight: 700; color: #475569;">(GP / Total Day) x Unpaid</div>
            </div>
        `;

        theadEl.innerHTML = `
            <tr style="background: #f1f5f9; color: #475569; font-weight: 700;">
                <th style="padding: 12px; text-align: center; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">No</th>
                <th style="padding: 12px; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Employee Name</th>
                <th style="padding: 12px; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Klien / Position</th>
                <th style="padding: 12px; text-align: center; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Working Days / Total</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Base Salary</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Absence Deductionsi</th>
            </tr>
        `;

        let htmlRows = '';
        rows.forEach((r, idx) => {
            const pot = parseFloat(r.deduction_absen || 0);
            const wDays = (r.working_days !== undefined && r.working_days !== null) ? r.working_days : 0;
            const tDays = (r.total_days_in_period !== undefined && r.total_days_in_period !== null) ? r.total_days_in_period : '-';
            htmlRows += `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 12px; text-align: center; font-weight: 600;">${idx + 1}</td>
                    <td style="padding: 12px; font-weight: 700;">${escapeHtml(r.employee_name || '-')}</td>
                    <td style="padding: 12px;">${escapeHtml(r.position_name || r.client_name || '-')}</td>
                    <td style="padding: 12px; text-align: center;"><span style="background: #f1f5f9; padding: 2px 8px; border-radius: 4px; font-weight: 600;">${wDays} / ${tDays} Day</span></td>
                    <td style="padding: 12px; text-align: right;">${formatRupiah(r.gaji_pokok || 0)}</td>
                    <td style="padding: 12px; text-align: right; font-weight: 800; color: ${pot > 0 ? '#dc2626' : '#64748b'};">${formatRupiah(pot)}</td>
                </tr>
            `;
        });
        tbodyEl.innerHTML = htmlRows;

    } else if (type === 'total_net_salary') {
        titleEl.innerHTML = `<i class="fas fa-wallet" style="color: #ffffff;"></i> Details Calculation Total Net Salary (Net Salary / THP)`;
        
        let totalNet = 0;
        let totalGross = 0;
        let totalDeductions = 0;
        rows.forEach(r => {
            const gp = parseFloat(r.gaji_pokok || 0);
            const ot = parseFloat(r.overtime || 0);
            const ea = parseFloat(r.early_arrival || 0);
            const rapel = parseFloat(r.rapel || 0);
            const bonus = parseFloat(r.bonus_lain || 0);
            const gross = gp + ot + ea + rapel + bonus;

            const potAbsen = parseFloat(r.deduction_absen || 0);
            const kesEmp = parseFloat(r.bpjs_kes_karyawan || 0);
            const jhtEmp = parseFloat(r.bpjs_jht_karyawan || 0);
            const jpEmp = parseFloat(r.bpjs_jp_karyawan || 0);
            const pph = parseFloat(r.pph21 || 0);
            const potLain = parseFloat(r.deduction_lain || 0);
            const ded = potAbsen + kesEmp + jhtEmp + jpEmp + pph + potLain;

            const thp = parseFloat(r.take_home_pay !== undefined && r.take_home_pay !== null ? r.take_home_pay : (gross - ded));

            totalGross += gross;
            totalDeductions += ded;
            totalNet += thp;
        });

        const avgNet = rows.length > 0 ? (totalNet / rows.length) : 0;

        summaryEl.innerHTML = `
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">TOTAL GAJI BERSIH (NET)</div>
                <div style="font-size: 16px; font-weight: 800; color: #16a34a;">${formatRupiah(totalNet)}</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">TOTAL KARYAWAN</div>
                <div style="font-size: 16px; font-weight: 800; color: #1e293b;">${rows.length} Orang</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">RATA-RATA / KARYAWAN</div>
                <div style="font-size: 16px; font-weight: 800; color: #3b82f6;">${formatRupiah(avgNet)}</div>
            </div>
            <div style="background: white; padding: 12px 15px; border-radius: 8px; border: 1px solid #e2e8f0;">
                <div style="font-size: 11px; color: #64748b; font-weight: 600;">TOTAL GROSS / DEDUCTION</div>
                <div style="font-size: 12px; font-weight: 700; color: #475569;">${formatRupiah(totalGross)} / <span style="color:#dc2626;">${formatRupiah(totalDeductions)}</span></div>
            </div>
        `;

        theadEl.innerHTML = `
            <tr style="background: #f1f5f9; color: #475569; font-weight: 700;">
                <th style="padding: 12px; text-align: center; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">No</th>
                <th style="padding: 12px; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Employee Name</th>
                <th style="padding: 12px; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Position / Klien</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Base Salary</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Total Earnings</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Total Deductions</th>
                <th style="padding: 12px; text-align: right; position: sticky; top: 0; background: #f1f5f9; z-index: 10; box-shadow: inset 0 -2px 0 #cbd5e1;">Net Salary (THP)</th>
            </tr>
        `;

        let htmlRows = '';
        rows.forEach((r, idx) => {
            const gp = parseFloat(r.gaji_pokok || 0);
            const ot = parseFloat(r.overtime || 0);
            const ea = parseFloat(r.early_arrival || 0);
            const rapel = parseFloat(r.rapel || 0);
            const bonus = parseFloat(r.bonus_lain || 0);
            const gross = gp + ot + ea + rapel + bonus;

            const potAbsen = parseFloat(r.deduction_absen || 0);
            const kesEmp = parseFloat(r.bpjs_kes_karyawan || 0);
            const jhtEmp = parseFloat(r.bpjs_jht_karyawan || 0);
            const jpEmp = parseFloat(r.bpjs_jp_karyawan || 0);
            const pph = parseFloat(r.pph21 || 0);
            const potLain = parseFloat(r.deduction_lain || 0);
            const ded = potAbsen + kesEmp + jhtEmp + jpEmp + pph + potLain;

            const thp = parseFloat(r.take_home_pay !== undefined && r.take_home_pay !== null ? r.take_home_pay : (gross - ded));

            htmlRows += `
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 12px; text-align: center; font-weight: 600;">${idx + 1}</td>
                    <td style="padding: 12px; font-weight: 700;">${escapeHtml(r.employee_name || '-')}</td>
                    <td style="padding: 12px;">${escapeHtml(r.position_name || r.client_name || '-')}</td>
                    <td style="padding: 12px; text-align: right;">${formatRupiah(gp)}</td>
                    <td style="padding: 12px; text-align: right; font-weight: 600; color: #1e293b;">${formatRupiah(gross)}</td>
                    <td style="padding: 12px; text-align: right; font-weight: 600; color: #dc2626;">${formatRupiah(ded)}</td>
                    <td style="padding: 12px; text-align: right; font-weight: 800; color: #16a34a;">${formatRupiah(thp)}</td>
                </tr>
            `;
        });
        tbodyEl.innerHTML = htmlRows;
    }

    if (overlay) overlay.style.display = 'block';
    modal.style.display = 'flex';
    modal.style.flexDirection = 'column';
}

window.showProcessBpjsDetailModal = showProcessBpjsDetailModal;
