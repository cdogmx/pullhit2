import type { CatalogItem } from './catalog';

export type ScanUsage = {
    used: number;
    cap: number | null;
    remaining: number | null;
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
    candidates: ScanCandidate[];
};

export type ScanResult = {
    detected: ScanDetected[];
    usage: ScanUsage;
};
