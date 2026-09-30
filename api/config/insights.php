<?php
// api/config/insights.php
// Own-history insight rules (Plan C design §6). Changing a rule's logic bumps rules_version.
return [
    'rules_version' => 1,

    'trend_28d_yoy' => ['material_pct' => 5.0],
    'trend_28d_vs_prior' => ['material_pct' => 10.0],
    'weekly_streak' => ['min_weeks' => 3],
    'anomaly_day' => [
        'lookback_days' => 28,      // days checked, ending on as_of
        'baseline_weeks' => 8,      // same weekdays before the day
        'min_baseline_days' => 4,
        'min_change_pct' => 30.0,
        'min_robust_z' => 3.5,
    ],
    'best_worst_weekday' => ['weeks' => 12, 'min_weeks' => 4, 'search_weeks' => 26],
    'data_gap' => ['lookback_days' => 28],
];
