<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CatalogItem;
use App\Models\ProductLine;
use App\Models\Set;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * XML sitemaps for search engines. A sitemap *index* (/sitemap.xml) points to:
 *   - /sitemap-pages.xml      — core public pages + brand & set landings
 *   - /sitemap-cards-{n}.xml  — every card page (/{brand}/{set}/{card}), paged
 *     into CHUNK-sized files so each stays under the spec's 50,000-URL limit.
 *
 * Card rows are streamed (cursor) to keep memory flat. There's no public link —
 * search engines discover it via the Sitemap directive in robots.txt.
 */
class SitemapController extends Controller
{
    /**
     * URLs per card sitemap. The spec caps a file at 50,000, but a file that
     * large (~4-5MB) is slow to generate/transfer and Googlebot times out
     * fetching it. ~10k fetches reliably, so we chunk small and let the index
     * list however many files that yields.
     */
    private const CHUNK = 10000;

    /** The sitemap index — lists the child sitemaps. */
    public function index(): Response
    {
        $cardPages = (int) max(1, ceil(
            CatalogItem::whereNotNull('slug')->count() / self::CHUNK,
        ));

        $sitemaps = [url('/sitemap-pages.xml')];
        for ($p = 1; $p <= $cardPages; $p++) {
            $sitemaps[] = url("/sitemap-cards-{$p}.xml");
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($sitemaps as $loc) {
            $xml .= '  <sitemap><loc>'.e($loc).'</loc></sitemap>'."\n";
        }

        $xml .= '</sitemapindex>'."\n";

        return $this->xml($xml);
    }

    /** Core public pages + every brand and set landing page. */
    public function pages(): Response
    {
        $paths = ['/', '/browse', '/brand', '/terms', '/privacy', '/rankings'];

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

        $xml = $this->urlsetOpen();

        foreach (array_unique($paths) as $path) {
            $xml .= '  <url><loc>'.e(url($path)).'</loc></url>'."\n";
        }

        $xml .= '</urlset>'."\n";

        return $this->xml($xml);
    }

    /** One CHUNK-sized page of card URLs (/{brand}/{set}/{card}), with lastmod. */
    public function cards(int $page): Response
    {
        $xml = $this->urlsetOpen();

        DB::table('catalog_items as ci')
            ->join('sets as s', 'ci.set_id', '=', 's.id')
            ->join('product_lines as pl', 'ci.product_line_id', '=', 'pl.id')
            ->whereNotNull('ci.slug')
            ->orderBy('ci.id')
            ->forPage(max(1, $page), self::CHUNK)
            ->select('pl.slug as brand', 's.slug as set', 'ci.slug as card', 'ci.updated_at')
            ->cursor()
            ->each(function (object $row) use (&$xml) {
                $loc = e(url("/{$row->brand}/{$row->set}/{$row->card}"));
                $lastmod = $row->updated_at ? substr((string) $row->updated_at, 0, 10) : null;

                $xml .= '  <url><loc>'.$loc.'</loc>'
                    .($lastmod ? '<lastmod>'.$lastmod.'</lastmod>' : '')
                    .'</url>'."\n";
            });

        $xml .= '</urlset>'."\n";

        return $this->xml($xml);
    }

    private function urlsetOpen(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
    }

    private function xml(string $xml): Response
    {
        return response($xml, 200)->header('Content-Type', 'application/xml');
    }
}
