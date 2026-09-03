<?php
/**
 * BTW IMF blog admin — configuration.
 * Copy this file to  config.php  and fill it in (admin/setup.php does this for you
 * on first run). config.php is git-ignored and denied over HTTP by admin/.htaccess.
 */
return [
    // Public site origin, no trailing slash. Used for canonical / OG / sitemap URLs.
    'site_url'   => 'https://btwimf.com',

    // password_hash() of your admin password. Generate via admin/setup.php,
    // or:  php -r 'echo password_hash("your-password", PASSWORD_DEFAULT), "\n";'
    'password_hash' => '',

    // Display name stamped on posts unless overridden per-post.
    'default_author' => 'BTW IMF Advisory Team',

    // Optional: e-mail to notify on publish (leave '' to disable).
    'notify_email' => '',
];
