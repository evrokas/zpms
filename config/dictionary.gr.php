<?php
/*
 * Greek translations for the terms this app's templates look up with t()
 * (the `dictionary` table). The array key is the English token exactly as it
 * is written in the template; the value is its Greek text.
 *
 * Loaded into the database by `php bin/import_dictionary.php`. That only
 * fills in terms nobody has translated yet -- a translation someone edited in
 * the dictionary table (gr_set = 1) is never overwritten -- so it is safe to
 * run on every deploy.
 *
 * Add a term here whenever a template gains a t('...') call, and add a
 * `dictionary.<lang>.php` file like this one for any further language.
 */
return [
    'Back to patient list' => 'Πίσω στη λίστα ασθενών',
];
