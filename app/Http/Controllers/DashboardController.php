<?php

namespace App\Http\Controllers;

use App\Services\DashboardStats;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(DashboardStats $stats): View
    {
        $summary = $stats->summary();

        return view('dashboard', [
            'counts' => $summary['counts'],
            'assignment' => $summary['assignment'],
            'openBatches' => $summary['open_batches'],
            'recentBatches' => $summary['recent_imports'],
            'batchDistribution' => $summary['batch_distribution'],
        ]);
    }
}
