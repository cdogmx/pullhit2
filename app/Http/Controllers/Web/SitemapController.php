<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Http\Response;

/**
 * XML sitemap so search engines discover the SEO set landings
 * (/browse/pokemon/surging-sparks) plus the core public pages.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $paths = ['/', '/browse', '/brand', '/terms', '/privacy'];

        foreach (ProductLine::pluck('slug') as $slug) {
            $paths[] = "/browse/{$slug}";
        }

        Set::with('productLine:id,slug')
            ->get(['id', 'slug', 'product_line_id'])
            ->each(function (Set $set) use (&$paths) {
                if ($set->productLine) {
                    $paths[] = "/browse/{$set->productLine->slug}/{$set->slug}";
                }
            });

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach (array_unique($paths) as $path) {
            $xml .= '  <url><loc>'.e(url($path)).'</loc></url>'."\n";
        }
        $xml .= '</urlset>'."\n";

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }
}
