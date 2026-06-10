<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MembershipTier;
use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\MarketValue;
use App\Models\Set;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin dashboard — at-a-glance catalog + membership stats.
 */
class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/dashboard', [
            'stats' => [
                'sets' => Set::count(),
                'items' => CatalogItem::count(),
                'valued' => MarketValue::distinct('catalog_item_id')->count('catalog_item_id'),
                'images' => CatalogItem::whereNotNull('primary_image_path')->count(),
                'users' => User::count(),
                'premium' => User::where('membership_tier', MembershipTier::Premium)->count(),
                'admins' => User::where('is_admin', true)->count(),
            ],
        ]);
    }
}
