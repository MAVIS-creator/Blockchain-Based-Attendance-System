<?php

require_once dirname(__DIR__, 2) . '/hybrid_dual_write.php';

if (!function_exists('hybrid_admin_read_enabled')) {
  function hybrid_admin_read_enabled()
  {
    if (!hybrid_enabled()) return false;
    $flag = strtolower((string)hybrid_env('HYBRID_ADMIN_READ', 'true'));
    return in_array($flag, ['1', 'true', 'yes', 'on'], true);
  }
}

if (!function_exists('hybrid_fetch_attendance_entries')) {
  function hybrid_fetch_attendance_entries($selectedDate, $selectedCourse, $searchName = '', $filterType = 'both', &$source = 'file')
  {
    if (!hybrid_admin_read_enabled()) return null;

    $courseFilter = trim((string)$selectedCourse);
    $courseFilterAll = ($courseFilter === '' || strtolower($courseFilter) === '__all');
    $normalizeCourse = static function ($value) {
      $value = strtolower(trim((string)$value));
      return preg_replace('/\s+/', ' ', $value);
    };
    $selectedCourseNorm = $normalizeCourse($courseFilter);

    // Build a timezone-aware UTC window for the full local calendar day.
    // Timestamps in Supabase are stored with the local timezone offset (e.g. +01:00 for
    // Africa/Lagos). Records logged between 00:00–00:59 local time have UTC timestamps
    // that fall on the *previous* calendar day (e.g. 2026-04-15T00:30+01:00 = 2026-04-14T23:30Z).
    // Using raw UTC midnight as the lower bound excludes those entries entirely.
    // We fix this by computing the true UTC start/end for the local day and using
    // PostgREST's and=() filter to send both bounds in one request.
    $appTz = (string)(hybrid_env('APP_TIMEZONE', 'Africa/Lagos') ?: 'Africa/Lagos');
    try {
      $tz = new DateTimeZone($appTz);
    } catch (Throwable $e) {
      $tz = new DateTimeZone('Africa/Lagos');
    }
    try {
      // Local day starts at 00:00:00 in the app timezone
      $localStart = new DateTime($selectedDate . ' 00:00:00', $tz);
      // Local day ends just before midnight (exclusive)
      $localEnd   = new DateTime($selectedDate . ' 00:00:00', $tz);
      $localEnd->modify('+1 day');
      // Convert to UTC ISO8601 for Supabase
      $localStart->setTimezone(new DateTimeZone('UTC'));
      $localEnd->setTimezone(new DateTimeZone('UTC'));
      $utcStart = $localStart->format('Y-m-d\TH:i:s\Z');
      $utcEnd   = $localEnd->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable $e) {
      // Fallback: use naive UTC bounds (old behaviour, may miss early local-morning records)
      $utcStart = $selectedDate . 'T00:00:00Z';
      $utcEnd   = $selectedDate . 'T23:59:59Z';
    }

    // PostgREST supports compound filters via and=(col.op.val,col.op.val).
    // This lets us send both gte AND lt for 'timestamp' in a single GET request.
    $customQuery = [
      'select' => 'name,matric,action,fingerprint,ip,mac,user_agent,course,reason,timestamp',
      'order'  => 'timestamp.asc',
      'and'    => '(timestamp.gte.' . $utcStart . ',timestamp.lt.' . $utcEnd . ')',
    ];

    $rows = null;
    $err  = null;
    $ok   = hybrid_supabase_select('attendance_logs', $customQuery, $rows, $err);
    if (!$ok || !is_array($rows)) return null;

    $entries = [];
    foreach ($rows as $row) {
      $ts = (string)($row['timestamp'] ?? '');
      if ($ts === '') continue;
      // Verify the entry falls within the local calendar day.
      // The timestamp may be stored with a timezone offset (e.g. +01:00).
      // Parse it so we compare against the local date, not UTC date.
      try {
        $dt = new DateTime($ts);
        $dt->setTimezone($tz);
        $tsDate = $dt->format('Y-m-d');
      } catch (Throwable $e) {
        // Fallback: naive substring (only works when tz offset is absent or +00:00)
        $tsDate = substr($ts, 0, 10);
      }
      if ($tsDate !== $selectedDate) continue;

      $entry = [
        'name' => (string)($row['name'] ?? ''),
        'matric' => (string)($row['matric'] ?? ''),
        'action' => (string)($row['action'] ?? ''),
        'fingerprint' => (string)($row['fingerprint'] ?? ''),
        'ip' => (string)($row['ip'] ?? ''),
        'mac' => (string)($row['mac'] ?? 'UNKNOWN'),
        'timestamp' => str_replace('T', ' ', substr($ts, 0, 19)),
        'device' => (string)($row['user_agent'] ?? ''),
        'course' => (string)($row['course'] ?? 'General'),
        'reason' => (string)($row['reason'] ?? '-'),
      ];

      if (!$courseFilterAll) {
        if ($normalizeCourse($entry['course']) !== $selectedCourseNorm) continue;
      }

      if (
        $searchName !== '' &&
        stripos($entry['name'], $searchName) === false &&
        stripos($entry['ip'], $searchName) === false &&
        stripos($entry['mac'], $searchName) === false
      ) {
        continue;
      }

      if ($filterType === 'ip' && ($entry['ip'] === '' || $entry['ip'] === 'UNKNOWN')) continue;
      if ($filterType === 'mac' && ($entry['mac'] === '' || $entry['mac'] === 'UNKNOWN')) continue;

      $entries[] = $entry;
    }

    $source = 'supabase';
    return $entries;
  }
}

if (!function_exists('hybrid_fetch_support_tickets')) {
  function hybrid_fetch_support_tickets(&$source = 'file')
  {
    if (!hybrid_admin_read_enabled()) return null;

    $rows = null;
    $err = null;
    $ok = hybrid_supabase_select('support_tickets', [
      'select' => 'name,matric,message,fingerprint,ip,created_at_local,resolved,timestamp',
      'order' => 'timestamp.asc'
    ], $rows, $err);

    if (!$ok || !is_array($rows)) return null;

    $tickets = [];
    foreach ($rows as $row) {
      $tickets[] = [
        'name' => (string)($row['name'] ?? ''),
        'matric' => (string)($row['matric'] ?? ''),
        'message' => (string)($row['message'] ?? ''),
        'fingerprint' => (string)($row['fingerprint'] ?? ''),
        'ip' => (string)($row['ip'] ?? ''),
        'timestamp' => (string)($row['created_at_local'] ?? substr((string)($row['timestamp'] ?? ''), 0, 19)),
        'resolved' => (bool)($row['resolved'] ?? false),
      ];
    }

    $source = 'supabase';
    return $tickets;
  }
}

if (!function_exists('hybrid_support_ticket_unresolved_count')) {
  function hybrid_support_ticket_unresolved_count(&$source = 'file')
  {
    $tickets = hybrid_fetch_support_tickets($source);
    if (!is_array($tickets)) {
      return null;
    }

    $count = 0;
    foreach ($tickets as $ticket) {
      if (!is_array($ticket)) {
        continue;
      }
      if (empty($ticket['resolved'])) {
        $count++;
      }
    }

    return $count;
  }
}

if (!function_exists('hybrid_mark_support_ticket_resolved')) {
  function hybrid_mark_support_ticket_resolved($ticketTimestamp)
  {
    if (!hybrid_admin_read_enabled()) return false;
    $err = null;
    if (hybrid_supabase_update('support_tickets', ['created_at_local' => 'eq.' . $ticketTimestamp], ['resolved' => true], $err)) {
      return true;
    }
    // Fallback for legacy rows without created_at_local value.
    return hybrid_supabase_update('support_tickets', ['timestamp' => 'eq.' . $ticketTimestamp], ['resolved' => true], $err);
  }
}
