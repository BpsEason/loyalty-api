<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\CampaignOverviewWidget;
use App\Filament\Widgets\CustomerGrowthWidget;
use App\Filament\Widgets\OverviewStatsWidget;
use App\Filament\Widgets\PointTrendWidget;
use App\Filament\Widgets\RecentTransactionsWidget;
use App\Filament\Widgets\RewardOverviewWidget;
use App\Http\Middleware\RememberFilamentTenant;
use App\Http\Middleware\SetPermissionsTeamId;
use App\Models\Tenant;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->maxContentWidth('full')
            ->globalSearch(false)
            ->login()
            ->brandName('Loyalty Platform')
            ->font('Inter')
            ->colors([
                'primary' => Color::Blue,
            ])
            ->darkMode(true)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->tenant(Tenant::class)
            ->tenantMiddleware([
                RememberFilamentTenant::class,
            ])
            ->discoverResources(
                in: app_path('Filament/Resources'),
                for: 'App\\Filament\\Resources',
            )
            ->discoverPages(
                in: app_path('Filament/Pages'),
                for: 'App\\Filament\\Pages',
            )
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(
                in: app_path('Filament/Widgets'),
                for: 'App\\Filament\\Widgets',
            )
            ->widgets([
                OverviewStatsWidget::class,
                PointTrendWidget::class,
                CustomerGrowthWidget::class,
                CampaignOverviewWidget::class,
                RewardOverviewWidget::class,
                RecentTransactionsWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                SetPermissionsTeamId::class,
                Authenticate::class,
            ]);
    }
}
