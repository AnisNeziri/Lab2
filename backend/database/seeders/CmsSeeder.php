<?php

namespace Database\Seeders;

use App\Models\CmsPage;
use Illuminate\Database\Seeder;

class CmsSeeder extends Seeder
{
    public function run(): void
    {
        $blocks = [
            [
                'slug' => 'landing-hero-title',
                'title' => 'Hero Title',
                'content' => 'Simplify Your Inventory with AIMS',
            ],
            [
                'slug' => 'landing-hero-subtitle',
                'title' => 'Hero Subtitle',
                'content' => 'Empower your company with real-time insights and effortless control.',
            ],
            [
                'slug' => 'feature-realtime',
                'title' => 'Real-Time Tracking',
                'content' => 'Always know your inventory levels instantly with live updates.',
            ],
            [
                'slug' => 'feature-analytics',
                'title' => 'Powerful Analytics',
                'content' => 'Gain insights into sales, stock, and operations with smart reports.',
            ],
            [
                'slug' => 'feature-integration',
                'title' => 'Easy Integration',
                'content' => 'Connect seamlessly with your existing tools and workflows.',
            ],
            [
                'slug' => 'about-section',
                'title' => 'About AIMS Inventory',
                'content' => 'AIMS is built by passionate engineers and business experts who understand how critical inventory control is for a company\'s success. Our mission is to make inventory management smarter, faster, and more reliable for businesses of all sizes.',
            ],
        ];

        foreach ($blocks as $block) {
            CmsPage::updateOrCreate(
                ['slug' => $block['slug'], 'company_id' => null],
                [
                    'title' => $block['title'],
                    'content' => $block['content'],
                    'is_published' => true,
                ]
            );
        }
    }
}
