<?php

/**
 * Translation Administration Module
 *
 * Provides admin interface for managing translations
 */

class TranslationAdminModule extends moduleClass {

    public function __construct($adir, $amodule, $atemplate) {
        parent::__construct($adir, $amodule, $atemplate);
    }

    /**
     * Render the translation admin dashboard
     */
    public function render($params = array()) {
        global $kernel;

        // Get translation statistics
        $stats = dictionaryClassEx::getTranslationStats();

        // Get recent tokens
        $recentTokens = dictionaryClassEx::getRecentTokens(10);

        // Get configuration
        $languages = $kernel->getConfig('languages');

        return $this->RenderTemplate([
            'stats' => $stats,
            'recent_tokens' => $recentTokens,
            'languages' => $languages
        ]);
    }

    /**
     * Get all translations with search and filter
     */
    public function getTranslations($search = '', $language = '', $status = '', $page = 1, $perPage = 50) {
        global $kernel;

        $tokens = dictionaryClassEx::getAllTokens();
        $languages = array_keys($kernel->getConfig('languages'));

        // Filter by search
        if (!empty($search)) {
            $tokens = array_filter($tokens, function($token) use ($search, $languages) {
                foreach ($languages as $lang) {
                    if (stripos($token[$lang], $search) !== false) {
                        return true;
                    }
                }
                return false;
            });
        }

        // Filter by language and status
        if (!empty($language) && !empty($status)) {
            $tokens = array_filter($tokens, function($token) use ($language, $status) {
                $flagColumn = $language . "_set";
                if ($status === 'translated') {
                    return !empty($token[$language]) && $token[$flagColumn] == 1;
                } else if ($status === 'untranslated') {
                    return empty($token[$language]) || $token[$flagColumn] == 0;
                }
                return true;
            });
        }

        // Pagination
        $total = count($tokens);
        $totalPages = ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
        $tokens = array_slice($tokens, $offset, $perPage);

        return [
            'tokens' => $tokens,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages
        ];
    }

    /**
     * Export translations to CSV
     */
    public function exportToCSV($language = 'all') {
        global $kernel;

        $tokens = dictionaryClassEx::getAllTokens();
        $languages = array_keys($kernel->getConfig('languages'));

        // Create CSV header
        if ($language === 'all') {
            $header = array_merge(['ID', 'Token'], $languages);
        } else {
            $header = ['ID', 'Token', 'English', $language];
        }

        // Create CSV content
        $csv = [];
        $csv[] = $header;

        foreach ($tokens as $token) {
            if ($language === 'all') {
                $row = [$token['id']];
                // Use first non-empty language as token
                foreach ($languages as $lang) {
                    if (!empty($token[$lang])) {
                        $row[] = $token[$lang];
                        break;
                    }
                }
                // Add all languages
                foreach ($languages as $lang) {
                    $row[] = $token[$lang] ?? '';
                }
            } else {
                $row = [
                    $token['id'],
                    $token['en'] ?? $token[$languages[0]] ?? '',
                    $token['en'] ?? '',
                    $token[$language] ?? ''
                ];
            }
            $csv[] = $row;
        }

        return $csv;
    }

    /**
     * Import translations from CSV
     */
    public function importFromCSV($file, $language) {
        global $kernel;

        $languages = array_keys($kernel->getConfig('languages'));

        if (!in_array($language, $languages)) {
            return ['error' => 'Invalid language', 'imported' => 0, 'failed' => 0];
        }

        if (!file_exists($file)) {
            return ['error' => 'File not found', 'imported' => 0, 'failed' => 0];
        }

        $imported = 0;
        $failed = 0;

        if (($handle = fopen($file, 'r')) !== FALSE) {
            // Skip header row
            $header = fgetcsv($handle);

            while (($data = fgetcsv($handle)) !== FALSE) {
                // CSV format: ID, Token, English, Language
                // or ID, Token, Language1, Language2, ...
                if (count($data) >= 3) {
                    $token = $data[1]; // Token column
                    $translation = $data[count($data) - 1]; // Last column is the translation

                    if (!empty($token) && !empty($translation)) {
                        if (dictionaryClassEx::updateTranslation($token, $language, $translation)) {
                            $imported++;
                        } else {
                            $failed++;
                        }
                    } else {
                        $failed++;
                    }
                }
            }
            fclose($handle);
        }

        return [
            'imported' => $imported,
            'failed' => $failed
        ];
    }
}

/**
 * Register the translation admin module
 */
function register_translation_admin_module() {
    global $kernel;

    $kernel->registerModule(new TranslationAdminModule(
        __DIR__,
        'translation_admin',
        'translation_admin.zetem'
    ));
}
