<?php

namespace App\Controllers;

use App\Traits\AuthTrait;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

abstract class BaseController extends Controller
{
    use AuthTrait;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
    }

    /**
     * Hitung dasar upah lembur sebulan dan upah per jam sesuai PP 35/2021 Pasal 32:
     * 1. Dasar pokok = Gaji Pokok + Tunjangan Tetap
     * 2. Aturan 75%: Jika (Gaji Pokok + Tunjangan Tetap) < 75% dari Total Upah (termasuk tunjangan tidak tetap),
     *    maka dasar upah lembur adalah 75% dari Total Upah.
     * 3. Batas Minimal: Jika dasar upah lembur lebih kecil dari UMK/UMP wilayah, gunakan UMK/UMP.
     * 4. Upah per jam = Dasar Upah Lembur / 173 (atau divisor custom).
     */
    protected function hitungDasarUpahLembur(
        float $gajiPokok,
        float $tunjanganTetap = 0.0,
        float $tunjanganTidakTetap = 0.0,
        float $minimumWage = 0.0,
        float $divisor = 173.0
    ): array {
        $upahTetap = max(0.0, $gajiPokok + $tunjanganTetap);
        $totalUpah = max(0.0, $upahTetap + $tunjanganTidakTetap);

        // Aturan 75% PP 35/2021 Pasal 32 ayat (2)
        if ($totalUpah > 0 && ($upahTetap < 0.75 * $totalUpah)) {
            $dasarUpah = 0.75 * $totalUpah;
            $ruleApplied = '75_percent_total_wage';
        } else {
            $dasarUpah = $upahTetap;
            $ruleApplied = 'fixed_wage';
        }

        // Minimal UMK / UMP PP 35/2021 Pasal 32 ayat (3)
        if ($minimumWage > 0 && $dasarUpah < $minimumWage) {
            $dasarUpah = $minimumWage;
            $ruleApplied = 'minimum_wage';
        }

        $standardDivisor = ($divisor > 0) ? $divisor : 173.0;
        $upahPerJam = ($dasarUpah > 0) ? ($dasarUpah / $standardDivisor) : 0.0;

        return [
            'dasar_upah'   => $dasarUpah,
            'upah_per_jam' => $upahPerJam,
            'upah_tetap'   => $upahTetap,
            'total_upah'   => $totalUpah,
            'divisor'      => $standardDivisor,
            'rule_applied' => $ruleApplied
        ];
    }
}

