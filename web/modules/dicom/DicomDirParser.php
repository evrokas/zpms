<?php
/**
 * Parses a DICOMDIR file to extract the study→series→image hierarchy.
 * Returns a structured tree with file paths for each image.
 * Falls back gracefully — caller should use file-scanning if this returns empty.
 */
class DicomDirParser {

    // DICOMDIR-specific tags
    const TAG_DIRECTORY_RECORD_TYPE  = '00041430';
    const TAG_REF_FILE_ID            = '00041500';
    const TAG_PATIENT_NAME           = '00100010';
    const TAG_PATIENT_ID             = '00100020';
    const TAG_STUDY_INSTANCE_UID     = '0020000D';
    const TAG_STUDY_DATE             = '00080020';
    const TAG_STUDY_DESCRIPTION      = '00081030';
    const TAG_MODALITY               = '00080060';
    const TAG_SERIES_INSTANCE_UID    = '0020000E';
    const TAG_SERIES_NUMBER          = '00200011';
    const TAG_SERIES_DESCRIPTION     = '0008103E';
    const TAG_INSTANCE_NUMBER        = '00200013';

    private $base_dir;
    private $file;

    public function __construct($dicomdir_path) {
        $this->base_dir = dirname($dicomdir_path);
        $this->file = $dicomdir_path;
    }

    public function parse() {
        $records = $this->extractRecords();
        if (empty($records)) return null;
        return $this->buildTree($records);
    }

    private function extractRecords() {
        $dcmdump = $this->findDcmdump();
        if ($dcmdump) {
            return $this->extractWithDcmdump($dcmdump);
        }
        return $this->extractWithParser();
    }

    private function extractWithDcmdump($dcmdump_bin) {
        $cmd = escapeshellarg($dcmdump_bin) . ' +L +P 00041430 +P 00041500'
             . ' +P 00100010 +P 00100020 +P 0020000d +P 00080020'
             . ' +P 00081030 +P 00080060 +P 0020000e +P 00200011'
             . ' +P 0008103e +P 00200013'
             . ' ' . escapeshellarg($this->file) . ' 2>/dev/null';

        exec($cmd, $lines, $code);
        if ($code !== 0 || empty($lines)) return [];

        $records = [];
        $current = [];

        foreach ($lines as $line) {
            if (preg_match('/\(([0-9a-f]{4}),([0-9a-f]{4})\)\s+\w+\s+\[([^\]]*)\]/', $line, $m)) {
                $tag = strtoupper($m[1] . $m[2]);
                $val = trim($m[3]);

                if ($tag === '00041430') {
                    if (!empty($current)) $records[] = $current;
                    $current = ['type' => $val];
                } else {
                    $current[$tag] = $val;
                }
            }
        }
        if (!empty($current)) $records[] = $current;

        return $records;
    }

    private function extractWithParser() {
        $fh = fopen($this->file, 'rb');
        if (!$fh) return [];

        fseek($fh, 128);
        $magic = fread($fh, 4);
        if ($magic !== 'DICM') fseek($fh, 0);

        $records = [];
        $current = [];
        $tags_of_interest = [
            self::TAG_DIRECTORY_RECORD_TYPE, self::TAG_REF_FILE_ID,
            self::TAG_PATIENT_NAME, self::TAG_PATIENT_ID,
            self::TAG_STUDY_INSTANCE_UID, self::TAG_STUDY_DATE,
            self::TAG_STUDY_DESCRIPTION, self::TAG_MODALITY,
            self::TAG_SERIES_INSTANCE_UID, self::TAG_SERIES_NUMBER,
            self::TAG_SERIES_DESCRIPTION, self::TAG_INSTANCE_NUMBER,
        ];

        $size = filesize($this->file);
        while (ftell($fh) < $size && !feof($fh)) {
            $raw = fread($fh, 4);
            if (strlen($raw) < 4) break;

            $group   = unpack('v', substr($raw, 0, 2))[1];
            $element = unpack('v', substr($raw, 2, 2))[1];
            $tag_hex = sprintf('%04X%04X', $group, $element);

            if ($group === 0xFFFE) {
                $len = unpack('V', fread($fh, 4))[1];
                if ($len !== 0xFFFFFFFF && $len > 0) fseek($fh, $len, SEEK_CUR);
                continue;
            }

            $vr = fread($fh, 2);
            if (strlen($vr) < 2) break;

            if (in_array($vr, DicomParser::SHORT_VR)) {
                $length = unpack('v', fread($fh, 2))[1];
            } else {
                fread($fh, 2);
                $length = unpack('V', fread($fh, 4))[1];
            }

            if ($length === 0xFFFFFFFF || $length > 65536) continue;

            $value = ($length > 0) ? trim(fread($fh, $length), " \0") : '';

            if ($tag_hex === self::TAG_DIRECTORY_RECORD_TYPE) {
                if (!empty($current)) $records[] = $current;
                $current = ['type' => $value];
            } elseif (in_array($tag_hex, $tags_of_interest)) {
                $current[$tag_hex] = $value;
            }
        }
        if (!empty($current)) $records[] = $current;

        fclose($fh);
        return $records;
    }

    private function buildTree($records) {
        $result = [
            'patient_name' => '',
            'patient_id'   => '',
            'studies'      => [],
        ];

        $current_study  = null;
        $current_series = null;

        foreach ($records as $rec) {
            $type = strtoupper(trim($rec['type'] ?? ''));

            switch ($type) {
                case 'PATIENT':
                    $result['patient_name'] = $rec[self::TAG_PATIENT_NAME] ?? '';
                    $result['patient_id']   = $rec[self::TAG_PATIENT_ID] ?? '';
                    break;

                case 'STUDY':
                    if ($current_study !== null && $current_series !== null) {
                        $current_study['series'][] = $current_series;
                        $current_series = null;
                    }
                    if ($current_study !== null) {
                        $result['studies'][] = $current_study;
                    }
                    $current_study = [
                        'study_uid'  => $rec[self::TAG_STUDY_INSTANCE_UID] ?? '',
                        'study_date' => $rec[self::TAG_STUDY_DATE] ?? '',
                        'study_desc' => $rec[self::TAG_STUDY_DESCRIPTION] ?? '',
                        'series'     => [],
                    ];
                    break;

                case 'SERIES':
                    if ($current_series !== null && $current_study !== null) {
                        $current_study['series'][] = $current_series;
                    }
                    $current_series = [
                        'series_uid'    => $rec[self::TAG_SERIES_INSTANCE_UID] ?? '',
                        'series_number' => $rec[self::TAG_SERIES_NUMBER] ?? '',
                        'series_desc'   => $rec[self::TAG_SERIES_DESCRIPTION] ?? '',
                        'modality'      => $rec[self::TAG_MODALITY] ?? '',
                        'images'        => [],
                    ];
                    break;

                case 'IMAGE':
                    if ($current_series !== null) {
                        $ref_file = $rec[self::TAG_REF_FILE_ID] ?? '';
                        $rel_path = str_replace('\\', DIRECTORY_SEPARATOR, $ref_file);
                        $abs_path = $this->base_dir . DIRECTORY_SEPARATOR . $rel_path;

                        $current_series['images'][] = [
                            'instance_number' => (int)($rec[self::TAG_INSTANCE_NUMBER] ?? 0),
                            'file_path'       => $abs_path,
                            'rel_path'        => $rel_path,
                        ];
                    }
                    break;
            }
        }

        if ($current_series !== null && $current_study !== null) {
            $current_study['series'][] = $current_series;
        }
        if ($current_study !== null) {
            $result['studies'][] = $current_study;
        }

        return (!empty($result['studies'])) ? $result : null;
    }

    public static function findDicomDir($dir) {
        $candidates = ['DICOMDIR', 'dicomdir', 'Dicomdir'];
        foreach ($candidates as $name) {
            $path = $dir . '/' . $name;
            if (file_exists($path)) return $path;
        }
        $files = scandir($dir);
        foreach ($files as $f) {
            if (strtoupper($f) === 'DICOMDIR') return $dir . '/' . $f;
        }
        return null;
    }

    private function findDcmdump() {
        $paths = ['/usr/bin/dcmdump', '/usr/local/bin/dcmdump'];
        foreach ($paths as $p) {
            if (file_exists($p)) return $p;
        }
        exec('which dcmdump 2>/dev/null', $out, $code);
        return ($code === 0 && !empty($out[0])) ? $out[0] : null;
    }
}
