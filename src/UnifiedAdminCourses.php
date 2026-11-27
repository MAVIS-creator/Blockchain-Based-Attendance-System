<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/cache_helpers.php';

function bbas_unified_course_names(): array
{
    $courses = admin_cached_json_file('unified_course_names', admin_course_storage_migrate_file('course.json'), [], 0);
    $names = [];
    foreach ($courses as $key => $value) {
        $name = is_string($key) ? $key : (is_string($value) ? $value : '');
        if ($name !== '' && strlen($name) <= 191 && preg_match('//u', $name)) {
            $names[$name] = $name;
        }
    }
    return array_values($names);
}

function bbas_unified_set_active_course(string $course): void
{
    if ($course !== '' && !in_array($course, bbas_unified_course_names(), true)) {
        throw new DomainException('Choose a configured course.');
    }
    $path = admin_course_storage_migrate_file('active_course.json');
    $temporary = tempnam(dirname($path), '.active-course-');
    if ($temporary === false) throw new RuntimeException('Could not prepare active course');
    try {
        $encoded = json_encode(['course' => $course], JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $encoded) !== strlen($encoded) || !rename($temporary, $path)) {
            throw new RuntimeException('Could not save active course');
        }
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}
