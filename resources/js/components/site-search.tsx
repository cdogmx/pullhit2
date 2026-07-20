import { router } from '@inertiajs/react';
import { useState } from 'react';
import { SearchSuggest } from '@/components/search-suggest';

/**
 * Public type-ahead for the site header and homepage hero. Thin wrapper over
 * SearchSuggest (which owns the dropdown + fetch); here the only decision is
 * what a full search does — visit the browse page for the term.
 *
 * `variant` switches the input chrome: `header` is the compact bordered box in
 * the site header; `hero` is the rounded white pill (+ Search button) used on
 * the homepage hero.
 */
export function SiteSearch({
    className,
    variant = 'header',
    placeholder = 'Search cards, sets, brands…',
}: {
    className?: string;
    variant?: 'header' | 'hero';
    placeholder?: string;
}) {
    const [q, setQ] = useState('');

    return (
        <SearchSuggest
            value={q}
            onChange={setQ}
            onSubmit={(term) =>
                router.visit(`/browse?q=${encodeURIComponent(term)}`)
            }
            variant={variant}
            placeholder={placeholder}
            className={className}
        />
    );
}
