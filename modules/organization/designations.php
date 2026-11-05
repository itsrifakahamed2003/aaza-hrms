<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$org = [
    'key'      => 'designation',
    'table'    => 'designations',
    'field'    => 'title',
    'fk'       => 'designation_id',
    'singular' => 'Designation',
    'plural'   => 'Designations',
    'self'     => 'modules/organization/designations.php',
    'defaults' => ['Intern', 'Junior Developer', 'Software Developer', 'Senior Developer',
                   'Team Leader', 'Project Manager', 'HR Officer'],
];
require __DIR__ . '/_manage.php';
