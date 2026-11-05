<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$org = [
    'key'      => 'department',
    'table'    => 'departments',
    'field'    => 'name',
    'fk'       => 'department_id',
    'singular' => 'Department',
    'plural'   => 'Departments',
    'self'     => 'modules/organization/departments.php',
    'defaults' => ['Software Development', 'Web Development', 'Marketing', 'Sales',
                   'Human Resources', 'Finance', 'Administration'],
];
require __DIR__ . '/_manage.php';
