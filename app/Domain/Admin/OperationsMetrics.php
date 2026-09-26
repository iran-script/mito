<?php

namespace App\Domain\Admin;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class OperationsMetrics
{
    public function snapshot(AdminUser $admin): array
    {
        abort_unless($admin->canAccessPanel(Filament::getPanel('admin')), 403);

        return Cache::remember('admin.operations.metrics', 30, fn () => [
            'Total users' => DB::table('users')->count(), 'Active accounts' => DB::table('users')->where('status', 'active')->count(),
            'Completed profiles' => DB::table('profiles')->whereNotNull('profile_completed_at')->count(),
            'Gold users' => DB::table('memberships')->where('plan_code', 'gold')->where('status', 'active')->where('starts_at', '<=', now())->where('ends_at', '>', now())->distinct()->count('user_id'),
            'Open reports' => DB::table('reports')->whereIn('status', ['open', 'reviewing'])->count() + DB::table('event_reports')->whereIn('status', ['open', 'reviewing'])->count(),
            'Active conversations' => DB::table('conversations')->where('status', 'active')->count(),
            'Upcoming events' => DB::table('events')->where('status', 'published')->where('starts_at', '>', now())->count(),
            'Games created today' => DB::table('game_sessions')->where('created_at', '>=', now()->startOfDay())->count(),
            'Coins in wallets' => DB::table('wallets')->sum('balance'),
            'Direct messages today' => DB::table('direct_messages')->where('created_at', '>=', now()->startOfDay())->count(),
            'Chat requests today' => DB::table('chat_requests')->where('created_at', '>=', now()->startOfDay())->count(),
        ]);
    }
}
