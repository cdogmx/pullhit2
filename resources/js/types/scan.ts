import type { CatalogItem } from './catalog';

export type ScanUsage = {
    used: number;
    cap: number | null;
    remaining: number | null;
    /** Monthly allowance left (before dipping into purchased credits). */
    monthly_remaining?: number | null;
    /** Purchased top-up credits that persist past the monthly allowance. */
    credits?: number;
    unlimited: boolean;
};

export type ScanIdentified = {
    name: string | null;
    number: string | null;
    set_name: string | null;
    language: string | null;
    is_graded: boolean;
    grading_company: string | null;
    grade: number | null;
    confidence: number;
};

export type ScanCandidate = {
    card: CatalogItem;
    score: number;
    reasons: string[];
};

export type ScanDetected = {
    identified: ScanIdentified;
    thumbnail: string | null;
    /** Perceptual hash of the scanned image; sent back to teach the cache on add. */
    fingerprint: string | null;
    /** 'cache' = recognised from a prior confirmed scan (no AI read); 'vision' = AI read. */
    source: 'vision' | 'cache';
    candidates: ScanCandidate[];
};

export type ScanResult = {
    detected: ScanDetected[];
    usage: ScanUsage;
};
