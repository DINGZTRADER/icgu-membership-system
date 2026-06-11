<?php

use Illuminate\Support\Facades\Route;

// Baseline landing confirmation for your approval presentation
Route::get('/', function () {
    return [
        'Institute' => 'Institute of Corporate Governance of Uganda (ICGU)',
        'Ecosystem_Status' => 'Sandbox Active',
        'Compliance_Baseline' => 'Uganda Companies Act 2012',
        'Database_Cluster' => 'Supabase Managed PostgreSQL',
    ];
});
