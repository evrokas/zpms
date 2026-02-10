#!/usr/bin/env php
<?php
/**
 * Cleanup Temp Files Cron Job
 *
 * Deletes temporary files older than the configured TTL (default 24 hours).
 * Run this via cron, e.g.:
 *   0 3 * * * /usr/bin/php /var/www/html/apps/zpms/cron/cleanup-temp-files.php
 */

// Bootstrap the framework
require_once __DIR__ . '/../fw/bootstrap.php';

$fs_config = $kernel->getConfig('file_system') ?? [];

// Initialize the temporary file manager
$temp_manager = new TemporaryFileManager($fs_config);

// Run cleanup
$cleaned = $temp_manager->cleanup();

// Log the result
$timestamp = date('Y-m-d H:i:s');
echo "[$timestamp] Cleaned $cleaned temporary files\n";
