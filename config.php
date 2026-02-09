<?php
declare(strict_types=1);

return [
  'db_host' => getenv('DB_HOST') ?: 'localhost',
  'db_user' => getenv('DB_USER') ?: 'root',
  'db_pass' => getenv('DB_PASS') ?: '',
  'db_name' => getenv('DB_NAME') ?: 'amaranth10',
  'db_port' => (int) (getenv('DB_PORT') ?: 3306),
  'admin_email' => getenv('ADMIN_EMAIL') ?: 'iyjy@duzon119.co.kr',
  'mail_from' => getenv('MAIL_FROM') ?: 'no-reply@localhost',
];
