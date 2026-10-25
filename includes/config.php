<?php
// Edit these for your server (XAMPP/WAMP defaults shown).
// You can also set the SSIS_DB_* environment variables instead.
define('DB_HOST', getenv('SSIS_DB_HOST') ?: '127.0.0.1');
define('DB_NAME', getenv('SSIS_DB_NAME') ?: 'ssis');
define('DB_USER', getenv('SSIS_DB_USER') ?: 'root');
define('DB_PASS', getenv('SSIS_DB_PASS') ?: '');

// URL path where the app lives, no trailing slash. Example: '/CuyoTech-University' for http://localhost/CuyoTech-University/
define('APP_BASE', getenv('SSIS_BASE') !== false ? getenv('SSIS_BASE') : '/CuyoTech-University');

// Tuition assessment and minimum required payment to confirm enrollment
const SEMESTER_TUITION_FEE = 24000.00;
const MINIMUM_ENROLLMENT_PAYMENT = 6000.00;

// Documents students can request, with fees in PHP.
const DOC_FEES = [
    'Transcript of Records'      => 150,
    'Certificate of Enrollment'  => 50,
    'Certificate of Registration'=> 75,
    'Good Moral Certificate'     => 75,
];
const OFFICES = ['Registrar', 'Library', 'Cashier', 'Student Affairs', 'Department'];
const ROLE_LABELS = [
    'student' => 'Student', 'registrar' => 'Registrar', 'cashier' => 'Cashier',
    'depthead' => 'Department head', 'admin' => 'Administrator',
];
// Degree programs offered by each college (keyed by department code)
const COLLEGE_PROGRAMS = [
    'COE'  => [
        'Bachelor of Science in Computer Engineering',
        'Bachelor of Science in Electronics Engineering',
        'Bachelor of Science in Industrial Engineering',
    ],
    'CEAS' => [
        'Bachelor of Elementary Education',
        'Bachelor of Secondary Education major in English',
        'Bachelor of Secondary Education major in Social Studies',
        'Bachelor of Secondary Education major in Mathematics',
        'Bachelor of Secondary Education major in Filipino',
        'Bachelor of Science in Psychology',
    ],
    'CHAS' => [
        'Bachelor of Science in Nursing',
    ],
    'CCS'  => [
        'Bachelor of Science in Computer Science',
        'Bachelor of Science in Information Technology',
    ],
    'CBAA' => [
        'Bachelor of Science in Accountancy',
        'Bachelor of Science in Business Administration major in Financial Management',
        'Bachelor of Science in Business Administration major in Marketing Management',
    ],
];

// Short code used to build section names, e.g. BSN + year 1 + letter A = "BSN 1A"
const PROGRAM_CODES = [
    'Bachelor of Science in Computer Engineering'  => 'BSCpE',
    'Bachelor of Science in Electronics Engineering' => 'BSECE',
    'Bachelor of Science in Industrial Engineering' => 'BSIE',
    'Bachelor of Elementary Education'             => 'BEED',
    'Bachelor of Secondary Education major in English'       => 'BSED-ENG',
    'Bachelor of Secondary Education major in Social Studies' => 'BSED-SS',
    'Bachelor of Secondary Education major in Mathematics'   => 'BSED-MATH',
    'Bachelor of Secondary Education major in Filipino'      => 'BSED-FIL',
    'Bachelor of Science in Psychology'            => 'BSPSY',
    'Bachelor of Science in Nursing'               => 'BSN',
    'Bachelor of Science in Computer Science'      => 'BSCS',
    'Bachelor of Science in Information Technology' => 'BSIT',
    'Bachelor of Science in Accountancy'           => 'BSA',
    'Bachelor of Science in Business Administration major in Financial Management'  => 'BSBA-FM',
    'Bachelor of Science in Business Administration major in Marketing Management' => 'BSBA-MM',
];
const MAX_YEAR_LEVEL = 4;
