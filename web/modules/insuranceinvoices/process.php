<?php
require 'parser.php';

function process_run() {

    // Config
    $uploadDir = __DIR__ . '/uploads/';

    // Check file
    if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
        die('Error uploading file.');
    }

    // Save file
    $filename = basename($_FILES['pdf']['name']);
    $targetPath = $uploadDir . uniqid() . "_" . $filename;
    if (!move_uploaded_file($_FILES['pdf']['tmp_name'], $targetPath)) {
        die('Failed to save uploaded file.');
    }

    // Parse it
    $data = parseDoctorFeesPDF($targetPath);
    // echopre("parseDoctorFeePDF: data: " . print_r($data, 1));
    if (!$data) die("Could not parse PDF.");

    $patient = $data['patient'];
    $doctor = $data['doctor'];
    $summary = $data['summary'];

    $xr = new insuranceInvoicesClass([
        "patient_code" => $patient["code"],
        "patient_name" => $patient["full_name"],
        "street" => $patient["address"]["street_name"] . " " . $patient["address"]["street_number"],
        "town" => $patient["address"]["town"],
        "postal_code" => $patient["address"]["postal_code"],
        "entry_date" => getDBformattime( $patient["entry_date"] ),
        "exit_date" => getDBformattime($patient["exit_date"]),
        "insurance" => $patient["insurance"],
        
        "doctor_name" => $doctor["name"],
        "specialty" => $doctor["specialty"],
        // "visit_date" => $doctor["visit_date"],
        // "status" => $doctor["status"],
        "contract_amount" => $doctor["total_fee"],
        "insurance_amount" => $doctor["insurance_payment"],
        "patient_amount" => $doctor["patient_payment"],
        "participation" => $doctor["patient_percentage"],

        "total_contract_amount" => $summary["total_fee"],
        "total_insurance_amount" => $summary["insurance_payment"],
        "total_patient_amount" => $summary["patient_payment"],
        "pdf_path" => $targetPath
    ]);

    $xr->insert();

    // echo "File uploaded, parsed, and saved successfully.";
}