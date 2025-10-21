<?php

function getConfig() {

    return [
            'database' => [
            'host' => 'localhost',
            'user' => 'backup_user',
            'pass' => 'secure_password',
            'name' => 'cms_database'
        ],
        
        'table_categories' => [
            'dynamic' => ['users', 'patient_data', 'analytics'],
            'static' => ['content_types', 'taxonomy', 'system_config'],
            'session' => ['sessions', 'auth_tokens']
        ],
        
        'paths' => [
            'core_cms' => '/var/www/cms/core',
            'userspace' => '/var/www/cms/userspace',
            'uploads' => '/var/www/cms/uploads',
            'backup_storage' => '/var/backups/cms'
        ]
    ];
}