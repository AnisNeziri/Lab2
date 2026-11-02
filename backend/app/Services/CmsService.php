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
        return CmsPage::withoutGlobalScope('company')->where('is_published', true)
            ->whereNull('company_id')
            ->whereIn('slug', self::LANDING_SLUGS)
            ->orderBy('title')
            ->get();
    }

    public function listEditable(): \Illuminate\Database\Eloquent\Collection
    {
        $this->authorizePlatformEditor();
        return CmsPage::withoutGlobalScope('company')->whereNull('company_id')
            ->whereIn('slug', self::LANDING_SLUGS)
            ->orderBy('title')
            ->get();
    }

    public function findBySlug(string $slug): ?CmsPage
    {
        if (! in_array($slug, self::LANDING_SLUGS, true)) {
            return null;
        }

        return CmsPage::withoutGlobalScope('company')->where('slug', $slug)->whereNull('company_id')->where('is_published', true)->first();
    }

    private function authorizePlatformEditor(): void
    {
        abort_unless(Auth::user()?->role === 'superadmin' && Auth::user()?->company_id === null, 403, 'Public site content is managed by platform administration.');
    }

    public function update(CmsPage $page, array $data): CmsPage
    {
        $this->authorizePlatformEditor();
        abort_unless($page->company_id === null, 403);
        if (! in_array($page->slug, self::LANDING_SLUGS, true)) {
            abort(422, 'This content block cannot be edited.');
        }

        $data['updated_by'] = Auth::id();
        $page->update($data);

        return $page->fresh();
    }
}
