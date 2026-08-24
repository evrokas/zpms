<?php
class DicomConverter {

    private $dcmtk_path;
    private $thumb_width;
    private $thumb_quality;
    private $full_format;

    public function __construct($config) {
        $this->dcmtk_path   = rtrim($config['dcmtk_bin_path'], '/');
        $this->thumb_width  = $config['thumbnail_width'] ?? 200;
        $this->thumb_quality = $config['thumb_quality'] ?? 80;
        $this->full_format  = $config['full_image_format'] ?? 'png';
    }

    public function checkDcmtk() {
        $bin = $this->dcmtk_path . '/dcm2pnm';
        if (!file_exists($bin)) {
            exec('which dcm2pnm 2>/dev/null', $out, $code);
            if ($code === 0 && !empty($out[0])) {
                $this->dcmtk_path = dirname($out[0]);
                return true;
            }
            return false;
        }
        return true;
    }

    public function convertToImage($dcm_path, $output_path) {
        $bin = escapeshellarg($this->dcmtk_path . '/dcm2pnm');
        $in  = escapeshellarg($dcm_path);
        $out = escapeshellarg($output_path);

        $format_flag = ($this->full_format === 'jpg') ? '+oj' : '+op';
        $cmd = "$bin $format_flag +Wm $in $out 2>&1";
        exec($cmd, $output, $code);

        if ($code !== 0 || !file_exists($output_path)) {
            $cmd = "$bin $format_flag +Wh $in $out 2>&1";
            exec($cmd, $output, $code);
        }

        return ($code === 0 && file_exists($output_path)) ? $output_path : false;
    }

    public function createThumbnail($source_path, $thumb_path) {
        $info = getimagesize($source_path);
        if (!$info) return false;

        $src_w = $info[0];
        $src_h = $info[1];
        $mime  = $info['mime'];
        $new_w = $this->thumb_width;
        $new_h = (int)round($src_h * ($new_w / $src_w));

        switch ($mime) {
            case 'image/png':  $src = imagecreatefrompng($source_path);  break;
            case 'image/jpeg': $src = imagecreatefromjpeg($source_path); break;
            default: return false;
        }

        $dst = imagecreatetruecolor($new_w, $new_h);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $new_w, $new_h, $src_w, $src_h);

        $dir = dirname($thumb_path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $result = imagejpeg($dst, $thumb_path, $this->thumb_quality);
        imagedestroy($src);
        imagedestroy($dst);
        return $result;
    }

    public function processExam($exam_id, $dcm_dir, $images_base_dir, $db) {
        $series_map = $this->processViaDicomDir($dcm_dir);

        if (empty($series_map)) {
            $series_map = $this->processViaFileScan($dcm_dir);
        }

        foreach ($series_map as $series_uid => $series_data) {
            $tags  = $series_data['tags'];
            $files = $series_data['files'];

            usort($files, function($a, $b) {
                return $a['instance_number'] - $b['instance_number'];
            });

            $stmt = $db->prepare("INSERT INTO dicom_series
                (exam_id, series_uid, series_number, series_desc, modality, frame_count, status)
                VALUES (?, ?, ?, ?, ?, ?, 'converting')");
            $stmt->execute([
                $exam_id, $series_uid,
                $tags['series_number'] ?? null,
                $tags['series_description'] ?? null,
                $tags['modality'] ?? null,
                count($files),
            ]);
            $series_id = $db->lastInsertId();

            $full_dir  = $images_base_dir . '/' . $series_id . '/full';
            $thumb_dir = $images_base_dir . '/' . $series_id . '/thumb';
            mkdir($full_dir, 0755, true);
            mkdir($thumb_dir, 0755, true);

            $rel_path = 'exams/' . $exam_id . '/images/' . $series_id;
            $db->prepare("UPDATE dicom_series SET images_path = ? WHERE id = ?")
               ->execute([$rel_path, $series_id]);

            $frame_num = 0;
            foreach ($files as $file_info) {
                $frame_num++;
                $frame_str = str_pad($frame_num, 4, '0', STR_PAD_LEFT);
                $ext = $this->full_format;

                $full_filename  = $frame_str . '.' . $ext;
                $thumb_filename = $frame_str . '.jpg';
                $full_path  = $full_dir  . '/' . $full_filename;
                $thumb_path = $thumb_dir . '/' . $thumb_filename;

                $converted = $this->convertToImage($file_info['path'], $full_path);
                if ($converted) {
                    $this->createThumbnail($full_path, $thumb_path);
                    $dims = getimagesize($full_path);

                    $stmt = $db->prepare("INSERT INTO dicom_images
                        (series_id, instance_number, sop_instance_uid, dcm_filename,
                         thumb_filename, full_filename, width, height)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([
                        $series_id,
                        $file_info['instance_number'],
                        $file_info['sop_instance_uid'],
                        basename($file_info['path']),
                        $thumb_filename, $full_filename,
                        $dims[0] ?? null, $dims[1] ?? null,
                    ]);
                }
            }

            $db->prepare("UPDATE dicom_series SET status = 'ready' WHERE id = ?")
               ->execute([$series_id]);
        }

        return $series_map;
    }

    private function processViaDicomDir($dcm_dir) {
        $dicomdir_path = DicomDirParser::findDicomDir($dcm_dir);
        if (!$dicomdir_path) return [];

        $parser = new DicomDirParser($dicomdir_path);
        $tree = $parser->parse();
        if (!$tree || empty($tree['studies'])) return [];

        $series_map = [];
        foreach ($tree['studies'] as $study) {
            foreach ($study['series'] as $series) {
                $series_uid = $series['series_uid'] ?: 'unknown_' . count($series_map);

                $valid_images = [];
                foreach ($series['images'] as $img) {
                    if (file_exists($img['file_path'])) {
                        $valid_images[] = [
                            'path'             => $img['file_path'],
                            'instance_number'  => $img['instance_number'],
                            'sop_instance_uid' => '',
                        ];
                    }
                }
                if (empty($valid_images)) continue;

                $series_map[$series_uid] = [
                    'tags' => [
                        'series_instance_uid' => $series['series_uid'],
                        'series_number'       => $series['series_number'],
                        'series_description'  => $series['series_desc'],
                        'modality'            => $series['modality'],
                        'study_instance_uid'  => $study['study_uid'],
                        'study_date'          => $study['study_date'],
                        'study_description'   => $study['study_desc'],
                        'patient_name'        => $tree['patient_name'],
                        'patient_id'          => $tree['patient_id'],
                    ],
                    'files' => $valid_images,
                ];
            }
        }

        return $series_map;
    }

    private function processViaFileScan($dcm_dir) {
        $dcm_files = $this->findDcmFiles($dcm_dir);
        $series_map = [];

        foreach ($dcm_files as $dcm_file) {
            $parser = new DicomParser($dcm_file);
            $tags = $parser->parse();

            $series_uid = $tags['series_instance_uid'] ?? 'unknown_series';
            if (!isset($series_map[$series_uid])) {
                $series_map[$series_uid] = ['tags' => $tags, 'files' => []];
            }
            $series_map[$series_uid]['files'][] = [
                'path'             => $dcm_file,
                'instance_number'  => (int)($tags['instance_number'] ?? 0),
                'sop_instance_uid' => $tags['sop_instance_uid'] ?? '',
            ];
        }

        return $series_map;
    }

    private function findDcmFiles($dir) {
        $result = [];
        $skip_extensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff',
                            'xml', 'txt', 'html', 'pdf', 'csv', 'json',
                            'zip', 'gz', 'tar', 'log', 'ini', 'yml', 'yaml'];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) continue;

            $name = $file->getFilename();
            $ext  = strtolower($file->getExtension());

            if (strtoupper($name) === 'DICOMDIR') continue;
            if (in_array($ext, $skip_extensions)) continue;

            if ($ext === 'dcm') {
                $result[] = $file->getPathname();
            } else {
                if (DicomParser::isDicom($file->getPathname())) {
                    $result[] = $file->getPathname();
                }
            }
        }
        return $result;
    }

    public function findDcmFilesPublic($dir) {
        return $this->findDcmFiles($dir);
    }
}
