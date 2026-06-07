<?php

namespace App\Services;

use App\Models\CmsPage;
use Illuminate\Support\Facades\Auth;

class CmsService
{
    public const LANDING_SLUGS = [
        'landing-hero-title',
        'landing-hero-subtitle',
        'feature-realtime',
        'feature-analytics',
        'feature-integration',
        'about-section',
    ];

    public function listPublished(): \Illuminate\Database\Eloquent\Collection
    {
        return CmsPage::where('is_published', true)
            ->whereNull('company_id')
            ->whereIn('slug', self::LANDING_SLUGS)
            ->orderBy('title')
            ->get();
    }

    public function listEditable(): \Illuminate\Database\Eloquent\Collection
    {
        return CmsPage::whereNull('company_id')
            ->whereIn('slug', self::LANDING_SLUGS)
            ->orderBy('title')
            ->get();
    }

    public function findBySlug(string $slug): ?CmsPage
    {
        if (! in_array($slug, self::LANDING_SLUGS, true)) {
            return null;
        }

        return CmsPage::where('slug', $slug)->whereNull('company_id')->first();
    }

    public function update(CmsPage $page, array $data): CmsPage
    {
        if (! in_array($page->slug, self::LANDING_SLUGS, true)) {
            abort(422, 'This content block cannot be edited.');
        }

        $data['updated_by'] = Auth::id();
        $page->update($data);

        return $page->fresh();
    }
}
