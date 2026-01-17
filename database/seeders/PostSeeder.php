<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PostSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $posts = [
            [
                'title' => "When to See a Doctor: Warning Signs You Shouldn't Ignore",
                'slug' => 'when-to-see-a-doctor',
                'category' => 'General Health',
                'type' => 'article',
                'description' => "Some symptoms require immediate medical attention. Learn when not to wait...",
                'content' => "<p>Detailed content about when to see a doctor...</p>",
                'time_to_read' => '5 min read',
                'image_url' => "https://images.unsplash.com/photo-1559839734-2b71ea197ec2?q=80&w=2070&auto=format&fit=crop",
                'is_featured' => false,
            ],
            [
                'title' => "Understanding High Blood Pressure (Hypertension)",
                'slug' => 'understanding-high-blood-pressure',
                'category' => 'Heart Health',
                'type' => 'article',
                'description' => "Why silence is not golden when it comes to BP. Learn the warning signs...",
                'content' => "<p>Detailed content about hypertension...</p>",
                'time_to_read' => '4 min read', // Fixed typo
                'image_url' => "https://plus.unsplash.com/premium_photo-1673953509975-576678fa6710?q=80&w=2070&auto=format&fit=crop",
                 'is_featured' => false,
            ],
            [
                'title' => "Nigerian Superfoods for Better Immunity",
                'slug' => 'nigerian-superfoods-immunity',
                'category' => 'Nutrition',
                'type' => 'article',
                'description' => "Discover local foods that boost your immune system naturally...",
                'content' => "<p>Detailed content about Nigerian superfoods...</p>",
                'time_to_read' => '6 min read',
                'image_url' => "https://images.unsplash.com/photo-1512621776951-a57141f2eefd?q=80&w=2070&auto=format&fit=crop",
                 'is_featured' => false,
            ],
             [
                'title' => "Cholera Outbreak: 5 Ways to Protect Your Family Today",
                'slug' => 'cholera-outbreak-protection',
                'category' => 'Public Health',
                'type' => 'article',
                'description' => "Learn essential preventive measures to keep your loved ones safe during this health emergency.",
                'content' => "<p>Full article about Cholera prevention...</p>",
                'time_to_read' => '3 min read',
                'image_url' => "https://images.unsplash.com/photo-1542037947-80ad594d5b1e?q=80&w=2371&auto=format&fit=crop",
                'is_featured' => true,
                'author_name' => 'Dr. Bala Usman'
            ],
             [
                'title' => "5-Minute Morning Stretches for Office Workers",
                'slug' => 'morning-stretches',
                'category' => 'Fitness',
                'type' => 'video',
                'description' => "Start your day right with these simple moves.",
                'content' => "Video Link",
                'time_to_read' => '5:45',
                'image_url' => "https://images.unsplash.com/photo-1544367563-121955377d09?q=80&w=2067&auto=format&fit=crop",
                 'is_featured' => false,
            ],
             [
                'title' => "How to Use Your First Aid Kit Properly",
                'slug' => 'first-aid-kit-guide',
                'category' => 'Emergency Care',
                'type' => 'video',
                'description' => "Be prepared for emergencies.",
                'content' => "Video Link",
                'time_to_read' => '5:20',
                'image_url' => "https://images.unsplash.com/photo-1584308666744-24d5c474f2ae?q=80&w=2060&auto=format&fit=crop",
                 'is_featured' => false,
            ],
        ];

        foreach ($posts as $post) {
            \App\Models\Post::create($post);
        }
    }
}
