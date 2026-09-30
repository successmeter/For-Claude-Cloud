<?php
// api/config/hub.php

return [
    /*
     * Days a web-visible competitor set stays locked against further member removals after a
     * removal once it has served a benchmark (design §4.7; the 30-day value is the assumption in
     * 07-decisions-and-open-questions.md §7.2).
     */
    'composition_lock_days' => (int) env('HUB_COMPOSITION_LOCK_DAYS', 30),
];
