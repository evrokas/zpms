<?php
/**
 * Lightweight DICOM tag parser — reads essential metadata from .dcm file headers.
 * No external dependencies. Handles Explicit & Implicit VR Little Endian.
 */
class DicomParser {

    const TAGS = [
        '00080016' => 'sop_class_uid',
        '00080018' => 'sop_instance_uid',
        '00080020' => 'study_date',
        '00080030' => 'study_time',
        '00080050' => 'accession_number',
        '00080060' => 'modality',
        '00081030' => 'study_description',
        '0008103E' => 'series_description',
        '00100010' => 'patient_name',
        '00100020' => 'patient_id',
        '00100030' => 'patient_birth_date',
        '00100040' => 'patient_sex',
        '0020000D' => 'study_instance_uid',
        '0020000E' => 'series_instance_uid',
        '00200011' => 'series_number',
        '00200013' => 'instance_number',
        '00280010' => 'rows',
        '00280011' => 'columns',
        '00280100' => 'bits_allocated',
        '00280004' => 'photometric_interpretation',
    ];

    const SHORT_VR = [
        'AE','AS','AT','CS','DA','DS','DT','FL','FD','IS',
        'LO','LT','PN','SH','SL','SS','ST','TM','UI','UL','US'
    ];

    private $file;
    private $fh;
    private $explicit_vr = true;

    public function __construct($filepath) {
        $this->file = $filepath;
    }

    public function parse() {
        $result = [];
        $this->fh = fopen($this->file, 'rb');
        if (!$this->fh) return $result;

        fseek($this->fh, 128);
        $magic = fread($this->fh, 4);
        if ($magic !== 'DICM') {
            fseek($this->fh, 0); // try without preamble
        }

        $max_offset = min(filesize($this->file), 65536);

        while (ftell($this->fh) < $max_offset && !feof($this->fh)) {
            $tag_data = $this->readTag();
            if ($tag_data === false) break;

            list($tag_hex, $value, $vr) = $tag_data;

            if ($tag_hex === '00020010') {
                // Transfer Syntax UID — detect implicit VR
                $this->explicit_vr = (trim($value, " \0") !== '1.2.840.10008.1.2');
            }
            if ($tag_hex === '7FE00010') break; // pixel data — stop

            $tag_upper = strtoupper($tag_hex);
            if (isset(self::TAGS[$tag_upper])) {
                $result[self::TAGS[$tag_upper]] = trim($value, " \0");
            }
        }

        fclose($this->fh);
        return $result;
    }

    private function readTag() {
        $raw = fread($this->fh, 4);
        if (strlen($raw) < 4) return false;

        $group   = unpack('v', substr($raw, 0, 2))[1];
        $element = unpack('v', substr($raw, 2, 2))[1];
        $tag_hex = sprintf('%04X%04X', $group, $element);

        $is_meta  = ($group === 0x0002);
        $explicit = $is_meta || $this->explicit_vr;
        $vr = '';

        if ($explicit) {
            $vr = fread($this->fh, 2);
            if (strlen($vr) < 2) return false;

            if (in_array($vr, self::SHORT_VR)) {
                $length = unpack('v', fread($this->fh, 2))[1];
            } else {
                fread($this->fh, 2); // reserved
                $length = unpack('V', fread($this->fh, 4))[1];
            }
        } else {
            $length = unpack('V', fread($this->fh, 4))[1];
        }

        if ($length === 0xFFFFFFFF || $length > 65536) return false;

        $value = ($length > 0) ? fread($this->fh, $length) : '';

        // Unpack numeric VRs
        if ($vr === 'US' && $length === 2)     $value = (string)unpack('v', $value)[1];
        elseif ($vr === 'UL' && $length === 4) $value = (string)unpack('V', $value)[1];
        elseif ($vr === 'SS' && $length === 2) $value = (string)unpack('s', $value)[1];
        elseif ($vr === 'SL' && $length === 4) $value = (string)unpack('l', $value)[1];

        return [$tag_hex, $value, $vr];
    }

    public static function isDicom($filepath) {
        $fh = fopen($filepath, 'rb');
        if (!$fh) return false;
        fseek($fh, 128);
        $magic = fread($fh, 4);
        fclose($fh);
        if ($magic === 'DICM') return true;
        // Fallback: check for group 0002 or 0008 at byte 0
        $fh = fopen($filepath, 'rb');
        $first = fread($fh, 2);
        fclose($fh);
        $group = unpack('v', $first)[1];
        return ($group === 0x0002 || $group === 0x0008);
    }
}
