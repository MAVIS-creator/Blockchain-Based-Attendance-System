<?php

if (!function_exists('ai_recommendation_clean_text')) {
  function ai_recommendation_clean_text(string $text): string
  {
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/\*\*(.*?)\*\*/', '$1', $text) ?? $text;
    $text = preg_replace('/__(.*?)__/', '$1', $text) ?? $text;
    $text = preg_replace('/(?<!\*)\*(?!\s)([^*]+?)(?<!\s)\*(?!\*)/', '$1', $text) ?? $text;
    $text = preg_replace('/\h+/', ' ', $text) ?? $text;
    return trim($text);
  }
}

if (!function_exists('ai_recommendation_split_sections')) {
  /**
   * @return array<int, array{label:string, value:string}>
   */
  function ai_recommendation_split_sections(string $text): array
  {
    $normalized = ai_recommendation_clean_text($text);
    if ($normalized === '') {
      return [];
    }

    $parts = preg_split('/\s+(?=[A-Za-z][A-Za-z0-9\/ _-]{1,40}:)/', $normalized) ?: [];
    $sections = [];
    foreach ($parts as $part) {
      $part = trim((string)$part);
      if ($part === '') {
        continue;
      }
      if (preg_match('/^([A-Za-z][A-Za-z0-9\/ _-]{1,40}):\s*(.*)$/', $part, $m)) {
        $sections[] = [
          'label' => trim((string)$m[1]),
          'value' => trim((string)$m[2]),
        ];
      } else {
        $sections[] = [
          'label' => 'Details',
          'value' => $part,
        ];
      }
    }

    return $sections;
  }
}

if (!function_exists('ai_recommendation_extract_items')) {
  /**
   * @return array<int, string>
   */
  function ai_recommendation_extract_items(string $value): array
  {
    $value = trim($value);
    if ($value === '') {
      return [];
    }

    $value = preg_replace('/\s*•\s*/u', "\n- ", $value) ?? $value;
    $value = preg_replace('/\s+-\s+/', "\n- ", $value) ?? $value;
    $value = preg_replace('/\s*(\d+)\.\s+/', "\n$1. ", $value) ?? $value;

    $lines = preg_split('/\n+/', $value) ?: [];
    $items = [];
    foreach ($lines as $line) {
      $line = trim((string)$line);
      if ($line === '') {
        continue;
      }
      $line = preg_replace('/^[-*]\s*/', '', $line) ?? $line;
      $line = trim($line);
      if ($line !== '') {
        $items[] = $line;
      }
    }

    return $items;
  }
}

if (!function_exists('ai_recommendation_render_html')) {
  function ai_recommendation_render_html(string $text): string
  {
    $sections = ai_recommendation_split_sections($text);
    if (empty($sections)) {
      return '<div style="color:var(--on-surface-variant);">Review needed.</div>';
    }

    $listLabels = [
      'action',
      'actions',
      'next steps',
      'steps',
      'additional information',
      'recommendation',
      'plan',
    ];

    $labelChipStyles = [
      'action' => 'background:#e8f3ff;color:#134b7c;border:1px solid #c8ddf4;',
      'actions' => 'background:#e8f3ff;color:#134b7c;border:1px solid #c8ddf4;',
      'next steps' => 'background:#eefce8;color:#1f6b2c;border:1px solid #cfeec6;',
      'steps' => 'background:#eefce8;color:#1f6b2c;border:1px solid #cfeec6;',
      'additional information' => 'background:#fff6e8;color:#7a4f12;border:1px solid #f5dfbd;',
      'recommendation' => 'background:#ede9ff;color:#4b2d8a;border:1px solid #d7cbff;',
      'plan' => 'background:#ede9ff;color:#4b2d8a;border:1px solid #d7cbff;',
      'issue' => 'background:#ffeef0;color:#8f1f2f;border:1px solid #f5c2c8;',
      'status' => 'background:#e9fff4;color:#0f6b46;border:1px solid #bfead6;',
      'case id' => 'background:#f2f4f7;color:#394150;border:1px solid #d6dbe3;',
      'matric' => 'background:#f2f4f7;color:#394150;border:1px solid #d6dbe3;',
    ];

    $chip = static function (string $label, string $labelKey) use ($labelChipStyles): string {
      $style = $labelChipStyles[$labelKey] ?? 'background:#f3f4f6;color:#374151;border:1px solid #d1d5db;';
      return '<span style="display:inline-flex;align-items:center;padding:2px 8px;border-radius:999px;font-size:0.68rem;font-weight:800;letter-spacing:0.03em;text-transform:uppercase;' . $style . '">' . htmlspecialchars($label) . '</span>';
    };

    $html = '<div style="display:grid;gap:6px;">';
    foreach ($sections as $section) {
      $label = trim((string)$section['label']);
      $value = trim((string)$section['value']);
      if ($label === '' && $value === '') {
        continue;
      }

      $labelKey = strtolower($label);
      $shouldList = in_array($labelKey, $listLabels, true)
        || preg_match('/\d+\.\s/', $value)
        || strpos($value, ' • ') !== false
        || strpos($value, ' - ') !== false;

      if ($shouldList) {
        $items = ai_recommendation_extract_items($value);
        if (!empty($items)) {
          $html .= '<div>';
          $html .= $chip($label, $labelKey);
          $html .= '<ul style="margin:6px 0 0 18px;padding:0;line-height:1.45;">';
          foreach ($items as $item) {
            $html .= '<li style="margin:4px 0;">' . htmlspecialchars($item) . '</li>';
          }
          $html .= '</ul>';
          $html .= '</div>';
          continue;
        }
      }

      if ($labelKey === 'details') {
        $html .= '<div>' . htmlspecialchars($value) . '</div>';
      } else {
        $html .= '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;">' . $chip($label, $labelKey) . '<span>' . htmlspecialchars($value) . '</span></div>';
      }
    }
    $html .= '</div>';

    return $html;
  }
}
