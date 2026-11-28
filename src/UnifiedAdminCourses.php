<?php
declare(strict_types=1);

require_once __DIR__ . '/../admin/cache_helpers.php';

function bbas_unified_course_names(): array
{
    return array_map('strval', array_keys(bbas_unified_course_catalogue()));
}

function bbas_unified_course_catalogue(): array
{
    $path = admin_course_storage_migrate_file('course.json');
    if (!is_file($path)) return [];
    $raw = file_get_contents($path);
    $courses = is_string($raw) ? json_decode($raw) : null;
    if (!is_array($courses) && !is_object($courses)) {
        throw new RuntimeException('Course catalogue could not be read');
    }
    $catalogue = [];
    foreach ($courses as $key => $value) {
        $name = is_array($courses) ? $value : (string)$key;
        if (is_string($name) && $name !== '' && strlen($name) <= 191) {
            $catalogue[$name] = is_array($courses) ? 'Course outline not set.' : $value;
        }
    }
    return $catalogue;
}

function bbas_unified_add_course(string $course, string $outline): void
{
    $catalogue = bbas_unified_course_catalogue();
    if (array_key_exists($course, $catalogue)) {
        throw new DomainException('That course already exists.');
    }
    $catalogue[$course] = $outline;
    bbas_unified_save_course_file('course.json', (object)$catalogue);
}

function bbas_unified_set_active_course(string $course): void
{
    if ($course !== '' && !in_array($course, bbas_unified_course_names(), true)) {
        throw new DomainException('Choose a configured course.');
    }
    bbas_unified_save_course_file('active_course.json', ['course' => $course]);
}

function bbas_unified_save_course_file(string $filename, array|object $data): void
{
    $path = admin_course_storage_migrate_file($filename);
    $temporary = tempnam(dirname($path), '.unified-course-');
    if ($temporary === false) throw new RuntimeException('Could not prepare active course');
    try {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $encoded) !== strlen($encoded) || !rename($temporary, $path)) {
            throw new RuntimeException('Could not save active course');
        }
    } finally {
        if (is_file($temporary)) unlink($temporary);
    }
}
