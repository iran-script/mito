<?php

namespace Database\Seeders;

use App\Domain\Events\EventCategory;
use App\Domain\Profiles\City;
use App\Domain\Profiles\Interest;
use App\Domain\Profiles\Province;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Tehran' => ['Tehran', 'Rey'], 'Isfahan' => ['Isfahan', 'Kashan'], 'Fars' => ['Shiraz'], 'Razavi Khorasan' => ['Mashhad']] as $province => $cities) {
            $p = Province::firstOrCreate(['name' => $province]);
            foreach ($cities as $city) {
                City::firstOrCreate(['province_id' => $p->id, 'name' => $city]);
            }
        }
        foreach (['sport', 'music', 'movies', 'travel', 'cafe', 'books', 'gaming', 'nature', 'technology', 'art'] as $slug) {
            Interest::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        }
        foreach ([['direct_message', 2], ['chat_acceptance', 2], ['telegram_id_share', 4]] as [$code, $cost]) {
            $this->defaults('coin_feature_prices', ['feature_code' => $code], ['coin_cost' => $cost, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]);
        }
        $this->defaults('economy_settings', ['key' => 'signup_bonus'], ['value' => '5', 'updated_at' => now(), 'created_at' => now()]);
        foreach ([['gold_bulk_max_recipients_per_action', '10'], ['gold_bulk_chat_requests_per_day', '30'], ['gold_bulk_direct_messages_per_day', '20'], ['gold_bulk_actions_cooldown_seconds', '60'], ['gold_priority_enabled', '1'], ['gold_duration_days', '30']] as [$key, $value]) {
            $this->defaults('economy_settings', ['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
        }
        foreach ([['Starter', 20, 0, 100000], ['Popular', 50, 5, 200000], ['Value', 120, 20, 400000]] as [$name, $base, $bonus, $price]) {
            $this->defaults('coin_packages', ['name' => $name], ['base_coins' => $base, 'bonus_coins' => $bonus, 'price_amount' => $price, 'currency' => 'IRR', 'is_active' => true, 'is_featured' => $name === 'Popular', 'sort_order' => $base, 'updated_at' => now(), 'created_at' => now()]);
        }
        foreach (['sport', 'cafe', 'hiking', 'travel', 'gaming', 'music', 'social', 'study', 'other'] as $slug) {
            EventCategory::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        }
        foreach ([['first_win', 'First Win', 'Win your first game'], ['five_streak', 'On Fire', 'Win five games in a row'], ['quiz_master', 'Quiz Master', 'Complete 10 Speed Quiz games'], ['top_ten_weekly', 'Top 10 Weekly', 'Finish a completed week in the top 10 with positive net rating gained'], ['fifty_wins', '50 Wins', 'Win 50 competitive games'], ['hundred_games', '100 Games', 'Complete 100 multiplayer games'], ['rps_champion', 'RPS Champion', 'Win 10 Rock Paper Scissors games']] as [$code, $name, $description]) {
            $this->defaults('badges', ['code' => $code], ['name' => $name, 'description' => $description, 'updated_at' => now(), 'created_at' => now()]);
        }
        foreach ([['What is 2 + 2?', ['3', '4', '5'], '4'], ['Which color is made by mixing blue and yellow?', ['Green', 'Purple', 'Orange'], 'Green']] as [$question, $options, $correct]) {
            $this->defaults('quiz_questions', ['question' => $question], ['options' => json_encode($options), 'correct_option' => $correct, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]);
        }
        foreach (range(1, 10) as $number) {
            $this->defaults('quiz_questions', ['question' => 'Speed Quiz question '.$number], ['options' => json_encode(['Option A', 'Option B', 'Option C', 'Option D']), 'correct_option' => 'Option A', 'category' => 'general', 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]);
        }
        foreach ([['Coffee or Nature?', ['Coffee', 'Nature'], 'Coffee'], ['Movie or Music?', ['Movie', 'Music'], 'Music'], ['Morning or Night?', ['Morning', 'Night'], 'Morning']] as [$question, $options, $correct]) {
            $this->defaults('quiz_questions', ['question' => $question], ['options' => json_encode($options), 'correct_option' => $correct, 'category' => 'this_or_that', 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]);
        }
        foreach (range(1, 10) as $number) {
            $this->defaults('quiz_questions', ['question' => 'This or That question '.$number], ['options' => json_encode(['Choice A', 'Choice B']), 'correct_option' => 'Choice A', 'category' => 'this_or_that', 'is_active' => true, 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    private function defaults(string $table, array $identity, array $values): void
    {
        if (! DB::table($table)->where($identity)->exists()) {
            DB::table($table)->insert($identity + $values);
        }
    }
}
