<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Patient extends Model
{
    use HasFactory;

    protected $connection = 'sqlsrv';
    protected $table = 'Patient';
    protected $primaryKey = 'MRN';

    public function bed()
    {
        return $this->hasOne(Bed::class, 'MRN', 'MRN');
    }

    /* CONNECTION KE DATABASE SQLSRV UNTUK MENGAMBIL DATA PASIEN. */
    public static function getPatientData()
    {
        $cacheKey = 'patientRanapLocal';

        return Cache::remember($cacheKey, 90, function() {     // Usia cache 90 detik (1,5 menit).
            return DB::connection('sqlsrv')
                -> select("
                WITH Dashboard_CTE AS (
                    SELECT DISTINCT 
                        a.RegistrationNo,
                        r.ServiceUnitName,
                        a.BedCode,
                        a.MedicalNo,
                        a.PatientName,
                        r.CustomerType,
                        r.ChargeClassName,
                        RencanaPulang = 
                            CASE 
                                WHEN cv.PlanDischargeTime IS NULL
                                    THEN CAST(cv.PlanDischargeDate AS VARCHAR) + ' ' + CAST(cv.PlanDischargeTime AS VARCHAR)
                                ELSE CAST(cv.PlanDischargeDate AS VARCHAR) + ' ' + CAST(cv.PlanDischargeTime AS VARCHAR)
                            END,
                        CatRencanaPulang = cv.PlanDischargeNotes,
                        TungguJangdik = 
                            (SELECT TOP 1 TransactionNo 
                            FROM PatientChargesHD
                            WHERE VisitID=cv.VisitID 
                            AND GCTransactionStatus<>'X121^999' 
                            AND GCTransactionStatus IN ('X121^001','X121^002','X121^003')
                            AND HealthcareServiceUnitID IN (82,83,99,138,140)
                            ORDER BY TestOrderID ASC),
                        Keperawatan =
                            (SELECT TOP 1 TransactionNo 
                            FROM PatientChargesHD
                            WHERE VisitID=cv.VisitID 
                            AND GCTransactionStatus<>'X121^999' 
                            AND GCTransactionStatus IN ('X121^001','X121^002','X121^003')
                            AND HealthcareServiceUnitID NOT IN (82,83,99,138,140,101,137)
                            ORDER BY TestOrderID ASC),
                        TungguFarmasi = 
                            (SELECT TOP 1 TransactionNo 
                            FROM PatientChargesHD
                            WHERE VisitID=cv.VisitID 
                            AND GCTransactionStatus<>'X121^999' 
                            AND GCTransactionStatus IN ('X121^001','X121^002','X121^003')
                            AND HealthcareServiceUnitID IN (101,137)
                            ORDER BY TestOrderID ASC),
                        RegistrationStatus = 
                            (SELECT TOP 1 IsLockDownNEW
                            FROM RegistrationStatusLog 
                            WHERE RegistrationID = a.RegistrationID 
                            ORDER BY ID DESC),
                        OutStanding =
                            (SELECT DISTINCT COUNT(GCTransactionStatus) 
                            FROM PatientChargesHD 
                            WHERE VisitID=cv.VisitID 
                            AND GCTransactionStatus IN ('X121^001','X121^002','X121^003')),
                        SelesaiBilling = 
                            (SELECT TOP 1 PrintedDate 
                            FROM ReportPrintLog 
                            WHERE ReportID=7012 
                            AND ReportParameter = CONCAT('RegistrationID = ',r.RegistrationID) 
                            ORDER BY PrintedDate DESC),
                        Keterangan =
                        CASE 
                            WHEN sc.StandardCodeName = '' OR sc.StandardCodeName IS NULL
                                THEN ''
                            ELSE sc.StandardCodeName
                        END
                    FROM vBed a
                    LEFT JOIN vPatient p ON p.MRN = a.MRN
                    LEFT JOIN PatientNotes pn ON pn.MRN = a.MRN
                    LEFT JOIN vRegistration r ON r.RegistrationID = a.RegistrationID
                    LEFT JOIN ConsultVisit cv ON cv.VisitID = r.VisitID
                    LEFT JOIN StandardCode sc ON sc.StandardCodeID = cv.GCPlanDischargeNotesType
                    LEFT JOIN PatientVisitNote pvn ON pvn.VisitID = cv.VisitID 
                        AND pvn.GCNoteType IN ('X312^001', 'X312^002', 'X312^003', 'X312^004', 'X312^005', 'X312^006')
                    WHERE a.IsDeleted = 0 
                    AND a.RegistrationID IS NOT NULL
                    AND cv.PlanDischargeDate IS NOT NULL
                    AND r.GCRegistrationStatus <> 'X020^006' -- Pendaftaran Tidak DiBatalkan
                )
                SELECT 
                    RegistrationNo,
                    ServiceUnitName,
                    BedCode,
                    MedicalNo,
                    PatientName,
                    CustomerType,
                    ChargeClassName,
                    RencanaPulang,
                    CatRencanaPulang,
                    CASE
                        WHEN Keperawatan IS NOT NULL AND TungguJangdik IS NULL AND TungguFarmasi IS NOT NULL THEN 'Tunggu Keperawatan'
                        WHEN TungguJangdik IS NOT NULL AND Keperawatan IS NOT NULL AND TungguFarmasi IS NOT NULL THEN 'Tunggu Jangdik'
                        WHEN TungguFarmasi IS NOT NULL AND Keperawatan IS NULL AND TungguJangdik IS NULL THEN 'Tunggu Farmasi'
                        WHEN RegistrationStatus = 0 AND OutStanding > 0 AND SelesaiBilling IS NULL THEN 'Tunggu Kasir'
                        WHEN RegistrationStatus = 1 AND OutStanding = 0 AND SelesaiBilling IS NULL THEN 'Tunggu Kasir'
                        WHEN RegistrationStatus = 1 AND OutStanding = 0 AND SelesaiBilling IS NOT NULL THEN 'Selesai Kasir'
                    END AS Keterangan
                FROM Dashboard_CTE
            ");
        });
    }
}
