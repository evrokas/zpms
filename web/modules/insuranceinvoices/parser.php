<?php

function parsePdfText($pdfText) {
    $result = [
        'patient' => [],
        'doctor' => [],
        'summary' => []
    ];

    // Extract patient information
    $patientCodePattern = '/Κωδ. Εισαγωγής\s*:\s*(\d+)/';
    preg_match($patientCodePattern, $pdfText, $codeMatches);
    if (isset($codeMatches[1])) {
        $result['patient']['code'] = trim($codeMatches[1]);
    }

    $patientPattern = '/Ονοματεπώνυμο ασθενή:\s*(.*?)\s*του/s';
    preg_match($patientPattern, $pdfText, $patientMatches);
    if (isset($patientMatches[1])) {
        $result['patient']['full_name'] = trim($patientMatches[1]);
    }

    // Extract and parse address
    $addressPattern = '/Διεύθυνση\s*:\s*(.*?)\s*-\s*Περιοχή:\s*(.*?)\s*-\s*Τ\.Κ\.:\s*(\d+)/s';
    preg_match($addressPattern, $pdfText, $addressMatches);
    
    if (isset($addressMatches[1])) {
        // Parse street name and number
        $streetParts = explode(' ', trim($addressMatches[1]));
        $streetNumber = array_pop($streetParts);
        $streetName = implode(' ', $streetParts);
        
        $result['patient']['address'] = [
            'street_name' => $streetName,
            'street_number' => $streetNumber,
            'town' => isset($addressMatches[2]) ? trim($addressMatches[2]) : null,
            'postal_code' => isset($addressMatches[3]) ? trim($addressMatches[3]) : null
        ];
    }

    $insurancePattern = '/Ασφάλεια\s*:\s*(.*?)\s*\(/s';
    preg_match($insurancePattern, $pdfText, $insuranceMatches);
    if (isset($insuranceMatches[1])) {
        $result['patient']['insurance'] = trim($insuranceMatches[1]);
    }

    $entryDatePattern = '/Ημ\/νία Εισαγωγής\s*:\s*(.*?)\s/';
    preg_match($entryDatePattern, $pdfText, $entryDateMatches);
    if (isset($entryDateMatches[1])) {
        $entryDateMatches[1] = str_replace('/','-',$entryDateMatches[1]);
        $result['patient']['entry_date'] = trim($entryDateMatches[1]);
    }

    $exitDatePattern = '/Ημ\/νία Εξόδου\s*:\s*(.*?)\s/';
    preg_match($exitDatePattern, $pdfText, $exitDateMatches);
    if (isset($exitDateMatches[1])) {
        $exitDateMatches[1] = str_replace('/','-',$exitDateMatches[1]);
        $result['patient']['exit_date'] = trim($exitDateMatches[1]);
    }

    // Function to format monetary values
    function formatEuroAmount($amount) {
        // Remove all non-digit characters except decimal point
        $cleaned = preg_replace('/[^0-9.]/', '', $amount);
        // Convert to float
        $floatValue = (float)$cleaned;
        // Format with thousand separators and 2 decimal places
        return number_format($floatValue, 2, '.', '');
    }

    // Extract doctor ΡΟΚΑΣ ΕΥΑΓΓΕΛΟΣ information
    $doctorPattern = '/ΡΟΚΑΣ ΕΥΑΓΓΕΛΟΣ.*?Τελική Έγκριση\s*([\d,.]+)\s*([\d,.]+)\s*([\d,.]+)\s*([\d,.]+%)/s';
    preg_match($doctorPattern, $pdfText, $doctorMatches);
    if (isset($doctorMatches[1])) {
        $result['doctor'] = [
            'name' => 'ΡΟΚΑΣ ΕΥΑΓΓΕΛΟΣ',
            'specialty' => 'ΝΕΥΡΟΧΕΙΡΟΥΡΓΟΣ',
            'total_fee' => formatEuroAmount($doctorMatches[1]),
            'insurance_payment' => formatEuroAmount($doctorMatches[2]),
            'patient_payment' => formatEuroAmount($doctorMatches[3]),
            'patient_percentage' => $doctorMatches[4]
        ];
    }

    // Extract summary for ΡΟΚΑΣ ΕΥΑΓΓΕΛΟΣ
    $summaryPattern = '/ΡΟΚΑΣ ΕΥΑΓΓΕΛΟΣ.*?Σύνολα\s*:\s*([\d,.]+)\s*([\d,.]+)\s*([\d,.]+)/s';
    preg_match($summaryPattern, $pdfText, $summaryMatches);
    if (isset($summaryMatches[1])) {
        $result['summary'] = [
            'total_fee' => formatEuroAmount($summaryMatches[1]),
            'insurance_payment' => formatEuroAmount($summaryMatches[2]),
            'patient_payment' => formatEuroAmount($summaryMatches[3])
        ];
    }

    return $result;
}





function parseDoctorFeesPDF($pdfPath)
{
    $text = shell_exec("pdftotext '$pdfPath' -layout -");
    if (!$text) return false;

    $text = mb_convert_encoding($text, 'UTF-8', 'auto');
    $text = preg_replace('/\s+/', ' ', $text);
    // echopre("converted file: " . print_r($text, 1));

    $res = parsePdfText($text);
    return $res;
}
