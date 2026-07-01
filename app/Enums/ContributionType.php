<?php

namespace App\Enums;

/**
 * The kinds of accepted contribution that earn points. Point values live in
 * config('community.points') so they're tunable without a deploy.
 */
enum ContributionType: string
{
    case EditSuggestion = 'edit_suggestion';
    case MissingCard = 'missing_card';
    case MissingSet = 'missing_set';
    // Engagement + onboarding earns (see App\Actions\Community).
    case ScanFeedback = 'scan_feedback';
    case DailyCheckin = 'daily_checkin';
    case StreakBonus = 'streak_bonus';
    case ProfileComplete = 'profile_complete';
    case FirstScan = 'first_scan';
    case FirstCollectionCard = 'first_collection_card';
    case FirstPublicCollection = 'first_public_collection';
    case Referral = 'referral';

    public function label(): string
    {
        return match ($this) {
            self::EditSuggestion => 'Card edit',
            self::MissingCard => 'Missing card',
            self::MissingSet => 'Missing set',
            self::ScanFeedback => 'Scan feedback',
            self::DailyCheckin => 'Daily check-in',
            self::StreakBonus => 'Streak bonus',
            self::ProfileComplete => 'Completed profile',
            self::FirstScan => 'First scan',
            self::FirstCollectionCard => 'First card added',
            self::FirstPublicCollection => 'Made collection public',
            self::Referral => 'Referral',
        };
    }

    /** Points awarded for this contribution (config-driven, with a fallback). */
    public function points(): int
    {
        return (int) (config('community.points.'.$this->value) ?? 0);
    }
}
